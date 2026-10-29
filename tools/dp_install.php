<?php
/**
 * NEW FILE: tools/dp_install.php
 *
 * One-time database setup for Digital Payment. Safe to run again: it only adds what is missing.
 *
 *     php tools/dp_install.php            makes the changes
 *     php tools/dp_install.php --check    only reports what is missing, changes nothing
 *
 * It does two things:
 *   1. creates the table digital_payments (every try to pay: reference, amount, phone, status, the sale or credit it belongs to)
 *   2. adds 'Digital Payment' to the payment methods a sale can have (sales.payment_method). The four existing methods and every
 *      existing sale are untouched.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }
define('RM_OS_ALLOW_GUEST', true);           // no login in a command-line script
require __DIR__ . '/../config/db.php';

$check = in_array('--check', $argv, true);
$changed = 0;
$say = function (string $m) { echo $m . PHP_EOL; };

// 1. the table
$exists = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'digital_payments'"))[0];
if ($exists) {
    $say('OK       table digital_payments already exists');
} elseif ($check) {
    $say('MISSING  table digital_payments');
    $changed++;
} else {
    mysqli_query($conn, "CREATE TABLE digital_payments (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        reference       VARCHAR(40)  NOT NULL,                    -- our own unique reference, sent to XentriPay as customerRef
        purpose         ENUM('sale','credit') NOT NULL,           -- a new sale, or a payment on a credit sale
        sale_id         INT NULL,                                 -- credit: the invoice being paid. sale: set when the sale is recorded
        customer_id     INT NULL,
        amount          DECIMAL(12,2) NOT NULL,                   -- whole RWF
        msisdn          VARCHAR(15)  NOT NULL,                    -- 2507xxxxxxxx
        network         VARCHAR(10)  NOT NULL DEFAULT 'MTN',
        status          ENUM('initiated','pending','success','failed','cancelled','expired') NOT NULL DEFAULT 'initiated',
        gateway_refid   VARCHAR(80)  NULL,                        -- XentriPay's own reference
        failure_reason  VARCHAR(300) NULL,
        recorded_by     INT NOT NULL,                             -- the user who started it
        sale_payment_id INT NULL,                                 -- credit: the payment row it created
        applied_at      DATETIME NULL,                            -- when it was recorded against the sale / credit (set ONCE)
        applied_amount  DECIMAL(12,2) NULL,
        needs_attention TINYINT(1) NOT NULL DEFAULT 0,            -- money received but not (fully) recorded: the Admin must act
        note            VARCHAR(300) NULL,
        last_checked_at DATETIME NULL,
        confirmed_at    DATETIME NULL,                            -- when XentriPay confirmed it
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dp_reference (reference),
        UNIQUE KEY uq_dp_gateway (gateway_refid),
        UNIQUE KEY uq_dp_sale_payment (sale_payment_id),
        KEY idx_dp_status (status, created_at),
        KEY idx_dp_sale (sale_id),
        KEY idx_dp_user (recorded_by, created_at),
        CONSTRAINT fk_dp_user FOREIGN KEY (recorded_by) REFERENCES users (id),
        CONSTRAINT fk_dp_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $say('CREATED  table digital_payments');
    $changed++;
}

// 2. the new payment method
$col = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales' AND column_name = 'payment_method'"));
if (!$col) { $say('PROBLEM  sales.payment_method was not found. Is this the right database?'); exit(1); }
if (strpos($col['COLUMN_TYPE'], 'Digital Payment') !== false) {
    $say("OK       sales.payment_method already allows 'Digital Payment'");
} elseif ($check) {
    $say("MISSING  'Digital Payment' in sales.payment_method");
    $changed++;
} else {
    mysqli_query($conn, "ALTER TABLE sales MODIFY payment_method ENUM('Cash','Mobile Money','Bank Transfer','Credit','Digital Payment') NOT NULL DEFAULT 'Cash'");
    $say("CHANGED  sales.payment_method now allows 'Digital Payment'");
    $changed++;
}

$say($check ? ($changed ? "\n$changed thing(s) to do: run  php tools/dp_install.php" : "\nEverything is in place.") : "\nDone.");
exit(0);