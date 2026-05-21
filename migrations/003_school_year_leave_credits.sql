-- ────────────────────────────────────────────────────────────────────────────
-- Migration 003: School Year Management & Leave Credit Allocation
--
-- Adds:
--   school_years            — academic-year CRUD, one active at a time
--   employee_leave_credits  — per-employee / per-year / per-type credit ledger
--   leave_requests.school_year_id — links each request to its school year
--
-- Prerequisites : migrations 001, 002
-- Run against   : gei_payroll_system database
-- Safe to re-run: all DDL steps are guarded with IF NOT EXISTS / SET @…
-- ────────────────────────────────────────────────────────────────────────────

-- ── Step 1: school_years ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS school_years (
    school_year_id  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    year_name       VARCHAR(20)   NOT NULL COMMENT 'e.g. 2025-2026',
    start_date      DATE          NOT NULL,
    end_date        DATE          NOT NULL,
    is_active       TINYINT(1)    NOT NULL DEFAULT 0,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (school_year_id),
    UNIQUE  KEY uq_year_name (year_name),
    KEY idx_is_active (is_active),
    KEY idx_dates     (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Step 2: employee_leave_credits ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS employee_leave_credits (
    credit_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    employee_id     INT UNSIGNED  NOT NULL,
    school_year_id  INT UNSIGNED  NOT NULL,
    leave_type_id   INT UNSIGNED  NOT NULL,
    allocated_days  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    used_days       DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    -- remaining = allocated_days - used_days (always computed, never stored)
    notes           VARCHAR(255)  NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (credit_id),
    UNIQUE  KEY uq_emp_year_type (employee_id, school_year_id, leave_type_id),
    KEY idx_employee   (employee_id),
    KEY idx_school_year(school_year_id),
    KEY idx_leave_type (leave_type_id),

    CONSTRAINT fk_elc_employee
        FOREIGN KEY (employee_id)    REFERENCES employees   (employee_id)    ON DELETE CASCADE,
    CONSTRAINT fk_elc_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(school_year_id) ON DELETE CASCADE,
    CONSTRAINT fk_elc_leave_type
        FOREIGN KEY (leave_type_id)  REFERENCES leave_types (leave_type_id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Step 3: Add school_year_id to leave_requests (nullable for legacy rows) ─
-- MySQL 5.7 compatible: check via information_schema before ALTER
SET @col_exists = (
    SELECT COUNT(*)
    FROM   information_schema.COLUMNS
    WHERE  TABLE_SCHEMA = DATABASE()
      AND  TABLE_NAME   = 'leave_requests'
      AND  COLUMN_NAME  = 'school_year_id'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE leave_requests ADD COLUMN school_year_id INT UNSIGNED NULL DEFAULT NULL AFTER leave_type_id',
    'SELECT "Column school_year_id already exists in leave_requests" AS notice'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ── Step 4: FK on leave_requests.school_year_id ──────────────────────────
SET @fk_exists = (
    SELECT COUNT(*)
    FROM   information_schema.TABLE_CONSTRAINTS
    WHERE  CONSTRAINT_SCHEMA = DATABASE()
      AND  TABLE_NAME         = 'leave_requests'
      AND  CONSTRAINT_NAME    = 'fk_lr_school_year'
      AND  CONSTRAINT_TYPE    = 'FOREIGN KEY'
);
SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE leave_requests ADD CONSTRAINT fk_lr_school_year FOREIGN KEY (school_year_id) REFERENCES school_years (school_year_id) ON DELETE SET NULL',
    'SELECT "FK fk_lr_school_year already exists" AS notice'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ── Step 5: Seed default school year 2025-2026 (skip if any row exists) ──
INSERT IGNORE INTO school_years (year_name, start_date, end_date, is_active)
SELECT '2025-2026', '2025-06-01', '2026-03-31', 1
WHERE  NOT EXISTS (SELECT 1 FROM school_years LIMIT 1);

-- ── Verification ──────────────────────────────────────────────────────────
SELECT 'school_years'           AS tbl, COUNT(*) AS rows FROM school_years
UNION ALL
SELECT 'employee_leave_credits' AS tbl, COUNT(*) AS rows FROM employee_leave_credits;

SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT
FROM   information_schema.COLUMNS
WHERE  TABLE_SCHEMA = DATABASE()
  AND  TABLE_NAME   = 'leave_requests'
  AND  COLUMN_NAME  = 'school_year_id';
