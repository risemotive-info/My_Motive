<?php
$pageSearchScope = 'attendance'; // tells the topbar search what module we're in
require '../../config/db.php';
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const PER_PAGE = 15;
$role = current_user_role();

$date = $_GET['date'] ?? '';
$where = '';

if ($date !== '') {
    $dateValue = DateTime::createFromFormat('Y-m-d', $date);
    if ($dateValue && $dateValue->format('Y-m-d') === $date) {
        $where = ' WHERE attendance.attendance_date = ?';
    } else {
        $date = '';
    }
}

$countSql = 'SELECT COUNT(*) AS c FROM attendance' . $where;
if ($where !== '') {
    $countStatement = mysqli_prepare($conn, $countSql);
    mysqli_stmt_bind_param($countStatement, 's', $date);
    mysqli_stmt_execute($countStatement);
    $totalRows = mysqli_fetch_assoc(mysqli_stmt_get_result($countStatement))['c'];
} else {
    $totalRows = mysqli_fetch_assoc(mysqli_query($conn, $countSql))['c'];
}
$currentPage = get_current_page();
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

$sql = "SELECT attendance.*, users.names AS employee_name, departments.name AS department_name
        FROM attendance
        INNER JOIN users ON attendance.user_id = users.id
        LEFT JOIN departments ON users.department_id = departments.id" . $where . '
        ORDER BY attendance.attendance_date DESC, users.names
        LIMIT ' . PER_PAGE . ' OFFSET ' . $offset;
$statement = mysqli_prepare($conn, $sql);

if ($where !== '') {
    mysqli_stmt_bind_param($statement, 's', $date);
}

mysqli_stmt_execute($statement);
$records = mysqli_stmt_get_result($statement);

// Managers/Admins: records waiting on their action today.
$pending = null;
if (in_array($role, ['Manager', 'Admin'], true)) {
    $pending = mysqli_query($conn, "SELECT attendance.*, users.names AS employee_name
        FROM attendance INNER JOIN users ON attendance.user_id = users.id
        WHERE attendance.workflow_status IN ('Pending Clock-In Approval', 'Pending Clock-Out Confirmation')
        ORDER BY attendance.attendance_date, users.names");
}

function workflow_badge($status) {
    $map = [
        'Pending Clock-In Approval' => 'secondary',
        'Working' => 'info',
        'Pending Clock-Out Confirmation' => 'secondary',
        'Confirmed' => 'success',
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
    <h2>Attendance Management</h2>
    <div class="d-flex gap-2">
        <a href="clock.php" class="rm-btn rm-btn-primary"><i class="bi bi-clock-fill me-1"></i> My Clock In/Out</a>
        <?php if (in_array($role, ['Admin', 'Manager'], true)) { ?>
        <a href="create.php" class="btn btn-outline-primary">+ Record Manually</a>
        <?php } ?>
    </div>
</div>

<?php if ($pending && mysqli_num_rows($pending) > 0) { ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">Awaiting Your Action</h5></div>
    <div class="card-body p-0">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr>
                <th>Date</th>
                <th>Employee</th>
                <th>Check In</th>
                <th>Check Out</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php while ($row = mysqli_fetch_assoc($pending)) { ?>
            <tr>
                <td><?= date('d M Y', strtotime($row['attendance_date'])); ?></td>
                <td><?= htmlspecialchars($row['employee_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= $row['check_in'] ? date('H:i', strtotime($row['check_in'])) : '—'; ?></td>
                <td><?= $row['check_out'] ? date('H:i', strtotime($row['check_out'])) : '—'; ?></td>
                <td><?= workflow_badge($row['workflow_status']); ?></td>
                <td><a href="approve.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-primary btn-sm"><?= $row['workflow_status'] === 'Pending Clock-Out Confirmation' ? 'Confirm' : 'Approve'; ?></a></td>
            </tr>
            <?php } ?>
        </table>
    </div>
</div>
<?php } ?>

<form method="GET" class="row g-2 mb-4">
    <div class="col-auto">
        <label for="date" class="visually-hidden">Attendance date</label>
        <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div class="col-auto"><button type="submit" class="btn btn-outline-primary">Filter</button></div>
    <div class="col-auto"><a href="index.php" class="btn btn-outline-secondary">Clear</a></div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive" id="pageResultsContainer">
            <table class="table table-bordered table-hover bg-white mb-0">
                <tr>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Check In</th>
                    <th>Check Out</th>
                    <th>Status</th>
                    <th>Workflow</th>
                    <?php if (in_array($role, ['Admin', 'Manager'], true)) { ?><th>Action</th><?php } ?>
                </tr>
                <?php if (mysqli_num_rows($records) === 0) { ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No attendance records found.</td></tr>
                <?php } ?>
                <?php while ($record = mysqli_fetch_assoc($records)) { ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($record['attendance_date'])); ?></td>
                        <td><?= htmlspecialchars($record['employee_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($record['department_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= $record['check_in'] ? date('H:i', strtotime($record['check_in'])) : '—'; ?></td>
                        <td><?= $record['check_out'] ? date('H:i', strtotime($record['check_out'])) : '—'; ?></td>
                        <td><span class="badge bg-<?= $record['status'] === 'Present' ? 'success' : ($record['status'] === 'Late' ? 'warning text-dark' : 'secondary'); ?>"><?= htmlspecialchars($record['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?= workflow_badge($record['workflow_status']); ?></td>
                        <?php if (in_array($role, ['Admin', 'Manager'], true)) { ?>
                        <td class="text-nowrap">
                            <a href="edit.php?id=<?= (int) $record['id']; ?>" class="rm-btn rm-btn-warning btn-sm">Edit</a>
                            <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this attendance record?')">
                                <input type="hidden" name="id" value="<?= (int) $record['id']; ?>">
                                <button type="submit" class="rm-btn rm-btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </table>
        </div>
        <div id="pageResultsPagination">
        <?php render_pagination($currentPage, $totalPages, $date !== '' ? ['date' => $date] : []); ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>