<?php
/**
 * NEW FILE: modules/timetable/day.php — the detailed time log of one employee for one day:
 * the timetable that applied, every recorded event (and whether it came from a correction), the calculation,
 * the lost-time intervals with their reasons, approved exceptions, the reminders sent and the audit trail.
 * Employees see their own days; supervisors their team's, and can give more time (break / post) for today.
 */
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
tt_ensure($conn);

$me = (int) current_user_id();
$uid = (int) ($_GET['user'] ?? $_POST['user'] ?? $me);
if (!tt_can_see_user($conn, $uid)) { http_response_code(403); include '../../includes/access_denied.php'; exit; }
$date = tt_valid_date($_GET['date'] ?? $_POST['date'] ?? '') ? ($_GET['date'] ?? $_POST['date']) : date('Y-m-d');
$isSup = tt_is_supervisor() && $uid !== $me && access_can('timetable.approve');
$error = null;

// Supervisor gives more time now (approved straight away, audited).
if ($isSup && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'extend') {
    $ctx = tt_context($conn, $uid, $date);
    $kind = ($_POST['kind'] ?? '') === 'post' ? 'post' : 'break';
    $ref = $kind === 'break' ? (int) ($_POST['ref'] ?? 0) : 0;
    $t = tt_valid_time($_POST['until'] ?? '');
    $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 500);
    $base = null;
    if ($ctx['plan']) {
        if ($kind === 'break') { foreach ($ctx['plan']['breaks'] as $b) { if ($b['id'] === $ref) { $base = tt_break_due($b, $ctx['ex']); } } }
        else { $base = tt_post_due($ctx['plan'], $ctx['ex']); }
    }
    $until = $t ? tt_at($date, $t) : null;
    if ($until && $base && $until < $base - 12 * 3600) { $until += 86400; }
    if (!tt_csrf_ok()) { $error = 'The page had expired. Please try again.'; }
    elseif (!$base) { $error = 'Nothing to extend on this day.'; }
    elseif (!$until || $until <= $base) { $error = 'The new time must be after ' . tt_hm12($base) . '.'; }
    elseif ($reason === '') { $error = 'Please give the reason.'; }
    else {
        $ne = date('Y-m-d H:i:s', $until);
        $s = mysqli_prepare($conn, "INSERT INTO tt_extensions (user_id, work_date, kind, ref_id, new_end, reason, status, requested_by, decided_by, decided_at) VALUES (?, ?, ?, ?, ?, ?, 'Approved', ?, ?, NOW())");
        mysqli_stmt_bind_param($s, 'ississii', $uid, $date, $kind, $ref, $ne, $reason, $me, $me);
        mysqli_stmt_execute($s);
        $id = (int) mysqli_insert_id($conn);
        tt_audit($conn, $me, $uid, 'extension', $id, 'granted', ucfirst($kind) . ' extended from ' . date('H:i', $base) . ' to ' . date('H:i', $until) . ' on ' . $date . '. Reason: ' . $reason);
        tt_deliver($conn, 'decision', 30000000 + $id, $date, $uid, $uid, 'More time approved', 'Your supervisor extended your ' . ($kind === 'break' ? 'break' : 'assigned post return') . ' until ' . date('g:i A', $until) . '. Reason: ' . $reason);
        header('Location: day.php?user=' . $uid . '&date=' . $date . '&success=' . urlencode('Extension recorded. The employee was notified.')); exit;
    }
}

$ctx = tt_context($conn, $uid, $date);
$now = time();
$at = $date === date('Y-m-d') ? $now : strtotime($date . ' 23:59:59') + 6 * 3600;
$calc = tt_engine_compute($ctx['plan'], $ctx['events'], $ctx['ex'], $at, tt_settings($conn));
$emp = tt_user($conn, $uid);
$events = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM tt_events WHERE user_id = $uid AND work_date = '$date' ORDER BY actual_at, id"), MYSQLI_ASSOC);
$notes = mysqli_fetch_all(mysqli_query($conn, "SELECT l.*, u.names AS recipient FROM tt_notification_log l LEFT JOIN users u ON u.id = l.recipient_id WHERE l.subject_user_id = $uid AND l.work_date = '$date' ORDER BY l.id"), MYSQLI_ASSOC);
$audit = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names AS actor FROM tt_audit a LEFT JOIN users u ON u.id = a.actor_id WHERE a.user_id = $uid AND DATE(a.created_at) = '$date' ORDER BY a.id"), MYSQLI_ASSOC);
$breakNames = [];
if ($ctx['plan']) { foreach ($ctx['plan']['breaks'] as $b) { $breakNames[$b['id']] = $b['label']; } }
$overtime = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM tt_overtime WHERE user_id = $uid AND work_date = '$date'"));

