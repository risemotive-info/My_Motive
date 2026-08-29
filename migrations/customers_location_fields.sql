-- Adds structured location fields (dropdowns) alongside the existing
-- free-text address field on customers.

ALTER TABLE customers
  ADD COLUMN province VARCHAR(50) NULL,
  ADD COLUMN district VARCHAR(50) NULL,
  ADD COLUMN sector VARCHAR(50) NULL;