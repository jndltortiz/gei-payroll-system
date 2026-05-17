<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
redirectIfNoRole(['SUPERADMIN', 'PRINCIPAL', 'ASSISTANTPRINCIPAL']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

$departmentid = (int)($_POST['departmentid'] ?? 0);

if ($departmentid <= 0) {
    setFlash('danger', 'Invalid department.');
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

try {
    // Prevent delete if positions exist
    $check = $pdo->prepare("SELECT COUNT(*) FROM positions WHERE departmentid = :id");
    $check->execute([':id' => $departmentid]);
    if ((int)$check->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete — this department still has positions linked to it. Remove the positions first.');
        header('Location: ' . BASE_URL . 'modules/departments/index.php');
        exit;
    }

    // Get name for audit log
    $nameStmt = $pdo->prepare("SELECT departmentname FROM departments WHERE departmentid = :id");
    $nameStmt->execute([':id' => $departmentid]);
    $deptName = $nameStmt->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM departments WHERE departmentid = :id");
    $stmt->execute([':id' => $departmentid]);

    // Audit log
    $pdo->prepare("
        INSERT INTO auditlogs (userid, action, tablename, recordid, description)
        VALUES (:uid, 'DELETE', 'departments', :rid, :desc)
    ")->execute([
        ':uid'  => user()['userid'],
        ':rid'  => $departmentid,
        ':desc' => 'Deleted department: ' . $deptName,
    ]);

    setFlash('success', 'Department "' . $deptName . '" deleted successfully.');

} catch (PDOException $e) {
    setFlash('danger', 'Database error: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . 'modules/departments/index.php');
exit;