<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdminPage();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

$action         = $_POST['action'] ?? '';
$departmentName = trim($_POST['department_name'] ?? $_POST['departmentname'] ?? '');
$description    = trim($_POST['description'] ?? '');
$departmentId   = (int)($_POST['department_id'] ?? $_POST['departmentid'] ?? 0);

if ($departmentName === '') {
    setFlash('danger', 'Department name is required.');
    $redirect = $action === 'update'
        ? BASE_URL . 'modules/departments/edit.php?id=' . $departmentId
        : BASE_URL . 'modules/departments/create.php';
    header('Location: ' . $redirect);
    exit;
}

try {
    $userId = $_SESSION['user']['user_id'] ?? null;

    if ($action === 'create') {
        $check = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_name = :name");
        $check->execute([':name' => $departmentName]);
        if ((int)$check->fetchColumn() > 0) {
            setFlash('danger', 'A department with that name already exists.');
            header('Location: ' . BASE_URL . 'modules/departments/create.php');
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO departments (department_name, description)
            VALUES (:name, :desc)
        ");
        $stmt->execute([
            ':name' => $departmentName,
            ':desc' => $description ?: null,
        ]);

        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (:uid, 'CREATE', 'departments', :rid, :desc)
        ")->execute([
            ':uid'  => $userId,
            ':rid'  => $pdo->lastInsertId(),
            ':desc' => 'Created department: ' . $departmentName,
        ]);

        setFlash('success', 'Department "' . $departmentName . '" created successfully.');

    } elseif ($action === 'update') {
        if ($departmentId <= 0) {
            setFlash('danger', 'Invalid department ID.');
            header('Location: ' . BASE_URL . 'modules/departments/index.php');
            exit;
        }

        $check = $pdo->prepare("
            SELECT COUNT(*) FROM departments
            WHERE department_name = :name AND department_id != :id
        ");
        $check->execute([':name' => $departmentName, ':id' => $departmentId]);
        if ((int)$check->fetchColumn() > 0) {
            setFlash('danger', 'Another department with that name already exists.');
            header('Location: ' . BASE_URL . 'modules/departments/edit.php?id=' . $departmentId);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE departments
            SET department_name = :name,
                description     = :desc
            WHERE department_id = :id
        ");
        $stmt->execute([
            ':name' => $departmentName,
            ':desc' => $description ?: null,
            ':id'   => $departmentId,
        ]);

        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (:uid, 'UPDATE', 'departments', :rid, :desc)
        ")->execute([
            ':uid'  => $userId,
            ':rid'  => $departmentId,
            ':desc' => 'Updated department: ' . $departmentName,
        ]);

        setFlash('success', 'Department updated successfully.');

    } else {
        setFlash('danger', 'Invalid action.');
    }

} catch (PDOException $e) {
    setFlash('danger', 'Database error: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . 'modules/departments/index.php');
exit;
