-- migrations/018_employee_calendar_entries.sql
-- Employee personal calendar entries: notes, to-dos, reminders.
-- Safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `employee_calendar_entries` (
    `entry_id`    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `employee_id` INT UNSIGNED    NOT NULL,
    `title`       VARCHAR(200)    NOT NULL,
    `description` TEXT            DEFAULT NULL,
    `type`        ENUM('NOTE','TODO','REMINDER') NOT NULL DEFAULT 'NOTE',
    `entry_date`  DATE            NOT NULL,
    `entry_time`  TIME            DEFAULT NULL,
    `recurrence`  ENUM('NONE','DAILY','WEEKLY','MONTHLY') NOT NULL DEFAULT 'NONE',
    `is_done`     TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`entry_id`),
    KEY `idx_emp_date` (`employee_id`, `entry_date`),
    KEY `idx_emp_recurrence` (`employee_id`, `recurrence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
