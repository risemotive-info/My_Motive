<?php
/**
 * NEW FILE: modules/timetable/api.php
 * Called every minute by the official clock on every page (includes/rm_clock.js):
 *   - returns the SERVER time (the clock corrects itself; the device clock is never trusted),
 *   - returns the signed-in employee's timetable status (the dashboard refreshes when it changes),
 *   - then sends the reminders that are due (tt_tick: at most once per 50 seconds for the whole system).
 * It does NOT count as activity, so the 30-minute inactivity sign-out still works.
 */
define('RM_SESSION_PING', true);
$_GET['touch'] = '0';
require '../../config/db.php';
require_once '../../includes/timetable_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = ['ok' => true, 'server_ms' => (int) round(microtime(true) * 1000), 'tz' => 'Africa/Kigali'];
try {
    tt_ensure($conn);
    if (!empty($_GET['status'])) {
        $uid = (int) current_user_id();
        $now = time();
        $date = tt_work_date($conn, $uid, $now);
        $ctx = tt_context($conn, $uid, $date);
        $calc = tt_engine_compute($ctx['plan'], $ctx['events'], $ctx['ex'], $now, tt_settings($conn));
        $out['status'] = $calc['status'];
        $out['lost'] = $calc['lost_minutes'];
        $out['fingerprint'] = md5(json_encode([$calc['status'], $ctx['events'], $ctx['ex']['labels'], $calc['lost_minutes']]));
    }
} catch (Throwable $e) {
    error_log('timetable api: ' . $e->getMessage());
}
$json = json_encode($out);
session_write_close();
header('Content-Length: ' . strlen($json));
echo $json;
// Answer first, then do the reminders (they can take a moment when emails are sent).
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { while (ob_get_level() > 0) { ob_end_flush(); } flush(); }
try { @set_time_limit(120); tt_tick($conn); } catch (Throwable $e) { error_log('timetable tick: ' . $e->getMessage()); }