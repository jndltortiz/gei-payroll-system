<?php
/**
 * actions/get-employee-shift.php
 * Returns an employee's shift info for the attendance auto-suggest feature.
 * GET: employee_id
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$empId = (int)($_GET['employee_id'] ?? 0);
if (!$empId) {
    echo json_encode(['success' => false]); exit;
}

$stmt = $pdo->prepare("
    SELECT e.first_name, e.last_name, e.employment_type,
           s.shift_name, s.start_time,
           s.end_time, s.grace_period_minutes, s.half_day_time
    FROM employees e
    LEFT JOIN shifts s ON e.shift_id = s.shift_id
    WHERE e.employee_id = ? AND e.employee_status = 'ACTIVE'
");
$stmt->execute([$empId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) {
    echo json_encode(['success' => false, 'message' => 'Employee not found.']); exit;
}

echo json_encode([
    'success'              => true,
    'employee_name'        => $emp['first_name'] . ' ' . $emp['last_name'],
    'employment_type'      => $emp['employment_type'] ?? 'FULL_TIME',   // ← NEW
    'shift_name'           => $emp['shift_name'] ?? null,
    'start_time'           => $emp['start_time'] ?? null,
    'end_time'             => $emp['end_time']   ?? null,
    'grace_period_minutes' => (int)($emp['grace_period_minutes'] ?? 0),
    'half_day_time'        => $emp['half_day_time'] ?? null,
]);