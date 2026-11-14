<?php
/**
 * NEW FILE: modules/timetable/reports.php — Historical reports & time-performance analytics (section 14).
 *   Views: employees (scheduled vs actual, lateness, breaks, lost time, overtime), daily log, departments,
 *   punctuality ranking. Periods: today, week, month, year, custom. Export: Excel (CSV) and PDF (same layout as
 *   every MY MOTIVE report). Managers see their department; Administrators everyone.
 */
ob_start();
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
require_once '../../includes/report_helpers.php';
require_role(['Admin', 'Manager']);
tt_ensure($conn);

$period = report_period_from_request($_GET);
$view = in_array($_GET['view'] ?? '', ['employees', 'daily', 'departments', 'ranking'], true) ? $_GET['view'] : 'employees';
$dep = (int) ($_GET['department'] ?? 0);
$emp = (int) ($_GET['employee'] ?? 0);
$views = ['employees' => 'Employees: scheduled vs actual', 'daily' => 'Daily time log', 'departments' => 'Department comparison', 'ranking' => 'Punctuality ranking'];

function tt_report_build(mysqli $conn, string $view, array $period, int $dep, int $emp, array $views): array
{
    $filters = [['Period', $period['label']]];
    if ($dep) { $filters[] = ['Department', (string) (mysqli_fetch_row(mysqli_query($conn, 'SELECT name FROM departments WHERE id = ' . $dep))[0] ?? '')]; }
    if ($emp) { $filters[] = ['Employee', (string) (tt_user($conn, $emp)['names'] ?? '')]; }
    $data = tt_period_report($conn, $period['start'], $period['end'], $dep ?: null, $emp ?: null);
    $rows = array_filter($data['rows'], function ($r) { return $r['has_schedule']; });
    $h = function ($m) { return round($m / 60, 2); };
    $sum = function ($k) use ($rows) { return array_sum(array_column($rows, $k)); };
    $summary = [['Employees', (string) count($rows)], ['Scheduled hours', number_format($h($sum('scheduled')), 1)], ['Worked hours', number_format($h($sum('worked')), 1)],
        ['Lost working time', tt_dur($sum('lost'))], ['Late arrivals', (string) $sum('late_days')], ['Late returns', (string) $sum('late_returns')],
        ['Approved overtime', tt_dur($sum('overtime_approved'))], ['Overtime waiting', tt_dur($sum('overtime_pending'))]];
    $out = ['title' => 'Work Timetable · ' . $views[$view], 'filters' => $filters, 'summary' => $summary, 'rows' => [], 'type' => $view,
            'notes' => ['Times are official MY MOTIVE server times (Africa/Kigali). Lost time excludes grace periods and approved exceptions; it is recorded for evaluation and is not a payroll deduction by itself.']];

    if ($view === 'employees') {
        $out['columns'] = [
            ['key' => 'name', 'label' => 'Employee', 'w' => 16], ['key' => 'dept', 'label' => 'Department', 'w' => 11],
            ['key' => 'days', 'label' => 'Work days', 'w' => 6, 'align' => 'R', 'type' => 'int'], ['key' => 'present', 'label' => 'Present', 'w' => 6, 'align' => 'R', 'type' => 'int'],
            ['key' => 'absent', 'label' => 'Absent', 'w' => 6, 'align' => 'R', 'type' => 'int'], ['key' => 'att', 'label' => 'Attend. %', 'w' => 6, 'align' => 'R'],
            ['key' => 'sched', 'label' => 'Sched. h', 'w' => 7, 'align' => 'R'], ['key' => 'worked', 'label' => 'Worked h', 'w' => 7, 'align' => 'R'],
            ['key' => 'late', 'label' => 'Late (times / min)', 'w' => 9, 'align' => 'R'], ['key' => 'brk', 'label' => 'Break auth./actual min', 'w' => 10, 'align' => 'R'],
            ['key' => 'excess', 'label' => 'Excess break min', 'w' => 7, 'align' => 'R', 'type' => 'int'], ['key' => 'ret', 'label' => 'Late returns', 'w' => 6, 'align' => 'R', 'type' => 'int'],
            ['key' => 'unauth', 'label' => 'Unauth. absence min', 'w' => 8, 'align' => 'R', 'type' => 'int'], ['key' => 'lost', 'label' => 'Lost min', 'w' => 7, 'align' => 'R', 'type' => 'int'],
            ['key' => 'ot', 'label' => 'Approved OT min', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ];
        foreach ($rows as $uid => $r) {
            $out['rows'][] = ['uid' => $uid, 'name' => $r['employee']['names'], 'dept' => $r['employee']['department'], 'days' => $r['working_days'], 'present' => $r['present_days'], 'absent' => $r['absent_days'],
                'att' => $r['attendance_pct'] !== null ? $r['attendance_pct'] . '%' : '—', 'sched' => number_format($h($r['scheduled']), 1), 'worked' => number_format($h($r['worked']), 1),
                'late' => $r['late_days'] . ' / ' . $r['late_minutes'], 'brk' => $r['auth_break'] . ' / ' . $r['actual_break'], 'excess' => $r['excess_break'], 'ret' => $r['late_returns'],
                'unauth' => $r['absence'] + $r['early_departure'] + $r['post_delay'], 'lost' => $r['lost'], 'ot' => $r['overtime_approved']];
        }
        $out['totals'] = ['excess' => $sum('excess_break'), 'ret' => $sum('late_returns'), 'lost' => $sum('lost'), 'ot' => $sum('overtime_approved')];
    } elseif ($view === 'daily') {
        $out['columns'] = [
            ['key' => 'date', 'label' => 'Date', 'w' => 9, 'type' => 'date'], ['key' => 'name', 'label' => 'Employee', 'w' => 15], ['key' => 'status', 'label' => 'Status', 'w' => 10],
            ['key' => 'report', 'label' => 'Due', 'w' => 6], ['key' => 'in', 'label' => 'In', 'w' => 6], ['key' => 'breaks', 'label' => 'Breaks (actual)', 'w' => 15],
            ['key' => 'out', 'label' => 'Out', 'w' => 6], ['key' => 'worked', 'label' => 'Worked', 'w' => 8], ['key' => 'late', 'label' => 'Late min', 'w' => 6, 'align' => 'R', 'type' => 'int'],
            ['key' => 'excess', 'label' => 'Excess break', 'w' => 7, 'align' => 'R', 'type' => 'int'], ['key' => 'lost', 'label' => 'Lost min', 'w' => 6, 'align' => 'R', 'type' => 'int'],
            ['key' => 'ot', 'label' => 'OT min', 'w' => 6, 'align' => 'R', 'type' => 'int'], ['key' => 'notes', 'label' => 'Exceptions / flags', 'w' => 16],
        ];
        foreach ($data['days'] as $uid => $days) {
            if (empty($rows[$uid])) { continue; }
            foreach ($days as $d => $c) {
                if (!$c['plan'] || ($c['plan']['day_type'] === 'off' && !$c['events']['check_in'])) { continue; }
                $br = [];
                foreach ($c['plan']['breaks'] as $b) { $x = $c['events']['breaks'][$b['id']] ?? null; if ($x) { $br[] = tt_hm($x['start'] ?? null) . '–' . tt_hm($x['end'] ?? null); } }
                $out['rows'][] = ['uid' => $uid, 'date' => $d, 'name' => $rows[$uid]['employee']['names'], 'status' => $c['status'] . ($c['punctuality'] === 'Late' ? ' (late)' : ''),
                    'report' => $c['plan']['day_type'] === 'work' ? tt_hm(tt_allowed_arrival($c['plan'], $c['ex'])) : '', 'in' => tt_hm($c['events']['check_in']), 'breaks' => implode(', ', $br),
                    'out' => tt_hm($c['events']['sign_out']), 'worked' => tt_dur($c['worked_minutes']), 'late' => $c['late_arrival_minutes'], 'excess' => $c['excess_break_minutes'],
                    'lost' => $c['lost_minutes'], 'ot' => $c['overtime_minutes'], 'notes' => implode('; ', array_merge($c['excused'], $c['flags']))];
            }
        }
        usort($out['rows'], function ($a, $b) { return [$a['date'], $a['name']] <=> [$b['date'], $b['name']]; });
        $out['totals'] = ['lost' => array_sum(array_column($out['rows'], 'lost')), 'excess' => array_sum(array_column($out['rows'], 'excess'))];
    } elseif ($view === 'departments') {
        $g = [];
        foreach ($rows as $r) {
            $k = $r['employee']['department'] ?: 'No department';
            $g[$k] = $g[$k] ?? ['dept' => $k, 'people' => 0, 'days' => 0, 'present' => 0, 'sched' => 0, 'worked' => 0, 'late' => 0, 'excess' => 0, 'lost' => 0, 'ot' => 0];
            $g[$k]['people']++; $g[$k]['days'] += $r['working_days']; $g[$k]['present'] += $r['present_days']; $g[$k]['sched'] += $r['scheduled']; $g[$k]['worked'] += $r['worked'];
            $g[$k]['late'] += $r['late_days']; $g[$k]['excess'] += $r['excess_break']; $g[$k]['lost'] += $r['lost']; $g[$k]['ot'] += $r['overtime_approved'];
        }
        $out['columns'] = [['key' => 'dept', 'label' => 'Department', 'w' => 18], ['key' => 'people', 'label' => 'Employees', 'w' => 7, 'align' => 'R', 'type' => 'int'],
            ['key' => 'att', 'label' => 'Attendance %', 'w' => 8, 'align' => 'R'], ['key' => 'sched_h', 'label' => 'Scheduled h', 'w' => 8, 'align' => 'R'], ['key' => 'worked_h', 'label' => 'Worked h', 'w' => 8, 'align' => 'R'],
            ['key' => 'late', 'label' => 'Late arrivals', 'w' => 8, 'align' => 'R', 'type' => 'int'], ['key' => 'excess', 'label' => 'Excess break min', 'w' => 8, 'align' => 'R', 'type' => 'int'],
            ['key' => 'lost', 'label' => 'Lost min', 'w' => 8, 'align' => 'R', 'type' => 'int'], ['key' => 'lost_pp', 'label' => 'Lost min / person', 'w' => 8, 'align' => 'R'],
            ['key' => 'score', 'label' => 'Time kept %', 'w' => 8, 'align' => 'R'], ['key' => 'ot', 'label' => 'Approved OT min', 'w' => 8, 'align' => 'R', 'type' => 'int']];
        foreach ($g as $x) {
            $out['rows'][] = $x + ['att' => $x['days'] ? round(100 * $x['present'] / $x['days'], 1) . '%' : '—', 'sched_h' => number_format($h($x['sched']), 1), 'worked_h' => number_format($h($x['worked']), 1),
                'lost_pp' => $x['people'] ? (string) round($x['lost'] / $x['people']) : '0', 'score' => $x['sched'] ? round(100 * max(0, $x['sched'] - $x['lost']) / $x['sched'], 1) . '%' : '—'];
        }
        usort($out['rows'], function ($a, $b) { return (float) $b['score'] <=> (float) $a['score']; });
    } else {
        $out['columns'] = [['key' => 'rank', 'label' => '#', 'w' => 4, 'align' => 'R', 'type' => 'int'], ['key' => 'name', 'label' => 'Employee', 'w' => 18], ['key' => 'dept', 'label' => 'Department', 'w' => 14],
            ['key' => 'score', 'label' => 'Time kept %', 'w' => 9, 'align' => 'R'], ['key' => 'punct', 'label' => 'Punctual days %', 'w' => 9, 'align' => 'R'],
            ['key' => 'late', 'label' => 'Late arrivals', 'w' => 8, 'align' => 'R', 'type' => 'int'], ['key' => 'ret', 'label' => 'Late returns', 'w' => 8, 'align' => 'R', 'type' => 'int'],
            ['key' => 'lost', 'label' => 'Lost min', 'w' => 8, 'align' => 'R', 'type' => 'int']];
        $list = array_filter($rows, function ($r) { return $r['score'] !== null; });
        // Ranking: time kept (share of scheduled time not lost), then fewer late arrivals, then fewer late returns.
        uasort($list, function ($a, $b) { return [$b['score'], $a['late_days'], $a['late_returns']] <=> [$a['score'], $b['late_days'], $b['late_returns']]; });
        $i = 0;
        foreach ($list as $uid => $r) {
            $out['rows'][] = ['uid' => $uid, 'rank' => ++$i, 'name' => $r['employee']['names'], 'dept' => $r['employee']['department'], 'score' => $r['score'] . '%',
                'punct' => $r['punctuality_pct'] !== null ? $r['punctuality_pct'] . '%' : '—', 'late' => $r['late_days'], 'ret' => $r['late_returns'], 'lost' => $r['lost']];
        }
        $out['notes'][] = 'Ranking: share of scheduled working time not lost; ties go to fewer late arrivals, then fewer late returns.';
    }
    $out['by_reason'] = [];
    foreach ($data['days'] as $uid => $days) { if (empty($rows[$uid])) { continue; } foreach ($days as $c) { foreach ($c['lost_by_reason'] as $k => $m) { $out['by_reason'][$k] = ($out['by_reason'][$k] ?? 0) + $m; } } }
    return $out;
}

$report = $period['error'] ? null : tt_report_build($conn, $view, $period, $dep, $emp, $views);
$export = $_GET['export'] ?? '';
if ($report && $export === 'csv') {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="work-timetable-' . $view . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF"); // Excel reads the accents correctly
    fputcsv($o, [$report['title']]);
    foreach ($report['filters'] as $f) { fputcsv($o, $f); }
    fputcsv($o, []);
    fputcsv($o, array_column($report['columns'], 'label'));
    foreach ($report['rows'] as $r) { fputcsv($o, array_map(function ($c) use ($r) { return $r[$c['key']] ?? ''; }, $report['columns'])); }
    exit;
}
if ($report && $export === 'pdf') {
    require_once '../../includes/report_pdf.php';
    $pdf = report_render_pdf($report);
    ob_end_clean();
    $pdf->Output('work-timetable-' . $view . '-' . date('Ymd') . '.pdf', isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);
    exit;
}

