<?php
/**
 * NEW FILE: includes/digital_payment_helpers.php
 *
 * Digital Payment (MTN MoMo through XentriPay) for the staff portal, in two places:
 *   - purpose 'sale'   a NEW sale paid digitally. The sale is saved only after the payment is CONFIRMED, and a confirmed
 *                      payment can be used by one sale only.
 *   - purpose 'credit' a payment on a Credit / Partially Paid sale. Once XentriPay confirms it, it is recorded
 *                      automatically (by the page that is waiting, or by tools/dp_reconcile.php if nobody is waiting).
 *
 * The rules this file keeps:
 *   1. Only XentriPay's own answer ("SUCCESS" from the status call) can make a payment successful. Starting a request, a
 *      message on the screen or a call to our callback address never does.
 *   2. A confirmed payment is applied ONCE: row locks and unique keys make a second check, a retry, a double click or the
 *      cron job harmless.
 *   3. The customer's PIN never passes through here: it is typed on the customer's phone.
 *   4. Money that was really received is never lost: a payment that cannot be applied is kept, flagged "needs attention",
 *      and listed in modules/sales/digital_payments.php.
 */
require_once __DIR__ . '/xentripay.php';

const DP_DUPLICATE_WINDOW_SECONDS = 120;  // the same request again within this time is the same request
const DP_POLL_GAP_SECONDS = 2;            // never ask XentriPay about the same payment more often than this
const DP_EXPIRE_HOURS = 48;               // a payment nobody approved for this long is closed
const DP_SALE_USE_DAYS = 3;               // a confirmed payment for a new sale can be used for this long
const DP_START_LIMIT_PER_MINUTE = 6;      // requests one user may start per minute

// ───────────────────────────── small helpers ─────────────────────────────

