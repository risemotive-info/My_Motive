<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/notification_helper.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$reason = trim($_POST['reason'] ?? '') ?: 'No reason provided.';

if (!$id) {
    header('Location: index.php');
    exit;
}

$statement = mysqli_prepare($conn, "SELECT recorded_by, amount, transaction_type FROM transactions WHERE id = ? AND status = 'pending'");
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$transaction = mysqli_stmt_get_result($statement)->fetch_assoc();

if ($transaction) {
    $adminId = $_SESSION['user_id'] ?? null;

    $update = mysqli_prepare($conn, "UPDATE transactions SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
    mysqli_stmt_bind_param($update, 'isi', $adminId, $reason, $id);
    mysqli_stmt_execute($update);

    if (!empty($transaction['recorded_by'])) {
        notifyUser(
            $conn,
            $transaction['recorded_by'],
            'Transaction Rejected',
            'Your ' . strtolower($transaction['transaction_type']) . ' of RWF ' . number_format((float) $transaction['amount'], 2) . ' (#' . $id . ') was rejected: ' . $reason
        );
    }

    header('Location: index.php?success=Transaction rejected.');
    exit;
}

header('Location: index.php?error=Transaction not found or already reviewed.');
exit;