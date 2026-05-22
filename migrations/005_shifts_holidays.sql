-- ─────────────────────────────────────────────────────────────────────────────
-- Migration 005: Shift Management and Holiday Calendar
--
-- Creates:
--   shifts     — named work schedules (start time, end time, grace period,
--                half-day threshold)
--   holidays   — school holiday calendar with type REGULAR / SPECIAL
--
-- Modifies:
--   employees  — adds shift_id FK (nullable) if it does not already exist
--
-- Seeds:
--   One default shift "Regular (8:00 AM – 5:00 PM)" if no shifts exist
--
-- Prerequisites : migrations 001–004
-- Run against   : gei_payroll_system database
-- Safe to re-run: all DDL guarded with IF NOT EXISTS / information_schema checks
-- ─────────────────────────────────────────────────────────────────────────────

-- ── Step 1: Create shifts table ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS shifts (
    shift_id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    shift_name            VARCHAR(100)  NOT NULL,
    start_time            TIME          NOT NULL,
    end_time              TIME          NOT NULL,
    grace_period_minutes  INT           NOT NULL DEFAULT 0
                          COMMENT 'Minutes after start_time before attendance is marked LATE',
    half_day_time         TIME          NULL
                          COMMENT 'Time-in threshold above which attendance becomes HALF_DAY. NULL = 09:00 default in app logic.',
    is_active             TINYINT(1)    NOT NULL DEFAULT 1,
    created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (shift_id),
    KEY idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Step 2: Add shift_id column to employees if it does not exist ────────────
SET @col_emp_shift = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'employees'
      AND COLUMN_NAME  = 'shift_id'
);
SET @s2 = IF(@col_emp_shift = 0,
    'ALTER TABLE employees ADD COLUMN shift_id INT UNSIGNED NULL DEFAULT NULL AFTER employment_type',
    'SELECT "employees.shift_id already exists" AS notice'
);
PREPARE _st FROM @s2; EXECUTE _st; DEALLOCATE PREPARE _st;

-- ── Step 3: Add FK employees.shift_id → shifts.shift_id ─────────────────────
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME         = 'employees'
      AND CONSTRAINT_NAME    = 'fk_emp_shift'
      AND CONSTRAINT_TYPE    = 'FOREIGN KEY'
);
SET @s3 = IF(@fk_exists = 0,
    'ALTER TABLE employees ADD CONSTRAINT fk_emp_shift FOREIGN KEY (shift_id) REFERENCES shifts (shift_id) ON DELETE SET NULL',
    'SELECT "fk_emp_shift already exists" AS notice'
);
PREPARE _st FROM @s3; EXECUTE _st; DEALLOCATE PREPARE _st;

-- ── Step 4: Create holidays table (or patch if it already existed) ───────────
-- Safe: CREATE TABLE IF NOT EXISTS handles the new-table case.
-- The ALTER TABLE steps below handle an existing table missing the new columns.
CREATE TABLE IF NOT EXISTS holidays (
    holiday_id     INT UNSIGNED               NOT NULL AUTO_INCREMENT,
    holiday_name   VARCHAR(150)               NOT NULL,
    holiday_date   DATE                       NOT NULL,
    holiday_type   ENUM('REGULAR','SPECIAL')  NOT NULL DEFAULT 'REGULAR',
    school_year_id INT UNSIGNED               NULL
                   COMMENT 'Optional: links holiday to a school year for filtered views',
    notes          VARCHAR(255)               NULL,
    created_at     TIMESTAMP                  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP                  NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                       ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (holiday_id),
    UNIQUE  KEY uq_holiday_date  (holiday_date),
    KEY idx_school_year          (school_year_id),
    KEY idx_holiday_type         (holiday_type),
    KEY idx_holiday_date_range   (holiday_date),

    CONSTRAINT fk_holiday_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years (school_year_id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Step 4b: Patch existing holidays table — add school_year_id if missing ───
SET @col_hol_sy = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'holidays'
      AND COLUMN_NAME  = 'school_year_id'
);
SET @s4b = IF(@col_hol_sy = 0,
    'ALTER TABLE holidays ADD COLUMN school_year_id INT UNSIGNED NULL DEFAULT NULL COMMENT ''Optional: links holiday to a school year for filtered views'' AFTER holiday_type',
    'SELECT "holidays.school_year_id already exists" AS notice'
);
PREPARE _st FROM @s4b; EXECUTE _st; DEALLOCATE PREPARE _st;

-- ── Step 4c: Patch existing holidays table — add notes if missing ─────────────
SET @col_hol_notes = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'holidays'
      AND COLUMN_NAME  = 'notes'
);
SET @s4c = IF(@col_hol_notes = 0,
    'ALTER TABLE holidays ADD COLUMN notes VARCHAR(255) NULL DEFAULT NULL AFTER school_year_id',
    'SELECT "holidays.notes already exists" AS notice'
);
PREPARE _st FROM @s4c; EXECUTE _st; DEALLOCATE PREPARE _st;

-- ── Step 4d: Add school_year FK on holidays if not present ────────────────────
SET @fk_hol_sy = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME         = 'holidays'
      AND CONSTRAINT_NAME    = 'fk_holiday_school_year'
      AND CONSTRAINT_TYPE    = 'FOREIGN KEY'
);
SET @s4d = IF(@fk_hol_sy = 0,
    'ALTER TABLE holidays ADD CONSTRAINT fk_holiday_school_year FOREIGN KEY (school_year_id) REFERENCES school_years (school_year_id) ON DELETE SET NULL',
    'SELECT "fk_holiday_school_year already exists" AS notice'
);
PREPARE _st FROM @s4d; EXECUTE _st; DEALLOCATE PREPARE _st;

-- ── Step 5: Seed default shift if the table is empty ─────────────────────────
INSERT INTO shifts (shift_name, start_time, end_time, grace_period_minutes, half_day_time, is_active)
SELECT 'Regular (8:00 AM – 5:00 PM)', '08:00:00', '17:00:00', 15, '09:00:00', 1
WHERE NOT EXISTS (SELECT 1 FROM shifts LIMIT 1);

-- ── Verification ──────────────────────────────────────────────────────────────
SELECT 'shifts'   AS tbl, COUNT(*) AS row_count FROM shifts
UNION ALL
SELECT 'holidays' AS tbl, COUNT(*) AS row_count FROM holidays;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'employees'
  AND COLUMN_NAME  = 'shift_id';
