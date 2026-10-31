<?php
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
function navActive($segment, $currentPath) {
    return str_contains($currentPath, $segment) ? 'active' : '';
}
$userName = $_SESSION['user_name'] ?? 'Admin';
$initials = strtoupper(substr($userName, 0, 1) . (strpos($userName, ' ') !== false ? substr($userName, strpos($userName, ' ') + 1, 1) : ''));
// Profile photo (optional). Falls back to the icon when none is set.
$userPhotoUrl = null;
try {
    $photoUserId = current_user_id();
    $photoStmt = mysqli_prepare($conn, 'SELECT photo FROM users WHERE id = ?');
    mysqli_stmt_bind_param($photoStmt, 'i', $photoUserId);
    mysqli_stmt_execute($photoStmt);
    $photoRow = mysqli_fetch_assoc(mysqli_stmt_get_result($photoStmt));
    if (!empty($photoRow['photo'])) {
        $userPhotoUrl = BASE_URL . '/assets/uploads/users/' . rawurlencode(basename($photoRow['photo']));
    }
} catch (Throwable $e) {
    $userPhotoUrl = null; // photo column not added yet
}
?>

<!-- NEW: system-wide colour for "warning" badges (Medium priority, Partially Paid, Pending, Low Stock, Late ...).
     The yellow is replaced by grey with white text. To use another colour, change --rm-warning-badge only. -->