include '../../includes/header.php';
include '../../includes/sidebar.php';
tt_tabs($uid === $me ? 'my.php' : 'board.php');
tt_flash();
if ($error) { echo '<div class="alert alert-danger">' . tt_e($error) . '</div>'; }
$p = $ctx['plan'];
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div><h5 class="mb-0"><?= tt_e($emp['names']); ?> · <?= date('l, d M Y', strtotime($date)); ?></h5><div class="small text-muted"><?= tt_e($emp['department']); ?></div></div>
    <form class="d-flex gap-2" method="GET"><input type="hidden" name="user" value="<?= $uid; ?>">
        <a class="btn btn-sm btn-outline-secondary" href="?user=<?= $uid; ?>&date=<?= date('Y-m-d', strtotime($date . ' -1 day')); ?>">&larr;</a>
        <input type="date" name="date" value="<?= tt_e($date); ?>" max="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" onchange="this.form.submit()">
        <a class="btn btn-sm btn-outline-secondary <?= $date >= date('Y-m-d') ? 'disabled' : ''; ?>" href="?user=<?= $uid; ?>&date=<?= date('Y-m-d', strtotime($date . ' +1 day')); ?>">&rarr;</a>
    </form>
</div>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6>Timetable that applied <?= tt_badge($calc['status']); ?></h6>
            <?php if (!$p) { ?><div class="text-muted small">No timetable on this date.</div>
            <?php } else { ?>
                <div class="small text-muted mb-1"><?= $p['schedule_kind'] === 'temporary' ? 'Temporary adjustment' : 'Permanent timetable'; ?> #<?= (int) $p['schedule_id']; ?><?= $p['day_label'] ? ' · ' . tt_e($p['day_label']) : ''; ?></div>
                <?php if ($p['day_type'] === 'work') { ?>
                <table class="table table-sm small mb-0">
                    <tr><td>Reporting</td><td><?= tt_hm($p['report']); ?> (grace <?= (int) $p['grace_arrival']; ?> min)</td></tr>
                    <?php if ($p['post']) { ?><tr><td>Assigned post</td><td><?= tt_e($p['post']['name']); ?>: leave <?= tt_hm($p['post']['leave_main']); ?>, arrive <?= tt_hm($p['post']['arrive']); ?>, leave post <?= tt_hm($p['post']['leave_post']); ?>, back <?= tt_hm($p['post']['return_main']); ?></td></tr><?php } ?>
                    <?php foreach ($p['breaks'] as $b) { ?><tr><td><?= tt_e($b['label']); ?></td><td><?= tt_hm($b['start']); ?>–<?= tt_hm($b['end']); ?><?= tt_break_due($b, $ctx['ex']) > $b['end'] ? ' <strong>→ ' . tt_hm(tt_break_due($b, $ctx['ex'])) . '</strong>' : ''; ?></td></tr><?php } ?>
                    <tr><td>Departure</td><td><?= tt_hm($p['departure']); ?><?= tt_allowed_departure($p, $ctx['ex']) < $p['departure'] ? ' <strong>(may leave ' . tt_hm(tt_allowed_departure($p, $ctx['ex'])) . ')</strong>' : ''; ?></td></tr>
                </table>
                <?php } ?>
                <?php if ($ctx['ex']['labels']) { ?><div class="small mt-2"><strong>Approved exceptions:</strong> <?= tt_e(implode(' · ', $ctx['ex']['labels'])); ?></div><?php } ?>
            <?php } ?>
        </div></div>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6>Calculation</h6>
            <table class="table table-sm small mb-0">
                <?php foreach ([['Scheduled working time', $calc['scheduled_minutes']], ['Actual working time', $calc['worked_minutes']], ['Authorized break', $calc['authorized_break_minutes']],
                    ['Actual break', $calc['actual_break_minutes']], ['Excess break', $calc['excess_break_minutes']], ['Late arrival', $calc['late_arrival_minutes']],
                    ['Early departure', $calc['early_departure_minutes']], ['Unauthorized absence', $calc['absence_minutes']], ['Post delay', $calc['post_delay_minutes']],
                    ['Overtime (worked)', $calc['overtime_minutes']]] as [$k, $v]) { ?>
                    <tr><td><?= tt_e($k); ?></td><td class="text-end"><?= tt_dur((int) $v); ?></td></tr>
                <?php } ?>
                <tr><td>Overtime decision</td><td class="text-end"><?= $overtime ? tt_e($overtime['status']) . ($overtime['status'] === 'Approved' ? ' · ' . tt_dur((int) $overtime['approved_minutes']) : '') : '—'; ?></td></tr>
                <tr class="fw-bold"><td>Total lost working time</td><td class="text-end <?= $calc['lost_minutes'] ? 'text-danger' : ''; ?>"><?= tt_dur($calc['lost_minutes']); ?></td></tr>
            </table>
            <?php foreach ($calc['lost'] as [$s, $e, $r]) { ?><div class="small d-flex justify-content-between border-top py-1"><span><?= tt_e(TT_LOST_REASONS[$r] ?? $r); ?></span><span><?= tt_hm($s); ?>–<?= tt_hm($e); ?> · <?= tt_dur(tt_min($e - $s)); ?></span></div><?php } ?>
            <?php foreach ($calc['flags'] as $f) { ?><div class="alert alert-danger small py-1 px-2 mt-2 mb-0"><?= tt_e($f); ?></div><?php } ?>
        </div></div>
        <?php if ($isSup && $p && $p['day_type'] === 'work' && $date === date('Y-m-d')) { ?>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <h6>Give more time (approved now)</h6>
            <form method="POST" class="row g-2"><?= tt_csrf_field(); ?><input type="hidden" name="do" value="extend"><input type="hidden" name="user" value="<?= $uid; ?>"><input type="hidden" name="date" value="<?= tt_e($date); ?>">
                <div class="col-6"><select name="kind" class="form-select form-select-sm" id="ttKind"><option value="break">Break</option><?php if ($p['post']) { ?><option value="post">Post return</option><?php } ?></select></div>
                <div class="col-6"><select name="ref" class="form-select form-select-sm"><?php foreach ($p['breaks'] as $b) { ?><option value="<?= (int) $b['id']; ?>"><?= tt_e($b['label']); ?></option><?php } ?></select></div>
                <div class="col-6"><input type="time" name="until" class="form-control form-control-sm" required></div>
                <div class="col-6"><input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason" required></div>
                <div class="col-12"><button class="btn btn-sm btn-primary">Record extension</button> <a href="corrections.php?user=<?= $uid; ?>&date=<?= tt_e($date); ?>" class="btn btn-sm btn-link">File a correction for this employee</a></div>
            </form>
        </div></div>
        <?php } elseif ($uid === $me) { ?>
            <a href="corrections.php?date=<?= tt_e($date); ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil-square"></i> Request a correction for this day</a>
        <?php } ?>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6>Recorded events (server time)</h6>
            <table class="table table-sm small mb-0">
                <tr><th>Event</th><th>Scheduled</th><th>Recorded</th><th>Difference</th><th>Source</th></tr>
                <?php if (!$events) { ?><tr><td colspan="5" class="text-muted">Nothing recorded.</td></tr><?php } ?>
                <?php foreach ($events as $e) { ?>
                    <tr><td><?= tt_e(TT_EVENT_LABELS[$e['event_type']] ?? $e['event_type']); ?><?= $e['ref_id'] && isset($breakNames[(int) $e['ref_id']]) ? ' · ' . tt_e($breakNames[(int) $e['ref_id']]) : ''; ?></td>
                        <td><?= $e['scheduled_at'] ? date('H:i', strtotime($e['scheduled_at'])) : '—'; ?></td><td><strong><?= date('H:i:s', strtotime($e['actual_at'])); ?></strong></td>
                        <td><?= $e['diff_minutes'] !== null ? (($e['diff_minutes'] > 0 ? '+' : '') . (int) $e['diff_minutes'] . ' min') : '—'; ?></td>
                        <td><?= $e['source'] === 'employee' ? 'Employee' : '<span class="badge bg-info">Correction #' . (int) $e['correction_id'] . '</span>'; ?></td></tr>
                <?php } ?>
            </table>
        </div></div>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6>Notifications sent</h6>
            <div class="table-responsive" style="max-height:260px;"><table class="table table-sm small mb-0">
                <?php if (!$notes) { ?><tr><td class="text-muted">None.</td></tr><?php } ?>
                <?php foreach ($notes as $n) { ?><tr><td><?= date('H:i', strtotime($n['created_at'])); ?></td><td><?= tt_e($n['title']); ?></td><td><?= tt_e($n['recipient']); ?></td><td><?= tt_e(strtoupper($n['channel'])); ?></td><td><?= tt_e($n['status']); ?><?= $n['detail'] ? ' · ' . tt_e($n['detail']) : ''; ?></td></tr><?php } ?>
            </table></div>
        </div></div>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <h6>Audit trail (this day)</h6>
            <?php if (!$audit) { ?><div class="small text-muted">No entries.</div><?php } ?>
            <?php foreach ($audit as $a) { ?><div class="small border-top py-1"><span class="text-muted"><?= date('H:i:s', strtotime($a['created_at'])); ?> · <?= tt_e($a['actor'] ?: 'System'); ?> · <?= tt_e($a['action']); ?></span><br><?= tt_e($a['details']); ?></div><?php } ?>
        </div></div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>