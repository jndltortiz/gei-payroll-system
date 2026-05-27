<?php
require '../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Content-Type: application/json'); echo json_encode(null); exit; }

$stmt = $pdo->prepare("
    SELECT
        e.*,
        d.department_name,
        p.position_name,
        s.shift_name,
        s.start_time  AS shift_start,
        s.end_time    AS shift_end,
        ec.monthly_salary,
        COALESCE(NULLIF(ec.daily_rate, 0), ROUND(ec.monthly_salary / 22, 2)) AS daily_rate,
        u.username,
        u.is_active AS user_is_active,
        u.must_change_password,
        r.role_name
    FROM employees e
    LEFT JOIN departments d   ON e.department_id = d.department_id
    LEFT JOIN positions p     ON e.position_id   = p.position_id
    LEFT JOIN shifts s        ON e.shift_id       = s.shift_id
    LEFT JOIN employee_compensations ec ON e.employee_id = ec.employee_id AND ec.is_active = 1
    LEFT JOIN users u         ON e.employee_id = u.employee_id
    LEFT JOIN roles r         ON u.role_id = r.role_id
    WHERE e.employee_id = ?
");
$stmt->execute([$id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Education entries for edit wizard population
$eduStmt = $pdo->prepare("
    SELECT institution, year_obtained, description
    FROM employee_credentials
    WHERE employee_id = ? AND credential_type = 'Education'
    ORDER BY year_obtained DESC
");
$eduStmt->execute([$id]);
$education = $eduStmt->fetchAll(PDO::FETCH_ASSOC);

// Documents list for edit wizard
$docStmt = $pdo->prepare("
    SELECT doc_type, doc_name, file_size, uploaded_at
    FROM employee_documents
    WHERE employee_id = ?
    ORDER BY uploaded_at DESC
");
$docStmt->execute([$id]);
$documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

if ($employee) {
    $employee['education']  = $education;
    $employee['documents']  = $documents;
}

header('Content-Type: application/json');
echo json_encode($employee);