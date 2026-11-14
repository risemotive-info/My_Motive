<?php
/**
 * NEW FILE: modules/timetable/corrections.php
 * Section 15: an employee who forgot to record a check-in, break or return does NOT type a time into their record.
 * They send a CORRECTION REQUEST (what, when, why); a supervisor approves or rejects it; the change and the old
 * value go to the audit trail. Also: asking for MORE TIME on a break or at an assigned post (extension request).
 * Supervisors may file a request for an employee of their team; somebody still has to approve it.
 */
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
tt_ensure($conn);

$me = (int) current_user_id();
$isSup = tt_is_supervisor();
$forUser = $isSup && !empty($_REQUEST['user']) ? (int) $_REQUEST['user'] : $me;
if (!tt_can_see_user($conn, $forUser)) { $forUser = $me; }
$date = tt_valid_date($_REQUEST['date'] ?? '') ? $_REQUEST['date'] : date('Y-m-d');
$minDate = date('Y-m-d', strtotime('-14 days'));
if ($date > date('Y-m-d') || $date < $minDate) { $date = date('Y-m-d'); }
$ctx = tt_context($conn, $forUser, $date);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tt_csrf_ok()) { $error = 'The page had expired. Please try again.'; }
    elseif (($_POST['type'] ?? '') === 'extension') {
        // More time for the break or post the employee is on NOW.
        $now = time();
        $wd = tt_work_date($conn, $me, $now);
        $c = tt_context($conn, $me, $wd);
        $ev = tt_norm_events($c['events']);
        $ob = $c['plan'] ? tt_open_break($c['plan'], $ev) : null;
        $kind = $ob ? 'break' : (($c['plan'] && tt_on_post($c['plan'], $ev)) ? 'post' : null);
        $t = tt_valid_time($_POST['until'] ?? '');
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 500);
        $currentDue = $ob ? tt_break_due($ob, $c['ex']) : ($kind ? tt_post_due($c['plan'], $c['ex']) : null);
        $until = $t ? tt_at($wd, $t) : null;
        if ($until && $currentDue && $until < $currentDue - 12 * 3600) { $until += 86400; }
        if (!$kind) { $error = 'You are not on a break or at an assigned post now.'; }
        elseif (!$until || $until <= $currentDue) { $error = 'Choose a time after your current return time (' . tt_hm12($currentDue) . ').'; }
        elseif ($reason === '') { $error = 'Please give the reason.'; }
        else {
            $ref = $ob ? $ob['id'] : 0; $ne = date('Y-m-d H:i:s', $until);
            $s = mysqli_prepare($conn, 'INSERT INTO tt_extensions (user_id, work_date, kind, ref_id, new_end, reason, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'ississi', $me, $wd, $kind, $ref, $ne, $reason, $me);
            mysqli_stmt_execute($s);
            $id = (int) mysqli_insert_id($conn);
            tt_audit($conn, $me, $me, 'extension', $id, 'requested', 'More time (' . $kind . ') until ' . $ne . ' requested. Reason: ' . $reason);
            $u = tt_user($conn, $me);
            foreach (tt_supervisors($conn, $me) as $sup) {
                tt_deliver($conn, 'request_sup', 30000000 + $id, $wd, $me, $sup, 'More time requested: ' . $u['names'],
                    $u['names'] . ' asks to extend their ' . ($kind === 'break' ? strtolower($ob['label']) : 'assigned post') . ' until ' . date('g:i A', $until) . '. Reason: ' . $reason . '. Open Work Timetable > Approvals.');
            }
            header('Location: my.php?success=' . urlencode('Your request was sent to your supervisor. Until it is approved, your official return time stays ' . tt_hm12($currentDue) . '.'));
            exit;
        }
    } else {
        $type = (string) ($_POST['event_type'] ?? '');
        $ref = (int) ($_POST['ref'] ?? 0);
        $t = tt_valid_time($_POST['time'] ?? '');
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 1000);
        $plan = $ctx['plan'];
        $validRefs = $plan ? array_column($plan['breaks'], 'id') : [];
        if (!isset(TT_EVENT_LABELS[$type])) { $error = 'Choose what was not recorded correctly.'; }
        elseif (in_array($type, ['break_start', 'break_end'], true) && !in_array($ref, $validRefs, true)) { $error = 'Choose the break.'; }
        elseif (!$t) { $error = 'Enter the real time (HH:MM).'; }
        elseif (mb_strlen($reason) < 5) { $error = 'Please explain what happened.'; }
        else {
            if (!in_array($type, ['break_start', 'break_end'], true)) { $ref = 0; }
            $ts = tt_at($date, $t);
            if ($plan && $plan['report'] && $ts < $plan['report'] - 6 * 3600) { $ts += 86400; } // night shift
            if ($ts > time()) { $error = 'The time cannot be in the future.'; }
            else {
                $req = date('Y-m-d H:i:s', $ts);
                $dup = mysqli_prepare($conn, "SELECT COUNT(*) FROM tt_corrections WHERE user_id = ? AND work_date = ? AND event_type = ? AND ref_id = ? AND status = 'Pending'");
                mysqli_stmt_bind_param($dup, 'issi', $forUser, $date, $type, $ref);
                mysqli_stmt_execute($dup);
                if ((int) mysqli_fetch_row(mysqli_stmt_get_result($dup))[0]) { $error = 'A request for this is already waiting for a decision.'; }
                else {
                    $s = mysqli_prepare($conn, 'INSERT INTO tt_corrections (user_id, work_date, event_type, ref_id, requested_time, reason, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($s, 'ississi', $forUser, $date, $type, $ref, $req, $reason, $me);
                    mysqli_stmt_execute($s);
                    $id = (int) mysqli_insert_id($conn);
                    tt_audit($conn, $me, $forUser, 'correction', $id, 'requested', TT_EVENT_LABELS[$type] . ' on ' . $date . ' at ' . $req . ' requested' . ($forUser !== $me ? ' on behalf of the employee' : '') . '. Reason: ' . $reason);
                    $u = tt_user($conn, $forUser);
                    foreach (tt_supervisors($conn, $forUser) as $sup) {
                        if ($sup === $me) { continue; }
                        tt_deliver($conn, 'request_sup', 10000000 + $id, $date, $forUser, $sup, 'Time correction to decide: ' . $u['names'],
                            $u['names'] . ': ' . TT_EVENT_LABELS[$type] . ' on ' . date('d M Y', strtotime($date)) . ' at ' . date('H:i', $ts) . '. Reason: ' . $reason);
                    }
                    header('Location: corrections.php?success=' . urlencode('Correction request sent. Your records change only if it is approved.') . ($forUser !== $me ? '&user=' . $forUser : ''));
                    exit;
                }
            }
        }
    }
}

