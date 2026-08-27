<?php
require '../../config/db.php';
$pageSearchScope = 'leave'; // tells the topbar search what module we're in
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const PER_PAGE = 10;
$userId = current_user_id();
$role = current_user_role();

$currentPage = get_current_page();
$countStatement = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM leave_requests WHERE user_id = ?');
mysqli_stmt_bind_param($countStatement, 'i', $userId);
mysqli_stmt_execute($countStatement);
$totalRows = mysqli_fetch_assoc(mysqli_stmt_get_result($countStatement))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

// Everyone sees their own requests.
$myStatement = mysqli_prepare($conn, 'SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . PER_PAGE . ' OFFSET ' . $offset);
mysqli_stmt_bind_param($myStatement, 'i', $userId);
mysqli_stmt_execute($myStatement);
$myLeaves = mysqli_stmt_get_result($myStatement);

// Managers/Admins also see requests waiting on their decision.
$pendingMyApproval = null;
if ($role === 'Manager') {
    $pendingMyApproval = mysqli_query($conn, "SELECT leave_requests.*, users.names AS employee_name FROM leave_requests INNER JOIN users ON leave_requests.user_id = users.id WHERE leave_requests.status = 'Pending' ORDER BY leave_requests.created_at");
} elseif ($role === 'Admin') {
    $pendingMyApproval = mysqli_query($conn, "SELECT leave_requests.*, users.names AS employee_name FROM leave_requests INNER JOIN users ON leave_requests.user_id = users.id WHERE leave_requests.status IN ('Pending', 'Manager Approved') ORDER BY leave_requests.created_at");
}

function leave_status_badge($status) {
    $map = [
        'Pending' => 'secondary',
        'Manager Approved' => 'info',
        'Approved' => 'success',
        'Rejected' => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}
?>

<?php if (isset($_GET['success'])) { ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Leave Requests</h2>
    <a href="create.php" class="rm-btn rm-btn-primary">+ Request Leave</a>
</div>

<?php if ($pendingMyApproval && mysqli_num_rows($pendingMyApproval) > 0) { ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">Awaiting Your Approval</h5></div>
    <div class="card-body p-0">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Dates</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php while ($row = mysqli_fetch_assoc($pendingMyApproval)) { ?>
            <tr>
                <td><?= htmlspecialchars($row['employee_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($row['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($row['start_date'], ENT_QUOTES, 'UTF-8'); ?> &rarr; <?= htmlspecialchars($row['end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= leave_status_badge($row['status']); ?></td>
                <td><a href="approve.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-primary rm-btn-sm">Review</a></td>
            </tr>
            <?php } ?>
        </table>
    </div>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h5 class="mb-0">My Leave Requests</h5></div>
    <div class="card-body p-0">
        <!-- Live-search wires up against this container: it caches this exact
             markup on page load and swaps it out for filtered results as you
             type in the topbar search box, restoring it when the box is
             cleared. Only searches YOUR leave requests, matching this page's
             own query — the approval queue above stays untouched. -->
        <div id="pageResultsContainer">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr>
                <th>Type</th>
                <th>Dates</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Submitted</th>
            </tr>
            <?php if (mysqli_num_rows($myLeaves) === 0) { ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No leave requests yet.</td></tr>
            <?php } ?>
            <?php while ($row = mysqli_fetch_assoc($myLeaves)) { ?>
            <tr>
                <td><?= htmlspecialchars($row['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($row['start_date'], ENT_QUOTES, 'UTF-8'); ?> &rarr; <?= htmlspecialchars($row['end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($row['reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= leave_status_badge($row['status']); ?></td>
                <td><?= date('d M Y', strtotime($row['created_at'])); ?></td>
            </tr>
            <?php } ?>
        </table>
        </div>
    </div>
    <div id="pageResultsPagination">
    <?php render_pagination($currentPage, $totalPages); ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>