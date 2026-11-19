<?php
require '../../config/db.php';
require '../../includes/sales_helpers.php'; // for customer_credit_status()
require_once '../../includes/sms_messages.php'; // NEW: sms_customer_code() (the same Customer ID as the welcome SMS)
// NEW: this page had no role check of its own (every other page has one).
require_role(['Admin', 'Manager', 'Employee']);
$pageSearchScope = 'customers'; // tells the topbar search what module we're in
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const PER_PAGE = 10;
$canSendSms = in_array(current_user_role(), ['Admin', 'Manager'], true) && mm_has_feature('sms'); // PACKAGES // NEW: same roles as the Send SMS page
$canEdit = in_array(current_user_role(), ['Admin', 'Manager'], true); // editing does not depend on the SMS package

$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM customers WHERE is_active = 1"))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

// CHANGED: the balance counts only sales that are really owed (Credit and Partially Paid).
// Before, a sale still waiting for discount/credit approval was also counted as owed, although it
// is not finalized yet. Now it matches the Sales list, the SMS and the credit reminders.
$customers = mysqli_query($conn, "SELECT customers.*,
        COALESCE(SUM(CASE WHEN sales.status IN ('Credit', 'Partially Paid') THEN sales.total_amount - sales.amount_paid + sales.charges_accrued - sales.charges_paid ELSE 0 END), 0) AS balance
    FROM customers LEFT JOIN sales ON sales.customer_id = customers.id
    WHERE customers.is_active = 1
    GROUP BY customers.id ORDER BY customers.name LIMIT " . PER_PAGE . ' OFFSET ' . $offset);
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div><?php } ?>
<?php if (isset($_GET['error'])) { ?>
<!-- NEW: error messages were not shown on this page. -->
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customers</h2>
    <div class="d-flex gap-2">
        <?php if (in_array(current_user_role(), ['Admin', 'Manager'], true)) { ?>
            <!-- NEW: Customers & Credit reports -->
            <a href="reports.php" class="btn btn-outline-primary">Reports</a>
            <!-- NEW: Rewards Management -->
            <a href="rewards.php" class="btn btn-outline-primary">Rewards</a>
        <?php } ?>
        <a href="create.php" class="rm-btn rm-btn-primary">+ Add Customer</a>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div id="pageResultsContainer">
        <div class="table-responsive">
        <table class="table table-bordered table-hover bg-white mb-0">
<tr>
    <th>Customer ID</th>
    <th>Name</th>
    <th>Phone</th>
    <th>Email</th>
    <th>Location</th>
    <th>Outstanding Balance</th>
    <th>Loyalty Points</th>
    <th>Credit Status</th>
    <th>Action</th>
</tr>
<?php if (mysqli_num_rows($customers) === 0) { ?>
<tr><td colspan="9" class="text-center text-muted py-4">No customers yet.</td></tr><?php } ?>
<?php while ($customer = mysqli_fetch_assoc($customers)) {
    $loyaltyPoints = (int) $customer['loyalty_points'];
    $creditStatus = customer_credit_status($loyaltyPoints);
    $balance = (float) $customer['balance'];
    // NEW: a phone number that cannot receive SMS (not 07 + 8 digits) is flagged, so it gets corrected.
    $phoneOk = sms_normalize_phone($customer['phone'] ?? null) !== null;
?>
<tr>
    <!-- NEW: the Customer ID used in the welcome SMS (RMC + the customer number with 6 digits). -->
    <td class="text-nowrap fw-semibold"><?= htmlspecialchars(sms_customer_code($conn, (int) $customer['id']), ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td class="text-nowrap">
        <?= htmlspecialchars($customer['phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
        <?php if (!$phoneOk) { ?>
            <span class="badge bg-warning text-dark" title="This number cannot receive SMS. It must be 10 digits starting with 07.">Check number</span>
        <?php } ?>
    </td>
    <td><?= htmlspecialchars($customer['email'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php
        $locationParts = array_filter([$customer['sector'] ?? '', $customer['district'] ?? '', $customer['province'] ?? '']);
        echo $locationParts ? htmlspecialchars(implode(', ', $locationParts), ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>';
    ?></td>
    <td><?php if ($balance > 0.009) { ?>
    <span class="text-danger fw-semibold">RWF <?= number_format($balance, 2); ?></span>
    <?php } else { ?><span class="text-muted">RWF 0.00</span><?php } ?></td>
    <td><span class="fw-semibold"><?= $loyaltyPoints; ?></span></td>
    <td>
        <span class="badge bg-<?= $creditStatus === 'Allowed' ? 'success' : 'danger'; ?>"><?= htmlspecialchars($creditStatus, ENT_QUOTES, 'UTF-8'); ?></span>
    </td>
    <td class="text-nowrap">
        <?php if ($canEdit) { ?>
            <!-- only Admins and Managers can edit customers (edit.php checks this again on the server) -->
            <a href="edit.php?id=<?= (int) $customer['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a>
        <?php } ?>
        <?php if ($canSendSms) { ?>
            <!-- NEW: this customer's Rewards section (history, points balance, record a reward) -->
            <a href="rewards.php?customer_id=<?= (int) $customer['id']; ?>" class="btn btn-outline-success btn-sm" title="Rewards"><i class="bi bi-gift"></i> Rewards</a>
        <?php } ?>
        <?php if ($canSendSms && $phoneOk) { ?>
            <!-- NEW: SMS to this customer. With a balance it opens the credit reminder; otherwise a blank message. -->
            <a href="../sms/sms_compose.php?group=customer&id=<?= (int) $customer['id']; ?>&template=<?= $balance > 0.009 ? 'credit' : 'custom'; ?>" class="btn btn-outline-primary btn-sm" title="Send an SMS to this customer"><i class="bi bi-chat-dots"></i> SMS</a>
        <?php } ?>
    </td>
</tr>
<?php } ?>
</table>
        </div>
        </div>
        <div id="pageResultsPagination">
        <?php render_pagination($currentPage, $totalPages); ?>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>