<?php
require '../../config/db.php'; 
$pageSearchScope = 'customers'; // tells the topbar search what module we're in
include '../../includes/header.php'; 
include '../../includes/sidebar.php';
$customers = mysqli_query($conn, "SELECT customers.*, COALESCE(SUM(CASE WHEN sales.status != 'Cancelled' THEN sales.total_amount - sales.amount_paid ELSE 0 END), 0) AS balance FROM customers LEFT JOIN sales ON sales.customer_id = customers.id WHERE customers.is_active = 1 GROUP BY customers.id ORDER BY customers.name");
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customers</h2>
    <a href="create.php" class="rm-btn rm-btn-primary">+ Add Customer</a>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0" id="pageResultsContainer">
        <table class="table table-bordered table-hover bg-white mb-0">
<tr>
    <th>Name</th>
    <th>Phone</th>
    <th>Email</th>
    <th>Outstanding Balance</th>
    <th>Action</th>
</tr>
<?php if (mysqli_num_rows($customers) === 0) { ?>
<tr><td colspan="5" class="text-center text-muted py-4">No customers yet.</td></tr><?php } ?>
<?php while ($customer = mysqli_fetch_assoc($customers)) { ?>
<tr>
    <td><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($customer['phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($customer['email'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php if ($customer['balance'] > 0) { ?>
    <span class="text-danger fw-semibold">RWF <?= number_format($customer['balance'], 2); ?></span>
    <?php } else { ?><span class="text-muted">RWF 0.00</span><?php } ?></td>
    <td><a href="edit.php?id=<?= (int) $customer['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a></td>
</tr>
<?php } ?>
</table></div></div>
<?php include '../../includes/footer.php'; ?>