<style>
    :root { --rm-warning-badge: #6B7280; }
    .badge.bg-warning,
    .badge.bg-warning.text-dark { background-color: var(--rm-warning-badge) !important; color: #FFFFFF !important; }
</style>

<div class="sidebar">

    <h3>
        <img src="<?= htmlspecialchars(company_profile()['logo_url'], ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars(company_profile()['name'], ENT_QUOTES, 'UTF-8') ?> logo"
             style="width:36px; height:36px; object-fit:contain; vertical-align:middle; margin-right:8px; border-radius:8px;">
        <span style="display:inline-block; vertical-align:middle; max-width:150px; font-size:15px; line-height:1.2; white-space:normal;"><?= htmlspecialchars(company_profile()['name'], ENT_QUOTES, 'UTF-8') ?></span>
    </h3>

    <?php ob_start(); // PACKAGES: menu entries the package does not include are removed below ?>
    <a href="../dashboard/index.php" class="<?= navActive('dashboard', $currentPath) ?>">
        <i class="bi bi-house-door-fill"></i> Dashboard
    </a>

    <?php
    // NEW (Task 1): Internal Communication, with the number of unread messages.
    $commUnread = 0;
    try { require_once __DIR__ . '/comm_helpers.php'; $commUnread = comm_unread_total($conn, (int) current_user_id(), current_user_role()); }
    catch (Throwable $commError) { $commUnread = 0; }
    ?>
    <a href="../messages/index.php" class="<?= navActive('/messages/', $currentPath) ?>">
        <i class="bi bi-chat-dots-fill"></i> Messages
        <?php if ($commUnread > 0) { ?><span class="badge rounded-pill ms-1" style="background:#E24B4A;"><?= $commUnread > 99 ? '99+' : $commUnread; ?></span><?php } ?>
    </a>

    <?php if (current_user_role() === 'Admin') { ?>
    <a href="../departments/index.php" class="<?= navActive('departments', $currentPath) ?>">
        <i class="bi bi-building"></i> Departments
    </a>
    <?php } ?>

    <?php if (current_user_role() === 'Admin') { ?>
    <a href="../users/index.php" class="<?= navActive('users', $currentPath) ?>">
        <i class="bi bi-people-fill"></i> Employees
    </a>
    <?php } ?>

      <?php if (current_user_role() === 'Admin') { ?>
    <!-- NEW (Task 3): Admin-managed categories (expense categories, asset types, leave types ...) -->
    <a href="../categories/index.php" class="<?= navActive('/categories/', $currentPath) ?>">
        <i class="bi bi-tags-fill"></i> Categories
    </a>
    <a href="../company/profile.php" class="<?= navActive('company', $currentPath) ?>">
        <i class="bi bi-sliders"></i> Business profile
    </a>
    <?php } ?>
    <a href="../attendance/index.php" class="<?= navActive('attendance', $currentPath) ?>">
        <i class="bi bi-calendar-check"></i> Attendance
    </a>

    <a href="../leave/index.php" class="<?= navActive('leave', $currentPath) ?>">
        <i class="bi bi-calendar-plus"></i> Leave Requests
    </a>

    <a href="../projects/index.php" class="<?= navActive('projects', $currentPath) ?>">
        <i class="bi bi-folder-fill"></i> RM Targets
    </a>

    <a href="../tasks/index.php" class="<?= navActive('tasks', $currentPath) ?>">
        <i class="bi bi-check2-square"></i> Tasks
    </a>

    <a href="../transactions/index.php" class="<?= navActive('transactions', $currentPath) ?>">
        <i class="bi bi-cash-stack"></i> Transactions
    </a>

    <a href="../products/index.php" class="<?= navActive('products', $currentPath) ?>">
        <i class="bi bi-box-seam"></i> Inventory
    </a>

    <?php if (in_array(current_user_role(), ['Admin', 'Manager'], true)) { ?>
    <a href="../purchase_orders/index.php" class="<?= navActive('purchase_orders', $currentPath) ?>">
        <i class="bi bi-clipboard-check"></i> Purchase Orders
    </a>
    <?php } ?>

    <?php if (in_array(current_user_role(), ['Admin', 'Manager'], true)) { ?>
    <a href="../business_parties/index.php" class="<?= navActive('business_parties', $currentPath) ?>">
        <i class="bi bi-briefcase-fill"></i> Business Parties
    </a>
    <?php } ?>

    <a href="../customers/index.php" class="<?= navActive('customers', $currentPath) ?>">
        <i class="bi bi-person-lines-fill"></i> Customers
    </a>

    <a href="../sales/index.php" class="<?= navActive('sales', $currentPath) ?>">
        <i class="bi bi-cart-check-fill"></i> Sales
    </a>
    <a href="../loans/index.php" class="<?= navActive('loans', $currentPath) ?>">
    <i class="bi bi-bank"></i> Loans
     </a>
     <?php if (current_user_role() === 'Admin') { ?>
      <a href="../asset_management/index.php" class="<?= navActive('asset_management', $currentPath) ?>">
      <i class="bi bi-building-gear"></i> Assets
     </a>
   <?php } ?>
    <?php if (in_array(current_user_role(), ['Admin', 'Manager'], true)) { ?>
    <!-- NEW: Financial Reports module (Profit & Loss, Balance Sheet, ...) -->
    <a href="../financial_reports/index.php" class="<?= navActive('financial_reports', $currentPath) ?>">
        <i class="bi bi-graph-up-arrow"></i> Financial Reports
    </a>
    <?php } ?>

    <?php if (current_user_role() === 'Admin') { ?>
    <a href="../payroll/index.php" class="<?= navActive('payroll', $currentPath) ?>">
        <i class="bi bi-wallet2"></i> Payroll
    </a>
    <?php } else { ?>
    <a href="../payroll/my.php" class="<?= navActive('payroll', $currentPath) ?>">
        <i class="bi bi-wallet2"></i> My Payslips
    </a>
    <?php } ?>

    <a href="../notifications/index.php" class="<?= navActive('notifications', $currentPath) ?>">
        <i class="bi bi-bell-fill"></i> Notifications
    </a>

    <?php
    // NEW (Support): tickets to the MY MOTIVE team, with the number of answers not read yet.
    $supportUnread = 0;
    try {
        if (is_file(__DIR__ . '/support_helpers.php')) { require_once __DIR__ . '/support_helpers.php'; }
        if (function_exists('support_business_unread')) { $supportUnread = (int) support_business_unread(); }
    } catch (Throwable $supportError) { $supportUnread = 0; }
    ?>
    <a href="../support/index.php" class="<?= navActive('/support/', $currentPath) ?>">
        <i class="bi bi-question-circle-fill"></i> Support
        <?php if ($supportUnread > 0) { ?><span class="badge rounded-pill ms-1" style="background:#E24B4A;"><?= $supportUnread > 99 ? '99+' : $supportUnread; ?></span><?php } ?>
    </a>

   <?php // NEW: Announcements from the MY MOTIVE team, with a red number for the ones not read yet
    $announceNew = 0;
    try {
        require_once __DIR__ . '/announcement_helpers.php';
        $announceNew = announcement_unread_count((int) mm_company_id(), (int) current_user_id(), current_user_role() === 'Admin');
    } catch (Throwable $e) {
    } ?>
    <a href="../announcements/index.php" class="<?= navActive('announcements', $currentPath) ?>">
        <i class="bi bi-megaphone-fill"></i> Announcements<?= $announceNew > 0 ? ' <span class="badge text-bg-danger ms-1">' . (int) $announceNew . '</span>' : ''; ?>
    </a>

    <?php echo mm_filter_menu(ob_get_clean()); ?>

    <div style="border-top:1px solid rgba(255,255,255,.08); margin:14px 10px 10px; padding-top:14px;">

        <div style="display:flex; align-items:center; gap:10px; padding:0 22px 12px;">
            <div style="width:34px; height:34px; border-radius:50%; background:var(--accent-blue); color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600; flex-shrink:0; overflow:hidden;">
            <?php if ($userPhotoUrl) { ?>
            <img src="<?= htmlspecialchars($userPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:100%; height:100%; object-fit:cover; display:block;">
            <?php } else { ?>
           <?= htmlspecialchars($initials ?: 'A', ENT_QUOTES, 'UTF-8'); ?>
          <?php } ?>
               </div>
            <div style="min-width:0;">
                <div style="color:#fff; font-size:13px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div style="color:rgba(255,255,255,.5); font-size:11px;">Signed in</div>
            </div>
        </div>

        <a href="../../logout.php" style="color:rgba(255,255,255,.6);">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>

    </div>

</div>

<div class="content">

    <div class="topbar">
        <button class="menu-toggle-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
            <i class="bi bi-list"></i>
        </button>
        <?php $searchScope = $pageSearchScope ?? 'all'; ?>
        <form class="topbar-search" method="GET" action="../search/index.php" id="topbarSearchForm" autocomplete="off">
            <i class="bi bi-search"></i>
            <input type="hidden" name="scope" id="topbarSearchScope" value="<?= htmlspecialchars($searchScope, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="q" id="topbarSearchInput" placeholder="Search anything..." value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
        </form>
        <div class="topbar-icons">
            <?php
            $notifUserId = current_user_id();

            $unreadStatement = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0');
            mysqli_stmt_bind_param($unreadStatement, 'i', $notifUserId);
            mysqli_stmt_execute($unreadStatement);
            $unreadCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($unreadStatement))['c'];

            $recentStatement = mysqli_prepare($conn, 'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 6');
            mysqli_stmt_bind_param($recentStatement, 'i', $notifUserId);
            mysqli_stmt_execute($recentStatement);
            $recentNotifications = mysqli_stmt_get_result($recentStatement);
            ?>
            <div class="dropdown">
                <a href="#" class="topbar-icon-btn" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-bell"></i>
                    <?php if ($unreadCount > 0) { ?>
                        <span class="topbar-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                    <?php } ?>
                </a>
                <div class="dropdown-menu dropdown-menu-end p-0 shadow" style="width:320px; max-height:400px; overflow-y:auto;">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom gap-2">
                        <strong class="small">Notifications<?= $unreadCount > 0 ? ' <span class="badge bg-danger">' . $unreadCount . ' new</span>' : ''; ?></strong>
                        <span class="d-flex align-items-center gap-2">
                            <?php if ($unreadCount > 0) { ?>
                            <!-- NEW: mark all as read, from the bell menu -->
                            <form method="POST" action="../notifications/action.php" class="d-inline m-0">
                                <input type="hidden" name="action" value="mark_all">
                                <button type="submit" class="btn btn-link btn-sm p-0 small text-decoration-none">Mark all as read</button>
                            </form>
                            <?php } ?>
                            <a href="../notifications/index.php" class="small">View all</a>
                        </span>
                    </div>
                    <?php if (mysqli_num_rows($recentNotifications) === 0) { ?>
                        <div class="text-muted small text-center py-3">No notifications yet.</div>
                    <?php } ?>
                    <?php while ($n = mysqli_fetch_assoc($recentNotifications)) { ?>
                        <a href="../notifications/open.php?id=<?= (int) $n['id']; ?>"
                           class="dropdown-item py-2" style="white-space:normal; <?= $n['is_read'] ? '' : 'background:#EEF3FF; border-left:3px solid #1E2FE0;'; ?>">
                            <!-- CHANGED: unread notifications stand out (blue background, bar and dot) -->
                            <div class="<?= $n['is_read'] ? 'fw-normal' : 'fw-bold'; ?> small">
                                <?php if (!$n['is_read']) { ?><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#1E2FE0;margin-right:6px;"></span><?php } ?><?= htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-muted small"><?= htmlspecialchars(mb_strimwidth($n['message'], 0, 80, '…'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-muted" style="font-size:11px;"><?= date('d M, H:i', strtotime($n['created_at'])); ?></div>
                        </a>
                    <?php } ?>
                </div>
            </div>
            <div class="dropdown">
        <div class="topbar-org dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="cursor:pointer;">
        <div class="topbar-org-avatar">
       <?php if ($userPhotoUrl) { ?>
       <img src="<?= htmlspecialchars($userPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:100%; height:100%; object-fit:cover; display:block;">
        <?php } else { ?>
        <i class="bi bi-building"></i>
        <?php } ?>
        </div>
        <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Account', ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="../users/profile.php"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="../../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
    </ul>
</div>
        </div>
    </div>

<!-- NEW: every long dropdown (8+ choices) becomes a type-to-search box, on every page -->
<script src="<?= BASE_URL ?>/includes/searchable_select.js?v=1"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Live-search: types into the topbar box, filters the CURRENT page's own
    // table in place. No dropdown, no popup — just the table you're already
    // looking at, narrowed down to matches as you type.
    //
    // A module page opts in by wrapping its listing table in
    // <div id="pageResultsContainer"> ... </div>, and (optionally) wrapping
    // its pagination in <div id="pageResultsPagination"> ... </div>.
    // Pages that haven't been wired up yet simply don't have this element,
    // so typing does nothing live there — Enter still runs the full search.
    //
    // This has to wait for DOMContentLoaded: this script sits in sidebar.php,
    // which is included near the TOP of every page, before the module's own
    // table markup (output further down by e.g. departments/index.php) has
    // even reached the browser yet. Without waiting, getElementById would
    // always return null.

    const input = document.getElementById('topbarSearchInput');
    const scopeField = document.getElementById('topbarSearchScope');
    const resultsContainer = document.getElementById('pageResultsContainer');
    const paginationContainer = document.getElementById('pageResultsPagination');

    if (!input || !resultsContainer) return;

    resultsContainer.style.transition = 'opacity 0.1s ease';

    const originalHTML = resultsContainer.innerHTML;
    let debounceTimer = null;
    let currentRequest = null;

    function restoreOriginal() {
        resultsContainer.innerHTML = originalHTML;
        if (paginationContainer) {
            paginationContainer.style.display = '';
        }
    }

    function runSearch(query) {
        if (currentRequest) currentRequest.abort();
        const controller = new AbortController();
        currentRequest = controller;

        if (paginationContainer) {
            paginationContainer.style.display = 'none';
        }

        resultsContainer.style.opacity = '0.5';

        fetch('../search/live.php?q=' + encodeURIComponent(query) + '&scope=' + encodeURIComponent(scopeField.value), {
            signal: controller.signal
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                resultsContainer.style.opacity = '1';
                if (data.empty) {
                    // CHANGED: the typed text is inserted as plain text, never as HTML. Before, typing
                    // something like <img src=x onerror=...> in the search box ran it as code.
                    const msg = document.createElement('div');
                    msg.className = 'text-center text-muted py-5';
                    msg.textContent = 'No matches found for "' + query + '".';
                    resultsContainer.innerHTML = '';
                    resultsContainer.appendChild(msg);
                } else {
                    resultsContainer.innerHTML = data.html;
                }
            })
            .catch(function (err) {
                if (err.name !== 'AbortError') {
                    resultsContainer.style.opacity = '1';
                    resultsContainer.innerHTML = '<div class="text-center text-muted py-5">Search failed. Please try again.</div>';
                }
            });
    }

    input.addEventListener('input', function () {
        const query = input.value.trim();
        clearTimeout(debounceTimer);

        if (query.length === 0) {
            resultsContainer.style.opacity = '1';
            restoreOriginal();
            return;
        }

        // Fire almost immediately — just enough delay to avoid firing twice
        // for the same keystroke, not enough to feel like a wait.
        debounceTimer = setTimeout(function () {
            runSearch(query);
        }, 120);
    });

    // Live search already updates this table as you type, so there's no
    // reason to send Enter off to the old separate "search everything" page
    // (which isn't scoped to this module and can show unrelated matches).
    const form = document.getElementById('topbarSearchForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
        });
    }
});
</script>