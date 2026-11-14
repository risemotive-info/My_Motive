<?php
/**
 * NEW FILE: modules/timetable/selftest.php — runs the acceptance scenarios of the specification (section 17)
 * against the engine on THIS server, and checks the module's database tables and the reminder schedule.
 * The scenarios use a test timetable in memory: no employee record is created or changed.
 */
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
require_once '../../includes/timetable_scenarios.php';
require_role(['Admin']);
tt_ensure($conn);

$results = tt_run_scenarios();
$passed = count(array_filter($results, function ($r) { return $r['ok']; }));
$checks = [];
foreach (['tt_schedules', 'tt_schedule_breaks', 'tt_events', 'tt_corrections', 'tt_extensions', 'tt_overtime', 'tt_notification_log', 'tt_holidays', 'tt_settings', 'tt_audit'] as $t) {
    $checks[] = ['Table ' . $t, (bool) mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE '$t'"))];
}
$dbNow = strtotime((string) mysqli_fetch_row(mysqli_query($conn, 'SELECT NOW()'))[0]);
$checks[] = ['PHP time zone is Africa/Kigali', date_default_timezone_get() === 'Africa/Kigali'];
$checks[] = ['Database clock matches the PHP clock (± 60 s)', abs($dbNow - time()) <= 60];
$checks[] = ['Leave types for exceptions exist', (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM rm_categories WHERE group_key = 'leave_type' AND name IN ('" . implode("','", array_keys(TT_TIMED_LEAVE)) . "')"))[0] === count(TT_TIMED_LEAVE)];
$last = (int) tt_setting_raw($conn, 'last_tick');
$checks[] = ['Reminders checked in the last 10 minutes', $last > time() - 600];
$checks[] = ['Cron key generated (reminders at any hour)', strlen((string) tt_setting_raw($conn, 'cron_key')) >= 20];

include '../../includes/header.php';
include '../../includes/sidebar.php';
tt_tabs('settings.php');
?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <h5>Acceptance scenarios: <?= $passed; ?> / <?= count($results); ?> passed</h5>
    <div class="small text-muted mb-2">Run on this server at <?= date('d M Y H:i:s'); ?>. Example timetable from the specification: reports 7:00, post 8:00–10:30, lunch 12:00–2:00 PM, departure 5:00 PM.</div>
    <table class="table table-sm small mb-0">
        <?php foreach ($results as $r) { ?><tr><td style="width:40px;"><?= $r['n']; ?></td><td><?= tt_e($r['name']); ?><?php foreach ($r['errors'] as $e) { ?><div class="text-danger"><?= tt_e($e); ?></div><?php } ?></td>
            <td class="text-end"><?= $r['ok'] ? '<span class="badge bg-success">PASS</span>' : '<span class="badge bg-danger">FAIL</span>'; ?></td></tr><?php } ?>
    </table>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-body">
    <h6>Installation checks</h6>
    <table class="table table-sm small mb-0">
        <?php foreach ($checks as [$l, $ok]) { ?><tr><td><?= tt_e($l); ?></td><td class="text-end"><?= $ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Check</span>'; ?></td></tr><?php } ?>
    </table>
    <div class="small text-muted mt-2">The scenarios check the rules. Also do the live test with a real test employee: set a timetable a few minutes ahead, then check in, start and end a break, and try to sign out early. Confirm the reminders arrive in the bell, by email and by SMS.</div>
</div></div>
<?php include '../../includes/footer.php'; ?>