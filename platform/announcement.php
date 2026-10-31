<?php
/**
 * NEW FILE: platform/announcement.php
 * Write a new announcement, or change an existing one.
 */
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/announcement_helpers.php';

$admin = platform_require_admin();
$id = (is_string($_GET['id'] ?? null) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
$existing = null;
if ($id > 0) {
    try {
        $existing = announcement_get($id);
    } catch (Throwable $e) {
        $existing = null;
    }
    if (!$existing) {
        http_response_code(404);
        platform_page_start('Not found', $admin, 'announcements');
        echo '<div class="alert alert-warning">That announcement does not exist. <a href="announcements.php">Back to the announcements</a></div>';
        platform_page_end();
        exit;
    }
}

$error = null;
$form = $existing ?: ['title' => '', 'body' => '', 'level' => 'info', 'audience' => 'everyone', 'link_url' => '', 'link_label' => '', 'starts_at' => '', 'ends_at' => '', 'status' => 'draft'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    foreach (['title', 'body', 'level', 'audience', 'link_url', 'link_label', 'starts_at', 'ends_at'] as $k) {
        $form[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    }
    $submit = (is_string($_POST['submit'] ?? null) && $_POST['submit'] === 'publish') ? 'publish' : 'save';
    $r = announcement_save($existing ? $id : null, $form, $submit, $admin, !empty($_POST['show_again']));
    if ($r['ok']) {
        platform_flash($submit === 'publish' ? 'Published. People will see it now.' : 'Saved.');
        header('Location: ' . ($existing ? 'announcement.php?id=' . $id : 'announcements.php'));
        exit;
    }
    $error = $r['error'];
}
// dates in the form's own format
$asInput = static function ($v): string {
    $t = is_string($v) ? trim($v) : '';
    return ($t !== '' && strtotime($t) !== false) ? date('Y-m-d\TH:i', strtotime($t)) : $t;
};
$status = $existing ? (string) $existing['status'] : 'draft';
platform_page_start($existing ? 'Edit announcement' : 'New announcement', $admin, 'announcements');
?>
<p><a href="announcements.php">&larr; All announcements</a></p>
<h4 class="mb-3"><?= $existing ? 'Edit announcement' : 'New announcement'; ?>
    <?php if ($existing) { $state = announcement_state($existing); ?><span class="badge text-bg-<?= ['draft' => 'secondary', 'scheduled' => 'info', 'live' => 'success', 'ended' => 'secondary', 'archived' => 'dark'][$state]; ?> fs-6 align-middle"><?= h(ucfirst($state)); ?></span><?php } ?></h4>
<?php if ($error) { ?><div class="alert alert-danger"><?= h($error); ?></div><?php } ?>
<div class="row g-3">
<div class="col-lg-7">
<div class="card p-3">
<form method="POST">
    <?= platform_csrf_field(); ?>
    <div class="mb-3"><label class="form-label" for="title">Title</label>
        <input class="form-control" id="title" name="title" maxlength="160" required value="<?= h($form['title']); ?>" placeholder="For example: Planned maintenance on Sunday"></div>
    <div class="mb-3"><label class="form-label" for="body">Message</label>
        <textarea class="form-control" id="body" name="body" rows="7" maxlength="5000" required placeholder="What do people need to know? Plain text. Press Enter for a new line."><?= h($form['body']); ?></textarea></div>
    <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label" for="level">Type</label>
            <select class="form-select" id="level" name="level"><?php foreach (ANNOUNCEMENT_LEVELS as $k => $label) { ?><option value="<?= h($k); ?>" <?= $form['level'] === $k ? 'selected' : ''; ?>><?= h($label); ?></option><?php } ?></select>
            <div class="form-text">The colour: blue information, green good news, yellow warning, red important.</div></div>
        <div class="col-md-6"><label class="form-label" for="audience">Who sees it</label>
            <select class="form-select" id="audience" name="audience"><?php foreach (ANNOUNCEMENT_AUDIENCES as $k => $label) { ?><option value="<?= h($k); ?>" <?= $form['audience'] === $k ? 'selected' : ''; ?>><?= h($label); ?></option><?php } ?></select></div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-7"><label class="form-label" for="link_url">Button link <span class="text-muted">(optional)</span></label>
            <input class="form-control" id="link_url" name="link_url" maxlength="300" value="<?= h($form['link_url']); ?>" placeholder="https://..."></div>
        <div class="col-md-5"><label class="form-label" for="link_label">Button text</label>
            <input class="form-control" id="link_label" name="link_label" maxlength="40" value="<?= h($form['link_label']); ?>" placeholder="Learn more"></div>
    </div>
    <div class="row g-3 mb-2">
        <div class="col-md-6"><label class="form-label" for="starts_at">Show from <span class="text-muted">(optional)</span></label>
            <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" value="<?= h($asInput($form['starts_at'])); ?>">
            <div class="form-text">Empty: as soon as it is published.</div></div>
        <div class="col-md-6"><label class="form-label" for="ends_at">Show until <span class="text-muted">(optional)</span></label>
            <input class="form-control" type="datetime-local" id="ends_at" name="ends_at" value="<?= h($asInput($form['ends_at'])); ?>">
            <div class="form-text">Empty: until you archive it.</div></div>
    </div>
    <p class="text-muted small">Times are the server's time. It is now <strong><?= h(date('d M Y H:i')); ?></strong>.</p>
    <?php if ($existing && $status === 'published') { ?>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="show_again" value="1" id="show_again"><label class="form-check-label" for="show_again">Show it again to people who already read it</label></div>
    <?php } ?>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($status === 'draft') { ?>
        <button class="btn btn-outline-primary" type="submit" name="submit" value="save">Save as draft</button>
        <button class="btn btn-primary" type="submit" name="submit" value="publish">Publish</button>
        <?php } else { ?>
        <button class="btn btn-primary" type="submit" name="submit" value="save">Save changes</button>
        <?php } ?>
    </div>
</form>
</div></div>
<div class="col-lg-5">
    <div class="card p-3">
        <h6 class="mb-3">How people will see it</h6>
        <?php echo announcement_card_html(['title' => $form['title'] !== '' ? $form['title'] : 'Your title', 'body' => $form['body'] !== '' ? $form['body'] : 'Your message.', 'level' => isset(ANNOUNCEMENT_LEVELS[$form['level']]) ? $form['level'] : 'info',
            'audience' => $form['audience'], 'link_url' => is_string($form['link_url']) && announcement_clean_link($form['link_url']) ? $form['link_url'] : '', 'link_label' => $form['link_label'], 'starts_at' => null, 'published_at' => date('Y-m-d H:i:s')], false, true); ?>
        <div class="text-muted small">This preview shows what you last typed.</div>
    </div>
</div>
</div>
<?php platform_page_end();
