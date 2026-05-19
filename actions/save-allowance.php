<?php
// actions/save-allowance.php
// Handles both ADD (no allowance_type_id) and EDIT (with allowance_type_id)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
requireLogin();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

// Validate
$name      = trim($body['allowance_name'] ?? '');
$amount    = max(0, (float)($body['default_amount'] ?? 0));
$isTaxable = isset($body['is_taxable']) ? (int)(bool)$body['is_taxable'] : 0;
$isActive  = isset($body['is_active'])  ? (int)(bool)$body['is_active']  : 1;
$appliesTo = in_array($body['applies_to'] ?? '', ['ALL','DEPARTMENT','POSITION','EMPLOYEE'])
    ? $body['applies_to'] : 'ALL';

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Allowance name is required.']);
    exit;
}

$id = !empty($body['allowance_type_id']) ? (int)$body['allowance_type_id'] : null;

try {
    if ($id) {
        // --- EDIT ---
        $check = $pdo->prepare("SELECT allowance_type_id FROM allowance_types WHERE allowance_name = ? AND allowance_type_id != ?");
        $check->execute([$name, $id]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'An allowance with that name already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE allowance_types SET
                allowance_name = :name,
                default_amount = :amount,
                is_taxable     = :is_taxable,
                is_active      = :is_active
            WHERE allowance_type_id = :id
        ");
        $stmt->execute([
            ':name'       => $name,
            ':amount'     => $amount,
            ':is_taxable' => $isTaxable,
            ':is_active'  => $isActive,
            ':id'         => $id,
        ]);

        updateAssignment($pdo, $id, $appliesTo);
        auditLog($pdo, 'UPDATE', $id, "Allowance updated: {$name}");
        echo json_encode(['success' => true, 'message' => 'Allowance updated successfully.', 'id' => $id]);

    } else {
        // --- ADD ---
        $check = $pdo->prepare("SELECT allowance_type_id FROM allowance_types WHERE allowance_name = ?");
        $check->execute([$name]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'An allowance with that name already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO allowance_types (allowance_name, default_amount, is_taxable, is_active)
            VALUES (:name, :amount, :is_taxable, :is_active)
        ");
        $stmt->execute([
            ':name'       => $name,
            ':amount'     => $amount,
            ':is_taxable' => $isTaxable,
            ':is_active'  => $isActive,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Insert assignment
        $pdo->prepare("INSERT INTO allowance_assignments (allowance_type_id, applies_to, target_id, is_active) VALUES (?,?,NULL,1)")
            ->execute([$newId, $appliesTo]);

        auditLog($pdo, 'INSERT', $newId, "Allowance created: {$name}");
        echo json_encode(['success' => true, 'message' => 'Allowance added successfully.', 'id' => $newId]);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

// ─── Helpers ────────────────────────────────────────────────

function updateAssignment(PDO $pdo, int $id, string $appliesTo): void
{
    $exists = $pdo->prepare("SELECT assignment_id FROM allowance_assignments WHERE allowance_type_id = ? LIMIT 1");
    $exists->execute([$id]);
    if ($exists->fetch()) {
        $pdo->prepare("UPDATE allowance_assignments SET applies_to = ?, target_id = NULL, updated_at = NOW() WHERE allowance_type_id = ?")
            ->execute([$appliesTo, $id]);
    } else {
        $pdo->prepare("INSERT INTO allowance_assignments (allowance_type_id, applies_to, target_id, is_active) VALUES (?,?,NULL,1)")
            ->execute([$id, $appliesTo]);
    }
}

function auditLog(PDO $pdo, string $action, int $recordId, string $desc): void
{
    $userId = $_SESSION['user']['user_id'] ?? null;
    if (!$userId) return;
    $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?,?,?,?,?)")
        ->execute([$userId, $action, 'allowance_types', $recordId, $desc]);
}