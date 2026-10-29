<?php
/**
 * NEW FILE: includes/access_control.php
 * Task II: centralized Roles & Permissions.
 *
 *   Permission  = "module.action", e.g. "sales.create", "transactions.approve", "loans.executive_delete".
 *   Role        = a reusable set of permissions (Cashier, Store Keeper ...). An employee can have several.
 *   Overrides   = per employee: GRANT (adds one permission) or DENY (removes one; a DENY always wins).
 *   Effective   = (union of the employee's roles) + grants - denies.
 *
 *   Every page is checked on the server (access_enforce_page(), called from config/db.php): the page's
 *   folder is the module, its file name the action. Direct URLs and hand-sent forms are refused.
 *   The existing role checks (require_role) stay in place: permissions only ever narrow access further.
 *
 *   Day one changes nothing: three system roles (Administrator, Manager, Employee) hold every permission
 *   (except the executive ones) and are given to each employee from their current role.
 */

/** module => [label, [actions]]. 'executive_delete' and 'manage' are high-risk. */
function access_catalog(): array
{
    $std = ['view', 'create', 'edit', 'approve', 'delete', 'reports'];
    return [
        'dashboard'         => ['Dashboard', ['view']],
        'departments'       => ['Departments', ['view', 'create', 'edit', 'delete']],
        'users'             => ['Employees', ['view', 'create', 'edit', 'delete']],
        'attendance'        => ['Attendance', ['view', 'create', 'edit', 'approve', 'delete', 'reports']],
        'leave'             => ['Leave Requests', ['view', 'create', 'approve']],
        'projects'          => ['RM Targets', ['view', 'create', 'edit', 'delete']],
        'tasks'             => ['Tasks', $std],
        'transactions'      => ['Transactions', $std],
        'funds'             => ['RM Funds (capital, allocations, fund reports)', ['view', 'manage']],
        'categories'        => ['Categories', ['manage']],
        'products'          => ['RM Offerings / Inventory', ['view', 'create', 'edit', 'delete', 'reports']],
        'purchases'         => ['Purchase History', ['view']],
        'purchase_orders'   => ['Purchase Orders', ['view', 'create', 'edit', 'approve', 'delete', 'reports']],
        'business_parties'  => ['Business Parties', ['view', 'create', 'edit', 'delete', 'reports']],
        'customers'         => ['Customers', ['view', 'create', 'edit', 'delete', 'reports']],
        'sales'             => ['Sales', $std],
        'loans'             => ['Loans', ['view', 'create', 'edit', 'delete', 'reports', 'executive_delete']],
        'asset_management'  => ['Assets', ['view', 'create', 'edit', 'delete', 'reports', 'executive_delete']],
        'financial_reports' => ['Financial Reports (sensitive)', ['view']],
        'payroll'           => ['Payroll (sensitive)', ['view', 'create', 'edit', 'delete', 'reports']],
        'notifications'     => ['Notifications', ['view']],
        'messages'          => ['Messages (internal communication)', ['view', 'create']],
        'sms'               => ['SMS', ['view', 'create']],
        'tools'             => ['Tools (database migrations)', ['manage']],
        'access'            => ['Roles & Permissions', ['manage']],
    ];
}

const ACCESS_ACTION_LABELS = [
    'view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'approve' => 'Approve / reject', 'delete' => 'Delete / cancel',
    'reports' => 'Reports', 'manage' => 'Manage', 'executive_delete' => 'Executive delete (fully paid / processed)',
];
/** Permissions only the Managing Director holds at the start (not in any system role). */
const ACCESS_EXECUTIVE = ['loans.executive_delete', 'asset_management.executive_delete'];

function access_all_permissions(): array
{
    $out = [];
    foreach (access_catalog() as $m => [$label, $actions]) { foreach ($actions as $a) { $out[] = $m . '.' . $a; } }
    return $out;
}

