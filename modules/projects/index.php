<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
$pageSearchScope = 'projects'; // tells the topbar search what module we're in
include '../../includes/header.php';
include '../../includes/sidebar.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';

$sql = "SELECT projects.*, users.names AS creator_name
        FROM projects
        LEFT JOIN users ON projects.created_by = users.id
        ORDER BY projects.id ASC";
$projects = mysqli_query($conn, $sql);
?>

<?php if (isset($_GET['success'])) { ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Projects Management</h2>
    <?php if ($isAdmin) { ?>
    <a href="create.php" class="rm-btn rm-btn-primary">+ Add Project</a>
    <?php } ?>
</div>

<div class="card border-0 shadow-sm"><div class="card-body p-0">
<!-- Live-search wires up against this container: it caches this exact markup
     on page load and swaps it out for filtered results as you type in the
     topbar search box, restoring it when the box is cleared. -->
<div id="pageResultsContainer">
<div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0">
    <tr><th>No.</th><th>Project</th><th>Dates</th><th>Status</th><th>Created By</th><th>Action</th></tr>
    <?php if (mysqli_num_rows($projects) === 0) { ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No projects found.</td></tr>
    <?php } ?>
    <?php $rowNumber = 1; ?>
    <?php while ($project = mysqli_fetch_assoc($projects)) { ?>
        <tr>
            <td><?= $rowNumber++; ?></td>
            <td><strong><?= htmlspecialchars($project['project_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br><small class="text-muted"><?= htmlspecialchars($project['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></td>
            <td><?= $project['start_date'] ? date('d M Y', strtotime($project['start_date'])) : '—'; ?><br><?= $project['end_date'] ? date('d M Y', strtotime($project['end_date'])) : '—'; ?></td>
            <td><span class="badge bg-<?= $project['status'] === 'Completed' ? 'success' : ($project['status'] === 'On Hold' ? 'secondary' : 'primary'); ?>"><?= htmlspecialchars($project['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
            <td><?= htmlspecialchars($project['creator_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="text-nowrap">
                <a href="view.php?id=<?= (int) $project['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">View</a>
                <?php if ($isAdmin) { ?>
             <a href="edit.php?id=<?= (int) $project['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> 
             <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this project?')">
                <input type="hidden" name="id" value="<?= (int) $project['id']; ?>">
                <button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>
            </form>
                <?php } ?>
            </td>
        </tr>
    <?php } ?>
</table>
</div>
</div>
</div></div>
<?php include '../../includes/footer.php'; ?>