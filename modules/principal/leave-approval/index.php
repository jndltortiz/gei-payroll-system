<?php
// modules/principal/leave-approval/index.php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requirePrincipal();

$principal_id = $_SESSION['user']['employee_id'];
$user_id      = $_SESSION['user']['user_id'];

// ── Stats ─────────────────────────────────────────────────────────────────────

// Principal's leave balance
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(CASE WHEN tt.type_name IN ('CREDIT','ADJUSTMENT') THEN lt.days ELSE -lt.days END), 0) AS balance
    FROM leave_transactions lt
    JOIN leave_transaction_types tt ON lt.transaction_type_id = tt.transaction_type_id
    WHERE lt.employee_id = ?
");
$stmt->execute([$principal_id]);
$leave_balance = (int) $stmt->fetchColumn();

// Pending date reviews
$stmt = $pdo->query("
    SELECT COUNT(*) AS cnt,
           COUNT(DISTINCT lr.leave_id) AS requests
    FROM leave_request_dates lrd
    JOIN leave_requests lr ON lrd.leave_id = lr.leave_id
    WHERE lrd.status = 'PENDING'
");
$pending = $stmt->fetch(PDO::FETCH_ASSOC);
$pending_dates   = (int) $pending['cnt'];
$pending_requests = (int) $pending['requests'];

// Staff on leave today
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT lrd.leave_id) AS cnt
    FROM leave_request_dates lrd
    JOIN leave_requests lr ON lrd.leave_id = lr.leave_id
    WHERE lrd.leave_date = ? AND lrd.status = 'APPROVED'
");
$stmt->execute([$today]);
$staff_on_leave = (int) $stmt->fetchColumn();

