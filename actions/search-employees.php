<?php
// If placed at: actions/search-employees.php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT e.employee_id, e.employee_no, e.first_name, e.last_name,
           d.department_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE e.employee_status = 'ACTIVE'
      AND (
          e.first_name LIKE :q
          OR e.last_name LIKE :q
          OR e.employee_no LIKE :q
          OR CONCAT(e.first_name, ' ', e.last_name) LIKE :q
      )
    ORDER BY e.first_name ASC
    LIMIT 20
");

$stmt->execute([':q' => '%' . $q . '%']);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results);
exit;