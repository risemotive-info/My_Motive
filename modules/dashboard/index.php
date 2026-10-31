<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require_once '../../includes/fund_helpers.php';
require_once '../../includes/stock_rules.php'; // NEW: the same "low stock" rule as Purchase Orders
include '../../includes/header.php';
include '../../includes/sidebar.php';

function scalarQuery($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_row($result);
    return $row[0] ?? 0;
}

// CHANGED (spec section 14): money figures count APPROVED transactions only. Before, pending,
// rejected and deleted transactions were added to this month's income and expenses.
$approvedThisMonth = "status = 'approved' AND YEAR(transaction_date) = YEAR(CURDATE()) AND MONTH(transaction_date) = MONTH(CURDATE())";
// Stock bought from the RM Capital Fund (purchase orders, re-stock) becomes stock; it is not a running cost.
$stockPurchaseCategory = 'Purchase (Re-stock)';

$activeEmployees = scalarQuery($conn, 'SELECT COUNT(*) FROM users WHERE is_active = 1');
$activeDepartments = scalarQuery($conn, 'SELECT COUNT(*) FROM departments WHERE is_active = 1');
$todayAttendance = scalarQuery($conn, "SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND status IN ('Present', 'Late')");
$openTasks = scalarQuery($conn, "SELECT COUNT(*) FROM tasks WHERE status != 'Completed'");
$monthlyIncome = (float) scalarQuery($conn, "SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE transaction_type = 'Income' AND $approvedThisMonth");
// CHANGED: expenses are split into running costs and stock purchases (same as the Transactions reports).
$monthlyExpense = (float) scalarQuery($conn, "SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE transaction_type = 'Expense' AND category <> '$stockPurchaseCategory' AND $approvedThisMonth");
$monthlyStockPurchases = (float) scalarQuery($conn, "SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE transaction_type = 'Expense' AND category = '$stockPurchaseCategory' AND $approvedThisMonth");

$totalProjects = scalarQuery($conn, "SELECT COUNT(*) FROM projects");
$totalProducts = scalarQuery($conn, "SELECT COUNT(*) FROM products");
// CHANGED: uses each product's own reorder level (else the default), like the Purchase Order
// "low stock" list. It was a fixed 5, so the two pages could disagree.
$reorderLevel = stock_reorder_level_sql();
$lowStockCount = scalarQuery($conn, "SELECT COUNT(*) FROM products WHERE item_type = 'Item' AND is_active = 1 AND quantity <= $reorderLevel");

// RM Funds: shown to admins and managers only
// (change this list if other roles should see them too).
$canSeeFunds = in_array(strtolower($_SESSION['user_role'] ?? ''), ['admin', 'manager'], true) && mm_has_feature('funds'); // PACKAGES
$fundCards = [];
if ($canSeeFunds) {
    // CHANGED: the Operating Fund card is now the same size as the other cards, and the
    // RM Capital Fund is shown next to it (spec section 14: dashboards show the updated funds).
    foreach (['OPERATING' => '../transactions/index.php', 'CAPITAL' => '../transactions/capital_check.php'] as $code => $link) {
        $f = fund_by_code($conn, $code);
        if ($f) {
            $fundCards[] = [
                'name' => $f['name'],
                'available' => fund_available($conn, (int) $f['id']),
                'pending' => fund_pending_total($conn, (int) $f['id']),
                'link' => $link,
                'icon' => $code === 'OPERATING' ? 'bi-wallet2' : 'bi-bank',
                'tint' => $code === 'OPERATING' ? 'teal' : 'cyan',
                'note' => $code === 'OPERATING' ? 'For business expenses' : 'Cash + stock + assets',
            ];
        }
    }
}

