-- RFID card registration: one card per employee, one employee per card.
-- Normalize any existing values the same way includes/rfid-utils.php does
-- (uppercase, no spaces/colons/hyphens) so the kiosk lookup keeps matching.
UPDATE employees
SET rfid_uid = NULLIF(UPPER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(rfid_uid), ' ', ''), ':', ''), '-', ''), '\t', '')), '')
WHERE rfid_uid IS NOT NULL;

-- If this fails with a duplicate-key error, two employees share a card UID:
-- clear one of them before re-running.
ALTER TABLE employees
    ADD COLUMN rfid_registered_at DATETIME NULL DEFAULT NULL AFTER rfid_uid,
    ADD UNIQUE KEY uq_employees_rfid_uid (rfid_uid);
