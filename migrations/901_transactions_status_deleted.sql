-- Deleting a payroll, a transaction, a loan, a reward or a purchase order keeps the money record and marks it as 'deleted'
-- (status = 'deleted', with who deleted it and when), so the history and the reports stay correct. But the list of values that
-- transactions.status could hold did not include that word, so a database that checks its values (every normal server) refused it,
-- and the page answered "Unable to delete ... Nothing was changed."
-- This ADDS 'deleted' to the list that is already there (anything else in the list is kept). If 'deleted' is already allowed,
-- nothing happens. Safe to run more than once.
SET @mm_sql = (SELECT IF(COLUMN_TYPE LIKE '%''deleted''%', 'SELECT 1',
    CONCAT('ALTER TABLE transactions MODIFY status ', REPLACE(COLUMN_TYPE, ')', ',''deleted'')'), ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT ''pending'''))
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'status');
PREPARE mm_stmt FROM @mm_sql;
EXECUTE mm_stmt;
DEALLOCATE PREPARE mm_stmt;
