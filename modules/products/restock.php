<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?success=' . urlencode('You do not have permission to restock items.'));
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid item selected.'); exit; }

$statement = mysqli_prepare($conn, "SELECT * FROM products WHERE id = ? AND item_type = 'Item'");
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$product) { header('Location: index.php?success=Item not found or is a service.'); exit; }

if (isset($_POST['save'])) {
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
    $unitCost = filter_input(INPUT_POST, 'unit_cost', FILTER_VALIDATE_FLOAT);
    $supplier = trim($_POST['supplier'] ?? '');
    $purchaseDate = $_POST['purchase_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $recordedBy = $_SESSION['user_id'] ?? null;
    $validDate = DateTime::createFromFormat('Y-m-d', $purchaseDate);

    if (!$quantity || $quantity <= 0 || $unitCost === false || $unitCost < 0 || !$validDate || $validDate->format('Y-m-d') !== $purchaseDate) {
        $error = 'Please enter a valid quantity, unit cost, and date.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $insertPurchase = mysqli_prepare($conn, 'INSERT INTO purchases (product_id, quantity, unit_cost, supplier, purchase_date, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($insertPurchase, 'iidsssi', $id, $quantity, $unitCost, $supplier, $purchaseDate, $notes, $recordedBy);
            mysqli_stmt_execute($insertPurchase);

            $updateStock = mysqli_prepare($conn, 'UPDATE products SET quantity = quantity + ? WHERE id = ?');
            mysqli_stmt_bind_param($updateStock, 'ii', $quantity, $id);
            mysqli_stmt_execute($updateStock);

            // Auto-post the restock cost to Transactions as an Expense.
            // No approval needed — mirrors how sales_finalize() posts income
            // for Sales, so it appears in Transactions immediately.
            $totalCost = $quantity * $unitCost;
            $description = 'Restock: ' . $quantity . ' x ' . $product['product_name']
                . ($supplier !== '' ? ' from ' . $supplier : '');
            $insertTransaction = mysqli_prepare($conn, "INSERT INTO transactions
                (category, transaction_type, amount, transaction_date, description, recorded_by, status)
                VALUES ('Purchase (Re-stock)', 'Expense', ?, ?, ?, ?, 'approved')");
            mysqli_stmt_bind_param($insertTransaction, 'dssi', $totalCost, $purchaseDate, $description, $recordedBy);
            mysqli_stmt_execute($insertTransaction);

            mysqli_commit($conn);
            header('Location: index.php?success=' . urlencode($quantity . ' units added to ' . $product['product_name'] . '.'));
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = 'Unable to record restock. Please try again.';
        }
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-box-arrow-in-down';
$modal_title = 'Restock Item';
$modal_subtitle = 'Add new stock and record the purchase.';
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <div class="d-flex align-items-center justify-content-between mb-3 p-3" style="background:#F8FAFC; border-radius:12px;">
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-muted small">Code: <?= htmlspecialchars($product['product_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="text-end">
                    <div class="text-muted small">Current stock</div>
                    <div class="fw-bold fs-5"><?= (int) $product['quantity']; ?> <?= htmlspecialchars($product['unit'] ?? 'Pieces', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>

            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <form method="POST">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Quantity to add</label>
                        <input type="number" name="quantity" class="form-control rm-input" min="1" step="1" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Unit cost (RWF)</label>
                        <input type="number" name="unit_cost" class="form-control rm-input" min="0" step="0.01" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Supplier</label>
                    <input type="text" name="supplier" class="form-control rm-input" placeholder="e.g. ABC Wholesalers">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control rm-input" value="<?= date('Y-m-d'); ?>" required>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Notes</label>
                    <textarea name="notes" class="form-control rm-input" rows="3" style="height:auto;"></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Add Stock
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">
                        <i class="bi bi-x-circle-fill me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>