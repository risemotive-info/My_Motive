<?php
/**
 * NEW FILE: platform/ticket.php
 * One support ticket: read the conversation, answer the business (or leave a note only admins see), change the status.
 */
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/support_helpers.php';

$admin = platform_require_admin();
$id = (is_string($_GET['id'] ?? null) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
try {
    $ticket = $id > 0 ? support_admin_ticket($id) : null;
} catch (Throwable $e) {
    $ticket = null;
}
if (!$ticket) {
    http_response_code(404);
    platform_page_start('Not found', $admin, 'tickets');
    echo '<div class="alert alert-warning">That ticket does not exist. <a href="tickets.php">Back to the tickets</a></div>';
    platform_page_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'reply') {
        $r = support_admin_reply($ticket, $admin, is_string($_POST['body'] ?? null) ? $_POST['body'] : '', !empty($_POST['internal']), is_string($_POST['status'] ?? null) ? $_POST['status'] : (string) $ticket['status']);
        $r['ok'] ? platform_flash(!empty($_POST['internal']) ? 'Note saved. Only admins can see it.' : 'Answer sent. The business was emailed.') : platform_flash($r['error'], 'danger');
    } elseif ($action === 'update') {
        $assign = is_string($_POST['assign'] ?? null) && in_array($_POST['assign'], ['me', 'none'], true) ? $_POST['assign'] : null;
        support_admin_update($ticket, $admin, is_string($_POST['priority'] ?? null) ? $_POST['priority'] : null, $assign) ? platform_flash('Saved.') : platform_flash('Could not save.', 'danger');
    }
    header('Location: ticket.php?id=' . $id);
    exit;
}

support_admin_mark_read($id);   // opening it marks it as read
$messages = support_messages($id, false);
$assignedName = '';
if ($ticket['assigned_admin_id']) {
    $a = platform_admin_by_id((int) $ticket['assigned_admin_id']);
    $assignedName = $a ? (string) $a['name'] : '';
}
platform_page_start($ticket['reference'], $admin, 'tickets');
?>
<p><a href="tickets.php">&larr; All tickets</a></p>
<div class="row g-3">
<div class="col-lg-8">
    <div class="mb-3">
        <div class="text-muted small"><?= h($ticket['reference']); ?> · <?= h(SUPPORT_CATEGORIES[$ticket['category']] ?? $ticket['category']); ?></div>
        <h4 class="mb-1"><?= h($ticket['subject']); ?></h4>
        <span class="badge text-bg-<?= support_status_badge($ticket['status']); ?>"><?= h(support_status_label($ticket['status'], false)); ?></span>
        <span class="badge text-bg-<?= support_priority_badge($ticket['priority']); ?>"><?= h(explode(':', SUPPORT_PRIORITIES[$ticket['priority']] ?? '')[0]); ?></span>
    </div>

    <?php foreach ($messages as $m) {
        $fromBusiness = $m['author_type'] === 'company';
        $internal = (int) $m['is_internal'] === 1; ?>
    <div class="card p-3 mb-2" style="<?= $internal ? 'background:#FFF8E1;border-left:4px solid #F2B600;' : ($fromBusiness ? '' : 'border-left:4px solid #4C6FFF;background:#F7F9FF;'); ?>">
        <div class="d-flex justify-content-between flex-wrap gap-1 mb-1">
            <strong><?= h($m['author_name']); ?> <small class="text-muted fw-normal">(<?= $fromBusiness ? h($ticket['company_name']) : 'MY MOTIVE support'; ?>)</small><?= $internal ? ' <span class="badge text-bg-warning">internal note: the business cannot see this</span>' : ''; ?></strong>
            <small class="text-muted"><?= h(date('d M Y H:i', strtotime($m['created_at']))); ?></small>
        </div>
        <div style="white-space:pre-wrap;word-break:break-word;"><?= h($m['body']); ?></div>
    </div>
    <?php } ?>

    <div class="card p-3 mt-3">
        <h6>Answer</h6>
        <form method="POST">
            <?= platform_csrf_field(); ?>
            <input type="hidden" name="action" value="reply">
            <textarea class="form-control mb-2" name="body" rows="5" maxlength="5000" required></textarea>
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <label class="form-label small text-muted mb-0">After this answer the ticket is</label>
                    <select name="status" class="form-select form-select-sm">
                        <?php foreach (['in_progress' => 'In progress', 'waiting_customer' => 'Waiting for the business', 'resolved' => 'Resolved', 'closed' => 'Closed', 'open' => 'Open'] as $k => $label) { ?>
                        <option value="<?= h($k); ?>" <?= $k === ($ticket['status'] === 'open' ? 'in_progress' : $ticket['status']) ? 'selected' : ''; ?>><?= h($label); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="internal" value="1" id="internal"><label class="form-check-label small" for="internal">Internal note (the business will not see it)</label></div></div>
                <div class="col-md-3 text-end mt-3"><button class="btn btn-primary btn-sm" type="submit">Send</button></div>
            </div>
        </form>
    </div>
</div>

<div class="col-lg-4">
    <div class="card p-3 mb-3">
        <h6>Business</h6>
        <div><strong><?= h($ticket['company_name']); ?></strong> <small class="text-muted">(<?= h($ticket['company_code']); ?>)</small></div>
        <hr class="my-2">
        <div class="small">
            <div>Raised by: <strong><?= h($ticket['user_name']); ?></strong><?= $ticket['user_role'] ? ' (' . h($ticket['user_role']) . ')' : ''; ?></div>
            <div>Email: <?= h($ticket['contact_email'] ?: 'none'); ?></div>
            <div>Phone: <?= h($ticket['contact_phone'] ?: 'none'); ?></div>
            <?php if ($ticket['page_hint']) { ?><div>Page: <?= h($ticket['page_hint']); ?></div><?php } ?>
            <div>Raised: <?= h(date('d M Y H:i', strtotime($ticket['created_at']))); ?></div>
            <?php if ($ticket['resolved_at']) { ?><div>Resolved: <?= h(date('d M Y H:i', strtotime($ticket['resolved_at']))); ?></div><?php } ?>
            <?php if ($ticket['closed_at']) { ?><div>Closed: <?= h(date('d M Y H:i', strtotime($ticket['closed_at']))); ?></div><?php } ?>
        </div>
    </div>
    <div class="card p-3">
        <h6>Manage</h6>
        <form method="POST">
            <?= platform_csrf_field(); ?>
            <input type="hidden" name="action" value="update">
            <label class="form-label small text-muted mb-0">Urgency</label>
            <select name="priority" class="form-select form-select-sm mb-2">
                <?php foreach (SUPPORT_PRIORITIES as $k => $label) { ?><option value="<?= h($k); ?>" <?= $ticket['priority'] === $k ? 'selected' : ''; ?>><?= h(explode(':', $label)[0]); ?></option><?php } ?>
            </select>
            <label class="form-label small text-muted mb-0">Looked after by</label>
            <select name="assign" class="form-select form-select-sm mb-2">
                <option value=""><?= h($assignedName !== '' ? $assignedName . ' (keep)' : 'Nobody yet'); ?></option>
                <option value="me">Me (<?= h($admin['name']); ?>)</option>
                <option value="none">Nobody</option>
            </select>
            <button class="btn btn-outline-primary btn-sm" type="submit">Save</button>
        </form>
    </div>
</div>
</div>
<?php platform_page_end();
