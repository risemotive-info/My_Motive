<?php
/**
 * NEW FILE: includes/credit_payment_helpers.php
 *
 * Records ONE payment on a Credit / Partially Paid sale.
 *
 * This is the block that used to sit inside modules/sales/pay_credit.php, moved here WITHOUT changing what it does, so that
 *   - a payment typed in by the cashier (cash, bank, mobile money...) and
 *   - a Digital Payment that XentriPay has confirmed
 * are recorded by exactly the same code: the sale is locked, the overdue charges are brought up to date, the payment pays the
 * charges first and then the invoice amount (each with its own ledger entry), the loyalty points are decided, the profit goes
 * into the four Funds when the sale becomes Paid, and the "we received your payment" SMS goes out after the commit.
 */
require_once __DIR__ . '/sales_helpers.php';          // also loads fund_helpers.php and profit_rules.php
require_once __DIR__ . '/credit_charge_helpers.php';
require_once __DIR__ . '/sms_messages.php';

/**
 * @param array|null $digital  null for a normal payment. For a confirmed Digital Payment: the digital_payments row
 *                             (it must contain id and reference). The row is marked as applied in the SAME transaction.
 * @param bool       $ownTransaction  true (normal): this function opens and commits its own transaction and sends the SMS.
 *                             false: the caller already opened a transaction (and holds the lock on the digital_payments row),
 *                             commits it, and sends the SMS from the returned 'sms' values AFTER its commit.
 * @return array{payment_id:int, status:string, to_charges:float, to_principal:float, points_message:string, applied_amount:float, excess:float, sms:array}
 * @throws RuntimeException  a rule of the business (shown to the user as it is); any other Throwable is a system problem.
 *                           In both cases, when this function opened the transaction, it has been rolled back.
 */
