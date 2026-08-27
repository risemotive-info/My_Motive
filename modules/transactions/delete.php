<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?success=' . urlencode('You do not have permission to delete transactions.'));
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?success=' . urlencode('Invalid transaction selected.'));
    exit;
}

$statement = mysqli_prepare($conn, 'DELETE FROM transactions WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);

$message = mysqli_stmt_affected_rows($statement) ? 'Transaction deleted successfully.' : 'Transaction not found.';
header('Location: index.php?success=' . urlencode($message));
exit;