<?php
require '../../config/db.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid notification.'); exit; }

$userId = current_user_id();
$role = current_user_role();
$isAdmin = $role === 'Admin';

if ($isAdmin) {
    $statement = mysqli_prepare($conn, "
    SELECT notifications.*, users.names
    FROM notifications
    LEFT JOIN users
    ON notifications.user_id = users.id
    WHERE notifications.id = ?");
    mysqli_stmt_bind_param($statement, 'i', $id);
} else {
    // Only allow viewing your own notification.
    $statement = mysqli_prepare($conn, "
    SELECT notifications.*, users.names
    FROM notifications
    LEFT JOIN users
    ON notifications.user_id = users.id
    WHERE notifications.id = ? AND notifications.user_id = ?");
    mysqli_stmt_bind_param($statement, 'ii', $id, $userId);
}
mysqli_stmt_execute($statement);
$notification = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$notification) { header('Location: index.php?success=Notification not found.'); exit; }
?>

<div class="card shadow">

    <div class="card-header">
        <h3>Notification Details</h3>
    </div>

    <div class="card-body">

        <table class="table table-bordered">

            <tr>
                <th width="200">User</th>
                <td><?= htmlspecialchars($notification['names'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>

            <tr>
                <th>Title</th>
                <td><?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>

            <tr>
                <th>Message</th>
                <td><?= htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>

            <tr>
                <th>Status</th>
                <td>
                    <?= $notification['is_read'] ? "Read" : "Unread"; ?>
                </td>
            </tr>

            <tr>
                <th>Date</th>
                <td><?= date('d M Y H:i', strtotime($notification['created_at'])); ?></td>
            </tr>

        </table>

        <a href="index.php" class="btn btn-secondary">
            Back
        </a>

    </div>

</div>

<?php
include '../../includes/footer.php';
?>