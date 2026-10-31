<?php
/**
 * MY MOTIVE SaaS: which parts of the system a company's package includes.
 *
 * Each package (Dream, Hasty, ...) lists "features" in the platform database (packages.features).
 * A feature is a part of the system: "tasks", "loans", "payroll" ... This file knows which page belongs to which
 * feature. Loaded by includes/tenant.php on every signed-in page.
 *
 * Two safety rules:
 *   - A page that belongs to no known feature is refused (the door is closed unless we opened it).
 *   - Business 1 (your own business) has the "owner" package, which includes everything.
 */

require_once __DIR__ . '/platform.php';

/** Folder inside /modules/ -> the feature it belongs to. '*' = everyone. */
const MM_MODULE_FEATURE = [
    'dashboard'         => 'dashboard',
    'users'             => 'employees',
    'departments'       => 'employees',
    'attendance'        => 'attendance',
    'leave'             => 'leave',
    'projects'          => 'targets',            // "RM Targets"
    'tasks'             => 'tasks',
    'transactions'      => 'transactions',
    'categories'        => '*',
    'messages'          => '*', 
    'products'          => 'products',           // "Inventory"
    'purchases'         => 'products',
    'purchase_orders'   => 'products',
    'business_parties'  => 'business_parties',
    'customers'         => 'customers',
    'sales'             => 'sales',
    'loans'             => 'loans',
    'asset_management'  => 'assets',
    'financial_reports' => 'financial_reports',
    'payroll'           => 'payroll',
    'notifications'     => 'notifications',
    'sms'               => 'sms',
    'tools'             => 'tools',
    'search'            => '*',
    'support'           => '*',   // NEW: Support tickets to the platform team (every business, every package)
    'company'           => '*',   // the business profile page (it is limited to the Admin role inside the page)
    'announcements'     => '*', 
];

/** Pages inside modules/transactions/ that are the "RM Funds" screens (a feature of their own). */
const MM_FUND_PAGES = [
    'allocate_profit.php', 'capital_check.php', 'capital_delete.php', 'capital_edit.php',
    'capital_inflow.php', 'capital_reconciliation.php', 'fund_report_pdf.php', 'fund_reports.php',
];

/** Names shown to people. */
const MM_FEATURE_LABELS = [
    'dashboard' => 'Dashboard', 'employees' => 'Employees', 'attendance' => 'Attendance', 'leave' => 'Leave requests',
    'targets' => 'RM Targets', 'tasks' => 'Tasks', 'transactions' => 'Transactions', 'funds' => 'RM Funds',
    'products' => 'Inventory', 'customers' => 'Customers', 'business_parties' => 'Business parties', 'sales' => 'Sales',
    'loans' => 'Loans', 'assets' => 'Assets', 'financial_reports' => 'Financial reports', 'payroll' => 'Payroll',
    'notifications' => 'Notifications', 'sms' => 'SMS', 'tools' => 'Tools',
];

/**
 * The feature a page needs, '*' if anyone may open it, or null if the page is not inside /modules/ at all
 * (the login page and so on). A page inside an unknown module returns 'unmapped', which no package has.
 */
function mm_feature_for_script(string $scriptName): ?string
{
    if (!preg_match('#/modules/([A-Za-z0-9_]+)/([A-Za-z0-9_]+\.php)$#', $scriptName, $m)) {
        return null;
    }
    [, $module, $file] = $m;
    if ($module === 'transactions' && in_array($file, MM_FUND_PAGES, true)) {
        return 'funds';
    }
    return MM_MODULE_FEATURE[$module] ?? 'unmapped';
}

/** The package of a company: ['code', 'name', 'features' => [...]], or null if it has no active subscription. */
function platform_package_for_company(int $companyId): ?array
{
    $st = mysqli_prepare(platform_conn(), "SELECT p.code, p.name, p.features, p.sms_quota FROM subscriptions s JOIN packages p ON p.id = s.package_id WHERE s.company_id = ? AND s.status = 'active' ORDER BY s.id DESC LIMIT 1");
    mysqli_stmt_bind_param($st, 'i', $companyId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$row) {
        if ($companyId !== 1) {
            return null;
        }
        // Business 1 is your own business: a missing or expired subscription must never lock you out of your own business.
        $st = mysqli_prepare(platform_conn(), "SELECT code, name, features FROM packages WHERE code = 'owner' LIMIT 1");
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: ['code' => 'owner', 'name' => 'Owner (everything)', 'features' => json_encode(array_keys(MM_FEATURE_LABELS))];
    }
    $features = json_decode((string) $row['features'], true);
    return [
        'code'      => $row['code'],
        'name'      => $row['name'],
        'features'  => is_array($features) ? array_values($features) : [],
        'sms_quota' => isset($row['sms_quota']) && $row['sms_quota'] !== null ? (int) $row['sms_quota'] : null,   // null = no limit
    ];
}

