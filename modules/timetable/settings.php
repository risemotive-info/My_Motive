<?php
/**
 * NEW FILE: modules/timetable/settings.php — Administrators: grace periods, reminder times, notification channels
 * per type (in-app / email / SMS; SMS meant for high-risk messages only), public holidays, the cron link,
 * the notification delivery history and the self-test. Every change is in the audit trail.
 */
require '../../config/db.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; } // an Admin sees the exact reason instead of "HTTP ERROR 500"
require_once '../../includes/timetable_helpers.php';
require_role(['Admin']);
tt_ensure($conn);
$me = (int) current_user_id();

const TT_SETTING_LABELS = [
    'grace_arrival' => ['Grace period for arrival (min)', 'An arrival up to this many minutes after the reporting time is not late. Each timetable can override it.'],
    'grace_return' => ['Grace period for returns (min)', 'For returns from breaks and assigned posts.'],
    'checkin_opens' => ['Check-in opens before reporting time (min)', ''],
    'break_early_allow' => ['A break may start early by (min)', 'Outside this, starting a break is refused with a warning.'],
    'lead_report' => ['Reminder before reporting time (min)', ''],
    'lead_break' => ['Reminder before a break starts (min)', 'Specification: 10.'],
    'lead_break_end' => ['Reminder before a break ends (min)', 'Specification: 15.'],
    'lead_post' => ['Reminder before leaving for the post (min)', ''],
    'lead_post_return' => ['Reminder before the post return time (min)', ''],
    'lead_departure' => ['Reminder before departure (min)', ''],
    'reminder_window' => ['Do not send reminders older than (min)', 'If the server was off, old reminders are not sent late.'],
    'overtime_min' => ['Smallest overtime proposed for approval (min)', ''],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tt_csrf_ok()) { header('Location: settings.php?error=' . urlencode('The page had expired. Please try again.')); exit; }
    $do = $_POST['do'] ?? '';
    if ($do === 'numbers') {
        $changes = [];
        $old = tt_settings($conn);
        foreach (TT_SETTING_LABELS as $k => $l) {
            $v = max(0, min(600, (int) ($_POST[$k] ?? TT_DEFAULTS[$k])));
            if ((int) $old[$k] !== $v) { $changes[] = $l[0] . ': ' . $old[$k] . ' → ' . $v; }
            tt_setting_set($conn, $k, (string) $v);
        }
        $ch = [];
        foreach (TT_NOTIFY_KINDS as $kind => $x) {
            $ch[$kind] = [isset($_POST['ch'][$kind]['app']) ? 1 : 0, isset($_POST['ch'][$kind]['email']) ? 1 : 0, isset($_POST['ch'][$kind]['sms']) ? 1 : 0];
            if ($ch[$kind] !== $old['channels'][$kind]) { $changes[] = $x[0] . ' channels: ' . implode('/', $ch[$kind]); }
        }
        tt_setting_set($conn, 'channels', json_encode($ch));
        if ($changes) { tt_audit($conn, $me, null, 'settings', null, 'changed', implode('; ', $changes)); }
        header('Location: settings.php?success=' . urlencode('Settings saved.')); exit;
    }
    if ($do === 'holiday_add') {
        $d = (string) ($_POST['date'] ?? ''); $n = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        if (!tt_valid_date($d) || $n === '') { header('Location: settings.php?error=' . urlencode('Enter a date and a name.')); exit; }
        if ($d < date('Y-m-d')) { header('Location: settings.php?error=' . urlencode('Holidays can be added for today or later (past days keep their records).')); exit; }
        $s = mysqli_prepare($conn, 'INSERT INTO tt_holidays (holiday_date, name, created_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        mysqli_stmt_bind_param($s, 'ssi', $d, $n, $me);
        mysqli_stmt_execute($s);
        tt_audit($conn, $me, null, 'holiday', null, 'added', $d . ' ' . $n);
        header('Location: settings.php?success=' . urlencode('Holiday saved.')); exit;
    }
    if ($do === 'holiday_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $h = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM tt_holidays WHERE id = ' . $id));
        if ($h && $h['holiday_date'] > date('Y-m-d')) {
            mysqli_query($conn, 'DELETE FROM tt_holidays WHERE id = ' . $id);
            tt_audit($conn, $me, null, 'holiday', $id, 'removed', $h['holiday_date'] . ' ' . $h['name']);
            header('Location: settings.php?success=' . urlencode('Holiday removed.')); exit;
        }
        header('Location: settings.php?error=' . urlencode('Past holidays stay (their days are already recorded).')); exit;
    }
    if ($do === 'cron_key') {
        tt_setting_set($conn, 'cron_key', bin2hex(random_bytes(20)));
        tt_audit($conn, $me, null, 'settings', null, 'cron_key', 'A new cron key was generated (the old link stops working).');
        header('Location: settings.php?success=' . urlencode('New key generated. Update the link in your online scheduler.')); exit;
    }
}

