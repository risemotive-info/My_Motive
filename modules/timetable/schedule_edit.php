<?php
/**
 * NEW FILE: modules/timetable/schedule_edit.php — set or change a timetable (section 2).
 *   Permanent: a new VERSION from a date (today or later). The previous version ends the day before; past days
 *              keep exactly the timetable they had.
 *   Temporary: an adjustment for a date range (one day, a week, a month) that does not touch the permanent one.
 * The same timetable can be given to several employees at once. Every save, cancel and change is in the audit trail.
 */
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
require_role(['Admin']);
tt_ensure($conn);

$me = (int) current_user_id();
$uid = (int) ($_GET['user'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tt_csrf_ok()) { $errors[] = 'The page had expired. Please try again.'; }
    elseif (($_POST['do'] ?? '') === 'cancel') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        [$ok, $msg] = $reason === '' ? [false, 'Give the reason for cancelling.'] : tt_cancel_schedule($conn, (int) $_POST['id'], $me, $reason);
        header('Location: schedule_edit.php?user=' . (int) $_POST['user'] . '&' . ($ok ? 'success' : 'error') . '=' . urlencode($msg)); exit;
    } else {
        $users = array_values(array_unique(array_map('intval', (array) ($_POST['user_ids'] ?? []))));
        if (!$users) { $errors[] = 'Choose at least one employee.'; }
        $breaks = [];
        foreach ((array) ($_POST['b_label'] ?? []) as $i => $l) {
            $breaks[] = ['label' => $l, 'type' => $_POST['b_type'][$i] ?? 'break', 'start' => $_POST['b_start'][$i] ?? '', 'end' => $_POST['b_end'][$i] ?? ''];
        }
        $saved = 0;
        foreach ($users as $u) {
            [$ok, $msg] = tt_save_schedule($conn, array_merge($_POST, ['user_id' => $u, 'breaks' => $breaks]), $me);
            if ($ok) { $saved++; } else { $emp = tt_user($conn, $u); $errors[] = ($emp['names'] ?? 'Employee') . ': ' . $msg; break; }
        }
        if (!$errors) {
            header('Location: ' . (count($users) === 1 ? 'schedule_edit.php?user=' . $users[0] . '&' : 'schedules.php?') . 'success=' . urlencode($saved . ' timetable(s) saved. The employee(s) were notified.')); exit;
        }
    }
}

$emps = tt_employees($conn);
$history = $uid ? tt_schedule_rows($conn, $uid) : [];
// Pre-fill: the posted form, else the employee's current (or latest) permanent timetable, else a common default.
$cur = null;
foreach ($history as $h) { if ($h['kind'] === 'permanent' && $h['status'] !== 'cancelled') { $cur = $h; break; } }
$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [
    'kind' => $_GET['kind'] ?? 'permanent', 'valid_from' => date('Y-m-d'), 'valid_to' => '',
    'working_days' => $cur ? explode(',', $cur['working_days']) : ['1', '2', '3', '4', '5'],
    'report_time' => substr($cur['report_time'] ?? '07:00', 0, 5), 'arrival_time' => substr((string) ($cur['arrival_time'] ?? ''), 0, 5),
    'departure_time' => substr($cur['departure_time'] ?? '17:00', 0, 5),
    'grace_arrival' => $cur['grace_arrival'] ?? '', 'grace_return' => $cur['grace_return'] ?? '',
    'post_name' => $cur['post_name'] ?? '', 'post_leave_main' => substr((string) ($cur['post_leave_main'] ?? ''), 0, 5), 'post_arrive' => substr((string) ($cur['post_arrive'] ?? ''), 0, 5),
    'post_leave' => substr((string) ($cur['post_leave'] ?? ''), 0, 5), 'post_return_main' => substr((string) ($cur['post_return_main'] ?? ''), 0, 5),
    'note' => '', 'title' => '', 'user_ids' => $uid ? [$uid] : [],
];
$fBreaks = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ((array) ($_POST['b_label'] ?? []) as $i => $l) { $fBreaks[] = [$l, $_POST['b_type'][$i] ?? 'break', $_POST['b_start'][$i] ?? '', $_POST['b_end'][$i] ?? '']; }
} elseif ($cur) {
    foreach ($cur['breaks'] as $b) { $fBreaks[] = [$b['label'], $b['break_type'], substr($b['start_time'], 0, 5), substr($b['end_time'], 0, 5)]; }
} else { $fBreaks[] = ['Lunch break', 'lunch', '12:00', '13:00']; }
$set = tt_settings($conn);
$audit = $uid ? mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names AS actor FROM tt_audit a LEFT JOIN users u ON u.id = a.actor_id WHERE a.user_id = $uid AND a.entity = 'schedule' ORDER BY a.id DESC LIMIT 30"), MYSQLI_ASSOC) : [];
$sel = array_map('intval', (array) ($f['user_ids'] ?? []));
$v = function ($k) use ($f) { return tt_e($f[$k] ?? ''); };

