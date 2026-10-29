<?php
/**
 * NEW FILE: modules/sales/digital_payment_callback.php
 *
 * OPTIONAL. The address XentriPay can call when a payment changes. Nothing in the call is trusted: it is only a SIGNAL.
 * The payment is looked up by its own reference and XentriPay is asked for the real status, so a forged call can never mark
 * a payment as paid. (Without this address the system still works: the pop-up and tools/dp_reconcile.php check the status.)
 *
 * If config/xentripay.php has a 'callback_secret', the address must be called as  digital_payment_callback.php?key=THE_SECRET
 */
define('RM_OS_ALLOW_GUEST', true);      // XentriPay has no login
require '../../config/db.php';
require_once '../../includes/digital_payment_helpers.php';

header('Content-Type: application/json; charset=utf-8');
$secret = (string) (xentripay_settings()['callback_secret'] ?? '');
if ($secret !== '' && !hash_equals($secret, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['received' => false]);
    exit;
}
$body = json_decode((string) file_get_contents('php://input'), true);
$payload = is_array($body) ? $body : $_REQUEST;
try {
    dp_handle_callback($conn, $payload);
} catch (Throwable $e) {
    error_log('[staff digital payment] callback failed: ' . $e->getMessage());
}
echo json_encode(['received' => true]);   // the same answer whether or not the payment was found