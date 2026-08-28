<?php
// ONE-TIME MIGRATION RUNNER.
// Visit this page once while logged in as an admin, confirm the results look
// right, then DELETE this file and push that deletion. Do not leave it on
// the server.

session_start();
require __DIR__ . '/config/db.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    http_response_code(403);
    die('Forbidden: log in as an admin first, then reload this page.');
}

header('Content-Type: text/plain');

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'];
    return $count > 0;
}

echo "Starting migration...\n\n";

// --- Step 1: add missing columns to transactions ---
$columns = [
    'recorded_by' => "ALTER TABLE transactions ADD COLUMN recorded_by INT(11) NULL",
    'status' => "ALTER TABLE transactions ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'approved'",
    'approved_by' => "ALTER TABLE transactions ADD COLUMN approved_by INT(11) NULL",
    'approved_at' => "ALTER TABLE transactions ADD COLUMN approved_at DATETIME NULL",
    'rejection_reason' => "ALTER TABLE transactions ADD COLUMN rejection_reason TEXT NULL",
];

foreach ($columns as $name => $sql) {
    if (column_exists($conn, 'transactions', $name)) {
        echo "SKIP: column '$name' already exists.\n";
        continue;
    }
    if (mysqli_query($conn, $sql)) {
        echo "OK: added column '$name'.\n";
    } else {
        echo "ERROR adding '$name': " . mysqli_error($conn) . "\n";
    }
}

echo "\n";

// --- Step 2: backfill old status values ---
$backfillSql = "UPDATE transactions SET status = 'approved' WHERE status NOT IN ('approved', 'pending', 'rejected')";
if (mysqli_query($conn, $backfillSql)) {
    echo "OK: backfilled " . mysqli_affected_rows($conn) . " row(s) to status = 'approved'.\n";
} else {
    echo "ERROR backfilling status: " . mysqli_error($conn) . "\n";
}

echo "\nMigration finished. DELETE THIS FILE NOW.\n";