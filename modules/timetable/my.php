<?php
/**
 * NEW FILE: modules/timetable/my.php — the employee's Time Management Dashboard (section 10).
 * Today's official timetable, the live status, countdowns, the action buttons, lost time and where it came from,
 * this month's punctuality, and the next working day. Updates by itself (rm_clock.js).
 */
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
tt_ensure($conn);

$me = (int) current_user_id();
$now = time();
$set = tt_settings($conn);
$date = tt_work_date($conn, $me, $now);
$ctx = tt_context($conn, $me, $date);
$plan = $ctx['plan']; $ev = tt_norm_events($ctx['events']); $ex = $ctx['ex'];
$calc = tt_engine_compute($plan, $ev, $ex, $now, $set);

// This month
$monthFrom = date('Y-m-01', $now);
$rep = tt_period_report($conn, $monthFrom, date('Y-m-t', $now), null, $me);
$month = $rep['rows'][$me] ?? null;
$recent = array_reverse($rep['days'][$me] ?? [], true);
$next = $plan ? tt_next_working_plan($conn, $me, $date) : null;
$nextDayFlash = $_SESSION['tt_next_day'] ?? null; unset($_SESSION['tt_next_day']);
$myCorrections = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM tt_corrections WHERE user_id = $me ORDER BY id DESC LIMIT 5"), MYSQLI_ASSOC);
$myExt = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM tt_extensions WHERE user_id = $me AND work_date = '" . $date . "' ORDER BY id DESC"), MYSQLI_ASSOC);
$overtimeToday = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM tt_overtime WHERE user_id = $me AND work_date = '$date'"));

