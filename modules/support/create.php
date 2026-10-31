<?php
/**
 * NEW FILE: modules/support/create.php
 * Raise a support ticket.
 */
require '../../config/db.php';
require_once '../../includes/support_helpers.php';
$pageSearchScope = 'support';

$company = mm_company();
$me = support_current_user($conn);
$error = null;
$in = ['category' => '', 'priority' => 'normal', 'subject' => '', 'message' => '', 'email' => $me['email'], 'phone' => $me['phone'], 'page' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($in as $k => $_) {
        $in[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : $in[$k];
    }
    if (!support_csrf_ok()) {
        $error = 'Your page was open for too long. Please send it again.';
    } else {
        $result = support_create($company, $me, $in);
        if ($result['ok']) {
            header('Location: view.php?id=' . (int) $result['id'] . '&success=' . urlencode('Ticket ' . $result['reference'] . ' was sent. The MY MOTIVE team will answer here and by email.'));
            exit;
        }
        $error = $result['error'];
    }
}
$e = 'support_h';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="mb-0">Raise a ticket</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Support</a>
</div>
<?php if ($error) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>

<div class="rm-card"><div class="card-body">
<form method="POST" autocomplete="off" style="max-width:760px;">
    <input type="hidden" name="csrf" value="<?= $e(support_csrf_token()); ?>">
    <div class="row g-3">
        <div class="col-md-7">
            <label class="form-label" for="category">What is it about?</label>
            <select class="form-select" id="category" name="category" required>
                <option value="">Choose...</option>
                <?php foreach (SUPPORT_CATEGORIES as $k => $label) { ?><option value="<?= $e($k); ?>" <?= $in['category'] === $k ? 'selected' : ''; ?>><?= $e($label); ?></option><?php } ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label" for="priority">How urgent is it?</label>
            <select class="form-select" id="priority" name="priority">
                <?php foreach (SUPPORT_PRIORITIES as $k => $label) { ?><option value="<?= $e($k); ?>" <?= $in['priority'] === $k ? 'selected' : ''; ?>><?= $e($label); ?></option><?php } ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label" for="subject">Title</label>
            <input class="form-control" id="subject" name="subject" maxlength="160" required value="<?= $e($in['subject']); ?>" placeholder="For example: I cannot delete a payroll">
        </div>
        <div class="col-12">
            <label class="form-label" for="message">What happened?</label>
            <textarea class="form-control" id="message" name="message" rows="7" maxlength="5000" required placeholder="What were you doing, what did you expect, and what did you see (copy the message on the screen if there is one)?"><?= $e($in['message']); ?></textarea>
        </div>
        <div class="col-12">
            <label class="form-label" for="page">Which page were you on? <span class="text-muted">(optional)</span></label>
            <input class="form-control" id="page" name="page" maxlength="200" value="<?= $e($in['page']); ?>" placeholder="For example: Payroll, then Delete">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="email">Email for the answer</label>
            <input class="form-control" type="email" id="email" name="email" maxlength="190" value="<?= $e($in['email']); ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="phone">Phone <span class="text-muted">(optional)</span></label>
            <input class="form-control" id="phone" name="phone" maxlength="30" value="<?= $e($in['phone']); ?>">
        </div>
    </div>
    <p class="text-muted small mt-3 mb-3">Please do not write passwords in a ticket. We will answer here, and by email if you gave one.</p>
    <button class="rm-btn rm-btn-primary" type="submit">Send ticket</button>
</form>
</div></div>
<?php include '../../includes/footer.php'; ?>
