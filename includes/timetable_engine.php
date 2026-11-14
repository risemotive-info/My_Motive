<?php
/**
 * NEW FILE: includes/timetable_engine.php
 * WORK TIMETABLE: the calculation engine. NO database here: every function works on plain arrays and
 * Unix timestamps, so the same rules are used by the employee dashboard, the admin board, reports, payroll,
 * reminders and the automatic tests (tests/timetable_engine_test.php) and can be checked without a server.
 *
 *   PLAN        what the employee's timetable says for one date (built by tt_engine_plan()):
 *               ['date', 'day_type' => work|off|holiday|leave, 'day_label', 'report', 'arrival', 'departure',
 *                'breaks' => [['id','label','type','start','end']], 'post' => null|['name','leave_main','arrive',
 *                'leave_post','return_main'], 'grace_arrival' (min), 'grace_return' (min)]
 *   EVENTS      what was recorded (server time): ['check_in' => ts|null, 'sign_out' => ts|null,
 *               'breaks' => [breakId => ['start' => ts, 'end' => ts|null]],
 *               'post' => ['leave_main' => ts, 'arrive_post' => ts, 'leave_post' => ts, 'return_main' => ts]]
 *   EXCEPTIONS  APPROVED exceptions only (leave requests, supervisor extensions):
 *               ['late_until' => ts|null, 'early_from' => ts|null, 'break_ext' => [breakId => ts],
 *                'post_ext' => ts|null, 'windows' => [[start, end, label]]]
 *
 * Lost working time is built as time INTERVALS (late arrival, late return, overdue break, early departure ...).
 * Approved exception windows are then cut out of them, so an approved exception can never count as lost time,
 * and the same minute is never counted twice.
 */

const TT_DEFAULTS = [
    'grace_arrival'      => 5,    // minutes after reporting time before an arrival counts as late
    'grace_return'       => 5,    // minutes after a break / post return time before it counts as late
    'checkin_opens'      => 120,  // check-in opens this many minutes before reporting time
    'break_early_allow'  => 5,    // a break may be started this many minutes before its start time
    'lead_report'        => 30,   // reminder before reporting time
    'lead_break'         => 10,   // reminder before a break starts
    'lead_break_end'     => 15,   // reminder before a break ends
    'lead_post'          => 10,   // reminder before leaving for the assigned post
    'lead_post_return'   => 15,   // reminder before the return from the assigned post
    'lead_departure'     => 15,   // reminder before the official departure time
    'reminder_window'    => 60,   // a reminder older than this (server was down ...) is not sent any more
    'overtime_min'       => 15,   // overtime shorter than this is not proposed for approval
];

const TT_LOST_REASONS = [
    'late_arrival'     => 'Late arrival',
    'not_reported'     => 'Not reported yet',
    'absent'           => 'Absent (no check-in)',
    'early_break'      => 'Break started early',
    'late_return'      => 'Late return from break',
    'missing_return'   => 'Break return not recorded',
    'post_late_return' => 'Late return from assigned post',
    'post_overdue'     => 'Post return not recorded',
    'early_departure'  => 'Early departure',
];

const TT_STATUS_STYLE = [
    'Scheduled' => 'secondary', 'Not Reported' => 'danger', 'Absent' => 'danger', 'Working' => 'primary', 'On Time' => 'success',
    'Late' => 'danger', 'Break Approaching' => 'info', 'On Break' => 'info', 'Return Due' => 'primary', 'Overdue' => 'danger',
    'Returned Late' => 'danger', 'On Assigned Post' => 'info', 'Post Return Overdue' => 'danger', 'Completed' => 'success',
    'Overtime' => 'dark', 'Off Day' => 'light', 'Holiday' => 'light', 'On Leave' => 'light', 'No Schedule' => 'light',
];

/* ------------------------------------------------------------------ time helpers */

/** Timestamp of a clock time ('HH:MM' or 'HH:MM:SS') on a date (server time zone = Africa/Kigali). */
function tt_at(string $date, ?string $time): ?int
{
    if ($time === null || $time === '') { return null; }
    $ts = strtotime($date . ' ' . substr($time, 0, 8));
    return $ts === false ? null : $ts;
}

function tt_hm(?int $ts): string { return $ts ? date('H:i', $ts) : '—'; }
function tt_hm12(?int $ts): string { return $ts ? date('g:i A', $ts) : '—'; }

/** 135 -> "2h 15min", 20 -> "20 min", 0 -> "0 min". */
function tt_dur(int $minutes): string
{
    $neg = $minutes < 0; $m = abs($minutes);
    $s = $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'min' : '') : $m . ' min';
    return ($neg ? '-' : '') . $s;
}

function tt_min(int $seconds): int { return (int) floor(max(0, $seconds) / 60); }

/** ISO weekday 1 (Mon) .. 7 (Sun). */
function tt_weekday(string $date): int { return (int) date('N', strtotime($date)); }

