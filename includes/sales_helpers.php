<?php
/**
 * Shared helpers for the RM Mart & Spark sales module.
 */

function sales_compute_status($amountPaid, $totalAmount) {
    if ($amountPaid >= $totalAmount - 0.01) {
        return 'Paid';
    }
    if ($amountPaid <= 0.009) {
        return 'Credit';
    }
    return 'Partially Paid';
}

/**
 * Deducts stock for every line item on a sale and posts the revenue into
 * Finance/Transactions. Called either immediately (auto-approved sale)
 * or once a Manager approves a discounted sale.
 */
function sales_finalize($conn, $saleId) {
    $saleStatement = mysqli_prepare($conn, 'SELECT * FROM sales WHERE id = ?');
    mysqli_stmt_bind_param($saleStatement, 'i', $saleId);
    mysqli_stmt_execute($saleStatement);
    $sale = mysqli_fetch_assoc(mysqli_stmt_get_result($saleStatement));
    if (!$sale) {
        return false;
    }

    $itemsStatement = mysqli_prepare($conn, 'SELECT * FROM sale_items WHERE sale_id = ?');
    mysqli_stmt_bind_param($itemsStatement, 'i', $saleId);
    mysqli_stmt_execute($itemsStatement);
    $items = mysqli_stmt_get_result($itemsStatement);

    $hasProduct = false;
    while ($item = mysqli_fetch_assoc($items)) {
        if ($item['item_type'] !== 'Product' || !$item['product_id']) {
            continue; // services don't touch inventory
        }
        $hasProduct = true;
        $update = mysqli_prepare($conn, 'UPDATE products SET quantity = quantity - ? WHERE id = ?');
        mysqli_stmt_bind_param($update, 'ii', $item['quantity'], $item['product_id']);
        mysqli_stmt_execute($update);
    }

    // A sale can mix Product and Service line items, but a transaction only
    // takes one category now — tag it Product if any physical item was sold,
    // otherwise Service.
    $category = $hasProduct ? 'Product' : 'Service';

    $description = 'Sale #' . $saleId . ' (' . $sale['payment_method'] . ')';
    $transactionStatement = mysqli_prepare($conn, "INSERT INTO transactions (category, transaction_type, amount, transaction_date, description, recorded_by, status) VALUES (?, 'Income', ?, ?, ?, ?, 'approved')");
    mysqli_stmt_bind_param($transactionStatement, 'sdssi', $category, $sale['total_amount'], $sale['sale_date'], $description, $sale['recorded_by']);
    mysqli_stmt_execute($transactionStatement);
    $transactionId = mysqli_insert_id($conn);

    $newStatus = sales_compute_status((float) $sale['amount_paid'], (float) $sale['total_amount']);
    $updateSale = mysqli_prepare($conn, 'UPDATE sales SET status = ?, transaction_id = ? WHERE id = ?');
    mysqli_stmt_bind_param($updateSale, 'sii', $newStatus, $transactionId, $saleId);
    mysqli_stmt_execute($updateSale);

    return true;
}
/**
 * Cancels a Credit, Partially Paid, or fully Paid sale. Deletes the linked
 * income transaction (it's being reversed) and marks the sale Cancelled.
 * Stock is intentionally left untouched — goods already left the shop.
 * amount_paid is reset to 0 so a cancelled sale never shows a stale paid
 * balance; if cash was actually collected and needs to go back to the
 * customer, that refund must be recorded separately (e.g. as an expense).
 *
 * Already 'Cancelled' sales and sales still 'Pending Discount Approval'
 * are rejected.
 */
function sales_cancel($conn, $saleId, $userId, $reason) {
    $saleStatement = mysqli_prepare($conn, 'SELECT * FROM sales WHERE id = ?');
    mysqli_stmt_bind_param($saleStatement, 'i', $saleId);
    mysqli_stmt_execute($saleStatement);
    $sale = mysqli_fetch_assoc(mysqli_stmt_get_result($saleStatement));

    if (!$sale) {
        return ['ok' => false, 'error' => 'Sale not found.'];
    }
    if (!in_array($sale['status'], ['Credit', 'Partially Paid', 'Paid'], true)) {
        return ['ok' => false, 'error' => 'Only Credit, Partially Paid, or Paid sales can be cancelled.'];
    }

    mysqli_begin_transaction($conn);

    if (!empty($sale['transaction_id'])) {
        $deleteTx = mysqli_prepare($conn, 'DELETE FROM transactions WHERE id = ?');
        mysqli_stmt_bind_param($deleteTx, 'i', $sale['transaction_id']);
        mysqli_stmt_execute($deleteTx);
    }

    $updateSale = mysqli_prepare($conn, 'UPDATE sales
    SET status = "Cancelled", transaction_id = NULL, amount_paid = 0, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?,
        cancel_requested_by = NULL, cancel_requested_at = NULL, cancel_request_reason = NULL
    WHERE id = ?');
    mysqli_stmt_bind_param($updateSale, 'isi', $userId, $reason, $saleId);
    mysqli_stmt_execute($updateSale);

    mysqli_commit($conn);

    return ['ok' => true];
}
/**
 * Employee flags a Credit/Partially Paid sale for cancellation. Nothing is
 * actually cancelled yet — an Admin/Manager must approve it.
 */
function sales_request_cancel($conn, $saleId, $userId, $reason) {
    $saleStatement = mysqli_prepare($conn, 'SELECT * FROM sales WHERE id = ?');
    mysqli_stmt_bind_param($saleStatement, 'i', $saleId);
    mysqli_stmt_execute($saleStatement);
    $sale = mysqli_fetch_assoc(mysqli_stmt_get_result($saleStatement));

    if (!$sale) {
        return ['ok' => false, 'error' => 'Sale not found.'];
    }
   if (!in_array($sale['status'], ['Credit', 'Partially Paid', 'Paid'], true)) {
        return ['ok' => false, 'error' => 'Only Credit, Partially Paid, or Paid sales can be requested for cancellation.'];
    }
    if (!empty($sale['cancel_requested_by'])) {
        return ['ok' => false, 'error' => 'A cancellation request is already pending for this sale.'];
    }

    $update = mysqli_prepare($conn, 'UPDATE sales SET cancel_requested_by = ?, cancel_requested_at = NOW(), cancel_request_reason = ? WHERE id = ?');
    mysqli_stmt_bind_param($update, 'isi', $userId, $reason, $saleId);
    mysqli_stmt_execute($update);

    return ['ok' => true];
}

/**
 * Admin/Manager rejects a pending cancellation request — the sale is left
 * exactly as it was, just clears the request flags.
 */
function sales_reject_cancel_request($conn, $saleId) {
    $update = mysqli_prepare($conn, 'UPDATE sales SET cancel_requested_by = NULL, cancel_requested_at = NULL, cancel_request_reason = NULL WHERE id = ?');
    mysqli_stmt_bind_param($update, 'i', $saleId);
    mysqli_stmt_execute($update);
    return ['ok' => true];
}