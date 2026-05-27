-- migrations/027_notifications.sql
-- Notification system foundation + calendar reminder columns.
-- Compatible with MySQL 5.7+ and MariaDB 10.0+.
--
-- Part 1: notifications table (always safe to re-run)
-- Part 2: ALTER TABLE via stored procedure (handles IF NOT EXISTS on older MySQL)

-- ── Part 1: Notifications table ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notifications` (
    `notification_id` INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`         INT             NOT NULL,
    `title`           VARCHAR(200)    NOT NULL,
    `message`         TEXT            DEFAULT NULL,
    `type`            ENUM('calendar','payroll','leave','loan','service_credit','system')
                                      NOT NULL DEFAULT 'system',
    `link_url`        VARCHAR(500)    DEFAULT NULL,
    `related_id`      INT             DEFAULT NULL,
    `is_read`         TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`notification_id`),
    KEY `idx_notif_user_unread` (`user_id`, `is_read`),
    KEY `idx_notif_user_date`   (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Part 2: Calendar reminder columns (MySQL 5.7-compatible via procedure) ───
DROP PROCEDURE IF EXISTS `gei_add_reminder_cols`;

DELIMITER //
CREATE PROCEDURE `gei_add_reminder_cols`()
BEGIN
    -- reminder_offset
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'employee_calendar_entries'
          AND COLUMN_NAME  = 'reminder_offset'
    ) THEN
        ALTER TABLE `employee_calendar_entries`
            ADD COLUMN `reminder_offset` ENUM('none','at_time','10min','30min','1hour','1day')
                                         NOT NULL DEFAULT 'none';
    END IF;

    -- notify_in_system
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'employee_calendar_entries'
          AND COLUMN_NAME  = 'notify_in_system'
    ) THEN
        ALTER TABLE `employee_calendar_entries`
            ADD COLUMN `notify_in_system` TINYINT(1) NOT NULL DEFAULT 0;
    END IF;

    -- reminder_sent
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'employee_calendar_entries'
          AND COLUMN_NAME  = 'reminder_sent'
    ) THEN
        ALTER TABLE `employee_calendar_entries`
            ADD COLUMN `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0;
    END IF;
END//
DELIMITER ;

CALL `gei_add_reminder_cols`();
DROP PROCEDURE IF EXISTS `gei_add_reminder_cols`;
