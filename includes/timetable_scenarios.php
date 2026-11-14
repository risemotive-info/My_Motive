<?php
/**
 * NEW FILE: includes/timetable_scenarios.php
 * The acceptance scenarios of the WORK TIMETABLE specification (section 17), as automatic checks of the engine.
 * Used by tests/timetable_engine_test.php (command line) and modules/timetable/selftest.php (on the server).
 * Example employee (from the specification): reports 7:00, assigned post 8:00–10:00, lunch 12:00–2:00 PM,
 * departure 5:00 PM, Monday to Friday, grace 5 minutes.
 */
require_once __DIR__ . '/timetable_engine.php';

function tt_scn_schedule(array $over = []): array
{
    return $over + [
        'id' => 1, 'kind' => 'permanent', 'status' => 'active', 'valid_from' => '2026-01-01', 'valid_to' => null,
        'working_days' => '1,2,3,4,5', 'report_time' => '07:00:00', 'arrival_time' => null, 'departure_time' => '17:00:00',
        'grace_arrival' => 5, 'grace_return' => 5, 'post_name' => 'Kicukiro branch',
        'post_leave_main' => '08:00:00', 'post_arrive' => '08:30:00', 'post_leave' => '10:00:00', 'post_return_main' => '10:30:00',
    ];
}

function tt_scn_breaks(): array
{
    return [['id' => 11, 'label' => 'Lunch break', 'break_type' => 'lunch', 'start_time' => '12:00:00', 'end_time' => '14:00:00']];
}

