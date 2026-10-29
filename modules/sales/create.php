<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require '../../includes/sales_helpers.php';   // also loads fund_helpers.php and profit_rules.php (require_once)
require '../../includes/product_unit_helpers.php';
require_once '../../includes/sms_messages.php'; // NEW: customer SMS (MY MOTIVE SMS, messages 2 and 3)
require_once '../../includes/digital_payment_helpers.php'; // NEW: Digital Payment (MTN MoMo through XentriPay)
require_role(['Admin', 'Manager', 'Employee']);

$customers = mysqli_query($conn, 'SELECT id, name, phone FROM customers WHERE is_active = 1 ORDER BY name'); // CHANGED: phone, to fill in the Digital Payment pop-up
$customerList = [];
while ($c = mysqli_fetch_assoc($customers)) { $customerList[] = $c; }

$catalog = mysqli_query($conn, "SELECT id, item_type, product_name, product_code, selling_price, quantity, unit FROM products WHERE is_active = 1 ORDER BY item_type, product_name");
$catalogList = [];
while ($p = mysqli_fetch_assoc($catalog)) { $catalogList[] = $p; }

$itemProductIds = array_map(function ($p) { return $p['id']; }, array_filter($catalogList, function ($p) { return $p['item_type'] === 'Item'; }));
$unitsMap = product_units_map($conn, $itemProductIds);

