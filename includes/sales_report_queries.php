<?php
/**
 * Data layer for the Sales reports.
 * sales_report_build() returns an array in the shape documented in report_pdf.php,
 * so the on-screen page and the PDF both render from exactly the same data.
 *
 * Rules used throughout:
 *  - A "recorded sale" has status Paid, Partially Paid or Credit.
 *    Pending Discount Approval and Cancelled sales are not counted as sales.
 *  - Product cost = quantity x pack_size x products.buying_price (buying_price is per base unit).
 *  - Service cost = (1 - SALES_SERVICE_PROFIT_RATE) of the service's net amount, i.e. services earn 80% profit.
 *  - Profit = net sales amount - cost. Discounts are spread across lines pro rata.
 *  - Employee = the user in sales.recorded_by.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/profit_rules.php'; // service profit rate and line profit/cost SQL

const SALES_REPORT_VALID_STATUS = "('Paid','Partially Paid','Credit')";

/** Prepared-statement helper: returns all rows as associative arrays. */
function sales_report_fetch(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/** Lists used to fill the filter dropdowns. */
function sales_report_lookups(mysqli $conn): array
{
    return [
        'products'  => sales_report_fetch($conn, "SELECT id, product_name, product_code, item_type FROM products ORDER BY item_type, product_name"),
        'customers' => sales_report_fetch($conn, "SELECT id, name FROM customers ORDER BY name"),
        'employees' => sales_report_fetch($conn, "SELECT id, names FROM users ORDER BY names"),
        'payments'  => ['Cash', 'Mobile Money', 'Bank Transfer', 'Credit', 'Digital Payment'],
    ];
}

/**
 * One row per sale, with items text and cost worked out.
 * $where uses alias s (sales); extra params are bound in order.
 */
function sales_report_sale_rows(mysqli $conn, string $where, string $types, array $params, string $order = 's.sale_date, s.id'): array
{
    mysqli_query($conn, 'SET SESSION group_concat_max_len = 8192');
    $sql = "SELECT s.id AS sale_id, s.sale_date, s.payment_method, s.status,
                   s.subtotal, s.discount_amount, s.total_amount, s.amount_paid,
                   s.loyalty_points_earned, s.cancelled_at, s.cancel_reason,
                   c.name AS customer, u.names AS employee, cu.names AS cancelled_by_name,
                   (SELECT GROUP_CONCAT(CONCAT(COALESCE(p.product_name, si.service_name), ' x', si.quantity,
                            IF(si.item_type = 'Product' AND si.pack_label IS NOT NULL AND si.pack_label <> '', CONCAT(' ', si.pack_label), ''))
                            ORDER BY si.id SEPARATOR '; ')
                      FROM sale_items si LEFT JOIN products p ON p.id = si.product_id
                     WHERE si.sale_id = s.id) AS items,
                   (SELECT COALESCE(SUM(" . sales_report_line_cost_sql('s', 'si', 'p') . "), 0)
                      FROM sale_items si LEFT JOIN products p ON p.id = si.product_id
                     WHERE si.sale_id = s.id) AS cost
              FROM sales s
              LEFT JOIN customers c ON c.id = s.customer_id
              LEFT JOIN users u ON u.id = s.recorded_by
              LEFT JOIN users cu ON cu.id = s.cancelled_by
             WHERE $where
             ORDER BY $order";
    $rows = sales_report_fetch($conn, $sql, $types, $params);
    foreach ($rows as &$r) {
        $r['customer'] = $r['customer'] ?: 'Walk-in Customer';
        $r['profit']   = (float) $r['total_amount'] - (float) $r['cost'];
        $r['balance']  = (float) $r['total_amount'] - (float) $r['amount_paid'];
    }
    unset($r);
    return $rows;
}

function sales_report_sum(array $rows, string $key): float
{
    $t = 0.0;
    foreach ($rows as $r) {
        $t += (float) $r[$key];
    }
    return $t;
}

/**
 * Main entry point.
 * $req is normally $_GET: report, period, from, to, product_id, customer_id, employee_id, payment_method.
 */
function sales_report_build(mysqli $conn, string $type, array $req): array
{
    $types = sales_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Sales Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }
    $period = report_period_from_request($req);
    if ($period['error']) {
        return $fail($period['error']);
    }
    $start = $period['start'];
    $end   = $period['end'];
    $filters = [['Period', $period['label']]];
    $valid = SALES_REPORT_VALID_STATUS;

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => [],
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [],
        'notes' => ['Amounts are net of discounts. Product profit = net amount minus purchase cost (buying price x quantity). Service profit = ' . round(SALES_SERVICE_PROFIT_RATE * 100) . '% of the net service amount.'],
    ];

    switch ($type) {
        // ------------------------------------------------------------------
        case 'product':
            $productId = (int) ($req['product_id'] ?? 0);
            if ($productId <= 0) {
                return $fail('Please select a product or service.');
            }
            $p = sales_report_fetch($conn, 'SELECT product_name, product_code, item_type FROM products WHERE id = ?', 'i', [$productId]);
            if (!$p) {
                return $fail('That product or service was not found.');
            }
            $filters[] = [$p[0]['item_type'] === 'Service' ? 'Service' : 'Product', $p[0]['product_name'] . ' (' . $p[0]['product_code'] . ')'];

            $rows = sales_report_fetch($conn,
                "SELECT s.id AS sale_id, s.sale_date, c.name AS customer, u.names AS employee,
                        si.quantity, si.pack_label,
                        si.quantity * COALESCE(NULLIF(si.pack_size, 0), 1) AS base_qty,
                        " . sales_report_line_net_sql() . " AS amount,
                        " . sales_report_line_net_sql() . " - " . sales_report_line_cost_sql() . " AS profit
                   FROM sale_items si
                   JOIN sales s ON s.id = si.sale_id
                   JOIN products p ON p.id = si.product_id
                   LEFT JOIN customers c ON c.id = s.customer_id
                   LEFT JOIN users u ON u.id = s.recorded_by
                  WHERE si.product_id = ? AND s.status IN $valid AND s.sale_date BETWEEN ? AND ?
                  ORDER BY s.sale_date, s.id, si.id",
                'iss', [$productId, $start, $end]);
            foreach ($rows as &$r) {
                $r['customer'] = $r['customer'] ?: 'Walk-in Customer';
                $r['sold_as']  = $r['pack_label'] ?: '';
            }
            unset($r);

            $saleIds = [];
            foreach ($rows as $r) { $saleIds[$r['sale_id']] = true; }
            $report['summary'] = [
                ['Times Sold (sales)', number_format(count($saleIds))],
                ['Quantity Sold (base units)', number_format(sales_report_sum($rows, 'base_qty'))],
                ['Total Amount', report_money(sales_report_sum($rows, 'amount'))],
                ['Profit Generated', report_money(sales_report_sum($rows, 'profit'))],
            ];
            $report['columns'] = [
                ['key' => 'sale_id', 'label' => 'Sale #', 'w' => 7, 'type' => 'int'],
                ['key' => 'sale_date', 'label' => 'Date', 'w' => 11, 'type' => 'date'],
                ['key' => 'customer', 'label' => 'Customer', 'w' => 20],
                ['key' => 'quantity', 'label' => 'Qty', 'w' => 6, 'align' => 'R', 'type' => 'int'],
                ['key' => 'sold_as', 'label' => 'Sold As', 'w' => 9],
                ['key' => 'base_qty', 'label' => 'Base Units', 'w' => 9, 'align' => 'R', 'type' => 'int'],
                ['key' => 'amount', 'label' => 'Amount (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
                ['key' => 'profit', 'label' => 'Profit (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
                ['key' => 'employee', 'label' => 'Recorded By', 'w' => 16],
            ];
            $report['rows'] = $rows;
            $report['totals'] = [
                'base_qty' => sales_report_sum($rows, 'base_qty'),
                'amount'   => sales_report_sum($rows, 'amount'),
                'profit'   => sales_report_sum($rows, 'profit'),
            ];
            break;

        // ------------------------------------------------------------------
        case 'customer':
            $cid = (string) ($req['customer_id'] ?? '');
            if ($cid === '') {
                return $fail('Please select a customer.');
            }
            $where = "s.status IN $valid AND s.sale_date BETWEEN ? AND ?";
            $bindTypes = 'ss';
            $params = [$start, $end];
            if ($cid === 'walkin') {
                $where .= ' AND s.customer_id IS NULL';
                $filters[] = ['Customer', 'Walk-in Customer'];
            } else {
                $c = sales_report_fetch($conn, 'SELECT name, loyalty_points FROM customers WHERE id = ?', 'i', [(int) $cid]);
                if (!$c) {
                    return $fail('That customer was not found.');
                }
                $where .= ' AND s.customer_id = ?';
                $bindTypes .= 'i';
                $params[] = (int) $cid;
                $filters[] = ['Customer', $c[0]['name']];
                $filters[] = ['Current Loyalty Points Balance', number_format((int) $c[0]['loyalty_points'])];
            }
            $rows = sales_report_sale_rows($conn, $where, $bindTypes, $params);
            $report['summary'] = [
                ['Transactions', number_format(count($rows))],
                ['Total Amount', report_money(sales_report_sum($rows, 'total_amount'))],
                ['Profit Generated', report_money(sales_report_sum($rows, 'profit'))],
                ['Loyalty Points Earned', number_format(sales_report_sum($rows, 'loyalty_points_earned'))],
            ];
            $report['columns'] = [
                ['key' => 'sale_id', 'label' => 'Sale #', 'w' => 6, 'type' => 'int'],
                ['key' => 'sale_date', 'label' => 'Date', 'w' => 10, 'type' => 'date'],
                ['key' => 'items', 'label' => 'Items / Services', 'w' => 30],
                ['key' => 'payment_method', 'label' => 'Payment', 'w' => 10],
                ['key' => 'total_amount', 'label' => 'Amount (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
                ['key' => 'profit', 'label' => 'Profit (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
                ['key' => 'loyalty_points_earned', 'label' => 'Points Earned', 'w' => 8, 'align' => 'R', 'type' => 'int'],
                ['key' => 'employee', 'label' => 'Recorded By', 'w' => 12],
            ];
            $report['rows'] = $rows;
            $report['totals'] = [
                'total_amount' => sales_report_sum($rows, 'total_amount'),
                'profit' => sales_report_sum($rows, 'profit'),
                'loyalty_points_earned' => sales_report_sum($rows, 'loyalty_points_earned'),
            ];
            break;

        // ------------------------------------------------------------------
        case 'employee':
            $eid = (int) ($req['employee_id'] ?? 0);
            if ($eid <= 0) {
                return $fail('Please select an employee.');
            }
            $e = sales_report_fetch($conn, 'SELECT names FROM users WHERE id = ?', 'i', [$eid]);
            if (!$e) {
                return $fail('That employee was not found.');
            }
            $filters[] = ['Employee', $e[0]['names']];
            $rows = sales_report_sale_rows($conn, "s.status IN $valid AND s.recorded_by = ? AND s.sale_date BETWEEN ? AND ?", 'iss', [$eid, $start, $end]);
            $report['summary'] = [
                ['Transactions Recorded', number_format(count($rows))],
                ['Total Amount', report_money(sales_report_sum($rows, 'total_amount'))],
                ['Profit Generated', report_money(sales_report_sum($rows, 'profit'))],
            ];
            $report['columns'] = [
                ['key' => 'sale_id', 'label' => 'Sale #', 'w' => 6, 'type' => 'int'],
                ['key' => 'sale_date', 'label' => 'Date', 'w' => 10, 'type' => 'date'],
                ['key' => 'customer', 'label' => 'Customer', 'w' => 15],
                ['key' => 'items', 'label' => 'Products / Services', 'w' => 32],
                ['key' => 'total_amount', 'label' => 'Amount (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
                ['key' => 'profit', 'label' => 'Profit (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ];
            $report['rows'] = $rows;
            $report['totals'] = ['total_amount' => sales_report_sum($rows, 'total_amount'), 'profit' => sales_report_sum($rows, 'profit')];
            break;

        // ------------------------------------------------------------------
        case 'payment':
            $method = (string) ($req['payment_method'] ?? '');
            if (!in_array($method, ['Cash', 'Mobile Money', 'Bank Transfer', 'Credit', 'Digital Payment'], true)) {
                return $fail('Please select a payment method.');
            }
            $filters[] = ['Payment Method', $method];
            $rows = sales_report_sale_rows($conn, "s.status IN $valid AND s.payment_method = ? AND s.sale_date BETWEEN ? AND ?", 'sss', [$method, $start, $end]);
            $report['summary'] = [
                ['Transactions', number_format(count($rows))],
                ['Total Amount', report_money(sales_report_sum($rows, 'total_amount'))],
                ['Profit Generated', report_money(sales_report_sum($rows, 'profit'))],
            ];
            $report['columns'] = [
                ['key' => 'sale_id', 'label' => 'Sale #', 'w' => 6, 'type' => 'int'],
                ['key' => 'sale_date', 'label' => 'Date', 'w' => 10, 'type' => 'date'],
                ['key' => 'customer', 'label' => 'Customer', 'w' => 15],
                ['key' => 'items', 'label' => 'Products / Services', 'w' => 30],
                ['key' => 'total_amount', 'label' => 'Amount (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
                ['key' => 'profit', 'label' => 'Profit (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
                ['key' => 'employee', 'label' => 'Recorded By', 'w' => 12],
            ];
            $report['rows'] = $rows;
            $report['totals'] = ['total_amount' => sales_report_sum($rows, 'total_amount'), 'profit' => sales_report_sum($rows, 'profit')];
            break;

        // ------------------------------------------------------------------
        case 'cancellations':
            $report['notes'] = ['Cancelled sales are listed by the date they were cancelled. The total shown is the sales value that was not realised.'];
            $rows = sales_report_sale_rows($conn,
                "s.status = 'Cancelled' AND DATE(COALESCE(s.cancelled_at, s.sale_date)) BETWEEN ? AND ?",
                'ss', [$start, $end], 's.cancelled_at, s.id');
            $report['summary'] = [
                ['Cancelled Sales', number_format(count($rows))],
                ['Amount Not Realised', report_money(sales_report_sum($rows, 'total_amount'))],
            ];
            $report['columns'] = [
                ['key' => 'sale_id', 'label' => 'Sale #', 'w' => 6, 'type' => 'int'],
                ['key' => 'sale_date', 'label' => 'Sale Date', 'w' => 10, 'type' => 'date'],
                ['key' => 'cancelled_at', 'label' => 'Cancelled On', 'w' => 12, 'type' => 'datetime'],
                ['key' => 'customer', 'label' => 'Customer', 'w' => 13],
                ['key' => 'items', 'label' => 'Items / Services', 'w' => 24],
                ['key' => 'total_amount', 'label' => 'Amount (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
                ['key' => 'cancel_reason', 'label' => 'Cancellation Reason', 'w' => 20],
                ['key' => 'cancelled_by_name', 'label' => 'Cancelled By', 'w' => 11],
                ['key' => 'employee', 'label' => 'Sale Recorded By', 'w' => 11],
            ];
            $report['rows'] = $rows;
            $report['totals'] = ['total_amount' => sales_report_sum($rows, 'total_amount')];
            break;

        // ------------------------------------------------------------------
        case 'summary':
            $rows = sales_report_sale_rows($conn, "s.status IN $valid AND s.sale_date BETWEEN ? AND ?", 'ss', [$start, $end]);
            $report['columns'] = [
                ['key' => 'sale_id', 'label' => 'Sale #', 'w' => 6, 'type' => 'int'],
                ['key' => 'sale_date', 'label' => 'Date', 'w' => 10, 'type' => 'date'],
                ['key' => 'customer', 'label' => 'Customer', 'w' => 14],
                ['key' => 'payment_method', 'label' => 'Payment', 'w' => 10],
                ['key' => 'status', 'label' => 'Status', 'w' => 9],
                ['key' => 'total_amount', 'label' => 'Total (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
                ['key' => 'amount_paid', 'label' => 'Paid (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
                ['key' => 'balance', 'label' => 'Balance (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
                ['key' => 'profit', 'label' => 'Profit (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
                ['key' => 'employee', 'label' => 'Recorded By', 'w' => 12],
            ];
            $report['rows'] = $rows;
            $report['totals'] = [
                'total_amount' => sales_report_sum($rows, 'total_amount'),
                'amount_paid'  => sales_report_sum($rows, 'amount_paid'),
                'balance'      => sales_report_sum($rows, 'balance'),
                'profit'       => sales_report_sum($rows, 'profit'),
            ];
            $report['footer_summary'] = [
                ['Total Transactions', number_format(count($rows))],
                ['Gross Sales (before discount)', report_money(sales_report_sum($rows, 'subtotal'))],
                ['Total Discounts', report_money(sales_report_sum($rows, 'discount_amount'))],
                ['Net Sales', report_money(sales_report_sum($rows, 'total_amount'))],
                ['Amount Received', report_money(sales_report_sum($rows, 'amount_paid'))],
                ['Outstanding (Credit / Part-paid)', report_money(sales_report_sum($rows, 'balance'))],
                ['Total Cost of Sales', report_money(sales_report_sum($rows, 'cost'))],
                ['OVERALL PROFIT', report_money(sales_report_sum($rows, 'profit'))],
            ];
            break;
    }

    $report['filters'] = $filters;
    return $report;
}