$departments = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC);
$emps = tt_employees($conn);
$vis = tt_visible_user_ids($conn);
if ($vis !== null) { $emps = array_intersect_key($emps, array_flip($vis)); }
$qs = http_build_query(array_diff_key($_GET, ['export' => 1]));

ob_end_flush();
include '../../includes/header.php';
include '../../includes/sidebar.php';
tt_tabs('reports.php');
tt_flash();
?>
<form class="row g-2 mb-3 align-items-end" method="GET">
    <div class="col-md-3"><label class="form-label small mb-0">Report</label><select name="view" class="form-select form-select-sm"><?php foreach ($views as $k => $l) { ?><option value="<?= $k; ?>" <?= $view === $k ? 'selected' : ''; ?>><?= tt_e($l); ?></option><?php } ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0">Period</label><select name="period" class="form-select form-select-sm" id="ttPeriod"><?php foreach (report_period_options() as $k => $l) { ?><option value="<?= $k; ?>" <?= $period['preset'] === $k ? 'selected' : ''; ?>><?= tt_e($l); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-1 tt-custom"><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= tt_e($_GET['from'] ?? ''); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-1 tt-custom"><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= tt_e($_GET['to'] ?? ''); ?>" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Department</label><select name="department" class="form-select form-select-sm"><option value="0">All</option><?php foreach ($departments as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= $dep === (int) $d['id'] ? 'selected' : ''; ?>><?= tt_e($d['name']); ?></option><?php } ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0">Employee</label><select name="employee" class="form-select form-select-sm"><option value="0">All</option><?php foreach ($emps as $e) { ?><option value="<?= (int) $e['id']; ?>" <?= $emp === (int) $e['id'] ? 'selected' : ''; ?>><?= tt_e($e['names']); ?></option><?php } ?></select></div>
    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Show</button></div>
