<?php
/**
 * NEW FILE: modules/timetable/board.php — Administrative Time Management Dashboard (section 11).
 * Who is working, on break, not reported, late, overdue, back late, out at a post, done, in overtime — right now.
 * Admins see everyone; Managers see their department. Refreshes every minute.
 */
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
require_role(['Admin', 'Manager']);
tt_ensure($conn);

$now = time();
$today = date('Y-m-d', $now);
$date = tt_valid_date($_GET['date'] ?? '') && $_GET['date'] <= $today ? $_GET['date'] : $today;
$dep = (int) ($_GET['department'] ?? 0);
$emp = (int) ($_GET['employee'] ?? 0);
$filter = (string) ($_GET['status'] ?? '');
$at = $date === $today ? $now : strtotime($date . ' 23:59:59') + 6 * 3600;
$set = tt_settings($conn);

$emps = tt_employees($conn, $dep ?: null);
$vis = tt_visible_user_ids($conn);
if ($vis !== null) { $emps = array_intersect_key($emps, array_flip($vis)); }
$allEmps = $emps;
if ($emp) { $emps = array_intersect_key($emps, [$emp => 1]); }
$ctx = $emps ? tt_contexts($conn, $date, $date, array_keys($emps)) : [];

$groups = [
    'working' => ['Currently working', ['Working', 'Break Approaching', 'Returned Late'], 'primary'],
    'break' => ['On lunch / break', ['On Break', 'Return Due'], 'info'],
    'not_reported' => ['Not yet reported', ['Not Reported'], 'danger'],
    'late' => ['Arrived late', [], 'danger'],
    'overdue' => ['Break overdue', ['Overdue'], 'danger'],
    'returned_late' => ['Returned late', [], 'danger'],
    'post' => ['Not back from assigned post', ['Post Return Overdue'], 'danger'],
    'on_post' => ['At assigned post', ['On Assigned Post'], 'info'],
    'completed' => ['Completed working hours', ['Completed'], 'success'],
    'overtime' => ['Working overtime', ['Overtime'], 'dark'],
    'absent' => ['Absent', ['Absent'], 'danger'],
    'off' => ['Off / holiday / leave', ['Off Day', 'Holiday', 'On Leave'], 'secondary'],
    'none' => ['No timetable', ['No Schedule'], 'secondary'],
];
$rows = []; $counts = array_fill_keys(array_keys($groups), 0);
foreach ($emps as $uid => $e) {
    $c = $ctx[$uid][$date];
    $calc = tt_engine_compute($c['plan'], $c['events'], $c['ex'], $at, $set);
    $in = [];
    foreach ($groups as $k => [$l, $sts]) { if (in_array($calc['status'], $sts, true)) { $in[] = $k; } }
    if ($calc['punctuality'] === 'Late') { $in[] = 'late'; }
    if (isset($calc['lost_by_reason']['late_return'])) { $in[] = 'returned_late'; }
    foreach ($in as $k) { $counts[$k]++; }
    if ($filter && !in_array($filter, $in, true)) { continue; }
    $rows[] = ['e' => $e, 'c' => $c, 'calc' => $calc];
}
$departments = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC);

