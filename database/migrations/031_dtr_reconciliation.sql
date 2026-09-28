-- Migration 031: DTR upload reconciliation workflow
-- Tracks whether an uploaded DTR backup has been reviewed and linked to an
-- attendance_records row, or rejected with a reason.

ALTER TABLE `dtr_attachments`
  ADD COLUMN `reconciliation_status` ENUM('pending','reconciled','rejected') NOT NULL DEFAULT 'pending' AFTER `notes`,
  ADD COLUMN `linked_attendance_id` INT(11) DEFAULT NULL AFTER `reconciliation_status`,
  ADD COLUMN `reconciliation_notes` TEXT DEFAULT NULL AFTER `linked_attendance_id`,
  ADD COLUMN `rejection_reason` TEXT DEFAULT NULL AFTER `reconciliation_notes`,
  ADD COLUMN `reviewed_by` INT(11) DEFAULT NULL AFTER `rejection_reason`,
  ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL AFTER `reviewed_by`;

ALTER TABLE `dtr_attachments`
  ADD KEY `idx_dtr_reconciliation_status` (`reconciliation_status`),
  ADD KEY `idx_dtr_linked_attendance` (`linked_attendance_id`);

ALTER TABLE `dtr_attachments`
  ADD CONSTRAINT `fk_dtr_linked_attendance`
    FOREIGN KEY (`linked_attendance_id`) REFERENCES `attendance_records` (`attendance_id`)
    ON DELETE SET NULL;
