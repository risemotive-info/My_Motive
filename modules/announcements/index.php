<?php
/**
 * NEW FILE: modules/announcements/index.php
 * Announcements from the MY MOTIVE team. Every person of every business can open this page.
 * Opening it marks what they can see as read; "Got it" on the dashboard marks one as read.
 */
require '../../config/db.php';
require_once '../../includes/announcement_helpers.php';
$pageSearchScope = 'announcements';

$companyId = (int) mm_company_id();
$userId = (int) current_user_id();
$isAdmin = current_user_role() === 'Admin';

// "Got it" / "Mark all as read" (a form, with its security token)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = (($_POST['back'] ?? '') === 'dashboard') ? '../dashboard/index.php' : 'index.php';
    if (!announcement_csrf_ok()) {
        header('Location: ' . $back);
        exit;
    }
    try {
        if (($_POST['action'] ?? '') === 'read') {
            announcement_mark_read($companyId, $userId, $isAdmin, (int) ($_POST['id'] ?? 0));
        } elseif (($_POST['action'] ?? '') === 'read_all') {
            announcement_mark_all_read($companyId, $userId, $isAdmin);
        }
    } catch (Throwable $e) {
        error_log('[announcement] mark read failed: ' . $e->getMessage());
    }
    header('Location: ' . $back);
    exit;
}

$ready = true;
$current = [];
$earlier = [];
try {
    $current = announcement_for_user($companyId, $userId, $isAdmin);
    $earlier = announcement_earlier($isAdmin);
    announcement_mark_all_read($companyId, $userId, $isAdmin);   // they are about to read them: the page shows "New" only this one time
} catch (Throwable $e) {
    $ready = false;   // the platform has not installed the announcement tables yet
}
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<div class="mb-3">
    <h2 class="mb-0">Announcements</h2>
    <div class="text-muted small">News and notices from the MY MOTIVE team.</div>
</div>

<?php if (!$ready) { ?>
<div class="alert alert-warning">Announcements are being set up on this server. Please try again later.</div>
<?php } else { ?>
    <?php if (!$current) { ?>
    <div class="alert alert-light border">There are no announcements right now. When the MY MOTIVE team has news for you, it appears here and on your dashboard.</div>
    <?php } ?>
    <?php foreach ($current as $a) { echo announcement_card_html($a, !(int) $a['is_read'], $isAdmin); } ?>

    <?php if ($earlier) { ?>
    <details class="mt-4">
        <summary class="text-muted mb-3" style="cursor:pointer">Earlier announcements (the last <?= (int) ANNOUNCEMENT_HISTORY_DAYS; ?> days)</summary>
        <?php foreach ($earlier as $a) { echo announcement_card_html($a, false, $isAdmin); } ?>
    </details>
    <?php } ?>
<?php } ?>
<?php include '../../includes/footer.php'; ?>
