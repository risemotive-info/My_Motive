<?php
$pageSearchScope = 'sales'; // tells the topbar search what module we're in
require '../../config/db.php';
require_role(['Admin', 'Manager', 'Employee']);
require '../../includes/pagination.php';
include '../../includes/header.php'; include '../../includes/sidebar.php';

const PER_PAGE = 10;
$role = current_user_role();
$userId = current_user_id();

$whereClause = $role === 'Employee' ? ' WHERE sales.recorded_by = ' . (int) $userId : '';

$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM sales' . $whereClause))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

$sql = "SELECT sales.*, customers.name AS customer_name, users.names AS recorded_by_name
        FROM sales LEFT JOIN customers ON sales.customer_id = customers.id
        LEFT JOIN users ON sales.recorded_by = users.id" . $whereClause . "
        ORDER BY sales.created_at DESC LIMIT " . PER_PAGE . " OFFSET " . $offset;
$sales = mysqli_query($conn, $sql);

$pending = null;
if (in_array($role, ['Manager', 'Admin'], true)) {
    $pending = mysqli_query($conn, "SELECT sales.*, customers.name AS customer_name, users.names AS requested_by_name FROM sales LEFT JOIN customers ON sales.customer_id = customers.id LEFT JOIN users ON sales.discount_requested_by = users.id WHERE sales.status = 'Pending Discount Approval' ORDER BY sales.created_at");
}

function sale_status_badge($status) {
    $map = ['Pending Discount Approval' => 'secondary', 'Paid' => 'success', 'Partially Paid' => 'warning text-dark', 'Credit' => 'danger', 'Cancelled' => 'dark'];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
    <?php if (isset($_GET['error'])) { ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Sales</h2>
    <a href="create.php" class="btn btn-primary">+ New Sale</a>
</div>

<?php if ($pending && mysqli_num_rows($pending) > 0) { ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">Awaiting Discount Approval</h5></div>
    <?php
$cancelRequests = null;
if (in_array($role, ['Manager', 'Admin'], true)) {
    $cancelRequests = mysqli_query($conn, "SELECT sales.*, customers.name AS customer_name, users.names AS requested_by_name
        FROM sales LEFT JOIN customers ON sales.customer_id = customers.id
        LEFT JOIN users ON sales.cancel_requested_by = users.id
        WHERE sales.cancel_requested_by IS NOT NULL ORDER BY sales.cancel_requested_at");
}
?>
<?php if ($cancelRequests && mysqli_num_rows($cancelRequests) > 0) { ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">Pending Cancellation Requests</h5></div>
    <div class="card-body p-0">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr><th>Date</th><th>Customer</th><th>Requested By</th><th>Reason</th><th>Total</th><th>Action</th></tr>
            <?php while ($row = mysqli_fetch_assoc($cancelRequests)) { ?>
            <tr>
                <td><?= date('d M Y', strtotime($row['sale_date'])); ?></td>
                <td><?= htmlspecialchars($row['customer_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($row['requested_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($row['cancel_request_reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>RWF <?= number_format($row['total_amount'], 2); ?></td>
                <td><a href="review_cancel_request.php?id=<?= (int) $row['id']; ?>" class="btn btn-primary btn-sm">Review</a></td>
            </tr>
            <?php } ?>
        </table>
    </div>
</div>
<?php } ?>
    <div class="card-body p-0">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr><th>Date</th><th>Customer</th><th>Requested By</th><th>Discount</th><th>Total</th><th>Action</th></tr>
            <?php while ($row = mysqli_fetch_assoc($pending)) { ?>
            <tr>
                <td><?= date('d M Y', strtotime($row['sale_date'])); ?></td>
                <td><?= htmlspecialchars($row['customer_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($row['requested_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                <td>RWF <?= number_format($row['discount_amount'], 2); ?></td>
                <td>RWF <?= number_format($row['total_amount'], 2); ?></td>
                <td><a href="approve_discount.php?id=<?= (int) $row['id']; ?>" class="btn btn-primary btn-sm">Review</a></td>
            </tr>
            <?php } ?>
        </table>
    </div>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <!-- Live-search wires up against this container: it caches this exact
             markup on page load and swaps it out for filtered results as you
             type in the topbar search box, restoring it when the box is
             cleared. The discount/cancel-approval panels above are separate
             management widgets, not search results, so they stay outside. -->
        <div id="pageResultsContainer">
        <div class="table-responsive">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr>
                <th>Date</th>
                <th>Customer</th>
                <th>Recorded By</th>
                <th>Payment</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php if (mysqli_num_rows($sales) === 0) { ?><tr><td colspan="8" class="text-center text-muted py-4">No sales recorded yet.</td></tr><?php } ?>
            <?php while ($sale = mysqli_fetch_assoc($sales)) { ?>
            <tr>
                <td><?= date('d M Y', strtotime($sale['sale_date'])); ?></td>
                <td><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($sale['recorded_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($sale['payment_method'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>RWF <?= number_format($sale['total_amount'], 2); ?></td>
                <td>RWF <?= number_format($sale['amount_paid'], 2); ?></td>
                <td><?= sale_status_badge($sale['status']); ?></td>
                <td>
               <a href="invoice.php?id=<?= (int) $sale['id']; ?>" target="_blank" class="btn btn-outline-primary btn-sm">Invoice</a>

    <?php if ($sale['status'] === 'Credit' || $sale['status'] === 'Partially Paid') { ?>
        <a href="pay_credit.php?id=<?= (int) $sale['id']; ?>" class="btn btn-outline-success btn-sm">Pay</a>
    <?php } ?>

    <?php if (in_array($sale['status'], ['Credit', 'Partially Paid', 'Paid'], true)) { ?>
        <?php if (in_array($role, ['Admin', 'Manager'], true)) { ?>
            <?php if (!empty($sale['cancel_requested_by'])) { ?>
                <a href="review_cancel_request.php?id=<?= (int) $sale['id']; ?>" class="btn btn-warning btn-sm">Review Request</a>
            <?php } else { ?>
                <a href="cancel.php?id=<?= (int) $sale['id']; ?>" class="btn btn-outline-danger btn-sm">Cancel</a>
            <?php } ?>
        <?php } elseif (!empty($sale['cancel_requested_by'])) { ?>
            <span class="badge bg-secondary">Cancel Requested</span>
        <?php } else { ?>
            <a href="request_cancel.php?id=<?= (int) $sale['id']; ?>" class="btn btn-outline-warning btn-sm">Request Cancel</a>
        <?php } ?>
    <?php } ?>
                </td>
            </tr>
            <?php } ?>
        </table>
        </div>
        </div>
        <div id="pageResultsPagination">
        <?php render_pagination($currentPage, $totalPages); ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>