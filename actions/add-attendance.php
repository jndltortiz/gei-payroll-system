<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO attendance_records
                (employee_id, attendance_date, time_in, time_out, attendance_status, attendance_source, remarks)
            VALUES (?, ?, ?, ?, ?, 'MANUAL', ?)
        ");

        $stmt->execute([
            $_POST['employee_id'],
            $_POST['date'],
            $_POST['time_in']  ?: null,
            $_POST['time_out'] ?: null,
            $_POST['status'],
            $_POST['remarks']  ?? null,
        ]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}