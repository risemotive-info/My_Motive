<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require_role(['Admin']);

const WORKING_HOURS_PER_DAY = 8;
const PERFORMANCE_BONUS_RATE = 0.05; // up to 5% of basic salary, scaled by average score
const SALES_COMMISSION_RATE = 0.02; // 2% of an employee's finalized sales in the month

function calculate_payroll_preview($conn, $userId, $period) {
    $employeeStatement = mysqli_prepare($conn, 'SELECT monthly_salary FROM users WHERE id = ? AND is_active = 1');
    mysqli_stmt_bind_param($employeeStatement, 'i', $userId);
    mysqli_stmt_execute($employeeStatement);
    $employee = mysqli_fetch_assoc(mysqli_stmt_get_result($employeeStatement));
    if (!$employee) {
        return null;
    }

    $basicSalary = (float) $employee['monthly_salary'];
    $monthStart = $period . '-01';
    $daysInMonth = (int) date('t', strtotime($monthStart));

    // Count weekdays (Mon-Fri) in the month as expected working days.
    $expectedWorkingDays = 0;
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dow = (int) date('N', strtotime("$monthStart +" . ($day - 1) . ' days'));
        if ($dow <= 5) {
            $expectedWorkingDays++;
        }
    }

    // Confirmed attendance for this employee within the month.
    $attendanceStatement = mysqli_prepare($conn, "SELECT
            COUNT(*) AS present_days,
            COALESCE(SUM(overtime_minutes), 0) AS overtime_minutes
        FROM attendance
        WHERE user_id = ? AND workflow_status = 'Confirmed'
            AND DATE_FORMAT(attendance_date, '%Y-%m') = ?");
    mysqli_stmt_bind_param($attendanceStatement, 'is', $userId, $period);
    mysqli_stmt_execute($attendanceStatement);
    $attendance = mysqli_fetch_assoc(mysqli_stmt_get_result($attendanceStatement));

    $presentDays = (int) $attendance['present_days'];
    $overtimeMinutes = (int) $attendance['overtime_minutes'];
    $absentDays = max(0, $expectedWorkingDays - $presentDays);

    $dailyRate = $expectedWorkingDays > 0 ? $basicSalary / $expectedWorkingDays : 0;
    $hourlyRate = $dailyRate / WORKING_HOURS_PER_DAY;

    $attendanceDeduction = round($absentDays * $dailyRate, 2);
    $overtimePay = round(($overtimeMinutes / 60) * $hourlyRate, 2);

    // Average performance score from tasks approved within the month.
    $taskStatement = mysqli_prepare($conn, "SELECT AVG(performance_score) AS avg_score
        FROM tasks
        WHERE assigned_to = ? AND status = 'Approved' AND performance_score IS NOT NULL
            AND DATE_FORMAT(COALESCE(reviewed_at, submitted_at), '%Y-%m') = ?");
    mysqli_stmt_bind_param($taskStatement, 'is', $userId, $period);
    mysqli_stmt_execute($taskStatement);
    $taskResult = mysqli_fetch_assoc(mysqli_stmt_get_result($taskStatement));
    $avgScore = $taskResult['avg_score'] !== null ? round((float) $taskResult['avg_score'], 2) : null;
    $performanceBonus = $avgScore !== null ? round(($avgScore / 100) * ($basicSalary * PERFORMANCE_BONUS_RATE), 2) : 0.00;

    // Sales commission: a percentage of this employee's finalized sales in the month
    // (excludes sales still pending discount approval or cancelled).
    $salesStatement = mysqli_prepare($conn, "SELECT COALESCE(SUM(total_amount), 0) AS total_sales
        FROM sales
        WHERE recorded_by = ? AND status IN ('Paid', 'Partially Paid', 'Credit')
            AND DATE_FORMAT(sale_date, '%Y-%m') = ?");
    mysqli_stmt_bind_param($salesStatement, 'is', $userId, $period);
    mysqli_stmt_execute($salesStatement);
    $salesResult = mysqli_fetch_assoc(mysqli_stmt_get_result($salesStatement));
    $totalSales = (float) $salesResult['total_sales'];
    $salesCommission = round($totalSales * SALES_COMMISSION_RATE, 2);

    return [
        'basic_salary' => $basicSalary,
        'expected_working_days' => $expectedWorkingDays,
        'present_days' => $presentDays,
        'absent_days' => $absentDays,
        'overtime_minutes' => $overtimeMinutes,
        'attendance_deduction' => $attendanceDeduction,
        'overtime_pay' => $overtimePay,
        'avg_performance_score' => $avgScore,
        'performance_bonus' => $performanceBonus,
        'total_sales' => $totalSales,
        'sales_commission' => $salesCommission,
    ];
}

$employees = mysqli_query($conn, 'SELECT id, names, monthly_salary FROM users WHERE is_active = 1 ORDER BY names');

$selectedUserId = isset($_REQUEST['user_id']) ? filter_var($_REQUEST['user_id'], FILTER_VALIDATE_INT) : false;
$selectedUserId = $selectedUserId ?: null;
$selectedPeriod = $_REQUEST['pay_period'] ?? date('Y-m');
$preview = ($selectedUserId && preg_match('/^\d{4}-\d{2}$/', $selectedPeriod))
    ? calculate_payroll_preview($conn, $selectedUserId, $selectedPeriod)
    : null;

if (isset($_POST['save'])) {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $period = $_POST['pay_period'] ?? '';
    $bonus = filter_input(INPUT_POST, 'bonus', FILTER_VALIDATE_FLOAT);
    $deductions = filter_input(INPUT_POST, 'deductions', FILTER_VALIDATE_FLOAT);
    $salesCommission = filter_input(INPUT_POST, 'sales_commission', FILTER_VALIDATE_FLOAT);
    $status = $_POST['status'] ?? '';

    if (!$userId || !preg_match('/^\d{4}-\d{2}$/', $period) || $bonus === false || $bonus < 0
        || $deductions === false || $deductions < 0 || $salesCommission === false || $salesCommission < 0
        || !in_array($status, ['Draft', 'Paid'], true)) {
        $error = 'Please enter valid payroll details.';
    } else {
        $calc = calculate_payroll_preview($conn, $userId, $period);
        if (!$calc) {
            $error = 'Select a valid active employee.';
        } else {
            $periodDate = $period . '-01';

            $duplicateCheck = mysqli_prepare($conn, 'SELECT id FROM payroll WHERE user_id = ? AND pay_period = ?');
            mysqli_stmt_bind_param($duplicateCheck, 'is', $userId, $periodDate);
            mysqli_stmt_execute($duplicateCheck);

            if (mysqli_num_rows(mysqli_stmt_get_result($duplicateCheck)) > 0) {
                $error = 'Payroll already exists for this employee and month.';
            } else {
            $netSalary = $calc['basic_salary']
                + $calc['overtime_pay']
                + $calc['performance_bonus']
                + $salesCommission
                + $bonus
                - $deductions
                - $calc['attendance_deduction'];
            $paidAt = $status === 'Paid' ? date('Y-m-d H:i:s') : null;

            mysqli_begin_transaction($conn);
            try {
                $statement = mysqli_prepare($conn, "INSERT INTO payroll
                    (user_id, pay_period, basic_salary, present_days, absent_days, overtime_minutes,
                     attendance_deduction, overtime_pay, avg_performance_score, performance_bonus,
                     sales_commission, bonus, deductions, net_salary, status, paid_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param(
                    $statement, 'isdiiiddddddddss',
                    $userId, $periodDate, $calc['basic_salary'], $calc['present_days'], $calc['absent_days'], $calc['overtime_minutes'],
                    $calc['attendance_deduction'], $calc['overtime_pay'], $calc['avg_performance_score'], $calc['performance_bonus'],
                    $salesCommission, $bonus, $deductions, $netSalary, $status, $paidAt
                );
                mysqli_stmt_execute($statement);

                // Auto-post the payment to Transactions as an Expense — only for
                // 'Paid' runs, since a Draft hasn't actually paid anyone yet.
                // No approval needed — mirrors how sales_finalize() posts income
                // for Sales, so it appears in Transactions immediately.
                if ($status === 'Paid') {
                    $nameStatement = mysqli_prepare($conn, 'SELECT names FROM users WHERE id = ?');
                    mysqli_stmt_bind_param($nameStatement, 'i', $userId);
                    mysqli_stmt_execute($nameStatement);
                    $employeeRow = mysqli_fetch_assoc(mysqli_stmt_get_result($nameStatement));
                    $employeeName = $employeeRow['names'] ?? ('Employee #' . $userId);

                    $adminId = $_SESSION['user_id'] ?? null;
                    $paidDate = date('Y-m-d');
                    $description = 'Payroll: ' . $employeeName . ' (' . date('F Y', strtotime($periodDate)) . ')';

                    $insertTransaction = mysqli_prepare($conn, "INSERT INTO transactions
                        (category, transaction_type, amount, transaction_date, description, recorded_by, status)
                        VALUES ('Payroll', 'Expense', ?, ?, ?, ?, 'approved')");
                    mysqli_stmt_bind_param($insertTransaction, 'dssi', $netSalary, $paidDate, $description, $adminId);
                    mysqli_stmt_execute($insertTransaction);
                }

                mysqli_commit($conn);
                header('Location: index.php?success=Payroll generated successfully.');
                exit;
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = 'Unable to generate payroll. Please try again.';
            }
            }
        }
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-cash-stack';
$modal_title = 'Generate Payroll';
$modal_subtitle = 'Basic salary, attendance, and task performance are calculated automatically.';
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <form method="GET" class="mb-3">
                <div class="row g-3">
                    <div class="col-7">
                        <label class="form-label small fw-semibold text-muted">Employee</label>
                        <select name="user_id" class="form-select rm-input" onchange="this.form.submit()" required>
                            <option value="">Select employee</option>
                            <?php while ($employee = mysqli_fetch_assoc($employees)) { ?>
                                <option value="<?= (int) $employee['id']; ?>" <?= $selectedUserId === (int) $employee['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($employee['names'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-5">
                        <label class="form-label small fw-semibold text-muted">Pay Period</label>
                        <input type="month" name="pay_period" class="form-control rm-input" value="<?= htmlspecialchars($selectedPeriod, ENT_QUOTES, 'UTF-8'); ?>" onchange="this.form.submit()" required>
                    </div>
                </div>
            </form>

            <?php if ($preview) { ?>
            <div class="mb-3 p-3" style="background:#F8FAFC; border-radius:12px;">
                <div class="row g-2 small">
                    <div class="col-6"><span class="text-muted">Basic Salary:</span> <strong>RWF <?= number_format($preview['basic_salary'], 2); ?></strong></div>
                    <div class="col-6"><span class="text-muted">Expected Working Days:</span> <strong><?= $preview['expected_working_days']; ?></strong></div>
                    <div class="col-6"><span class="text-muted">Present Days (confirmed):</span> <strong><?= $preview['present_days']; ?></strong></div>
                    <div class="col-6"><span class="text-muted">Absent Days:</span> <strong><?= $preview['absent_days']; ?></strong></div>
                    <div class="col-6"><span class="text-muted">Overtime:</span> <strong><?= round($preview['overtime_minutes'] / 60, 1); ?> hrs</strong></div>
                    <div class="col-6"><span class="text-muted">Avg Task Score:</span> <strong><?= $preview['avg_performance_score'] !== null ? $preview['avg_performance_score'] . '/100' : 'No reviewed tasks'; ?></strong></div>
                    <div class="col-6"><span class="text-muted">Total Sales Recorded:</span> <strong>RWF <?= number_format($preview['total_sales'], 2); ?></strong></div>
                    <div class="col-6"><span class="text-muted">Attendance Deduction:</span> <strong class="text-danger">- RWF <?= number_format($preview['attendance_deduction'], 2); ?></strong></div>
                    <div class="col-6"><span class="text-muted">Overtime Pay:</span> <strong class="text-success">+ RWF <?= number_format($preview['overtime_pay'], 2); ?></strong></div>
                    <div class="col-6"><span class="text-muted">Performance Bonus:</span> <strong class="text-success">+ RWF <?= number_format($preview['performance_bonus'], 2); ?></strong></div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="user_id" value="<?= (int) $selectedUserId; ?>">
                <input type="hidden" name="pay_period" value="<?= htmlspecialchars($selectedPeriod, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="row g-3 mb-3">
                    <div class="col-4">
                        <label class="form-label small fw-semibold text-muted">Sales Commission (RWF)</label>
                        <input type="number" name="sales_commission" class="form-control rm-input" min="0" step="0.01" value="<?= $preview['sales_commission']; ?>">
                        <small class="text-muted">Auto: 2% of this employee's finalized sales this month.</small>
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-semibold text-muted">Extra Bonus (RWF)</label>
                        <input type="number" name="bonus" class="form-control rm-input" min="0" step="0.01" value="0" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-semibold text-muted">Other Deductions (RWF)</label>
                        <input type="number" name="deductions" class="form-control rm-input" min="0" step="0.01" value="0" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <option value="Draft">Draft</option>
                        <option value="Paid">Paid</option>
                    </select>
                </div>

                <p class="text-muted mb-4">
                    Estimated Net Salary: <strong>RWF <?= number_format(
                        $preview['basic_salary'] + $preview['overtime_pay'] + $preview['performance_bonus'] + $preview['sales_commission'] - $preview['attendance_deduction'], 2
                    ); ?></strong> <small>+ extra bonus &minus; other deductions entered above</small>
                </p>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Generate Payroll
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">
                        <i class="bi bi-x-circle-fill me-2"></i>Cancel
                    </a>
                </div>
            </form>
            <?php } else { ?>
                <p class="text-muted">Select an employee and pay period to calculate payroll from attendance and task performance.</p>
                <a href="index.php" class="rm-btn rm-btn-secondary">
                    <i class="bi bi-x-circle-fill me-2"></i>Cancel
                </a>
            <?php } ?>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>