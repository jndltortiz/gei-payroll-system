<?php
require_once __DIR__ . '/../../includes/auth.php';
requireAdminPage();

$pageTitle = 'Edit Department';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'Invalid department.');
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE department_id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$dept = $stmt->fetch();

if (!$dept) {
    setFlash('danger', 'Department not found.');
    header('Location: ' . BASE_URL . 'modules/departments/index.php');
    exit;
}

$extraCSS = [BASE_URL . 'assets/css/departments.css'];
$loadBootstrap = false;
require_once __DIR__ . '/../../includes/head.php';
?>
<body>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="main">
    <?php require_once __DIR__ . '/../../includes/header.php'; ?>

    <div class="content">
        <div class="dept-page">
            <div class="dept-page-header">
                <div>
                    <a href="<?= BASE_URL ?>modules/departments/index.php" class="dept-back-link">
                        <i class="fa fa-arrow-left"></i>
                        <span>Back to Departments</span>
                    </a>
                    <h1><i class="fa fa-building-pen"></i> Edit Department</h1>
                    <p>Update the department name and description.</p>
                </div>
            </div>

            <form action="<?= BASE_URL ?>actions/department-save.php" method="POST" class="dept-form-card">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="department_id" value="<?= (int)$dept['department_id'] ?>">

                <label class="dept-field">
                    <span>Department Name</span>
                    <input
                        type="text"
                        name="department_name"
                        value="<?= htmlspecialchars($dept['department_name'], ENT_QUOTES, 'UTF-8') ?>"
                        required
                        maxlength="100"
                    >
                </label>

                <label class="dept-field">
                    <span>Description</span>
                    <textarea
                        name="description"
                        rows="3"
                        maxlength="255"
                    ><?= htmlspecialchars($dept['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </label>

                <div class="dept-form-actions">
                    <a href="<?= BASE_URL ?>modules/departments/index.php" class="dept-btn dept-btn--ghost">Cancel</a>
                    <button type="submit" class="dept-btn dept-btn--primary">
                        <i class="fa fa-floppy-disk"></i>
                        <span>Update Department</span>
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