include '../../includes/header.php';
include '../../includes/sidebar.php';
tt_tabs('schedules.php');
tt_flash();
foreach ($errors as $e) { echo '<div class="alert alert-danger">' . tt_e($e) . '</div>'; }
?>
<div class="row g-3">
<div class="col-xl-7">
<div class="card border-0 shadow-sm"><div class="card-body">
<h5 class="mb-3"><?= $uid ? 'Timetable · ' . tt_e($emps[$uid]['names'] ?? '') : 'Set a timetable'; ?></h5>
<form method="POST"><?= tt_csrf_field(); ?>
    <div class="mb-3"><label class="form-label small fw-semibold">Employee(s)</label>
        <select name="user_ids[]" class="form-select" multiple size="<?= $uid ? 1 : 6; ?>" required>
            <?php foreach ($emps as $e) { ?><option value="<?= (int) $e['id']; ?>" <?= in_array((int) $e['id'], $sel, true) ? 'selected' : ''; ?>><?= tt_e($e['names'] . ' · ' . ($e['department'] ?? '')); ?></option><?php } ?>
        </select><?php if (!$uid) { ?><div class="form-text">Hold Ctrl (or Cmd) to choose several employees: each gets the same timetable.</div><?php } ?></div>
    <div class="row g-2 mb-3">
        <div class="col-md-4"><label class="form-label small fw-semibold">Type</label>
            <select name="kind" class="form-select" id="ttKind">
                <option value="permanent" <?= ($f['kind'] ?? '') !== 'temporary' ? 'selected' : ''; ?>>Permanent (new version)</option>
                <option value="temporary" <?= ($f['kind'] ?? '') === 'temporary' ? 'selected' : ''; ?>>Temporary adjustment</option></select></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Valid from</label><input type="date" name="valid_from" class="form-control" min="<?= date('Y-m-d'); ?>" value="<?= $v('valid_from'); ?>" required></div>
        <div class="col-md-4" id="ttTo"><label class="form-label small fw-semibold">Valid until</label><input type="date" name="valid_to" class="form-control" min="<?= date('Y-m-d'); ?>" value="<?= $v('valid_to'); ?>"></div>
    </div>
    <div class="mb-3"><label class="form-label small fw-semibold">Official working days</label><div>
        <?php foreach (['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'] as $k => $l) { ?>
            <label class="me-3"><input type="checkbox" name="working_days[]" value="<?= $k; ?>" <?= in_array((string) $k, array_map('strval', (array) ($f['working_days'] ?? [])), true) ? 'checked' : ''; ?>> <?= $l; ?></label>
        <?php } ?></div></div>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><label class="form-label small fw-semibold">Reporting time</label><input type="time" name="report_time" class="form-control" value="<?= $v('report_time'); ?>" required></div>
        <div class="col-6 col-md-3"><label class="form-label small fw-semibold">Expected arrival</label><input type="time" name="arrival_time" class="form-control" value="<?= $v('arrival_time'); ?>"><div class="form-text">Optional</div></div>
        <div class="col-6 col-md-3"><label class="form-label small fw-semibold">Departure / sign-out</label><input type="time" name="departure_time" class="form-control" value="<?= $v('departure_time'); ?>" required></div>
        <div class="col-6 col-md-3"><label class="form-label small fw-semibold">Grace (arrival / return)</label>
            <div class="d-flex gap-1"><input type="number" name="grace_arrival" class="form-control" min="0" max="120" placeholder="<?= (int) $set['grace_arrival']; ?>" value="<?= $v('grace_arrival'); ?>"><input type="number" name="grace_return" class="form-control" min="0" max="120" placeholder="<?= (int) $set['grace_return']; ?>" value="<?= $v('grace_return'); ?>"></div>
            <div class="form-text">Minutes; empty = default</div></div>
    </div>
    <label class="form-label small fw-semibold">Lunch and breaks <span class="text-muted fw-normal">(each employee may have different times; several per day)</span></label>
    <table class="table table-sm mb-1" id="ttBreaks"><tr><th>Name</th><th>Type</th><th>Starts</th><th>Ends</th><th></th></tr>
        <?php foreach ($fBreaks as [$l, $t, $s, $e]) { ?>
        <tr><td><input name="b_label[]" class="form-control form-control-sm" value="<?= tt_e($l); ?>" maxlength="60"></td>
            <td><select name="b_type[]" class="form-select form-select-sm"><option value="lunch" <?= $t === 'lunch' ? 'selected' : ''; ?>>Lunch</option><option value="break" <?= $t !== 'lunch' ? 'selected' : ''; ?>>Break</option></select></td>
            <td><input type="time" name="b_start[]" class="form-control form-control-sm" value="<?= tt_e($s); ?>"></td><td><input type="time" name="b_end[]" class="form-control form-control-sm" value="<?= tt_e($e); ?>"></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger" onclick="this.closest('tr').remove(); ttTotals();">Remove</button></td></tr>
        <?php } ?>
    </table>
    <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="ttAddBreak"><i class="bi bi-plus"></i> Add a break</button>
    <div class="p-2 rounded small mb-3" style="background:#F4F6FB;" id="ttTotals"></div>

    <details class="mb-3" <?= !empty($f['post_leave_main']) ? 'open' : ''; ?>><summary class="small fw-semibold">Assigned post / field duty (optional)</summary>
        <div class="row g-2 mt-1">
            <div class="col-md-4"><label class="form-label small">Post / location</label><input name="post_name" class="form-control form-control-sm" value="<?= $v('post_name'); ?>" maxlength="160"></div>
            <div class="col-6 col-md-2"><label class="form-label small">Leave workplace</label><input type="time" name="post_leave_main" class="form-control form-control-sm" value="<?= $v('post_leave_main'); ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small">Arrive at post</label><input type="time" name="post_arrive" class="form-control form-control-sm" value="<?= $v('post_arrive'); ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small">Leave post</label><input type="time" name="post_leave" class="form-control form-control-sm" value="<?= $v('post_leave'); ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small">Back at workplace</label><input type="time" name="post_return_main" class="form-control form-control-sm" value="<?= $v('post_return_main'); ?>"></div>
        </div></details>
    <div class="mb-3"><label class="form-label small fw-semibold">Note (why / what changed)</label><input name="note" class="form-control" maxlength="500" value="<?= $v('note'); ?>"></div>
    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Save timetable</button>
    <a href="schedules.php" class="btn btn-link">Back</a>