/** High-risk: deletions, approvals, payroll, funds, financial reports, access management, executive. */
function access_is_high_risk(string $perm): bool
{
    [$m, $a] = explode('.', $perm) + [1 => ''];
    return in_array($a, ['delete', 'approve', 'manage', 'executive_delete'], true)
        || in_array($m, ['payroll', 'funds', 'financial_reports', 'access'], true);
}

/* ------------------------------------------------------------------ storage */

function access_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS acl_roles (
        id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(80) NOT NULL, description VARCHAR(255) NULL,
        system_key VARCHAR(20) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS acl_role_permissions (
        role_id INT(11) NOT NULL, permission VARCHAR(60) NOT NULL, PRIMARY KEY (role_id, permission)) ENGINE=InnoDB');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS acl_user_roles (
        user_id INT(11) NOT NULL, role_id INT(11) NOT NULL, PRIMARY KEY (user_id, role_id)) ENGINE=InnoDB');
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS acl_user_overrides (
        user_id INT(11) NOT NULL, permission VARCHAR(60) NOT NULL, effect ENUM('grant','deny') NOT NULL,
        PRIMARY KEY (user_id, permission)) ENGINE=InnoDB");
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS acl_audit (
        id INT(11) NOT NULL AUTO_INCREMENT, actor_id INT(11) NULL, target_user_id INT(11) NULL, role_id INT(11) NULL,
        action VARCHAR(40) NOT NULL, details TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY target_user_id (target_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // First run: the three system roles (same access as today) and each employee's role from their current one.
    if ((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM acl_roles'))[0] === 0) {
        $everything = array_values(array_diff(access_all_permissions(), ACCESS_EXECUTIVE));
        foreach (['admin' => 'Administrator', 'manager' => 'Manager', 'employee' => 'Employee'] as $key => $name) {
            $s = mysqli_prepare($conn, 'INSERT INTO acl_roles (name, description, system_key) VALUES (?, ?, ?)');
            $desc = 'Starting role: same access as the "' . $name . '" account type had before Roles & Permissions. '
                  . 'The pages\' own role rules still apply on top.';
            mysqli_stmt_bind_param($s, 'sss', $name, $desc, $key);
            mysqli_stmt_execute($s);
            $rid = (int) mysqli_insert_id($conn);
            $p = mysqli_prepare($conn, 'INSERT INTO acl_role_permissions (role_id, permission) VALUES (?, ?)');
            foreach ($everything as $perm) {
                if ($key !== 'admin' && $perm === 'access.manage') { continue; } // only Administrators manage access
                mysqli_stmt_bind_param($p, 'is', $rid, $perm);
                mysqli_stmt_execute($p);
            }
            mysqli_query($conn, "INSERT IGNORE INTO acl_user_roles (user_id, role_id) SELECT id, $rid FROM users WHERE LOWER(role) = '$key'");
        }
        // The Managing Director: the first Administrator account gets the executive permissions.
        $md = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM users WHERE LOWER(role) = 'admin' AND is_active = 1 ORDER BY id LIMIT 1"));
        if ($md) {
            foreach (ACCESS_EXECUTIVE as $perm) {
                $g = mysqli_prepare($conn, "INSERT IGNORE INTO acl_user_overrides (user_id, permission, effect) VALUES (?, ?, 'grant')");
                $mid = (int) $md[0];
                mysqli_stmt_bind_param($g, 'is', $mid, $perm);
                mysqli_stmt_execute($g);
            }
        }
        access_log($conn, null, null, null, 'setup', 'Roles & Permissions started: Administrator, Manager and Employee roles created with the same access as before; executive delete given to the first Administrator account.');
    }
    // An employee added later without any role gets the starting role of their account type.
    mysqli_query($conn, "INSERT IGNORE INTO acl_user_roles (user_id, role_id)
        SELECT u.id, r.id FROM users u JOIN acl_roles r ON r.system_key = LOWER(u.role) COLLATE utf8mb4_general_ci
        WHERE NOT EXISTS (SELECT 1 FROM acl_user_roles x WHERE x.user_id = u.id)");
}

function access_log(mysqli $conn, ?int $actor, ?int $target, ?int $roleId, string $action, string $details): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO acl_audit (actor_id, target_user_id, role_id, action, details) VALUES (?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iiiss', $actor, $target, $roleId, $action, $details);
    mysqli_stmt_execute($s);
}

/** ['roles' => [id => name], 'from_roles' => [perm], 'grants' => [perm], 'denies' => [perm], 'effective' => [perm]] */
function access_user_breakdown(mysqli $conn, int $userId, ?array $roleIds = null, ?array $grants = null, ?array $denies = null): array
{
    access_ensure($conn);
    if ($roleIds === null) {
        $roleIds = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT role_id FROM acl_user_roles WHERE user_id = ' . $userId), MYSQLI_ASSOC), 'role_id'));
    }
    if ($grants === null || $denies === null) {
        $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT permission, effect FROM acl_user_overrides WHERE user_id = ' . $userId), MYSQLI_ASSOC);
        $grants = $grants ?? array_column(array_filter($rows, function ($r) { return $r['effect'] === 'grant'; }), 'permission');
        $denies = $denies ?? array_column(array_filter($rows, function ($r) { return $r['effect'] === 'deny'; }), 'permission');
    }
    $roles = [];
    $fromRoles = [];
    if ($roleIds) {
        $in = implode(',', array_map('intval', $roleIds));
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, name FROM acl_roles WHERE id IN ($in)"), MYSQLI_ASSOC) as $r) { $roles[(int) $r['id']] = $r['name']; }
        $fromRoles = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT permission FROM acl_role_permissions WHERE role_id IN ($in)"), MYSQLI_ASSOC), 'permission');
    }
    $effective = array_values(array_diff(array_unique(array_merge($fromRoles, $grants)), $denies));
    sort($effective);
    return ['roles' => $roles, 'from_roles' => $fromRoles, 'grants' => $grants, 'denies' => $denies, 'effective' => $effective];
}

/** Does the signed-in employee have this permission? (cached for the request) */
function access_can(string $perm, ?mysqli $conn = null): bool
{
    static $cache = [];   // per user, for the request
    $conn = $conn ?? ($GLOBALS['conn'] ?? null);
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if (!$conn instanceof mysqli || $uid <= 0) { return false; }
    if (!isset($cache[$uid])) {
        try { $cache[$uid] = array_flip(access_user_breakdown($conn, $uid)['effective']); }
        catch (Throwable $e) { error_log('access_can: ' . $e->getMessage()); $cache[$uid] = []; }
    }
    return isset($cache[$uid][$perm]);
}

/** The permission a page needs: '*' = everyone signed in, null = not a module page. */
function access_page_permission(string $script): ?string
{
    if (!preg_match('#/modules/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)\.php$#', $script, $m)) { return null; }
    [, $module, $file] = $m;
    $f = strtolower($file);

    // Pages anyone signed in may open (they show only the person's own data, or are general).
    $open = ['dashboard/index', 'search/index', 'search/live', 'users/profile', 'payroll/my', 'payroll/payslip',
             'notifications/index', 'notifications/view', 'notifications/open', 'notifications/action', 'notifications/mark_read', 'notifications/delete',
             'leave/index', 'leave/create', 'leave/view', 'company/profile'];
    if (in_array($module . '/' . $f, $open, true) || $module === 'search') { return '*'; }

    // Pages with their own permission.
    if ($module === 'transactions') {
        if (preg_match('/^(capital_|allocate_profit|fund_report)/', $f)) {
            return preg_match('/^fund_report/', $f) ? 'funds.view' : 'funds.manage';
        }
        if ($f === 'audit' || $f === 'delete_confirm') { return 'transactions.delete'; }
        if ($f === 'get_fund_balance') { return 'transactions.create'; }
    }
    if ($module === 'access') { return 'access.manage'; }
    if ($module === 'categories') { return 'categories.manage'; }
    if ($module === 'tools') { return 'tools.manage'; }
    if ($module === 'messages') { return $f === 'create' ? 'messages.create' : 'messages.view'; }
    if (($module === 'loans' || $module === 'asset_management') && strpos($f, 'executive') !== false) { return $module . '.executive_delete'; }
    if ($module === 'asset_management' && in_array($f, ['valuation', 'valuation_evidence', 'refund_receive', 'sync_capital_fund'], true)) { return 'asset_management.edit'; }
    if ($module === 'sales' && in_array($f, ['ledger', 'invoice', 'receipt'], true)) { return 'sales.view'; }
    if ($module === 'sales' && in_array($f, ['pay_credit', 'record_payment'], true)) { return 'sales.create'; }
    if ($module === 'sales' && in_array($f, ['category_profit', 'category_profit_pdf', 'digital_payments'], true)) { return 'sales.reports'; }
    if ($module === 'customers' && preg_match('/^reward/', $f)) { return in_array($f, ['rewards', 'reward_evidence'], true) ? 'customers.view' : 'customers.edit'; }

    // Everything else: the module = folder, the action = file name.
    $catalog = access_catalog();
    if (!isset($catalog[$module])) { return '*'; } // a module this file does not know yet: the page's own role check applies
    $actions = $catalog[$module][1];
    $action = 'view';
    if (strpos($f, 'request_') === 0) { $action = 'edit'; } // e.g. Request Cancel: asking, not deciding
    elseif (preg_match('/approve|reject|review|accept/', $f)) { $action = 'approve'; }
    elseif (preg_match('/delete|cancel|deactivate|dispose|remove|undo/', $f)) { $action = 'delete'; }
    elseif (preg_match('/edit|update/', $f)) { $action = 'edit'; }
    elseif (preg_match('/report|pdf|export|statement|print/', $f)) { $action = 'reports'; }
    elseif (preg_match('/create|new|add|restock|record|pay|submit|start|compose|send/', $f)) { $action = 'create'; }
    if (!in_array($action, $actions, true)) { $action = in_array('view', $actions, true) ? 'view' : $actions[0]; }
    return $module . '.' . $action;
}

/** Refuses the current page when the signed-in employee lacks its permission. Called from config/db.php. */
function access_enforce_page(mysqli $conn): void
{
    if (empty($_SESSION['user_id'])) { return; }
    $perm = access_page_permission((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($perm === null || $perm === '*') { return; }
    try { access_ensure($conn); } catch (Throwable $e) { error_log('access_ensure: ' . $e->getMessage()); return; }
    if (!access_can($perm, $conn)) {
        http_response_code(403);
        $GLOBALS['ACCESS_DENIED_PERMISSION'] = $perm;
        if (is_file(__DIR__ . '/access_denied.php')) { include __DIR__ . '/access_denied.php'; }
        else { echo 'You do not have permission to open this page (' . htmlspecialchars($perm, ENT_QUOTES, 'UTF-8') . ').'; }
        exit;
    }
}

/** Removes sidebar links to pages the employee may not open. */
function access_filter_menu(string $html): string
{
    return (string) preg_replace_callback(
        '~[ \t]*<a href="\.\./([A-Za-z0-9_]+)/([A-Za-z0-9_]+\.php)"[^>]*>.*?</a>[ \t]*\r?\n?~s',
        static function (array $m): string {
            $perm = access_page_permission('/modules/' . $m[1] . '/' . $m[2]);
            return ($perm === null || $perm === '*' || access_can($perm)) ? $m[0] : '';
        },
        $html
    );
}

function access_label(string $perm): string
{
    [$m, $a] = explode('.', $perm) + [1 => ''];
    return (access_catalog()[$m][0] ?? $m) . ': ' . (ACCESS_ACTION_LABELS[$a] ?? $a);
}