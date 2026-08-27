<?php
require '../../config/db.php';
$pageSearchScope = 'departments'; // tells the topbar search what module we're in
require_role(['Admin']);
include '../../includes/header.php';
include '../../includes/sidebar.php';
require '../../includes/status_filter.php';
require '../../includes/pagination.php';
const PER_PAGE = 10;
$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM departments'))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

$query = 'SELECT * FROM departments ORDER BY name LIMIT ' . PER_PAGE . ' OFFSET ' . $offset;
$result = mysqli_query($conn, $query);
$statusFilter = get_status_filter();
$whereClause = status_where_clause($statusFilter, 'users.is_active');

$departmentsList = [];
while ($row = mysqli_fetch_assoc($result)) { $departmentsList[] = $row; }
// Load all active roles
$rolesQuery = mysqli_query($conn, "
    SELECT department_id, name
    FROM roles
    WHERE is_active = 1
    ORDER BY name
");

$rolesByDepartment = [];

while ($role = mysqli_fetch_assoc($rolesQuery)) {
    $rolesByDepartment[$role['department_id']][] = $role['name'];
}
?>

<?php if (isset($_GET['success'])) { ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Departments Management</h2>
    <a href="create.php" class="rm-btn rm-btn-primary">+ Add Department</a>
</div>

<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div style="overflow-x:auto;" id="pageResultsContainer">
<table class="table table-bordered table-hover bg-white mb-0">
<tr>
    <th>Name</th>
    <th>Roles</th>
    <th>Status</th>
    <th>Action</th>
</tr>
<?php if (empty($departmentsList)) { ?>
<tr><td colspan="4" class="text-center text-muted py-4">No departments yet.</td></tr>
<?php } ?>
<?php foreach ($departmentsList as $row) {
    $roles = $rolesByDepartment[$row['id']] ?? [];
?>
<tr>
    <td>
        <strong><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
        <div class="text-muted small"><?= htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8'); ?></div>
    </td>
    <td>
        <?php if (empty($roles)) { ?>
            <span class="text-muted small">No roles defined yet</span>
        <?php } else { ?>
            <?php foreach ($roles as $title) { ?>
                <span class="badge bg-light text-dark border me-1 mb-1"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php } ?>
        <?php } ?>
    </td>
    <td>
        <?php if ($row['is_active']) { ?>
            <span class="badge bg-success">Active</span>
        <?php } else { ?>
            <span class="badge bg-danger">Inactive</span>
        <?php } ?>
    </td>
    <td class="text-nowrap">
        <a href="view.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">View / Manage Roles</a>
        <a href="edit.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a>
        <form method="POST" action="toggle_status.php" class="d-inline" onsubmit="return confirm('<?= $row['is_active'] ? 'Deactivate this department? It will be hidden from the Add Employee dropdown but its data is kept.' : 'Reactivate this department? It will show up in the Add Employee dropdown again.'; ?>')">
            <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
            <button type="submit" class="rm-btn <?= $row['is_active'] ? 'rm-btn-secondary' : 'rm-btn-success'; ?> rm-btn-sm"><?= $row['is_active'] ? 'Deactivate' : 'Reactivate'; ?></button>
        </form>
        <form method="POST" action="delete_permanent.php" class="d-inline" onsubmit="return confirm('Permanently delete this department? This cannot be undone. It only works if no employees or roles are assigned to it.')">
            <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
            <button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>
        </form>
    </td>
</tr>
<?php } ?>
</table>
</div>
<div id="pageResultsPagination">
<?php render_pagination($currentPage, $totalPages); ?>
</div>
</div>

<?php include '../../includes/footer.php'; ?>