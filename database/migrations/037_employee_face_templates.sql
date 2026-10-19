-- 037: Multiple face-api.js 0.22.2 / 128-value descriptors per employee.
-- Select the target database explicitly. No attendance/biometric log integration.
CREATE TABLE employee_face_templates (
    template_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT NOT NULL,
    descriptor JSON NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consented_at DATETIME NOT NULL,
    PRIMARY KEY (template_id),
    KEY idx_face_employee (employee_id),
    CONSTRAINT fk_face_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id),
    CONSTRAINT fk_face_creator FOREIGN KEY (created_by) REFERENCES users(user_id),
    CONSTRAINT chk_face_descriptor CHECK (JSON_TYPE(descriptor) = 'ARRAY' AND JSON_LENGTH(descriptor) = 128)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;