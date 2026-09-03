<?php
require '../../config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid notification.'); exit; }

$role = current_user_role();
if ($role !== 'Admin') {
    header('Location: index.php?success=Only an Admin can delete notifications.');
    exit;
}

$statement = mysqli_prepare($conn, 'DELETE FROM notifications WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);

header("Location:index.php?success=Notification deleted successfully.");
exit;