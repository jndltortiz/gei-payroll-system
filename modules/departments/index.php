<?php
require_once __DIR__ . '/../../includes/auth.php';
requireAdminPage();

$pageTitle = 'Departments';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;
$flash = getFlash();
$esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$departments = [];
$positionsByDepartment = [];
$totalPages = 1;
$totalRecords = 0;
$totalPositions = 0;
$totalAssignedEmployees = 0;
$loadError = false;

try {
    $where = '';
    $params = [];

    if ($search !== '') {
        $where = 'WHERE d.department_name LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare("
        SELECT
            d.*,
            COALESCE(pc.total_positions, 0) AS total_positions,
            COALESCE(ec.total_employees, 0) AS total_employees
        FROM departments d
        LEFT JOIN (
            SELECT department_id, COUNT(*) AS total_positions
            FROM positions
            GROUP BY department_id
        ) pc ON pc.department_id = d.department_id
        LEFT JOIN (
            SELECT department_id, COUNT(*) AS total_employees
            FROM employees
            GROUP BY department_id
        ) ec ON ec.department_id = d.department_id
        {$where}
        ORDER BY d.department_name ASC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $departments = $stmt->fetchAll();

    if ($search !== '') {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_name LIKE :search");
        $countStmt->execute([':search' => '%' . $search . '%']);
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM departments");
    }
    $totalRecords = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRecords / $limit));

    $positionStmt = $pdo->query("
        SELECT
            p.*,
            COALESCE(ec.total_employees, 0) AS total_employees
        FROM positions p
        LEFT JOIN (
            SELECT position_id, COUNT(*) AS total_employees
            FROM employees
            GROUP BY position_id
        ) ec ON ec.position_id = p.position_id
        ORDER BY p.position_name ASC
    ");
    foreach ($positionStmt->fetchAll() as $position) {
        $deptId = (int) $position['department_id'];
        $positionsByDepartment[$deptId][] = $position;
        $totalPositions++;
    }

    $totalAssignedEmployees = (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE department_id IS NOT NULL")->fetchColumn();
} catch (Throwable $e) {
    error_log('[departments] Failed to load department management page: ' . $e->getMessage());
    $loadError = true;
}

$extraCSS = [BASE_URL . 'assets/css/departments.css'];
$loadBootstrap = false;
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="main">
    <?php include __DIR__ . '/../../includes/header.php'; ?>

    <div class="content">
        <div class="dept-page">
            <div class="dept-page-header">
                <div>
                    <h1><i class="fa fa-building"></i> Departments</h1>
                    <p>Manage workforce departments and the positions available inside each department.</p>
                </div>
                <a href="<?= BASE_URL ?>modules/departments/create.php" class="dept-btn dept-btn--primary">
                    <i class="fa fa-plus"></i>
                    <span>Add Department</span>
                </a>
            </div>

            <?php if ($flash): ?>
                <div class="dept-alert dept-alert--<?= $esc($flash['type'] ?? 'info') ?>">
                    <i class="fa <?= ($flash['type'] ?? '') === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <span><?= $esc($flash['message'] ?? '') ?></span>
                </div>
            <?php endif; ?>

            <?php if ($loadError): ?>
                <div class="dept-alert dept-alert--danger">
                    <i class="fa fa-circle-exclamation"></i>
                    <span>Departments could not be loaded right now. Please try again or check the server logs.</span>
                </div>
            <?php endif; ?>

            <div class="dept-stats-grid">
                <div class="dept-stat-card">
                    <div class="dept-stat-icon"><i class="fa fa-sitemap"></i></div>
                    <div>
                        <strong><?= number_format($totalRecords) ?></strong>
                        <span>Departments</span>
                    </div>
                </div>
                <div class="dept-stat-card">
                    <div class="dept-stat-icon dept-stat-icon--blue"><i class="fa fa-id-badge"></i></div>
                    <div>
                        <strong><?= number_format($totalPositions) ?></strong>
                        <span>Positions</span>
                    </div>
                </div>
                <div class="dept-stat-card">
                    <div class="dept-stat-icon dept-stat-icon--gold"><i class="fa fa-users"></i></div>
                    <div>
                        <strong><?= number_format($totalAssignedEmployees) ?></strong>
                        <span>Assigned Employees</span>
                    </div>
                </div>
            </div>

            <div class="dept-toolbar">
                <form method="GET" class="dept-search">
                    <i class="fa fa-magnifying-glass"></i>
                    <input
                        type="text"
                        name="search"
                        value="<?= $esc($search) ?>"
                        placeholder="Search departments..."
                    >
                    <button type="submit" class="dept-btn dept-btn--subtle">
                        <i class="fa fa-search"></i>
                        <span>Search</span>
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="<?= BASE_URL ?>modules/departments/index.php" class="dept-btn dept-btn--ghost">
                            <i class="fa fa-xmark"></i>
                            <span>Clear</span>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="dept-list-card">
                <div class="dept-list-head">
                    <div>Department</div>
                    <div>Description</div>
                    <div>Positions</div>
                    <div>Employees</div>
                    <div>Actions</div>
                </div>

                <?php if (empty($departments)): ?>
                    <div class="dept-empty-state">
                        <i class="fa fa-folder-open"></i>
                        <strong>No departments found</strong>
                        <span>Try another search or add the first department.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($departments as $dept): ?>
                        <?php
                        $deptId = (int) $dept['department_id'];
                        $deptPositions = $positionsByDepartment[$deptId] ?? [];
                        ?>
                        <section class="dept-row" id="department-<?= $deptId ?>">
                            <div class="dept-row-main">
                                <div class="dept-name-cell">
                                    <div class="dept-avatar"><?= $esc(strtoupper(substr($dept['department_name'], 0, 1))) ?></div>
                                    <div>
                                        <strong><?= $esc($dept['department_name']) ?></strong>
                                        <span>ID <?= $deptId ?></span>
                                    </div>
                                </div>
                                <div class="dept-desc-cell">
                                    <?= $dept['description'] ? $esc($dept['description']) : '<span class="dept-muted">No description</span>' ?>
                                </div>
                                <div>
                                    <button
                                        type="button"
                                        class="dept-count-pill"
                                        data-toggle-positions="<?= $deptId ?>">
                                        <i class="fa fa-id-badge"></i>
                                        <span><?= (int) $dept['total_positions'] ?></span>
                                    </button>
                                </div>
                                <div class="dept-employee-count">
                                    <?= number_format((int) $dept['total_employees']) ?>
                                </div>
                                <div class="dept-actions">
                                    <button
                                        type="button"
                                        class="dept-icon-btn"
                                        title="Manage positions"
                                        data-toggle-positions="<?= $deptId ?>">
                                        <i class="fa fa-list-check"></i>
                                    </button>
                                    <a
                                        href="<?= BASE_URL ?>modules/departments/edit.php?id=<?= $deptId ?>"
                                        class="dept-icon-btn"
                                        title="Edit department">
                                        <i class="fa fa-pen"></i>
                                    </a>
                                    <form
                                        method="POST"
                                        action="<?= BASE_URL ?>actions/department-delete.php"
                                        data-confirm-title="Delete Department"
                                        data-confirm-message="Delete <?= $esc($dept['department_name']) ?>?"
                                        data-confirm-note="This will fail if positions are still linked to the department."
                                        data-confirm-type="danger"
                                        data-confirm-btn="Delete">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="department_id" value="<?= $deptId ?>">
                                        <button type="submit" class="dept-icon-btn dept-icon-btn--danger" title="Delete department">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="dept-positions-panel" id="positions-panel-<?= $deptId ?>" hidden>
                                <div class="dept-positions-head">
                                    <div>
                                        <strong>Positions in <?= $esc($dept['department_name']) ?></strong>
                                        <span>These populate the Employee Department to Position dropdown.</span>
                                    </div>
                                    <button
                                        type="button"
                                        class="dept-btn dept-btn--primary dept-btn--small"
                                        data-open-position-modal
                                        data-mode="create"
                                        data-department-id="<?= $deptId ?>"
                                        data-department-name="<?= $esc($dept['department_name']) ?>">
                                        <i class="fa fa-plus"></i>
                                        <span>Add Position</span>
                                    </button>
                                </div>

                                <?php if (empty($deptPositions)): ?>
                                    <div class="dept-position-empty">
                                        <i class="fa fa-circle-info"></i>
                                        <span>No positions yet. Add positions before assigning employees to this department.</span>
                                    </div>
                                <?php else: ?>
                                    <div class="dept-position-list">
                                        <?php foreach ($deptPositions as $position): ?>
                                            <div class="dept-position-item">
                                                <div>
                                                    <strong><?= $esc($position['position_name']) ?></strong>
                                                    <span><?= $position['description'] ? $esc($position['description']) : 'No description' ?></span>
                                                </div>
                                                <div class="dept-position-meta">
                                                    <span><?= number_format((int) $position['total_employees']) ?> employees</span>
                                                    <button
                                                        type="button"
                                                        class="dept-icon-btn"
                                                        title="Edit position"
                                                        data-open-position-modal
                                                        data-mode="update"
                                                        data-department-id="<?= $deptId ?>"
                                                        data-department-name="<?= $esc($dept['department_name']) ?>"
                                                        data-position-id="<?= (int) $position['position_id'] ?>"
                                                        data-position-name="<?= $esc($position['position_name']) ?>"
                                                        data-position-description="<?= $esc($position['description'] ?? '') ?>">
                                                        <i class="fa fa-pen"></i>
                                                    </button>
                                                    <form
                                                        method="POST"
                                                        action="<?= BASE_URL ?>actions/position-action.php"
                                                        data-confirm-title="Delete Position"
                                                        data-confirm-message="Delete <?= $esc($position['position_name']) ?>?"
                                                        data-confirm-note="This will fail if employees are assigned to the position."
                                                        data-confirm-type="danger"
                                                        data-confirm-btn="Delete">
                                                        <?= csrfInput() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="department_id" value="<?= $deptId ?>">
                                                        <input type="hidden" name="position_id" value="<?= (int) $position['position_id'] ?>">
                                                        <button type="submit" class="dept-icon-btn dept-icon-btn--danger" title="Delete position">
                                                            <i class="fa fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="dept-pagination">
                        <span>
                            Showing <?= $totalRecords === 0 ? 0 : $offset + 1 ?>-<?= min($offset + $limit, $totalRecords) ?>
                            of <?= number_format($totalRecords) ?>
                        </span>
                        <div>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <a
                                    href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"
                                    class="<?= $p === $page ? 'active' : '' ?>">
                                    <?= $p ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="dept-modal" id="positionModal" aria-hidden="true">
    <div class="dept-modal-backdrop" data-close-position-modal></div>
    <div class="dept-modal-box" role="dialog" aria-modal="true" aria-labelledby="positionModalTitle">
        <div class="dept-modal-head">
            <div>
                <h2 id="positionModalTitle">Add Position</h2>
                <p id="positionModalDept">Department</p>
            </div>
            <button type="button" class="dept-icon-btn" data-close-position-modal title="Close">
                <i class="fa fa-xmark"></i>
            </button>
        </div>
        <form method="POST" action="<?= BASE_URL ?>actions/position-action.php" class="dept-modal-body">
            <?= csrfInput() ?>
            <input type="hidden" name="action" id="positionAction" value="create">
            <input type="hidden" name="department_id" id="positionDepartmentId">
            <input type="hidden" name="position_id" id="positionId">

            <label class="dept-field">
                <span>Position Name</span>
                <input type="text" name="position_name" id="positionName" required maxlength="100">
            </label>

            <label class="dept-field">
                <span>Description</span>
                <textarea name="description" id="positionDescription" rows="3" maxlength="255"></textarea>
            </label>

            <div class="dept-modal-actions">
                <button type="button" class="dept-btn dept-btn--ghost" data-close-position-modal>Cancel</button>
                <button type="submit" class="dept-btn dept-btn--primary" id="positionSubmitBtn">
                    <i class="fa fa-floppy-disk"></i>
                    <span>Save Position</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('positionModal');
    const title = document.getElementById('positionModalTitle');
    const deptLabel = document.getElementById('positionModalDept');
    const action = document.getElementById('positionAction');
    const departmentId = document.getElementById('positionDepartmentId');
    const positionId = document.getElementById('positionId');
    const positionName = document.getElementById('positionName');
    const positionDescription = document.getElementById('positionDescription');
    const submitBtn = document.getElementById('positionSubmitBtn');

    function openPanel(id) {
        const panel = document.getElementById('positions-panel-' + id);
        if (!panel) return;
        panel.hidden = !panel.hidden;
    }

    function openModal(button) {
        const mode = button.dataset.mode || 'create';
        const deptName = button.dataset.departmentName || 'Department';
        action.value = mode;
        departmentId.value = button.dataset.departmentId || '';
        positionId.value = button.dataset.positionId || '';
        positionName.value = button.dataset.positionName || '';
        positionDescription.value = button.dataset.positionDescription || '';
        title.textContent = mode === 'update' ? 'Edit Position' : 'Add Position';
        deptLabel.textContent = deptName;
        submitBtn.querySelector('span').textContent = mode === 'update' ? 'Update Position' : 'Save Position';
        modal.classList.add('dept-modal--open');
        modal.setAttribute('aria-hidden', 'false');
        positionName.focus();
    }

    function closeModal() {
        modal.classList.remove('dept-modal--open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-toggle-positions]').forEach(function (button) {
        button.addEventListener('click', function () {
            openPanel(button.dataset.togglePositions);
        });
    });

    document.querySelectorAll('[data-open-position-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(button);
        });
    });

    document.querySelectorAll('[data-close-position-modal]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModal();
    });

    if (window.location.hash && window.location.hash.indexOf('#department-') === 0) {
        const id = window.location.hash.replace('#department-', '');
        const panel = document.getElementById('positions-panel-' + id);
        if (panel) panel.hidden = false;
    }
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
