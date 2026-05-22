-- ─────────────────────────────────────────────────────────────────────────────
-- Migration 007: Attendance Module Refactor
--
-- Modifies:
--   attendance_records  — adds overtime_minutes, review_status,
--                         school_year_id, is_archived
--
-- Creates:
--   attendance_audit_log  — full audit trail for every edit/timeout/create
--   dtr_attachments       — DTR backup file uploads per cutoff period
--
-- Prerequisites : migrations 001–006
-- Run against   : gei_payroll_system database
-- Safe to re-run: all DDL guarded with information_schema checks
-- ─────────────────────────────────────────────────────────────────────────────

-- ── Step 1: Add overtime_minutes ──────────────────────────────────────────────
SET @ex1 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_records'
      AND COLUMN_NAME  = 'overtime_minutes'
);
SET @s1 = IF(@ex1 = 0,
    'ALTER TABLE attendance_records ADD COLUMN overtime_minutes INT NOT NULL DEFAULT 0 COMMENT ''Minutes worked past shift end_time'' AFTER time_out',
    'SELECT "overtime_minutes already exists" AS notice'
);
PREPARE _s FROM @s1; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── Step 2: Add review_status ─────────────────────────────────────────────────
SET @ex2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_records'
      AND COLUMN_NAME  = 'review_status'
);
SET @s2 = IF(@ex2 = 0,
    "ALTER TABLE attendance_records ADD COLUMN review_status ENUM('PENDING','REVIEWED','APPROVED','FLAGGED','CORRECTED') NOT NULL DEFAULT 'PENDING' COMMENT 'Attendance review lifecycle state' AFTER attendance_status",
    'SELECT "review_status already exists" AS notice'
);
PREPARE _s FROM @s2; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── Step 3: Add school_year_id ────────────────────────────────────────────────
SET @ex3 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_records'
      AND COLUMN_NAME  = 'school_year_id'
);
SET @s3 = IF(@ex3 = 0,
    'ALTER TABLE attendance_records ADD COLUMN school_year_id INT UNSIGNED NULL DEFAULT NULL COMMENT ''FK to school_years for archiving scope'' AFTER review_status',
    'SELECT "school_year_id already exists" AS notice'
);
PREPARE _s FROM @s3; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── Step 4: Add is_archived ───────────────────────────────────────────────────
SET @ex4 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_records'
      AND COLUMN_NAME  = 'is_archived'
);
SET @s4 = IF(@ex4 = 0,
    'ALTER TABLE attendance_records ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = archived after school year close'' AFTER school_year_id',
    'SELECT "is_archived already exists" AS notice'
);
PREPARE _s FROM @s4; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── Step 5: Index on is_archived for archive queries ─────────────────────────
SET @ix5 = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_records'
      AND INDEX_NAME   = 'idx_ar_archived'
);
SET @s5 = IF(@ix5 = 0,
    'ALTER TABLE attendance_records ADD INDEX idx_ar_archived (is_archived)',
    'SELECT "idx_ar_archived already exists" AS notice'
);
PREPARE _s FROM @s5; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ── Step 6: Create attendance_audit_log ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance_audit_log (
    audit_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    attendance_id   INT UNSIGNED    NOT NULL
                    COMMENT 'FK to attendance_records.attendance_id',
    changed_by      INT UNSIGNED    NULL
                    COMMENT 'FK to users.user_id — NULL if system action',
    action_type     VARCHAR(30)     NOT NULL DEFAULT 'EDIT'
                    COMMENT 'EDIT | TIMEOUT | CREATE | AUTO',
    -- Snapshot of old values (NULL = field was not set)
    old_time_in     TIME            NULL,
    old_time_out    TIME            NULL,
    old_status      VARCHAR(20)     NULL,
    old_method      VARCHAR(50)     NULL,
    old_remarks     TEXT            NULL,
    -- Snapshot of new values
    new_time_in     TIME            NULL,
    new_time_out    TIME            NULL,
    new_status      VARCHAR(20)     NULL,
    new_method      VARCHAR(50)     NULL,
    new_remarks     TEXT            NULL,
    -- Admin-provided reason (required for EDIT action_type)
    reason          TEXT            NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY  (audit_id),
    KEY idx_aal_attendance (attendance_id),
    KEY idx_aal_changed_by (changed_by),
    KEY idx_aal_created    (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Step 7: Create dtr_attachments ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dtr_attachments (
    attachment_id   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    cutoff_start    DATE            NOT NULL,
    cutoff_end      DATE            NOT NULL,
    employee_id     INT UNSIGNED    NULL
                    COMMENT 'NULL = covers the whole cutoff period',
    file_name       VARCHAR(255)    NOT NULL
                    COMMENT 'Original uploaded filename',
    file_path       VARCHAR(500)    NOT NULL
                    COMMENT 'Relative server path to stored file',
    file_type       ENUM('EXCEL','PDF','IMAGE','OTHER') NOT NULL DEFAULT 'OTHER',
    uploaded_by     INT UNSIGNED    NULL
                    COMMENT 'FK to users.user_id',
    notes           TEXT            NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY  (attachment_id),
    KEY idx_dtr_cutoff   (cutoff_start, cutoff_end),
    KEY idx_dtr_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Verification ──────────────────────────────────────────────────────────────
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'attendance_records'
  AND COLUMN_NAME  IN ('overtime_minutes','review_status','school_year_id','is_archived')
ORDER BY ORDINAL_POSITION;

SELECT TABLE_NAME, COUNT(*) AS column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   IN ('attendance_audit_log','dtr_attachments')
GROUP BY TABLE_NAME;
