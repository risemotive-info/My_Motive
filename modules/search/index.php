<?php
require '../../config/db.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

$q = trim($_GET['q'] ?? '');
$scope = $_GET['scope'] ?? 'all';
$allowedScopes = ['all', 'employees', 'products', 'customers', 'departments', 'attendance', 'leave', 'projects', 'tasks', 'transactions', 'sales', 'notifications', 'categories'];
if (!in_array($scope, $allowedScopes, true)) {
    $scope = 'all';
}

// When scoped to a single module we can afford to return more rows
// since only one table is being rendered.
$limit = ($scope === 'all') ? 8 : 50;

$employees = [];
$products = [];
$customers = [];
$departments = [];
$attendance = [];
$leaveRequests = [];
$projects = [];
$tasks = [];
$transactions = [];
$sales = [];
$notifications = [];
$categories = [];

if ($q !== '') {
    $like = '%' . $q . '%';

    if ($scope === 'all' || $scope === 'employees') {
        $employeeStatement = mysqli_prepare($conn, "SELECT id, names, email, role FROM users WHERE (names LIKE ? OR email LIKE ?) AND is_active = 1 LIMIT $limit");
        mysqli_stmt_bind_param($employeeStatement, 'ss', $like, $like);
        mysqli_stmt_execute($employeeStatement);
        $employees = mysqli_fetch_all(mysqli_stmt_get_result($employeeStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'products') {
        $productStatement = mysqli_prepare($conn, "SELECT id, product_name, product_code, item_type FROM products WHERE (product_name LIKE ? OR product_code LIKE ?) AND is_active = 1 LIMIT $limit");
        mysqli_stmt_bind_param($productStatement, 'ss', $like, $like);
        mysqli_stmt_execute($productStatement);
        $products = mysqli_fetch_all(mysqli_stmt_get_result($productStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'customers') {
        $customerStatement = mysqli_prepare($conn, "SELECT id, name, phone, email FROM customers WHERE (name LIKE ? OR phone LIKE ?) LIMIT $limit");
        mysqli_stmt_bind_param($customerStatement, 'ss', $like, $like);
        mysqli_stmt_execute($customerStatement);
        $customers = mysqli_fetch_all(mysqli_stmt_get_result($customerStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'departments') {
        $departmentStatement = mysqli_prepare($conn, "SELECT id, name, description, status FROM departments WHERE name LIKE ? OR description LIKE ? LIMIT $limit");
        mysqli_stmt_bind_param($departmentStatement, 'ss', $like, $like);
        mysqli_stmt_execute($departmentStatement);
        $departments = mysqli_fetch_all(mysqli_stmt_get_result($departmentStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'attendance') {
        $attendanceStatement = mysqli_prepare($conn, "SELECT attendance.id, attendance.attendance_date, attendance.status, users.names AS user_name
            FROM attendance LEFT JOIN users ON attendance.user_id = users.id
            WHERE users.names LIKE ? OR attendance.status LIKE ? LIMIT $limit");
        mysqli_stmt_bind_param($attendanceStatement, 'ss', $like, $like);
        mysqli_stmt_execute($attendanceStatement);
        $attendance = mysqli_fetch_all(mysqli_stmt_get_result($attendanceStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'leave') {
        $leaveStatement = mysqli_prepare($conn, "SELECT leave_requests.id, leave_requests.leave_type, leave_requests.start_date, leave_requests.end_date, leave_requests.status, users.names AS user_name
            FROM leave_requests LEFT JOIN users ON leave_requests.user_id = users.id
            WHERE users.names LIKE ? OR leave_requests.leave_type LIKE ? OR leave_requests.reason LIKE ? OR leave_requests.status LIKE ? LIMIT $limit");
        mysqli_stmt_bind_param($leaveStatement, 'ssss', $like, $like, $like, $like);
        mysqli_stmt_execute($leaveStatement);
        $leaveRequests = mysqli_fetch_all(mysqli_stmt_get_result($leaveStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'projects') {
        $projectStatement = mysqli_prepare($conn, "SELECT id, project_name, description, status FROM projects WHERE project_name LIKE ? OR description LIKE ? LIMIT $limit");
        mysqli_stmt_bind_param($projectStatement, 'ss', $like, $like);
        mysqli_stmt_execute($projectStatement);
        $projects = mysqli_fetch_all(mysqli_stmt_get_result($projectStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'tasks') {
        $taskStatement = mysqli_prepare($conn, "SELECT tasks.id, tasks.title, tasks.status, tasks.priority, projects.project_name
            FROM tasks LEFT JOIN projects ON tasks.project_id = projects.id
            WHERE tasks.title LIKE ? OR tasks.description LIKE ? OR tasks.status LIKE ? LIMIT $limit");
        mysqli_stmt_bind_param($taskStatement, 'sss', $like, $like, $like);
        mysqli_stmt_execute($taskStatement);
        $tasks = mysqli_fetch_all(mysqli_stmt_get_result($taskStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'transactions') {
        $transactionStatement = mysqli_prepare($conn, "SELECT id, category, amount, transaction_type, description, transaction_date FROM transactions WHERE category LIKE ? OR description LIKE ? OR transaction_type LIKE ? LIMIT $limit");
        mysqli_stmt_bind_param($transactionStatement, 'sss', $like, $like, $like);
        mysqli_stmt_execute($transactionStatement);
        $transactions = mysqli_fetch_all(mysqli_stmt_get_result($transactionStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'sales') {
        $saleStatement = mysqli_prepare($conn, "SELECT sales.id, sales.sale_date, sales.total_amount, sales.status, customers.name AS customer_name
            FROM sales LEFT JOIN customers ON sales.customer_id = customers.id
            WHERE customers.name LIKE ? OR sales.payment_method LIKE ? OR sales.status LIKE ?
            ORDER BY sales.created_at DESC LIMIT $limit");
        mysqli_stmt_bind_param($saleStatement, 'sss', $like, $like, $like);
        mysqli_stmt_execute($saleStatement);
        $sales = mysqli_fetch_all(mysqli_stmt_get_result($saleStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'notifications') {
        $notificationStatement = mysqli_prepare($conn, "SELECT notifications.id, notifications.title, notifications.message, notifications.is_read, notifications.created_at, users.names
            FROM notifications LEFT JOIN users ON notifications.user_id = users.id
            WHERE users.names LIKE ? OR notifications.title LIKE ? OR notifications.message LIKE ?
            ORDER BY notifications.id DESC LIMIT $limit");
        mysqli_stmt_bind_param($notificationStatement, 'sss', $like, $like, $like);
        mysqli_stmt_execute($notificationStatement);
        $notifications = mysqli_fetch_all(mysqli_stmt_get_result($notificationStatement), MYSQLI_ASSOC);
    }

    if ($scope === 'all' || $scope === 'categories') {
        $categoryStatement = mysqli_prepare($conn, "SELECT id, category_name, description, is_active, created_at FROM categories WHERE category_name LIKE ? OR description LIKE ? ORDER BY category_name LIMIT $limit");
        mysqli_stmt_bind_param($categoryStatement, 'ss', $like, $like);
        mysqli_stmt_execute($categoryStatement);
        $categories = mysqli_fetch_all(mysqli_stmt_get_result($categoryStatement), MYSQLI_ASSOC);
    }
}

$totalResults = count($employees) + count($products) + count($customers) + count($sales)
    + count($departments) + count($attendance) + count($leaveRequests) + count($projects) + count($tasks) + count($transactions)
    + count($notifications) + count($categories);

// Friendly label for the scoped heading, e.g. "Customers matching 'mugisha'"
$scopeLabels = [
    'employees' => 'Employees',
    'products' => 'Items & Services',
    'customers' => 'Customers',
    'departments' => 'Departments',
    'attendance' => 'Attendance',
    'leave' => 'Leave Requests',
    'projects' => 'Projects',
    'tasks' => 'Tasks',
    'transactions' => 'Transactions',
    'sales' => 'Sales',
    'notifications' => 'Notifications',
    'categories' => 'Categories',
];
?>

<div class="mb-4">
    <h2><?= $scope !== 'all' ? htmlspecialchars($scopeLabels[$scope], ENT_QUOTES, 'UTF-8') . ' search' : 'Search results'; ?></h2>
    <?php if ($q !== '') { ?>
        <p class="text-muted mb-0"><?= (int) $totalResults; ?> result<?= $totalResults == 1 ? '' : 's'; ?> for "<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>"<?= $scope !== 'all' ? ' in ' . htmlspecialchars($scopeLabels[$scope], ENT_QUOTES, 'UTF-8') : ''; ?></p>
    <?php } ?>
</div>

<?php if ($q === '') { ?>
    <div class="card shadow"><div class="card-body text-center text-muted py-5">Type something in the search bar above to get started.</div></div>
<?php } elseif ($totalResults === 0) { ?>
    <div class="card shadow"><div class="card-body text-center text-muted py-5">No matches found for "<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>"<?= $scope !== 'all' ? ' in ' . htmlspecialchars($scopeLabels[$scope], ENT_QUOTES, 'UTF-8') : ''; ?>.</div></div>
<?php } else { ?>

    <?php if (!empty($employees)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-people-fill"></i> Employees</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($employees as $e) { ?>
                <tr>
                    <td><?= htmlspecialchars($e['names'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($e['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($e['role'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../users/view.php?id=<?= (int) $e['id']; ?>" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($products)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-box-seam"></i> Items &amp; Services</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($products as $p) { ?>
                <tr>
                    <td><?= htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($p['product_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-primary"><?= htmlspecialchars($p['item_type'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../products/view.php?id=<?= (int) $p['id']; ?>" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($customers)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-person-lines-fill"></i> Customers</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($customers as $c) { ?>
                <tr>
                    <td><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($c['phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($c['email'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end"><a href="../customers/view.php?id=<?= (int) $c['id']; ?>" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($departments)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-building"></i> Departments</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($departments as $d) { ?>
                <tr>
                    <td><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars(mb_strimwidth($d['description'] ?? '', 0, 60, '...'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($d['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../departments/index.php" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($attendance)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-calendar-check"></i> Attendance</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($attendance as $a) { ?>
                <tr>
                    <td><?= htmlspecialchars($a['user_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= date('d M Y', strtotime($a['attendance_date'])); ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($a['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../attendance/index.php" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($leaveRequests)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-calendar-plus"></i> Leave Requests</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($leaveRequests as $l) { ?>
                <tr>
                    <td><?= htmlspecialchars($l['user_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($l['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= date('d M', strtotime($l['start_date'])); ?> – <?= date('d M Y', strtotime($l['end_date'])); ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($l['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../leave/index.php" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($projects)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-folder-fill"></i> Projects</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($projects as $p) { ?>
                <tr>
                    <td><?= htmlspecialchars($p['project_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../projects/index.php" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($tasks)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-check2-square"></i> Tasks</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($tasks as $t) { ?>
                <tr>
                    <td><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($t['project_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($t['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../tasks/index.php" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($transactions)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-cash-stack"></i> Transactions</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($transactions as $tr) { ?>
                <tr>
                    <td><?= htmlspecialchars($tr['category'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars(mb_strimwidth($tr['description'] ?? '', 0, 60, '...'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>RWF <?= number_format($tr['amount'], 2); ?></td>
                    <td><span class="badge bg-<?= $tr['transaction_type'] === 'Income' ? 'success' : 'danger'; ?>"><?= htmlspecialchars($tr['transaction_type'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../transactions/index.php" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($sales)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-receipt"></i> Sales</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($sales as $s) { ?>
                <tr>
                    <td><?= date('d M Y', strtotime($s['sale_date'])); ?></td>
                    <td><?= htmlspecialchars($s['customer_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>RWF <?= number_format($s['total_amount'], 2); ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($s['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../sales/invoice.php?id=<?= (int) $s['id']; ?>" target="_blank" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($notifications)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-bell-fill"></i> Notifications</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($notifications as $n) { ?>
                <tr>
                    <td><?= htmlspecialchars($n['names'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars(mb_strimwidth($n['message'] ?? '', 0, 60, '...'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-<?= $n['is_read'] ? 'success' : 'warning text-dark'; ?>"><?= $n['is_read'] ? 'Read' : 'Unread'; ?></span></td>
                    <td class="text-end"><a href="../notifications/index.php" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($categories)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-tags-fill"></i> Categories</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($categories as $c) { ?>
                <tr>
                    <td><?= htmlspecialchars($c['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars(mb_strimwidth($c['description'] ?? '', 0, 60, '...'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary'; ?>"><?= $c['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                    <td class="text-end"><a href="../categories/index.php" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

<?php } ?>

<?php include '../../includes/footer.php'; ?>