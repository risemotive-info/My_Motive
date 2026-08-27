<?php
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
function navActive($segment, $currentPath) {
    return str_contains($currentPath, $segment) ? 'active' : '';
}
$userName = $_SESSION['user_name'] ?? 'Admin';
$initials = strtoupper(substr($userName, 0, 1) . (strpos($userName, ' ') !== false ? substr($userName, strpos($userName, ' ') + 1, 1) : ''));
?>

<div class="sidebar">

    <h3>
        <img src="<?= BASE_URL ?>/assets/images/logo.jpg"
             alt="Rise Motive Logo"
             style="width:36px; height:36px; object-fit:contain; vertical-align:middle; margin-right:8px; border-radius:8px;">
        MY MOTIVE
    </h3>

    <a href="../dashboard/index.php" class="<?= navActive('dashboard', $currentPath) ?>">
        <i class="bi bi-house-door-fill"></i> Dashboard
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
        <i class="bi bi-box-seam"></i> RM Offerings
    </a>

    <a href="../customers/index.php" class="<?= navActive('customers', $currentPath) ?>">
        <i class="bi bi-person-lines-fill"></i> Customers
    </a>

    <a href="../sales/index.php" class="<?= navActive('sales', $currentPath) ?>">
        <i class="bi bi-cart-check-fill"></i> Sales
    </a>

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

    <div style="border-top:1px solid rgba(255,255,255,.08); margin:14px 10px 10px; padding-top:14px;">

        <div style="display:flex; align-items:center; gap:10px; padding:0 22px 12px;">
            <div style="width:34px; height:34px; border-radius:50%; background:var(--accent-blue); color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600; flex-shrink:0;">
                <?= htmlspecialchars($initials ?: 'A', ENT_QUOTES, 'UTF-8'); ?>
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
            <a href="../notifications/index.php" class="topbar-icon-btn">
                <i class="bi bi-bell"></i>
                <span class="topbar-badge">3</span>
            </a>
            <div class="dropdown">
    <div class="topbar-org dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="cursor:pointer;">
        <div class="topbar-org-avatar">
            <i class="bi bi-building"></i>
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
                    resultsContainer.innerHTML = '<div class="text-center text-muted py-5">No matches found for "' + query + '".</div>';
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