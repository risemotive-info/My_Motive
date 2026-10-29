<?php
/**
 * NEW FILE: modules/sales/digital_payment.php
 *
 * The small JSON service behind the Digital Payment pop-up (new sale and credit payment).
 *
 *   POST action=start   purpose=sale|credit  amount  phone  [sale_id] [customer_id]   starts a request to the customer's phone
 *   GET  action=status  id=...                                                         asks XentriPay and tells what happened
 *   POST action=cancel  id=...                                                         the cashier stops waiting
 *
 * Only a logged-in Admin / Manager / Employee can use it. Every request that changes something must carry the CSRF token
 * of the session. A user can only follow the payments they started (an Admin or Manager can follow any).
 */
require '../../config/db.php';
require_once '../../includes/digital_payment_helpers.php';
require_role(['Admin', 'Manager', 'Employee']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dp_reply(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) current_user_id();
$role = current_user_role();
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

// Roles & Permissions: taking and following a Digital Payment needs the same permission as recording a sale (Sales: Create).
// It is checked HERE too, so this service never depends on where the rest of the portal starts its page check.
if (is_file(__DIR__ . '/../../includes/access_control.php')) {
    require_once __DIR__ . '/../../includes/access_control.php';
    if (!access_can('sales.create', $conn)) {
        dp_reply(['ok' => false, 'message' => 'You do not have permission to take payments.'], 403);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !dp_csrf_ok()) {
    dp_reply(['ok' => false, 'message' => 'Your session expired. Please reload the page and try again.'], 403);
}

/** The payment, if this user may see it. */
function dp_owned(mysqli $conn, int $id, int $userId, ?string $role): ?array
{
    $row = $id > 0 ? dp_row($conn, $id) : null;
    if (!$row) {
        return null;
    }
    return ((int) $row['recorded_by'] === $userId || in_array($role, ['Admin', 'Manager'], true)) ? $row : null;
}

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = dp_start($conn, [
        'purpose'     => $_POST['purpose'] ?? '',
        'amount'      => $_POST['amount'] ?? '',
        'phone'       => $_POST['phone'] ?? '',
        'sale_id'     => $_POST['sale_id'] ?? 0,
        'customer_id' => $_POST['customer_id'] ?? 0,
    ], $userId);
    dp_reply($result, $result['ok'] ? 200 : 422);
}

if ($action === 'status') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!dp_owned($conn, $id, $userId, $role)) {
        dp_reply(['ok' => false, 'message' => 'Payment not found.'], 404);
    }
    $row = dp_refresh($conn, $id); // asks XentriPay (at most every couple of seconds) and, for a credit payment, records it once confirmed
    $out = ['ok' => true, 'attempt' => dp_public($row)];
    if ($row['purpose'] === 'credit' && !empty($row['applied_at']) && $row['sale_id']) {
        $st = mysqli_prepare($conn, 'SELECT total_amount, amount_paid, charges_accrued, charges_paid, status FROM sales WHERE id = ?');
        $sid = (int) $row['sale_id'];
        mysqli_stmt_bind_param($st, 'i', $sid);
        mysqli_stmt_execute($st);
        $s = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        $left = $s ? round((float) $s['total_amount'] - (float) $s['amount_paid'] + (float) $s['charges_accrued'] - (float) $s['charges_paid'], 2) : 0;
        $msg = 'Digital Payment of RWF ' . number_format((float) $row['applied_amount'], 2) . ' received and recorded. '
            . ($left > 0.009 ? 'Remaining balance: RWF ' . number_format($left, 2) . '.' : 'The invoice is now fully paid.');
        $out['message'] = $msg;
        $out['redirect'] = 'invoice.php?id=' . $sid . '&success=' . urlencode($msg);
    }
    dp_reply($out);
}

if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (!dp_owned($conn, $id, $userId, $role)) {
        dp_reply(['ok' => false, 'message' => 'Payment not found.'], 404);
    }
    $row = dp_cancel($conn, $id, $userId);
    dp_reply(['ok' => true, 'attempt' => dp_public($row)]);
}

dp_reply(['ok' => false, 'message' => 'Unknown request.'], 400);