$set = tt_settings($conn, true);
$holidays = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM tt_holidays WHERE holiday_date >= '" . date('Y-01-01') . "' ORDER BY holiday_date"), MYSQLI_ASSOC);
$key = (string) tt_setting_raw($conn, 'cron_key');
$lastTick = (int) tt_setting_raw($conn, 'last_tick');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$cronUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-site') . rtrim((string) BASE_URL, '/') . '/modules/timetable/cron.php?key=' . $key;
$log = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.*, s.names AS subject, r.names AS recipient FROM tt_notification_log l LEFT JOIN users s ON s.id = l.subject_user_id LEFT JOIN users r ON r.id = l.recipient_id ORDER BY l.id DESC LIMIT 100'), MYSQLI_ASSOC);
$audit = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names AS actor FROM tt_audit a LEFT JOIN users u ON u.id = a.actor_id WHERE a.entity IN ('settings', 'holiday') ORDER BY a.id DESC LIMIT 20"), MYSQLI_ASSOC);
$stats = mysqli_fetch_all(mysqli_query($conn, "SELECT channel, status, COUNT(*) n FROM tt_notification_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY channel, status"), MYSQLI_ASSOC);

include '../../includes/header.php';
include '../../includes/sidebar.php';
tt_tabs('settings.php');
tt_flash();
?>
<div class="row g-3">
<div class="col-xl-7">
<form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body"><?= tt_csrf_field(); ?><input type="hidden" name="do" value="numbers">
    <h6>Rules and reminder times</h6>
    <div class="row g-2 mb-3">
        <?php foreach (TT_SETTING_LABELS as $k => [$l, $help]) { ?>
            <div class="col-md-6"><label class="form-label small mb-0"><?= tt_e($l); ?></label><input type="number" min="0" max="600" name="<?= $k; ?>" value="<?= (int) $set[$k]; ?>" class="form-control form-control-sm"><?php if ($help) { ?><div class="form-text"><?= tt_e($help); ?></div><?php } ?></div>
        <?php } ?>
    </div>
    <h6>Notification channels</h6>
    <div class="small text-muted mb-2">SMS costs money and interrupts: keep it for HIGH-RISK messages (overdue returns, employees who have not reported). Email uses the existing mail settings, SMS the existing Pindo account.</div>
    <table class="table table-sm small align-middle"><tr><th>Notification</th><th>To</th><th class="text-center">MY MOTIVE</th><th class="text-center">Email</th><th class="text-center">SMS</th></tr>
        <?php foreach (TT_NOTIFY_KINDS as $kind => [$label, $who]) { [$a, $e, $s] = $set['channels'][$kind]; ?>
            <tr><td><?= tt_e($label); ?></td><td><?= tt_e($who); ?></td>
                <td class="text-center"><input type="checkbox" name="ch[<?= $kind; ?>][app]" <?= $a ? 'checked' : ''; ?>></td>
                <td class="text-center"><input type="checkbox" name="ch[<?= $kind; ?>][email]" <?= $e ? 'checked' : ''; ?>></td>
                <td class="text-center"><input type="checkbox" name="ch[<?= $kind; ?>][sms]" <?= $s ? 'checked' : ''; ?>></td></tr>
        <?php } ?>
    </table>
    <button class="btn btn-primary">Save settings</button>