</form>
</div></div>
</div>
<div class="col-xl-5">
    <?php if ($uid) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6>All versions (history is kept)</h6>
        <?php if (!$history) { ?><div class="small text-muted">No timetable yet.</div><?php } ?>
        <?php foreach ($history as $h) { $future = $h['valid_from'] > date('Y-m-d'); ?>
            <div class="border-top py-2 small">
                <div class="d-flex justify-content-between"><strong>#<?= (int) $h['id']; ?> <?= ucfirst($h['kind']); ?></strong>
                    <span class="badge bg-<?= ['active' => 'success', 'replaced' => 'secondary', 'cancelled' => 'light text-dark border'][$h['status']] ?? 'secondary'; ?>"><?= tt_e($h['status']); ?></span></div>
                <div><?= date('d M Y', strtotime($h['valid_from'])); ?> → <?= $h['valid_to'] ? date('d M Y', strtotime($h['valid_to'])) : 'until changed'; ?></div>
                <div class="text-muted"><?= tt_e(tt_schedule_text($h)); ?></div>
                <div class="text-muted">by <?= tt_e($h['created_by_name'] ?: '—'); ?>, <?= date('d M Y H:i', strtotime($h['created_at'])); ?><?= $h['note'] ? ' · ' . tt_e($h['note']) : ''; ?></div>
                <?php if ($future && $h['status'] !== 'cancelled') { ?>
                    <form method="POST" class="d-flex gap-1 mt-1"><?= tt_csrf_field(); ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= (int) $h['id']; ?>"><input type="hidden" name="user" value="<?= $uid; ?>">
                        <input name="reason" class="form-control form-control-sm" placeholder="Reason" required><button class="btn btn-sm btn-outline-danger">Cancel (not started)</button></form>
                <?php } ?>
            </div>
        <?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <h6>Audit trail</h6>
        <?php foreach ($audit as $a) { ?><div class="small border-top py-1"><span class="text-muted"><?= date('d M Y H:i', strtotime($a['created_at'])); ?> · <?= tt_e($a['actor'] ?: 'System'); ?></span><br><?= tt_e($a['details']); ?></div><?php } ?>
        <?php if (!$audit) { ?><div class="small text-muted">No changes yet.</div><?php } ?>
    </div></div>
    <?php } else { ?>
    <div class="card border-0 shadow-sm"><div class="card-body small">
        <h6>How timetables work</h6>
        <ul class="mb-0">
            <li><strong>Permanent</strong>: starts on the chosen date (today or later). The old timetable stops the day before; past days are never changed.</li>
            <li><strong>Temporary adjustment</strong>: applies only between its dates (e.g. one Saturday, a special week) and then the permanent timetable applies again.</li>
            <li>Approved leave, late arrival, early departure, extended breaks and external assignments come from <a href="../leave/index.php">Leave Requests</a> and adjust the day automatically.</li>
            <li>Public holidays (Settings) are days off for everyone.</li>
        </ul>
    </div></div>
    <?php } ?>
