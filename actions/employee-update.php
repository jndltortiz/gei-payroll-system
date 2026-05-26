<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../modules/employees/index.php');
    exit;
}

try {
    $id = (int)$_POST['employee_id'];

    $existing = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $existing->execute([$id]);
    $curr = $existing->fetch(PDO::FETCH_ASSOC);

    if (!$curr) {
        header('Location: ../modules/employees/index.php?error=notfound');
        exit;
    }

    $use = function($field, $dbField = null) use ($curr) {
        $dbField = $dbField ?? $field;
        $val = $_POST[$field] ?? '';
        return (trim($val) !== '') ? $val : $curr[$dbField];
    };

    $useNullable = function($field, $dbField = null) use ($curr) {
        $dbField = $dbField ?? $field;
        if (isset($_POST[$field]) && trim($_POST[$field]) !== '') {
            return $_POST[$field];
        }
        return $curr[$dbField];
    };

    $normalizeContact = function($val) {
        if (!$val) return $val;
        $val = trim(preg_replace('/\s+/', '', $val));
        if (preg_match('/^\+639\d{9}$/', $val)) return $val;
        if (preg_match('/^639\d{9}$/', $val))   return '+' . $val;
        if (preg_match('/^09\d{9}$/', $val))     return '+63' . substr($val, 1);
        return $val;
    };

    // Check if personal_email column exists (migration 015)
    $hasPE = false;
    try {
        $pdo->query("SELECT personal_email FROM employees LIMIT 1");
        $hasPE = true;
    } catch (PDOException $e) { /* column not yet migrated */ }

    // ── UPDATE employees table ─────────────────────────────────────────────────
    $peClause = $hasPE ? ", personal_email = :personal_email" : '';
    $stmt = $pdo->prepare("
        UPDATE employees SET
            first_name                  = :first_name,
            middle_name                 = :middle_name,
            last_name                   = :last_name,
            suffix                      = :suffix,
            sex                         = :sex,
            birth_date                  = :birth_date,
            civil_status                = :civil_status,
            address                     = :address,
            email                       = :email,
            contact_no                  = :contact_no,
            hire_date                   = :hire_date,
            employment_type             = :employment_type,
            employee_status             = :employee_status,
            department_id               = :department_id,
            position_id                 = :position_id,
            shift_id                    = :shift_id,
            sss_no                      = :sss_no,
            philhealth_no               = :philhealth_no,
            pagibig_no                  = :pagibig_no,
            tin_no                      = :tin_no,
            peraa_no                    = :peraa_no,
            emergency_contact_name      = :ec_name,
            emergency_contact_relation  = :ec_rel,
            emergency_contact_number    = :ec_num
            $peClause
        WHERE employee_id = :employee_id
    ");
    $empParams = [
        ':employee_id'  => $id,
        ':first_name'   => $use('first_name'),
        ':middle_name'  => $useNullable('middle_name'),
        ':last_name'    => $use('last_name'),
        ':suffix'       => $useNullable('suffix'),
        ':sex'          => $useNullable('sex'),
        ':birth_date'   => $useNullable('birth_date'),
        ':civil_status' => $useNullable('civil_status'),
        ':address'      => $useNullable('address'),
        ':email'        => $use('email'),
        ':contact_no'   => $normalizeContact($useNullable('contact_no')),
        ':hire_date'    => $use('hire_date'),
        ':employment_type'  => $use('employment_type'),
        ':employee_status'  => $use('employee_status'),
        ':department_id'    => $use('department_id'),
        ':position_id'      => $use('position_id'),
        ':shift_id'         => $useNullable('shift_id'),
        ':sss_no'       => $useNullable('sss_no'),
        ':philhealth_no'=> $useNullable('philhealth_no'),
        ':pagibig_no'   => $useNullable('pagibig_no'),
        ':tin_no'       => $useNullable('tin_no'),
        ':peraa_no'     => $useNullable('peraa_no'),
        ':ec_name'      => $useNullable('emergency_contact_name'),
        ':ec_rel'       => $useNullable('emergency_contact_relation'),
        ':ec_num'       => $useNullable('emergency_contact_number'),
    ];
    if ($hasPE) {
        $empParams[':personal_email'] = trim($_POST['personal_email'] ?? '') ?: null;
    }
    $stmt->execute($empParams);

    // ── UPDATE users table ─────────────────────────────────────────────────────
    $userSets   = [];
    $userParams = [':emp_id' => $id];

    if (!empty($_POST['username'])) {
        $userSets[]  = 'username = :username';
        $userParams[':username'] = $_POST['username'];
    }
    if (!empty($_POST['password'])) {
        $userSets[]  = 'password_hash = :password';
        $userParams[':password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
    }
    if (!empty($_POST['role'])) {
        $roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
        $roleStmt->execute([$_POST['role']]);
        $roleRow = $roleStmt->fetch();
        if ($roleRow) {
            $userSets[]  = 'role_id = :role_id';
            $userParams[':role_id'] = (int)$roleRow['role_id'];
        }
    }

    if (!empty($userSets)) {
        $pdo->prepare(
            "UPDATE users SET " . implode(', ', $userSets) . " WHERE employee_id = :emp_id"
        )->execute($userParams);
    }

    // ── UPDATE salary ──────────────────────────────────────────────────────────
    if (isset($_POST['basic_salary']) && trim($_POST['basic_salary']) !== '') {
        $monthly = (float)$_POST['basic_salary'];
        $daily   = round($monthly / 22, 2);

        $checkSalary = $pdo->prepare("
            SELECT compensation_id FROM employee_compensations
            WHERE employee_id = ? AND is_active = 1 LIMIT 1
        ");
        $checkSalary->execute([$id]);
        $existingSalary = $checkSalary->fetch(PDO::FETCH_ASSOC);

        if ($existingSalary) {
            $pdo->prepare("
                UPDATE employee_compensations
                SET monthly_salary = ?, daily_rate = ?, effective_date = CURDATE()
                WHERE compensation_id = ?
            ")->execute([$monthly, $daily, $existingSalary['compensation_id']]);
        } else {
            $pdo->prepare("
                INSERT INTO employee_compensations
                    (employee_id, effective_date, monthly_salary, daily_rate, pay_basis, is_active)
                VALUES (?, CURDATE(), ?, ?, 'MONTHLY', 1)
            ")->execute([$id, $monthly, $daily]);
        }
    }

    // ── REPLACE education entries ──────────────────────────────────────────────
    $eduDegrees = $_POST['edu_degree'] ?? [];
    if (!empty($eduDegrees)) {
        // Clear existing education credentials
        $pdo->prepare("DELETE FROM employee_credentials WHERE employee_id = ? AND credential_type = 'Education'")->execute([$id]);

        $credStmt = $pdo->prepare("
            INSERT INTO employee_credentials (employee_id, credential_type, institution, year_obtained, description)
            VALUES (?, 'Education', ?, ?, ?)
        ");
        $eduCourses = $_POST['edu_course'] ?? [];
        $eduSchools = $_POST['edu_school'] ?? [];
        $eduYears   = $_POST['edu_year']   ?? [];
        foreach ($eduDegrees as $i => $degree) {
            $school = trim($eduSchools[$i] ?? '');
            $course = trim($eduCourses[$i] ?? '');
            $year   = (int)($eduYears[$i]  ?? 0);
            $desc   = trim($degree . ($course ? " — $course" : ''));
            if ($school || $desc) {
                $credStmt->execute([$id, $school ?: null, $year ?: null, $desc ?: null]);
            }
        }
    }

    // ── ADD new document uploads ───────────────────────────────────────────────
    $uploadDir = __DIR__ . '/../uploads/employees/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $docTypes = $_POST['doc_type'] ?? [];
    $docFiles = $_FILES['doc_file'] ?? [];
    if (!empty($docFiles['name'])) {
        $docStmt = $pdo->prepare("
            INSERT INTO employee_documents (employee_id, doc_type, doc_name, file_path, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $uid = $_SESSION['user']['user_id'] ?? null;
        foreach ($docFiles['name'] as $j => $fileName) {
            if ($docFiles['error'][$j] !== UPLOAD_ERR_OK || !$fileName) continue;
            if ($docFiles['size'][$j] > 5 * 1024 * 1024) continue;
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','jpg','jpeg','png'])) continue;
            $stored   = 'emp_' . $id . '_' . time() . '_' . $j . '.' . $ext;
            $filePath = 'uploads/employees/' . $stored;
            if (move_uploaded_file($docFiles['tmp_name'][$j], $uploadDir . $stored)) {
                $docStmt->execute([
                    $id,
                    trim($docTypes[$j] ?? 'Document'),
                    $fileName,
                    $filePath,
                    $docFiles['size'][$j],
                    $uid,
                ]);
            }
        }
    }

    // ── Audit log ──────────────────────────────────────────────────────────────
    $uid = $_SESSION['user']['user_id'] ?? null;
    if ($uid) {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?,?,?,?,?)")
            ->execute([$uid, 'UPDATE', 'employees', $id,
                       "Updated employee record ID {$id}"]);
    }

    header('Location: ../modules/employees/index.php?success=updated');
    exit;

} catch (PDOException $e) {
    header('Location: ../modules/employees/index.php?error=' . urlencode($e->getMessage()));
    exit;
}
