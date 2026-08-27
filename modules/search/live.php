<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';

header('Content-Type: application/json');

function esc($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

$q = trim($_GET['q'] ?? '');
$scope = $_GET['scope'] ?? 'all';

// Scopes that have an exact-table renderer wired up (mirrors the real page
// table exactly). Only these support live in-place filtering.
$exactScopes = ['attendance', 'customers', 'departments', 'notifications', 'projects', 'sales', 'transactions', 'employees', 'leave', 'products', 'tasks'];

$role = function_exists('current_user_role') ? current_user_role() : null;
$canManageAttendance = in_array($role, ['Admin', 'Manager'], true);
$isAdminSession = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
$userId = function_exists('current_user_id') ? current_user_id() : null;
$currentUserId = $_SESSION['user_id'] ?? 0;

if ($q === '' || mb_strlen($q) < 1 || !in_array($scope, $exactScopes, true)) {
    echo json_encode(['empty' => true, 'html' => '']);
    exit;
}

$like = '%' . $q . '%';

/* ---------- shared little helpers, ported from each module's index.php ---------- */

function workflowBadgeHtml($status) {
    $map = [
        'Pending Clock-In Approval' => 'secondary',
        'Working' => 'info',
        'Pending Clock-Out Confirmation' => 'secondary',
        'Confirmed' => 'success',
        'Rejected' => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . esc($status) . '</span>';
}

function attendanceStatusBadgeHtml($status) {
    $class = $status === 'Present' ? 'success' : ($status === 'Late' ? 'warning text-dark' : 'secondary');
    return '<span class="badge bg-' . $class . '">' . esc($status) . '</span>';
}

/* ---------- exact-table renderers (mirror the real module page) ---------- */

function renderAttendanceRows(mysqli $conn, string $like, int $limit, bool $canManage): string
{
    $sql = "SELECT attendance.id, attendance.attendance_date, attendance.check_in, attendance.check_out,
                   attendance.status, attendance.workflow_status,
                   users.names AS employee_name, departments.name AS department_name
            FROM attendance
            INNER JOIN users ON attendance.user_id = users.id
            LEFT JOIN departments ON users.department_id = departments.id
            WHERE users.names LIKE ? OR attendance.status LIKE ?
            ORDER BY attendance.attendance_date DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    foreach ($rows as $r) {
        $html .= '<tr>';
        $html .= '<td>' . esc(date('d M Y', strtotime($r['attendance_date']))) . '</td>';
        $html .= '<td>' . esc($r['employee_name']) . '</td>';
        $html .= '<td>' . esc($r['department_name'] ?? '—') . '</td>';
        $html .= '<td>' . ($r['check_in'] ? esc(date('H:i', strtotime($r['check_in']))) : '—') . '</td>';
        $html .= '<td>' . ($r['check_out'] ? esc(date('H:i', strtotime($r['check_out']))) : '—') . '</td>';
        $html .= '<td>' . attendanceStatusBadgeHtml($r['status']) . '</td>';
        $html .= '<td>' . workflowBadgeHtml($r['workflow_status']) . '</td>';
        if ($canManage) {
            $id = (int) $r['id'];
            $html .= '<td class="text-nowrap">';
            $html .= '<a href="../attendance/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning btn-sm">Edit</a> ';
            $html .= '<form method="POST" action="../attendance/delete.php" class="d-inline" onsubmit="return confirm(\'Delete this attendance record?\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn rm-btn-danger btn-sm">Delete</button>';
            $html .= '</form></td>';
        }
        $html .= '</tr>';
    }
    return $html;
}

function attendanceHeaderRow(bool $canManage): string
{
    $h = '<tr><th>Date</th><th>Employee</th><th>Department</th><th>Check In</th><th>Check Out</th><th>Status</th><th>Workflow</th>';
    if ($canManage) $h .= '<th>Action</th>';
    return $h . '</tr>';
}

function renderCustomersRows(mysqli $conn, string $like, int $limit): string
{
    $sql = "SELECT customers.id, customers.name, customers.phone, customers.email,
                   COALESCE(SUM(CASE WHEN sales.status != 'Cancelled' THEN sales.total_amount - sales.amount_paid ELSE 0 END), 0) AS balance
            FROM customers
            LEFT JOIN sales ON sales.customer_id = customers.id
            WHERE customers.is_active = 1 AND (customers.name LIKE ? OR customers.phone LIKE ?)
            GROUP BY customers.id
            ORDER BY customers.name
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    foreach ($rows as $r) {
        $html .= '<tr>';
        $html .= '<td>' . esc($r['name']) . '</td>';
        $html .= '<td>' . esc($r['phone'] ?? '—') . '</td>';
        $html .= '<td>' . esc($r['email'] ?? '—') . '</td>';
        if ($r['balance'] > 0) {
            $html .= '<td><span class="text-danger fw-semibold">RWF ' . number_format($r['balance'], 2) . '</span></td>';
        } else {
            $html .= '<td><span class="text-muted">RWF 0.00</span></td>';
        }
        $html .= '<td><a href="../customers/edit.php?id=' . (int) $r['id'] . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a></td>';
        $html .= '</tr>';
    }
    return $html;
}

function customersHeaderRow(): string
{
    return '<tr><th>Name</th><th>Phone</th><th>Email</th><th>Outstanding Balance</th><th>Action</th></tr>';
}

function renderDepartmentsRows(mysqli $conn, string $like, int $limit, ?string $role): string
{
    $sql = "SELECT id, name, description, is_active FROM departments WHERE name LIKE ? OR description LIKE ? ORDER BY name LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    if (empty($rows)) {
        return '';
    }

    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $rolesByDept = [];
    $idList = implode(',', $ids);
    $rolesResult = mysqli_query($conn, "SELECT department_id, name FROM roles WHERE is_active = 1 AND department_id IN ($idList) ORDER BY name");
    while ($r = mysqli_fetch_assoc($rolesResult)) {
        $rolesByDept[$r['department_id']][] = $r['name'];
    }

    $isAdmin = $role === 'Admin';

    $html = '';
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $roles = $rolesByDept[$id] ?? [];

        $html .= '<tr>';
        $html .= '<td><strong>' . esc($r['name']) . '</strong><div class="text-muted small">' . esc($r['description']) . '</div></td>';
        $html .= '<td>';
        if (empty($roles)) {
            $html .= '<span class="text-muted small">No roles defined yet</span>';
        } else {
            foreach ($roles as $title) {
                $html .= '<span class="badge bg-light text-dark border me-1 mb-1">' . esc($title) . '</span>';
            }
        }
        $html .= '</td>';
        $html .= '<td>' . ($r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>') . '</td>';
        if ($isAdmin) {
            $html .= '<td class="text-nowrap">';
            $html .= '<a href="../departments/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View / Manage Roles</a> ';
            $html .= '<a href="../departments/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
            $confirmMsg = $r['is_active']
                ? 'Deactivate this department? It will be hidden from the Add Employee dropdown but its data is kept.'
                : 'Reactivate this department? It will show up in the Add Employee dropdown again.';
            $html .= '<form method="POST" action="../departments/toggle_status.php" class="d-inline" onsubmit="return confirm(\'' . esc($confirmMsg) . '\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn ' . ($r['is_active'] ? 'rm-btn-secondary' : 'rm-btn-success') . ' rm-btn-sm">' . ($r['is_active'] ? 'Deactivate' : 'Reactivate') . '</button>';
            $html .= '</form> ';
            $html .= '<form method="POST" action="../departments/delete_permanent.php" class="d-inline" onsubmit="return confirm(\'Permanently delete this department? This cannot be undone. It only works if no employees or roles are assigned to it.\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>';
            $html .= '</form>';
            $html .= '</td>';
        }
        $html .= '</tr>';
    }
    return $html;
}

function departmentsHeaderRow(?string $role): string
{
    $h = '<tr><th>Name</th><th>Roles</th><th>Status</th>';
    if ($role === 'Admin') $h .= '<th>Action</th>';
    return $h . '</tr>';
}

function renderNotificationsRows(mysqli $conn, string $like, int $limit): string
{
    $sql = "SELECT notifications.id, notifications.title, notifications.message, notifications.is_read, notifications.created_at, users.names
            FROM notifications
            LEFT JOIN users ON notifications.user_id = users.id
            WHERE users.names LIKE ? OR notifications.title LIKE ? OR notifications.message LIKE ?
            ORDER BY notifications.id DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $html .= '<tr>';
        $html .= '<td>' . esc($r['names']) . '</td>';
        $html .= '<td>' . esc($r['title']) . '</td>';
        $html .= '<td>' . esc(mb_strimwidth($r['message'] ?? '', 0, 50, '...')) . '</td>';
        $html .= '<td>' . ($r['is_read'] ? '<span class="badge bg-success">Read</span>' : '<span class="badge bg-warning text-dark">Unread</span>') . '</td>';
        $html .= '<td>' . esc(date('d M Y H:i', strtotime($r['created_at']))) . '</td>';
        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../notifications/view.php?id=' . $id . '" class="rm-btn rm-btn-info btn-sm">View</a> ';
        $html .= '<a href="../notifications/mark_read.php?id=' . $id . '" class="rm-btn rm-btn-success btn-sm">Mark Read</a> ';
        $html .= '<a href="../notifications/delete.php?id=' . $id . '" class="rm-btn rm-btn-danger btn-sm" onclick="return confirm(\'Delete this notification?\')">Delete</a>';
        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function notificationsHeaderRow(): string
{
    return '<tr><th>User</th><th>Title</th><th>Message</th><th>Status</th><th>Date</th><th>Action</th></tr>';
}

function renderProjectsRows(mysqli $conn, string $like, int $limit, bool $isAdmin): string
{
    $sql = "SELECT projects.*, users.names AS creator_name
            FROM projects
            LEFT JOIN users ON projects.created_by = users.id
            WHERE projects.project_name LIKE ? OR projects.description LIKE ?
            ORDER BY projects.id ASC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    $rowNumber = 1;
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $statusClass = $r['status'] === 'Completed' ? 'success' : ($r['status'] === 'On Hold' ? 'secondary' : 'primary');
        $html .= '<tr>';
        $html .= '<td>' . $rowNumber++ . '</td>';
        $html .= '<td><strong>' . esc($r['project_name']) . '</strong><br><small class="text-muted">' . esc($r['description'] ?? '') . '</small></td>';
        $html .= '<td>' . ($r['start_date'] ? esc(date('d M Y', strtotime($r['start_date']))) : '—') . '<br>' . ($r['end_date'] ? esc(date('d M Y', strtotime($r['end_date']))) : '—') . '</td>';
        $html .= '<td><span class="badge bg-' . $statusClass . '">' . esc($r['status']) . '</span></td>';
        $html .= '<td>' . esc($r['creator_name'] ?? '—') . '</td>';
        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../projects/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View</a> ';
        if ($isAdmin) {
            $html .= '<a href="../projects/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
            $html .= '<form method="POST" action="../projects/delete.php" class="d-inline" onsubmit="return confirm(\'Delete this project?\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>';
            $html .= '</form>';
        }
        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function projectsHeaderRow(): string
{
    return '<tr><th>No.</th><th>Project</th><th>Dates</th><th>Status</th><th>Created By</th><th>Action</th></tr>';
}

function saleStatusBadgeHtml($status) {
    $map = [
        'Pending Discount Approval' => 'secondary',
        'Paid' => 'success',
        'Partially Paid' => 'warning text-dark',
        'Credit' => 'danger',
        'Cancelled' => 'dark',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . esc($status) . '</span>';
}

function renderSalesRows(mysqli $conn, string $like, int $limit, ?string $role, $userId): string
{
    $where = '(customers.name LIKE ? OR sales.payment_method LIKE ? OR sales.status LIKE ?)';
    if ($role === 'Employee') {
        $where .= ' AND sales.recorded_by = ' . (int) $userId;
    }

    $sql = "SELECT sales.*, customers.name AS customer_name, users.names AS recorded_by_name
            FROM sales
            LEFT JOIN customers ON sales.customer_id = customers.id
            LEFT JOIN users ON sales.recorded_by = users.id
            WHERE $where
            ORDER BY sales.created_at DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $canManage = in_array($role, ['Admin', 'Manager'], true);

    $html = '';
    foreach ($rows as $sale) {
        $id = (int) $sale['id'];
        $html .= '<tr>';
        $html .= '<td>' . esc(date('d M Y', strtotime($sale['sale_date']))) . '</td>';
        $html .= '<td>' . esc($sale['customer_name'] ?? 'Walk-in') . '</td>';
        $html .= '<td>' . esc($sale['recorded_by_name'] ?? '—') . '</td>';
        $html .= '<td>' . esc($sale['payment_method']) . '</td>';
        $html .= '<td>RWF ' . number_format($sale['total_amount'], 2) . '</td>';
        $html .= '<td>RWF ' . number_format($sale['amount_paid'], 2) . '</td>';
        $html .= '<td>' . saleStatusBadgeHtml($sale['status']) . '</td>';

        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../sales/invoice.php?id=' . $id . '" target="_blank" class="btn btn-outline-primary btn-sm">Invoice</a> ';

        if ($sale['status'] === 'Credit' || $sale['status'] === 'Partially Paid') {
            $html .= '<a href="../sales/pay_credit.php?id=' . $id . '" class="btn btn-outline-success btn-sm">Pay</a> ';
        }

        if (in_array($sale['status'], ['Credit', 'Partially Paid', 'Paid'], true)) {
            if ($canManage) {
                if (!empty($sale['cancel_requested_by'])) {
                    $html .= '<a href="../sales/review_cancel_request.php?id=' . $id . '" class="btn btn-warning btn-sm">Review Request</a>';
                } else {
                    $html .= '<a href="../sales/cancel.php?id=' . $id . '" class="btn btn-outline-danger btn-sm">Cancel</a>';
                }
            } elseif (!empty($sale['cancel_requested_by'])) {
                $html .= '<span class="badge bg-secondary">Cancel Requested</span>';
            } else {
                $html .= '<a href="../sales/request_cancel.php?id=' . $id . '" class="btn btn-outline-warning btn-sm">Request Cancel</a>';
            }
        }

        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function salesHeaderRow(): string
{
    return '<tr><th>Date</th><th>Customer</th><th>Recorded By</th><th>Payment</th><th>Total</th><th>Paid</th><th>Status</th><th>Action</th></tr>';
}

function renderTransactionsRows(mysqli $conn, string $like, int $limit, bool $isAdmin, $currentUserId): string
{
    $visibilityWhere = $isAdmin ? '1=1' : "(status = 'approved' OR recorded_by = " . (int) $currentUserId . ')';
    $searchWhere = '(category LIKE ? OR description LIKE ? OR transaction_type LIKE ?)';

    $sql = "SELECT transactions.*, users.names AS recorder_name
            FROM transactions
            LEFT JOIN users ON transactions.recorded_by = users.id
            WHERE $visibilityWhere AND $searchWhere
            ORDER BY transaction_date DESC, transactions.id DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $statusBadgeClass = [
        'approved' => 'success',
        'pending' => 'warning text-dark',
        'rejected' => 'danger',
    ];

    $html = '';
    foreach ($rows as $t) {
        $id = (int) $t['id'];
        $badgeClass = $statusBadgeClass[$t['status']] ?? 'secondary';

        $html .= '<tr>';
        $html .= '<td>' . esc(date('d M Y', strtotime($t['transaction_date']))) . '</td>';
        $html .= '<td>' . esc($t['category']) . '</td>';
        $html .= '<td><span class="badge bg-' . ($t['transaction_type'] === 'Income' ? 'success' : 'danger') . '">' . esc($t['transaction_type']) . '</span></td>';
        $html .= '<td>RWF ' . number_format((float) $t['amount'], 2) . '</td>';
        $html .= '<td>' . esc($t['description'] ?? '') . '</td>';
        $html .= '<td>' . esc($t['recorder_name'] ?? '—') . '</td>';
        $html .= '<td><span class="badge bg-' . $badgeClass . '">' . esc(ucfirst($t['status'])) . '</span>';
        if ($t['status'] === 'rejected' && !empty($t['rejection_reason'])) {
            $html .= ' <i class="bi bi-info-circle" title="' . esc($t['rejection_reason']) . '"></i>';
        }
        $html .= '</td>';

        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../transactions/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View</a> ';

        if ($isAdmin && $t['status'] === 'pending') {
            $html .= '<a href="../transactions/approve.php?id=' . $id . '" class="rm-btn rm-btn-success rm-btn-sm">Approve</a> ';
            $html .= '<button type="button" class="rm-btn rm-btn-danger rm-btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModalLive' . $id . '">Reject</button>';

            $html .= '<div class="modal fade" id="rejectModalLive' . $id . '" tabindex="-1"><div class="modal-dialog"><div class="modal-content">';
            $html .= '<form method="POST" action="../transactions/reject.php">';
            $html .= '<div class="modal-header"><h5 class="modal-title">Reject Transaction #' . $id . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>';
            $html .= '<div class="modal-body"><input type="hidden" name="id" value="' . $id . '"><label class="form-label small fw-semibold text-muted">Reason (optional)</label><textarea name="reason" class="form-control" rows="3"></textarea></div>';
            $html .= '<div class="modal-footer"><button type="button" class="rm-btn rm-btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="rm-btn rm-btn-danger">Reject</button></div>';
            $html .= '</form></div></div></div>';
        }

        if ($isAdmin) {
            $html .= '<a href="../transactions/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
            $html .= '<form method="POST" action="../transactions/delete.php" class="d-inline" onsubmit="return confirm(\'Delete this transaction?\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>';
            $html .= '</form>';
        }

        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function transactionsHeaderRow(): string
{
    return '<tr><th>Date</th><th>Category</th><th>Type</th><th>Amount</th><th>Description</th><th>Recorded By</th><th>Status</th><th>Action</th></tr>';
}

function renderEmployeesRows(mysqli $conn, string $like, int $limit): string
{
    $sql = "SELECT users.*, departments.name AS department_name
            FROM users
            LEFT JOIN departments ON users.department_id = departments.id
            WHERE users.names LIKE ? OR users.email LIKE ? OR users.role LIKE ?
            ORDER BY users.id ASC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $html .= '<tr>';
        $html .= '<td>' . $id . '</td>';
        $html .= '<td>' . esc($r['names']) . '</td>';
        $html .= '<td>' . esc($r['email']) . '</td>';
        $html .= '<td>' . esc(ucfirst($r['role'])) . '</td>';
        $html .= '<td>' . esc($r['department_name'] ?? '—') . '</td>';
        $html .= '<td>' . ($r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>') . '</td>';
        $html .= '<td>' . esc(date('d M Y', strtotime($r['created_at']))) . '</td>';
        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../users/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View</a> ';
        $html .= '<a href="../users/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
        $confirmMsg = $r['is_active']
            ? 'Deactivate this employee? Their history is kept but they will no longer be able to log in.'
            : 'Reactivate this employee? They will be able to log in again.';
        $html .= '<form method="POST" action="../users/toggle_status.php" class="d-inline" onsubmit="return confirm(\'' . esc($confirmMsg) . '\')">';
        $html .= '<input type="hidden" name="id" value="' . $id . '">';
        $html .= '<button type="submit" class="rm-btn ' . ($r['is_active'] ? 'rm-btn-secondary' : 'rm-btn-success') . ' rm-btn-sm">' . ($r['is_active'] ? 'Deactivate' : 'Reactivate') . '</button>';
        $html .= '</form> ';
        $html .= '<form method="POST" action="../users/delete_permanent.php" class="d-inline" onsubmit="return confirm(\'Permanently delete this employee? This cannot be undone. It only works if they have no attendance, tasks, sales, payroll, or leave history.\')">';
        $html .= '<input type="hidden" name="id" value="' . $id . '">';
        $html .= '<button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>';
        $html .= '</form>';
        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function employeesHeaderRow(): string
{
    return '<tr><th>ID</th><th>Full Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Created At</th><th>Action</th></tr>';
}

function renderLeaveRows(mysqli $conn, string $like, int $limit, $userId): string
{
    $sql = "SELECT * FROM leave_requests
            WHERE user_id = ? AND (leave_type LIKE ? OR reason LIKE ? OR status LIKE ?)
            ORDER BY created_at DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'isss', $userId, $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $statusClass = [
        'Pending' => 'secondary',
        'Manager Approved' => 'info',
        'Approved' => 'success',
        'Rejected' => 'danger',
    ];

    $html = '';
    foreach ($rows as $r) {
        $class = $statusClass[$r['status']] ?? 'secondary';
        $html .= '<tr>';
        $html .= '<td>' . esc($r['leave_type']) . '</td>';
        $html .= '<td>' . esc($r['start_date']) . ' &rarr; ' . esc($r['end_date']) . '</td>';
        $html .= '<td>' . esc($r['reason'] ?? '') . '</td>';
        $html .= '<td><span class="badge bg-' . $class . '">' . esc($r['status']) . '</span></td>';
        $html .= '<td>' . esc(date('d M Y', strtotime($r['created_at']))) . '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function leaveHeaderRow(): string
{
    return '<tr><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th>Submitted</th></tr>';
}

function renderProductsRows(mysqli $conn, string $like, int $limit, bool $isAdmin): string
{
    $lowStockThreshold = 5;

    $sql = "SELECT * FROM products
            WHERE product_name LIKE ? OR product_code LIKE ? OR item_type LIKE ?
            ORDER BY item_type, product_name ASC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    $rowNumber = 1;
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $isService = $r['item_type'] === 'Service';

        $html .= '<tr>';
        $html .= '<td>' . $rowNumber++ . '</td>';
        $html .= '<td><span class="badge bg-' . ($isService ? 'info text-dark' : 'primary') . '">' . esc($r['item_type']) . '</span></td>';
        $html .= '<td>' . esc($r['product_name']) . '</td>';
        $html .= '<td>' . esc($r['product_code']) . '</td>';

        if ($isService) {
            $html .= '<td><span class="text-muted">—</span></td>';
        } else {
            $html .= '<td>' . (int) $r['quantity'];
            if ($r['quantity'] <= $lowStockThreshold) {
                $html .= ' <span class="badge bg-warning text-dark ms-1">Low Stock</span>';
            }
            $html .= '</td>';
        }

        $html .= $isService ? '<td><span class="text-muted">—</span></td>' : '<td>' . esc($r['unit'] ?? 'Pieces') . '</td>';
        $html .= $isService ? '<td><span class="text-muted">—</span></td>' : '<td>RWF ' . number_format($r['buying_price'], 2) . '</td>';
        $html .= '<td>RWF ' . number_format($r['selling_price'], 2) . '</td>';
        $html .= '<td>' . ($r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>') . '</td>';

        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../products/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View</a> ';
        if ($isAdmin) {
            $html .= '<a href="../products/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
            if (!$isService) {
                $html .= '<a href="../products/restock.php?id=' . $id . '" class="rm-btn rm-btn-success rm-btn-sm"><i class="bi bi-box-arrow-in-down"></i> Restock</a> ';
            }
            $confirmMsg = $r['is_active'] ? 'Deactivate this?' : 'Reactivate this?';
            $html .= '<a href="../products/delete.php?id=' . $id . '" class="rm-btn ' . ($r['is_active'] ? 'rm-btn-danger' : 'rm-btn-success') . ' rm-btn-sm" onclick="return confirm(\'' . esc($confirmMsg) . '\')">' . ($r['is_active'] ? 'Deactivate' : 'Activate') . '</a>';
        }
        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function productsHeaderRow(): string
{
    return '<tr><th>No.</th><th>Type</th><th>Name</th><th>Code</th><th>Quantity</th><th>Unit</th><th>Buying Price</th><th>Selling Price</th><th>Status</th><th>Action</th></tr>';
}

function taskStatusBadgeHtml($status) {
    $map = [
        'Pending' => 'secondary',
        'Accepted' => 'info',
        'In Progress' => 'primary',
        'Completed' => 'warning text-dark',
        'Approved' => 'success',
        'Rejected' => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . esc($status) . '</span>';
}

function renderTasksRows(mysqli $conn, string $like, int $limit, ?string $role, $userId): string
{
    $where = '(tasks.title LIKE ? OR tasks.description LIKE ? OR tasks.status LIKE ?)';
    if ($role === 'Employee') {
        $where .= ' AND tasks.assigned_to = ' . (int) $userId;
    }

    $sql = "SELECT tasks.*, projects.project_name, users.names AS assignee_name
            FROM tasks
            INNER JOIN projects ON tasks.project_id = projects.id
            LEFT JOIN users ON tasks.assigned_to = users.id
            WHERE $where
            ORDER BY CASE WHEN tasks.status IN ('Approved', 'Rejected') THEN 1 ELSE 0 END, tasks.due_date ASC, tasks.created_at DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $canManage = in_array($role, ['Admin', 'Manager'], true);

    $html = '';
    foreach ($rows as $task) {
        $id = (int) $task['id'];
        $priorityClass = $task['priority'] === 'High' ? 'danger' : ($task['priority'] === 'Medium' ? 'warning text-dark' : 'secondary');

        $html .= '<tr>';
        $html .= '<td><strong>' . esc($task['title']) . '</strong><br><small class="text-muted">' . esc($task['description'] ?? '') . '</small></td>';
        $html .= '<td>' . esc($task['project_name']) . '</td>';
        $html .= '<td>' . esc($task['assignee_name'] ?? 'Unassigned') . '</td>';
        $html .= '<td><span class="badge bg-' . $priorityClass . '">' . esc($task['priority']) . '</span></td>';
        $html .= '<td>' . ($task['due_date'] ? esc(date('d M Y', strtotime($task['due_date']))) : '—') . '</td>';
        $html .= '<td>' . taskStatusBadgeHtml($task['status']) . '</td>';
        $html .= '<td>' . ($task['performance_score'] !== null ? (int) $task['performance_score'] . '/100' : '—') . '</td>';

        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../tasks/view.php?id=' . $id . '" class="btn btn-info btn-sm">View</a> ';

        if ($role === 'Employee' && (int) $task['assigned_to'] === (int) $userId) {
            if ($task['status'] === 'Pending') {
                $html .= '<a href="../tasks/accept.php?id=' . $id . '" class="btn btn-success btn-sm">Accept</a> ';
            } elseif ($task['status'] === 'Accepted') {
                $html .= '<a href="../tasks/start.php?id=' . $id . '" class="btn btn-primary btn-sm">Start Work</a> ';
            } elseif ($task['status'] === 'In Progress') {
                $html .= '<a href="../tasks/submit.php?id=' . $id . '" class="btn btn-warning btn-sm">Submit</a> ';
            }
        }

        if ($canManage) {
            if ($task['status'] === 'Completed') {
                $html .= '<a href="../tasks/review.php?id=' . $id . '" class="btn btn-success btn-sm">Review</a> ';
            }
            $html .= '<a href="../tasks/edit.php?id=' . $id . '" class="btn btn-warning btn-sm">Edit</a> ';
            $html .= '<form method="POST" action="../tasks/delete.php" class="d-inline" onsubmit="return confirm(\'Delete this task?\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="btn btn-danger btn-sm">Delete</button>';
            $html .= '</form>';
        }

        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function tasksHeaderRow(): string
{
    return '<tr><th>Task</th><th>Project</th><th>Assigned To</th><th>Priority</th><th>Due Date</th><th>Status</th><th>Score</th><th>Action</th></tr>';
}

/* ================= Build the replacement HTML for #pageResultsContainer =================
   Header + matching rows, wrapped exactly like the original markup around
   that module's table. */
$rowsHtml = '';
$html = '';

switch ($scope) {
    case 'attendance':
        $rowsHtml = renderAttendanceRows($conn, $like, 50, $canManageAttendance);
        if ($rowsHtml !== '') {
            // #pageResultsContainer IS the .table-responsive div on this page —
            // just replace its table, don't re-wrap it.
            $html = '<table class="table table-bordered table-hover bg-white mb-0">'
                . attendanceHeaderRow($canManageAttendance) . $rowsHtml . '</table>';
        }
        break;
    case 'customers':
        $rowsHtml = renderCustomersRows($conn, $like, 50);
        if ($rowsHtml !== '') {
            $html = '<table class="table table-bordered table-hover bg-white mb-0">'
                . customersHeaderRow() . $rowsHtml . '</table>';
        }
        break;
    case 'departments':
        $rowsHtml = renderDepartmentsRows($conn, $like, 50, $role);
        if ($rowsHtml !== '') {
            // #pageResultsContainer IS the overflow-x:auto div here too.
            $html = '<table class="table table-bordered table-hover bg-white mb-0">'
                . departmentsHeaderRow($role) . $rowsHtml . '</table>';
        }
        break;
    case 'notifications':
        $rowsHtml = renderNotificationsRows($conn, $like, 50);
        if ($rowsHtml !== '') {
            $html = '<table class="table table-bordered table-hover">'
                . notificationsHeaderRow() . $rowsHtml . '</table>';
        }
        break;
    case 'projects':
        $rowsHtml = renderProjectsRows($conn, $like, 50, $isAdminSession);
        if ($rowsHtml !== '') {
            // #pageResultsContainer IS the .table-responsive div on this page —
            // just replace its table, don't re-wrap it.
            $html = '<table class="table table-bordered table-hover bg-white mb-0">'
                . projectsHeaderRow() . $rowsHtml . '</table>';
        }
        break;
    case 'sales':
        $rowsHtml = renderSalesRows($conn, $like, 50, $role, $userId);
        if ($rowsHtml !== '') {
            $html = '<table class="table table-bordered table-hover bg-white mb-0">'
                . salesHeaderRow() . $rowsHtml . '</table>';
        }
        break;
    case 'transactions':
        $rowsHtml = renderTransactionsRows($conn, $like, 50, $isAdminSession, $currentUserId);
        if ($rowsHtml !== '') {
            $html = '<table class="table table-bordered table-hover bg-white mb-0">'
                . transactionsHeaderRow() . $rowsHtml . '</table>';
        }
        break;
    case 'employees':
        $rowsHtml = renderEmployeesRows($conn, $like, 50);
        if ($rowsHtml !== '') {
            $html = '<table class="table table-bordered table-hover bg-white mb-0">'
                . employeesHeaderRow() . $rowsHtml . '</table>';
        }
        break;
    case 'leave':
        $rowsHtml = renderLeaveRows($conn, $like, 50, $userId);
        if ($rowsHtml !== '') {
            $html = '<table class="table table-bordered table-hover bg-white mb-0">'
                . leaveHeaderRow() . $rowsHtml . '</table>';
        }
        break;
    case 'products':
        $rowsHtml = renderProductsRows($conn, $like, 50, $isAdminSession);
        if ($rowsHtml !== '') {
            // Note: this page's table uses different classes (no bg-white/mb-0)
            // than the others — matched exactly here.
            $html = '<table class="table table-bordered table-hover">'
                . productsHeaderRow() . $rowsHtml . '</table>';
        }
        break;
    case 'tasks':
        $rowsHtml = renderTasksRows($conn, $like, 50, $role, $userId);
        if ($rowsHtml !== '') {
            $html = '<table class="table table-bordered table-hover bg-white mb-0">'
                . tasksHeaderRow() . $rowsHtml . '</table>';
        }
        break;
}

echo json_encode(['empty' => $rowsHtml === '', 'html' => $html]);