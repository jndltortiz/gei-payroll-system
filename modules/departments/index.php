<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Departments';

$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

try {
    if ($search !== '') {
        $stmt = $pdo->prepare("
            SELECT d.*, COUNT(p.positionid) AS totalpositions
            FROM departments d
            LEFT JOIN positions p ON p.departmentid = d.departmentid
            WHERE d.departmentname LIKE :search
            GROUP BY d.departmentid
            ORDER BY d.departmentname ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':search', '%' . $search . '%');
    } else {
        $stmt = $pdo->prepare("
            SELECT d.*, COUNT(p.positionid) AS totalpositions
            FROM departments d
            LEFT JOIN positions p ON p.departmentid = d.departmentid
            GROUP BY d.departmentid
            ORDER BY d.departmentname ASC
            LIMIT :limit OFFSET :offset
        ");
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $departments = $stmt->fetchAll();

    // Total count for pagination
    if ($search !== '') {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE departmentname LIKE :search");
        $countStmt->execute([':search' => '%' . $search . '%']);
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM departments");
    }
    $totalRecords = (int) $countStmt->fetchColumn();
    $totalPages   = ceil($totalRecords / $limit);

} catch (Exception $e) {
    $departments  = [];
    $totalPages   = 1;
    $totalRecords = 0;
}

$pageTitle     = 'Departments';
$extraCSS      = [];
$loadBootstrap = true;
require_once __DIR__ . '/../../includes/head.php';
?>
<body>

<div class="app-wrapper">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../../includes/header.php'; ?>

        <div class="content-area">

            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h2 class="page-title">Departments</h2>
                    <p class="page-subtitle mb-0">Manage school departments</p>
                </div>
                <?php if (hasRole(['SUPERADMIN','PRINCIPAL','ASSISTANTPRINCIPAL'])): ?>
                <a href="<?= BASE_URL ?>modules/departments/create.php"
                   class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> Add Department
                </a>
                <?php endif; ?>
            </div>

            <!-- Search -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-3">
                    <form method="GET" class="d-flex gap-2">
                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            placeholder="Search department name..."
                            value="<?= htmlspecialchars($search) ?>"
                            style="max-width: 320px;"
                        >
                        <button class="btn btn-outline-primary" type="submit">
                            <i class="bi bi-search"></i> Search
                        </button>
                        <?php if ($search): ?>
                            <a href="<?= BASE_URL ?>modules/departments/index.php"
                               class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" style="width: 60px;">#</th>
                                    <th>Department Name</th>
                                    <th>Description</th>
                                    <th class="text-center">Positions</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($departments)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-5">
                                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                            No departments found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($departments as $i => $dept): ?>
                                    <tr>
                                        <td class="ps-4 text-muted">
                                            <?= $offset + $i + 1 ?>
                                        </td>
                                        <td class="fw-semibold">
                                            <?= htmlspecialchars($dept['departmentname']) ?>
                                        </td>
                                        <td class="text-muted">
                                            <?= htmlspecialchars($dept['description'] ?? '—') ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary-subtle text-primary rounded-pill px-3">
                                                <?= $dept['totalpositions'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if (hasRole(['SUPERADMIN','PRINCIPAL','ASSISTANTPRINCIPAL'])): ?>
                                            <a href="<?= BASE_URL ?>modules/departments/edit.php?id=<?= $dept['departmentid'] ?>"
                                               class="btn btn-sm btn-outline-primary me-1">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="confirmDelete(<?= $dept['departmentid'] ?>, '<?= htmlspecialchars($dept['departmentname'], ENT_QUOTES) ?>')">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                            <?php else: ?>
                                                <span class="text-muted small">View only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex align-items-center justify-content-between px-4 py-3 border-top">
                        <small class="text-muted">
                            Showing <?= $offset + 1 ?>–<?= min($offset + $limit, $totalRecords) ?>
                            of <?= $totalRecords ?> departments
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link"
                                       href="?page=<?= $p ?>&search=<?= urlencode($search) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Delete Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">Are you sure you want to delete:</p>
                <p class="fw-semibold" id="deleteTargetName"></p>
                <div class="alert alert-warning rounded-3 mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    This will fail if the department still has positions linked to it.
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteForm" method="POST"
                      action="<?= BASE_URL ?>actions/department-delete.php">
                    <input type="hidden" name="departmentid" id="deleteTargetId">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    document.getElementById('deleteTargetId').value = id;
    document.getElementById('deleteTargetName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>