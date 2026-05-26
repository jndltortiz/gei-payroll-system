<?php
/**
 * actions/employee-save.php
 * Creates a new employee with 201 file fields, government IDs,
 * emergency contact, credentials, and user account.
 * Returns JSON.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
}

// ── Validation ────────────────────────────────────────────────────────────────
$required = ['first_name','last_name','sex','hire_date','employment_type',
             'department_id','position_id','employee_status','email','password'];
foreach ($required as $field) {
    if (empty(trim($_POST[$field] ?? ''))) {
        echo json_encode(['success' => false,
            'message' => 'Required field missing: ' . ucwords(str_replace('_',' ',$field))]);
        exit;
    }
}

$email = trim($_POST['email']);
if (!str_ends_with(strtolower($email), '@gei.edu.ph')) {
    echo json_encode(['success' => false,
        'message' => 'Email must end with @gei.edu.ph.']); exit;
}
if (strlen($_POST['password']) < 8) {
    echo json_encode(['success' => false,
        'message' => 'Password must be at least 8 characters.']); exit;
}

// ── Duplicate email check ─────────────────────────────────────────────────────
$emailCheck = $pdo->prepare("SELECT employee_id FROM employees WHERE email = ?");
$emailCheck->execute([$email]);
if ($emailCheck->fetch()) {
    echo json_encode(['success' => false,
        'message' => 'An employee with that email already exists.']); exit;
}

// ── Generate employee number ──────────────────────────────────────────────────
$year = date('Y');
$lastNo = $pdo->prepare("SELECT employee_no FROM employees WHERE employee_no LIKE ? ORDER BY employee_no DESC LIMIT 1");
$lastNo->execute(["EMP-$year-%"]);
$row = $lastNo->fetch();
$num = $row ? (int)substr($row['employee_no'], -3) + 1 : 1;
$employee_no = "EMP-$year-" . str_pad($num, 3, '0', STR_PAD_LEFT);

// ── Unique username ───────────────────────────────────────────────────────────
$baseUsername = trim($_POST['username'] ?? '');
if (!$baseUsername) {
    $baseUsername = strtolower(trim($_POST['first_name'])[0] . '.' . str_replace(' ', '', $_POST['last_name']));
}
$baseUsername = preg_replace('/[^a-z0-9._-]/', '', strtolower($baseUsername));
$username = $baseUsername;
$suffix   = 1;
$uCheck   = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
while (true) {
    $uCheck->execute([$username]);
    if (!$uCheck->fetch()) break;
    $username = $baseUsername . $suffix++;
}

// ── Contact number normalisation ──────────────────────────────────────────────
$contact = trim($_POST['contact_no'] ?? '');
if ($contact) {
    $c = preg_replace('/\s+/', '', $contact);
    if      (preg_match('/^\+639\d{9}$/', $c))  $contact = $c;
    elseif  (preg_match('/^639\d{9}$/',  $c))   $contact = '+' . $c;
    elseif  (preg_match('/^09\d{9}$/',   $c))   $contact = '+63' . substr($c, 1);
    else                                          $contact = $c;
}

try {
    $pdo->beginTransaction();

    // ── Check if personal_email column exists (migration 015) ────────────────
    $hasPE = false;
    try {
        $pdo->query("SELECT personal_email FROM employees LIMIT 1");
        $hasPE = true;
    } catch (PDOException $e) { /* column not yet migrated */ }

    // ── INSERT employee ───────────────────────────────────────────────────────
    $peCol = $hasPE ? ', personal_email' : '';
    $peVal = $hasPE ? ', :personal_email' : '';
    $insertStmt = $pdo->prepare("
        INSERT INTO employees (
            employee_no, first_name, middle_name, last_name, suffix,
            sex, birth_date, civil_status, address, contact_no, email$peCol,
            hire_date, employment_type, employee_status,
            department_id, position_id, shift_id,
            sss_no, philhealth_no, pagibig_no, tin_no, peraa_no,
            emergency_contact_name, emergency_contact_relation, emergency_contact_number
        ) VALUES (
            :emp_no, :fn, :mn, :ln, :sfx,
            :sex, :bd, :cs, :addr, :cno, :email$peVal,
            :hd, :et, :es,
            :dept, :pos, :shift,
            :sss, :ph, :pi, :tin, :peraa,
            :ec_name, :ec_rel, :ec_num
        )
    ");
    $insertParams = [
        ':emp_no' => $employee_no,
        ':fn'     => trim($_POST['first_name']),
        ':mn'     => trim($_POST['middle_name'] ?? '') ?: null,
        ':ln'     => trim($_POST['last_name']),
        ':sfx'    => trim($_POST['suffix'] ?? '') ?: null,
        ':sex'    => $_POST['sex'],
        ':bd'     => $_POST['birth_date'] ?: null,
        ':cs'     => $_POST['civil_status'] ?: null,
        ':addr'   => trim($_POST['address'] ?? '') ?: null,
        ':cno'    => $contact ?: null,
        ':email'  => $email,
        ':hd'     => $_POST['hire_date'],
        ':et'     => $_POST['employment_type'],
        ':es'     => $_POST['employee_status'],
        ':dept'   => (int)$_POST['department_id'],
        ':pos'    => (int)$_POST['position_id'],
        ':shift'  => ($_POST['shift_id'] ?? '') ?: null,
        ':sss'    => trim($_POST['sss_no']        ?? '') ?: null,
        ':ph'     => trim($_POST['philhealth_no'] ?? '') ?: null,
        ':pi'     => trim($_POST['pagibig_no']    ?? '') ?: null,
        ':tin'    => trim($_POST['tin_no']        ?? '') ?: null,
        ':peraa'  => trim($_POST['peraa_no']      ?? '') ?: null,
        ':ec_name'=> trim($_POST['emergency_contact_name']     ?? '') ?: null,
        ':ec_rel' => trim($_POST['emergency_contact_relation'] ?? '') ?: null,
        ':ec_num' => trim($_POST['emergency_contact_number']   ?? '') ?: null,
    ];
    if ($hasPE) {
        $insertParams[':personal_email'] = trim($_POST['personal_email'] ?? '') ?: null;
    }
    $insertStmt->execute($insertParams);

    $empId = (int)$pdo->lastInsertId();

    // ── Compensation ──────────────────────────────────────────────────────────
    $monthly = max(0, (float)($_POST['basic_salary'] ?? 0));
    $daily   = $monthly > 0 ? round($monthly / 22, 2) : 0;
    $pdo->prepare("
        INSERT INTO employee_compensations
            (employee_id, effective_date, monthly_salary, daily_rate, pay_basis, is_active)
        VALUES (?, CURDATE(), ?, ?, 'MONTHLY', 1)
    ")->execute([$empId, $monthly, $daily]);

    // ── User account ──────────────────────────────────────────────────────────
    // Look up role_id dynamically so any role (Admin, Accounting, Employee, Principal) works
    $roleName = trim($_POST['role'] ?? 'Employee');
    $roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
    $roleStmt->execute([$roleName]);
    $roleRow  = $roleStmt->fetch();
    $roleId   = $roleRow ? (int)$roleRow['role_id'] : 3; // fallback: Employee
    $pdo->prepare("
        INSERT INTO users (username, password_hash, role_id, employee_id, is_active)
        VALUES (?, ?, ?, ?, 1)
    ")->execute([$username, password_hash($_POST['password'], PASSWORD_DEFAULT), $roleId, $empId]);

    // ── Educational credentials (multiple entries) ────────────────────────────
    $eduDegrees = $_POST['edu_degree'] ?? [];
    $eduCourses = $_POST['edu_course'] ?? [];
    $eduSchools = $_POST['edu_school'] ?? [];
    $eduYears   = $_POST['edu_year']   ?? [];
    $credStmt   = $pdo->prepare("
        INSERT INTO employee_credentials
            (employee_id, credential_type, institution, year_obtained, description)
        VALUES (?, 'Education', ?, ?, ?)
    ");
    foreach ($eduDegrees as $i => $degree) {
        $school = trim($eduSchools[$i] ?? '');
        $course = trim($eduCourses[$i] ?? '');
        $year   = (int)($eduYears[$i]  ?? 0);
        $desc   = trim($degree . ($course ? " — $course" : ''));
        if ($school || $desc) {
            $credStmt->execute([$empId, $school ?: null, $year ?: null, $desc ?: null]);
        }
    }

    // ── Audit ────────────────────────────────────────────────────────────────
    $uid = $_SESSION['user']['user_id'] ?? null;

    // ── Document uploads ───────────────────────────────────────────────────────
    $uploadDir  = __DIR__ . '/../uploads/employees/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $docTypes   = $_POST['doc_type'] ?? [];
    $docFiles   = $_FILES['doc_file'] ?? [];
    if (!empty($docFiles['name'])) {
        $docStmt = $pdo->prepare("
            INSERT INTO employee_documents (employee_id, doc_type, doc_name, file_path, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($docFiles['name'] as $j => $fileName) {
            if ($docFiles['error'][$j] !== UPLOAD_ERR_OK || !$fileName) continue;
            if ($docFiles['size'][$j] > 5 * 1024 * 1024) continue; // 5MB limit
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','jpg','jpeg','png'])) continue;
            $stored   = 'emp_' . $empId . '_' . time() . '_' . $j . '.' . $ext;
            $filePath = 'uploads/employees/' . $stored;
            if (move_uploaded_file($docFiles['tmp_name'][$j], $uploadDir . $stored)) {
                $docStmt->execute([
                    $empId,
                    trim($docTypes[$j] ?? 'Document'),
                    $fileName,
                    $filePath,
                    $docFiles['size'][$j],
                    $uid,
                ]);
            }
        }
    }
    if ($uid) {
        $pdo->prepare("INSERT INTO audit_logs (user_id,action,table_name,record_id,description) VALUES (?,?,?,?,?)")
            ->execute([$uid, 'CREATE', 'employees', $empId,
                       "Created employee {$employee_no} — {$_POST['first_name']} {$_POST['last_name']}"]);
    }

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'message'     => "Employee {$employee_no} created successfully.",
        'employee_id' => $empId,
        'employee_no' => $employee_no,
        'username'    => $username,
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}