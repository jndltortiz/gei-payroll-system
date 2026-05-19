<?php
require_once __DIR__ . '/../../config/config.php';

$pageTitle = 'Leave Management';

// Fetch summary stats
$stmtPending = $pdo->prepare("
    SELECT COUNT(*) as cnt
    FROM leave_requests lr
    WHERE lr.status = 'PENDING'
");
$stmtPending->execute();
$pendingCount = $stmtPending->fetchColumn();

// Get current user's leave balance (leave_transactions sum)
$userId = $_SESSION['user']['user_id'] ?? null;
$employeeId = $_SESSION['user']['employee_id'] ?? null;

// My leave balance: standard leave days - days used
$myBalance = 0;
$myDaysUsed = 0;
if ($employeeId) {
    // Get payroll settings for default paid leave days
    $stmtSettings = $pdo->query("SELECT default_paid_leave_days FROM payroll_settings LIMIT 1");
    $settings = $stmtSettings->fetch();
    $defaultDays = $settings['default_paid_leave_days'] ?? 30;

    // Days used = approved leave transactions (debit)
    $stmtUsed = $pdo->prepare("
        SELECT COALESCE(SUM(lt.days), 0)
        FROM leave_transactions lt
        JOIN leave_transaction_types ltt ON lt.transaction_type_id = ltt.transaction_type_id
        WHERE lt.employee_id = ?
          AND ltt.type_name IN ('DEBIT', 'USE', 'USED', 'DEDUCTION')
    ");
    $stmtUsed->execute([$employeeId]);
    $myDaysUsed = (float) $stmtUsed->fetchColumn();

    // Fallback: count approved leave_requests total_days
    if ($myDaysUsed == 0) {
        $stmtApproved = $pdo->prepare("
            SELECT COALESCE(SUM(total_days), 0)
            FROM leave_requests
            WHERE employee_id = ? AND status = 'APPROVED'
        ");
        $stmtApproved->execute([$employeeId]);
        $myDaysUsed = (float) $stmtApproved->fetchColumn();
    }

    $myBalance = $defaultDays - $myDaysUsed;
}

// Staff on leave today
$today = date('Y-m-d');
$stmtOnLeave = $pdo->prepare("
    SELECT COUNT(DISTINCT lr.employee_id)
    FROM leave_requests lr
    WHERE lr.status = 'APPROVED'
      AND ? BETWEEN lr.start_date AND lr.end_date
");
$stmtOnLeave->execute([$today]);
$staffOnLeave = $stmtOnLeave->fetchColumn();

// Fetch all leave records with employee info
$filterStatus = $_GET['status'] ?? 'ALL';

$sql = "
    SELECT
        lr.leave_id,
        lr.employee_id,
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        e.first_name,
        e.last_name,
        d.department_name,
        lt.leave_name,
        lr.leave_type_id,
        lr.reason,
        lr.start_date,
        lr.end_date,
        lr.total_days,
        lr.status,
        lr.approved_by,
        lr.approved_at,
        lr.remarks,
        lr.created_at,
        CONCAT(au.first_name, ' ', au.last_name) AS approver_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    JOIN departments d ON e.department_id = d.department_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    LEFT JOIN users u ON lr.approved_by = u.user_id
    LEFT JOIN employees au ON u.employee_id = au.employee_id
";

$params = [];
if ($filterStatus !== 'ALL') {
    $sql .= " WHERE lr.status = ?";
    $params[] = $filterStatus;
}
$sql .= " ORDER BY lr.created_at DESC";

$stmtLeave = $pdo->prepare($sql);
$stmtLeave->execute($params);
$leaveRecords = $stmtLeave->fetchAll();

// Get leave types for the file-a-leave modal
$stmtTypes = $pdo->query("SELECT leave_type_id, leave_name FROM leave_types WHERE 1 ORDER BY leave_name");
$leaveTypes = $stmtTypes->fetchAll();

$pageTitle     = $pageTitle ?? 'Leave Management';
$extraCSS      = [BASE_URL . 'assets/css/leave.css'];
$loadBootstrap = true;
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">

    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main-wrapper">

        <?php include __DIR__ . '/../../includes/header.php'; ?>

        <main class="main-content">

            <!-- PAGE HEADER -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Leave Management</h1>
                    <p class="page-subtitle">File your own leave requests and manage employee leave records.</p>
                </div>
                <button class="btn-file-leave" id="btnFileLeave">
                    <i class="fa fa-plus"></i> File a Leave
                </button>
            </div>

            <!-- STAT CARDS -->
            <div class="stat-cards">
                <div class="stat-card stat-card--blue">
                    <div class="stat-card__label">My Leave Balance</div>
                    <div class="stat-card__value stat-card__value--blue"><?= number_format($myBalance, 0) ?> Days</div>
                    <div class="stat-card__sub">Available this year</div>
                </div>
                <div class="stat-card stat-card--amber">
                    <div class="stat-card__label">Pending Approvals</div>
                    <div class="stat-card__value stat-card__value--amber"><?= $pendingCount ?> Request<?= $pendingCount != 1 ? 's' : '' ?></div>
                    <div class="stat-card__sub">Awaiting your review</div>
                </div>
                <div class="stat-card stat-card--teal">
                    <div class="stat-card__label">Staff On Leave Today</div>
                    <div class="stat-card__value stat-card__value--teal"><?= $staffOnLeave ?> Staff</div>
                    <div class="stat-card__sub">As of today, <?= date('M d, Y') ?></div>
                </div>
            </div>

            <!-- LEAVE RECORDS TABLE -->
            <div class="leave-table-card">
                <div class="leave-table-card__header">
                    <div>
                        <h2 class="leave-table-card__title">All Leave Records</h2>
                        <p class="leave-table-card__sub">Click a row to expand individual date statuses</p>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Filter:</label>
                        <select class="filter-select" id="statusFilter" onchange="applyFilter(this.value)">
                            <option value="ALL" <?= $filterStatus === 'ALL' ? 'selected' : '' ?>>All</option>
                            <option value="PENDING" <?= $filterStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                            <option value="APPROVED" <?= $filterStatus === 'APPROVED' ? 'selected' : '' ?>>Approved</option>
                            <option value="REJECTED" <?= $filterStatus === 'REJECTED' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                </div>

                <table class="leave-table">
                    <thead>
                        <tr>
                            <th style="width:32px;"></th>
                            <th>Employee Name</th>
                            <th>Leave Type</th>
                            <th>Dates</th>
                            <th>Total Days</th>
                            <th>Overall Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="leaveTableBody">
                        <?php if (empty($leaveRecords)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    <i class="fa fa-calendar-xmark"></i>
                                    <p>No leave records found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leaveRecords as $record): ?>
                                <?php
                                    $initials = strtoupper(substr($record['first_name'], 0, 1) . substr($record['last_name'], 0, 1));
                                    $isPending = $record['status'] === 'PENDING';
                                    $isCurrentUser = ($record['employee_id'] == $employeeId);

                                    // Parse dates for display
                                    $startDate = new DateTime($record['start_date']);
                                    $endDate   = new DateTime($record['end_date']);
                                    $totalDays = (float)$record['total_days'];

                                    // Build date pills: start + end + overflow
                                    $diffDays = (int)$endDate->diff($startDate)->days;
                                    $datePills = [];
                                    $datePills[] = $startDate->format('M d');
                                    if ($diffDays >= 1) $datePills[] = $endDate->format('M d');
                                    $extra = $diffDays > 1 ? ('+' . ($diffDays - 1) . ' more') : null;
                                ?>
                                <!-- MAIN ROW -->
                                <tr class="leave-row" data-leave-id="<?= $record['leave_id'] ?>" onclick="toggleExpand(this, <?= $record['leave_id'] ?>)">
                                    <td class="chevron-cell">
                                        <span class="chevron"><i class="fa fa-chevron-down"></i></span>
                                    </td>
                                    <td class="name-cell">
                                        <?= htmlspecialchars($record['employee_name']) ?>
                                        <?php if ($isCurrentUser): ?><span class="you-badge">(You)</span><?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($record['leave_name']) ?></td>
                                    <td class="dates-cell">
                                        <?php foreach ($datePills as $pill): ?>
                                            <span class="date-pill"><?= $pill ?></span>
                                        <?php endforeach; ?>
                                        <?php if ($extra): ?>
                                            <span class="date-pill date-pill--more"><?= $extra ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $totalDays == 1 ? '1 Day' : $totalDays . ' Days' ?></td>
                                    <td>
                                        <span class="status-badge status-badge--<?= strtolower($record['status']) ?>">
                                            <?= ucfirst(strtolower($record['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="actions-cell" onclick="event.stopPropagation()">
                                        <button class="btn-action btn-action--view"
                                            onclick="openViewModal(<?= $record['leave_id'] ?>)">
                                            <i class="fa fa-eye"></i> View
                                        </button>
                                        <?php if ($isPending): ?>
                                            <button class="btn-action btn-action--approve"
                                                onclick="quickAction(<?= $record['leave_id'] ?>, 'approve')">
                                                <i class="fa fa-check"></i> All
                                            </button>
                                            <button class="btn-action btn-action--reject"
                                                onclick="quickAction(<?= $record['leave_id'] ?>, 'reject')">
                                                <i class="fa fa-times"></i> All
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <!-- EXPANDED ROW (hidden by default) -->
                                <tr class="expand-row" id="expand-<?= $record['leave_id'] ?>" style="display:none;">
                                    <td colspan="7" class="expand-cell">
                                        <div class="expand-inner" id="expand-inner-<?= $record['leave_id'] ?>">
                                            <div class="expand-loading">
                                                <i class="fa fa-spinner fa-spin"></i> Loading...
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>
</div>

<!-- MODALS -->
<?php include __DIR__ . '/modals/file-leave.php'; ?>
<?php include __DIR__ . '/modals/view-leave.php'; ?>
<?php include __DIR__ . '/modals/confirm-action.php'; ?>
<?php include __DIR__ . '/modals/deny-reason.php'; ?>

<script>
    const LEAVE_TYPES = <?= json_encode($leaveTypes) ?>;
    const BASE_URL    = '<?= BASE_URL ?>';
    const EMPLOYEE_ID = <?= $employeeId ?? 'null' ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/leave.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>