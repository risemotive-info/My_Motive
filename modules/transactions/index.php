<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
$pageSearchScope = 'transactions'; // tells the topbar search what module we're in
require '../../includes/pagination.php';
include '../../includes/header.php'; include '../../includes/sidebar.php';
const PER_PAGE = 10;
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
$currentUserId = $_SESSION['user_id'] ?? 0;

// Visibility: admin sees every transaction. Non-admin sees approved ones
// plus their own pending/rejected submissions.
$visibilityWhere = $isAdmin
    ? '1=1'
    : "(status = 'approved' OR recorded_by = $currentUserId)";

$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM transactions WHERE $visibilityWhere"))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

// Totals only ever count approved transactions.
$summary = mysqli_query($conn, "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE 0 END), 0) AS income, COALESCE(SUM(CASE WHEN transaction_type = 'Expense' THEN amount ELSE 0 END), 0) AS expense FROM transactions WHERE status = 'approved'");
$totals = mysqli_fetch_assoc($summary);

// Profit Overview: Product profit = Selling - Buying price per unit sold;
// Service profit = 80% of amount paid. Only counts sales that actually
// completed (excludes still-pending and cancelled sales).
$profitSummary = mysqli_query($conn, "SELECT
        COALESCE(SUM(CASE WHEN sale_items.item_type = 'Product' THEN (sale_items.unit_price - COALESCE(products.buying_price, 0)) * sale_items.quantity ELSE 0 END), 0) AS product_profit,
        COALESCE(SUM(CASE WHEN sale_items.item_type = 'Service' THEN 0.8 * sale_items.line_total ELSE 0 END), 0) AS service_profit
    FROM sale_items
    JOIN sales ON sale_items.sale_id = sales.id
    LEFT JOIN products ON sale_items.product_id = products.id
    WHERE sales.status NOT IN ('Pending Discount Approval', 'Cancelled')");
$profitTotals = mysqli_fetch_assoc($profitSummary);
$productProfit = (float) $profitTotals['product_profit'];
$serviceProfit = (float) $profitTotals['service_profit'];
$totalProfit = $productProfit + $serviceProfit;

$sql = "SELECT transactions.*, users.names AS recorder_name FROM transactions LEFT JOIN users ON transactions.recorded_by = users.id WHERE $visibilityWhere ORDER BY transaction_date DESC, transactions.id DESC LIMIT " . PER_PAGE . ' OFFSET ' . $offset;
$transactions = mysqli_query($conn, $sql);

$statusBadge = [
    'approved' => 'success',
    'pending' => 'warning text-dark',
    'rejected' => 'danger',
];
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($_GET['error'])) { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Transactions Management</h2>
    <div class="d-flex gap-2"><a href="create.php" class="rm-btn rm-btn-primary">+ Add Transaction</a></div></div>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-success">
            <div class="card-body">
                <small class="text-muted">Total Income</small>
                <h4 class="text-success mb-0">RWF <?= number_format((float) $totals['income'], 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body">
                <small class="text-muted">Total Expenses</small>
                <h4 class="text-danger mb-0">RWF <?= number_format((float) $totals['expense'], 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <small class="text-muted">Balance</small>
                <h4 class="text-primary mb-0">RWF <?= number_format((float) $totals['income'] - (float) $totals['expense'], 2); ?></h4>
            </div>
        </div>
    </div>
</div>

<h5 class="mb-3">Profit Overview</h5>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-info">
            <div class="card-body">
                <small class="text-muted">Product Profit</small>
                <h4 class="text-info mb-0">RWF <?= number_format($productProfit, 2); ?></h4>
                <small class="text-muted">Selling Price − Buying Price</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-warning">
            <div class="card-body">
                <small class="text-muted">Service Profit</small>
                <h4 class="text-warning mb-0">RWF <?= number_format($serviceProfit, 2); ?></h4>
                <small class="text-muted">80% of amount paid for services</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <small class="text-muted">Total Profit</small>
                <h4 class="text-primary mb-0">RWF <?= number_format($totalProfit, 2); ?></h4>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <!-- Live-search wires up against this container: it caches this exact
             markup on page load and swaps it out for filtered results as you
             type in the topbar search box, restoring it when the box is
             cleared. The income/expense/balance summary cards above stay
             outside since they aren't search results. -->
        <div id="pageResultsContainer">
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white mb-0">
    <tr>
        <th>Date</th>
        <th>Category</th>
        <th>Type</th>
        <th>Amount</th>
        <th>Description</th>
        <th>Recorded By</th>
        <th>Status</th>
        <th>Action</th>
    </tr><?php if (mysqli_num_rows($transactions) === 0) { ?>
    <tr><td colspan="8" class="text-center text-muted py-4">No transactions found. Click "Add Transaction" to record one.</td></tr><?php } ?>
    <?php while ($transaction = mysqli_fetch_assoc($transactions)) { ?>
    <tr><td><?= date('d M Y', strtotime($transaction['transaction_date'])); ?>
</td><td><?= htmlspecialchars($transaction['category'], ENT_QUOTES, 'UTF-8'); ?>
</td><td><span class="badge bg-<?= $transaction['transaction_type'] === 'Income' ? 'success' : 'danger'; ?>">
    <?= $transaction['transaction_type']; ?></span></td>
    <td>RWF <?= number_format((float) $transaction['amount'], 2); ?></td>
    <td><?= htmlspecialchars($transaction['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($transaction['recorder_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td>
        <span class="badge bg-<?= $statusBadge[$transaction['status']] ?? 'secondary'; ?>">
            <?= ucfirst($transaction['status']); ?>
        </span>
        <?php if ($transaction['status'] === 'rejected' && !empty($transaction['rejection_reason'])) { ?>
            <i class="bi bi-info-circle" title="<?= htmlspecialchars($transaction['rejection_reason'], ENT_QUOTES, 'UTF-8'); ?>"></i>
        <?php } ?>
    </td>
    <td class="text-nowrap">
        <a href="view.php?id=<?= (int) $transaction['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">View</a>
        <?php if ($isAdmin && $transaction['status'] === 'pending') { ?>
            <a href="approve.php?id=<?= (int) $transaction['id']; ?>" class="rm-btn rm-btn-success rm-btn-sm">Approve</a>
            <button type="button" class="rm-btn rm-btn-danger rm-btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?= (int) $transaction['id']; ?>">Reject</button>

            <div class="modal fade" id="rejectModal<?= (int) $transaction['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="reject.php">
                            <div class="modal-header">
                                <h5 class="modal-title">Reject Transaction #<?= (int) $transaction['id']; ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="id" value="<?= (int) $transaction['id']; ?>">
                                <label class="form-label small fw-semibold text-muted">Reason (optional)</label>
                                <textarea name="reason" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="rm-btn rm-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="rm-btn rm-btn-danger">Reject</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php } ?>
        <?php if ($isAdmin) { ?>
         <a href="edit.php?id=<?= (int) $transaction['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> 
         <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this transaction?')">
            <input type="hidden" name="id" value="<?= (int) $transaction['id']; ?>">
            <button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>
        </form>
        <?php } ?>
        </td></tr><?php } ?></table></div>
        </div>
        <div id="pageResultsPagination">
        <?php render_pagination($currentPage, $totalPages); ?>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>