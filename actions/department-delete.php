<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdminPage();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

$departmentId = (int)($_POST['department_id'] ?? $_POST['departmentid'] ?? 0);

if ($departmentId <= 0) {
    setFlash('danger', 'Invalid department.');
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

try {
    $userId = $_SESSION['user']['user_id'] ?? null;
    $pdo->beginTransaction();

    $check = $pdo->prepare("SELECT COUNT(*) FROM positions WHERE department_id = :id");
    $check->execute([':id' => $departmentId]);
    if ((int)$check->fetchColumn() > 0) {
        $pdo->rollBack();
        setFlash('danger', 'Cannot delete this department because positions are still linked to it. Remove the positions first.');
        header('Location: ' . BASE_URL . 'modules/departments/index.php');
        exit;
    }

    $nameStmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = :id");
    $nameStmt->execute([':id' => $departmentId]);
    $deptName = $nameStmt->fetchColumn();

    if ($deptName === false) {
        $pdo->rollBack();
        setFlash('danger', 'Department not found.');
        header('Location: ' . BASE_URL . 'modules/departments/index.php');
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM departments WHERE department_id = :id");
    $stmt->execute([':id' => $departmentId]);

    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
        VALUES (:uid, 'DELETE', 'departments', :rid, :desc)
    ")->execute([
        ':uid'  => $userId,
        ':rid'  => $departmentId,
        ':desc' => 'Deleted department: ' . $deptName,
    ]);

    $pdo->commit();
    setFlash('success', 'Department "' . $deptName . '" deleted successfully.');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('danger', 'Database error: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . 'modules/departments/index.php');
exit;
