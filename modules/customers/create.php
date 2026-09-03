<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
if (isset($_POST['save'])) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $sector = trim($_POST['sector'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '') { $error = 'Customer name is required.'; }
    elseif ($phone === '') { $error = 'Phone number is required.'; }
    elseif (!preg_match('/^\d{10}$/', $phone)) { $error = 'Phone number must be exactly 10 digits.'; }
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
         $error = 'Please enter a valid email address.'; }
    elseif ($province === '' || $district === '' || $sector === '') {
        $error = 'Please select Province, District, and Sector.';
    }
    else {
        // A customer must be identifiable by phone number, and the same
        // phone number can't belong to two different customer records.
        $dupStatement = mysqli_prepare($conn, 'SELECT id FROM customers WHERE phone = ?');
        mysqli_stmt_bind_param($dupStatement, 's', $phone);
        mysqli_stmt_execute($dupStatement);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStatement));

        if ($existing) {
            $error = 'A customer with this phone number already exists.';
        } else {
            $statement = mysqli_prepare($conn, 'INSERT INTO customers (name, phone, email, province, district, sector, address) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($statement, 'sssssss', $name, $phone, $email, $province, $district, $sector, $address);
            if (mysqli_stmt_execute($statement)) {
                header('Location: index.php?success=Customer added successfully.'); exit; }
            $error = 'Unable to add customer.';
        }
    }
}
include '../../includes/header.php';
include '../../includes/sidebar.php';
$modal_icon = 'bi-person-plus-fill';
$modal_title = 'Add Customer';
$modal_subtitle = 'Create a new customer profile.';
?>
<div class="rm-modal-backdrop">
    <div class="rm-modal">
    <?php include '../../includes/model_header.php'; ?>
    <div class="rm-modal-body">
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <form method="POST">
            <div class="mb-3"><label class="form-label small fw-semibold text-muted">Customer Name</label><input type="text" name="name" class="form-control rm-input" value="<?= htmlspecialchars($name ?? '', ENT_QUOTES, 'UTF-8'); ?>" required></div>
            <div class="row g-3 mb-3">
                <div class="col-6"><label class="form-label small fw-semibold text-muted">Phone</label><input type="text" name="phone" class="form-control rm-input" value="<?= htmlspecialchars($phone ?? '', ENT_QUOTES, 'UTF-8'); ?>" inputmode="numeric" pattern="\d{10}" maxlength="10" placeholder="0788888888" title="Phone number must be exactly 10 digits" required></div>
                <div class="col-6"><label class="form-label small fw-semibold text-muted">Email</label><input type="email" name="email" class="form-control rm-input" value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
            </div>

            <label class="form-label small fw-semibold text-muted">Location</label>
            <div class="row g-3 mb-3">
                <div class="col-4">
                    <select name="province" id="provinceSelect" class="form-select rm-input" required>
                        <option value="">Select province</option>
                    </select>
                </div>
                <div class="col-4">
                    <select name="district" id="districtSelect" class="form-select rm-input" required disabled>
                        <option value="">Select district</option>
                    </select>
                </div>
                <div class="col-4">
                    <select name="sector" id="sectorSelect" class="form-select rm-input" required disabled>
                        <option value="">Select sector</option>
                    </select>
                </div>
            </div>

            <div class="mb-4"><label class="form-label small fw-semibold text-muted">Address (optional detail e.g. landmark, street)</label><textarea name="address" class="form-control rm-input" rows="3" style="height:auto;"><?= htmlspecialchars($address ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></div>
            <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                <button type="submit" name="save" class="rm-btn rm-btn-primary">
                    <i class="bi bi-check-circle-fill me-2"></i>Save Customer</button>
                <a href="index.php" class="rm-btn rm-btn-secondary">
                    <i class="bi bi-x-circle-fill me-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div></div>

<script src="../../includes/rwanda_locations.js"></script>
<script>
(function () {
    var provinceSelect = document.getElementById('provinceSelect');
    var districtSelect = document.getElementById('districtSelect');
    var sectorSelect = document.getElementById('sectorSelect');

    // Preserve the previously selected values if the form re-renders after a
    // validation error, so the user doesn't have to reselect everything.
    var selectedProvince = <?= json_encode($province ?? '', JSON_UNESCAPED_UNICODE); ?>;
    var selectedDistrict = <?= json_encode($district ?? '', JSON_UNESCAPED_UNICODE); ?>;
    var selectedSector = <?= json_encode($sector ?? '', JSON_UNESCAPED_UNICODE); ?>;

    function fillSelect(select, options, selectedValue, placeholder) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        options.forEach(function (opt) {
            var el = document.createElement('option');
            el.value = opt;
            el.textContent = opt;
            if (opt === selectedValue) { el.selected = true; }
            select.appendChild(el);
        });
    }

    function populateProvinces() {
        fillSelect(provinceSelect, Object.keys(RWANDA_LOCATIONS), selectedProvince, 'Select province');
    }

    function populateDistricts() {
        var districts = provinceSelect.value ? Object.keys(RWANDA_LOCATIONS[provinceSelect.value] || {}) : [];
        districtSelect.disabled = districts.length === 0;
        fillSelect(districtSelect, districts, selectedDistrict, 'Select district');
    }

    function populateSectors() {
        var sectors = (provinceSelect.value && districtSelect.value)
            ? (RWANDA_LOCATIONS[provinceSelect.value][districtSelect.value] || [])
            : [];
        sectorSelect.disabled = sectors.length === 0;
        fillSelect(sectorSelect, sectors, selectedSector, 'Select sector');
    }

    provinceSelect.addEventListener('change', function () {
        selectedDistrict = '';
        selectedSector = '';
        populateDistricts();
        populateSectors();
    });

    districtSelect.addEventListener('change', function () {
        selectedSector = '';
        populateSectors();
    });

    populateProvinces();
    populateDistricts();
    populateSectors();
})();
</script>

<?php include '../../includes/footer.php'; ?>