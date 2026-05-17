<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
redirectIfNoRole(['SUPERADMIN', 'PRINCIPAL', 'ASSISTANTPRINCIPAL']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

$action         = $_POST['action'] ?? '';
$departmentname = trim($_POST['departmentname'] ?? '');
$description    = trim($_POST['description'] ?? '');
$departmentid   = (int)($_POST['departmentid'] ?? 0);

if ($departmentname === '') {
    setFlash('danger', 'Department name is required.');
    $redirect = $action === 'update'
        ? BASE_URL . 'modules/departments/edit.php?id=' . $departmentid
        : BASE_URL . 'modules/departments/create.php';
    header('Location: ' . $redirect);
    exit;
}

try {
    if ($action === 'create') {
        // Check duplicate
        $check = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE departmentname = :name");
        $check->execute([':name' => $departmentname]);
        if ((int)$check->fetchColumn() > 0) {
            setFlash('danger', 'A department with that name already exists.');
            header('Location: ' . BASE_URL . 'modules/departments/create.php');
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO departments (departmentname, description)
            VALUES (:name, :desc)
        ");
        $stmt->execute([
            ':name' => $departmentname,
            ':desc' => $description ?: null,
        ]);

        // Audit log
        $pdo->prepare("
            INSERT INTO auditlogs (userid, action, tablename, recordid, description)
            VALUES (:uid, 'CREATE', 'departments', :rid, :desc)
        ")->execute([
            ':uid'  => user()['userid'],
            ':rid'  => $pdo->lastInsertId(),
            ':desc' => 'Created department: ' . $departmentname,
        ]);

        setFlash('success', 'Department "' . $departmentname . '" created successfully.');

    } elseif ($action === 'update') {
        if ($departmentid <= 0) {
            setFlash('danger', 'Invalid department ID.');
            header('Location: ' . BASE_URL . 'modules/departments/index.php');
            exit;
        }

        // Check duplicate (excluding self)
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM departments
            WHERE departmentname = :name AND departmentid != :id
        ");
        $check->execute([':name' => $departmentname, ':id' => $departmentid]);
        if ((int)$check->fetchColumn() > 0) {
            setFlash('danger', 'Another department with that name already exists.');
            header('Location: ' . BASE_URL . 'modules/departments/edit.php?id=' . $departmentid);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE departments
            SET departmentname = :name,
                description    = :desc
            WHERE departmentid = :id
        ");
        $stmt->execute([
            ':name' => $departmentname,
            ':desc' => $description ?: null,
            ':id'   => $departmentid,
        ]);

        // Audit log
        $pdo->prepare("
            INSERT INTO auditlogs (userid, action, tablename, recordid, description)
            VALUES (:uid, 'UPDATE', 'departments', :rid, :desc)
        ")->execute([
            ':uid'  => user()['userid'],
            ':rid'  => $departmentid,
            ':desc' => 'Updated department: ' . $departmentname,
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