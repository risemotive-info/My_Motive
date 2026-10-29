<?php
/**
 * NEW FILE: modules/sales/digital_payments.php
 *
 * Every Digital Payment (MTN MoMo through XentriPay): the reference, amount, date, status and the sale or credit it belongs
 * to. For the Admin and Managers. It also shows the cases that need a person:
 *   - "Needs attention": the money was received but could not be (fully) recorded, so it must be refunded or applied by hand
 *   - "Received, no sale yet": a customer paid for a new sale but the sale was never saved
 */
require '../../config/db.php';
require_once '../../includes/digital_payment_helpers.php';
require_role(['Admin', 'Manager']);

$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };

// "Check again": ask XentriPay now (and record a confirmed credit payment).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recheck'])) {
    $row = dp_refresh($conn, (int) ($_POST['id'] ?? 0), true);
    $msg = $row ? 'Checked ' . $row['reference'] . ': ' . dp_status_label($row['status']) . '.' : 'Payment not found.';
    header('Location: digital_payments.php?' . http_build_query(array_merge(array_intersect_key($_GET, array_flip(['status', 'purpose', 'from', 'to'])), ['success' => $msg])));
    exit;
}

$status = $_GET['status'] ?? '';
$purpose = $_GET['purpose'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$where = [];
$types = '';
$params = [];
if (in_array($status, ['initiated', 'pending', 'success', 'failed', 'cancelled', 'expired'], true)) { $where[] = 'd.status = ?'; $types .= 's'; $params[] = $status; }
if ($status === 'attention') { $where[] = 'd.needs_attention = 1'; }
if ($status === 'unrecorded') { $where[] = "d.purpose = 'sale' AND d.status = 'success' AND d.sale_id IS NULL"; }
if (in_array($purpose, ['sale', 'credit'], true)) { $where[] = 'd.purpose = ?'; $types .= 's'; $params[] = $purpose; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'DATE(d.created_at) >= ?'; $types .= 's'; $params[] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'DATE(d.created_at) <= ?'; $types .= 's'; $params[] = $to; }

$sql = 'SELECT d.*, users.names AS user_name, customers.name AS customer_name FROM digital_payments d
        LEFT JOIN users ON users.id = d.recorded_by LEFT JOIN customers ON customers.id = d.customer_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY d.id DESC LIMIT 300';
$st = mysqli_prepare($conn, $sql);
if ($types !== '') { mysqli_stmt_bind_param($st, $types, ...$params); }
mysqli_stmt_execute($st);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

$sum = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
    SUM(status IN ('initiated','pending')) AS waiting,
    COALESCE(SUM(CASE WHEN status = 'success' AND DATE(confirmed_at) = CURDATE() THEN amount END), 0) AS paid_today,
    SUM(needs_attention = 1) AS attention,
    SUM(purpose = 'sale' AND status = 'success' AND sale_id IS NULL) AS unrecorded
    FROM digital_payments"));

$badge = ['success' => 'success', 'pending' => 'warning', 'initiated' => 'warning', 'failed' => 'danger', 'cancelled' => 'secondary', 'expired' => 'secondary'];
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success"><?= $e($_GET['success']); ?></div><?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2>Digital Payments</h2>
        <div class="text-muted small">MTN MoMo payments taken through XentriPay: new sales and credit payments.</div>
    </div>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Sales</a>
</div>

<?php if (!xentripay_configured()) { ?>
<div class="alert alert-warning">Digital Payment is <strong>not set up yet</strong> on this server (config/xentripay.php is missing or has no address or key). The option is hidden from cashiers until it is.</div>
<?php } ?>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Waiting for customers', (int) $sum['waiting'], 'text-warning', 'status=pending'],
        ['Received today (RWF)', number_format((float) $sum['paid_today'], 2), 'text-success', 'status=success'],
        ['Needs attention', (int) $sum['attention'], ((int) $sum['attention'] > 0 ? 'text-danger' : ''), 'status=attention'],
        ['Received, no sale yet', (int) $sum['unrecorded'], ((int) $sum['unrecorded'] > 0 ? 'text-danger' : ''), 'status=unrecorded'],
    ] as $c) { ?>
    <div class="col-md-3"><a class="text-decoration-none text-reset" href="?<?= $c[3]; ?>"><div class="card border-0 shadow-sm"><div class="card-body">
        <div class="text-muted small"><?= $e($c[0]); ?></div><div class="fs-4 fw-bold <?= $c[2]; ?>"><?= $c[1]; ?></div>
    </div></div></a></div>
    <?php } ?>
