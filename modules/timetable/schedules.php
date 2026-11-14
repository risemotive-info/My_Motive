<?php
/**
 * NEW FILE: modules/timetable/schedules.php — Administrators: every employee's official timetable.
 * Shows what applies today, the next change, and links to set / adjust each timetable (schedule_edit.php).
 */
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
require_role(['Admin']);
tt_ensure($conn);

$dep = (int) ($_GET['department'] ?? 0);
$emps = tt_employees($conn, $dep ?: null);
$today = date('Y-m-d');
$ctx = $emps ? tt_contexts($conn, $today, $today, array_keys($emps)) : [];
$upcoming = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT user_id, kind, valid_from, valid_to FROM tt_schedules WHERE status <> 'cancelled' AND valid_from > '$today' ORDER BY valid_from"), MYSQLI_ASSOC) as $r) {
    $upcoming[(int) $r['user_id']][] = $r;
}
$departments = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC);
$without = 0;
foreach ($emps as $uid => $e) { if (empty($ctx[$uid][$today]['plan'])) { $without++; } }

include '../../includes/header.php';
include '../../includes/sidebar.php';
tt_tabs('schedules.php');
tt_flash();
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <form method="GET" class="d-flex gap-2"><select name="department" class="form-select form-select-sm" onchange="this.form.submit()"><option value="0">All departments</option>
        <?php foreach ($departments as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= $dep === (int) $d['id'] ? 'selected' : ''; ?>><?= tt_e($d['name']); ?></option><?php } ?></select></form>
    <div class="d-flex gap-2"><a href="schedule_edit.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Set a timetable (one or several employees)</a></div>
</div>
<?php if ($without) { ?><div class="alert alert-primary small"><?= $without; ?> active employee(s) have no timetable today: reminders, lateness and sign-out rules do not apply to them until one is set.</div><?php } ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr><th class="ps-3">Employee</th><th>Today</th><th>Working days &amp; hours</th><th>Breaks</th><th>Assigned post</th><th>Coming changes</th><th></th></tr>
    <?php foreach ($emps as $uid => $e) { $p = $ctx[$uid][$today]['plan'] ?? null; ?>
        <tr>
            <td class="ps-3"><strong><?= tt_e($e['names']); ?></strong><div class="small text-muted"><?= tt_e($e['department']); ?> · <?= tt_e($e['role']); ?></div></td>
            <td><?= $p ? tt_badge(['work' => 'Scheduled', 'off' => 'Off Day', 'holiday' => 'Holiday', 'leave' => 'On Leave'][$p['day_type']]) . ($p['schedule_kind'] === 'temporary' ? ' <span class="badge bg-info">temporary</span>' : '') : tt_badge('No Schedule'); ?></td>
            <?php if ($p) { $s = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM tt_schedules WHERE id = ' . (int) $p['schedule_id'])); $days = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun']; ?>
                <td><?= tt_e(implode(' ', array_map(function ($x) use ($days) { return $days[$x] ?? $x; }, explode(',', $s['working_days'])))); ?><div><?= substr($s['report_time'], 0, 5); ?>–<?= substr($s['departure_time'], 0, 5); ?> · <?= tt_dur(tt_engine_scheduled_minutes(array_merge($p, ['day_type' => 'work']))); ?>/day</div></td>
                <td class="small"><?php foreach ($p['breaks'] as $b) { ?><div><?= tt_e($b['label']); ?> <?= tt_hm($b['start']); ?>–<?= tt_hm($b['end']); ?></div><?php } ?></td>
                <td class="small"><?= $p['post'] ? tt_e($p['post']['name']) . ' ' . tt_hm($p['post']['leave_main']) . '–' . tt_hm($p['post']['return_main']) : '—'; ?></td>
            <?php } else { ?><td colspan="3" class="text-muted small">No timetable</td><?php } ?>
            <td class="small"><?php foreach ($upcoming[$uid] ?? [] as $u) { ?><div><?= ucfirst($u['kind']); ?> from <?= date('d M', strtotime($u['valid_from'])); ?><?= $u['valid_to'] ? ' to ' . date('d M', strtotime($u['valid_to'])) : ''; ?></div><?php } ?></td>
            <td class="text-nowrap"><a href="schedule_edit.php?user=<?= (int) $uid; ?>" class="btn btn-sm btn-outline-primary"><?= $p ? 'Change / history' : 'Set timetable'; ?></a></td>
        </tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>