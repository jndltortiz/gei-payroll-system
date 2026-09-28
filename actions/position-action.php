<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdminPage();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

$action = $_POST['action'] ?? '';
$departmentId = (int)($_POST['department_id'] ?? 0);
$positionId = (int)($_POST['position_id'] ?? 0);
$positionName = trim($_POST['position_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$redirect = BASE_URL . 'modules/departments/index.php' . ($departmentId > 0 ? '#department-' . $departmentId : '');
$userId = $_SESSION['user']['user_id'] ?? null;

$redirectTo = static function (string $url): void {
    header('Location: ' . $url);
    exit;
};

if ($departmentId <= 0) {
    setFlash('danger', 'Invalid department.');
    $redirectTo(BASE_URL . 'modules/departments/index.php');
}

try {
    $deptStmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = :id");
    $deptStmt->execute([':id' => $departmentId]);
    $departmentName = $deptStmt->fetchColumn();

    if ($departmentName === false) {
        setFlash('danger', 'Department not found.');
        $redirectTo(BASE_URL . 'modules/departments/index.php');
    }

    if ($action === 'create' || $action === 'update') {
        if ($positionName === '') {
            setFlash('danger', 'Position name is required.');
            $redirectTo($redirect);
        }

        $duplicateSql = "
            SELECT COUNT(*)
            FROM positions
            WHERE department_id = :department_id
              AND position_name = :position_name
        ";
        $duplicateParams = [
            ':department_id' => $departmentId,
            ':position_name' => $positionName,
        ];

        if ($action === 'update') {
            if ($positionId <= 0) {
                setFlash('danger', 'Invalid position.');
                $redirectTo($redirect);
            }

            $duplicateSql .= " AND position_id != :position_id";
            $duplicateParams[':position_id'] = $positionId;
        }

        $dupStmt = $pdo->prepare($duplicateSql);
        $dupStmt->execute($duplicateParams);
        if ((int)$dupStmt->fetchColumn() > 0) {
            setFlash('danger', 'That position already exists in this department.');
            $redirectTo($redirect);
        }
    }

    if ($action === 'create') {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO positions (department_id, position_name, description)
            VALUES (:department_id, :position_name, :description)
        ");
        $stmt->execute([
            ':department_id' => $departmentId,
            ':position_name' => $positionName,
            ':description' => $description !== '' ? $description : null,
        ]);
        $newPositionId = (int)$pdo->lastInsertId();

        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (:user_id, 'CREATE', 'positions', :record_id, :description)
        ");
        $audit->execute([
            ':user_id' => $userId,
            ':record_id' => $newPositionId,
            ':description' => 'Created position "' . $positionName . '" under department "' . $departmentName . '".',
        ]);

        $pdo->commit();
        setFlash('success', 'Position "' . $positionName . '" created successfully.');
        $redirectTo($redirect);
    }

    if ($action === 'update') {
        $ownerStmt = $pdo->prepare("
            SELECT position_name
            FROM positions
            WHERE position_id = :position_id AND department_id = :department_id
        ");
        $ownerStmt->execute([
            ':position_id' => $positionId,
            ':department_id' => $departmentId,
        ]);
        $oldPositionName = $ownerStmt->fetchColumn();

        if ($oldPositionName === false) {
            setFlash('danger', 'Position not found in this department.');
            $redirectTo($redirect);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE positions
            SET position_name = :position_name,
                description = :description
            WHERE position_id = :position_id
              AND department_id = :department_id
        ");
        $stmt->execute([
            ':position_name' => $positionName,
            ':description' => $description !== '' ? $description : null,
            ':position_id' => $positionId,
            ':department_id' => $departmentId,
        ]);

        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (:user_id, 'UPDATE', 'positions', :record_id, :description)
        ");
        $audit->execute([
            ':user_id' => $userId,
            ':record_id' => $positionId,
            ':description' => 'Updated position "' . $oldPositionName . '" to "' . $positionName . '".',
        ]);

        $pdo->commit();
        setFlash('success', 'Position updated successfully.');
        $redirectTo($redirect);
    }

    if ($action === 'delete') {
        if ($positionId <= 0) {
            setFlash('danger', 'Invalid position.');
            $redirectTo($redirect);
        }

        $positionStmt = $pdo->prepare("
            SELECT position_name
            FROM positions
            WHERE position_id = :position_id AND department_id = :department_id
        ");
        $positionStmt->execute([
            ':position_id' => $positionId,
            ':department_id' => $departmentId,
        ]);
        $positionName = $positionStmt->fetchColumn();

        if ($positionName === false) {
            setFlash('danger', 'Position not found in this department.');
            $redirectTo($redirect);
        }

        $employeeStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE position_id = :position_id");
        $employeeStmt->execute([':position_id' => $positionId]);
        if ((int)$employeeStmt->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete this position because employees are assigned to it.');
            $redirectTo($redirect);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            DELETE FROM positions
            WHERE position_id = :position_id
              AND department_id = :department_id
        ");
        $stmt->execute([
            ':position_id' => $positionId,
            ':department_id' => $departmentId,
        ]);

        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (:user_id, 'DELETE', 'positions', :record_id, :description)
        ");
        $audit->execute([
            ':user_id' => $userId,
            ':record_id' => $positionId,
            ':description' => 'Deleted position "' . $positionName . '" from department "' . $departmentName . '".',
        ]);

        $pdo->commit();
        setFlash('success', 'Position "' . $positionName . '" deleted successfully.');
        $redirectTo($redirect);
    }

    setFlash('danger', 'Invalid position action.');
    $redirectTo($redirect);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[position-action] ' . $e->getMessage());
    setFlash('danger', 'Position could not be saved. Please try again.');
    $redirectTo($redirect);
}