/* ------------------------------------------------------------------ plan */

/**
 * Builds the PLAN of one date from a schedule row (+ its breaks).
 * $dayType / $dayLabel come from the caller (holiday, approved full-day leave), $defaults from the settings.
 * A departure earlier than the reporting time is a night shift: it is on the next day (and so are later breaks).
 */
function tt_engine_plan(?array $schedule, array $breaks, string $date, string $dayType = 'work', string $dayLabel = '', array $defaults = []): ?array
{
    if (!$schedule) { return null; }
    $d = $defaults + TT_DEFAULTS;
    $days = array_map('intval', array_filter(explode(',', (string) $schedule['working_days']), 'strlen'));
    $wd = tt_weekday($date);
    if ($dayType === 'work' && !in_array($wd, $days, true)) { $dayType = 'off'; $dayLabel = $dayLabel ?: 'Not a working day'; }
    // Different hours on this weekday (e.g. Monday 8:00-17:00, Tuesday 9:00-18:00): they replace the usual hours.
    $dt = $schedule['day_times'][$wd] ?? null;
    if ($dt && !empty($dt['report_time']) && !empty($dt['departure_time'])) {
        $schedule['report_time'] = $dt['report_time'];
        $schedule['arrival_time'] = $dt['arrival_time'] ?? null;
        $schedule['departure_time'] = $dt['departure_time'];
    }
    // Breaks: those for every day (weekday 0) and those for this weekday only.
    $breaks = array_values(array_filter($breaks, function ($b) use ($wd) { $w = (int) ($b['weekday'] ?? 0); return $w === 0 || $w === $wd; }));

    $report = tt_at($date, $schedule['report_time']);
    $roll = function (?string $t) use ($date, $report): ?int {
        $ts = tt_at($date, $t);
        return ($ts !== null && $report !== null && $ts < $report) ? $ts + 86400 : $ts;
    };
    $plan = [
        'date' => $date, 'day_type' => $dayType, 'day_label' => $dayLabel,
        'schedule_id' => (int) ($schedule['id'] ?? 0), 'schedule_kind' => $schedule['kind'] ?? 'permanent',
        'report' => $report, 'arrival' => $roll($schedule['arrival_time'] ?? null) ?? $report,
        'departure' => $roll($schedule['departure_time']),
        'grace_arrival' => isset($schedule['grace_arrival']) && $schedule['grace_arrival'] !== null && $schedule['grace_arrival'] !== '' ? (int) $schedule['grace_arrival'] : (int) $d['grace_arrival'],
        'grace_return' => isset($schedule['grace_return']) && $schedule['grace_return'] !== null && $schedule['grace_return'] !== '' ? (int) $schedule['grace_return'] : (int) $d['grace_return'],
        'breaks' => [], 'post' => null,
    ];
    foreach ($breaks as $b) {
        $s = $roll($b['start_time']); $e = $roll($b['end_time']);
        if ($s === null || $e === null) { continue; }
        if ($e <= $s) { $e += 86400; }
        $plan['breaks'][] = ['id' => (int) $b['id'], 'label' => (string) ($b['label'] ?: 'Break'), 'type' => (string) ($b['break_type'] ?? 'break'), 'start' => $s, 'end' => $e];
    }
    usort($plan['breaks'], function ($a, $b) { return $a['start'] <=> $b['start']; });
    if (!empty($schedule['post_leave_main']) || !empty($schedule['post_arrive'])) {
        $plan['post'] = [
            'name' => (string) ($schedule['post_name'] ?? 'Assigned post'),
            'leave_main' => $roll($schedule['post_leave_main'] ?? null), 'arrive' => $roll($schedule['post_arrive'] ?? null),
            'leave_post' => $roll($schedule['post_leave'] ?? null), 'return_main' => $roll($schedule['post_return_main'] ?? null),
        ];
    }
    return $plan;
}

function tt_engine_scheduled_minutes(array $plan): int
{
    if ($plan['day_type'] !== 'work' || !$plan['report'] || !$plan['departure']) { return 0; }
    $m = tt_min($plan['departure'] - $plan['report']);
    foreach ($plan['breaks'] as $b) { $m -= tt_min($b['end'] - $b['start']); }
    return max(0, $m);
}

function tt_engine_break_minutes(array $plan): int
{
    $m = 0;
    foreach ($plan['breaks'] as $b) { $m += tt_min($b['end'] - $b['start']); }
    return $m;
}

/* ------------------------------------------------------------------ intervals */

/** Removes the $cuts intervals from $intervals ([start, end, reason] each). */
function tt_cut(array $intervals, array $cuts): array
{
    foreach ($cuts as $c) {
        [$cs, $ce] = $c;
        if ($ce <= $cs) { continue; }
        $out = [];
        foreach ($intervals as $iv) {
            [$s, $e, $r] = $iv;
            if ($ce <= $s || $cs >= $e) { $out[] = $iv; continue; }
            if ($cs > $s) { $out[] = [$s, $cs, $r]; }
            if ($ce < $e) { $out[] = [$ce, $e, $r]; }
        }
        $intervals = $out;
    }
    return $intervals;
}

