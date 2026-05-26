-- migrations/017_leave_type_future.sql
-- Adds per-leave-type future-date control and filing mode tracking.
-- Run once manually after 016_leave_workflow.sql.

-- 1. allow_future flag on leave_types
--    allow_future = 1 (default): employee may select future dates (Vacation, Maternity, Paternity)
--    allow_future = 0: only past/current dates allowed (Sick Leave, Emergency Leave)
ALTER TABLE leave_types
  ADD COLUMN allow_future TINYINT(1) NOT NULL DEFAULT 1;

-- Sick and Emergency leave types cannot select future dates
UPDATE leave_types
SET allow_future = 0
WHERE allow_backdated = 1;

-- 2. filing_mode on leave_requests
--    Tracks how the employee filed the request: SINGLE / MULTIPLE / RANGE
ALTER TABLE leave_requests
  ADD COLUMN filing_mode ENUM('SINGLE','MULTIPLE','RANGE') NOT NULL DEFAULT 'MULTIPLE'
  AFTER leave_type_id;
