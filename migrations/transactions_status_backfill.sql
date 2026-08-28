-- Normalizes pre-existing transaction status values to the new approval workflow.
-- Any transaction not already using the new 'approved' / 'pending' / 'rejected'
-- values (e.g. old rows marked 'Completed') is treated as approved, since it
-- was already considered final/complete before this workflow existed.

UPDATE transactions
SET status = 'approved'
WHERE status NOT IN ('approved', 'pending', 'rejected');