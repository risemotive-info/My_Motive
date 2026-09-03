<?php
require '../../config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid notification.'); exit; }

$userId = current_user_id();
$role = current_user_role();
$isAdmin = $role === 'Admin';

if ($isAdmin) {
    $statement = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE id = ?');
    mysqli_stmt_bind_param($statement, 'i', $id);
} else {
    // Only allow marking your own notification as read.
    $statement = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    mysqli_stmt_bind_param($statement, 'ii', $id, $userId);
}
mysqli_stmt_execute($statement);

header("Location:index.php?success=Notification marked as read.");
exit;