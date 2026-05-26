<?php
require_once __DIR__ . '/../../../includes/auth.php';
requirePrincipal();
require_once __DIR__ . '/../../../config/database.php';

$limit  = 12;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start  = ($page - 1) * $limit;
$deptFilter = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$search     = isset($_GET['search']) ? trim($_GET['search']) : '';

$deptStmt = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
$departments = $deptStmt->fetchAll();

$where  = "WHERE 1=1";
$params = [];
if ($deptFilter) {
    $where .= " AND e.department_id = :dept_id";
    $params[':dept_id'] = $deptFilter;
}
if ($search !== '') {
    $where .= " AND (e.first_name LIKE :search OR e.last_name LIKE :search OR e.employee_no LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM employees e $where");
$countStmt->execute($params);
$total = $countStmt->fetch()['total'];
$pages = max(1, ceil($total / $limit));

$params[':start'] = $start;
$params[':limit'] = $limit;

$stmt = $pdo->prepare("
    SELECT e.*, d.department_name, p.position_name,
           s.shift_name, s.start_time AS shift_start, s.end_time AS shift_end
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions p   ON e.position_id   = p.position_id
    LEFT JOIN shifts s      ON e.shift_id       = s.shift_id
    $where
    ORDER BY e.employee_id ASC
    LIMIT :start, :limit
");
$stmt->bindValue(':start', $start, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
foreach ($params as $k => $v) {
    if ($k !== ':start' && $k !== ':limit') $stmt->bindValue($k, $v);
}
$stmt->execute();
$employees = $stmt->fetchAll();

$pageTitle = 'Employee Directory';
$extraCSS  = [BASE_URL . 'assets/css/employee.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
    <?php include __DIR__ . '/../../../includes/principal-sidebar.php'; ?>
    <div class="main">
        <?php include __DIR__ . '/../../../includes/header.php'; ?>
        <div class="content">

            <div class="page-header">
                <div>
                    <h2 class="page-title">Employee Directory</h2>
                    <p class="page-date">View-only employee profiles — contact Admin to make changes</p>
                </div>
                <div class="actions">
                    <span style="font-size:12px;background:#f0fdf9;border:1px solid #a7f3d0;color:#065f46;padding:8px 14px;border-radius:8px;">
                        <i class="fa fa-eye"></i> View Only
                    </span>
                </div>
            </div>

            <div class="card">
                <div class="table-header">
                    <div style="position:relative;flex:1;max-width:300px;">
                        <i class="fa fa-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;pointer-events:none;"></i>
                        <input type="text" id="searchInput" placeholder="Search by name or ID..."
                               value="<?= htmlspecialchars($search) ?>"
                               onkeyup="searchTable()"
                               style="padding-left:32px;width:100%;">
                    </div>
                    <select id="deptFilterSelect" onchange="filterDept()">
                        <option value="0" <?= $deptFilter == 0 ? 'selected' : '' ?>>All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" <?= $deptFilter == $dept['department_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="table-wrapper">
                    <table id="empTable">
                        <thead>
                            <tr>
                                <th>Employee No</th>
                                <th>Name</th>
                                <th>Position</th>
                                <th>Department</th>
                                <th>Type</th>
                                <th>Shift</th>
                                <th>Date Hired</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state" style="padding:40px 24px;">
                                        <i class="fa fa-users-slash"></i>
                                        <p>No employees found</p>
                                        <small>Try adjusting your search or department filter</small>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($employees as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['employee_no'] ?? 'EMP-' . str_pad($row['employee_id'], 3, '0', STR_PAD_LEFT)) ?></td>
                                <td>
                                    <div class="emp-name">
                                        <strong><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></strong>
                                        <span><?= htmlspecialchars($row['email'] ?? '') ?></span>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($row['position_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['department_name'] ?? '-') ?></td>
                                <td>
                                    <?php
                                    $et = $row['employment_type'] ?? '';
                                    if ($et === 'FULL_TIME') echo '<span class="emp-type-badge full">Full-time</span>';
                                    elseif ($et === 'PART_TIME') echo '<span class="emp-type-badge part">Part-time</span>';
                                    else echo '-';
                                    ?>
                                </td>
                                <td><?php
                                    if ($row['shift_start'] && $row['shift_end']) {
                                        echo date('g:i A', strtotime($row['shift_start'])) . ' – ' . date('g:i A', strtotime($row['shift_end']));
                                    } elseif ($row['shift_name']) {
                                        echo htmlspecialchars($row['shift_name']);
                                    } else {
                                        echo '-';
                                    }
                                ?></td>
                                <td><?= $row['hire_date'] ? date('M j, Y', strtotime($row['hire_date'])) : '-' ?></td>
                                <td>
                                    <span class="badge <?= strtolower($row['employee_status']) ?>">
                                        <?= ucfirst(strtolower($row['employee_status'])) ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <button class="btn-view" onclick="openViewProfile(<?= $row['employee_id'] ?>)" title="View Profile">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <span>Showing <?= $total === 0 ? 0 : $start + 1 ?>–<?= min($start + $limit, $total) ?> of <?= $total ?> employees</span>
                    <div class="pagination">
                        <a href="?page=<?= max(1, $page-1) ?>&dept=<?= $deptFilter ?>&search=<?= urlencode($search) ?>" class="<?= $page == 1 ? 'disabled' : '' ?>">Previous</a>
                        <?php for ($i = 1; $i <= $pages; $i++): ?>
                            <a href="?page=<?= $i ?>&dept=<?= $deptFilter ?>&search=<?= urlencode($search) ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <a href="?page=<?= min($pages, $page+1) ?>&dept=<?= $deptFilter ?>&search=<?= urlencode($search) ?>" class="<?= $page == $pages ? 'disabled' : '' ?>">Next</a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- VIEW PROFILE MODAL ONLY (no add/edit/deactivate for Principal) -->
    <?php include __DIR__ . '/../../employees/modals/view-modal.php'; ?>

    <script>window.BASE_URL = '<?= BASE_URL ?>';</script>
    <script src="<?= BASE_URL ?>assets/js/employee.js?v=<?= filemtime(__DIR__ . '/../../../assets/js/employee.js') ?>"></script>
    <script>
    // Principal view — disable the Edit button inside view modal
    document.addEventListener('DOMContentLoaded', function() {
        const editBtn = document.querySelector('#viewEmployeeModal .btn-outline');
        if (editBtn) editBtn.style.display = 'none';
    });
    window.filterDept = function() {
        const dept   = document.getElementById('deptFilterSelect').value;
        const search = document.getElementById('searchInput')?.value || '';
        window.location.href = '?dept=' + dept + '&search=' + encodeURIComponent(search);
    };
    </script>
<?php include __DIR__ . '/../../../includes/footer.php'; ?>
