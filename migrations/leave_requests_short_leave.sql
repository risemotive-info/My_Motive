-- Adds start_time/end_time so a single-day "Short Leave" (e.g. lunch,
-- a few hours out) can be recorded alongside the existing full-day types.

ALTER TABLE leave_requests
  ADD COLUMN start_time TIME NULL,
  ADD COLUMN end_time TIME NULL;