/** The signed-in company's SMS limit per calendar month, or null when its package has no limit. */
function mm_sms_quota(): ?int
{
    $q = $GLOBALS['MM_PACKAGE']['sms_quota'] ?? null;
    return $q === null ? null : (int) $q;
}

/** True if the signed-in company's package includes this feature. */
function mm_has_feature(string $feature): bool
{
    return in_array($feature, $GLOBALS['MM_FEATURES'] ?? [], true);
}

function mm_package_name(): string
{
    return (string) ($GLOBALS['MM_PACKAGE']['name'] ?? '');
}

/** Removes the menu entries (links to ../module/page.php) that the package does not include. */
function mm_filter_menu(string $html): string
{
    return (string) preg_replace_callback(
        '~[ \t]*<a href="\.\./([A-Za-z0-9_]+)/([A-Za-z0-9_]+\.php)"[^>]*>.*?</a>[ \t]*\r?\n?~s',
        static function (array $m): string {
            $needed = mm_feature_for_script('/modules/' . $m[1] . '/' . $m[2]);
            return ($needed === null || $needed === '*' || mm_has_feature($needed)) ? $m[0] : '';
        },
        $html
    );
}

/**
 * For a business without the RM Funds feature the pages say "Capital Fund", never "RM Capital Fund".
 * It only touches ordinary web pages: a PDF, a spreadsheet or a JSON answer (they set their own Content-Type) is left alone.
 */
function mm_neutral_fund_words(string $output): string
{
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
            return $output;
        }
    }
    return (string) preg_replace(
        ['/\bRM (Capital|Business Operating|Future Plans|Emergency|Team Growth) Fund\b/', '/\bRM Funds\b/'],
        ['$1 Fund', 'Funds'],
        $output
    );
}

/** Shows the "not in your package" page and stops. */
function mm_package_denied(string $feature): void
{
    http_response_code(403);
    $featureLabel = MM_FEATURE_LABELS[$feature] ?? 'This page';
    $packageName = mm_package_name();
    include __DIR__ . '/package_denied.php';
    exit;
}

// ---------------------------------------------------------------- changing a company's package (command line and admin panel)

/** Gives a company a package. Changes the current subscription if there is one, otherwise starts a new one. */
function company_set_package(int $companyId, string $packageCode, string $actor): void
{
    $db = platform_conn();
    $st = mysqli_prepare($db, 'SELECT id, is_active FROM packages WHERE code = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 's', $packageCode);
    mysqli_stmt_execute($st);
    $package = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$package) {
        throw new RuntimeException('There is no package called ' . $packageCode . '.');
    }
    if (!platform_company_by_id($companyId)) {
        throw new RuntimeException('There is no such business.');
    }
    $packageId = (int) $package['id'];
    $st = mysqli_prepare($db, "UPDATE subscriptions SET package_id = ? WHERE company_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    mysqli_stmt_bind_param($st, 'ii', $packageId, $companyId);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) < 1) {
        $existing = mysqli_fetch_row(mysqli_query($db, 'SELECT COUNT(*) FROM subscriptions WHERE company_id = ' . $companyId . " AND status = 'active' AND package_id = " . $packageId))[0];
        if ((int) $existing === 0) {
            $start = new DateTimeImmutable('today');
            $startSql = $start->format('Y-m-d');
            $endSql = $start->modify('+1 month')->format('Y-m-d');
            $st = mysqli_prepare($db, "INSERT INTO subscriptions (company_id, package_id, billing_period, status, starts_at, ends_at) VALUES (?, ?, 'monthly', 'active', ?, ?)");
            mysqli_stmt_bind_param($st, 'iiss', $companyId, $packageId, $startSql, $endSql);
            mysqli_stmt_execute($st);
        }
    }
    platform_audit($companyId, $actor, 'package_set', $packageCode);
}