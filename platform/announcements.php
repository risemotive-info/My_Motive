<?php
/**
 * NEW FILE: platform/announcements.php
 * Public announcements: the list, and publish / unpublish / archive / restore / delete.
 */
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/announcement_helpers.php';

$admin = platform_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = (is_string($_POST['id'] ?? null) && ctype_digit($_POST['id'])) ? (int) $_POST['id'] : 0;
    $r = announcement_action($id, $action);
    platform_flash($r['message'], $r['ok'] ? 'success' : 'danger');
    header('Location: announcements.php');
    exit;
}

$ready = true;
try {
    $rows = announcement_list_admin();
} catch (Throwable $e) {
    $rows = [];
    $ready = false;
}
$now = announcement_now();
$stateBadge = ['draft' => 'secondary', 'scheduled' => 'info', 'live' => 'success', 'ended' => 'secondary', 'archived' => 'dark'];
platform_page_start('Announcements', $admin, 'announcements');
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0">Announcements</h4>
    <a href="announcement.php" class="btn btn-primary btn-sm">+ New announcement</a>
</div>
<p class="text-muted small">An announcement is shown to every person who uses MY MOTIVE (or only to the Admin of each business) on their dashboard and in their Announcements page, from its start time to its end time.</p>
<?php if (!$ready) { ?>
<div class="alert alert-warning">The announcement tables are not installed yet. Run <code>php tools/platform_migrate.php</code> on the server.</div>
<?php } else { ?>
<div class="card p-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
<thead><tr><th>Title</th><th>Type</th><th>Shown to</th><th>State</th><th>From</th><th>Until</th><th>Read by</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $a) { $state = announcement_state($a, $now); ?>
<tr>
    <td><a href="announcement.php?id=<?= (int) $a['id']; ?>" class="text-decoration-none"><strong><?= h($a['title']); ?></strong></a></td>
    <td><span class="badge text-bg-<?= announcement_alert_class($a['level']); ?>"><?= h(ANNOUNCEMENT_LEVELS[$a['level']] ?? $a['level']); ?></span></td>
    <td><small><?= h($a['audience'] === 'admins' ? 'Business Admins' : 'Everyone'); ?></small></td>
    <td><span class="badge text-bg-<?= $stateBadge[$state]; ?>"><?= h(ucfirst($state)); ?></span></td>
    <td class="small text-nowrap"><?= h($a['starts_at'] ? date('d M Y H:i', strtotime($a['starts_at'])) : ($a['published_at'] ? date('d M Y H:i', strtotime($a['published_at'])) : '')); ?></td>
    <td class="small text-nowrap"><?= h($a['ends_at'] ? date('d M Y H:i', strtotime($a['ends_at'])) : 'no end'); ?></td>
    <td class="small"><?= (int) $a['reads_people']; ?> <?= (int) $a['reads_people'] === 1 ? 'person' : 'people'; ?><?= (int) $a['reads_businesses'] > 0 ? '<br><span class="text-muted">in ' . (int) $a['reads_businesses'] . ' business' . ((int) $a['reads_businesses'] === 1 ? '' : 'es') . '</span>' : ''; ?></td>
    <td class="text-end text-nowrap">
        <a class="btn btn-outline-secondary btn-sm" href="announcement.php?id=<?= (int) $a['id']; ?>">Edit</a>
        <?php foreach ([
            ['publish', 'Publish', $a['status'] === 'draft', 'btn-success', ''],
            ['unpublish', 'Unpublish', $a['status'] === 'published', 'btn-outline-warning', 'Unpublish this announcement? Nobody will see it until you publish it again.'],
            ['archive', 'Archive', in_array($a['status'], ['draft', 'published'], true), 'btn-outline-dark', 'Archive this announcement? Nobody will see it any more.'],
            ['restore', 'Restore', $a['status'] === 'archived', 'btn-outline-primary', ''],
            ['delete', 'Delete', in_array($a['status'], ['draft', 'archived'], true), 'btn-outline-danger', 'Delete this announcement for good?'],
        ] as [$act, $label, $show, $class, $confirm]) { if (!$show) { continue; } ?>
        <form method="POST" class="d-inline"<?= $confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ');"' : ''; ?>>
            <?= platform_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $a['id']; ?>"><input type="hidden" name="action" value="<?= h($act); ?>">
            <button class="btn <?= $class; ?> btn-sm" type="submit"><?= h($label); ?></button>
        </form>
        <?php } ?>
    </td>
</tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="8" class="text-muted">No announcements yet. Press "New announcement".</td></tr><?php } ?>
</tbody></table></div></div>
<?php } ?>
<?php platform_page_end();
