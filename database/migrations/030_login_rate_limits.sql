-- migrations/030_login_rate_limits.sql
-- Tracks failed login attempts by normalized username and IP address.

CREATE TABLE IF NOT EXISTS `login_rate_limits` (
    `rate_limit_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier_type` ENUM('username','ip') NOT NULL,
    `identifier`      VARCHAR(191) NOT NULL,
    `failed_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `first_failed_at` DATETIME NOT NULL,
    `last_failed_at`  DATETIME NOT NULL,
    `locked_until`    DATETIME DEFAULT NULL,
    PRIMARY KEY (`rate_limit_id`),
    UNIQUE KEY `uniq_login_rate_identifier` (`identifier_type`, `identifier`),
    KEY `idx_login_rate_locked_until` (`locked_until`),
    KEY `idx_login_rate_last_failed` (`last_failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
