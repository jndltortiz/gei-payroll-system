<?php
// actions/delete-deduction.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
requireLogin();

$body = json_decode(file_get_contents('php://input'), true);
$id   = (int)($body['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid deduction ID.']);
    exit;
}

try {
    // Prevent deletion of government / loan deductions
    $check = $pdo->prepare("SELECT deduction_name, is_government, is_loan FROM deduction_types WHERE deduction_type_id = ?");
    $check->execute([$id]);
    $ded = $check->fetch();

    if (!$ded) {
        echo json_encode(['success' => false, 'message' => 'Deduction not found.']);
        exit;
    }

    if ($ded['is_government'] || $ded['is_loan']) {
        echo json_encode(['success' => false, 'message' => 'Government and loan deductions cannot be deleted.']);
        exit;
    }

    // Check if in use in payroll_deductions
    $inUse = $pdo->prepare("SELECT COUNT(*) FROM payroll_deductions WHERE deduction_type_id = ?");
    $inUse->execute([$id]);
    if ($inUse->fetchColumn() > 0) {
        // Soft-delete: just mark inactive
        $pdo->prepare("UPDATE deduction_types SET is_active = 0 WHERE deduction_type_id = ?")
            ->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Deduction deactivated (it has payroll history).']);
        exit;
    }

    // Hard delete: remove assignments first, then the type
    $pdo->prepare("DELETE FROM deduction_assignments WHERE deduction_type_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM deduction_types WHERE deduction_type_id = ? AND is_government = 0 AND is_loan = 0")->execute([$id]);

    // Audit
    $userId = $_SESSION['user']['user_id'] ?? null;
    if ($userId) {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?,?,?,?,?)")
            ->execute([$userId, 'DELETE', 'deduction_types', $id, "Deduction deleted: {$ded['deduction_name']}"]);
    }

    echo json_encode(['success' => true, 'message' => 'Deduction deleted successfully.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}