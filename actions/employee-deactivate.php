<?php
require '../config/database.php';

$stmt = $pdo->prepare("
UPDATE employees SET employee_status='INACTIVE'
WHERE employee_id=?
");

$stmt->execute([$_POST['employee_id']]);

header("Location: ../modules/employees/index.php");