</form>
<script>(function(){var p=document.getElementById('ttPeriod');function f(){document.querySelectorAll('.tt-custom').forEach(function(e){e.style.display=p.value==='custom'?'':'none';});}p.addEventListener('change',f);f();})();</script>

<?php if ($period['error']) { ?><div class="alert alert-danger"><?= tt_e($period['error']); ?></div><?php } elseif ($report) { ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
        <div><h5 class="mb-0"><?= tt_e($report['title']); ?></h5><?php foreach ($report['filters'] as $f) { ?><div class="small text-muted"><strong><?= tt_e($f[0]); ?>:</strong> <?= tt_e($f[1]); ?></div><?php } ?></div>
        <div class="d-flex gap-2 align-items-start"><a class="btn btn-sm btn-outline-success" href="?<?= tt_e($qs); ?>&export=csv"><i class="bi bi-file-earmark-excel"></i> Excel (CSV)</a>
            <a class="btn btn-sm btn-outline-primary" target="_blank" href="?<?= tt_e($qs); ?>&export=pdf"><i class="bi bi-file-earmark-pdf"></i> PDF</a></div>
    </div>
    <div class="row g-2 mb-3"><?php foreach ($report['summary'] as $s) { ?><div class="col-6 col-md-3"><div class="p-2 rounded" style="background:#F2F5F9;"><div class="small text-muted"><?= tt_e($s[0]); ?></div><div class="fw-bold"><?= tt_e($s[1]); ?></div></div></div><?php } ?></div>
    <?php $chartRows = $view === 'daily' ? [] : $report['rows']; if ($report['by_reason'] || $chartRows) { ?>
    <div class="row g-3 mb-3">
        <div class="col-md-5"><div class="small fw-semibold mb-1">Where lost time comes from (minutes)</div><canvas id="ttReasons" height="180"></canvas></div>
        <div class="col-md-7"><div class="small fw-semibold mb-1"><?= $view === 'departments' ? 'Time kept % by department' : 'Scheduled vs worked hours'; ?></div><canvas id="ttBars" height="180"></canvas></div>
    </div>
    <?php } ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:12.5px;">
        <tr><?php foreach ($report['columns'] as $c) { ?><th class="<?= ($c['align'] ?? 'L') === 'R' ? 'text-end' : ''; ?>"><?= tt_e($c['label']); ?></th><?php } ?></tr>
        <?php if (!$report['rows']) { ?><tr><td colspan="<?= count($report['columns']); ?>" class="text-center text-muted py-3">No employees with a timetable in this period.</td></tr><?php } ?>
        <?php foreach ($report['rows'] as $r) { ?><tr>
            <?php foreach ($report['columns'] as $c) { $val = $r[$c['key']] ?? ''; $txt = ($c['type'] ?? '') === 'date' ? date('D d M', strtotime($val)) : (string) $val; ?>
                <td class="<?= ($c['align'] ?? 'L') === 'R' ? 'text-end' : ''; ?>"><?php if ($c['key'] === 'name' && !empty($r['uid'])) { ?><a href="day.php?user=<?= (int) $r['uid']; ?>&date=<?= tt_e($r['date'] ?? min($period['end'], date('Y-m-d'))); ?>"><?= tt_e($txt); ?></a><?php } else { echo tt_e($txt); } ?></td>
            <?php } ?></tr><?php } ?>
        <?php if (!empty($report['totals'])) { ?><tr class="fw-bold"><?php foreach ($report['columns'] as $i => $c) { ?><td class="<?= ($c['align'] ?? 'L') === 'R' ? 'text-end' : ''; ?>"><?= array_key_exists($c['key'], $report['totals']) ? tt_e($report['totals'][$c['key']]) : ($i === 0 ? 'TOTAL' : ''); ?></td><?php } ?></tr><?php } ?>
    </table></div>
    <?php foreach ($report['notes'] as $n) { ?><div class="small text-muted mt-2"><?= tt_e($n); ?></div><?php } ?>
</div></div>
<script>
(function () {
    if (typeof Chart === 'undefined') { return; }
    var reasons = <?= json_encode(array_map(function ($k, $v) { return ['label' => TT_LOST_REASONS[$k] ?? $k, 'v' => $v]; }, array_keys($report['by_reason']), $report['by_reason'])); ?>;
    var el = document.getElementById('ttReasons');
    if (el && reasons.length) {
        new Chart(el, { type: 'doughnut', data: { labels: reasons.map(function (r) { return r.label; }), datasets: [{ data: reasons.map(function (r) { return r.v; }),
            backgroundColor: ['#E24B4A', '#1E2FE0', '#E68A1C', '#7C5CE0', '#159AA8', '#0FA968', '#8A90A3', '#1E2333', '#F0A3A2'] }] }, options: { plugins: { legend: { position: 'right' } } } });
    }
    var rows = <?= json_encode(array_values(array_map(function ($r) use ($view) {
        return $view === 'departments' ? ['l' => $r['dept'], 'a' => (float) $r['score']] : ['l' => $r['name'] ?? '', 'a' => (float) str_replace(',', '', (string) ($r['sched'] ?? 0)), 'b' => (float) str_replace(',', '', (string) ($r['worked'] ?? 0)), 's' => (float) ($r['score'] ?? 0)];
    }, array_slice($chartRows, 0, 25)))); ?>;
    var b = document.getElementById('ttBars');
    if (b && rows.length) {
        var ds = <?= json_encode($view); ?> === 'departments' ? [{ label: 'Time kept %', data: rows.map(function (r) { return r.a; }), backgroundColor: '#1E2FE0' }]
            : <?= json_encode($view); ?> === 'ranking' ? [{ label: 'Time kept %', data: rows.map(function (r) { return r.s; }), backgroundColor: '#0FA968' }]
            : [{ label: 'Scheduled h', data: rows.map(function (r) { return r.a; }), backgroundColor: '#C9CEDD' }, { label: 'Worked h', data: rows.map(function (r) { return r.b; }), backgroundColor: '#1E2FE0' }];
        new Chart(b, { type: 'bar', data: { labels: rows.map(function (r) { return r.l; }), datasets: ds }, options: { scales: { y: { beginAtZero: true } } } });
    }
})();
</script>
<?php } ?>
<?php include '../../includes/footer.php'; ?>