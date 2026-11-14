<?php
/**
 * NEW FILE: modules/timetable/approvals.php — supervisors decide:
 *   corrections (forgotten / wrong records), overtime (time after the official departure) and requests for more time.
 * A decision only succeeds while the request is still Pending (two supervisors can never both decide it).
 * Nobody decides their own request, except an Administrator, who must then write a note (house rule).
 * Approved overtime goes to the Attendance record of that day, which Payroll already pays.
 */
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
require_role(['Admin', 'Manager']);
tt_ensure($conn);

$me = (int) current_user_id();
$isAdmin = current_user_role() === 'Admin';
$tab = in_array($_GET['tab'] ?? '', ['corrections', 'overtime', 'extensions'], true) ? $_GET['tab'] : 'corrections';
$tables = ['corrections' => 'tt_corrections', 'overtime' => 'tt_overtime', 'extensions' => 'tt_extensions'];
$refBase = ['corrections' => 10000000, 'overtime' => 20000000, 'extensions' => 30000000];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = $_POST['tab'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
    $back = 'approvals.php?tab=' . urlencode($t);
    if (!tt_csrf_ok() || !isset($tables[$t]) || !in_array($decision, ['approve', 'reject'], true)) {
        header('Location: ' . $back . '&error=' . urlencode('The page had expired. Please try again.')); exit;
    }
    $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM ' . $tables[$t] . ' WHERE id = ' . $id));
    $uid = (int) ($row['user_id'] ?? 0);
    if (!$row || !tt_can_see_user($conn, $uid)) { header('Location: ' . $back . '&error=' . urlencode('Request not found.')); exit; }
    $own = $uid === $me || (int) ($row['requested_by'] ?? 0) === $me;
    if ($own && !$isAdmin) { header('Location: ' . $back . '&error=' . urlencode('You cannot decide your own request.')); exit; }
    if ($own && $note === '') { header('Location: ' . $back . '&error=' . urlencode('An Administrator deciding their own request must write a note.')); exit; }
    if ($decision === 'reject' && $note === '') { header('Location: ' . $back . '&error=' . urlencode('Please give the reason for rejecting.')); exit; }

    $status = $decision === 'approve' ? 'Approved' : 'Rejected';
    mysqli_begin_transaction($conn);
    try {
        if ($t === 'overtime') {
            $mins = $decision === 'approve' ? max(0, min((int) $row['minutes'], (int) ($_POST['approved_minutes'] ?? $row['minutes']))) : 0;
            $s = mysqli_prepare($conn, "UPDATE tt_overtime SET status = ?, approved_minutes = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ? AND status = 'Pending'");
            mysqli_stmt_bind_param($s, 'siisi', $status, $mins, $me, $note, $id);
        } else {
            $s = mysqli_prepare($conn, 'UPDATE ' . $tables[$t] . " SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ? AND status = 'Pending'");
            mysqli_stmt_bind_param($s, 'sisi', $status, $me, $note, $id);
        }
        mysqli_stmt_execute($s);
        if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('already'); }

        $what = '';
        if ($t === 'corrections') {
            $what = (TT_EVENT_LABELS[$row['event_type']] ?? $row['event_type']) . ' on ' . date('d M Y', strtotime($row['work_date'])) . ' at ' . date('H:i', strtotime($row['requested_time']));
            if ($decision === 'approve') { tt_apply_correction($conn, $row, $me); }
        } elseif ($t === 'overtime') {
            $what = 'Overtime of ' . tt_dur((int) $row['minutes']) . ' on ' . date('d M Y', strtotime($row['work_date'])) . ($decision === 'approve' ? ' (approved: ' . tt_dur($mins) . ')' : '');
            if ($decision === 'approve') {   // to the Attendance record: Payroll pays confirmed overtime
                $a = mysqli_prepare($conn, 'UPDATE attendance SET overtime_minutes = ? WHERE user_id = ? AND attendance_date = ?');
                mysqli_stmt_bind_param($a, 'iis', $mins, $uid, $row['work_date']);
                mysqli_stmt_execute($a);
            }
        } else {
            $what = 'More time (' . $row['kind'] . ') until ' . date('H:i', strtotime($row['new_end'])) . ' on ' . date('d M Y', strtotime($row['work_date']));
        }
        tt_audit($conn, $me, $uid, rtrim($t, 's'), $id, strtolower($status), $what . ' ' . strtolower($status) . ($own ? ' (own request, Administrator)' : '') . ($note !== '' ? '. Note: ' . $note : '') . '.');
        mysqli_commit($conn);
        tt_deliver($conn, 'decision', $refBase[$t] + $id, $row['work_date'], $uid, $uid, 'Work Timetable: request ' . strtolower($status),
            $what . ' was ' . strtoupper($status) . '.' . ($note !== '' ? ' Note: ' . $note : ''));
        header('Location: ' . $back . '&success=' . urlencode('Request ' . strtolower($status) . '. The employee was notified.')); exit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $msg = $e->getMessage() === 'already' ? 'This request was already decided by someone else. Nothing was changed.' : 'Nothing was changed because of a technical problem.' . ($isAdmin ? ' [' . $e->getMessage() . ']' : '');
        header('Location: ' . $back . '&error=' . urlencode($msg)); exit;
    }
}

