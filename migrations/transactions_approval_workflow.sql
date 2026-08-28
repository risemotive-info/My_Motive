-- Adds approval workflow columns to the transactions table.

ALTER TABLE transactions
  ADD COLUMN recorded_by INT(11) NULL,
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'approved',
  ADD COLUMN approved_by INT(11) NULL,
  ADD COLUMN approved_at DATETIME NULL,
  ADD COLUMN rejection_reason TEXT NULL;