// ── Pending requests (requests that have at least 1 pending date) ─────────────
$pending_leaves = $pdo->query("
    SELECT
        lr.leave_id,
        lr.employee_id,
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        CONCAT(LEFT(e.first_name,1), LEFT(e.last_name,1)) AS initials,
        p.position_name,
        lt.leave_name,
        lr.reason,
        lr.created_at AS filed_at,
        COUNT(CASE WHEN lrd.status = 'PENDING'  THEN 1 END) AS pending_count,
        COUNT(CASE WHEN lrd.status = 'APPROVED' THEN 1 END) AS approved_count,
        COUNT(CASE WHEN lrd.status = 'REJECTED' THEN 1 END) AS rejected_count,
        -- overall status for badge
        CASE
            WHEN COUNT(CASE WHEN lrd.status = 'PENDING' THEN 1 END) > 0
                 AND COUNT(CASE WHEN lrd.status IN ('APPROVED','REJECTED') THEN 1 END) > 0 THEN 'Partial'
            WHEN COUNT(CASE WHEN lrd.status = 'PENDING' THEN 1 END) > 0 THEN 'Pending'
            WHEN COUNT(CASE WHEN lrd.status = 'APPROVED' THEN 1 END) = COUNT(*) THEN 'Approved'
            ELSE 'Rejected'
        END AS overall_status
    FROM leave_requests lr
    JOIN employees e  ON lr.employee_id = e.employee_id
    JOIN positions  p ON e.position_id  = p.position_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lr.status IN ('PENDING','APPROVED')
    GROUP BY lr.leave_id
    HAVING pending_count > 0
    ORDER BY lr.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch individual dates per request
$leave_dates = [];
if ($pending_leaves) {
    $ids = implode(',', array_column($pending_leaves, 'leave_id'));
    $rows = $pdo->query("
        SELECT lrd.*, e2.first_name AS actioned_first, e2.last_name AS actioned_last
        FROM leave_request_dates lrd
        LEFT JOIN users u ON lrd.actioned_by = u.user_id
        LEFT JOIN employees e2 ON u.employee_id = e2.employee_id
        WHERE lrd.leave_id IN ($ids)
        ORDER BY lrd.leave_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $leave_dates[$r['leave_id']][] = $r;
    }
}

// ── Recent actioned leaves ────────────────────────────────────────────────────
$recent_actioned = $pdo->query("
    SELECT
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        lt.leave_name,
        lr.start_date, lr.end_date, lr.total_days,
        lr.status,
        lr.updated_at
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    WHERE lr.status IN ('APPROVED','REJECTED')
       OR (lr.status = 'PENDING' AND lr.approved_by IS NOT NULL)
    ORDER BY lr.updated_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Leave type options (for file modal) ──────────────────────────────────────
$leave_types = $pdo->query("SELECT leave_type_id, leave_name FROM leave_types WHERE 1 ORDER BY leave_name")->fetchAll(PDO::FETCH_ASSOC);

$display_date = date('M d, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Management – Principal Portal</title>
<link rel="stylesheet" href="../../../assets/css/principal-leave.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
<!-- Lucide icons via CDN -->
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <div class="logo-box">GEI</div>
        <span class="sidebar-label">Principal Portal</span>
    </div>

    <nav class="sidebar-nav">
        <a href="../dashboard/index.php" class="nav-item" title="Dashboard">
            <i data-lucide="layout-dashboard"></i><span>Dashboard</span>
        </a>
        <a href="../leave-approval/index.php" class="nav-item active" title="Leave">
            <i data-lucide="calendar-check"></i><span>Leave</span>
        </a>
        <a href="../payroll-approval/index.php" class="nav-item" title="Payroll">
            <i data-lucide="banknote"></i><span>Payroll</span>
        </a>
        <a href="../loan-approval/index.php" class="nav-item" title="Loans">
            <i data-lucide="hand-coins"></i><span>Loans</span>
        </a>
        <a href="../reports/index.php" class="nav-item" title="Reports">
            <i data-lucide="bar-chart-2"></i><span>Reports</span>
        </a>
        <a href="../settings/index.php" class="nav-item" title="Settings">
            <i data-lucide="settings"></i><span>Settings</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../../../actions/logout.php" class="nav-item logout" title="Logout">
            <i data-lucide="log-out"></i><span>Logout</span>
        </a>
    </div>
    <button class="sidebar-toggle" id="sidebarToggle">
        <i data-lucide="chevron-left" id="toggleIcon"></i>
    </button>
</aside>

<!-- ── Main ─────────────────────────────────────────────────────────────────── -->
<main class="main-content">

    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-left">
            <div class="school-info">
                <i data-lucide="school"></i>
                <span>Great Eastern Institute</span>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-icon notif-btn" id="notifBtn">
                <i data-lucide="bell"></i>
                <span class="notif-dot"></span>
            </button>
            <div class="user-chip" id="userMenuTrigger">
                <span class="user-name">Jose Rizal</span>
                <span class="user-role">School Principal</span>
                <div class="avatar">JR</div>
            </div>
        </div>
    </header>

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Leave Management</h1>
            <p class="page-subtitle">Review employee leave requests and file your own leave.</p>
        </div>
        <button class="btn-primary" id="fileLeaveBtn">
            <i data-lucide="plus"></i> File a Leave
        </button>
    </div>

    <!-- Stats row -->
    <div class="stats-row">
        <div class="stat-card stat-blue">
            <div class="stat-label">My Leave Balance</div>
            <div class="stat-value"><?= $leave_balance ?> Days</div>
            <div class="stat-sub">Available this year</div>
        </div>
        <div class="stat-card stat-amber">
            <div class="stat-label">Pending Date Reviews</div>
            <div class="stat-value"><?= $pending_dates ?> <?= $pending_dates == 1 ? 'Date' : 'Dates' ?></div>
            <div class="stat-sub">Across <?= $pending_requests ?> <?= $pending_requests == 1 ? 'request' : 'requests' ?></div>
        </div>
        <div class="stat-card stat-teal">
            <div class="stat-label">Staff On Leave Today</div>
            <div class="stat-value"><?= $staff_on_leave ?> <?= $staff_on_leave == 1 ? 'Staff' : 'Staff' ?></div>
            <div class="stat-sub">As of today, <?= $display_date ?></div>
        </div>
    </div>

    <!-- ── Pending Review ──────────────────────────────────────────────────── -->
    <section class="section">
        <div class="section-header">
            <h2 class="section-title">
                Pending Review
                <span class="count-badge"><?= count($pending_leaves) ?> <?= count($pending_leaves) == 1 ? 'request' : 'requests' ?></span>
            </h2>
            <span class="section-hint">Approve or reject each date individually</span>
        </div>

        <?php if (empty($pending_leaves)): ?>
        <div class="empty-state">
            <i data-lucide="check-circle-2"></i>
            <p>All caught up! No pending leave requests.</p>
        </div>
        <?php else: ?>
        <div class="leave-list" id="leaveList">
        <?php foreach ($pending_leaves as $leave):
            $dates  = $leave_dates[$leave['leave_id']] ?? [];
            $status = $leave['overall_status'];
            $badge_class = match($status) {
                'Pending'  => 'badge-pending',
                'Partial'  => 'badge-partial',
                'Approved' => 'badge-approved',
                default    => 'badge-rejected',
            };
        ?>
        <div class="leave-card" data-leave-id="<?= $leave['leave_id'] ?>">
            <div class="leave-card-top">
                <div class="leave-card-left">
                    <div class="emp-avatar"><?= htmlspecialchars($leave['initials']) ?></div>
                    <div class="emp-info">
                        <div class="emp-name">
                            <?= htmlspecialchars($leave['employee_name']) ?>
                            <span class="badge <?= $badge_class ?>"><?= $status ?></span>
                        </div>
                        <div class="emp-position"><?= htmlspecialchars($leave['position_name']) ?></div>
                        <div class="leave-meta">
                            <span><strong>Type:</strong> <?= htmlspecialchars($leave['leave_name']) ?></span>
                            <span class="meta-dot">·</span>
                            <span><strong>Filed:</strong> <?= date('M d, Y', strtotime($leave['filed_at'])) ?></span>
                        </div>
                        <div class="leave-reason"><strong>Reason:</strong> <?= htmlspecialchars($leave['reason']) ?></div>
                    </div>
                </div>
                <div class="leave-card-actions">
                    <?php if ($leave['pending_count'] > 0): ?>
                    <button class="btn-approve-all"
                            data-leave-id="<?= $leave['leave_id'] ?>"
                            onclick="bulkAction(<?= $leave['leave_id'] ?>, 'approve')">
                        <i data-lucide="check-circle"></i> Approve All
                    </button>
                    <button class="btn-reject-all"
                            data-leave-id="<?= $leave['leave_id'] ?>"
                            onclick="bulkAction(<?= $leave['leave_id'] ?>, 'reject')">
                        <i data-lucide="x-circle"></i> Reject All
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Date summary pills -->
            <div class="date-summary-pills">
                <span class="pill pill-pending"><?= $leave['pending_count'] ?> Pending</span>
                <span class="pill pill-approved"><?= $leave['approved_count'] ?> Approved</span>
                <span class="pill pill-rejected"><?= $leave['rejected_count'] ?> Rejected</span>
            </div>

            <!-- Dates table -->
            <div class="dates-table-wrap">
                <table class="dates-table">
                    <thead>
                        <tr>
                            <th>DATE</th>
                            <th>STATUS</th>
                            <th class="col-action">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dates as $d):
                        $ds = $d['status'];
                        $ds_class = match($ds) {
                            'APPROVED' => 'badge-approved',
                            'REJECTED' => 'badge-rejected',
                            default    => 'badge-pending',
                        };
                    ?>
                    <tr class="date-row" data-date-id="<?= $d['date_id'] ?>">
                        <td class="date-cell"><?= date('M d, Y', strtotime($d['leave_date'])) ?></td>
                        <td>
                            <span class="badge <?= $ds_class ?>"><?= ucfirst(strtolower($ds)) ?></span>
                        </td>
                        <td class="action-cell">
                        <?php if ($ds === 'PENDING'): ?>
                            <button class="btn-sm btn-approve"
                                    onclick="dateAction(<?= $d['date_id'] ?>, <?= $leave['leave_id'] ?>, 'approve')">
                                <i data-lucide="check"></i> Approve
                            </button>
                            <button class="btn-sm btn-reject"
                                    onclick="dateAction(<?= $d['date_id'] ?>, <?= $leave['leave_id'] ?>, 'reject')">
                                <i data-lucide="x"></i> Reject
                            </button>
                        <?php else: ?>
                            <span class="actioned-label <?= strtolower($ds) === 'approved' ? 'actioned-approve' : 'actioned-reject' ?>">
                                <?= $ds === 'APPROVED' ? '✓ Approved' : '✗ Rejected' ?>
                            </span>
                        <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- ── Recent Actioned Leaves ──────────────────────────────────────────── -->
    <section class="section">
        <div class="section-header">
            <h2 class="section-title">Recent Actioned Leaves</h2>
            <a href="../leave-calendar/index.php" class="btn-ghost">
                <i data-lucide="calendar"></i> View Calendar
            </a>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>EMPLOYEE</th>
                        <th>LEAVE TYPE</th>
                        <th>DATE(S)</th>
                        <th>TOTAL DAYS</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recent_actioned)): ?>
                <tr><td colspan="5" class="empty-row">No recent activity.</td></tr>
                <?php else: ?>
                <?php foreach ($recent_actioned as $r):
                    $rdate = ($r['start_date'] === $r['end_date'])
                        ? date('M d, Y', strtotime($r['start_date']))
                        : date('M d', strtotime($r['start_date'])) . ' – ' . date('M d, Y', strtotime($r['end_date']));
                    $rs_class = match(strtoupper($r['status'])) {
                        'APPROVED' => 'badge-approved',
                        'REJECTED' => 'badge-rejected',
                        default    => 'badge-partial',
                    };
                    $rs_label = ucfirst(strtolower($r['status']));
                ?>
                <tr>
                    <td class="td-name"><?= htmlspecialchars($r['employee_name']) ?></td>
                    <td class="td-muted"><?= htmlspecialchars($r['leave_name']) ?></td>
                    <td class="td-muted"><?= $rdate ?></td>
                    <td class="td-center"><?= $r['total_days'] ?></td>
                    <td><span class="badge <?= $rs_class ?>"><?= $rs_label ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<!-- ── Modals ────────────────────────────────────────────────────────────────── -->
<?php include 'modals/file-leave-modal.php'; ?>
<?php include 'modals/confirm-action-modal.php'; ?>
<?php include 'modals/reject-reason-modal.php'; ?>

<!-- Toast -->
<div class="toast-container" id="toastContainer"></div>

<script src="../../../assets/js/principal-leave-approval.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>