function tt_norm_events(array $ev): array
{
    return $ev + ['check_in' => null, 'sign_out' => null, 'breaks' => [], 'post' => []];
}

function tt_norm_exceptions(array $ex): array
{
    return $ex + ['late_until' => null, 'early_from' => null, 'break_ext' => [], 'post_ext' => null, 'windows' => [], 'labels' => []];
}

/** Break return time allowed after approved extensions. */
function tt_break_due(array $b, array $ex): int
{
    return max($b['end'], (int) ($ex['break_ext'][$b['id']] ?? 0));
}

/** Arrival allowed until: reporting time, moved by an approved late arrival or a window that covers it. */
function tt_allowed_arrival(array $plan, array $ex): int
{
    $a = max((int) $plan['report'], (int) ($ex['late_until'] ?? 0));
    $ws = $ex['windows'] ?? [];
    usort($ws, function ($x, $y) { return $x[0] <=> $y[0]; });
    foreach ($ws as $w) { if ($w[0] <= $a && $w[1] > $a) { $a = (int) $w[1]; } }
    return $a;
}

function tt_post_due(array $plan, array $ex): ?int
{
    if (!$plan['post'] || !$plan['post']['return_main']) { return null; }
    return max($plan['post']['return_main'], (int) ($ex['post_ext'] ?? 0));
}

/** The departure time the employee may sign out from (an approved early departure moves it earlier). */
function tt_allowed_departure(array $plan, array $ex): int
{
    $d = (int) $plan['departure'];
    if (!empty($ex['early_from']) && $ex['early_from'] < $d) { $d = (int) $ex['early_from']; }
    // An approved window (short leave, external assignment) that reaches the end of the day also allows leaving.
    foreach ($ex['windows'] as $w) { if ($w[1] >= $plan['departure'] && $w[0] < $d) { $d = (int) $w[0]; } }
    return $d;
}

function tt_open_break(array $plan, array $ev): ?array
{
    foreach ($plan['breaks'] as $b) {
        $r = $ev['breaks'][$b['id']] ?? null;
        if ($r && !empty($r['start']) && empty($r['end'])) { return $b; }
    }
    return null;
}

function tt_on_post(array $plan, array $ev): bool
{
    return $plan['post'] && !empty($ev['post']['leave_main']) && empty($ev['post']['return_main']) && !empty($plan['post']['return_main']);
}

/* ------------------------------------------------------------------ the day calculation */

/**
 * Everything about one employee's day at the moment $now.
 * Returns minutes (scheduled, worked, breaks, late, excess, lost ...), the lost-time intervals with their reasons,
 * flags for review and the live status shown on the dashboards.
 */