$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.*, d.names AS decided_name FROM tt_corrections c LEFT JOIN users d ON d.id = c.decided_by WHERE c.user_id = ' . $forUser . ' ORDER BY c.id DESC LIMIT 30'), MYSQLI_ASSOC);
$emp = tt_user($conn, $forUser);
$team = $isSup ? array_filter(tt_employees($conn), function ($e) use ($conn) { return tt_can_see_user($conn, (int) $e['id']); }) : [];
$wantExt = !empty($_GET['extension']);

include '../../includes/header.php';
include '../../includes/sidebar.php';
tt_tabs('my.php');
tt_flash();
if ($error) { echo '<div class="alert alert-danger">' . tt_e($error) . '</div>'; }
?>
<div class="row g-3">
    <div class="col-lg-5">
        <?php if ($wantExt) { ?>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6><i class="bi bi-hourglass-split me-1"></i>Ask for more time</h6>
            <p class="small text-muted">For the break or assigned post you are on now. Your official return time does not change until a supervisor approves.</p>
            <form method="POST"><?= tt_csrf_field(); ?><input type="hidden" name="type" value="extension">
                <label class="form-label small">New return time</label><input type="time" name="until" class="form-control mb-2" required>
                <label class="form-label small">Reason</label><textarea name="reason" class="form-control mb-2" rows="2" required maxlength="500"></textarea>
                <button class="btn btn-primary">Send request</button>
            </form>
        </div></div>
        <?php } ?>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <h6><i class="bi bi-pencil-square me-1"></i>Request a time correction</h6>
            <p class="small text-muted">For a check-in, break, return or sign-out you forgot to record (or recorded at the wrong moment). Up to 14 days back. Nothing changes until a supervisor approves.</p>
            <form method="GET" class="row g-2 mb-2">
                <?php if ($isSup) { ?>
                <div class="col-12"><label class="form-label small">Employee</label>
                    <select name="user" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($team as $t) { ?><option value="<?= (int) $t['id']; ?>" <?= (int) $t['id'] === $forUser ? 'selected' : ''; ?>><?= tt_e($t['names']); ?></option><?php } ?>
                    </select></div>
                <?php } ?>
                <div class="col-12"><label class="form-label small">Date</label>
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= tt_e($date); ?>" min="<?= $minDate; ?>" max="<?= date('Y-m-d'); ?>" onchange="this.form.submit()"></div>
            </form>
            <?php if (!$ctx['plan']) { ?>
                <div class="small text-muted">No timetable on this date.</div>
            <?php } else { $ev = tt_norm_events($ctx['events']); ?>
            <div class="small mb-2 p-2 rounded" style="background:#F4F6FB;">
                Recorded that day: check-in <?= tt_hm($ev['check_in']); ?>,
                <?php foreach ($ctx['plan']['breaks'] as $b) { ?><?= tt_e($b['label']); ?> <?= tt_hm($ev['breaks'][$b['id']]['start'] ?? null); ?>–<?= tt_hm($ev['breaks'][$b['id']]['end'] ?? null); ?>, <?php } ?>
                sign-out <?= tt_hm($ev['sign_out']); ?>.
            </div>
            <form method="POST"><?= tt_csrf_field(); ?>
                <input type="hidden" name="date" value="<?= tt_e($date); ?>"><input type="hidden" name="user" value="<?= $forUser; ?>">
                <label class="form-label small">What should be corrected?</label>
                <select name="event_type" class="form-select mb-2" required id="ttType">
                    <?php foreach (TT_EVENT_LABELS as $k => $l) { if (strpos($k, 'post_') === 0 && !$ctx['plan']['post']) { continue; } ?><option value="<?= $k; ?>"><?= tt_e($l); ?></option><?php } ?>
                </select>
                <div id="ttBreak" style="display:none;"><label class="form-label small">Which break?</label>
                    <select name="ref" class="form-select mb-2"><?php foreach ($ctx['plan']['breaks'] as $b) { ?><option value="<?= (int) $b['id']; ?>"><?= tt_e($b['label']); ?> (<?= tt_hm($b['start']); ?>–<?= tt_hm($b['end']); ?>)</option><?php } ?></select></div>
                <label class="form-label small">The real time</label><input type="time" name="time" class="form-control mb-2" required>
                <label class="form-label small">What happened?</label><textarea name="reason" class="form-control mb-2" rows="3" required maxlength="1000"></textarea>
                <button class="btn btn-primary">Send for approval</button>
            </form>
            <script>(function(){var t=document.getElementById('ttType'),b=document.getElementById('ttBreak');function f(){b.style.display=(t.value==='break_start'||t.value==='break_end')?'':'none';}t.addEventListener('change',f);f();})();</script>
            <?php } ?>
        </div></div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <h6>Correction requests<?= $forUser !== $me ? ' · ' . tt_e($emp['names']) : ''; ?></h6>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
                <tr><th>Date</th><th>What</th><th>Time</th><th>Reason</th><th>Status</th></tr>
                <?php if (!$rows) { ?><tr><td colspan="5" class="text-muted text-center py-3">No requests.</td></tr><?php } ?>
                <?php foreach ($rows as $r) { ?>
                    <tr><td><?= date('d M Y', strtotime($r['work_date'])); ?></td><td><?= tt_e(TT_EVENT_LABELS[$r['event_type']] ?? $r['event_type']); ?></td>
                        <td><?= date('H:i', strtotime($r['requested_time'])); ?></td><td class="small"><?= tt_e($r['reason']); ?></td>
                        <td><strong><?= tt_e($r['status']); ?></strong><?php if ($r['decided_name']) { ?><div class="small text-muted"><?= tt_e($r['decided_name']); ?><?= $r['decision_note'] ? ': ' . tt_e($r['decision_note']) : ''; ?></div><?php } ?></td></tr>
                <?php } ?>
            </table></div>
        </div></div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>