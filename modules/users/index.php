<?php
require '../../config/db.php';
$pageSearchScope = 'employees'; // tells the topbar search what module we're in
require_role(['Admin']);
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const PER_PAGE = 10;
$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM users'))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;
?>

<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="rm-btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<?php
$query = "
SELECT users.*, departments.name AS department_name
FROM users
LEFT JOIN departments ON users.department_id = departments.id
ORDER BY users.id ASC
LIMIT " . PER_PAGE . " OFFSET " . $offset;
$result = mysqli_query($conn, $query);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Employees Management</h2>
    <a href="create.php" class="rm-btn rm-btn-primary">+ Add Employee</a>
</div>

<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<!-- Live-search wires up against this container: it caches this exact markup
     on page load and swaps it out for filtered results as you type in the
     topbar search box, restoring it when the box is cleared. -->
<div id="pageResultsContainer">
<div style="overflow-x:auto;">
<table class="table table-bordered table-hover bg-white mb-0">
<tr>
    <th>ID</th>
    <th>Full Name</th>
    <th>Email</th>
    <th>Role</th>
    <th>Department</th>
    <th>Status</th>
    <th>Created At</th>
    <th>Action</th>
</tr>
<?php if (mysqli_num_rows($result) === 0) { ?>
<tr>
    <td colspan="8" class="text-center text-muted py-4">No employees yet.</td>
</tr><?php } ?>
<?php while ($row = mysqli_fetch_assoc($result)) { ?>
<tr>
    <td><?= (int) $row['id']; ?></td>
    <td><?= htmlspecialchars($row['names'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars(ucfirst($row['role']), ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($row['department_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td>
        <?php if ($row['is_active']) { ?>
            <span class="badge bg-success">Active</span>
        <?php } else { ?>
            <span class="badge bg-danger">Inactive</span>
        <?php } ?>
    </td>
    <td><?= htmlspecialchars(date('d M Y', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
    <td class="text-nowrap">
        <a href="view.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">View</a>
        <a href="edit.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a>
        <form method="POST" action="toggle_status.php" class="d-inline" onsubmit="return confirm('<?= $row['is_active'] ? 'Deactivate this employee? Their history is kept but they will no longer be able to log in.' : 'Reactivate this employee? They will be able to log in again.'; ?>')">
            <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
            <button type="submit" class="rm-btn <?= $row['is_active'] ? 'rm-btn-secondary' : 'rm-btn-success'; ?> rm-btn-sm"><?= $row['is_active'] ? 'Deactivate' : 'Reactivate'; ?></button>
        </form>
        <form method="POST" action="delete_permanent.php" class="d-inline" onsubmit="return confirm('Permanently delete this employee? This cannot be undone. It only works if they have no attendance, tasks, sales, payroll, or leave history.')">
            <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
            <button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>
        </form>
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
<script>
setTimeout(function () {
    let alert = document.querySelector('.alert');
    if (alert) { alert.remove(); }
}, 3000);
</script>
<?php include '../../includes/footer.php'; ?>