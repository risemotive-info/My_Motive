<?php
require '../../config/db.php';
$pageSearchScope = 'notifications'; // tells the topbar search what module we're in
include '../../includes/header.php';
include '../../includes/sidebar.php';

if(isset($_GET['success'])){
?>
<div class="alert alert-success alert-dismissible fade show" role="alert">

    <?= htmlspecialchars($_GET['success']); ?>

    <button type="button"
            class="btn-close"
            data-bs-dismiss="alert">
    </button>

</div>
<?php
}
?>

<?php

$query = "
SELECT notifications.*, users.names
FROM notifications
LEFT JOIN users
ON notifications.user_id = users.id
ORDER BY notifications.id DESC
";

$result = mysqli_query($conn, $query);
?>
<div class="d-flex justify-content-between align-items-center mb-4">

    <h2>Notifications Management</h2>

</div>

<div class="card shadow">

<div class="card-body" id="pageResultsContainer">

<table class="table table-bordered table-hover">

<tr>
    <th>User</th>
    <th>Title</th>
    <th>Message</th>
    <th>Status</th>
    <th>Date</th>
    <th>Action</th>
</tr>

<?php while($row = mysqli_fetch_assoc($result)){ ?>

<tr>

<td><?= $row['names']; ?></td>

<td><?= $row['title']; ?></td>

<td><?= $row['message']; ?></td>

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

<a href="view.php?id=<?= $row['id']; ?>" class="rm-btn rm-btn-info btn-sm">
View
</a>

<a href="mark_read.php?id=<?= $row['id']; ?>" class="rm-btn rm-btn-success btn-sm">
Mark Read
</a>

<a href="delete.php?id=<?= $row['id']; ?>"
class="rm-btn rm-btn-danger btn-sm"
onclick="return confirm('Delete this notification?')">
Delete
</a>

</td>

</tr>

<?php } ?>

</table>

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