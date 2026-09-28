<?php
require_once __DIR__ . '/../../includes/auth.php';
requireAdminPage();

$pageTitle = 'Add Department';
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
                    <h1><i class="fa fa-building-circle-check"></i> Add Department</h1>
                    <p>Create a department before adding its positions.</p>
                </div>
            </div>

            <form action="<?= BASE_URL ?>actions/department-save.php" method="POST" class="dept-form-card">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="create">

                <label class="dept-field">
                    <span>Department Name</span>
                    <input
                        type="text"
                        name="department_name"
                        placeholder="e.g. Teaching, Administration"
                        required
                        maxlength="100"
                    >
                </label>

                <label class="dept-field">
                    <span>Description</span>
                    <textarea
                        name="description"
                        rows="3"
                        placeholder="Optional short description"
                        maxlength="255"
                    ></textarea>
                </label>

                <div class="dept-form-actions">
                    <a href="<?= BASE_URL ?>modules/departments/index.php" class="dept-btn dept-btn--ghost">Cancel</a>
                    <button type="submit" class="dept-btn dept-btn--primary">
                        <i class="fa fa-floppy-disk"></i>
                        <span>Save Department</span>
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
