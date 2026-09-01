<?php
session_start();
require '../../config/db.php';
$pageSearchScope = 'products'; // tells the topbar search what module we're in
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const LOW_STOCK_THRESHOLD = 5;
const PER_PAGE = 10;

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';

$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM products'))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

$result = mysqli_query($conn, "SELECT * FROM products ORDER BY item_type, product_name ASC LIMIT " . PER_PAGE . " OFFSET " . $offset);
$lowStockCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM products WHERE item_type = 'Item' AND quantity <= " . LOW_STOCK_THRESHOLD . " AND is_active = 1"))['c'];

// Total value of current stock, based on Buying Price. Only active, physical
// Items carry stock — Services are excluded since they have no quantity.
$stockValueRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(quantity * buying_price), 0) AS stock_value
    FROM products WHERE item_type = 'Item' AND is_active = 1"));
$totalStockValue = (float) $stockValueRow['stock_value'];
?>

<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Items &amp; Services</h2>
    <div class="d-flex gap-2">
        <a href="../purchases/index.php" class="rm-btn rm-btn-secondary"><i class="bi bi-clock-history me-1"></i>Purchase History</a>
        <?php if ($isAdmin) { ?>
        <a href="create.php" class="rm-btn rm-btn-primary">+ Add Item or Service</a>
        <?php } ?>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <small class="text-muted">Total Stock Value</small>
                <h4 class="text-primary mb-0">RWF <?= number_format($totalStockValue, 2); ?></h4>
                <small class="text-muted">Based on Buying Price of active items in stock</small>
            </div>
        </div>
    </div>
</div>

<?php if ($lowStockCount > 0) { ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4" style="border-radius:10px;">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <span><?= (int) $lowStockCount; ?> item<?= $lowStockCount == 1 ? '' : 's'; ?> at or below <?= LOW_STOCK_THRESHOLD; ?> units in stock.</span>
</div>
<?php } ?>

<div class="card shadow">
<div class="card-body">
<!-- Live-search wires up against this container: it caches this exact markup
     on page load and swaps it out for filtered results as you type in the
     topbar search box, restoring it when the box is cleared. -->
<div id="pageResultsContainer">
<div style="overflow-x:auto;">
<table class="table table-bordered table-hover">
<tr>
    <th>No.</th><th>Type</th><th>Name</th><th>Code</th><th>Quantity</th><th>Unit</th><th>Buying Price</th><th>Selling Price</th><th>Status</th><th>Action</th>
</tr>
<?php if (mysqli_num_rows($result) === 0) { ?><tr><td colspan="10" class="text-center text-muted py-4">No items or services yet.</td></tr><?php } ?>
<?php $rowNumber = $offset + 1; ?>
<?php while ($row = mysqli_fetch_assoc($result)) { $isService = $row['item_type'] === 'Service'; ?>
<tr>
    <td><?= $rowNumber++; ?></td>
    <td><span class="badge bg-<?= $isService ? 'info text-dark' : 'primary'; ?>"><?= htmlspecialchars($row['item_type'], ENT_QUOTES, 'UTF-8'); ?></span></td>
    <td><?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($row['product_code'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td>
        <?php if ($isService) { ?>
            <span class="text-muted">—</span>
        <?php } else { ?>
            <?= (int) $row['quantity']; ?>
            <?php if ($row['quantity'] <= LOW_STOCK_THRESHOLD) { ?>
                <span class="badge bg-warning text-dark ms-1">Low Stock</span>
            <?php } ?>
        <?php } ?>
    </td>
    <td><?= $isService ? '<span class="text-muted">—</span>' : htmlspecialchars($row['unit'] ?? 'Pieces', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= $isService ? '<span class="text-muted">—</span>' : 'RWF ' . number_format($row['buying_price'], 2); ?></td>
    <td>RWF <?= number_format($row['selling_price'], 2); ?></td>
    <td>
        <?php if ($row['is_active']) { ?>
            <span class="badge bg-success">Active</span>
        <?php } else { ?>
            <span class="badge bg-danger">Inactive</span>
        <?php } ?>
    </td>
    <td class="text-nowrap">
        <a href="view.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">View</a>
        <?php if ($isAdmin) { ?>
        <a href="edit.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a>
        <?php if (!$isService) { ?>
        <a href="restock.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-success rm-btn-sm"><i class="bi bi-box-arrow-in-down"></i> Restock</a>
        <?php } ?>
        <a href="delete.php?id=<?= (int) $row['id']; ?>" class="rm-btn <?= $row['is_active'] ? 'rm-btn-danger' : 'rm-btn-success'; ?> rm-btn-sm" onclick="return confirm('<?= $row['is_active'] ? 'Deactivate this?' : 'Reactivate this?'; ?>')"><?= $row['is_active'] ? 'Deactivate' : 'Activate'; ?></a>
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