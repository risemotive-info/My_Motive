<?php
/**
 * NEW FILE: modules/timetable/timetable_pdf.php — an employee downloads their up-to-date timetable as PDF:
 * the next 14 days (holidays, approved leave and temporary adjustments included) and this month's time record.
 * Supervisors can open it for their team (?user=ID).
 */
ob_start();
require '../../config/db.php';
require_once '../../includes/timetable_helpers.php';
require_once '../../includes/report_pdf.php';
tt_ensure($conn);

$uid = (int) ($_GET['user'] ?? current_user_id());
if (!tt_can_see_user($conn, $uid)) { ob_end_clean(); http_response_code(403); echo 'Not allowed.'; exit; }
$emp = tt_user($conn, $uid);
$from = date('Y-m-d'); $to = date('Y-m-d', strtotime('+13 days'));
$ctx = tt_contexts($conn, $from, $to, [$uid])[$uid] ?? [];
$rows = [];
foreach ($ctx as $d => $c) {
    $p = $c['plan'];
    $type = !$p ? 'No timetable' : ['work' => 'Working day', 'off' => 'Day off', 'holiday' => 'Holiday', 'leave' => 'Leave'][$p['day_type']];
    $work = $p && $p['day_type'] === 'work';
    $rows[] = [
        'date' => $d, 'day' => date('l', strtotime($d)), 'type' => $type . ($p && $p['day_label'] && !$work ? ': ' . $p['day_label'] : '') . ($p && $p['schedule_kind'] === 'temporary' ? ' (adjusted)' : ''),
        'report' => $work ? tt_hm12(tt_allowed_arrival($p, $c['ex'])) : '',
        'post' => $work && $p['post'] ? $p['post']['name'] . ' ' . tt_hm($p['post']['leave_main']) . '–' . tt_hm(tt_post_due($p, $c['ex'])) : '',
        'breaks' => $work ? implode(', ', array_map(function ($b) use ($c) { return $b['label'] . ' ' . tt_hm($b['start']) . '–' . tt_hm(tt_break_due($b, $c['ex'])); }, $p['breaks'])) : '',
        'departure' => $work ? tt_hm12(tt_allowed_departure($p, $c['ex'])) : '',
        'hours' => $work ? tt_dur(tt_engine_scheduled_minutes($p)) : '',
        'notes' => $work ? implode('; ', $c['ex']['labels']) : '',
    ];
}
$m = tt_period_report($conn, date('Y-m-01'), date('Y-m-t'), null, $uid)['rows'][$uid] ?? null;
$report = [
    'title' => 'My Work Timetable',
    'filters' => [['Employee', $emp['names']], ['Department', (string) $emp['department']], ['Period', date('d M Y', strtotime($from)) . ' - ' . date('d M Y', strtotime($to))]],
    'summary' => $m ? [['This month: worked', tt_dur($m['worked'])], ['Late arrivals', $m['late_days'] . ' (' . tt_dur($m['late_minutes']) . ')'], ['Lost working time', tt_dur($m['lost'])], ['Approved overtime', tt_dur($m['overtime_approved'])]] : [],
    'columns' => [
        ['key' => 'date', 'label' => 'Date', 'w' => 9, 'type' => 'date'], ['key' => 'day', 'label' => 'Day', 'w' => 8], ['key' => 'type', 'label' => 'Day type', 'w' => 13],
        ['key' => 'report', 'label' => 'Reporting', 'w' => 8], ['key' => 'post', 'label' => 'Assigned post', 'w' => 14], ['key' => 'breaks', 'label' => 'Lunch / breaks', 'w' => 17],
        ['key' => 'departure', 'label' => 'Departure', 'w' => 8], ['key' => 'hours', 'label' => 'Working time', 'w' => 8], ['key' => 'notes', 'label' => 'Approved exceptions', 'w' => 15],
    ],
    'rows' => $rows,
    'notes' => ['Official MY MOTIVE time (Africa/Kigali). Generated ' . date('d M Y H:i') . '. Changes made later by your administrator or approved requests apply automatically; download again for the latest version.'],
];
$pdf = report_render_pdf($report);
ob_end_clean();
$pdf->Output('my-timetable-' . date('Ymd') . '.pdf', \Mpdf\Output\Destination::INLINE);