<?php
require '../config/database.php';
 
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
 
$stmt = $pdo->prepare("
    SELECT 
        e.*,
        d.department_name,
        p.position_name,
        s.shift_name,

        ec.monthly_salary,
        COALESCE(
            NULLIF(ec.daily_rate, 0),
            ROUND(ec.monthly_salary / 22, 2)
        ) AS daily_rate,

        u.username,
        r.role_name

    FROM employees e

    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions p ON e.position_id = p.position_id
    LEFT JOIN shifts s ON e.shift_id = s.shift_id

    LEFT JOIN employee_compensations ec 
        ON e.employee_id = ec.employee_id AND ec.is_active = 1

    LEFT JOIN users u ON e.employee_id = u.employee_id
    LEFT JOIN roles r ON u.role_id = r.role_id

    WHERE e.employee_id = ?
");

$stmt->execute([$_GET['id']]);
 
header('Content-Type: application/json');
echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));