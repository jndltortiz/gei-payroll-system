-- Migration 032: Multi-row DTR reconciliation entries
-- One DTR upload represents one unavailable/crash day and can produce many
-- attendance record creates/updates, one per employee row entered by Admin.

CREATE TABLE IF NOT EXISTS `dtr_reconciliation_entries` (
  `entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attachment_id` INT UNSIGNED NOT NULL,
  `attendance_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  `attendance_date` DATE NOT NULL,
  `entry_action` ENUM('create','update') NOT NULL,
  `time_in` TIME DEFAULT NULL,
  `time_out` TIME DEFAULT NULL,
  `attendance_status` ENUM('PRESENT','ABSENT','LATE','HALF_DAY','INCOMPLETE','LEAVE','HOLIDAY') NOT NULL DEFAULT 'PRESENT',
  `remarks` VARCHAR(255) DEFAULT NULL,
  `reason` TEXT NOT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`entry_id`),
  KEY `idx_dtr_recon_attachment` (`attachment_id`),
  KEY `idx_dtr_recon_attendance` (`attendance_id`),
  KEY `idx_dtr_recon_employee_date` (`employee_id`, `attendance_date`),
  CONSTRAINT `fk_dtr_recon_attachment`
    FOREIGN KEY (`attachment_id`) REFERENCES `dtr_attachments` (`attachment_id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_dtr_recon_attendance`
    FOREIGN KEY (`attendance_id`) REFERENCES `attendance_records` (`attendance_id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_dtr_recon_employee`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
