-- Migration 025: Add PARTIALLY_APPROVED to leave_requests.status enum
-- This status holds mixed decisions (some dates approved, some rejected)
-- until the admin records the result, at which point the split occurs.

ALTER TABLE `leave_requests`
  MODIFY COLUMN `status`
    ENUM('PENDING','APPROVED','REJECTED','PARTIALLY_APPROVED','CANCELLED')
    NOT NULL DEFAULT 'PENDING';
