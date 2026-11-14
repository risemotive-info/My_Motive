<?php
/**
 * NEW FILE: modules/timetable/action.php
 * The employee's buttons (Check In, Start Break, Return to Work, post movements, Sign Out).
 * Only POST, only for the signed-in employee, only with the page's security token. The time recorded is the
 * SERVER's time at this moment: nothing sent by the browser is used as a time.
 */
require '../../config/db.php';
require_once '../../includes/timetable_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !tt_csrf_ok()) {
    header('Location: my.php?error=' . urlencode('The page had expired. Please try again.'));
    exit;
}
$action = (string) ($_POST['action'] ?? '');
$ref = (int) ($_POST['ref'] ?? 0);
try {
    $r = tt_record_action($conn, (int) current_user_id(), $action, $ref);
} catch (Throwable $e) {
    error_log('timetable action: ' . $e->getMessage());
    $r = ['ok' => false, 'message' => 'Nothing was recorded because of a technical problem. Please try again.' . (current_user_role() === 'Admin' ? ' [' . $e->getMessage() . ']' : '')];
}
if (!empty($r['next_day'])) { $_SESSION['tt_next_day'] = $r['next_day']; }
header('Location: my.php?' . ($r['ok'] ? 'success' : 'error') . '=' . urlencode($r['message']));
exit;