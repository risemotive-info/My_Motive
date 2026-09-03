<?php
require '../../config/db.php';

$userId = current_user_id();
$role = current_user_role();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid leave request selected.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT leave_requests.*, users.names AS employee_name FROM leave_requests INNER JOIN users ON leave_requests.user_id = users.id WHERE leave_requests.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$leave = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$leave) { header('Location: index.php?success=Leave request not found.'); exit; }

// Only the employee who owns the request, or a Manager/Admin, may view or join the chat.
$canView = ((int) $leave['user_id'] === (int) $userId) || in_array($role, ['Manager', 'Admin'], true);
if (!$canView) { header('Location: index.php?success=You do not have access to that leave request.'); exit; }

if (isset($_POST['message'])) {
    $message = trim($_POST['message']);
    if ($message === '') {
        $error = 'Please write a message before sending.';
    } else {
        $insert = mysqli_prepare($conn, 'INSERT INTO leave_request_comments (leave_request_id, user_id, message) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($insert, 'iis', $id, $userId, $message);
        mysqli_stmt_execute($insert);
        header('Location: view.php?id=' . $id . '#chatBottom');
        exit;
    }
}

$commentsStatement = mysqli_prepare($conn, 'SELECT leave_request_comments.*, users.names AS author_name FROM leave_request_comments INNER JOIN users ON leave_request_comments.user_id = users.id WHERE leave_request_id = ? ORDER BY created_at ASC, id ASC');
mysqli_stmt_bind_param($commentsStatement, 'i', $id);
mysqli_stmt_execute($commentsStatement);
$commentsResult = mysqli_stmt_get_result($commentsStatement);

// Build a single chronological timeline mixing the manager/HR decisions
// (which were already being recorded) with free-form chat messages.
$events = [];

$events[] = [
    'time' => $leave['created_at'],
    'label' => htmlspecialchars($leave['employee_name'], ENT_QUOTES, 'UTF-8') . ' submitted this request',
    'body' => $leave['reason'] ?? '',
    'color' => '#6366F1',
];

if ($leave['manager_id']) {
    $managerVerb = ($leave['status'] === 'Rejected' && !$leave['hr_id']) ? 'rejected' : 'approved';
    $events[] = [
        'time' => $leave['manager_acted_at'],
        'label' => 'Supervisor ' . $managerVerb . ' this request',
        'body' => $leave['manager_comment'] ?? '',
        'color' => $managerVerb === 'rejected' ? '#EF4444' : '#22C55E',
    ];
}

if ($leave['hr_id']) {
    $hrVerb = $leave['status'] === 'Rejected' ? 'rejected' : 'gave final approval on';
    $events[] = [
        'time' => $leave['hr_acted_at'],
        'label' => 'HR ' . $hrVerb . ' this request',
        'body' => $leave['hr_comment'] ?? '',
        'color' => $leave['status'] === 'Rejected' ? '#EF4444' : '#22C55E',
    ];
}

while ($comment = mysqli_fetch_assoc($commentsResult)) {
    $isMe = (int) $comment['user_id'] === (int) $userId;
    $events[] = [
        'time' => $comment['created_at'],
        'label' => htmlspecialchars($comment['author_name'], ENT_QUOTES, 'UTF-8') . ($isMe ? ' (You)' : ''),
        'body' => $comment['message'],
        'color' => $isMe ? '#6366F1' : '#94A3B8',
    ];
}

usort($events, function ($a, $b) {
    return strtotime($a['time']) <=> strtotime($b['time']);
});

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-chat-left-text-fill';
$modal_title = 'Leave Request';
$modal_subtitle = htmlspecialchars($leave['employee_name'], ENT_QUOTES, 'UTF-8') . "'s request — chat & history";
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">

            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Leave Type</label>
                    <input class="form-control rm-input" value="<?= htmlspecialchars($leave['leave_type'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Dates</label>
                    <input class="form-control rm-input" value="<?= htmlspecialchars($leave['start_date'], ENT_QUOTES, 'UTF-8'); ?> &rarr; <?= htmlspecialchars($leave['end_date'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
            </div>

            <div class="mb-2 small fw-semibold text-muted text-uppercase" style="letter-spacing:.03em;">Chat &amp; History</div>

            <div class="d-flex flex-column gap-3 mb-4" style="max-height:420px; overflow-y:auto; padding-right:4px;">
                <?php foreach ($events as $event) { ?>
                <div class="p-3" style="background:#F8FAFC; border-radius:12px; border-left:3px solid <?= $event['color']; ?>;">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <span class="small fw-semibold"><?= $event['label']; ?></span>
                        <?php if ($event['time']) { ?>
                        <span class="small text-muted"><?= date('d M Y, g:i A', strtotime($event['time'])); ?></span>
                        <?php } ?>
                    </div>
                    <?php if ($event['body']) { ?>
                    <div class="small text-muted"><?= nl2br(htmlspecialchars($event['body'], ENT_QUOTES, 'UTF-8')); ?></div>
                    <?php } ?>
                </div>
                <?php } ?>
                <span id="chatBottom"></span>
            </div>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Add a message</label>
                    <textarea name="message" class="form-control rm-input" rows="2" style="height:auto;" placeholder="Write a message..."></textarea>
                </div>
                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" class="rm-btn rm-btn-primary">
                        <i class="bi bi-send-fill me-2"></i>Send
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back
                    </a>
                </div>
            </form>

        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>