// Tasks module: open work assigned to me (due today or overdue), shown beside the timetable.
$myTasks = null;
try {
    $myTasks = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS open_n, COALESCE(SUM(due_date IS NOT NULL AND due_date <= '$date'), 0) AS due_n
        FROM tasks WHERE assigned_to = $me AND status IN ('Pending', 'In Progress')"));
} catch (Throwable $e) { $myTasks = null; }
$ms = function (?int $ts) { return $ts ? $ts * 1000 : 0; };
$openBreak = $plan ? tt_open_break($plan, $ev) : null;
$onPost = $plan ? tt_on_post($plan, $ev) : false;
$can = function (string $a, int $ref = 0) use ($plan, $ev, $ex, $now, $set) { return tt_engine_can($plan, $ev, $ex, $now, $a, $ref, $set); };

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<style>
    .tt-clock{ font-size:2.6rem; font-weight:800; color:var(--accent-blue); font-variant-numeric:tabular-nums; letter-spacing:.03em; line-height:1; }
    .tt-count{ font-size:1.6rem; font-weight:700; font-variant-numeric:tabular-nums; }
    .tt-step{ border-left:3px solid var(--border-soft); padding:6px 0 6px 14px; position:relative; }
    .tt-step:before{ content:''; position:absolute; left:-7px; top:12px; width:11px; height:11px; border-radius:50%; background:#C9CEDD; }
    .tt-step.done:before{ background:var(--accent-teal); } .tt-step.late:before{ background:var(--accent-red); } .tt-step.now:before{ background:var(--accent-blue); box-shadow:0 0 0 4px var(--accent-blue-bg); }
    .tt-act .btn{ min-width:190px; }
</style>
<script>document.body.setAttribute('data-tt-live', '1');</script>

<?php tt_tabs('my.php'); tt_flash(); ?>
<?php if ($nextDayFlash) { ?>
    <div class="alert alert-primary" style="white-space:pre-line;"><strong><i class="bi bi-calendar-event me-1"></i>Next working day</strong>
<?= tt_e($nextDayFlash); ?></div>
<?php } ?>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
            <div class="small text-muted mb-1">Official MY MOTIVE time</div>
            <div class="tt-clock" data-rm-clock><?= date('H:i:s', $now); ?></div>
            <div class="text-muted mt-1" data-rm-clock="date"><?= date('D, d M Y', $now); ?></div>
            <div class="small text-muted mt-2"><i class="bi bi-shield-check text-success"></i> Server time · Africa/Kigali (UTC+2). Your device clock is not used.</div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small text-muted">Status now</span>
                <span><?= tt_badge($calc['status']); ?> <?= $calc['punctuality'] ? tt_badge($calc['punctuality']) : ''; ?></span>
            </div>
            <?php if ($calc['next']) { ?>
                <div class="small text-muted"><?= tt_e($calc['next']['label']); ?> at <strong><?= tt_hm12($calc['next']['at']); ?></strong></div>
                <div class="tt-count" data-rm-countdown="<?= $ms($calc['next']['at']); ?>" data-refresh-at-zero>—</div>
            <?php } elseif ($plan && $ev['sign_out']) { ?>
                <div class="fw-semibold">Day completed. Signed out at <?= tt_hm12($ev['sign_out']); ?>.</div>
            <?php } elseif ($plan) { ?>
                <div class="fw-semibold"><?= tt_e($plan['day_label'] ?: 'No working time today.'); ?></div>
            <?php } ?>
            <?php if ($plan && $plan['day_type'] === 'work' && $ev['check_in'] && !$ev['sign_out']) { $rem = tt_allowed_departure($plan, $ex); ?>
                <div class="small text-muted mt-2">Remaining working time: <strong data-rm-countdown="<?= $ms($rem); ?>">—</strong> (until <?= tt_hm12($rem); ?>)</div>
            <?php } ?>
            <?php if ($openBreak) { $due = tt_break_due($openBreak, $ex); ?>
                <div class="small mt-1">Remaining <?= tt_e(strtolower($openBreak['label'])); ?>: <strong data-rm-countdown="<?= $ms($due); ?>">—</strong> · return by <strong><?= tt_hm12($due); ?></strong></div>
            <?php } ?>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body tt-act">
            <div class="small text-muted mb-2">Record (official time is used)</div>
            <?php if (!$plan) { ?>
                <div class="text-muted">You have no work timetable yet. Your administrator will set it.</div>
            <?php } else {
                $buttons = [];
                if (!$ev['check_in']) { $buttons[] = ['check_in', 0, 'Check In', 'bi-box-arrow-in-right', 'primary']; }
                elseif (!$ev['sign_out']) {
                    if ($openBreak) { $buttons[] = ['break_end', $openBreak['id'], 'RETURN TO WORK / END BREAK', 'bi-arrow-return-left', 'success']; }
                    elseif ($onPost) {
                        if (empty($ev['post']['arrive_post'])) { $buttons[] = ['post_arrive', 0, 'Arrived at ' . $plan['post']['name'], 'bi-geo-alt', 'primary']; }
                        elseif (empty($ev['post']['leave_post'])) { $buttons[] = ['post_depart', 0, 'Leaving the post', 'bi-signpost', 'outline-primary']; }
                        $buttons[] = ['post_return', 0, 'Back at main workplace', 'bi-building-check', 'success'];
                    } else {
                        foreach ($plan['breaks'] as $b) {
                            if (empty($ev['breaks'][$b['id']]['start']) && $b['end'] > $now - 3600) { $buttons[] = ['break_start', $b['id'], 'START ' . strtoupper($b['label']), $b['type'] === 'lunch' ? 'bi-cup-hot' : 'bi-pause-circle', 'outline-primary']; }
                        }
                        if ($plan['post'] && empty($ev['post']['leave_main'])) { $buttons[] = ['post_leave', 0, 'Leave for ' . $plan['post']['name'], 'bi-truck', 'outline-primary']; }
                    }
                    $buttons[] = ['sign_out', 0, 'Sign Out', 'bi-box-arrow-right', 'danger'];
                }
                if (!$buttons) { echo '<div class="text-muted">Nothing to record now.</div>'; }
                foreach ($buttons as [$a, $ref, $label, $icon, $style]) {
                    [$ok, $why] = $can($a, $ref);
                    $confirm = $a === 'sign_out' ? ' onsubmit="return confirm(\'Sign out for today?\');"' : ''; ?>
                    <form method="POST" action="action.php" class="mb-2"<?= $confirm; ?>>
                        <?= tt_csrf_field(); ?><input type="hidden" name="action" value="<?= tt_e($a); ?>"><input type="hidden" name="ref" value="<?= (int) $ref; ?>">
                        <button type="submit" class="btn btn-<?= $style; ?><?= $ok ? '' : ' opacity-75'; ?>"><i class="bi <?= $icon; ?> me-1"></i><?= tt_e($label); ?></button>
                        <?php if (!$ok) { ?><div class="small text-muted mt-1"><i class="bi bi-info-circle"></i> <?= tt_e($why); ?></div><?php } ?>
                    </form>
            <?php } } ?>
            <?php if ($openBreak || $onPost) { ?>
                <a href="corrections.php?extension=1" class="small"><i class="bi bi-hourglass-split"></i> Ask your supervisor for more time</a>
            <?php } ?>
        </div></div>
    </div>
</div>

<?php if ($plan) { ?>
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Today's official timetable · <?= date('l, d M Y', strtotime($date)); ?></h6>
                <a href="timetable_pdf.php" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-file-earmark-pdf"></i> My timetable (PDF)</a>
            </div>
            <?php if ($plan['day_type'] !== 'work') { ?>
                <div class="text-muted"><?= tt_badge($calc['status']); ?> <?= tt_e($plan['day_label']); ?></div>
            <?php } else {
                $A = tt_allowed_arrival($plan, $ex);
                $steps = [['Reporting time', $A, $ev['check_in'], $A + 60 * $plan['grace_arrival']]];
                if ($plan['post']) {
                    $p = $plan['post'];
                    if ($p['leave_main']) { $steps[] = ['Leave for ' . $p['name'], $p['leave_main'], $ev['post']['leave_main'] ?? null, null]; }
                    if ($p['arrive']) { $steps[] = ['Arrive at ' . $p['name'], $p['arrive'], $ev['post']['arrive_post'] ?? null, null]; }
                    if ($p['leave_post']) { $steps[] = ['Leave the post', $p['leave_post'], $ev['post']['leave_post'] ?? null, null]; }
                    if ($p['return_main']) { $pd = tt_post_due($plan, $ex); $steps[] = ['Back at main workplace', $pd, $ev['post']['return_main'] ?? null, $pd + 60 * $plan['grace_return']]; }
                }
                foreach ($plan['breaks'] as $b) {
                    $due = tt_break_due($b, $ex);
                    $steps[] = [$b['label'] . ' starts', $b['start'], $ev['breaks'][$b['id']]['start'] ?? null, null];
                    $steps[] = [$b['label'] . ' ends (return)', $due, $ev['breaks'][$b['id']]['end'] ?? null, $due + 60 * $plan['grace_return']];
                }
                $steps[] = ['Official departure', tt_allowed_departure($plan, $ex), $ev['sign_out'], null];
                usort($steps, function ($a, $b) { return $a[1] <=> $b[1]; });
                $nowMarked = false;
                foreach ($steps as [$label, $at, $actual, $lateAfter]) {
                    $cls = $actual ? (($lateAfter && $actual > $lateAfter) ? 'late' : 'done') : '';
                    if (!$actual && !$nowMarked && $at >= $now - 300) { $cls = 'now'; $nowMarked = true; } ?>
                    <div class="tt-step <?= $cls; ?>">
                        <div class="d-flex justify-content-between"><span><?= tt_e($label); ?></span><strong><?= tt_hm12($at); ?></strong></div>
                        <?php if ($actual) { $diff = (int) round(($actual - $at) / 60); ?>
                            <div class="small <?= $cls === 'late' ? 'text-danger' : 'text-muted'; ?>">Recorded <?= tt_hm12($actual); ?><?= $diff ? ' (' . ($diff > 0 ? '+' : '') . $diff . ' min)' : ' (on time)'; ?></div>
                        <?php } ?>
                    </div>
            <?php } } ?>
            <?php if ($ex['labels']) { ?>
                <div class="mt-2 small"><strong>Approved exceptions today:</strong> <?= tt_e(implode(' · ', $ex['labels'])); ?></div>
            <?php } ?>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h6>Today in figures</h6>
            <div class="row g-2 small">
                <?php foreach ([['Scheduled working time', tt_dur($calc['scheduled_minutes'])], ['Time worked so far', tt_dur($calc['worked_minutes'])],
                    ['Authorized break', tt_dur($calc['authorized_break_minutes'])], ['Actual break', tt_dur($calc['actual_break_minutes'])],
                    ['Late arrival', tt_dur($calc['late_arrival_minutes'])], ['Excess break', tt_dur($calc['excess_break_minutes'])],
                    ['Overtime', tt_dur($calc['overtime_minutes']) . ($overtimeToday ? ' (' . $overtimeToday['status'] . ')' : '')], ['Lost working time', tt_dur($calc['lost_minutes'])]] as [$k, $v]) { ?>
                    <div class="col-6"><div class="p-2 rounded" style="background:#F4F6FB;"><div class="text-muted"><?= tt_e($k); ?></div><div class="fw-bold <?= $k === 'Lost working time' && $calc['lost_minutes'] ? 'text-danger' : ''; ?>"><?= tt_e($v); ?></div></div></div>
                <?php } ?>
            </div>
            <?php if ($calc['lost']) { ?>
                <div class="mt-3 small"><strong>Where the lost time comes from</strong>
                    <?php foreach ($calc['lost'] as [$s, $e, $r]) { ?>
                        <div class="d-flex justify-content-between border-bottom py-1"><span><?= tt_e(TT_LOST_REASONS[$r] ?? $r); ?></span><span><?= tt_hm($s); ?>–<?= tt_hm($e); ?> · <strong><?= tt_dur(tt_min($e - $s)); ?></strong></span></div>
                    <?php } ?>
                    <div class="text-muted mt-1">Recorded as unworked time for evaluation. Any salary effect follows the approved payroll policy. Approved exceptions are never counted.</div>
                </div>
            <?php } ?>
            <?php foreach ($calc['flags'] as $f) { ?><div class="alert alert-danger py-1 px-2 small mt-2 mb-0"><?= tt_e($f); ?> <a href="corrections.php?date=<?= tt_e($date); ?>">Request a correction</a></div><?php } ?>
        </div></div>
    </div>
</div>
<?php } ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">This month (<?= date('F Y', $now); ?>)</h6>
                <a href="corrections.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil-square"></i> Forgot to record? Request a correction</a></div>
            <?php if ($month) { ?>
            <div class="row g-2 small mb-3">
                <?php foreach ([['Attendance', $month['attendance_pct'] !== null ? $month['attendance_pct'] . '%' : '—'], ['Punctual days', $month['punctuality_pct'] !== null ? $month['punctuality_pct'] . '%' : '—'],
                    ['Late arrivals', $month['late_days'] . ' (' . tt_dur($month['late_minutes']) . ')'], ['Excess breaks', tt_dur($month['excess_break'])],
                    ['Lost working time', tt_dur($month['lost'])], ['Approved overtime', tt_dur($month['overtime_approved'])],
                    ['Overtime waiting', tt_dur($month['overtime_pending'])], ['Hours worked', tt_dur($month['worked'])]] as [$k, $v]) { ?>
                    <div class="col-6 col-md-3"><div class="p-2 rounded" style="background:#F4F6FB;"><div class="text-muted"><?= tt_e($k); ?></div><div class="fw-bold"><?= tt_e($v); ?></div></div></div>
                <?php } ?>
            </div>
            <?php } ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
                <tr><th>Date</th><th>Status</th><th>In</th><th>Out</th><th>Worked</th><th>Late</th><th>Excess break</th><th>Lost</th><th></th></tr>
                <?php $shown = 0; foreach ($recent as $d => $c) { if (!$c['plan'] || ($c['plan']['day_type'] === 'off' && !$c['events']['check_in'])) { continue; } if (++$shown > 12) { break; } ?>
                    <tr><td><?= date('D d M', strtotime($d)); ?></td><td><?= tt_badge($c['status']); ?></td>
                        <td><?= tt_hm($c['events']['check_in']); ?></td><td><?= tt_hm($c['events']['sign_out']); ?></td>
                        <td><?= tt_dur($c['worked_minutes']); ?></td><td><?= $c['late_arrival_minutes'] ? tt_dur($c['late_arrival_minutes']) : '—'; ?></td>
                        <td><?= $c['excess_break_minutes'] ? tt_dur($c['excess_break_minutes']) : '—'; ?></td>
                        <td class="<?= $c['lost_minutes'] ? 'text-danger fw-semibold' : ''; ?>"><?= $c['lost_minutes'] ? tt_dur($c['lost_minutes']) : '—'; ?></td>
                        <td><a href="day.php?date=<?= tt_e($d); ?>" class="small">Details</a></td></tr>
                <?php } if (!$shown) { ?><tr><td colspan="9" class="text-center text-muted py-3">No working days recorded yet this month.</td></tr><?php } ?>
            </table></div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6>Next working day</h6>
            <?php if ($next) { ?>
                <div class="fw-semibold"><?= date('l, d F Y', strtotime($next['date'])); ?></div>
                <div class="small">Reporting: <strong><?= tt_hm12($next['report']); ?></strong></div>
                <?php if ($next['post'] && $next['post']['leave_main']) { ?><div class="small">Assigned post: <?= tt_e($next['post']['name']); ?>, <?= tt_hm12($next['post']['arrive'] ?: $next['post']['leave_main']); ?></div><?php } ?>
                <?php foreach ($next['breaks'] as $b) { ?><div class="small"><?= tt_e($b['label']); ?>: <?= tt_hm12($b['start']); ?> – <?= tt_hm12($b['end']); ?></div><?php } ?>
                <div class="small">Departure: <strong><?= tt_hm12($next['departure']); ?></strong></div>
            <?php } else { ?><div class="small text-muted">None scheduled in the next weeks.</div><?php } ?>
        </div></div>
        <?php if ($myTasks) { ?>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body d-flex justify-content-between align-items-center">
            <div><h6 class="mb-1">My tasks</h6><div class="small"><?= (int) $myTasks['open_n']; ?> open<?= (int) $myTasks['due_n'] ? ' · <strong class="text-danger">' . (int) $myTasks['due_n'] . ' due today or overdue</strong>' : ''; ?></div></div>
            <a href="../tasks/index.php" class="btn btn-sm btn-outline-primary">Open</a>
        </div></div>
        <?php } ?>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <h6>My requests</h6>
            <?php if (!$myCorrections && !$myExt) { ?><div class="small text-muted">None.</div><?php } ?>
            <?php foreach ($myExt as $x) { ?><div class="small border-top py-1">More time (<?= tt_e($x['kind']); ?>) until <?= date('H:i', strtotime($x['new_end'])); ?> · <strong><?= tt_e($x['status']); ?></strong></div><?php } ?>
            <?php foreach ($myCorrections as $c) { ?><div class="small border-top py-1"><?= date('d M', strtotime($c['work_date'])); ?> · <?= tt_e(TT_EVENT_LABELS[$c['event_type']] ?? $c['event_type']); ?> <?= date('H:i', strtotime($c['requested_time'])); ?> · <strong><?= tt_e($c['status']); ?></strong></div><?php } ?>
        </div></div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>