if (isset($_POST['save'])) {
    $customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT) ?: null;
    $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
    $paymentMethod = $_POST['payment_method'] ?? '';
    $digitalPaymentId = (int) filter_input(INPUT_POST, 'digital_payment_id', FILTER_VALIDATE_INT); // NEW: the CONFIRMED Digital Payment of this sale
    $discountAmount = filter_input(INPUT_POST, 'discount_amount', FILTER_VALIDATE_FLOAT);
    $discountAmount = $discountAmount === false || $discountAmount === null ? 0 : $discountAmount;
    $amountPaidInput = filter_input(INPUT_POST, 'amount_paid', FILTER_VALIDATE_FLOAT);
    $amountPaidInput = $amountPaidInput === false || $amountPaidInput === null ? 0 : $amountPaidInput;

    $catalogIds = $_POST['catalog_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unitChoices = $_POST['unit_choice'] ?? [];
    $unitPrices = $_POST['unit_price'] ?? [];

    $validDate = DateTime::createFromFormat('Y-m-d', $saleDate);
    $lineItems = [];
    $subtotal = 0;
    $lineError = null;

    // CHANGED: total base units requested per product across ALL lines. Previously each line was
    // checked against the full stock on its own, so the same product on two lines could oversell.
    $requestedBaseUnits = [];

    foreach ($catalogIds as $index => $catalogId) {
        $catalogId = (int) $catalogId;
        $qty = (int) ($quantities[$index] ?? 0);
        if ($catalogId <= 0 || $qty <= 0) { continue; }

        $found = null;
        foreach ($catalogList as $p) { if ((int) $p['id'] === $catalogId) { $found = $p; break; } }
        if (!$found) { continue; }

        $isService = $found['item_type'] === 'Service';
        $packLabel = 'Piece';
        $packSize = 1;

        if (!$isService) {
            // Re-validate the chosen unit server-side against this product's
            // actual defined units — never trust a client-submitted pack size.
            $productUnits = $unitsMap[$catalogId] ?? [];
            $selectedUnitName = trim($unitChoices[$index] ?? '');
            $matchedUnit = null;
            foreach ($productUnits as $u) { if ($u['unit_name'] === $selectedUnitName) { $matchedUnit = $u; break; } }
            if (!$matchedUnit) {
                $lineError = 'Please select a valid unit for "' . $found['product_name'] . '".';
                break;
            }
            $packLabel = $matchedUnit['unit_name'];
            $packSize = $matchedUnit['pack_size'];

            // CHANGED: check the running total for this product, not just this line.
            $baseUnitsRequested = $qty * $packSize;
            $requestedBaseUnits[$catalogId] = ($requestedBaseUnits[$catalogId] ?? 0) + $baseUnitsRequested;
            if ($requestedBaseUnits[$catalogId] > (int) $found['quantity']) {
                $lineError = 'Not enough stock for "' . $found['product_name'] . '" (only ' . $found['quantity'] . ' ' . ($found['unit'] ?: 'units') . ' available, all lines for this product need ' . $requestedBaseUnits[$catalogId] . ').';
                break;
            }
        }

        $price = (float) ($unitPrices[$index] ?? 0);
        // NEW: a negative price would create negative revenue and fake profit.
        if ($price < 0) {
            $lineError = 'The price of "' . $found['product_name'] . '" cannot be negative.';
            break;
        }
        $lineTotal = $qty * $price;
        $subtotal += $lineTotal;

        $lineItems[] = [
            'item_type' => $isService ? 'Service' : 'Product',
            'product_id' => $catalogId,
            'service_name' => null,
            'unit' => !$isService ? $found['unit'] : null,
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $lineTotal,
            'pack_label' => $packLabel,
            'pack_size' => $packSize,
        ];
    }

    if (!$validDate || $validDate->format('Y-m-d') !== $saleDate || !in_array($paymentMethod, ['Cash', 'Mobile Money', 'Bank Transfer', 'Credit', 'Digital Payment'], true)) {
        $error = 'Please provide a valid sale date and payment method.';
    } elseif (empty($lineItems)) {
        $error = 'Add at least one item or service with a valid quantity.';
    } elseif ($lineError) {
        $error = $lineError;
    } elseif ($paymentMethod === 'Credit' && !$customerId) {
        // CHANGED: a Walk-in Customer used to skip the credit approval check entirely,
        // leaving an unpaid balance with nobody recorded to collect it from.
        $error = 'Credit sales need a registered customer. Please select a customer instead of Walk-in.';
    } elseif ($discountAmount < 0 || $discountAmount > $subtotal) {
        $error = 'Discount cannot be negative or greater than the subtotal.';
    } elseif ($paymentMethod === 'Digital Payment' && ($digitalProblem = dp_validate_for_sale($conn, $digitalPaymentId, (int) current_user_id(), (float) $amountPaidInput)) !== null) {
        // NEW: a Digital Payment sale can only be recorded with a payment that XentriPay has CONFIRMED (and that was not used before).
        $error = $digitalProblem;
    } else {
        $totalAmount = $subtotal - $discountAmount;
        if ($paymentMethod !== 'Credit' && $totalAmount > 0 && $amountPaidInput <= 0) {
            $error = 'Please enter the Amount Paid. It can only be zero when the payment method is Credit.';
        } elseif ($amountPaidInput < 0 || $amountPaidInput > $totalAmount + 0.01) {
            $error = 'Amount paid cannot be negative or greater than the total.';
        } else {
            $userId = current_user_id();
            $role = current_user_role();

            $needsDiscountApproval = $discountAmount > 0 && $role === 'Employee';

            // Automatic Credit Eligibility: customers with fewer than 500
            // Loyalty Points cannot complete a Credit sale without
            // Manager/Admin approval (see approve_discount.php).
            $needsCreditApproval = false;
            if ($paymentMethod === 'Credit' && $customerId) {
                $customerPointsStatement = mysqli_prepare($conn, 'SELECT loyalty_points FROM customers WHERE id = ?');
                mysqli_stmt_bind_param($customerPointsStatement, 'i', $customerId);
                mysqli_stmt_execute($customerPointsStatement);
                $customerPointsRow = mysqli_fetch_assoc(mysqli_stmt_get_result($customerPointsStatement));
                $customerLoyaltyPoints = $customerPointsRow ? (int) $customerPointsRow['loyalty_points'] : 0;
                $needsCreditApproval = customer_credit_status($customerLoyaltyPoints) === 'Not Allowed';
            }

            $needsApproval = $needsDiscountApproval || $needsCreditApproval;
            $status = $needsApproval ? 'Pending Discount Approval' : sales_compute_status($amountPaidInput, $totalAmount);
            $discountRequestedBy = $discountAmount > 0 ? $userId : null;
            $discountApprovedBy = ($discountAmount > 0 && !$needsApproval) ? $userId : null;
            $discountApprovedAt = ($discountAmount > 0 && !$needsApproval) ? date('Y-m-d H:i:s') : null;
            $needsCreditApprovalInt = (int) $needsCreditApproval;

            // CHANGED: the sale, its lines, the stock deduction, the income record and the fund
            // allocation are saved together. If any step fails, NOTHING is saved and the user
            // sees a clear message instead of a half-recorded sale.
            $saved = false;
            mysqli_begin_transaction($conn);
            try {
                $saleStatement = mysqli_prepare($conn, 'INSERT INTO sales
                    (customer_id, sale_date, subtotal, discount_amount, total_amount, amount_paid, payment_method, status, discount_requested_by, discount_approved_by, discount_approved_at, recorded_by, needs_credit_approval)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($saleStatement, 'isddddssiisii',
                    $customerId, $saleDate, $subtotal, $discountAmount, $totalAmount, $amountPaidInput, $paymentMethod, $status,
                    $discountRequestedBy, $discountApprovedBy, $discountApprovedAt, $userId, $needsCreditApprovalInt);
                mysqli_stmt_execute($saleStatement);
                $saleId = mysqli_insert_id($conn);

                foreach ($lineItems as $item) {
                    $itemStatement = mysqli_prepare($conn, 'INSERT INTO sale_items (sale_id, item_type, product_id, service_name, quantity, unit, unit_price, line_total, pack_label, pack_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($itemStatement, 'isisssddsi', $saleId, $item['item_type'], $item['product_id'], $item['service_name'], $item['quantity'], $item['unit'], $item['unit_price'], $item['line_total'], $item['pack_label'], $item['pack_size']);
                    mysqli_stmt_execute($itemStatement);
                }

                if ($paymentMethod === 'Digital Payment') {
                    // NEW: attach the CONFIRMED digital payment to this sale, once. If it was already used, nothing is saved.
                    dp_consume_for_sale($conn, $digitalPaymentId, (int) $saleId, (int) $userId, (float) $amountPaidInput);
                }

                if (!$needsApproval) {
                    // CHANGED: passes the user. A fully paid sale allocates its profit to the Funds here.
                    sales_finalize($conn, $saleId, $userId ? (int) $userId : null);
                }

                mysqli_commit($conn);
                $saved = true;
            } catch (mysqli_sql_exception $e) {
                mysqli_rollback($conn);
                error_log('new_sale failed: ' . $e->getMessage());
                $error = 'Unable to record the sale. Nothing was saved. Please try again.';
                // NEW: an Admin also sees the technical cause (e.g. a missing column), so problems on
                // the live server can be fixed without access to its error log.
                if (current_user_role() === 'Admin') { $error .= ' [Technical detail for Admin: ' . $e->getMessage() . ']'; }
            } catch (RuntimeException $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage() . ' Nothing was saved.';
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                error_log('new_sale failed: ' . $e->getMessage());
                $error = 'Unable to record the sale. Nothing was saved. Please try again.';
                if (current_user_role() === 'Admin') {
                    $error .= ' [Technical detail for Admin: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ' line ' . $e->getLine() . ']';
                }
            }

            if ($saved) {
                // NEW: receipt (paid) or credit SMS to the customer, only once the sale is saved and
                // completed. A sale waiting for approval gets its SMS when it is approved.
                if (!$needsApproval) {
                    sms_notify_sale_created($conn, (int) $saleId, $userId ? (int) $userId : null);
                }

                $successMessage = 'Sale recorded successfully.';
                if ($needsApproval) {
                    if ($needsDiscountApproval && $needsCreditApproval) {
                        $successMessage = 'Sale saved. Waiting for manager approval — this customer has fewer than 500 Loyalty Points and a discount was requested.';
                    } elseif ($needsCreditApproval) {
                        $successMessage = 'Sale saved. This customer has fewer than 500 Loyalty Points, so the Credit sale needs manager approval before it is completed.';
                    } else {
                        $successMessage = 'Sale saved. Waiting for manager approval of the discount before invoicing.';
                    }
                }

                header('Location: invoice.php?id=' . $saleId . '&success=' . urlencode($successMessage));
                exit;
            }
        }
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<style>
.rm-searchable { position: relative; }
.rm-searchable-dropdown {
    display: none;
    position: absolute;
    z-index: 30;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #E2E5EF;
    border-radius: 10px;
    max-height: 230px;
    overflow-y: auto;
    box-shadow: 0 10px 30px rgba(30,35,51,.10);
    padding: 4px;
}
.rm-searchable-option {
    padding: 8px 10px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    justify-content: space-between;
    gap: 8px;
}
.rm-searchable-option:hover,
.rm-searchable-option.active {
    background: #EEF1FE;
}
.rm-searchable-option .opt-meta {
    color: #8A90A3;
    font-size: 12px;
    white-space: nowrap;
}
.rm-searchable-empty {
    padding: 10px;
    font-size: 13px;
    color: #8A90A3;
    text-align: center;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>New Sale</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Sales</a>
</div>

<?php if (isset($error)) { ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
    <i class="bi bi-exclamation-circle-fill"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST" id="saleForm">
            <input type="hidden" name="digital_payment_id" id="digitalPaymentId" value=""><!-- NEW: set when the customer has paid by Digital Payment -->
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Customer</label>
                    <div class="rm-searchable" id="customerField"></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Sale Date</label>
                    <input type="date" name="sale_date" class="form-control rm-input" value="<?= date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Payment Method</label>
                    <select name="payment_method" class="form-select rm-input" required>
                        <option value="Cash">Cash</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Credit">Credit</option>
                        <?php $dpReady = xentripay_configured(); ?>
                        <option value="Digital Payment"<?= $dpReady ? '' : ' disabled'; ?>>Digital Payment<?= $dpReady ? '' : ' (not set up yet)'; ?></option>
                    </select>
                </div>
            </div>

            <label class="form-label small fw-semibold text-muted">Items</label>
            <table class="table table-bordered bg-white align-middle" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width:100px;">Type</th>
                        <th style="min-width:170px;">Item / Service</th>
                        <th style="width:140px;">Sold As</th>
                        <th style="width:80px;">Qty</th>
                        <th style="width:120px;">Price / Unit Sold</th>
                        <th style="width:110px;">Line Total</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <button type="button" id="addRow" class="rm-btn rm-btn-outline-primary rm-btn-sm mb-4"><i class="bi bi-plus-circle me-1"></i>Add Item</button>

            <div class="row g-3 mb-4 justify-content-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Discount (RWF)</label>
                    <input type="number" name="discount_amount" id="discountInput" class="form-control rm-input" min="0" step="0.01" value="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Amount Paid (RWF)</label>
                   <input type="number" name="amount_paid" id="amountPaidInput" class="form-control rm-input" min="0.01" step="0.01" placeholder="Enter amount received" required>
                </div>
            </div>

            <div class="text-end mb-4" style="font-size:14px;">
                <div>Subtotal: <strong id="subtotalDisplay">RWF 0.00</strong></div>
                <div>Total: <strong id="totalDisplay" style="font-size:18px; color:var(--accent-blue);">RWF 0.00</strong></div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-end">
                <button type="submit" name="save" class="rm-btn rm-btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Record Sale</button>
                <a href="index.php" class="rm-btn rm-btn-secondary">
                    <i class="bi bi-x-circle-fill me-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// CHANGED: escape any text before it is placed into the page with innerHTML, so product,
// customer or unit names containing < > " ' & cannot break the page or run script.
function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

const CATALOG = <?= json_encode(array_map(function ($p) {
    return ['id' => (int) $p['id'], 'type' => $p['item_type'], 'name' => $p['product_name'], 'code' => $p['product_code'], 'price' => (float) $p['selling_price'], 'stock' => (int) $p['quantity'], 'unit' => $p['unit']];
}, $catalogList), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const CUSTOMERS = <?= json_encode(array_map(function ($c) {
    return ['id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone'] ?? ''];
}, $customerList), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const UNITS_MAP = <?= json_encode($unitsMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>; // { productId: [ {unit_name, pack_size, is_base}, ... ] }, Items only

const itemsBody = document.querySelector('#itemsTable tbody');
const discountInput = document.getElementById('discountInput');
const amountPaidInput = document.getElementById('amountPaidInput');
const paymentMethodSelect = document.querySelector('[name="payment_method"]');
let currentTotal = 0;

// Credit may be left empty; every other method needs an amount above zero.
function syncAmountPaidRule() {
    const isCredit = paymentMethodSelect.value === 'Credit';
    const mustPay = !isCredit && currentTotal > 0;
    amountPaidInput.required = mustPay;
    amountPaidInput.min = mustPay ? '0.01' : '0';
    amountPaidInput.placeholder = isCredit ? '0 (optional on credit)' : 'Enter amount received';
}
paymentMethodSelect.addEventListener('change', syncAmountPaidRule);

/**
 * Small reusable "type to filter" dropdown.
 * Renders a text input + hidden input (for form submission) + a filtered list.
 */
function createSearchable(wrapper, options) {
    const {
        items,               // array of { id, label, meta, ...extra }
        hiddenName,          // name attribute for the posted hidden input
        placeholder,
        initialLabel,        // text shown before anything is picked
        initialValue,        // value for the hidden input before anything is picked
        onSelect,             // called with the chosen item (or null when cleared)
    } = options;

    // CHANGED: all values escaped.
    wrapper.innerHTML =
        '<input type="text" class="form-control rm-input rs-input" autocomplete="off" placeholder="' + escapeHtml(placeholder) + '" value="' + escapeHtml(initialLabel || '') + '">' +
        '<input type="hidden" name="' + escapeHtml(hiddenName) + '" value="' + escapeHtml(initialValue !== undefined ? initialValue : '') + '">';

    const input = wrapper.querySelector('.rs-input');
    const hidden = wrapper.querySelector('input[type=hidden]');
    let currentItems = items;

    const dropdown = document.createElement('div');
    dropdown.className = 'rm-searchable-dropdown';
    dropdown.style.position = 'fixed';
    dropdown.style.display = 'none';
    document.body.appendChild(dropdown);

    function positionDropdown() {
        const rect = input.getBoundingClientRect();
        dropdown.style.left = rect.left + 'px';
        dropdown.style.top = (rect.bottom + 4) + 'px';
        dropdown.style.width = rect.width + 'px';
    }

    function renderList(filterText) {
        const f = (filterText || '').trim().toLowerCase();
        const filtered = f === '' ? currentItems : currentItems.filter(function (it) {
            return it.label.toLowerCase().includes(f);
        });

        if (filtered.length === 0) {
            dropdown.innerHTML = '<div class="rm-searchable-empty">No matches found</div>';
        } else {
            // CHANGED: id, label and meta escaped.
            dropdown.innerHTML = filtered.map(function (it) {
                const meta = it.meta ? '<span class="opt-meta">' + escapeHtml(it.meta) + '</span>' : '';
                return '<div class="rm-searchable-option" data-id="' + escapeHtml(it.id) + '"><span>' + escapeHtml(it.label) + '</span>' + meta + '</div>';
            }).join('');
        }
        positionDropdown();
        dropdown.style.display = 'block';
    }

    input.addEventListener('focus', function () { renderList(input.value === (initialLabel || '') ? '' : input.value); });
    input.addEventListener('input', function () {
        hidden.value = '';
        if (onSelect) { onSelect(null); }
        renderList(input.value);
    });

    dropdown.addEventListener('mousedown', function (e) {
        const optEl = e.target.closest('.rm-searchable-option');
        if (!optEl) { return; }
        const id = optEl.getAttribute('data-id');
        const item = currentItems.find(function (it) { return String(it.id) === String(id); });
        if (!item) { return; }
        input.value = item.label;
        hidden.value = item.id;
        dropdown.style.display = 'none';
        if (onSelect) { onSelect(item); }
    });

    document.addEventListener('click', function (e) {
        if (!wrapper.contains(e.target) && !dropdown.contains(e.target)) { dropdown.style.display = 'none'; }
    });
    window.addEventListener('scroll', function () {
        if (dropdown.style.display === 'block') { positionDropdown(); }
    }, true);
    window.addEventListener('resize', function () {
        if (dropdown.style.display === 'block') { positionDropdown(); }
    });

    return {
        setItems: function (newItems) { currentItems = newItems; },
        reset: function (label, value) {
            input.value = label || '';
            hidden.value = value !== undefined ? value : '';
        },
        destroy: function () { dropdown.remove(); },
    };
}

// --- Customer field ---
createSearchable(document.getElementById('customerField'), {
    items: [{ id: '', label: 'Walk-in Customer' }].concat(CUSTOMERS.map(function (c) { return { id: c.id, label: c.name }; })),
    hiddenName: 'customer_id',
    placeholder: 'Search or select customer',
    initialLabel: 'Walk-in Customer',
    initialValue: '',
});

// --- Item / Service rows ---
function catalogItemsFor(itemType) {
    return CATALOG.filter(function (p) { return p.type === itemType; }).map(function (p) {
        return {
            id: p.id,
            label: p.name + ' (' + p.code + ')',
            meta: itemType === 'Item' ? (p.stock + ' ' + p.unit + ' in stock') : '',
            price: p.price,
            stock: p.stock,
            unit: p.unit,
        };
    });
}

function buildRow() {
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML =
        '<td>' +
            '<select class="form-select rm-input type-select">' +
                '<option value="Item">Item</option>' +
                '<option value="Service">Service</option>' +
            '</select>' +
        '</td>' +
        '<td class="target-cell"><div class="rm-searchable catalog-field"></div></td>' +
        '<td class="unit-cell"><span class="text-muted small">N/A</span></td>' +
        '<td><input type="number" class="form-control rm-input qty-input" name="quantity[]" min="1" value="1" required></td>' +
        '<td><input type="number" class="form-control rm-input price-input" name="unit_price[]" min="0" step="0.01" value="0.00"></td>' +
        '<td><span class="line-total">0.00</span></td>' +
        '<td><button type="button" class="btn btn-outline-danger btn-sm remove-row">&times;</button></td>';
    return tr;
}

function bindRow(row) {
    const typeSelect = row.querySelector('.type-select');
    const catalogFieldEl = row.querySelector('.catalog-field');
    const unitCell = row.querySelector('.unit-cell');
    const qty = row.querySelector('.qty-input');
    const priceInput = row.querySelector('.price-input');
    const lineTotalEl = row.querySelector('.line-total');
    const removeBtn = row.querySelector('.remove-row');

    let catalogWidget = null;
    let basePrice = 0; // per-base-unit selling price for the currently selected Item

    function populateUnitCell(productId) {
        const units = UNITS_MAP[productId] || [{ unit_name: 'Piece', pack_size: 1, is_base: true }];
        const select = document.createElement('select');
        select.className = 'form-select rm-input';
        select.name = 'unit_choice[]';
        // CHANGED: unit names escaped (value and visible text).
        select.innerHTML = units.map(function (u) {
            return '<option value="' + escapeHtml(u.unit_name) + '" data-pack-size="' + escapeHtml(u.pack_size) + '">'
                + escapeHtml(u.unit_name) + (u.pack_size > 1 ? ' (' + escapeHtml(u.pack_size) + ' each)' : '') + '</option>';
        }).join('');
        unitCell.innerHTML = '';
        unitCell.appendChild(select);
        select.addEventListener('change', function () {
            const selected = select.options[select.selectedIndex];
            const packSize = selected ? parseInt(selected.getAttribute('data-pack-size') || 1) : 1;
            priceInput.value = (basePrice * packSize).toFixed(2);
            updateTotal();
        });
        // Trigger the price prefill for the default (first) unit selected.
        select.dispatchEvent(new Event('change'));
    }

    function handleSelect(item) {
        const price = item ? item.price : 0;
        basePrice = price;
        if (typeSelect.value === 'Item' && item) {
            populateUnitCell(item.id);
        } else if (typeSelect.value === 'Service') {
            priceInput.value = price.toFixed(2);
        } else {
            unitCell.innerHTML = '<span class="text-muted small">Select item</span>';
            priceInput.value = '0.00';
        }
        updateTotal();
    }

    function initCatalogField() {
        if (catalogWidget) { catalogWidget.destroy(); }
        catalogWidget = createSearchable(catalogFieldEl, {
            items: catalogItemsFor(typeSelect.value),
            hiddenName: 'catalog_id[]',
            placeholder: 'Select ' + (typeSelect.value === 'Service' ? 'service' : 'item'),
            initialLabel: '',
            initialValue: '',
            onSelect: handleSelect,
        });
        priceInput.value = '0.00';
        unitCell.innerHTML = typeSelect.value === 'Service'
            ? '<span class="text-muted small">N/A</span>'
            : '<span class="text-muted small">Select item</span>';
        updateTotal();
    }

    function updateTotal() {
        const price = parseFloat(priceInput.value || 0);
        const q = parseInt(qty.value || 0);
        lineTotalEl.textContent = (price * q).toFixed(2);
        recalcTotals();
    }

    typeSelect.addEventListener('change', initCatalogField);
    qty.addEventListener('input', updateTotal);
    priceInput.addEventListener('input', updateTotal);
    removeBtn.addEventListener('click', function () {
        if (itemsBody.querySelectorAll('.item-row').length > 1) {
            if (catalogWidget) { catalogWidget.destroy(); }
            row.remove();
            recalcTotals();
        }
    });

    initCatalogField();
}

function recalcTotals() {
    let subtotal = 0;
    itemsBody.querySelectorAll('.item-row').forEach(function (row) {
        subtotal += parseFloat(row.querySelector('.line-total').textContent || 0);
    });
    const discount = parseFloat(discountInput.value || 0);
    const total = Math.max(0, subtotal - discount);
    document.getElementById('subtotalDisplay').textContent = 'RWF ' + subtotal.toFixed(2);
    document.getElementById('totalDisplay').textContent = 'RWF ' + total.toFixed(2);
    amountPaidInput.max = total;
    currentTotal = total;
    syncAmountPaidRule();
}

document.getElementById('addRow').addEventListener('click', function () {
    const row = buildRow();
    itemsBody.appendChild(row);
    bindRow(row);
});

discountInput.addEventListener('input', recalcTotals);

// Start with one row.
document.getElementById('addRow').click();
</script>
<?php include '../../includes/digital_payment_ui.php'; ?>
<script>
// NEW: Digital Payment (MTN MoMo through XentriPay) for a new sale.
// With any other payment method nothing here does anything. With Digital Payment, pressing Record Sale opens the pop-up
// (amount + the customer's MTN MoMo number), waits for the customer to approve on their phone, and ONLY THEN saves the sale.
(function () {
    const form = document.getElementById('saleForm');
    const idField = document.getElementById('digitalPaymentId');
    const saveButton = form.querySelector('button[name="save"]');
    const UNRECORDED = <?= json_encode(dp_unrecorded_for_user($conn, (int) current_user_id()), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const isDigital = function () { return paymentMethodSelect.value === 'Digital Payment'; };
    const customerId = function () { const f = form.querySelector('input[name="customer_id"]'); return f ? f.value : ''; };
    const customerPhone = function () { const c = CUSTOMERS.find(function (x) { return String(x.id) === String(customerId()); }); return c ? c.phone : ''; };

    // The amount is asked in the pop-up, so the Amount Paid box must not stop the button first.
    saveButton.addEventListener('click', function () {
        if (isDigital() && !idField.value) { amountPaidInput.required = false; amountPaidInput.min = '0'; }
    });
    // Changing the payment method forgets a confirmed payment that has not been used (it stays in the list of received payments).
    paymentMethodSelect.addEventListener('change', function () { idField.value = ''; });

    form.addEventListener('submit', function (e) {
        if (!isDigital() || idField.value) { return; }
        e.preventDefault();
        if (!(currentTotal > 0)) { alert('Add at least one item with a price before taking a Digital Payment.'); syncAmountPaidRule(); return; }
        DigitalPay.pay({
            purpose: 'sale',
            amount: parseFloat(amountPaidInput.value) || currentTotal,
            maxAmount: currentTotal,
            phone: customerPhone(),
            customerId: customerId(),
            resume: UNRECORDED,
            onClose: function () { syncAmountPaidRule(); },
            onSuccess: function (attempt) {
                // XentriPay has CONFIRMED the payment: now the sale is recorded, with exactly that amount.
                idField.value = attempt.id;
                amountPaidInput.value = attempt.amount;
                const save = document.createElement('input'); save.type = 'hidden'; save.name = 'save'; save.value = '1';
                form.appendChild(save);
                form.submit();
            }
        });
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>