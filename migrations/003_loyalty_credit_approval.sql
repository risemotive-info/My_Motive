-- Migration: Add Loyalty Points & Automatic Credit Approval
-- Date: 2026-09-01
--
-- Adds:
--   customers.loyalty_points        - running Loyalty Points balance per customer
--   sales.loyalty_points_earned     - points awarded from this specific sale (for receipts)
--   sales.needs_credit_approval     - flags a Credit sale that needed Manager/Admin
--                                     approval because the customer had < 500 points
--
-- Safe to re-run: each statement is guarded so it won't error if the column
-- already exists (e.g. if this was partially applied before).

-- customers.loyalty_points
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'customers'
      AND COLUMN_NAME = 'loyalty_points'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE customers ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0',
    'SELECT "customers.loyalty_points already exists, skipping"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- sales.loyalty_points_earned
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sales'
      AND COLUMN_NAME = 'loyalty_points_earned'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE sales ADD COLUMN loyalty_points_earned INT NULL DEFAULT NULL',
    'SELECT "sales.loyalty_points_earned already exists, skipping"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- sales.needs_credit_approval
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sales'
      AND COLUMN_NAME = 'needs_credit_approval'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE sales ADD COLUMN needs_credit_approval TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT "sales.needs_credit_approval already exists, skipping"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
