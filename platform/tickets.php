<?php
/**
 * NEW FILE: platform/tickets.php
 * Support tickets raised by the businesses. The newest and the unanswered ones first.
 */
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/support_helpers.php';

$admin = platform_require_admin();
$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'active';
if (!in_array($status, array_merge(['all', 'active'], SUPPORT_STATUSES), true)) {
    $status = 'active';
}
$priority = is_string($_GET['priority'] ?? null) && isset(SUPPORT_PRIORITIES[$_GET['priority']]) ? $_GET['priority'] : '';
$q = is_string($_GET['q'] ?? null) ? support_clean($_GET['q'], 80) : '';
$unread = !empty($_GET['unread']);

$ready = true;
try {
    $rows = support_admin_tickets(['status' => $status, 'priority' => $priority, 'q' => $q, 'unread' => $unread]);
    $counts = support_admin_counts();
} catch (Throwable $e) {
    $rows = [];
    $counts = [];
    $ready = false;
}
platform_page_start('Support tickets', $admin, 'tickets');
?>
<h4 class="mb-3">Support tickets</h4>
<?php if (!$ready) { ?>
<div class="alert alert-warning">The ticket tables are not installed yet. Run <code>php tools/platform_migrate.php</code> on the server.</div>
<?php } else { ?>
<div class="row g-3 mb-3">
    <?php foreach ([['Open', $counts['active'], '?status=active', ''], ['Not read yet', $counts['unread'], '?unread=1&status=all', $counts['unread'] > 0 ? 'text-danger' : ''], ['Urgent', $counts['urgent'], '?priority=urgent&status=active', $counts['urgent'] > 0 ? 'text-danger' : ''], ['Resolved', $counts['resolved'], '?status=resolved', ''], ['Closed', $counts['closed'], '?status=closed', '']] as $c) { ?>
    <div class="col-6 col-md"><a class="text-decoration-none text-reset" href="<?= h($c[2]); ?>"><div class="card p-3"><div class="text-muted small"><?= h($c[0]); ?></div><div class="fs-4 fw-bold <?= $c[3]; ?>"><?= (int) $c[1]; ?></div></div></a></div>
    <?php } ?>
</div>

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-3"><label class="form-label small text-muted">Status</label>
        <select name="status" class="form-select form-select-sm">
            <option value="active" <?= $status === 'active' ? 'selected' : ''; ?>>Open (not resolved or closed)</option>
            <option value="all" <?= $status === 'all' ? 'selected' : ''; ?>>All</option>
            <?php foreach (SUPPORT_STATUSES as $s) { ?><option value="<?= h($s); ?>" <?= $status === $s ? 'selected' : ''; ?>><?= h(support_status_label($s, false)); ?></option><?php } ?>
        </select></div>
    <div class="col-md-2"><label class="form-label small text-muted">Urgency</label>
        <select name="priority" class="form-select form-select-sm"><option value="">Any</option>
            <?php foreach (SUPPORT_PRIORITIES as $k => $label) { ?><option value="<?= h($k); ?>" <?= $priority === $k ? 'selected' : ''; ?>><?= h(explode(':', $label)[0]); ?></option><?php } ?>
        </select></div>
    <div class="col-md-4"><label class="form-label small text-muted">Search</label><input name="q" class="form-control form-control-sm" value="<?= h($q); ?>" placeholder="Ticket number, business, title or person"></div>
    <div class="col-md-2 form-check ms-2"><input class="form-check-input" type="checkbox" name="unread" value="1" id="unread" <?= $unread ? 'checked' : ''; ?>><label class="form-check-label small" for="unread">Not read yet</label></div>
    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
</form>

<div class="card p-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
<thead><tr><th>Ticket</th><th>Business</th><th>Title</th><th>About</th><th>Urgency</th><th>Status</th><th>From</th><th>Last activity</th></tr></thead><tbody>
<?php foreach ($rows as $t) { ?>
<tr class="<?= (int) $t['platform_unread'] === 1 && $t['status'] !== 'closed' ? 'table-warning' : ''; ?>">
    <td class="text-nowrap"><a href="ticket.php?id=<?= (int) $t['id']; ?>"><strong><?= h($t['reference']); ?></strong></a><?= (int) $t['platform_unread'] === 1 && $t['status'] !== 'closed' ? ' <span class="badge text-bg-danger">new</span>' : ''; ?></td>
    <td><?= h($t['company_name']); ?><br><small class="text-muted"><?= h($t['company_code']); ?></small></td>
    <td><a href="ticket.php?id=<?= (int) $t['id']; ?>" class="text-decoration-none"><?= h($t['subject']); ?></a></td>
    <td><small><?= h(SUPPORT_CATEGORIES[$t['category']] ?? $t['category']); ?></small></td>
    <td><span class="badge text-bg-<?= support_priority_badge($t['priority']); ?>"><?= h(explode(':', SUPPORT_PRIORITIES[$t['priority']] ?? $t['priority'])[0]); ?></span></td>
    <td><span class="badge text-bg-<?= support_status_badge($t['status']); ?>"><?= h(support_status_label($t['status'], false)); ?></span></td>
    <td><?= h($t['user_name']); ?></td>
    <td class="text-nowrap small text-muted"><?= h(date('d M Y H:i', strtotime($t['last_activity_at']))); ?></td>
</tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="8" class="text-muted">No tickets match.</td></tr><?php } ?>
</tbody></table></div></div>
<?php } ?>
<?php platform_page_end();
