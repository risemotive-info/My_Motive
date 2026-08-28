-- Adds approval workflow columns to the transactions table.
-- Safe to run multiple times (IF NOT EXISTS guards).

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS recorded_by INT(11) NULL,
  ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'approved',
  ADD COLUMN IF NOT EXISTS approved_by INT(11) NULL,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL;