<?php
require '../../config/db.php';
require_once '../../includes/sales_helpers.php';   // NEW: also loads fund_helpers.php and profit_rules.php
require_once '../../includes/sms_messages.php';    // NEW: payment SMS (MY MOTIVE SMS, message 4)
require_once '../../includes/credit_payment_helpers.php';   // NEW: the payment is recorded by credit_record_payment(), shared with Digital Payment
require_once '../../includes/digital_payment_helpers.php';  // NEW: Digital Payment (MTN MoMo through XentriPay)
// CHANGED: this page had no role check, unlike every other sales page, so anyone who could
// reach the URL could record payments (which also allocates profit to the RM Funds).
require_role(['Admin', 'Manager', 'Employee']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
// CHANGED: problems are sent as "error" (they were sent as "success"), and all messages are urlencoded.
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid sale requested.')); exit; }

// NEW (overdue charges): bring the invoice's daily charges up to date before showing it.
credit_accrue_now($conn, (int) $id);

$statement = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name, customers.phone AS customer_phone FROM sales LEFT JOIN customers ON sales.customer_id = customers.id WHERE sales.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$sale) { header('Location: index.php?error=' . urlencode('Sale not found.')); exit; }

// CHANGED: what is owed = the remaining invoice amount + unpaid overdue charges.
$principalOut = round((float) $sale['total_amount'] - (float) $sale['amount_paid'], 2);
$chargesOut = round((float) $sale['charges_accrued'] - (float) $sale['charges_paid'], 2);
$balance = round($principalOut + $chargesOut, 2);
// NEW: only Credit and Partially Paid sales can receive a payment
// (never Cancelled, Pending Discount Approval or already Paid).
$canPay = in_array($sale['status'], ['Credit', 'Partially Paid'], true);

// NEW (72-hour rule): shown on the page so the cashier knows what full payment now means for points.
$deadlineTs = !empty($sale['payment_deadline']) ? strtotime($sale['payment_deadline']) : null;
$pointsPending = ($sale['points_status'] ?? '') === 'Pending' ? (int) $sale['points_pending'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $payAmount = $payAmount ? round($payAmount, 2) : $payAmount;

    if (!$canPay) {
        $error = 'Payments can only be recorded on Credit or Partially Paid sales.';
    } elseif (!$payAmount || $payAmount <= 0) {
        $error = 'Enter a valid payment amount.';
    } elseif ($payAmount > $balance) {
        $error = 'Payment cannot exceed the remaining balance of RWF ' . number_format($balance, 2) . '.';
    } else {
        try {
            // CHANGED: the whole recording (lock, overdue charges, ledger, loyalty points, funds, commit and SMS) now lives in
            // credit_record_payment(), so a payment typed in here and a confirmed Digital Payment are recorded by the SAME code.
            $recordedBy = current_user_id();
            $result = credit_record_payment($conn, (int) $id, (float) $payAmount, $recordedBy ? (int) $recordedBy : null);
            header('Location: invoice.php?id=' . $id . '&success=' . urlencode(credit_payment_success_message($result)));
            exit;
        } catch (mysqli_sql_exception $e) {
            mysqli_rollback($conn);
            error_log('record_payment failed for sale #' . $id . ': ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        } catch (RuntimeException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('record_payment failed for sale #' . $id . ': ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Record Payment - Sale #<?= (int) $id; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container" style="max-width:480px; margin-top:60px;">
    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">Record Payment</h5>
            <p class="mb-1"><strong>Customer:</strong> <?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer', ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="mb-1"><strong>Total Amount:</strong> RWF <?= number_format($sale['total_amount'], 2); ?></p>
            <p class="mb-1"><strong>Already Paid:</strong> RWF <?= number_format($sale['amount_paid'], 2); ?></p>
            <?php if ($chargesOut > 0.009) { ?>
            <!-- NEW: overdue charges -->
            <p class="mb-1"><strong>Invoice Balance:</strong> RWF <?= number_format($principalOut, 2); ?></p>
            <p class="mb-1 text-danger"><strong>Overdue Charges (1% per day):</strong> RWF <?= number_format($chargesOut, 2); ?>
                <a href="ledger.php?id=<?= (int) $id; ?>" class="small">details</a></p>
            <?php } ?>
            <p class="mb-3"><strong>Total Balance Due:</strong> RWF <?= number_format($balance, 2); ?></p>
            <?php if ($chargesOut > 0.009) { ?><p class="small text-muted">A payment pays the overdue charges first, then the invoice amount.</p><?php } ?>

            <?php if ($canPay && $deadlineTs) { ?>
                <!-- NEW (72-hour rule) -->
                <div class="alert <?= time() <= $deadlineTs ? 'alert-info' : 'alert-warning'; ?> small">
                    Payment deadline: <strong><?= date('d M Y, H:i', $deadlineTs); ?></strong>.
                    <?php if ($pointsPending > 0) { ?>
                        <?= time() <= $deadlineTs
                            ? 'If the full balance is paid by then, the customer earns <strong>' . $pointsPending . ' Loyalty Points</strong>.'
                            : 'The deadline has passed: the <strong>' . $pointsPending . ' pending Loyalty Points</strong> will not be awarded.'; ?>
                    <?php } ?>
                </div>
            <?php } ?>

            <?php if (!empty($error)) { ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php } ?>

            <?php if (!$canPay && $sale['status'] !== 'Paid') { ?>
                <div class="alert alert-warning">Payments can only be recorded on Credit or Partially Paid sales. This sale is <strong><?= htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8'); ?></strong>.</div>
            <?php } elseif ($balance <= 0 || !$canPay) { ?>
                <div class="alert alert-success">This sale is already fully paid.</div>
            <?php } else { ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Amount Being Paid Now (RWF)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" max="<?= $balance; ?>" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Record Payment</button>
            </form>
            <?php } ?>
            <a href="index.php" class="btn btn-link mt-3 d-block text-center">Back to Sales</a>
        </div>
    </div>
</div>
<?php include '../../includes/digital_payment_ui.php'; ?>
<script>
// NEW: after the amount is entered, ask whether the customer wants to pay using Digital Payment (MTN MoMo).
//   Yes -> the pop-up asks for the customer's MTN MoMo number, waits for the customer to approve, and the payment is
//          recorded automatically once XentriPay confirms it.
//   No  -> the form is sent exactly as before (manual payment).
(function () {
    const form = document.querySelector('form[method="POST"]');
    if (!form || !DigitalPay.enabled) { return; }
    const amountInput = form.querySelector('[name="amount"]');
    form.addEventListener('submit', function (e) {
        if (form.dataset.manual === '1') { return; }
        e.preventDefault();
        const amount = parseFloat(amountInput.value);
        if (!(amount > 0)) { amountInput.reportValidity(); return; }
        DigitalPay.ask({
            purpose: 'credit',
            saleId: <?= (int) $id; ?>,
            amount: amount,
            amountLocked: true,
            maxAmount: <?= json_encode((float) $balance); ?>,
            phone: <?= json_encode((string) ($sale['customer_phone'] ?? '')); ?>,
            customerId: <?= (int) ($sale['customer_id'] ?? 0); ?>,
            onNo: function () { form.dataset.manual = '1'; form.submit(); }
        });
    });
})();
</script>
</body>
</html>