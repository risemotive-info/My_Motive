-- Adds the needs_credit_approval flag to sales, if it doesn't already exist.
-- If you get "Duplicate column name" running this, the column already
-- exists and you can safely ignore the error / skip this file.

ALTER TABLE sales
    ADD COLUMN needs_credit_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER discount_approved_at;