function tt_engine_compute(?array $plan, array $ev, array $ex, int $now, array $settings = []): array
{
    $ev = tt_norm_events($ev); $ex = tt_norm_exceptions($ex);
    $set = $settings + TT_DEFAULTS;
    $out = [
        'status' => 'No Schedule', 'punctuality' => null, 'scheduled_minutes' => 0, 'worked_minutes' => 0,
        'authorized_break_minutes' => 0, 'actual_break_minutes' => 0, 'excess_break_minutes' => 0,
        'late_arrival_minutes' => 0, 'late_return_minutes' => 0, 'early_departure_minutes' => 0, 'absence_minutes' => 0,
        'post_delay_minutes' => 0, 'overtime_minutes' => 0, 'lost_minutes' => 0, 'lost' => [], 'lost_by_reason' => [],
        'flags' => [], 'excused' => $ex['labels'], 'next' => null,
    ];
    if (!$plan) { return $out; }
    if ($plan['day_type'] !== 'work') {
        $out['status'] = ['off' => 'Off Day', 'holiday' => 'Holiday', 'leave' => 'On Leave'][$plan['day_type']] ?? 'Off Day';
        if ($ev['check_in']) {   // worked on a day off: everything is overtime (subject to approval)
            $end = $ev['sign_out'] ?: $now;
            $out['worked_minutes'] = tt_min($end - $ev['check_in']);
            $out['overtime_minutes'] = $out['worked_minutes'];
            $out['status'] = $ev['sign_out'] ? 'Completed' : 'Overtime';
        }
        return $out;
    }

    $R = (int) $plan['report']; $D = (int) $plan['departure'];
    $gA = 60 * (int) $plan['grace_arrival']; $gR = 60 * (int) $plan['grace_return'];
    $A = tt_allowed_arrival($plan, $ex);                  // arrival allowed until (approved late arrival / window)
    $Dallow = tt_allowed_departure($plan, $ex);
    $ci = $ev['check_in']; $so = $ev['sign_out'];
    $dayOver = $so !== null || $now >= $D;
    $lost = [];

    $out['scheduled_minutes'] = tt_engine_scheduled_minutes($plan);
    $breakCuts = array_map(function ($b) { return [$b['start'], $b['end']]; }, $plan['breaks']);

    // 1. Arrival
    if (!$ci) {
        if ($now >= $A + $gA) {
            if ($now >= $D) {
                $lost = array_merge($lost, tt_cut([[$R, $D, 'absent']], $breakCuts));
                $out['flags'][] = 'No check-in recorded for a scheduled working day.';
            } else {
                $lost = array_merge($lost, tt_cut([[$A, $now, 'not_reported']], $breakCuts));
            }
        }
    } else {
        $out['late_arrival_minutes'] = tt_min($ci - $A);
        if ($ci > $A + $gA) { $lost[] = [$A, $ci, 'late_arrival']; }
        $out['punctuality'] = $ci > $A + $gA ? 'Late' : 'On Time';
    }

    // 2. Breaks
    $openBreak = null;
    foreach ($plan['breaks'] as $b) {
        $due = tt_break_due($b, $ex);
        $out['authorized_break_minutes'] += tt_min($due - $b['start']);
        $r = $ev['breaks'][$b['id']] ?? null;
        if (!$r || empty($r['start'])) { continue; }
        $st = (int) $r['start']; $en = !empty($r['end']) ? (int) $r['end'] : null;
        $out['actual_break_minutes'] += tt_min(($en ?? $now) - $st);
        if ($st < $b['start'] - $gR) { $lost[] = [$st, $b['start'], 'early_break']; }
        if ($en !== null) {
            $out['late_return_minutes'] += tt_min($en - $due);
            if ($en > $due + $gR) { $lost[] = [$due, $en, 'late_return']; }
        } else {
            $openBreak = $b;
            if ($now > $due + $gR) {
                $lost[] = [$due, max($due, min($now, $D)), 'missing_return'];
                if ($dayOver) { $out['flags'][] = 'Return from "' . $b['label'] . '" was never recorded: correction needed.'; }
            }
        }
    }

    // 3. Assigned post / field duty
    $postDue = tt_post_due($plan, $ex);
    if ($postDue && !empty($ev['post']['leave_main'])) {
        $ret = $ev['post']['return_main'] ?? null;
        if ($ret) {
            if ($ret > $postDue + $gR) { $lost[] = [$postDue, $ret, 'post_late_return']; }
        } elseif ($now > $postDue + $gR) {
            $lost[] = [$postDue, max($postDue, min($now, $D)), 'post_overdue'];
            if ($dayOver) { $out['flags'][] = 'Return from the assigned post was never recorded: correction needed.'; }
        }
    }

    // 4. Departure
    if ($so && $so < $Dallow) { $lost[] = [$so, $Dallow, 'early_departure']; }
    if ($ci) {
        $end = $so ?: $now;
        $out['worked_minutes'] = max(0, tt_min($end - $ci) - $out['actual_break_minutes']);
        if ($end > $D) { $out['overtime_minutes'] = tt_min($end - max($D, $ci)); }
    }

    // 5. Scheduled breaks that were not taken (because the employee was not there yet, already gone, or out at the
    //    post) are break time, not lost working time.
    $untaken = [];
    foreach ($plan['breaks'] as $b) { if (empty($ev['breaks'][$b['id']]['start'])) { $untaken[] = [$b['start'], tt_break_due($b, $ex)]; } }
    $lost = array_merge(
        array_filter($lost, function ($iv) { return !in_array($iv[2], ['late_arrival', 'early_departure', 'post_late_return', 'post_overdue'], true); }),
        tt_cut(array_values(array_filter($lost, function ($iv) { return in_array($iv[2], ['late_arrival', 'early_departure', 'post_late_return', 'post_overdue'], true); })), $untaken)
    );

    // 6. Approved exceptions are never lost time.
    $windows = array_map(function ($w) { return [(int) $w[0], (int) $w[1]]; }, $ex['windows']);
    if ($Dallow < $D) { $windows[] = [$Dallow, $D]; }
    $lost = tt_cut($lost, $windows);
    $lost = array_values(array_filter($lost, function ($iv) { return $iv[1] - $iv[0] >= 60; }));

    $by = [];
    foreach ($lost as $iv) { $by[$iv[2]] = ($by[$iv[2]] ?? 0) + tt_min($iv[1] - $iv[0]); }
    $out['lost'] = $lost; $out['lost_by_reason'] = $by;
    $out['lost_minutes'] = array_sum($by);
    $out['excess_break_minutes'] = ($by['early_break'] ?? 0) + ($by['late_return'] ?? 0) + ($by['missing_return'] ?? 0);
    $out['early_departure_minutes'] = $by['early_departure'] ?? 0;
    $out['absence_minutes'] = ($by['absent'] ?? 0) + ($by['not_reported'] ?? 0);
    $out['post_delay_minutes'] = ($by['post_late_return'] ?? 0) + ($by['post_overdue'] ?? 0);
    // Minutes late = from the allowed arrival time to the check-in (only when it counts as late at all).
    $out['late_arrival_minutes'] = ($ci && isset($by['late_arrival'])) ? tt_min($ci - $A) : 0;

    // 7. Live status
    $out['status'] = tt_engine_status($plan, $ev, $ex, $now, $set, $out, $openBreak);
    $out['next'] = tt_engine_next($plan, $ev, $ex, $now);
    return $out;
}

