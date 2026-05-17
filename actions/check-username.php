<?php
include '../config/db.php'; 

$year = date('Y');

$stmt = $pdo->prepare("
    SELECT employee_no 
    FROM employees 
    WHERE employee_no LIKE :pattern
    ORDER BY employee_no DESC 
    LIMIT 1
");

$pattern = "EMP-$year-%";
$stmt->execute(['pattern' => $pattern]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $last = $row['employee_no'];

    // get last 3 digits
    $num = (int)substr($last, -3);
    $num++;
} else {
    $num = 1;
}

$newNo = str_pad($num, 3, '0', STR_PAD_LEFT);

echo json_encode([
    "employee_no" => "EMP-$year-$newNo"
]);