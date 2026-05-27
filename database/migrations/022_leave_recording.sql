-- migrations/022_leave_recording.sql
-- Adds attendance-recording tracking to leave_requests so that Admin must
-- explicitly mark the principal's decision as "recorded" (attendance updated)
-- before the request appears in the final Approved / Rejected tabs.
--
-- Workflow after this migration:
--   PENDING_REVIEW → FORWARDED → (principal decides) →
--   To Be Recorded (is_attendance_recorded=0) → Recorded (=1)
--
-- Run once manually. The ALTER is NOT idempotent; skip if column already exists.

ALTER TABLE `leave_requests`
    ADD COLUMN `is_attendance_recorded` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Admin has recorded principal decision in attendance (0=pending, 1=done)'
        AFTER `remarks`,
    ADD COLUMN `recorded_at` TIMESTAMP NULL DEFAULT NULL
        AFTER `is_attendance_recorded`,
    ADD COLUMN `recorded_by` INT NULL DEFAULT NULL
        AFTER `recorded_at`;

-- Backfill: existing approved/rejected records are treated as already recorded
-- so they continue to appear in the Approved/Rejected tabs without disruption.
UPDATE `leave_requests`
SET `is_attendance_recorded` = 1
WHERE `status` IN ('APPROVED', 'REJECTED');
