-- Migration 035: canonical attendance source enum and default only.
-- Select the intended database before execution. Does not repair any rows.
-- Preserve existing ENUM positions; append AUTO.
ALTER TABLE attendance_records
    MODIFY COLUMN attendance_source
    ENUM('FACE', 'RFID', 'MANUAL', 'AUTO') NOT NULL DEFAULT 'MANUAL';