</div>

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-2"><label class="form-label small text-muted">Status</label>
        <select name="status" class="form-select">
            <option value="">All</option>
            <?php foreach (['pending' => 'Waiting', 'success' => 'Paid', 'failed' => 'Failed', 'cancelled' => 'Stopped by cashier', 'expired' => 'Expired', 'attention' => 'Needs attention', 'unrecorded' => 'Received, no sale yet'] as $k => $l) { ?>
            <option value="<?= $k; ?>" <?= $status === $k ? 'selected' : ''; ?>><?= $e($l); ?></option>
            <?php } ?>
        </select></div>
    <div class="col-md-2"><label class="form-label small text-muted">Type</label>
        <select name="purpose" class="form-select"><option value="">All</option><option value="sale" <?= $purpose === 'sale' ? 'selected' : ''; ?>>New sale</option><option value="credit" <?= $purpose === 'credit' ? 'selected' : ''; ?>>Credit payment</option></select></div>
    <div class="col-md-2"><label class="form-label small text-muted">From</label><input type="date" name="from" class="form-control" value="<?= $e($from); ?>"></div>
    <div class="col-md-2"><label class="form-label small text-muted">To</label><input type="date" name="to" class="form-control" value="<?= $e($to); ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
</form>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-bordered bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Date</th><th>Reference</th><th>Type</th><th>Sale</th><th>Customer / phone</th><th class="text-end">Amount (RWF)</th><th>Status</th><th>Started by</th><th>Details</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="10" class="text-center text-muted py-4">No digital payments match.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?>
    <tr class="<?= (int) $r['needs_attention'] === 1 ? 'table-danger' : ''; ?>">
        <td class="text-nowrap"><?= date('d M Y H:i', strtotime($r['created_at'])); ?></td>
        <td class="text-nowrap"><code><?= $e($r['reference']); ?></code></td>
        <td><?= $r['purpose'] === 'credit' ? 'Credit payment' : 'New sale'; ?></td>
        <td><?php if ($r['sale_id']) { ?><a href="invoice.php?id=<?= (int) $r['sale_id']; ?>">RM<?= str_pad((string) $r['sale_id'], 6, '0', STR_PAD_LEFT); ?></a><?php } else { echo $r['purpose'] === 'sale' && $r['status'] === 'success' ? '<span class="text-danger">not recorded</span>' : '—'; } ?></td>
        <td><?= $e($r['customer_name'] ?? 'Walk-in'); ?><div class="small text-muted"><?= $e(dp_mask_phone((string) $r['msisdn'])); ?></div></td>
        <td class="text-end"><?= number_format((float) $r['amount'], 2); ?></td>
        <td><span class="badge text-bg-<?= $badge[$r['status']] ?? 'secondary'; ?>"><?= $e(dp_status_label($r['status'])); ?></span>
            <?php if ($r['confirmed_at']) { ?><div class="small text-muted"><?= date('d M H:i', strtotime($r['confirmed_at'])); ?></div><?php } ?></td>
        <td><?= $e($r['user_name'] ?? ''); ?></td>
        <td style="max-width:280px;"><?= $e($r['note'] ?: $r['failure_reason']); ?>
            <?php if ($r['applied_at'] && $r['purpose'] === 'credit') { ?><div class="small text-success">Recorded on the invoice: RWF <?= number_format((float) $r['applied_amount'], 2); ?></div><?php } ?></td>
        <td><?php if (in_array($r['status'], ['initiated', 'pending', 'cancelled', 'expired'], true) || ($r['status'] === 'success' && $r['purpose'] === 'credit' && !$r['applied_at'] && !(int) $r['needs_attention'])) { ?>
            <form method="POST"><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><button name="recheck" value="1" class="btn btn-sm btn-outline-primary">Check again</button></form><?php } ?></td>
    </tr>
    <?php } ?>
</table>
</div></div></div>
<div class="small text-muted mt-2">The newest 300 are shown. A payment that nobody approved is closed after <?= (int) DP_EXPIRE_HOURS; ?> hours.</div>