</div>
</div>
<template id="ttBreakRow"><tr><td><input name="b_label[]" class="form-control form-control-sm" value="Break" maxlength="60"></td>
    <td><select name="b_type[]" class="form-select form-select-sm"><option value="lunch">Lunch</option><option value="break" selected>Break</option></select></td>
    <td><input type="time" name="b_start[]" class="form-control form-control-sm"></td><td><input type="time" name="b_end[]" class="form-control form-control-sm"></td>
    <td><button type="button" class="btn btn-sm btn-link text-danger" onclick="this.closest('tr').remove(); ttTotals();">Remove</button></td></tr></template>
<script>
function ttMin(v) { if (!v) return null; var p = v.split(':'); return parseInt(p[0], 10) * 60 + parseInt(p[1], 10); }
function ttFmt(m) { var h = Math.floor(m / 60), r = m % 60; return (h ? h + 'h' : '') + (r ? (h ? ' ' : '') + r + 'min' : (h ? '' : '0 min')); }
function ttTotals() {
    var r = ttMin(document.querySelector('[name=report_time]').value), d = ttMin(document.querySelector('[name=departure_time]').value);
    if (r === null || d === null) { return; }
    if (d <= r) { d += 1440; }
    var br = 0;
    document.querySelectorAll('#ttBreaks tr').forEach(function (tr) {
        var s = tr.querySelector('[name="b_start[]"]'), e = tr.querySelector('[name="b_end[]"]');
        if (s && e && s.value && e.value) { var a = ttMin(s.value), b = ttMin(e.value); if (b <= a) { b += 1440; } br += b - a; }
    });
    document.getElementById('ttTotals').innerHTML = 'Total expected working hours per day: <strong>' + ttFmt(d - r - br) + '</strong> · Total permitted break duration: <strong>' + ttFmt(br) + '</strong>';
}
document.getElementById('ttAddBreak').addEventListener('click', function () {
    document.querySelector('#ttBreaks tbody, #ttBreaks').appendChild(document.getElementById('ttBreakRow').content.cloneNode(true)); ttTotals();
});
document.addEventListener('input', ttTotals);
(function () { var k = document.getElementById('ttKind'), t = document.getElementById('ttTo'); function f() { t.style.visibility = k.value === 'temporary' ? 'visible' : 'hidden'; } k.addEventListener('change', f); f(); })();
ttTotals();
</script>
<?php include '../../includes/footer.php'; ?>