/** The token the browser must send with every request that changes something (CSRF protection). */
function dp_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['dp_csrf'])) {
        $_SESSION['dp_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['dp_csrf'];
}

function dp_csrf_ok(): bool
{
    $sent = (string) ($_SERVER['HTTP_X_DP_CSRF'] ?? '');
    return $sent !== '' && !empty($_SESSION['dp_csrf']) && hash_equals((string) $_SESSION['dp_csrf'], $sent);
}

function dp_status_label(string $status): string
{
    return [
        'initiated' => 'Sending the request',
        'pending'   => 'Waiting for the customer to approve',
        'success'   => 'Paid',
        'failed'    => 'Failed',
        'cancelled' => 'Stopped by the cashier',
        'expired'   => 'Expired (no payment received)',
    ][$status] ?? $status;
}

function dp_mask_phone(string $msisdn): string
{
    $d = preg_replace('/\D+/', '', $msisdn);
    return strlen($d) >= 10 ? '0' . substr($d, -9, 2) . '******' . substr($d, -2) : '***';
}

/** The amount must be a whole number of RWF between the limits in config/xentripay.php. */
function dp_check_amount($raw): array
{
    $s = xentripay_settings();
    if (!is_numeric($raw)) {
        return ['ok' => false, 'error' => 'Enter the amount to pay.'];
    }
    $v = (float) $raw;
    if (abs($v - round($v)) > 0.004) {
        return ['ok' => false, 'error' => 'A Digital Payment must be a whole number of RWF (no cents).'];
    }
    $v = (int) round($v);
    if ($v < (int) $s['min_amount']) {
        return ['ok' => false, 'error' => 'The smallest Digital Payment is RWF ' . number_format((int) $s['min_amount']) . '.'];
    }
    if ($v > (int) $s['max_amount']) {
        return ['ok' => false, 'error' => 'The largest Digital Payment is RWF ' . number_format((int) $s['max_amount']) . '. Split it into several payments.'];
    }
    return ['ok' => true, 'amount' => $v];
}

function dp_new_reference(string $purpose): string
{
    return 'STF-' . ($purpose === 'credit' ? 'C' : 'S') . '-' . date('ymdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function dp_row(mysqli $conn, int $id, bool $lock = false): ?array
{
    $st = mysqli_prepare($conn, 'SELECT * FROM digital_payments WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    return $row ?: null;
}

/** What the browser is told about a payment (nothing secret: no gateway reference, no full phone number). */
function dp_public(array $row): array
{
    $applied = !empty($row['applied_at']);
    return [
        'id'              => (int) $row['id'],
        'reference'       => $row['reference'],
        'purpose'         => $row['purpose'],
        'status'          => $row['status'],
        'label'           => dp_status_label($row['status']),
        'amount'          => (float) $row['amount'],
        'phone'           => dp_mask_phone((string) $row['msisdn']),
        'reason'          => $row['failure_reason'],
        'applied'         => $applied,
        'applied_amount'  => $row['applied_amount'] !== null ? (float) $row['applied_amount'] : null,
        'needs_attention' => (int) $row['needs_attention'] === 1,
        'note'            => $row['note'],
        'sale_id'         => $row['sale_id'] !== null ? (int) $row['sale_id'] : null,
        'confirmed'       => $row['status'] === 'success',
    ];
}

// ───────────────────────────── starting a payment ─────────────────────────────

/**
 * Starts a Digital Payment: records the attempt, asks XentriPay to send the approval prompt to the customer's phone.
 *
 * $in: purpose ('sale'|'credit'), amount, phone, sale_id (credit), customer_id (sale, optional)
 * @return array{ok:bool, message?:string, attempt?:array, reused?:bool}
 */
function dp_start(mysqli $conn, array $in, int $userId): array
{
    try {
        if (!xentripay_configured()) {
            return ['ok' => false, 'message' => 'Digital Payment is not set up yet. Please ask the Admin to finish its setup.'];
        }
        $purpose = (string) ($in['purpose'] ?? '');
        if (!in_array($purpose, ['sale', 'credit'], true)) {
            return ['ok' => false, 'message' => 'Unknown payment type.'];
        }
        $amountCheck = dp_check_amount($in['amount'] ?? null);
        if (!$amountCheck['ok']) {
            return ['ok' => false, 'message' => $amountCheck['error']];
        }
        $amount = $amountCheck['amount'];
        $phone = xentripay_phone_check((string) ($in['phone'] ?? ''));
        if (!$phone['ok']) {
            return ['ok' => false, 'message' => $phone['error']];
        }

        $saleId = null;
        $customerId = null;
        $customerName = 'Walk-in Customer';
        $customerEmail = '';
        $detail = (string) xentripay_settings()['details'];

        if ($purpose === 'credit') {
            $saleId = (int) ($in['sale_id'] ?? 0);
            if ($saleId <= 0) {
                return ['ok' => false, 'message' => 'Invalid sale.'];
            }
            require_once __DIR__ . '/credit_charge_helpers.php';
            credit_accrue_now($conn, $saleId); // bring the overdue charges up to date before looking at the balance
            $st = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name, customers.email AS customer_email FROM sales LEFT JOIN customers ON sales.customer_id = customers.id WHERE sales.id = ?');
            mysqli_stmt_bind_param($st, 'i', $saleId);
            mysqli_stmt_execute($st);
            $sale = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            if (!$sale) {
                return ['ok' => false, 'message' => 'Sale not found.'];
            }
            if (!in_array($sale['status'], ['Credit', 'Partially Paid'], true)) {
                return ['ok' => false, 'message' => 'Payments can only be recorded on Credit or Partially Paid sales. This sale is ' . $sale['status'] . '.'];
            }
            $owed = round((float) $sale['total_amount'] - (float) $sale['amount_paid'] + (float) $sale['charges_accrued'] - (float) $sale['charges_paid'], 2);
            if ($amount > $owed + 0.009) {
                return ['ok' => false, 'message' => 'Payment cannot exceed the remaining balance of RWF ' . number_format($owed, 2) . '.'];
            }
            $customerId = $sale['customer_id'] !== null ? (int) $sale['customer_id'] : null;
            $customerName = $sale['customer_name'] ?: $customerName;
            $customerEmail = (string) ($sale['customer_email'] ?? '');
            $detail .= ' - credit payment, invoice RM' . str_pad((string) $saleId, 6, '0', STR_PAD_LEFT);

            // One digital request at a time for the same invoice, so a customer is never asked to pay twice by mistake.
            $other = mysqli_prepare($conn, "SELECT id, amount, msisdn, recorded_by, created_at FROM digital_payments
                WHERE purpose = 'credit' AND sale_id = ? AND status IN ('initiated', 'pending') AND created_at >= (NOW() - INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1");
            mysqli_stmt_bind_param($other, 'i', $saleId);
            mysqli_stmt_execute($other);
            $open = mysqli_fetch_assoc(mysqli_stmt_get_result($other));
            if ($open && !((int) $open['recorded_by'] === $userId && abs((float) $open['amount'] - $amount) < 0.01 && $open['msisdn'] === $phone['msisdn'])) {
                return ['ok' => false, 'message' => 'A Digital Payment request for this invoice is already waiting for the customer (started at ' . date('H:i', strtotime($open['created_at'])) . '). Let it finish, or stop waiting for it first.'];
            }
        } else {
            $customerId = !empty($in['customer_id']) ? (int) $in['customer_id'] : null;
            if ($customerId) {
                $st = mysqli_prepare($conn, 'SELECT name, email FROM customers WHERE id = ?');
                mysqli_stmt_bind_param($st, 'i', $customerId);
                mysqli_stmt_execute($st);
                $c = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
                if ($c) {
                    $customerName = $c['name'] ?: $customerName;
                    $customerEmail = (string) ($c['email'] ?? '');
                } else {
                    $customerId = null;
                }
            }
            $detail .= ' - new sale';
        }

        // The same request again within a moment (a double click, a retry) is the SAME request: nothing new is sent.
        $dup = mysqli_prepare($conn, "SELECT * FROM digital_payments WHERE recorded_by = ? AND purpose = ? AND amount = ? AND msisdn = ?
            AND ((? IS NULL AND sale_id IS NULL) OR sale_id = ?) AND status IN ('initiated', 'pending')
            AND created_at >= (NOW() - INTERVAL " . DP_DUPLICATE_WINDOW_SECONDS . " SECOND) ORDER BY id DESC LIMIT 1");
        mysqli_stmt_bind_param($dup, 'isdsii', $userId, $purpose, $amount, $phone['msisdn'], $saleId, $saleId);
        mysqli_stmt_execute($dup);
        $same = mysqli_fetch_assoc(mysqli_stmt_get_result($dup));
        if ($same) {
            return ['ok' => true, 'reused' => true, 'attempt' => dp_public($same)];
        }

        // Not more than a few new requests a minute from one user.
        $rate = mysqli_prepare($conn, 'SELECT COUNT(*) FROM digital_payments WHERE recorded_by = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)');
        mysqli_stmt_bind_param($rate, 'i', $userId);
        mysqli_stmt_execute($rate);
        $recent = (int) mysqli_fetch_row(mysqli_stmt_get_result($rate))[0];
        if ($recent >= (int) (xentripay_settings()['start_limit'] ?? DP_START_LIMIT_PER_MINUTE)) {
            return ['ok' => false, 'message' => 'Too many requests in a minute. Please wait a moment and try again.'];
        }

        $reference = dp_new_reference($purpose);
        $ins = mysqli_prepare($conn, "INSERT INTO digital_payments (reference, purpose, sale_id, customer_id, amount, msisdn, network, status, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'initiated', ?)");
        mysqli_stmt_bind_param($ins, 'ssiidssi', $reference, $purpose, $saleId, $customerId, $amount, $phone['msisdn'], $phone['network'], $userId);
        mysqli_stmt_execute($ins);
        $id = (int) mysqli_insert_id($conn);

        $email = filter_var($customerEmail, FILTER_VALIDATE_EMAIL) ? $customerEmail : (string) xentripay_settings()['fallback_email'];
        $result = xentripay_initiate([
            'email' => $email, 'cname' => mb_substr($customerName, 0, 60), 'cnumber' => $phone['local'], 'msisdn' => $phone['msisdn'],
            'amount' => $amount, 'customerRef' => $reference, 'details' => mb_substr($detail, 0, 120),
        ]);

        if (!$result['ok']) {
            $reason = mb_substr((string) ($result['message'] ?? 'The request could not be sent.'), 0, 290);
            $up = mysqli_prepare($conn, "UPDATE digital_payments SET status = 'failed', failure_reason = ? WHERE id = ?");
            mysqli_stmt_bind_param($up, 'si', $reason, $id);
            mysqli_stmt_execute($up);
            return ['ok' => false, 'message' => $reason, 'attempt' => dp_public(dp_row($conn, $id))];
        }
        $up = mysqli_prepare($conn, "UPDATE digital_payments SET status = 'pending', gateway_refid = ? WHERE id = ?");
        mysqli_stmt_bind_param($up, 'si', $result['refid'], $id);
        mysqli_stmt_execute($up);
        return ['ok' => true, 'attempt' => dp_public(dp_row($conn, $id))];
    } catch (Throwable $e) {
        error_log('[staff digital payment] start failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The payment request could not be started. Nothing was charged. Please try again.'];
    }
}

// ───────────────────────────── checking with XentriPay ─────────────────────────────

/**
 * Asks XentriPay what happened to a payment, saves the answer and, for a credit payment that has just been confirmed,
 * records it against the invoice. Safe to call as often as you like, from anywhere, at the same moment.
 */
function dp_refresh(mysqli $conn, int $id, bool $force = false): ?array
{
    $row = dp_row($conn, $id);
    if (!$row) {
        return null;
    }
    try {
        $open = in_array($row['status'], ['initiated', 'pending', 'cancelled'], true)
            || ($row['status'] === 'expired' && strtotime($row['created_at']) > time() - 3 * 86400);

        if ($open && !empty($row['gateway_refid'])) {
            $gap = (int) (xentripay_settings()['poll_gap'] ?? DP_POLL_GAP_SECONDS);
            $recently = $gap > 0 && !empty($row['last_checked_at']) && strtotime($row['last_checked_at']) > time() - $gap;
            if ($force || !$recently) {
                $touch = mysqli_prepare($conn, 'UPDATE digital_payments SET last_checked_at = NOW() WHERE id = ?');
                mysqli_stmt_bind_param($touch, 'i', $id);
                mysqli_stmt_execute($touch);

                $answer = xentripay_status((string) $row['gateway_refid']);
                if ($answer['ok'] && $answer['status'] === 'SUCCESS') {
                    $late = in_array($row['status'], ['cancelled', 'expired'], true);
                    $note = $late ? 'The customer approved after the cashier stopped waiting. The money WAS received.' : null;
                    $attention = ($late && $row['purpose'] === 'sale') ? 1 : 0;
                    $up = mysqli_prepare($conn, "UPDATE digital_payments SET status = 'success', confirmed_at = COALESCE(confirmed_at, NOW()), failure_reason = NULL,
                        note = COALESCE(?, note), needs_attention = GREATEST(needs_attention, ?) WHERE id = ? AND status <> 'success'");
                    mysqli_stmt_bind_param($up, 'sii', $note, $attention, $id);
                    mysqli_stmt_execute($up);
                } elseif ($answer['ok'] && $answer['status'] === 'FAILED' && in_array($row['status'], ['initiated', 'pending', 'expired'], true)) {
                    $reason = $answer['reason'] ?? 'The customer did not approve the payment, or it was declined.';
                    $up = mysqli_prepare($conn, "UPDATE digital_payments SET status = 'failed', failure_reason = ? WHERE id = ? AND status IN ('initiated', 'pending', 'expired')");
                    mysqli_stmt_bind_param($up, 'si', $reason, $id);
                    mysqli_stmt_execute($up);
                } elseif ($answer['ok'] && $answer['status'] === 'PENDING') {
                    if ($row['status'] === 'initiated') {
                        $up = mysqli_prepare($conn, "UPDATE digital_payments SET status = 'pending' WHERE id = ? AND status = 'initiated'");
                        mysqli_stmt_bind_param($up, 'i', $id);
                        mysqli_stmt_execute($up);
                    }
                    if (strtotime($row['created_at']) < time() - DP_EXPIRE_HOURS * 3600 && in_array($row['status'], ['initiated', 'pending', 'cancelled'], true)) {
                        $up = mysqli_prepare($conn, "UPDATE digital_payments SET status = 'expired', failure_reason = 'No approval was received in time.' WHERE id = ? AND status IN ('initiated', 'pending', 'cancelled')");
                        mysqli_stmt_bind_param($up, 'i', $id);
                        mysqli_stmt_execute($up);
                    }
                }
            }
        }

        $row = dp_row($conn, $id);
        if ($row && $row['status'] === 'success' && $row['purpose'] === 'credit' && empty($row['applied_at']) && (int) $row['needs_attention'] === 0) {
            dp_apply_credit($conn, $id);
            $row = dp_row($conn, $id);
        }
        return $row;
    } catch (Throwable $e) {
        error_log('[staff digital payment] refresh #' . $id . ' failed: ' . $e->getMessage());
        return dp_row($conn, $id);
    }
}

/** The cashier stops waiting. The payment is still checked later: if the customer does approve, the money is not lost. */
function dp_cancel(mysqli $conn, int $id, int $userId): ?array
{
    $st = mysqli_prepare($conn, "UPDATE digital_payments SET status = 'cancelled', failure_reason = 'The cashier stopped waiting for the customer.'
        WHERE id = ? AND recorded_by = ? AND status IN ('initiated', 'pending')");
    mysqli_stmt_bind_param($st, 'ii', $id, $userId);
    mysqli_stmt_execute($st);
    return dp_row($conn, $id);
}

// ───────────────────────────── credit payments ─────────────────────────────

/**
 * Records a CONFIRMED digital payment against its invoice, once. Called after XentriPay said SUCCESS.
 * The digital_payments row is locked first, so the page that is waiting and the cron job can never both record it.
 */
function dp_apply_credit(mysqli $conn, int $id): array
{
    require_once __DIR__ . '/credit_payment_helpers.php';
    mysqli_begin_transaction($conn);
    try {
        $row = dp_row($conn, $id, true);
        if (!$row || $row['status'] !== 'success' || $row['purpose'] !== 'credit' || !empty($row['applied_at'])) {
            mysqli_commit($conn);
            return ['done' => false];
        }
        $result = credit_record_payment($conn, (int) $row['sale_id'], (float) $row['amount'], (int) $row['recorded_by'], $row, false);
        mysqli_commit($conn);
        [$saleId, $paymentId, $amount, $by] = $result['sms'];
        sms_notify_payment($conn, $saleId, $paymentId, $amount, $by);
        return ['done' => true, 'result' => $result];
    } catch (RuntimeException $e) {
        // A rule of the business stops it (for example the invoice was paid in full by someone else meanwhile).
        // The money WAS received, so the payment is kept and flagged for the Admin instead of being lost.
        mysqli_rollback($conn);
        $note = mb_substr('Payment received but NOT applied to the invoice: ' . $e->getMessage() . ' It must be refunded or applied by hand.', 0, 290);
        $st = mysqli_prepare($conn, 'UPDATE digital_payments SET needs_attention = 1, note = ? WHERE id = ? AND applied_at IS NULL');
        mysqli_stmt_bind_param($st, 'si', $note, $id);
        mysqli_stmt_execute($st);
        return ['done' => false, 'blocked' => $e->getMessage()];
    } catch (Throwable $e) {
        // A system problem: nothing was saved, and the next check will simply try again.
        mysqli_rollback($conn);
        error_log('[staff digital payment] applying #' . $id . ' failed: ' . $e->getMessage());
        return ['done' => false, 'error' => true];
    }
}

// ───────────────────────────── a new sale paid digitally ─────────────────────────────

/** A problem with using this payment for a new sale, or null when it can be used. */
function dp_validate_for_sale(mysqli $conn, int $id, int $userId, float $amountPaid): ?string
{
    $row = $id > 0 ? dp_row($conn, $id) : null;
    $generic = 'Digital Payment must be confirmed by the customer before the sale is recorded. Please send the payment request again.';
    if (!$row || $row['purpose'] !== 'sale') {
        return $generic;
    }
    if ((int) $row['recorded_by'] !== $userId) {
        return 'This digital payment was started by another user.';
    }
    if ($row['status'] !== 'success') {
        return $generic;
    }
    if (!empty($row['applied_at']) || $row['sale_id'] !== null) {
        return 'This digital payment has already been used for another sale.';
    }
    if (strtotime($row['created_at']) < time() - DP_SALE_USE_DAYS * 86400) {
        return 'This digital payment is too old to be used for a new sale. Please contact the Admin.';
    }
    if (abs((float) $row['amount'] - $amountPaid) > 0.009) {
        return 'The Amount Paid (RWF ' . number_format($amountPaid, 2) . ') is not the confirmed digital payment (RWF ' . number_format((float) $row['amount'], 2) . ').';
    }
    return null;
}

/**
 * Attaches a confirmed payment to the sale that was just saved. Runs inside the sale's transaction. The UPDATE can succeed
 * once only, so one payment can never pay for two sales, even if two requests arrive at the same moment.
 */
function dp_consume_for_sale(mysqli $conn, int $id, int $saleId, int $userId, float $amountPaid): void
{
    $st = mysqli_prepare($conn, "UPDATE digital_payments SET sale_id = ?, applied_at = NOW(), applied_amount = amount
        WHERE id = ? AND purpose = 'sale' AND status = 'success' AND sale_id IS NULL AND applied_at IS NULL AND recorded_by = ? AND ABS(amount - ?) < 0.01");
    mysqli_stmt_bind_param($st, 'iiid', $saleId, $id, $userId, $amountPaid);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) !== 1) {
        throw new RuntimeException('This digital payment could not be used for the sale (it was already used, or it does not match).');
    }
}

/** Confirmed payments of this user that were never used for a sale (the browser closed, the save failed...). */
function dp_unrecorded_for_user(mysqli $conn, int $userId): array
{
    $st = mysqli_prepare($conn, "SELECT * FROM digital_payments WHERE recorded_by = ? AND purpose = 'sale' AND status = 'success'
        AND sale_id IS NULL AND applied_at IS NULL AND created_at >= (NOW() - INTERVAL " . DP_SALE_USE_DAYS . " DAY) ORDER BY id DESC LIMIT 5");
    mysqli_stmt_bind_param($st, 'i', $userId);
    mysqli_stmt_execute($st);
    return array_map('dp_public', mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC));
}

// ───────────────────────────── the scheduled check (tools/dp_reconcile.php) and the callback ─────────────────────────────

/** Checks every payment that is still open, so a payment is recorded even when nobody has the page open. */
function dp_reconcile(mysqli $conn): array
{
    $out = ['checked' => 0, 'confirmed' => 0, 'failed' => 0, 'closed' => 0];
    // A request that never reached XentriPay (the page was closed in that very second) is closed after a few minutes.
    mysqli_query($conn, "UPDATE digital_payments SET status = 'failed', failure_reason = 'The request was never sent.'
        WHERE status = 'initiated' AND gateway_refid IS NULL AND created_at < (NOW() - INTERVAL 5 MINUTE)");

    $ids = mysqli_fetch_all(mysqli_query($conn, "SELECT id, status FROM digital_payments
        WHERE (status IN ('initiated', 'pending', 'cancelled') AND gateway_refid IS NOT NULL AND created_at >= (NOW() - INTERVAL " . (DP_EXPIRE_HOURS + 24) . " HOUR))
           OR (status = 'success' AND purpose = 'credit' AND applied_at IS NULL AND needs_attention = 0)
           OR (status = 'expired' AND gateway_refid IS NOT NULL AND created_at >= (NOW() - INTERVAL 72 HOUR))
        ORDER BY id"), MYSQLI_ASSOC);
    foreach ($ids as $r) {
        $before = $r['status'];
        $row = dp_refresh($conn, (int) $r['id'], true);
        $out['checked']++;
        if ($row && $row['status'] === 'success' && $before !== 'success') { $out['confirmed']++; }
        elseif ($row && $row['status'] === 'failed') { $out['failed']++; }
        elseif ($row && $row['status'] === 'expired') { $out['closed']++; }
    }
    return $out;
}

/**
 * XentriPay may call our callback address when a payment changes. The call is only a SIGNAL: nothing in it is trusted.
 * We look the payment up by its reference and ask XentriPay for the real status ourselves.
 */
function dp_handle_callback(mysqli $conn, array $payload): bool
{
    $ref = (string) ($payload['customerRef'] ?? $payload['reference'] ?? ($payload['data']['customerRef'] ?? ''));
    $refid = (string) ($payload['refid'] ?? ($payload['data']['refid'] ?? ''));
    if ($ref === '' && $refid === '') {
        return false;
    }
    $st = mysqli_prepare($conn, 'SELECT id FROM digital_payments WHERE (reference = ? AND ? <> \'\') OR (gateway_refid = ? AND ? <> \'\') LIMIT 1');
    mysqli_stmt_bind_param($st, 'ssss', $ref, $ref, $refid, $refid);
    mysqli_stmt_execute($st);
    $found = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$found) {
        return false;
    }
    dp_refresh($conn, (int) $found['id'], true);
    return true;
}