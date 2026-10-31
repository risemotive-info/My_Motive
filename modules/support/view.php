<?php
/**
 * NEW FILE: modules/support/view.php
 * One ticket: the conversation, an answer box, and "close this ticket".
 */
require '../../config/db.php';
require_once '../../includes/support_helpers.php';
$pageSearchScope = 'support';

$company = mm_company();
$me = support_current_user($conn);
$isAdmin = current_user_role() === 'Admin';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$e = 'support_h';

try {
    $ticket = $id > 0 ? support_company_ticket($id, (int) $company['id'], (int) $me['id'], $isAdmin) : null;
} catch (Throwable $ex) {
    $ticket = null;
}
if (!$ticket) {
    http_response_code(404);
    include '../../includes/header.php';
    include '../../includes/sidebar.php';
    echo '<div class="alert alert-warning">This ticket was not found, or it belongs to someone else. <a href="index.php">Back to Support</a></div>';
    include '../../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $go = function (string $kind, string $text) use ($id) {
        header('Location: view.php?id=' . $id . '&' . $kind . '=' . urlencode($text));
        exit;
    };
    if (!support_csrf_ok()) {
        $go('error', 'Your page was open for too long. Please try again.');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'reply') {
        $r = support_company_reply($ticket, $me, (string) ($_POST['message'] ?? ''));
        $r['ok'] ? $go('success', 'Your answer was sent.') : $go('error', $r['error']);
    } elseif ($action === 'close') {
        support_company_close($ticket, $me) ? $go('success', 'The ticket was closed. You can raise a new one any time.') : $go('error', 'The ticket could not be closed.');
    }
    $go('error', 'Unknown action.');
}

support_mark_read_by_business($id);   // reading it clears the "new answer" mark
$messages = support_messages($id, true);
$closed = $ticket['status'] === 'closed';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= $e($_GET['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($_GET['error'])) { ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $e($_GET['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <div class="text-muted small"><?= $e($ticket['reference']); ?> · <?= $e(SUPPORT_CATEGORIES[$ticket['category']] ?? $ticket['category']); ?></div>
        <h2 class="mb-1"><?= $e($ticket['subject']); ?></h2>
        <span class="badge text-bg-<?= support_status_badge($ticket['status']); ?>"><?= $e(support_status_label($ticket['status'])); ?></span>
        <span class="badge text-bg-<?= support_priority_badge($ticket['priority']); ?>"><?= $e(explode(':', SUPPORT_PRIORITIES[$ticket['priority']] ?? '')[0]); ?></span>
        <span class="text-muted small ms-2">Raised by <?= $e($ticket['user_name']); ?> on <?= $e(date('d M Y H:i', strtotime($ticket['created_at']))); ?></span>
    </div>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Support</a>
</div>
<?php if ($ticket['status'] === 'waiting_customer') { ?><div class="alert alert-warning">The MY MOTIVE team is waiting for your answer.</div><?php } ?>
<?php if ($ticket['status'] === 'resolved') { ?><div class="alert alert-success">The team marked this ticket as resolved. If it is not, just answer below and it will be opened again.</div><?php } ?>

<div class="mb-4">
<?php foreach ($messages as $m) { $mine = $m['author_type'] === 'company'; ?>
    <div class="rm-card mb-3" style="<?= $mine ? '' : 'border-left:4px solid #4C6FFF; background:#F7F9FF;'; ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-1 mb-2">
                <strong><?= $mine ? $e($m['author_name']) : 'MY MOTIVE support'; ?></strong>
                <span class="text-muted small"><?= $e(date('d M Y H:i', strtotime($m['created_at']))); ?></span>
            </div>
            <div style="white-space:pre-wrap; word-break:break-word;"><?= $e($m['body']); ?></div>
        </div>
    </div>
<?php } ?>
</div>

<?php if ($closed) { ?>
<div class="alert alert-secondary">This ticket is closed. <a href="create.php">Raise a new ticket</a> if you still need help.</div>
<?php } else { ?>
<div class="rm-card"><div class="card-body">
    <form method="POST" class="mb-3">
        <input type="hidden" name="csrf" value="<?= $e(support_csrf_token()); ?>">
        <input type="hidden" name="id" value="<?= (int) $ticket['id']; ?>">
        <input type="hidden" name="action" value="reply">
        <label class="form-label" for="message">Your answer</label>
        <textarea class="form-control mb-2" id="message" name="message" rows="5" maxlength="5000" required></textarea>
        <button class="rm-btn rm-btn-primary" type="submit">Send answer</button>
    </form>
    <?php if ($isAdmin || (int) $ticket['company_user_id'] === (int) $me['id']) { ?>
    <form method="POST" onsubmit="return confirm('Close this ticket? You will not be able to answer it any more.');">
        <input type="hidden" name="csrf" value="<?= $e(support_csrf_token()); ?>">
        <input type="hidden" name="id" value="<?= (int) $ticket['id']; ?>">
        <input type="hidden" name="action" value="close">
        <button class="rm-btn rm-btn-light rm-btn-sm" type="submit">My problem is solved: close this ticket</button>
    </form>
    <?php } ?>
</div></div>
<?php } ?>
<?php include '../../includes/footer.php'; ?>
