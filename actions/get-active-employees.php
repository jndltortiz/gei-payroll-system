<?php
/**
 * actions/get-active-employees.php
 * Returns JSON list of active employees enriched with today's attendance state.
 * Used by the "Log Employee In" modal for searchable multi-select.
 *
 * GET params (all optional):
 *   search   — name or employee_no prefix
 *   dept_id  — filter by department
 *   pos_id   — filter by position
 *
 * Response per employee:
 *   employee_id, employee_no, full_name, department_name, position_name
 *   already_logged  — true if ANY attendance record exists for today
 *   needs_timeout   — true if time_in set but time_out is NULL (active check-in)
 *   current_status  — attendance_status of today's record if it exists
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$search  = trim($_GET['search']  ?? '');
$deptId  = (int)($_GET['dept_id'] ?? 0);
$posId   = (int)($_GET['pos_id']  ?? 0);
$today   = date('Y-m-d');

$where  = "WHERE e.employee_status = 'ACTIVE'";
$params = [':today' => $today];

if ($search !== '') {
    $where .= " AND (e.first_name LIKE :s OR e.last_name LIKE :s OR e.employee_no LIKE :s)";
    $params[':s'] = '%' . $search . '%';
}
if ($deptId > 0) {
    $where .= " AND e.department_id = :dept";
    $params[':dept'] = $deptId;
}
if ($posId > 0) {
    $where .= " AND e.position_id = :pos";
    $params[':pos'] = $posId;
}

$stmt = $pdo->prepare("
    SELECT
        e.employee_id,
        e.employee_no,
        CONCAT(e.first_name, ' ', e.last_name) AS full_name,
        d.department_name,
        p.position_name,
        ar.attendance_id,
        ar.time_in,
        ar.time_out,
        ar.attendance_status
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN attendance_records ar
          ON  ar.employee_id     = e.employee_id
          AND ar.attendance_date = :today
    $where
    ORDER BY e.last_name ASC, e.first_name ASC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$employees = [];
foreach ($rows as $r) {
    $employees[] = [
        'employee_id'     => (int)$r['employee_id'],
        'employee_no'     => $r['employee_no'] ?? '',
        'full_name'       => $r['full_name'],
        'department_name' => $r['department_name'] ?? '',
        'position_name'   => $r['position_name']   ?? '',
        'already_logged'  => $r['attendance_id'] !== null,
        'needs_timeout'   => $r['time_in'] !== null && $r['time_out'] === null,
        'current_status'  => $r['attendance_status'] ?? null,
    ];
}

echo json_encode(['success' => true, 'employees' => $employees]);