function tt_engine_status(array $plan, array $ev, array $ex, int $now, array $set, array $calc, ?array $openBreak): string
{
    $R = (int) $plan['report']; $D = (int) $plan['departure'];
    $A = tt_allowed_arrival($plan, $ex); $gA = 60 * (int) $plan['grace_arrival']; $gR = 60 * (int) $plan['grace_return'];
    if (!$ev['check_in']) {
        if ($now < $A + $gA) { return 'Scheduled'; }
        return $now >= $D ? 'Absent' : 'Not Reported';
    }
    if ($ev['sign_out']) { return 'Completed'; }
    if ($openBreak) {
        $due = tt_break_due($openBreak, $ex);
        if ($now > $due + $gR) { return 'Overdue'; }
        if ($now >= $due - 60 * (int) $set['lead_break_end']) { return 'Return Due'; }
        return 'On Break';
    }
    if (tt_on_post($plan, $ev)) {
        $due = (int) tt_post_due($plan, $ex);
        return $now > $due + $gR ? 'Post Return Overdue' : 'On Assigned Post';
    }
    if ($now >= $D) { return 'Overtime'; }
    foreach ($plan['breaks'] as $b) {
        $r = $ev['breaks'][$b['id']] ?? null;
        if ((!$r || empty($r['start'])) && $now >= $b['start'] - 60 * (int) $set['lead_break'] && $now < $b['end']) { return 'Break Approaching'; }
    }
    foreach ($plan['breaks'] as $b) {
        $r = $ev['breaks'][$b['id']] ?? null;
        if ($r && !empty($r['end']) && $r['end'] > tt_break_due($b, $ex) + $gR && $now - $r['end'] < 3600) { return 'Returned Late'; }
    }
    return 'Working';
}

/** The next thing on the employee's timetable: ['label', 'at'] (for the countdown), or null. */
function tt_engine_next(array $plan, array $ev, array $ex, int $now): ?array
{
    if ($plan['day_type'] !== 'work') { return null; }
    if (!$ev['check_in']) { return ['label' => 'Reporting time', 'at' => tt_allowed_arrival($plan, $ex)]; }
    if ($ev['sign_out']) { return null; }
    if ($b = tt_open_break($plan, $ev)) { return ['label' => $b['label'] . ' ends — return to work', 'at' => tt_break_due($b, $ex)]; }
    if (tt_on_post($plan, $ev)) { return ['label' => 'Return from ' . $plan['post']['name'], 'at' => (int) tt_post_due($plan, $ex)]; }
    $cands = [];
    foreach ($plan['breaks'] as $b) {
        $r = $ev['breaks'][$b['id']] ?? null;
        if ((!$r || empty($r['start'])) && $b['end'] > $now) { $cands[] = ['label' => $b['label'] . ' starts', 'at' => $b['start']]; }
    }
    if ($plan['post'] && empty($ev['post']['leave_main']) && $plan['post']['leave_main'] && $plan['post']['leave_main'] > $now - 3600) {
        $cands[] = ['label' => 'Leave for ' . $plan['post']['name'], 'at' => $plan['post']['leave_main']];
    }
    $cands[] = ['label' => 'Official departure', 'at' => tt_allowed_departure($plan, $ex)];
    usort($cands, function ($a, $b) { return $a['at'] <=> $b['at']; });
    return $cands[0];
}

/* ------------------------------------------------------------------ permission rules */

/**
 * May the employee record $action now? Returns [true, ''] or [false, 'clear message for the employee'].
 * Actions: check_in, break_start, break_end (with $ref = break id), post_leave, post_arrive, post_depart,
 * post_return, sign_out.
 */
