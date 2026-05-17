<?php
// actions/save-deduction.php
// Handles both ADD (no deduction_type_id) and EDIT (with deduction_type_id)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');
requireLogin();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

// Validate required fields
$name = trim($body['deduction_name'] ?? '');
if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Deduction name is required.']);
    exit;
}

$valueType = in_array($body['deduction_value_type'] ?? '', ['FIXED', 'PERCENTAGE'])
    ? $body['deduction_value_type'] : 'FIXED';
$amount    = max(0, (float)($body['deduction_amount'] ?? 0));
$isActive  = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;
$appliesTo = in_array($body['applies_to'] ?? '', ['ALL','DEPARTMENT','POSITION','EMPLOYEE'])
    ? $body['applies_to'] : 'ALL';

// Set amount vs rate depending on type
$dedAmount = $valueType === 'FIXED'       ? $amount : 0;
$dedRate   = $valueType === 'PERCENTAGE'  ? $amount : 0;

$id = !empty($body['deduction_type_id']) ? (int)$body['deduction_type_id'] : null;

try {
    if ($id) {
        // --- EDIT ---
        // Check name uniqueness (excluding self)
        $check = $pdo->prepare("SELECT deduction_type_id FROM deduction_types WHERE deduction_name = ? AND deduction_type_id != ?");
        $check->execute([$name, $id]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'A deduction with that name already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE deduction_types SET
                deduction_name       = :name,
                deduction_value_type = :value_type,
                deduction_amount     = :amount,
                deduction_rate       = :rate,
                is_active            = :is_active
            WHERE deduction_type_id  = :id
              AND is_government = 0
              AND is_loan = 0
        ");
        $stmt->execute([
            ':name'       => $name,
            ':value_type' => $valueType,
            ':amount'     => $dedAmount,
            ':rate'       => $dedRate,
            ':is_active'  => $isActive,
            ':id'         => $id,
        ]);

        // Update assignment applies_to
        updateAssignment($pdo, 'deduction_assignments', 'deduction_type_id', $id, $appliesTo);

        auditLog($pdo, 'UPDATE', 'deduction_types', $id, "Deduction updated: {$name}");

        echo json_encode(['success' => true, 'message' => 'Deduction updated successfully.', 'id' => $id]);

    } else {
        // --- ADD ---
        // Check name uniqueness
        $check = $pdo->prepare("SELECT deduction_type_id FROM deduction_types WHERE deduction_name = ?");
        $check->execute([$name]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'A deduction with that name already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO deduction_types
                (deduction_name, deduction_value_type, deduction_amount, deduction_rate,
                 is_government, is_loan, is_active)
            VALUES
                (:name, :value_type, :amount, :rate, 0, 0, :is_active)
        ");
        $stmt->execute([
            ':name'       => $name,
            ':value_type' => $valueType,
            ':amount'     => $dedAmount,
            ':rate'       => $dedRate,
            ':is_active'  => $isActive,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Insert assignment
        $aStmt = $pdo->prepare("
            INSERT INTO deduction_assignments
                (deduction_type_id, applies_to, target_id, is_active)
            VALUES (:did, :applies_to, NULL, 1)
        ");
        $aStmt->execute([':did' => $newId, ':applies_to' => $appliesTo]);

        auditLog($pdo, 'INSERT', 'deduction_types', $newId, "Deduction created: {$name}");

        echo json_encode(['success' => true, 'message' => 'Deduction added successfully.', 'id' => $newId]);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

// ─── Helpers ────────────────────────────────────────────────

function updateAssignment(PDO $pdo, string $table, string $col, int $id, string $appliesTo): void
{
    $exists = $pdo->prepare("SELECT assignment_id FROM $table WHERE $col = ? LIMIT 1");
    $exists->execute([$id]);
    $row = $exists->fetch();

    if ($row) {
        $pdo->prepare("UPDATE $table SET applies_to = ?, target_id = NULL, updated_at = NOW() WHERE $col = ?")
            ->execute([$appliesTo, $id]);
    } else {
        $pdo->prepare("INSERT INTO $table ($col, applies_to, target_id, is_active) VALUES (?,?,NULL,1)")
            ->execute([$id, $appliesTo]);
    }
}

function auditLog(PDO $pdo, string $action, string $table, int $recordId, string $desc): void
{
    $userId = $_SESSION['user']['user_id'] ?? null;
    if (!$userId) return;
    $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?,?,?,?,?)")
        ->execute([$userId, $action, $table, $recordId, $desc]);
}