-- Migration 009: attendance_corrections — employee self-service correction requests
-- Safe to re-run.

SET @tbl = (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_corrections');

SET @sql = IF(@tbl = 0,
    'CREATE TABLE attendance_corrections (
        correction_id   INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        attendance_id   INT UNSIGNED    NULL COMMENT ''NULL when reporting a missing record'',
        employee_id     INT UNSIGNED    NOT NULL,
        attendance_date DATE            NOT NULL,
        issue_type      ENUM(''MISSING_TIME_IN'',''MISSING_TIME_OUT'',''WRONG_STATUS'',''LATE_INCORRECT'',''OTHER'')
                        NOT NULL DEFAULT ''OTHER'',
        explanation     TEXT            NOT NULL,
        attachment_path VARCHAR(500)    NULL,
        attachment_name VARCHAR(255)    NULL,
        status          ENUM(''PENDING'',''REVIEWED'',''RESOLVED'',''DISMISSED'')
                        NOT NULL DEFAULT ''PENDING'',
        admin_note      TEXT            NULL,
        created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_emp_date (employee_id, attendance_date),
        INDEX idx_status   (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''attendance_corrections already exists'' AS notice'
);

PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;