</div></form>
<div class="card border-0 shadow-sm"><div class="card-body">
    <h6>Notification delivery history (last 100)</h6>
    <div class="small mb-2"><?php foreach ($stats as $s) { ?><span class="badge bg-light text-dark border me-1"><?= tt_e(strtoupper($s['channel']) . ' ' . $s['status'] . ': ' . $s['n']); ?></span><?php } ?> <span class="text-muted">last 7 days</span></div>
    <div class="table-responsive" style="max-height:420px;"><table class="table table-sm small mb-0">
        <tr><th>When</th><th>About</th><th>To</th><th>Message</th><th>Channel</th><th>Status</th></tr>
        <?php foreach ($log as $l) { ?><tr><td><?= date('d M H:i', strtotime($l['created_at'])); ?></td><td><?= tt_e($l['subject']); ?></td><td><?= tt_e($l['recipient']); ?></td><td><?= tt_e($l['title']); ?></td><td><?= tt_e(strtoupper($l['channel'])); ?></td><td><?= tt_e($l['status']); ?><?= $l['detail'] ? '<div class="text-muted">' . tt_e($l['detail']) . '</div>' : ''; ?></td></tr><?php } ?>
        <?php if (!$log) { ?><tr><td colspan="6" class="text-muted">Nothing sent yet.</td></tr><?php } ?>
    </table></div>
</div></div>
</div>
<div class="col-xl-5">
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <h6>Automatic reminders</h6>
    <div class="small mb-2">Last check: <strong><?= $lastTick ? date('d M Y H:i:s', $lastTick) . ' (' . tt_dur((int) floor((time() - $lastTick) / 60)) . ' ago)' : 'never'; ?></strong>.
        The clock on every page checks every minute while someone is signed in. For reminders at any hour (e.g. before anyone signs in), schedule this link <strong>every minute</strong> on cron-job.org (or a server cron):</div>
    <?php if ($key) { ?><input class="form-control form-control-sm mb-2" readonly value="<?= tt_e($cronUrl); ?>" onclick="this.select()"><?php } else { ?><div class="small text-danger mb-2">No key yet: generate one.</div><?php } ?>
    <div class="d-flex gap-2">
        <form method="POST"><?= tt_csrf_field(); ?><input type="hidden" name="do" value="cron_key"><button class="btn btn-sm btn-outline-secondary" onclick="return <?= $key ? "confirm('The current link will stop working. Continue?')" : 'true'; ?>;"><?= $key ? 'New key' : 'Generate key'; ?></button></form>
        <a href="cron.php" class="btn btn-sm btn-outline-primary">Check reminders now</a>
        <a href="selftest.php" class="btn btn-sm btn-outline-success"><i class="bi bi-clipboard-check"></i> Self-test (acceptance scenarios)</a>
    </div>
</div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <h6>Public holidays (<?= date('Y'); ?> onwards)</h6>
    <div class="small text-muted mb-2">Days off for everyone: no reminders, no lateness, not absent, skipped by "next working day". Fixed-date Rwandan holidays were added; add movable ones (Good Friday, Easter Monday, Eid al-Fitr, Eid al-Adha) when announced.</div>
    <form method="POST" class="d-flex gap-2 mb-2"><?= tt_csrf_field(); ?><input type="hidden" name="do" value="holiday_add">
        <input type="date" name="date" class="form-control form-control-sm" min="<?= date('Y-m-d'); ?>" required><input name="name" class="form-control form-control-sm" placeholder="Name" required maxlength="120"><button class="btn btn-sm btn-primary">Add</button></form>
    <div style="max-height:300px; overflow:auto;"><table class="table table-sm small mb-0">
        <?php foreach ($holidays as $h) { ?><tr><td><?= date('D d M Y', strtotime($h['holiday_date'])); ?></td><td><?= tt_e($h['name']); ?></td>
            <td class="text-end"><?php if ($h['holiday_date'] > date('Y-m-d')) { ?><form method="POST" class="m-0"><?= tt_csrf_field(); ?><input type="hidden" name="do" value="holiday_delete"><input type="hidden" name="id" value="<?= (int) $h['id']; ?>"><button class="btn btn-sm btn-link text-danger p-0" onclick="return confirm('Remove this holiday?');">Remove</button></form><?php } ?></td></tr><?php } ?>
    </table></div>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-body">
    <h6>Settings audit trail</h6>
    <?php foreach ($audit as $a) { ?><div class="small border-top py-1"><span class="text-muted"><?= date('d M Y H:i', strtotime($a['created_at'])); ?> · <?= tt_e($a['actor'] ?: 'System'); ?></span><br><?= tt_e($a['details']); ?></div><?php } ?>
    <?php if (!$audit) { ?><div class="small text-muted">No changes yet.</div><?php } ?>
</div></div>
</div>
</div>
<?php include '../../includes/footer.php'; ?>