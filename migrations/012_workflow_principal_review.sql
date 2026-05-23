-- Migration 012: Enforce Principal-review workflow for all loans
-- All loans must start as PENDING; only Principal can activate.
-- Adds RETURNED status for "Return for Correction" flow.

-- 1. Expand status ENUM to include RETURNED
ALTER TABLE employee_loans
  MODIFY COLUMN status
    ENUM('PENDING','ACTIVE','PAUSED','COMPLETED','CANCELLED','DENIED','ARCHIVED','RETURNED')
    NOT NULL DEFAULT 'PENDING';

-- 2. Add return_reason column (stored when Principal returns a loan for correction)
ALTER TABLE employee_loans
  ADD COLUMN return_reason TEXT NULL AFTER cancelled_reason;

-- 3. Correct any ACTIVE loans that were added without going through Principal review
--    (i.e., loans created before this migration that have no approved_by — they were
--    set ACTIVE directly by admin). Leave them as ACTIVE since they are already running.
--    No data migration needed; this only enforces forward-going workflow.
