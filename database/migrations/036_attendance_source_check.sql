-- Migration 036: reject invalid/empty attendance sources.
-- Apply after 035 and after resolving any existing invalid/empty sources.
-- Existing invalid data causes this statement to fail; it is not rewritten.
ALTER TABLE attendance_records
    ADD CONSTRAINT chk_attendance_source_canonical
    CHECK (attendance_source IN ('MANUAL', 'FACE', 'RFID', 'AUTO'));