function tt_engine_can(?array $plan, array $ev, array $ex, int $now, string $action, int $ref = 0, array $settings = []): array
{
    $ev = tt_norm_events($ev); $ex = tt_norm_exceptions($ex);
    $set = $settings + TT_DEFAULTS;
    if (!$plan) { return [false, 'You have no work timetable yet. Please contact your administrator.']; }
    if ($plan['day_type'] !== 'work' && $action !== 'sign_out') {
        if ($action === 'check_in') {
            return [false, 'Today is not a scheduled working day for you (' . ($plan['day_label'] ?: 'day off') . '). Working today needs an approved schedule adjustment.'];
        }
    }
    $ci = $ev['check_in']; $so = $ev['sign_out'];
    $open = tt_open_break($plan, $ev);

    switch ($action) {
        case 'check_in':
            if ($ci) { return [false, 'Your check-in was already recorded today at ' . tt_hm12($ci) . '.']; }
            $opens = (int) $plan['report'] - 60 * (int) $set['checkin_opens'];
            if ($now < $opens) { return [false, 'Check-in opens at ' . tt_hm12($opens) . ' (your reporting time is ' . tt_hm12($plan['report']) . ').']; }
            if ($now >= $plan['departure']) { return [false, 'Your scheduled working hours for today have ended (' . tt_hm12($plan['departure']) . '). A missing check-in needs a correction request.']; }
            return [true, ''];

        case 'break_start':
            $b = null; foreach ($plan['breaks'] as $x) { if ($x['id'] === $ref) { $b = $x; } }
            if (!$b) { return [false, 'This break is not on your timetable today.']; }
            if (!$ci) { return [false, 'Please check in before starting a break.']; }
            if ($so) { return [false, 'You have already signed out for today.']; }
            if ($open) { return [false, 'You are already on "' . $open['label'] . '". Record your return first.']; }
            if (tt_on_post($plan, $ev)) { return [false, 'You are recorded at your assigned post. Record your return first.']; }
            if (!empty($ev['breaks'][$b['id']]['start'])) { return [false, 'Your "' . $b['label'] . '" was already taken today (from ' . tt_hm12($ev['breaks'][$b['id']]['start']) . ').']; }
            $from = $b['start'] - 60 * (int) $set['break_early_allow'];
            $due = tt_break_due($b, $ex);
            if ($now < $from) {
                return [false, 'You are not yet authorized to start your ' . $b['label'] . '. It is scheduled from ' . tt_hm12($b['start']) . ' to ' . tt_hm12($due) . '. You can start it from ' . tt_hm12($from) . '.'];
            }
            if ($now >= $due) { return [false, 'Your ' . $b['label'] . ' period (' . tt_hm12($b['start']) . ' – ' . tt_hm12($due) . ') has ended. It can no longer be started today.']; }
            return [true, ''];

        case 'break_end':
            if (!$open || ($ref && $open['id'] !== $ref)) { return [false, 'You are not on a break.']; }
            return [true, ''];

        case 'post_leave':
            if (!$plan['post']) { return [false, 'You have no assigned post on your timetable today.']; }
            if (!$ci) { return [false, 'Please check in before leaving for your assigned post.']; }
            if ($so) { return [false, 'You have already signed out for today.']; }
            if ($open) { return [false, 'You are on a break. Record your return first.']; }
            if (!empty($ev['post']['leave_main'])) { return [false, 'Your departure to the assigned post was already recorded at ' . tt_hm12($ev['post']['leave_main']) . '.']; }
            if ($plan['post']['leave_main'] && $now < $plan['post']['leave_main'] - 60 * (int) $set['break_early_allow']) {
                return [false, 'You are scheduled to leave for ' . $plan['post']['name'] . ' at ' . tt_hm12($plan['post']['leave_main']) . '.'];
            }
            return [true, ''];
        case 'post_arrive':
            if (empty($ev['post']['leave_main'])) { return [false, 'Record your departure from the main workplace first.']; }
            if (!empty($ev['post']['arrive_post'])) { return [false, 'Your arrival at the post was already recorded.']; }
            return [true, ''];
        case 'post_depart':
            if (empty($ev['post']['arrive_post'])) { return [false, 'Record your arrival at the post first.']; }
            if (!empty($ev['post']['leave_post'])) { return [false, 'Your departure from the post was already recorded.']; }
            return [true, ''];
        case 'post_return':
            if (empty($ev['post']['leave_main'])) { return [false, 'You did not leave for an assigned post today.']; }
            if (!empty($ev['post']['return_main'])) { return [false, 'Your return was already recorded at ' . tt_hm12($ev['post']['return_main']) . '.']; }
            return [true, ''];

        case 'sign_out':
            if (!$ci) { return [false, 'You have not checked in today.']; }
            if ($so) { return [false, 'Your sign-out was already recorded at ' . tt_hm12($so) . '.']; }
            if ($open) { return [false, 'You are on "' . $open['label'] . '". Record your return to work before signing out.']; }
            if (tt_on_post($plan, $ev)) { return [false, 'Record your return from the assigned post before signing out.']; }
            if ($plan['day_type'] === 'work' && $now < tt_allowed_departure($plan, $ex)) {
                return [false, 'Your scheduled working hours have not yet been completed. Early sign-out requires administrative approval.'];
            }
            return [true, ''];
    }
    return [false, 'Unknown action.'];
}

/* ------------------------------------------------------------------ reminders */

