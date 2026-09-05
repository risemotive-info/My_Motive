<?php
require '../../config/db.php';
require_role(['Admin']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: index.php?success=Invalid employee selected.');
    exit;
}

$userStatement = mysqli_prepare($conn, 'SELECT * FROM users WHERE id = ?');
mysqli_stmt_bind_param($userStatement, 'i', $id);
mysqli_stmt_execute($userStatement);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($userStatement));

if (!$user) {
    header('Location: index.php?success=Employee not found.');
    exit;
}

if (isset($_POST['update'])) {
    $names = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? '';
    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    $roleId = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT); // department role, nullable
    $monthlySalary = filter_input(INPUT_POST, 'monthly_salary', FILTER_VALIDATE_FLOAT);
    $password = $_POST['password'] ?? '';

    if ($names === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$departmentId || $monthlySalary === false || $monthlySalary < 0 || !in_array($role, ['Admin', 'Manager', 'Employee'], true)) {
        $error = 'Please provide valid employee details.';
    } elseif ($roleId && !role_belongs_to_department($conn, $roleId, $departmentId)) {
        $error = 'The selected department role does not belong to the chosen department.';
    } else {
        $emailStatement = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ? AND id != ?');
        mysqli_stmt_bind_param($emailStatement, 'si', $email, $id);
        mysqli_stmt_execute($emailStatement);
        $existingEmail = mysqli_stmt_get_result($emailStatement);

        if (mysqli_num_rows($existingEmail) > 0) {
            $error = 'Employee with this email already exists.';
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $updateStatement = mysqli_prepare($conn, 'UPDATE users SET names = ?, email = ?, phone = ?, password_hash = ?, role = ?, department_id = ?, role_id = ?, monthly_salary = ? WHERE id = ?');
            mysqli_stmt_bind_param($updateStatement, 'sssssiidi', $names, $email, $phone, $passwordHash, $role, $departmentId, $roleId, $monthlySalary, $id);
            mysqli_stmt_execute($updateStatement);
            header('Location: index.php?success=Employee updated successfully.');
            exit;
        } else {
            $updateStatement = mysqli_prepare($conn, 'UPDATE users SET names = ?, email = ?, phone = ?, role = ?, department_id = ?, role_id = ?, monthly_salary = ? WHERE id = ?');
            mysqli_stmt_bind_param($updateStatement, 'ssssiidi', $names, $email, $phone, $role, $departmentId, $roleId, $monthlySalary, $id);
            mysqli_stmt_execute($updateStatement);
            header('Location: index.php?success=Employee updated successfully.');
            exit;
        }
    }

    $user['names'] = $names;
    $user['email'] = $email;
    $user['phone'] = $phone;
    $user['role'] = $role;
    $user['department_id'] = $departmentId;
    $user['role_id'] = $roleId;
    $user['monthly_salary'] = $monthlySalary;
}

function role_belongs_to_department($conn, $roleId, $departmentId) {
    $statement = mysqli_prepare($conn, 'SELECT id FROM roles WHERE id = ? AND department_id = ? AND is_active = 1');
    mysqli_stmt_bind_param($statement, 'ii', $roleId, $departmentId);
    mysqli_stmt_execute($statement);
    return mysqli_num_rows(mysqli_stmt_get_result($statement)) > 0;
}

$departments = mysqli_query($conn, 'SELECT * FROM departments WHERE is_active = 1 ORDER BY name');

// All active department roles, fetched once and filtered client-side by JS
// so switching the Department dropdown updates the Department Role options
// without a page reload.
$allRolesResult = mysqli_query($conn, 'SELECT id, department_id, name FROM roles WHERE is_active = 1 ORDER BY department_id, name');
$allRoles = [];
while ($r = mysqli_fetch_assoc($allRolesResult)) {
    $allRoles[] = $r;
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-person-gear';
$modal_title = 'Edit Employee';
$modal_subtitle = 'Update this employee\'s profile and account access.';
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

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Full name</label>
                    <input type="text" name="name" class="form-control rm-input" value="<?= htmlspecialchars($user['names'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Email address</label>
                        <input type="email" name="email" class="form-control rm-input" value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Phone number</label>
                        <input type="text" name="phone" class="form-control rm-input" value="<?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 078xxxxxxx">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">New password <span class="text-muted fw-normal">(leave blank to keep current)</span></label>
                    <input type="password" name="password" class="form-control rm-input">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Role</label>
                        <select name="role" class="form-select rm-input" required>
                            <?php foreach (['Admin', 'Manager', 'Employee'] as $role) { ?>
                                <option value="<?= $role; ?>" <?= $user['role'] === $role ? 'selected' : ''; ?>><?= $role; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Salary (RWF)</label>
                        <input type="number" name="monthly_salary" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars((string) $user['monthly_salary'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Department</label>
                        <select name="department_id" id="departmentSelect" class="form-select rm-input" required>
                            <?php while ($department = mysqli_fetch_assoc($departments)) { ?>
                                <option value="<?= (int) $department['id']; ?>" <?= (int) $user['department_id'] === (int) $department['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Department Role</label>
                        <select name="role_id" id="roleSelect" class="form-select rm-input">
                            <option value="">— None —</option>
                            <?php foreach ($allRoles as $r) { ?>
                                <option value="<?= (int) $r['id']; ?>" data-department="<?= (int) $r['department_id']; ?>" <?= (int) ($user['role_id'] ?? 0) === (int) $r['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="rm-btn rm-btn-primary" type="submit" name="update">
                    <i class="bi bi-check-circle-fill"></i>Update Employee
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<script>
(function () {
    var departmentSelect = document.getElementById('departmentSelect');
    var roleSelect = document.getElementById('roleSelect');
    var initialRoleId = '<?= (int) ($user['role_id'] ?? 0); ?>';

    function filterRoles(preserveSelection) {
        var selectedDept = departmentSelect.value;
        var previousValue = preserveSelection ? roleSelect.value : '';
        var options = roleSelect.querySelectorAll('option[data-department]');
        var matchFound = false;

        options.forEach(function (opt) {
            var matches = opt.getAttribute('data-department') === selectedDept;
            opt.hidden = !matches;
            opt.disabled = !matches;
            if (matches && opt.value === previousValue) {
                matchFound = true;
            }
        });

        if (matchFound) {
            roleSelect.value = previousValue;
        } else {
            roleSelect.value = '';
        }
    }

    departmentSelect.addEventListener('change', function () {
        filterRoles(false);
    });

    // On initial page load, keep the employee's existing role_id selected
    // if it belongs to their current department; otherwise fall back to None.
    roleSelect.value = initialRoleId;
    filterRoles(true);
})();
</script>

<?php include '../../includes/footer.php'; ?>