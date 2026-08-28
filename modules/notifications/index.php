<?php
require '../../config/db.php';
$pageSearchScope = 'notifications'; // tells the topbar search what module we're in
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const PER_PAGE = 10;

if (isset($_GET['success'])) {
?>
<div class="alert alert-success alert-dismissible fade show" role="alert">

    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>

    <button type="button"
            class="btn-close"
            data-bs-dismiss="alert">
    </button>

</div>
<?php
}
?>

<?php
$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM notifications"))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

$query = "
SELECT notifications.*, users.names
FROM notifications
LEFT JOIN users
ON notifications.user_id = users.id
ORDER BY notifications.id DESC
LIMIT " . PER_PAGE . " OFFSET " . $offset;

$result = mysqli_query($conn, $query);
?>
<div class="d-flex justify-content-between align-items-center mb-4">

    <h2>Notifications Management</h2>

</div>

<div class="card border-0 shadow-sm">

<div class="card-body p-0">
<div id="pageResultsContainer">
<div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0">

<tr>
    <th>User</th>
    <th>Title</th>
    <th>Message</th>
    <th>Status</th>
    <th>Date</th>
    <th>Action</th>
</tr>

<?php if (mysqli_num_rows($result) === 0) { ?>
<tr><td colspan="6" class="text-center text-muted py-4">No notifications yet.</td></tr>
<?php } ?>

<?php while($row = mysqli_fetch_assoc($result)){ ?>

<tr>

<td><?= htmlspecialchars($row['names'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>

<td><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></td>

<td><?= htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8'); ?></td>

<td>

<?php if($row['is_read']){ ?>

<span class="badge bg-success">
Read
</span>

<?php } else { ?>

<span class="badge bg-warning text-dark">
Unread
</span>

<?php } ?>

</td>

<td><?= date('d M Y H:i', strtotime($row['created_at'])); ?></td>

<td class="text-nowrap">

<a href="view.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">
View
</a>

<a href="mark_read.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-success rm-btn-sm">
Mark Read
</a>

<a href="delete.php?id=<?= (int) $row['id']; ?>"
class="rm-btn rm-btn-danger rm-btn-sm"
onclick="return confirm('Delete this notification?')">
Delete
</a>

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
    if (alert) {
        alert.remove();
    }
}, 3000);
</script>

<?php
include '../../includes/footer.php';
?>