function tt_run_scenarios(): array
{
    $D = '2026-11-16'; // a Monday
    $t = function (string $hm, string $date = '2026-11-16') { return strtotime($date . ' ' . $hm); };
    $plan = tt_engine_plan(tt_scn_schedule(), tt_scn_breaks(), $D);
    $L = 11; // lunch break id
    $base = ['check_in' => $t('06:58'), 'post' => ['leave_main' => $t('08:00'), 'arrive_post' => $t('08:25'), 'leave_post' => $t('10:00'), 'return_main' => $t('10:28')]];
    $results = [];
    $n = 0;
    $check = function (string $name, array $asserts) use (&$results, &$n) {
        $n++;
        $errors = [];
        foreach ($asserts as $label => [$got, $want]) {
            if ($got !== $want) { $errors[] = $label . ': expected ' . var_export($want, true) . ', got ' . var_export($got, true); }
        }
        $results[] = ['n' => $n, 'name' => $name, 'ok' => !$errors, 'errors' => $errors];
    };
    $kinds = function (array $rem) { return array_column($rem, 'kind'); };

    // 1. On time
    $c = tt_engine_compute($plan, $base, [], $t('09:00'));
    $check('Employee reports to work on time', ['punctuality' => [$c['punctuality'], 'On Time'], 'late minutes' => [$c['late_arrival_minutes'], 0], 'lost' => [$c['lost_minutes'], 0]]);

    // 2. Late
    $c = tt_engine_compute($plan, ['check_in' => $t('07:15')] + $base, [], $t('09:00'));
    $r = tt_engine_due_reminders($plan, [], [], $t('07:06'));
    $check('Employee reports late (7:15 for 7:00)', ['punctuality' => [$c['punctuality'], 'Late'], 'late minutes' => [$c['late_arrival_minutes'], 15],
        'lost' => [$c['lost_minutes'], 15], 'not-reported reminder sent' => [in_array('not_reported', $kinds($r), true), true],
        'supervisor told' => [in_array('not_reported_sup', $kinds($r), true), true],
        'no stale "prepare to report" sent with it' => [in_array('pre_report', $kinds($r), true), false]]);

    // 3. 10 minutes before lunch
    $r = tt_engine_due_reminders($plan, $base, [], $t('11:50'));
    $msg = ''; foreach ($r as $x) { if ($x['kind'] === 'break_pre') { $msg = $x['message']; } }
    $check('Notification 10 minutes before lunch', ['break_pre due at 11:50' => [in_array('break_pre', $kinds($r), true), true],
        'message' => [$msg, 'Reminder: Your scheduled lunch break will begin in 10 minutes. Please prepare to proceed for your break at 12:00 PM.'],
        'not yet at 11:45' => [in_array('break_pre', $kinds(tt_engine_due_reminders($plan, $base, [], $t('11:45'))), true), false]]);

    // 4. Starts lunch at the scheduled time
    $ok = tt_engine_can($plan, $base, [], $t('12:00'), 'break_start', $L);
    $onBreak = $base; $onBreak['breaks'][$L] = ['start' => $t('12:00'), 'end' => null];
    $check('Employee starts lunch at the scheduled time', ['allowed' => [$ok[0], true], 'status' => [tt_engine_compute($plan, $onBreak, [], $t('12:30'))['status'], 'On Break']]);

    // 5. 15 minutes before lunch ends
    $r = tt_engine_due_reminders($plan, $onBreak, [], $t('13:45'));
    $msg = ''; foreach ($r as $x) { if ($x['kind'] === 'break_end_pre') { $msg = $x['message']; } }
    $check('Notification 15 minutes before lunch ends', ['break_end_pre due' => [in_array('break_end_pre', $kinds($r), true), true],
        'message' => [$msg, 'Reminder: Your lunch break will end in 15 minutes. Please prepare to return to your assigned work duties by 2:00 PM.'],
        'status' => [tt_engine_compute($plan, $onBreak, [], $t('13:46'))['status'], 'Return Due']]);

    // 6. Returns on time
    $ev = $base; $ev['breaks'][$L] = ['start' => $t('12:00'), 'end' => $t('14:00')];
    $c = tt_engine_compute($plan, $ev, [], $t('15:00'));
    $check('Employee returns on time', ['excess' => [$c['excess_break_minutes'], 0], 'lost' => [$c['lost_minutes'], 0], 'actual break' => [$c['actual_break_minutes'], 120], 'status' => [$c['status'], 'Working']]);

    // 7. Returns 20 minutes late
    $ev = $base; $ev['breaks'][$L] = ['start' => $t('12:00'), 'end' => $t('14:20')];
    $c = tt_engine_compute($plan, $ev, [], $t('14:30'));
    $check('Employee returns 20 minutes late', ['status' => [$c['status'], 'Returned Late'], 'late return minutes' => [$c['late_return_minutes'], 20]]);

    // 8. The 20 minutes are calculated (spec example: arrival 7:10 + return 2:20 = 30 minutes lost)
    $ev['check_in'] = $t('07:10');
    $c = tt_engine_compute($plan, $ev, [], $t('15:00'));
    $check('System calculates and records the additional 20 minutes', ['authorized break' => [$c['authorized_break_minutes'], 120], 'actual break' => [$c['actual_break_minutes'], 140],
        'excess break' => [$c['excess_break_minutes'], 20], 'late arrival' => [$c['late_arrival_minutes'], 10], 'total lost' => [$c['lost_minutes'], 30]]);

    // 9. Fails to record the return
    $c = tt_engine_compute($plan, $onBreak, [], $t('14:30'));
    $r = tt_engine_due_reminders($plan, $onBreak, [], $t('14:06'));
    $end = tt_engine_compute($plan, $onBreak, [], $t('18:00'));
    $check('Employee fails to record their return', ['status' => [$c['status'], 'Overdue'], 'outstanding so far' => [$c['lost_by_reason']['missing_return'] ?? 0, 30],
        'employee warned' => [in_array('return_overdue', $kinds($r), true), true], 'supervisor told' => [in_array('return_overdue_sup', $kinds($r), true), true],
        'not assumed returned at day end' => [$end['lost_by_reason']['missing_return'] ?? 0, 180], 'flagged for review' => [count($end['flags']) > 0, true],
        'sign-out blocked while on break' => [tt_engine_can($plan, $onBreak, [], $t('17:05'), 'sign_out')[0], false]]);

    // 10. Lunch outside the assigned period
    $early = tt_engine_can($plan, $base, [], $t('10:45'), 'break_start', $L);
    $late = tt_engine_can($plan, $base, [], $t('14:10'), 'break_start', $L);
    $check('Employee attempts lunch outside the assigned period', ['refused at 10:45' => [$early[0], false], 'refused at 2:10 PM' => [$late[0], false],
        'clear message' => [strpos($early[1], 'scheduled from 12:00 PM to 2:00 PM') !== false, true], 'allowed at 11:56 (5 min early)' => [tt_engine_can($plan, $base, [], $t('11:56'), 'break_start', $L)[0], true]]);

    // 11. Sign-out before the authorized departure time
    $no = tt_engine_can($plan, $base, [], $t('16:00'), 'sign_out');
    $check('Employee attempts to sign out early', ['refused' => [$no[0], false], 'message' => [$no[1], 'Your scheduled working hours have not yet been completed. Early sign-out requires administrative approval.'],
        'allowed at 5:00 PM' => [tt_engine_can($plan, $base, [], $t('17:00'), 'sign_out')[0], true]]);

    // 12. Approved early departure (from 3:00 PM)
    $ex = ['early_from' => $t('15:00'), 'labels' => ['Early Departure 15:00']];
    $ev = $base; $ev['breaks'][$L] = ['start' => $t('12:00'), 'end' => $t('14:00')]; $ev['sign_out'] = $t('15:05');
    $c = tt_engine_compute($plan, $ev, $ex, $t('15:10'));
    $check('Employee has approved early departure', ['may sign out at 3:00 PM' => [tt_engine_can($plan, $base, $ex, $t('15:00'), 'sign_out')[0], true],
        'not lost time' => [$c['lost_minutes'], 0], 'status' => [$c['status'], 'Completed']]);

    // 13. Approved extended break (until 2:30 PM)
    $ex = ['break_ext' => [$L => $t('14:30')]];
    $ev = $base; $ev['breaks'][$L] = ['start' => $t('12:00'), 'end' => $t('14:25')];
    $c = tt_engine_compute($plan, $ev, $ex, $t('15:00'));
    $check('Employee has approved extended break', ['excess' => [$c['excess_break_minutes'], 0], 'lost' => [$c['lost_minutes'], 0],
        'authorized break' => [$c['authorized_break_minutes'], 150], 'still on break at 2:10 PM' => [tt_engine_compute($plan, $onBreak, $ex, $t('14:10'))['status'], 'On Break']]);

    // 14. Works beyond scheduled hours
    $ev = $base; $ev['breaks'][$L] = ['start' => $t('12:00'), 'end' => $t('14:00')];
    $live = tt_engine_compute($plan, $ev, [], $t('17:40'));
    $ev['sign_out'] = $t('18:30');
    $c = tt_engine_compute($plan, $ev, [], $t('18:31'));
    $check('Employee works beyond scheduled hours', ['status after 5 PM' => [$live['status'], 'Overtime'], 'overtime recorded' => [$c['overtime_minutes'], 90], 'lost' => [$c['lost_minutes'], 0]]);

    // 15. Admin changes the timetable (new version from 23 Nov; old days keep the old one)
    $rows = [tt_scn_schedule(['id' => 1, 'status' => 'replaced', 'valid_to' => '2026-11-22']),
             tt_scn_schedule(['id' => 2, 'valid_from' => '2026-11-23', 'report_time' => '08:00:00']),
             tt_scn_schedule(['id' => 3, 'kind' => 'temporary', 'valid_from' => '2026-11-25', 'valid_to' => '2026-11-25', 'report_time' => '09:00:00'])];
    $p23 = tt_engine_plan(tt_engine_pick_schedule($rows, '2026-11-23'), [], '2026-11-23');
    $c = tt_engine_compute($p23, ['check_in' => $t('07:55', '2026-11-23')], [], $t('09:00', '2026-11-23'));
    $check('Admin changes the employee\'s timetable', ['past day keeps old version' => [tt_engine_pick_schedule($rows, '2026-11-16')['id'], 1],
        'new version applies' => [tt_engine_pick_schedule($rows, '2026-11-23')['id'], 2], 'temporary adjustment wins' => [tt_engine_pick_schedule($rows, '2026-11-25')['id'], 3],
        'permanent again after it' => [tt_engine_pick_schedule($rows, '2026-11-26')['id'], 2], '7:55 is on time for 8:00' => [$c['punctuality'], 'On Time']]);

    // 16. Correct next-working-day schedule (sign out on Friday 13 Nov 2026)
    $planFor = function ($d) { return tt_engine_plan(tt_scn_schedule(), tt_scn_breaks(), $d); };
    $next = tt_engine_next_working_day($planFor, '2026-11-13');
    $txt = tt_engine_next_day_text($next);
    $check('Employee receives the correct next-working-day schedule', ['next day' => [$next['date'] ?? null, '2026-11-16'],
        'text' => [strpos($txt, 'Your next working day is Monday, 16 November 2026.') === 0, true],
        'times' => [strpos($txt, 'Reporting time: 7:00 AM.') !== false && strpos($txt, 'Lunch break: 12:00 PM – 2:00 PM.') !== false && strpos($txt, 'Expected departure: 5:00 PM.') !== false, true]]);

    // 17. Weekends, holidays and approved leave
    $holidays = ['2026-11-16' => 'Public holiday']; $leave = ['2026-11-17' => 'Annual leave'];
    $planFor2 = function ($d) use ($holidays, $leave) {
        $type = isset($holidays[$d]) ? 'holiday' : (isset($leave[$d]) ? 'leave' : 'work');
        return tt_engine_plan(tt_scn_schedule(), tt_scn_breaks(), $d, $type, $holidays[$d] ?? ($leave[$d] ?? ''));
    };
    $sat = $planFor2('2026-11-14');
    $hol = tt_engine_compute($planFor2('2026-11-16'), [], [], $t('18:00'));
    $check('Weekends, holidays and approved leave', ['Saturday is off' => [$sat['day_type'], 'off'], 'holiday not absent' => [$hol['status'] . '/' . $hol['lost_minutes'], 'Holiday/0'],
        'leave not absent' => [tt_engine_compute($planFor2('2026-11-17'), [], [], $t('18:00', '2026-11-17'))['lost_minutes'], 0],
        'no reminders on holiday' => [count(tt_engine_due_reminders($planFor2('2026-11-16'), [], [], $t('06:45'))), 0],
        'next working day skips them' => [tt_engine_next_working_day($planFor2, '2026-11-13')['date'], '2026-11-18']]);

    // 18. One official clock (server time, Africa/Kigali), whatever the device says
    $utc10 = gmmktime(10, 0, 0, 11, 16, 2026);
    $check('System clock is consistent (server time, Africa/Kigali)', ['time zone' => [date_default_timezone_get(), 'Africa/Kigali'], '10:00 UTC shows 12:00' => [tt_hm($utc10), '12:00'],
        'every rule takes the server time' => [tt_engine_can($plan, $base, [], $utc10, 'break_start', $L)[0], true]]);

    // Extra: excused windows (short leave in the morning) and the not-yet-reported case
    $ex = ['windows' => [[$t('07:00'), $t('09:00'), 'Short Leave']]];
    $c = tt_engine_compute($plan, ['check_in' => $t('08:55')], $ex, $t('09:30'));
    $check('Approved short leave is never lost time', ['lost' => [$c['lost_minutes'], 0], 'no not-reported reminder at 7:10' => [in_array('not_reported', $kinds(tt_engine_due_reminders($plan, [], $ex, $t('07:10'))), true), false]]);

    // Extra: arriving after lunch: lunch time is not lost working time (but the lateness itself is shown in full)
    $c = tt_engine_compute($plan, ['check_in' => $t('15:00')], [], $t('15:30'));
    $check('Lunch missed because of lateness is not counted as lost work', ['minutes late' => [$c['late_arrival_minutes'], 480], 'lost' => [$c['lost_minutes'], 360]]);

    // Extra: different hours per weekday (Monday 8:00-17:00, Tuesday 9:00-18:00 with a later lunch)
    $sch = tt_scn_schedule(['day_times' => [1 => ['report_time' => '08:00:00', 'arrival_time' => null, 'departure_time' => '17:00:00'],
                                            2 => ['report_time' => '09:00:00', 'arrival_time' => null, 'departure_time' => '18:00:00']]]);
    $brs = [['id' => 21, 'label' => 'Lunch', 'break_type' => 'lunch', 'start_time' => '12:00:00', 'end_time' => '13:00:00', 'weekday' => 1],
            ['id' => 22, 'label' => 'Lunch', 'break_type' => 'lunch', 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'weekday' => 2],
            ['id' => 23, 'label' => 'Tea', 'break_type' => 'break', 'start_time' => '10:00:00', 'end_time' => '10:15:00', 'weekday' => 0]];
    $mon = tt_engine_plan($sch, $brs, '2026-11-16'); $tue = tt_engine_plan($sch, $brs, '2026-11-17'); $wed = tt_engine_plan($sch, $brs, '2026-11-18');
    $check('Different working hours on different days', ['Monday reports 8:00' => [tt_hm($mon['report']), '08:00'], 'Tuesday reports 9:00' => [tt_hm($tue['report']), '09:00'],
        'Tuesday leaves 18:00' => [tt_hm($tue['departure']), '18:00'], 'Wednesday uses the usual 7:00' => [tt_hm($wed['report']), '07:00'],
        'Monday lunch 12:00' => [tt_hm($mon['breaks'][1]['start'] ?? null), '12:00'], 'Tuesday lunch 13:00' => [tt_hm($tue['breaks'][1]['start'] ?? null), '13:00'],
        'tea every day' => [count($wed['breaks']), 1], '9:03 on Tuesday is on time' => [tt_engine_compute($tue, ['check_in' => $t('09:03', '2026-11-17')], [], $t('10:00', '2026-11-17'))['punctuality'], 'On Time']]);
    return $results;
}