/**
 * Reminders that are due at $now: [['kind', 'ref', 'at', 'to' => employee|supervisor, 'risk' => normal|high, 'title', 'message']].
 * A reminder is due from its time until 'reminder_window' minutes later; the caller sends each one only once.
 */
function tt_engine_due_reminders(?array $plan, array $ev, array $ex, int $now, array $settings = [], string $employeeName = ''): array
{
    if (!$plan || $plan['day_type'] !== 'work') { return []; }
    $ev = tt_norm_events($ev); $ex = tt_norm_exceptions($ex);
    $set = $settings + TT_DEFAULTS;
    $win = 60 * (int) $set['reminder_window'];
    $ci = $ev['check_in']; $so = $ev['sign_out'];
    $A = tt_allowed_arrival($plan, $ex);
    $gA = 60 * (int) $plan['grace_arrival']; $gR = 60 * (int) $plan['grace_return'];
    $list = [];
    $add = function (string $kind, int $ref, int $at, string $title, string $msg, string $to = 'employee', string $risk = 'normal') use (&$list, $now, $win) {
        if ($now >= $at && $now < $at + $win) { $list[] = compact('kind', 'ref', 'at', 'to', 'risk', 'title') + ['message' => $msg]; }
    };

    // Approved full-day leave / holiday never reach here (day_type). A day fully covered by a window: no reporting reminders.
    if (!$ci) {
        $add('pre_report', 0, $A - 60 * (int) $set['lead_report'], 'Prepare to report to work',
            'Reminder: your reporting time today is ' . tt_hm12($A) . '. Please plan to arrive on time.');
        $add('report', 0, $A, 'Official reporting time',
            'It is now ' . tt_hm12($A) . ', your official reporting time. Please record your check-in in MY MOTIVE.');
        $add('not_reported', 0, $A + $gA, 'You have not reported',
            'Your reporting time was ' . tt_hm12($A) . ' and no check-in has been recorded yet. Please report and check in immediately.', 'employee');
        $add('not_reported_sup', 0, $A + $gA, 'Employee has not reported',
            ($employeeName ?: 'An employee') . ' was due at ' . tt_hm12($A) . ' and has not checked in yet.', 'supervisor', 'high');
        return tt_latest_of_chains($list);
    }
    if ($so) { return []; }

    foreach ($plan['breaks'] as $b) {
        $r = $ev['breaks'][$b['id']] ?? null;
        $due = tt_break_due($b, $ex);
        $name = $b['label'];
        if (!$r || empty($r['start'])) {
            $add('break_pre', $b['id'], $b['start'] - 60 * (int) $set['lead_break'], 'Your ' . $name . ' begins in ' . (int) $set['lead_break'] . ' minutes',
                'Reminder: Your scheduled ' . strtolower($name) . ' will begin in ' . (int) $set['lead_break'] . ' minutes. Please prepare to proceed for your break at ' . tt_hm12($b['start']) . '.');
            $add('break_start', $b['id'], $b['start'], 'Scheduled ' . $name,
                'Your scheduled ' . strtolower($name) . ' starts now (' . tt_hm12($b['start']) . ' – ' . tt_hm12($due) . '). Record START BREAK in MY MOTIVE when you leave.');
        } elseif (empty($r['end'])) {
            $add('break_end_pre', $b['id'], $due - 60 * (int) $set['lead_break_end'], 'Your ' . $name . ' ends in ' . (int) $set['lead_break_end'] . ' minutes',
                'Reminder: Your ' . strtolower($name) . ' will end in ' . (int) $set['lead_break_end'] . ' minutes. Please prepare to return to your assigned work duties by ' . tt_hm12($due) . '.');
            $add('break_end', $b['id'], $due, 'Return to work',
                'Your ' . strtolower($name) . ' has ended (' . tt_hm12($due) . '). Please return to work and record RETURN TO WORK in MY MOTIVE.');
            $add('return_overdue', $b['id'], $due + $gR, 'Late return warning',
                'Your ' . strtolower($name) . ' ended at ' . tt_hm12($due) . ' and your return has not been recorded. The time is being recorded as unworked time.', 'employee', 'high');
            $add('return_overdue_sup', $b['id'], $due + $gR, 'Break return overdue',
                ($employeeName ?: 'An employee') . ' was due back from ' . strtolower($name) . ' at ' . tt_hm12($due) . ' and has not recorded a return.', 'supervisor', 'high');
        }
    }
    if ($plan['post']) {
        $p = $plan['post']; $pdue = tt_post_due($plan, $ex);
        if (empty($ev['post']['leave_main']) && $p['leave_main']) {
            $add('post_go', 0, $p['leave_main'] - 60 * (int) $set['lead_post'], 'Proceed to your assigned post',
                'Reminder: you are scheduled to leave for ' . $p['name'] . ' at ' . tt_hm12($p['leave_main']) . ($p['arrive'] ? ' and arrive by ' . tt_hm12($p['arrive']) : '') . '.');
        } elseif (tt_on_post($plan, $ev) && $pdue) {
            $add('post_return_pre', 0, $pdue - 60 * (int) $set['lead_post_return'], 'Return from your assigned post',
                'Reminder: you are expected back at the main workplace by ' . tt_hm12($pdue) . '.');
            $add('post_overdue', 0, $pdue + $gR, 'Post return overdue',
                'You were expected back from ' . $p['name'] . ' at ' . tt_hm12($pdue) . '. Your return has not been recorded.', 'employee', 'high');
            $add('post_overdue_sup', 0, $pdue + $gR, 'Assigned post return overdue',
                ($employeeName ?: 'An employee') . ' was expected back from ' . $p['name'] . ' at ' . tt_hm12($pdue) . ' and has not recorded a return.', 'supervisor', 'high');
        }
    }
    $Dallow = tt_allowed_departure($plan, $ex);
    $add('pre_departure', 0, $Dallow - 60 * (int) $set['lead_departure'], 'Prepare to complete your working hours',
        'Your working hours end at ' . tt_hm12($Dallow) . '. Please complete your tasks and prepare to sign out.');
    $add('departure', 0, $Dallow, 'Working hours completed',
        'Your official working hours are complete (' . tt_hm12($Dallow) . '). You may now sign out. Time worked after this is recorded as overtime, subject to approval.');
    return tt_latest_of_chains($list);
}

