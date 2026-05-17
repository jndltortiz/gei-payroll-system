<?php
require_once __DIR__ . '/../../includes/auth.php';
require '../../config/database.php';

$limit = 6;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start = ($page - 1) * $limit;
$deptFilter = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch departments for filter
$deptStmt = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
$departments = $deptStmt->fetchAll();

// Fetch positions grouped by department (for cascade dropdowns)
$posStmt = $pdo->query("SELECT position_id, position_name, department_id FROM positions ORDER BY position_name");
$positions = $posStmt->fetchAll();

// Fetch shifts
$shiftStmt = $pdo->query("SELECT shift_id, shift_name, start_time, end_time, grace_period_minutes FROM shifts ORDER BY shift_name");
$shifts = $shiftStmt->fetchAll();

// Build query with filters
$where = "WHERE 1=1";
$params = [];

if ($deptFilter) {
    $where .= " AND e.department_id = :dept_id";
    $params[':dept_id'] = $deptFilter;
}
if ($search !== '') {
    $where .= " AND (e.first_name LIKE :search OR e.last_name LIKE :search OR e.employee_no LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM employees e $where");
$countStmt->execute($params);
$total = $countStmt->fetch()['total'];
$pages = max(1, ceil($total / $limit));

$params[':start'] = $start;
$params[':limit'] = $limit;

$stmt = $pdo->prepare("
    SELECT e.*, d.department_name, p.position_name, s.shift_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions p ON e.position_id = p.position_id
    LEFT JOIN shifts s ON e.shift_id = s.shift_id
    $where
    ORDER BY e.employee_id ASC
    LIMIT :start, :limit
");
$stmt->bindValue(':start', $start, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
foreach ($params as $k => $v) {
    if ($k !== ':start' && $k !== ':limit') {
        $stmt->bindValue($k, $v);
    }
}
$stmt->execute();
$result = $stmt->fetchAll();
?>

<?php include __DIR__ . '/../../includes/head.php'; ?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/dashboard.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/global.css">
<link rel="stylesheet" href="../../assets/css/employee.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<body>
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main">
        <?php include __DIR__ . '/../../includes/navbar.php'; ?>    
        <div class="content">

            <!-- HEADER -->
            <div class="page-header">
                <div>
                    <h2 class="page-title">Employee Management</h2>
                    <p class="page-date">Manage employee records, accounts, and profiles</p>
                </div>
                <div class="actions">
                    <button class="btn-outline"><i class="fa fa-file-export"></i> Export</button>
                    <button class="btn-primary" onclick="openModal('addEmployeeModal')">+ Add Employee</button>
                </div>
            </div>

            <!-- CARD -->
            <div class="card">
                <!-- FILTER BAR -->
                <div class="table-header">
                    <input
                        type="text"
                        id="searchInput"
                        placeholder="Search by name or ID..."
                        value="<?= htmlspecialchars($search) ?>"
                        onkeyup="searchTable()"
                    >
                    <select id="deptFilterSelect" onchange="filterDept()">
                        <option value="0" <?= $deptFilter == 0 ? 'selected' : '' ?>>All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" <?= $deptFilter == $dept['department_id'] ? 'selected' : '' ?>>
                                <?= $dept['department_name'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- TABLE -->
                <div class="table-wrapper">
                    <table id="empTable">
                        <thead>
                            <tr>
                                <th>Employee No</th>
                                <th>Name</th>
                                <th>Position</th>
                                <th>Department</th>
                                <th>Shift</th>
                                <th>Date Hired</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result as $row): ?>
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
                                <td><?= htmlspecialchars($row['shift_name'] ?? '-') ?></td>
                                <td><?= $row['hire_date'] ? date('M j, Y', strtotime($row['hire_date'])) : '-' ?></td>
                                <td>
                                    <span class="badge <?= strtolower($row['employee_status']) ?>">
                                        <?= ucfirst(strtolower($row['employee_status'])) ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <button class="btn-view" onclick="viewEmployee(<?= $row['employee_id'] ?>)" title="View">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                    <button class="btn-edit" onclick="editEmployee(<?= $row['employee_id'] ?>)" title="Edit">
                                        <i class="fa fa-pen"></i>
                                    </button>
                                    <button class="btn-delete" onclick="setDeactivate(<?= $row['employee_id'] ?>, '<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>')" title="Deactivate">
                                        <i class="fa fa-user-slash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- FOOTER -->
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

    <!-- MODALS -->
    <?php
    $modalPath = __DIR__ . '/modals/';
    include $modalPath . 'add-modal.php';
    include $modalPath . 'edit-modal.php';
    include $modalPath . 'view-modal.php';
    include $modalPath . 'deactivate-modal.php';
    include $modalPath . 'save-modal.php';
    ?>

    <!-- positions JSON for JS cascade -->
    <script>
        window.allPositions = <?= json_encode($positions) ?>;
    </script>
    <script src="../../assets/js/employee.js?v=<?= filemtime(__DIR__ . '/../../assets/js/employee.js') ?>"></script>
</body>