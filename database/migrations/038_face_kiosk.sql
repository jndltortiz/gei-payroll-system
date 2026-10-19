-- Test kiosk schema. Apply only to gei_test during development.
ALTER TABLE biometric_logs MODIFY employee_id INT NULL,
    ADD COLUMN match_distance DOUBLE NULL AFTER confidence_score,
    ADD KEY idx_face_cooldown (employee_id, biometric_type, captured_at);
CREATE TABLE kiosk_rate_limits (
    client_ip VARCHAR(45) NOT NULL PRIMARY KEY,
    window_started_at DATETIME NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;