<?php
/**
 * NEW FILE: includes/xentripay.php
 *
 * XentriPay (mobile money) for the staff portal. It is the same account and the same two calls that the RISE MOTIVE
 * platform already uses:
 *
 *   POST {base}/api/collections/initiate         starts a collection: the customer gets an approval prompt on the phone
 *   GET  {base}/api/collections/status/{refid}   PENDING | SUCCESS | FAILED
 *
 * Authentication is the header  X-XENTRIPAY-KEY.
 *
 * The settings are in  config/xentripay.php  (that folder is not in git, like config/db.php and config/sms.php).
 * A sample is in  docs/xentripay.config.sample.php .
 *
 * The customer's MoMo PIN is typed on the customer's own phone, in MTN's own approval prompt. This portal never asks
 * for it, never receives it and never stores it.
 */

/** The settings, with safe defaults. The file may be missing: then Digital Payment is simply switched off. */
function xentripay_settings(): array
{
    static $settings = null;
    if ($settings === null) {
        $defaults = [
            'enabled'        => true,
            'base_url'       => '',
            'api_key'        => '',
            'fallback_email' => 'payments@risemotive.rw', // XentriPay needs an email; used when the customer has none
            'min_amount'     => 100,                      // RWF
            'max_amount'     => 5000000,                  // RWF
            'allow_airtel'   => false,                    // the task asks for MTN MoMo only
            'timeout'        => 15,
            'start_limit'    => 6,                        // new requests one user may start per minute
            'poll_gap'       => 2,                        // seconds: XentriPay is asked about the same payment at most this often
            'details'        => 'Staff portal payment',
        ];
        $file = __DIR__ . '/../config/xentripay.php';
        $loaded = is_file($file) ? include $file : [];
        $settings = array_merge($defaults, is_array($loaded) ? $loaded : []);
    }
    return $settings;
}

/** Is Digital Payment ready to use (switched on, with an address and a key)? */
function xentripay_configured(): bool
{
    $s = xentripay_settings();
    return !empty($s['enabled']) && trim((string) $s['base_url']) !== '' && trim((string) $s['api_key']) !== '';
}

/**
 * Checks a Rwandan mobile number written in any usual way: 0788123456, 788123456, 250788123456, +250 788 123 456.
 *
 * @return array{ok:bool, local?:string, msisdn?:string, network?:string, error?:string}
 */
function xentripay_phone_check(string $input): array
{
    $digits = preg_replace('/\D+/', '', $input);
    if (strlen($digits) === 12 && strpos($digits, '250') === 0) {
        $digits = '0' . substr($digits, 3);
    } elseif (strlen($digits) === 9) {
        $digits = '0' . $digits;
    }
    if (!preg_match('/^07[2389][0-9]{7}$/', $digits)) {
        return ['ok' => false, 'error' => 'Enter a valid Rwandan mobile number, for example 0788 123 456.'];
    }
    $network = in_array(substr($digits, 0, 3), ['078', '079'], true) ? 'MTN' : 'Airtel';
    if ($network !== 'MTN' && empty(xentripay_settings()['allow_airtel'])) {
        return ['ok' => false, 'error' => 'Digital Payment works with MTN MoMo numbers only (they start with 078 or 079).'];
    }
    return ['ok' => true, 'local' => $digits, 'msisdn' => '250' . substr($digits, 1), 'network' => $network];
}

/** One call to XentriPay. Never throws. */
function xentripay_call(string $method, string $path, ?array $body = null): array
{
    $s = xentripay_settings();
    $url = rtrim((string) $s['base_url'], '/') . $path;
    $headers = ['X-XENTRIPAY-KEY: ' . $s['api_key'], 'Content-Type: application/json', 'Accept: application/json'];
    $payload = $body === null ? null : json_encode($body);
    $raw = false;
    $http = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int) $s['timeout'], CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $payload ?? '', 'timeout' => (int) $s['timeout'], 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $http = (int) $m[1];
        }
        if ($raw === false) {
            $error = 'no answer';
        }
    }
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['http' => $http, 'json' => is_array($json) ? $json : null, 'raw' => is_string($raw) ? $raw : '', 'error' => $error];
}

/**
 * Starts a mobile-money collection: the customer gets an approval prompt on the phone.
 * $p = email, cname, cnumber (local number), msisdn (250...), amount (whole RWF), customerRef (unique), details.
 *
 * @return array{ok:bool, refid?:string, message?:string, rejected?:bool}
 *   rejected = true: XentriPay answered "no", so nothing was created.
 *   rejected = false (with ok = false): there was no usable answer, so the customer may simply try again.
 */
function xentripay_initiate(array $p): array
{
    $r = xentripay_call('POST', '/api/collections/initiate', [
        'email' => $p['email'], 'cname' => $p['cname'], 'cnumber' => $p['cnumber'], 'msisdn' => $p['msisdn'],
        'amount' => (int) $p['amount'], 'currency' => 'RWF', 'pmethod' => 'momo',
        'customerRef' => $p['customerRef'], 'details' => $p['details'] ?? '', 'chargesIncluded' => true,
    ]);
    if ($r['http'] === 0 || $r['error'] !== '') {
        error_log('[staff xentripay] initiate: no answer (' . $r['error'] . ')');
        return ['ok' => false, 'rejected' => false, 'message' => 'The payment provider could not be reached. Please try again in a moment.'];
    }
    $j = $r['json'];
    if ($r['http'] >= 500) {
        error_log('[staff xentripay] initiate: HTTP ' . $r['http']);
        return ['ok' => false, 'rejected' => false, 'message' => 'The payment provider is busy. Please try again in a moment.'];
    }
    if ($r['http'] >= 400) {
        $msg = is_array($j) && !empty($j['message']) ? (string) $j['message'] : 'The payment provider did not accept the request.';
        return ['ok' => false, 'rejected' => true, 'message' => $msg];
    }
    if (is_array($j) && (int) ($j['success'] ?? 0) === 1 && !empty($j['refid'])) {
        return ['ok' => true, 'refid' => (string) $j['refid']];
    }
    return ['ok' => false, 'rejected' => true, 'message' => is_array($j) && !empty($j['reply']) ? (string) $j['reply'] : 'The payment was not accepted. Please check the number and try again.'];
}

/**
 * Asks XentriPay what happened to a payment. THIS is the only thing that may mark a payment as paid:
 * never the fact that the request was sent, never a message shown to the customer.
 *
 * @return array{ok:bool, status:string, reason?:string}  status = PENDING | SUCCESS | FAILED | UNKNOWN (no usable answer)
 */
function xentripay_status(string $reference): array
{
    $r = xentripay_call('GET', '/api/collections/status/' . rawurlencode($reference));
    if ($r['http'] !== 200 || !is_array($r['json'])) {
        return ['ok' => false, 'status' => 'UNKNOWN'];
    }
    $inner = isset($r['json']['data']) && is_array($r['json']['data']) ? $r['json']['data'] : $r['json'];
    $s = strtoupper(trim((string) ($inner['status'] ?? '')));
    if (!in_array($s, ['PENDING', 'SUCCESS', 'FAILED'], true)) {
        return ['ok' => false, 'status' => 'UNKNOWN'];
    }
    $out = ['ok' => true, 'status' => $s];
    foreach (['reason', 'failureReason', 'statusMessage', 'message'] as $k) {
        if ($s === 'FAILED' && !empty($inner[$k]) && is_string($inner[$k])) {
            $out['reason'] = mb_substr($inner[$k], 0, 250);
            break;
        }
    }
    return $out;
}