$vis = tt_visible_user_ids($conn);
$w = $vis === null ? '1=1' : 'x.user_id IN (' . (implode(',', $vis) ?: '0') . ')';
$show = ($_GET['show'] ?? 'pending') === 'all' ? '1=1' : "x.status = 'Pending'";
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT x.*, u.names, dpt.name AS department, d.names AS decided_name FROM ' . $tables[$tab] . ' x
    JOIN users u ON u.id = x.user_id LEFT JOIN departments dpt ON dpt.id = u.department_id LEFT JOIN users d ON d.id = x.decided_by
    WHERE ' . $w . ' AND ' . $show . ' ORDER BY x.status = \'Pending\' DESC, x.id DESC LIMIT 200'), MYSQLI_ASSOC);
$counts = tt_pending_counts($conn);

include '../../includes/header.php';
include '../../includes/sidebar.php';
tt_tabs('approvals.php');
tt_flash();
?>
<ul class="nav nav-tabs mb-3">
    <?php foreach (['corrections' => 'Time corrections', 'overtime' => 'Overtime', 'extensions' => 'More time (breaks / posts)'] as $k => $l) { ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : ''; ?>" href="?tab=<?= $k; ?>"><?= $l; ?><?= $counts[$k] ? ' <span class="badge bg-danger">' . $counts[$k] . '</span>' : ''; ?></a></li>
    <?php } ?>
    <li class="nav-item ms-auto"><a class="nav-link small" href="?tab=<?= $tab; ?>&show=<?= ($_GET['show'] ?? '') === 'all' ? 'pending' : 'all'; ?>"><?= ($_GET['show'] ?? '') === 'all' ? 'Pending only' : 'Show decided too'; ?></a></li>
</ul>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr><th class="ps-3">Employee</th><th>Date</th><th>Request</th><th>Reason</th><th>Status</th><th style="min-width:300px;">Decision</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing to decide.</td></tr><?php } ?>
    <?php foreach ($rows as $r) {
        if ($tab === 'corrections') { $req = tt_e(TT_EVENT_LABELS[$r['event_type']] ?? $r['event_type']) . ' at <strong>' . date('H:i', strtotime($r['requested_time'])) . '</strong>'; $reason = $r['reason']; }
        elseif ($tab === 'overtime') { $req = 'Overtime <strong>' . tt_dur((int) $r['minutes']) . '</strong>' . ($r['status'] === 'Approved' ? ' (approved ' . tt_dur((int) $r['approved_minutes']) . ')' : ''); $reason = 'Worked after the official departure time.'; }
        else { $req = ($r['kind'] === 'break' ? 'Break' : 'Post return') . ' until <strong>' . date('H:i', strtotime($r['new_end'])) . '</strong>'; $reason = $r['reason']; }
        $own = (int) $r['user_id'] === $me || (int) ($r['requested_by'] ?? 0) === $me; ?>
        <tr>
            <td class="ps-3"><a href="day.php?user=<?= (int) $r['user_id']; ?>&date=<?= tt_e($r['work_date']); ?>"><?= tt_e($r['names']); ?></a><div class="small text-muted"><?= tt_e($r['department']); ?></div></td>
            <td><?= date('D d M Y', strtotime($r['work_date'])); ?></td>
            <td><?= $req; ?></td><td class="small"><?= tt_e($reason); ?></td>
            <td><strong><?= tt_e($r['status']); ?></strong><?php if ($r['decided_name']) { ?><div class="small text-muted"><?= tt_e($r['decided_name']); ?><?= $r['decision_note'] ? ': ' . tt_e($r['decision_note']) : ''; ?></div><?php } ?></td>
            <td>
                <?php if ($r['status'] === 'Pending' && (!$own || $isAdmin)) { ?>
                <form method="POST" class="d-flex flex-wrap gap-1 align-items-center"><?= tt_csrf_field(); ?>
                    <input type="hidden" name="tab" value="<?= $tab; ?>"><input type="hidden" name="id" value="<?= (int) $r['id']; ?>">
                    <?php if ($tab === 'overtime') { ?><input type="number" name="approved_minutes" class="form-control form-control-sm" style="width:90px;" min="0" max="<?= (int) $r['minutes']; ?>" value="<?= (int) $r['minutes']; ?>" title="Minutes to approve"><?php } ?>
                    <input type="text" name="note" class="form-control form-control-sm" style="max-width:170px;" placeholder="<?= $own ? 'Note (required)' : 'Note'; ?>" <?= $own ? 'required' : ''; ?>>
                    <button name="decision" value="approve" class="btn btn-sm btn-success">Approve</button>
                    <button name="decision" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
                </form>
                <?php } elseif ($r['status'] === 'Pending') { ?><span class="small text-muted">Your own request: another supervisor decides.</span><?php } ?>
            </td>
        </tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>