include '../../includes/header.php';
include '../../includes/sidebar.php';
if ($date === $today) { echo '<meta http-equiv="refresh" content="60">'; }
tt_tabs('board.php');
tt_flash();
$q = function (array $over) { return '?' . http_build_query(array_merge($_GET, $over)); };
?>
<form class="row g-2 mb-3 align-items-end" method="GET">
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Date</label><input type="date" name="date" value="<?= tt_e($date); ?>" max="<?= $today; ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-0">Department</label><select name="department" class="form-select form-select-sm"><option value="0">All</option>
        <?php foreach ($departments as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= $dep === (int) $d['id'] ? 'selected' : ''; ?>><?= tt_e($d['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-0">Employee</label><select name="employee" class="form-select form-select-sm"><option value="0">All</option>
        <?php foreach ($allEmps as $e) { ?><option value="<?= (int) $e['id']; ?>" <?= $emp === (int) $e['id'] ? 'selected' : ''; ?>><?= tt_e($e['names']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach ($groups as $k => [$l]) { ?><option value="<?= $k; ?>" <?= $filter === $k ? 'selected' : ''; ?>><?= tt_e($l); ?></option><?php } ?></select></div>
    <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">Show</button></div>
</form>

<div class="row g-2 mb-3">
    <?php foreach ($groups as $k => [$l, $s, $style]) { if ($k === 'none' && !$counts[$k]) { continue; } ?>
        <div class="col-6 col-md-4 col-xl-2"><a href="<?= tt_e($q(['status' => $filter === $k ? '' : $k])); ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 <?= $filter === $k ? 'border border-primary' : ''; ?>"><div class="card-body py-2">
                <div class="small text-muted"><?= tt_e($l); ?></div>
                <div class="fs-4 fw-bold text-<?= $counts[$k] && in_array($style, ['danger'], true) ? 'danger' : 'dark'; ?>"><?= $counts[$k]; ?></div>
            </div></div></a></div>
    <?php } ?>
</div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr><th class="ps-3">Employee</th><th>Status</th><th>Reporting</th><th>Check-in</th><th>Now / next</th><th>Breaks</th><th>Departure</th><th>Lost today</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-4">No employees match.</td></tr><?php } ?>
    <?php foreach ($rows as ['e' => $e, 'c' => $c, 'calc' => $calc]) { $p = $c['plan']; $ev = tt_norm_events($c['events']); ?>
        <tr>
            <td class="ps-3"><strong><?= tt_e($e['names']); ?></strong><div class="small text-muted"><?= tt_e($e['department']); ?></div></td>
            <td><?= tt_badge($calc['status']); ?><?= $calc['punctuality'] === 'Late' ? ' ' . tt_badge('Late') : ''; ?><?php foreach ($calc['flags'] as $f) { ?><div class="small text-danger"><i class="bi bi-flag-fill"></i> review</div><?php break; } ?></td>
            <td><?= $p && $p['day_type'] === 'work' ? tt_hm(tt_allowed_arrival($p, $c['ex'])) : '—'; ?></td>
            <td><?= tt_hm($ev['check_in']); ?><?= $calc['late_arrival_minutes'] ? '<div class="small text-danger">+' . tt_dur($calc['late_arrival_minutes']) . '</div>' : ''; ?></td>
            <td class="small"><?php if ($calc['next']) { ?><?= tt_e($calc['next']['label']); ?> <?= tt_hm($calc['next']['at']); ?><?php if ($date === $today) { ?> · <span data-rm-countdown="<?= $calc['next']['at'] * 1000; ?>"></span><?php } } ?></td>
            <td class="small"><?php if ($p) { foreach ($p['breaks'] as $b) { $r = $ev['breaks'][$b['id']] ?? null; ?>
                <div><?= tt_e($b['label']); ?>: <?= tt_hm($r['start'] ?? null); ?>–<?= tt_hm($r['end'] ?? null); ?> <span class="text-muted">(<?= tt_hm($b['start']); ?>–<?= tt_hm(tt_break_due($b, $c['ex'])); ?>)</span></div>
            <?php } } ?></td>
            <td><?= $p && $p['day_type'] === 'work' ? tt_hm(tt_allowed_departure($p, $c['ex'])) : '—'; ?><?= $ev['sign_out'] ? '<div class="small text-muted">out ' . tt_hm($ev['sign_out']) . '</div>' : ''; ?></td>
            <td class="<?= $calc['lost_minutes'] ? 'text-danger fw-semibold' : ''; ?>"><?= $calc['lost_minutes'] ? tt_dur($calc['lost_minutes']) : '—'; ?></td>
            <td><a href="day.php?user=<?= (int) $e['id']; ?>&date=<?= tt_e($date); ?>" class="btn btn-sm btn-outline-primary">Time log</a></td>
        </tr>
    <?php } ?>
</table></div></div></div>
<div class="small text-muted mt-2">Statuses use the official server time<?= $date === $today ? ' and refresh every minute' : ' (end of that day)'; ?>. Lost time excludes grace periods and approved exceptions.</div>
<?php include '../../includes/footer.php'; ?>