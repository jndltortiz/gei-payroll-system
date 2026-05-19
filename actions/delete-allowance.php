<?php
// actions/delete-allowance.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
requireLogin();

$body = json_decode(file_get_contents('php://input'), true);
$id   = (int)($body['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid allowance ID.']);
    exit;
}

try {
    $check = $pdo->prepare("SELECT allowance_name FROM allowance_types WHERE allowance_type_id = ?");
    $check->execute([$id]);
    $al = $check->fetch();

    if (!$al) {
        echo json_encode(['success' => false, 'message' => 'Allowance not found.']);
        exit;
    }

    // Check if used in payroll history
    $inUse = $pdo->prepare("SELECT COUNT(*) FROM payroll_allowances WHERE allowance_type_id = ?");
    $inUse->execute([$id]);

    if ($inUse->fetchColumn() > 0) {
        // Soft-delete
        $pdo->prepare("UPDATE allowance_types SET is_active = 0 WHERE allowance_type_id = ?")
            ->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Allowance deactivated (it has payroll history).']);
        exit;
    }

    // Hard delete
    $pdo->prepare("DELETE FROM allowance_assignments WHERE allowance_type_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM allowance_types WHERE allowance_type_id = ?")->execute([$id]);

    // Audit
    $userId = $_SESSION['user']['user_id'] ?? null;
    if ($userId) {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?,?,?,?,?)")
            ->execute([$userId, 'DELETE', 'allowance_types', $id, "Allowance deleted: {$al['allowance_name']}"]);
    }

    echo json_encode(['success' => true, 'message' => 'Allowance deleted successfully.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}