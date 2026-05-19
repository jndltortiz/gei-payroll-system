<?php
// actions/save-assignment.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
requireLogin();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

$itemType  = $body['item_type'] ?? '';   // 'allowance' | 'deduction' | 'loan'
$itemId    = (int)($body['item_id'] ?? 0);
$appliesTo = $body['applies_to'] ?? 'ALL';
$targetId  = !empty($body['target_id']) ? (int)$body['target_id'] : null;

// Validate
$validTypes = ['allowance', 'deduction', 'loan'];
if (!in_array($itemType, $validTypes) || !$itemId) {
    echo json_encode(['success' => false, 'message' => 'Invalid item type or ID.']);
    exit;
}

$validScopes = ['ALL', 'DEPARTMENT', 'POSITION', 'EMPLOYEE'];
if (!in_array($appliesTo, $validScopes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid assignment scope.']);
    exit;
}

// Scope requires a target_id
if ($appliesTo !== 'ALL' && !$targetId) {
    echo json_encode(['success' => false, 'message' => 'A target must be selected for this scope.']);
    exit;
}

// Validate target_id exists in the right table
if ($appliesTo === 'DEPARTMENT' && $targetId) {
    $v = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ?");
    $v->execute([$targetId]);
    if (!$v->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Selected department not found.']);
        exit;
    }
}
if ($appliesTo === 'POSITION' && $targetId) {
    $v = $pdo->prepare("SELECT position_id FROM positions WHERE position_id = ?");
    $v->execute([$targetId]);
    if (!$v->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Selected position not found.']);
        exit;
    }
}
if ($appliesTo === 'EMPLOYEE' && $targetId) {
    $v = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ? AND employee_status = 'ACTIVE'");
    $v->execute([$targetId]);
    if (!$v->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Selected employee not found or inactive.']);
        exit;
    }
}

// Resolve table and FK column
$tableMap = [
    'allowance' => ['allowance_assignments', 'allowance_type_id'],
    'deduction' => ['deduction_assignments', 'deduction_type_id'],
    'loan'      => ['loan_type_assignments', 'loan_type_id'],
];

[$assignTable, $fkCol] = $tableMap[$itemType];

try {
    // Check if assignment already exists for this item
    $existing = $pdo->prepare("SELECT assignment_id FROM $assignTable WHERE $fkCol = ? LIMIT 1");
    $existing->execute([$itemId]);
    $row = $existing->fetch();

    if ($row) {
        $pdo->prepare("
            UPDATE $assignTable
            SET applies_to = :applies_to,
                target_id  = :target_id,
                updated_at = NOW()
            WHERE $fkCol = :item_id
        ")->execute([
            ':applies_to' => $appliesTo,
            ':target_id'  => $appliesTo === 'ALL' ? null : $targetId,
            ':item_id'    => $itemId,
        ]);
    } else {
        $pdo->prepare("
            INSERT INTO $assignTable ($fkCol, applies_to, target_id, is_active)
            VALUES (:item_id, :applies_to, :target_id, 1)
        ")->execute([
            ':item_id'    => $itemId,
            ':applies_to' => $appliesTo,
            ':target_id'  => $appliesTo === 'ALL' ? null : $targetId,
        ]);
    }

    // Audit
    $userId = $_SESSION['user']['user_id'] ?? null;
    if ($userId) {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?,?,?,?,?)")
            ->execute([
                $userId,
                'UPDATE',
                $assignTable,
                $itemId,
                ucfirst($itemType) . " assignment updated to: {$appliesTo}" . ($targetId ? " (target: {$targetId})" : ''),
            ]);
    }

    echo json_encode(['success' => true, 'message' => 'Assignment saved successfully.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}