<?php
/**
 * NEW FILE: modules/timetable/cron.php
 * Sends the Work Timetable reminders that are due. The clock on every page already does this while anyone is
 * signed in; this link makes it reliable at any hour (e.g. before the first employee signs in in the morning).
 *   Online scheduler (cron-job.org), EVERY MINUTE:   https://YOUR-SITE/modules/timetable/cron.php?key=THE_KEY
 *   Server cron:                                    * * * * * php /path/to/modules/timetable/cron.php
 * The key is shown under Work Timetable > Settings. Without the key, only an Administrator can open it.
 * Running it often is safe: a reminder is never sent twice.
 */
$isCli = PHP_SAPI === 'cli';
define('RM_OS_ALLOW_GUEST', true);
if ($isCli) { $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost'; }
require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/timetable_helpers.php';
tt_ensure($conn);

$key = (string) tt_setting_raw($conn, 'cron_key');
$keyOk = !$isCli && strlen($key) >= 20 && isset($_GET['key']) && hash_equals($key, (string) $_GET['key']);
if (!$isCli && !$keyOk) {
    require_once __DIR__ . '/../../includes/auth.php';   // not signed in: goes to the login page
    require_role(['Admin']);
}
@set_time_limit(300);
$sent = tt_tick($conn, true);
if ($isCli || $keyOk) {
    header('Content-Type: text/plain; charset=utf-8');
    echo date('Y-m-d H:i:s') . ' Work Timetable reminders sent: ' . $sent . PHP_EOL;
    exit(0);
}
header('Location: settings.php?success=' . urlencode('Reminders checked now: ' . $sent . ' message(s) sent.'));