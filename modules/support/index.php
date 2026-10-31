<?php
/**
 * NEW FILE: modules/support/index.php
 * Support: the tickets of this business. The business Admin sees all of them, everyone else only the ones they raised.
 */
require '../../config/db.php';
require_once '../../includes/support_helpers.php';
$pageSearchScope = 'support';

$company = mm_company();
$userId = (int) current_user_id();
$isAdmin = current_user_role() === 'Admin';
$filter = in_array($_GET['filter'] ?? '', ['active', 'answered', 'resolved', 'closed'], true) ? $_GET['filter'] : 'all';

$ready = true;
try {
    $tickets = support_company_tickets((int) $company['id'], $userId, $isAdmin, $filter);
    $unread = support_company_unread_count((int) $company['id'], $userId, $isAdmin);
} catch (Throwable $e) {
    $tickets = [];
    $unread = 0;
    $ready = false;   // the platform has not installed the ticket tables yet
}
$e = 'support_h';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= $e($_GET['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($_GET['error'])) { ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $e($_GET['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h2 class="mb-0">Support</h2>
        <div class="text-muted small">Something wrong, or a question? Raise a ticket and the MY MOTIVE team will answer here and by email.</div>
    </div>
    <a href="create.php" class="rm-btn rm-btn-primary">+ Raise a ticket</a>
</div>

<?php if (!$ready) { ?>
<div class="alert alert-warning">Support is being set up on this server. Please try again later, or contact MY MOTIVE directly.</div>
<?php } else { ?>
<?php if ($unread > 0) { ?>
<div class="alert alert-info">You have <strong><?= $unread; ?></strong> ticket<?= $unread === 1 ? '' : 's'; ?> with a new answer. <a href="?filter=answered">Show them</a></div>
<?php } ?>

<div class="mb-3 d-flex flex-wrap gap-2">
    <?php foreach (['all' => 'All', 'active' => 'Open', 'answered' => 'New answers', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $key => $label) { ?>
    <a class="rm-btn rm-btn-sm <?= $filter === $key ? 'rm-btn-primary' : 'rm-btn-light'; ?>" href="?filter=<?= $key; ?>"><?= $e($label); ?></a>
    <?php } ?>
</div>

<div class="rm-card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0 align-middle">
    <thead><tr><th>Ticket</th><th>Title</th><th>About</th><th>Urgency</th><th>Status</th><?php if ($isAdmin) { ?><th>Raised by</th><?php } ?><th>Last activity</th></tr></thead>
    <tbody>
    <?php if (!$tickets) { ?>
        <tr><td colspan="<?= $isAdmin ? 7 : 6; ?>" class="text-center text-muted py-4">No tickets here yet. <a href="create.php">Raise your first ticket</a>.</td></tr>
    <?php } ?>
    <?php foreach ($tickets as $t) { ?>
        <tr>
            <td class="text-nowrap"><a href="view.php?id=<?= (int) $t['id']; ?>"><strong><?= $e($t['reference']); ?></strong></a>
                <?php if ((int) $t['company_unread'] === 1) { ?><span class="badge text-bg-danger ms-1">new answer</span><?php } ?></td>
            <td><a href="view.php?id=<?= (int) $t['id']; ?>" class="text-decoration-none"><?= $e($t['subject']); ?></a></td>
            <td><?= $e(SUPPORT_CATEGORIES[$t['category']] ?? $t['category']); ?></td>
            <td><span class="badge text-bg-<?= support_priority_badge($t['priority']); ?>"><?= $e(explode(':', SUPPORT_PRIORITIES[$t['priority']] ?? $t['priority'])[0]); ?></span></td>
            <td><span class="badge text-bg-<?= support_status_badge($t['status']); ?>"><?= $e(support_status_label($t['status'])); ?></span></td>
            <?php if ($isAdmin) { ?><td><?= $e($t['user_name']); ?></td><?php } ?>
            <td class="text-nowrap small text-muted"><?= $e(date('d M Y H:i', strtotime($t['last_activity_at']))); ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
</div></div></div>
<?php } ?>
<?php include '../../includes/footer.php'; ?>
