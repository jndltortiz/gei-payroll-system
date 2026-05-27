-- Migration 023: DTR attachment soft-archive support
-- Adds is_archived flag so uploads can be hidden without deleting the audit record.

ALTER TABLE `dtr_attachments`
    ADD COLUMN `is_archived`  TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT '0=active, 1=archived (soft-deleted)'
        AFTER `notes`,
    ADD COLUMN `archived_at`  TIMESTAMP    NULL DEFAULT NULL
        AFTER `is_archived`,
    ADD COLUMN `archived_by`  INT          NULL DEFAULT NULL
        COMMENT 'user_id who archived the record'
        AFTER `archived_at`;
