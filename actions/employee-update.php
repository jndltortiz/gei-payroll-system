<?php
require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = (int)$_POST['employee_id'];

        // Fetch existing record to fall back to current values for blank fields
        $existing = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
        $existing->execute([$id]);
        $curr = $existing->fetch(PDO::FETCH_ASSOC);

        if (!$curr) {
            echo "Error: Employee not found.";
            exit();
        }

        // Helper: use posted value if non-empty, else keep existing DB value
        $use = function($field, $dbField = null) use ($curr) {
            $dbField = $dbField ?? $field;
            $val = $_POST[$field] ?? '';
            return (trim($val) !== '') ? $val : $curr[$dbField];
        };

        // For nullable fields: preserve existing value if blank
        $useNullable = function($field, $dbField = null) use ($curr) {
            $dbField = $dbField ?? $field;
            if (isset($_POST[$field]) && trim($_POST[$field]) !== '') {
                return $_POST[$field];
            }
            return $curr[$dbField];
        };

        // Helper: normalize PH contact number to +639XXXXXXXXX for DB storage
        $normalizeContact = function($val) {
            if (!$val) return $val;
            $val = trim(preg_replace('/\s+/', '', $val)); // strip spaces
            if (preg_match('/^\+639\d{9}$/', $val)) return $val; // already correct
            if (preg_match('/^639\d{9}$/', $val))  return '+' . $val;
            if (preg_match('/^09\d{9}$/',  $val))  return '+63' . substr($val, 1);
            return $val; // fallback: save as-is
        };

        // ── UPDATE employees table ────────────────────────────────
        $empParams = [
            ':employee_id'     => $id,
            ':first_name'      => $use('first_name'),
            ':middle_name'     => $useNullable('middle_name'),
            ':last_name'       => $use('last_name'),
            ':suffix'          => $useNullable('suffix'),
            ':sex'             => $useNullable('sex'),
            ':birth_date'      => $useNullable('birth_date'),
            ':civil_status'    => $useNullable('civil_status'),
            ':address'         => $useNullable('address'),
            ':email'           => $use('email'),
            ':contact_no'      => $normalizeContact($useNullable('contact_no')),
            ':hire_date'       => $use('hire_date'),
            ':employment_type' => $use('employment_type'),
            ':employee_status' => $use('employee_status'),
            ':department_id'   => $use('department_id'),
            ':position_id'     => $use('position_id'),
            ':shift_id'        => $useNullable('shift_id'),
        ];

        $stmt = $pdo->prepare("
            UPDATE employees SET
                first_name      = :first_name,
                middle_name     = :middle_name,
                last_name       = :last_name,
                suffix          = :suffix,
                sex             = :sex,
                birth_date      = :birth_date,
                civil_status    = :civil_status,
                address         = :address,
                email           = :email,
                contact_no      = :contact_no,
                hire_date       = :hire_date,
                employment_type = :employment_type,
                employee_status = :employee_status,
                department_id   = :department_id,
                position_id     = :position_id,
                shift_id        = :shift_id
            WHERE employee_id   = :employee_id
        ");
        $stmt->execute($empParams);

        // ── UPDATE users table (only changed fields) ──────────────
        $userSets  = [];
        $userParams = [':emp_id' => $id];

        if (!empty($_POST['username'])) {
            $userSets[] = 'username = :username';
            $userParams[':username'] = $_POST['username'];
        }
        if (!empty($_POST['password'])) {
            $userSets[] = 'password_hash = :password';
            $userParams[':password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }
        if (!empty($_POST['role'])) {
            // Map role name → role_id
            $roleMap = ['Admin' => 1, 'Accounting' => 2, 'Employee' => 3];
            $roleId  = $roleMap[$_POST['role']] ?? null;
            if ($roleId) {
                $userSets[] = 'role_id = :role_id';
                $userParams[':role_id'] = $roleId;
            }
        }

        if (!empty($userSets)) {
            $userStmt = $pdo->prepare(
                "UPDATE users SET " . implode(', ', $userSets) . " WHERE employee_id = :emp_id"
            );
            $userStmt->execute($userParams);
        }

        // ── UPDATE salary in employee_compensations ───────────────
        if (isset($_POST['basic_salary']) && trim($_POST['basic_salary']) !== '') {
            $monthly = (float) $_POST['basic_salary'];
            $daily   = $monthly / 22;

            // Check if an active record exists
            $checkSalary = $pdo->prepare("
                SELECT compensation_id FROM employee_compensations
                WHERE employee_id = ? AND is_active = 1
                LIMIT 1
            ");
            $checkSalary->execute([$id]);
            $existingSalary = $checkSalary->fetch(PDO::FETCH_ASSOC);

            if ($existingSalary) {
                // Update the existing active record in place
                $updateSalary = $pdo->prepare("
                    UPDATE employee_compensations
                    SET monthly_salary = ?, daily_rate = ?, effective_date = CURDATE()
                    WHERE compensation_id = ?
                ");
                $updateSalary->execute([$monthly, $daily, $existingSalary['compensation_id']]);
            } else {
                // No record yet — insert one
                $newSalary = $pdo->prepare("
                    INSERT INTO employee_compensations
                        (employee_id, effective_date, monthly_salary, daily_rate, pay_basis, is_active)
                    VALUES (?, CURDATE(), ?, ?, 'MONTHLY', 1)
                ");
                $newSalary->execute([$id, $monthly, $daily]);
            }
        }

        header("Location: ../modules/employees/index.php");
        exit();

    } catch (PDOException $e) {
        echo "Error: " . $e->getMessage();
    }
}