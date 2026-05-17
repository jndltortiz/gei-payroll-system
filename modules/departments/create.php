<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
redirectIfNoRole(['SUPERADMIN', 'PRINCIPAL', 'ASSISTANTPRINCIPAL']);

$pageTitle = 'Edit Department';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'Invalid department.');
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE departmentid = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$dept = $stmt->fetch();

if (!$dept) {
    setFlash('danger', 'Department not found.');
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

        <div class="content-area">

            <div class="mb-4">
                <a href="<?= BASE_URL ?>modules/departments/index.php"
                   class="text-decoration-none text-muted small">
                    <i class="bi bi-arrow-left me-1"></i> Back to Departments
                </a>
                <h2 class="page-title mt-2">Edit Department</h2>
            </div>

            <div class="card border-0 shadow-sm rounded-4" style="max-width: 600px;">
                <div class="card-body p-4">
                    <form action="<?= BASE_URL ?>actions/department-save.php" method="POST">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="departmentid"
                               value="<?= $dept['departmentid'] ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Department Name <span class="text-danger">*</span>
                            </label>
                            <input
                                type="text"
                                name="departmentname"
                                class="form-control"
                                value="<?= htmlspecialchars($dept['departmentname']) ?>"
                                required
                                maxlength="100"
                            >
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea
                                name="description"
                                class="form-control"
                                rows="3"
                                maxlength="255"
                            ><?= htmlspecialchars($dept['description'] ?? '') ?></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Update Department
                            </button>
                            <a href="<?= BASE_URL ?>modules/departments/index.php"
                               class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

        </div>
        <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
    </div>
</div>