/**
 * Reminders that follow each other (before reporting -> reporting time -> not reported) are a CHAIN: when several of
 * one chain are due at once (the server was asleep, the first check of the day), only the latest is sent.
 */
function tt_latest_of_chains(array $list): array
{
    $chains = [['pre_report', 'report', 'not_reported'], ['break_pre', 'break_start'], ['break_end_pre', 'break_end', 'return_overdue'],
               ['post_return_pre', 'post_overdue'], ['pre_departure', 'departure']];
    $drop = [];
    foreach ($list as $i => $a) {
        foreach ($chains as $chain) {
            $pa = array_search($a['kind'], $chain, true);
            if ($pa === false) { continue; }
            foreach ($list as $b) {
                $pb = array_search($b['kind'], $chain, true);
                if ($pb !== false && $pb > $pa && $b['ref'] === $a['ref']) { $drop[$i] = true; }
            }
        }
    }
    return array_values(array_diff_key($list, $drop));
}

/* ------------------------------------------------------------------ schedule versions & next working day */

/**
 * Which schedule applies on $date: a TEMPORARY adjustment covering the date wins over the PERMANENT timetable;
 * among several, the newest. Cancelled versions never apply. Replaced versions still apply to their own dates,
 * which is how history stays exactly as it was.
 */
function tt_engine_pick_schedule(array $rows, string $date): ?array
{
    $best = null; $bestRank = -1;
    foreach ($rows as $r) {
        if (($r['status'] ?? 'active') === 'cancelled') { continue; }
        if ($r['valid_from'] > $date) { continue; }
        if (!empty($r['valid_to']) && $r['valid_to'] < $date) { continue; }
        $rank = (($r['kind'] ?? 'permanent') === 'temporary' ? 1000000000 : 0) + (int) $r['id'];
        if ($rank > $bestRank) { $best = $r; $bestRank = $rank; }
    }
    return $best;
}

/** The first date after $fromDate whose plan is a working day. $planFor(date) returns a PLAN or null. */
function tt_engine_next_working_day(callable $planFor, string $fromDate, int $maxDays = 60): ?array
{
    for ($i = 1; $i <= $maxDays; $i++) {
        $d = date('Y-m-d', strtotime($fromDate . ' +' . $i . ' day'));
        $p = $planFor($d);
        if ($p && $p['day_type'] === 'work') { return $p; }
    }
    return null;
}

/** The text shown right after sign-out (section 9). */
function tt_engine_next_day_text(?array $p): string
{
    if (!$p) { return 'No upcoming working day is scheduled in the next weeks. Please check with your administrator.'; }
    $lines = ['Your next working day is ' . date('l, j F Y', strtotime($p['date'])) . '.', 'Reporting time: ' . tt_hm12($p['report']) . '.'];
    if ($p['post'] && $p['post']['leave_main']) { $lines[] = 'Assigned post time: ' . tt_hm12($p['post']['arrive'] ?: $p['post']['leave_main']) . ' (' . $p['post']['name'] . ').'; }
    foreach ($p['breaks'] as $b) { $lines[] = $b['label'] . ': ' . tt_hm12($b['start']) . ' – ' . tt_hm12($b['end']) . '.'; }
    $lines[] = 'Expected departure: ' . tt_hm12($p['departure']) . '.';
    $lines[] = 'Please plan accordingly and arrive on time.';
    return implode("\n", $lines);
}