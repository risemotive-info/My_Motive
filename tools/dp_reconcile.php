<?php
/**
 * NEW FILE: tools/dp_reconcile.php
 *
 * Checks every Digital Payment that is still open with XentriPay and records the confirmed ones, so a credit payment is
 * recorded even when nobody has the page open (the customer approves late, the browser was closed...).
 * Run it every minute with cron:
 *
 *     * * * * *  cd /var/www/risemotive-legacy && /usr/bin/php tools/dp_reconcile.php >> /var/log/dp-reconcile.log 2>&1
 *
 * Two copies can never run at once, and running it often is harmless.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }
define('RM_OS_ALLOW_GUEST', true);
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/digital_payment_helpers.php';

if (!xentripay_configured()) { echo date('Y-m-d H:i:s') . " Digital Payment is not set up: nothing to do.\n"; exit(0); }
$got = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT GET_LOCK('rm_dp_reconcile', 0)"))[0];
if ($got !== 1) { echo date('Y-m-d H:i:s') . " Another run is in progress.\n"; exit(0); }

$r = dp_reconcile($conn);
echo date('Y-m-d H:i:s') . ' checked=' . $r['checked'] . ' newly_confirmed=' . $r['confirmed'] . ' failed=' . $r['failed'] . ' closed=' . $r['closed'] . PHP_EOL;
mysqli_query($conn, "SELECT RELEASE_LOCK('rm_dp_reconcile')");