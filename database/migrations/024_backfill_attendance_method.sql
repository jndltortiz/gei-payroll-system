-- Migration 024: Backfill attendance_source for existing manually-entered records
-- Any row with NULL or empty attendance_source was created through the admin system
-- and is treated as MANUAL_ADMIN. Safe to re-run (WHERE clause is idempotent).

UPDATE `attendance_records`
SET `attendance_source` = 'MANUAL_ADMIN'
WHERE `attendance_source` IS NULL
   OR `attendance_source` = '';