function credit_record_payment(mysqli $conn, int $saleId, float $payAmount, ?int $recordedBy, ?array $digital = null, bool $ownTransaction = true): array
{
    if ($ownTransaction) {
        mysqli_begin_transaction($conn);
    }
    try {
        // Re-read the sale under a lock, so two payments at the same moment cannot both use the same old
        // amount_paid or overpay the sale.
        $lock = mysqli_prepare($conn, 'SELECT * FROM sales WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($lock, 'i', $saleId);
        mysqli_stmt_execute($lock);
        $fresh = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));

        if (!$fresh || !in_array($fresh['status'], ['Credit', 'Partially Paid'], true)) {
            throw new RuntimeException('This sale can no longer receive payments. Its status is now ' . ($fresh['status'] ?? 'unknown') . '.');
        }
        // Charges due up to this moment are added first (same transaction, same lock).
        credit_accrue($conn, $saleId, $recordedBy);
        $lock2 = mysqli_prepare($conn, 'SELECT * FROM sales WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($lock2, 'i', $saleId);
        mysqli_stmt_execute($lock2);
        $fresh = mysqli_fetch_assoc(mysqli_stmt_get_result($lock2));
        $freshBalance = round((float) $fresh['total_amount'] - (float) $fresh['amount_paid'] + (float) $fresh['charges_accrued'] - (float) $fresh['charges_paid'], 2);

        $excess = 0.0;
        if ($payAmount > $freshBalance) {
            if ($digital === null || $freshBalance <= 0.009) {
                throw new RuntimeException('Payment cannot exceed the remaining balance of RWF ' . number_format($freshBalance, 2) . '.');
            }
            // A Digital Payment is money that has REALLY been received. If the balance became smaller while the customer was
            // approving it (for example a manual payment was recorded meanwhile), only what is owed is applied and the
            // difference is reported so it can be refunded, never silently lost.
            $excess = round($payAmount - $freshBalance, 2);
            $payAmount = $freshBalance;
        }

        if ($digital === null) {
            $insert = mysqli_prepare($conn, 'INSERT INTO sale_payments (sale_id, amount, recorded_by) VALUES (?, ?, ?)');
            mysqli_stmt_bind_param($insert, 'idi', $saleId, $payAmount, $recordedBy);
        } else {
            $note = mb_substr('Digital Payment (MTN MoMo), ref ' . $digital['reference'], 0, 250);
            $insert = mysqli_prepare($conn, 'INSERT INTO sale_payments (sale_id, amount, recorded_by, notes) VALUES (?, ?, ?, ?)');
            mysqli_stmt_bind_param($insert, 'idis', $saleId, $payAmount, $recordedBy, $note);
        }
        mysqli_stmt_execute($insert);
        $paymentId = (int) mysqli_insert_id($conn);

        // The payment pays the charges first, then the invoice amount, each with its own ledger entry.
        // The status is Paid only when both are cleared.
        $applied = credit_apply_payment($conn, $fresh, (float) $payAmount, $paymentId, $recordedBy);
        $newStatus = $applied['status'];

        $pointsMessage = '';
        if ($newStatus === 'Paid') {
            // 72-hour rule: record when it was fully paid, and decide the pending points
            // (awarded if paid in full by the deadline, otherwise not awarded).
            $paidAt = mysqli_prepare($conn, 'UPDATE sales SET fully_paid_at = NOW() WHERE id = ? AND fully_paid_at IS NULL');
            mysqli_stmt_bind_param($paidAt, 'i', $saleId);
            mysqli_stmt_execute($paidAt);
            if (($fresh['points_status'] ?? '') === 'Pending') {
                $awarded = sales_settle_pending_points($conn, $saleId);
                $pointsMessage = $awarded > 0
                    ? ' Paid within 72 hours: ' . $awarded . ' Loyalty Points awarded.'
                    : ' Paid after the 72-hour deadline: no Loyalty Points for this invoice.';
            }

            // The payment that completes the sale puts its profit into the four Funds immediately. Safe if called twice.
            allocate_sale_profit($conn, $saleId, $recordedBy);
        }

        if ($digital !== null) {
            // The digital payment is marked as applied in the SAME transaction: the money and its record can never disagree.
            $attention = $excess > 0 ? 1 : 0;
            $remark = $excess > 0
                ? mb_substr('The customer paid RWF ' . number_format($excess, 2) . ' more than was owed when the payment was confirmed. This must be refunded.', 0, 290)
                : null;
            $mark = mysqli_prepare($conn, 'UPDATE digital_payments SET applied_at = NOW(), applied_amount = ?, sale_payment_id = ?, sale_id = ?, needs_attention = ?, note = COALESCE(?, note)
                WHERE id = ? AND applied_at IS NULL');
            $digitalId = (int) $digital['id'];
            mysqli_stmt_bind_param($mark, 'diiisi', $payAmount, $paymentId, $saleId, $attention, $remark, $digitalId);
            mysqli_stmt_execute($mark);
            if (mysqli_stmt_affected_rows($mark) !== 1) {
                throw new RuntimeException('This digital payment has already been recorded.');
            }
            // The ledger lines of this payment say that it came through Digital Payment, with its reference.
            $ref = ' (Digital Payment, ref ' . $digital['reference'] . ')';
            $ledger = mysqli_prepare($conn, 'UPDATE credit_ledger SET notes = LEFT(CONCAT(COALESCE(notes, \'\'), ?), 255) WHERE sale_payment_id = ?');
            mysqli_stmt_bind_param($ledger, 'si', $ref, $paymentId);
            mysqli_stmt_execute($ledger);
        }

        if ($ownTransaction) {
            mysqli_commit($conn);
        }
    } catch (Throwable $e) {
        if ($ownTransaction) {
            mysqli_rollback($conn);
        }
        throw $e;
    }

    $sms = [$saleId, $paymentId, (float) $payAmount, $recordedBy];
    if ($ownTransaction) {
        // "We received your payment" SMS, sent only after the payment is saved.
        sms_notify_payment($conn, $saleId, $paymentId, (float) $payAmount, $recordedBy);
    }

    return [
        'payment_id'     => $paymentId,
        'status'         => $newStatus,
        'to_charges'     => (float) $applied['to_charges'],
        'to_principal'   => (float) $applied['to_principal'],
        'points_message' => $pointsMessage,
        'applied_amount' => (float) $payAmount,
        'excess'         => $excess,
        'sms'            => $sms,
    ];
}

/** The sentence shown after a payment, built the same way the page always built it. */
function credit_payment_success_message(array $result): string
{
    $split = $result['to_charges'] > 0
        ? ' RWF ' . number_format($result['to_charges'], 2) . ' paid the overdue charges and RWF ' . number_format($result['to_principal'], 2) . ' the invoice.'
        : '';
    return 'Payment recorded successfully.' . $split . $result['points_message'];
}