$topCategoriesResult = mysqli_query($conn, "SELECT transactions.category AS category_name, SUM(transactions.amount) AS total
    FROM transactions
    WHERE $approvedThisMonth
    GROUP BY transactions.category
    ORDER BY total DESC
    LIMIT 4");
$topCategories = [];
$topCategoriesTotal = 0;
while ($row = mysqli_fetch_assoc($topCategoriesResult)) {
    $topCategories[] = $row;
    $topCategoriesTotal += (float) $row['total'];
}
$categoryPalette = ['#3B4FE0', '#0FA968', '#E68A1C', '#7C5CE0'];

$recentTasksForFeed = mysqli_query($conn, "SELECT tasks.title, tasks.status, tasks.created_at, users.names AS assignee_name FROM tasks LEFT JOIN users ON tasks.assigned_to = users.id ORDER BY tasks.created_at DESC LIMIT 4");
// CHANGED: deleted and rejected transactions are not shown as activity.
$recentTransactionsForFeed = mysqli_query($conn, "SELECT transactions.amount, transactions.transaction_type, transactions.transaction_date, transactions.category AS category_name, transactions.status FROM transactions WHERE transactions.status IN ('approved', 'pending') ORDER BY transactions.transaction_date DESC, transactions.id DESC LIMIT 4");

$activityFeed = [];
while ($row = mysqli_fetch_assoc($recentTasksForFeed)) {
    $activityFeed[] = [
        'icon' => $row['status'] === 'Completed' ? 'bi-check2-circle' : 'bi-list-task',
        'tint' => $row['status'] === 'Completed' ? 'teal' : 'amber',
        'title' => $row['status'] === 'Completed' ? 'Task completed' : 'Task updated',
        'sub' => htmlspecialchars($row['title'] ?? '', ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($row['assignee_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'),
        'time' => $row['created_at'],
    ];
}
while ($row = mysqli_fetch_assoc($recentTransactionsForFeed)) {
    $isIncome = $row['transaction_type'] === 'Income';
    $isPending = $row['status'] === 'pending';
    $activityFeed[] = [
        'icon' => $isIncome ? 'bi-cash-coin' : 'bi-credit-card-2-front',
        'tint' => $isPending ? 'amber' : ($isIncome ? 'teal' : 'red'),
        'title' => ($isIncome ? 'Payment received' : 'Expense recorded') . ($isPending ? ' (awaiting approval)' : ''),
        'sub' => htmlspecialchars($row['category_name'] ?? '', ENT_QUOTES, 'UTF-8') . ' · RWF ' . number_format((float) $row['amount']),
        'time' => $row['transaction_date'],
    ];
}
usort($activityFeed, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));
$activityFeed = array_slice($activityFeed, 0, 6);

?>

<style>
:root{
    --accent-blue:#3B4FE0; --accent-blue-bg:#E8EDFD;
    --accent-teal:#0FA968; --accent-teal-bg:#E1F7EE;
    --accent-amber:#E68A1C; --accent-amber-bg:#FDF1DF;
    --accent-purple:#7C5CE0; --accent-purple-bg:#F0EBFD;
    --accent-cyan:#159AA8; --accent-cyan-bg:#E2F6F8;
    --accent-red:#E24B4A; --accent-red-bg:#FCEAEA;
    --ink:#1E2333; --muted:#8A90A3; --border-soft:#EEF1F8;
}
.rm-card{ background:#fff; border:1px solid var(--border-soft); border-radius:16px; box-shadow:0 1px 2px rgba(16,24,40,.04); }
.rm-card .card-header{ background:#fff; border-bottom:1px solid var(--border-soft); border-radius:16px 16px 0 0; font-weight:600; font-size:14px; color:var(--ink); padding:16px 20px; }
.rm-card .card-body{ padding:20px; }
.stat-card{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:18px 20px; height:100%; }
.stat-icon{ width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.stat-label{ font-size:12px; color:var(--muted); margin-bottom:4px; }
.stat-value{ font-size:22px; font-weight:700; color:var(--ink); margin:0; line-height:1.1; }
/* CHANGED: fund cards are normal-size stat cards; only the note line is extra. */
.fund-note{ font-size:11px; color:var(--muted); margin:4px 0 0; }
.date-pill{ background:#fff; border:1px solid var(--border-soft); border-radius:12px; padding:8px 16px; font-size:13px; color:var(--ink); box-shadow:0 1px 2px rgba(16,24,40,.04); }
.summary-figure h6{ font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; margin-bottom:2px; }
.summary-figure .amt{ font-size:24px; font-weight:700; color:var(--ink); }
.quick-btn{ border-radius:14px; border:1px solid var(--border-soft); background:#fff; color:var(--ink); padding:16px 12px; text-align:center; text-decoration:none; display:block; transition:.15s; font-size:13px; font-weight:500; }
.quick-btn:hover{ border-color:var(--accent-blue); color:var(--accent-blue); transform:translateY(-1px); }
.quick-btn i{ font-size:22px; display:block; margin-bottom:8px; }
.rm-table{ margin-bottom:0; }
.rm-table th{ font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); font-weight:600; border-top:none; border-bottom:1px solid var(--border-soft); padding:10px 20px; }
.rm-table td{ padding:12px 20px; font-size:13px; vertical-align:middle; border-bottom:1px solid var(--border-soft); color:var(--ink); }
.rm-table tr:last-child td{ border-bottom:none; }
.badge-soft-success{ background:var(--accent-teal-bg); color:var(--accent-teal); font-weight:600; font-size:11px; padding:5px 10px; border-radius:20px; }
.badge-soft-secondary{ background:#F1F2F6; color:#6B7280; font-weight:600; font-size:11px; padding:5px 10px; border-radius:20px; }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1" style="color:var(--ink)">
    Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?>
    <i class="bi bi-sun" style="color:var(--accent-amber); font-size:0.8em;"></i>
</h2>
        <p class="text-muted mb-0">Here's what's happening in <?= htmlspecialchars(company_profile()['name'], ENT_QUOTES, 'UTF-8'); ?> today.</p>
    </div>
    <div class="date-pill">
        <i class="bi bi-calendar3 me-1"></i>
        Today: <strong><?= date('d M Y'); ?></strong>
    </div>
</div>

<?php // NEW: Announcements from the MY MOTIVE team: the ones this person has not read yet
require_once '../../includes/announcement_helpers.php';
$announceUnread = [];
try {
    $announceUnread = announcement_for_user((int) mm_company_id(), (int) current_user_id(), current_user_role() === 'Admin', true, 4);
} catch (Throwable $e) {
}
foreach (array_slice($announceUnread, 0, 3) as $an) {
    $anClass = announcement_alert_class($an['level']);
    $anLong = mb_strlen($an['body']) > 280; ?>
<div class="alert alert-<?= $anClass; ?> mb-3" role="alert">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div style="min-width:0;flex:1">
            <strong><i class="bi bi-megaphone-fill me-1"></i><?= announcement_h($an['title']); ?></strong>
            <div style="white-space:pre-wrap;word-break:break-word;"><?= announcement_h($anLong ? mb_substr($an['body'], 0, 280) . '...' : $an['body']); ?></div>
            <?php if ($anLong) { ?><a class="alert-link" href="../announcements/index.php">Read more</a> <?php } ?>
            <?php if (!empty($an['link_url'])) { ?><a class="alert-link" href="<?= announcement_h($an['link_url']); ?>" target="_blank" rel="noopener noreferrer"><?= announcement_h($an['link_label'] ?: 'Learn more'); ?></a><?php } ?>
        </div>
        <form method="POST" action="../announcements/index.php" class="m-0">
            <input type="hidden" name="csrf" value="<?= announcement_h(announcement_csrf_token()); ?>">
            <input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $an['id']; ?>"><input type="hidden" name="back" value="dashboard">
            <button type="submit" class="btn btn-sm btn-outline-<?= $anClass; ?>">Got it</button>
        </form>
    </div>
</div>
<?php } ?>
<?php if (count($announceUnread) > 3) { ?><p class="mb-3"><a href="../announcements/index.php">See all announcements</a></p><?php } ?>

<div class="row g-3 mb-4">
    <?php foreach ($fundCards as $fc) { ?>

    <!-- CHANGED: RM fund cards are the same size as Employees, Departments, etc. -->
    <div class="col-6 col-xl-4">
        <a href="<?= $fc['link']; ?>" class="text-decoration-none">
            <div class="rm-card stat-card">
                <div class="min-width-0">
                    <p class="stat-label"><?= htmlspecialchars($fc['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="stat-value text-nowrap">RWF <?= number_format($fc['available']); ?></p>
                    <p class="fund-note">
                        <?= htmlspecialchars($fc['note'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($fc['pending'] > 0) { ?>&middot; RWF <?= number_format($fc['pending']); ?> pending<?php } ?>
                    </p>
                </div>
                <div class="stat-icon" style="background:var(--accent-<?= $fc['tint']; ?>-bg); color:var(--accent-<?= $fc['tint']; ?>)">
                    <i class="bi <?= $fc['icon']; ?>"></i>
                </div>
            </div>
        </a>
    </div>
    <?php } ?>
    <div class="col-6 col-xl-4">
        <div class="rm-card stat-card">
            <div>
                <p class="stat-label">Employees</p>
                <p class="stat-value"><?= (int) $activeEmployees ?></p>
            </div>
            <div class="stat-icon" style="background:var(--accent-blue-bg); color:var(--accent-blue)">
                <i class="bi bi-people-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="rm-card stat-card">
            <div>
                <p class="stat-label">Departments</p>
                <p class="stat-value"><?= (int) $activeDepartments ?></p>
            </div>
            <div class="stat-icon" style="background:var(--accent-teal-bg); color:var(--accent-teal)">
                <i class="bi bi-building"></i>
            </div>
        </div>
    </div>
    <?php if (mm_has_feature('targets')) { ?>
<div class="col-6 col-xl-4">
        <div class="rm-card stat-card">
            <div>
                <p class="stat-label">Projects</p>
                <p class="stat-value"><?= (int) $totalProjects ?></p>
            </div>
            <div class="stat-icon" style="background:var(--accent-amber-bg); color:var(--accent-amber)">
                <i class="bi bi-folder-fill"></i>
            </div>
        </div>
    </div>
<?php } ?>
    <div class="col-6 col-xl-4">
        <div class="rm-card stat-card">
            <div>
                <p class="stat-label">Products</p>
                <p class="stat-value"><?= (int) $totalProducts ?></p>
            </div>
            <div class="stat-icon" style="background:var(--accent-cyan-bg); color:var(--accent-cyan)">
                <i class="bi bi-box-seam"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <a href="../products/index.php" class="text-decoration-none">
            <div class="rm-card stat-card">
                <div>
                    <p class="stat-label">Low stock items</p>
                    <p class="stat-value" style="color:<?= $lowStockCount > 0 ? 'var(--accent-red)' : 'var(--ink)'; ?>"><?= (int) $lowStockCount ?></p>
                </div>
                <div class="stat-icon" style="background:var(--accent-red-bg); color:var(--accent-red)">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-xl-4">
        <div class="rm-card stat-card">
            <div>
                <p class="stat-label">Today's attendance</p>
                <p class="stat-value"><?= (int) $todayAttendance ?></p>
            </div>
            <div class="stat-icon" style="background:var(--accent-purple-bg); color:var(--accent-purple)">
                <i class="bi bi-calendar-check"></i>
            </div>
        </div>
    </div>
    <?php if (mm_has_feature('tasks')) { ?>
<div class="col-6 col-xl-4">
        <div class="rm-card stat-card">
            <div>
                <p class="stat-label">Open tasks</p>
                <p class="stat-value"><?= (int) $openTasks ?></p>
            </div>
            <div class="stat-icon" style="background:var(--accent-red-bg); color:var(--accent-red)">
                <i class="bi bi-list-check"></i>
            </div>
        </div>
    </div>
<?php } ?>
</div>

<?php if (mm_has_feature('transactions')) { ?>
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="rm-card h-100">
            <div class="card-header">📈 Financial overview <span class="text-muted fw-normal">· this month, approved</span></div>
            <div class="card-body">
                <canvas id="financeChart" height="110"></canvas>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="rm-card h-100">
            <div class="card-header">💰 Monthly summary</div>
            <div class="card-body d-flex flex-column gap-3">
                <div class="summary-figure d-flex align-items-center justify-content-between p-3 rounded-3" style="background:var(--accent-teal-bg)">
                    <div>
                        <h6 style="color:var(--accent-teal)">Income</h6>
                        <p class="amt mb-0">RWF <?= number_format($monthlyIncome) ?></p>
                    </div>
                    <i class="bi bi-arrow-up-circle-fill fs-2" style="color:var(--accent-teal)"></i>
                </div>
                <div class="summary-figure d-flex align-items-center justify-content-between p-3 rounded-3" style="background:var(--accent-red-bg)">
                    <div>
                        <h6 style="color:var(--accent-red)">Running costs</h6>
                        <p class="amt mb-0">RWF <?= number_format($monthlyExpense) ?></p>
                    </div>
                    <i class="bi bi-arrow-down-circle-fill fs-2" style="color:var(--accent-red)"></i>
                </div>
                <!-- NEW: stock bought from the RM Capital Fund, kept apart from running costs. -->
                <div class="summary-figure d-flex align-items-center justify-content-between p-3 rounded-3" style="background:var(--accent-cyan-bg)">
                    <div>
                        <h6 style="color:var(--accent-cyan)">Stock purchases</h6>
                        <p class="amt mb-0">RWF <?= number_format($monthlyStockPurchases) ?></p>
                    </div>
                    <i class="bi bi-box-arrow-in-down fs-2" style="color:var(--accent-cyan)"></i>
                </div>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<!-- NEW (Support): raise a ticket to the MY MOTIVE team and follow the answers -->
<div class="rm-card mb-4">
    <div class="card-header"> Support</div>
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <a href="../support/index.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-list-ul me-1"></i> My tickets
            <?php if (!empty($supportUnread)) { ?><span class="badge rounded-pill ms-1" style="background:#E24B4A;"><?= (int) $supportUnread; ?></span><?php } ?>
        </a>
        <a href="../support/create.php" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i> Raise a ticket
        </a>
        <span class="text-muted small">A question or something not working? Tell the MY MOTIVE team.</span>
    </div>
</div>

<div class="rm-card mb-4">
    <div class="card-header">⚡ Quick actions</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <a href="../users/create.php" class="quick-btn">
                    <i class="bi bi-person-plus-fill" style="color:var(--accent-blue)"></i>
                    Add employee
                </a>
            </div>
            <?php if (mm_has_feature('targets')) { ?>
<div class="col-6 col-md-3">
                <a href="../projects/create.php" class="quick-btn">
                    <i class="bi bi-folder-plus" style="color:var(--accent-teal)"></i>
                    Create project
                </a>
            </div>
<?php } ?>
            <div class="col-6 col-md-3">
                <a href="../products/create.php" class="quick-btn">
                    <i class="bi bi-box-seam" style="color:var(--accent-amber)"></i>
                    Add product
                </a>
            </div>
            <?php if (mm_has_feature('transactions')) { ?>
<div class="col-6 col-md-3">
                <a href="../transactions/create.php" class="quick-btn">
                    <i class="bi bi-cash-stack" style="color:var(--accent-red)"></i>
                    New transaction
                </a>
            </div>
<?php } ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <?php if (mm_has_feature('transactions')) { ?>
<div class="col-lg-4">
        <div class="rm-card h-100">
            <div class="card-header">Top categories <span class="text-muted fw-normal">· this month</span></div>
            <div class="card-body">
                <?php if ($topCategoriesTotal > 0): ?>
                <div style="max-width:180px; margin:0 auto 16px;">
                    <canvas id="categoryChart"></canvas>
                </div>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($topCategories as $i => $cat): ?>
                    <div class="d-flex align-items-center justify-content-between" style="font-size:12px;">
                        <div class="d-flex align-items-center gap-2 text-truncate">
                            <span style="width:8px;height:8px;border-radius:50%;background:<?= $categoryPalette[$i % 4] ?>; flex-shrink:0;"></span>
                            <span class="text-muted text-truncate"><?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <span class="fw-semibold flex-shrink-0">RWF <?= number_format((float) $cat['total']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-center text-muted py-5 mb-0">No transactions recorded this month yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php } ?>

    <div class="col-lg-8">
        <div class="rm-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                Recent activity
                <?php if (mm_has_feature('transactions')) { ?><a href="../transactions/index.php" class="small text-decoration-none" style="color:var(--accent-blue)">View all <i class="bi bi-chevron-right"></i></a><?php } ?>
            </div>
            <div class="card-body">
                <?php if (empty($activityFeed)): ?>
                <p class="text-center text-muted py-4 mb-0">Nothing to show yet.</p>
                <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($activityFeed as $item): ?>
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon" style="width:36px; height:36px; font-size:16px; background:var(--accent-<?= $item['tint'] ?>-bg); color:var(--accent-<?= $item['tint'] ?>);">
                            <i class="bi <?= $item['icon'] ?>"></i>
                        </div>
                        <div class="flex-grow-1 min-width-0">
                            <p class="mb-0" style="font-size:13px; font-weight:600; color:var(--ink);"><?= $item['title'] ?></p>
                            <p class="mb-0 text-muted text-truncate" style="font-size:12px;"><?= $item['sub'] ?></p>
                        </div>
                        <span class="text-muted flex-shrink-0" style="font-size:11px;"><?= date('d M, H:i', strtotime($item['time'])) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<?php if (mm_has_feature('transactions')) { ?>
<script>
const ctx = document.getElementById('financeChart');
new Chart(ctx, {
    type: 'bar',
    data: {
        // CHANGED: running costs and stock purchases shown separately.
        labels: ['Income', 'Running costs', 'Stock purchases'],
        datasets: [{
            label: 'Amount (RWF)',
            data: [<?= json_encode($monthlyIncome) ?>, <?= json_encode($monthlyExpense) ?>, <?= json_encode($monthlyStockPurchases) ?>],
            backgroundColor: ['#0FA968', '#E24B4A', '#159AA8'],
            borderRadius: 8,
            barThickness: 64
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { grid: { color: '#EEF1F8' }, ticks: { callback: v => 'RWF ' + v.toLocaleString() } },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php } ?>

<?php if ($topCategoriesTotal > 0 && mm_has_feature('transactions')): ?>
<script>
new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($topCategories, 'category_name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        datasets: [{
            data: <?= json_encode(array_map('floatval', array_column($topCategories, 'total'))) ?>,
            backgroundColor: <?= json_encode($categoryPalette) ?>,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        cutout: '68%',
        plugins: { legend: { display: false } }
    }
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>