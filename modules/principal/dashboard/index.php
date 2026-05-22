<?php
/**
 * modules/principal/dashboard/index.php
 * Principal Portal — Workflow Dashboard
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requirePrincipal();

$today = date('Y-m-d');
$hour  = (int)date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');

// ── Summary Card Counts ────────────────────────────────────────────────────────

$pendingPayrollCount = (int)$pdo->query("
    SELECT COUNT(*) FROM payroll_periods WHERE status = 'PROCESSING'
")->fetchColumn();

$pendingLeaveCount = (int)$pdo->query("
    SELECT COUNT(DISTINCT lr.leave_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lrd.status = 'PENDING'
")->fetchColumn();

$pendingLoanCount = (int)$pdo->query("
    SELECT COUNT(*) FROM employee_loans WHERE status = 'PENDING'
")->fetchColumn();

$pendingScCount = (int)$pdo->query("
    SELECT COUNT(*) FROM service_credits WHERE status = 'PENDING'
")->fetchColumn();

$totalPending = $pendingPayrollCount + $pendingLeaveCount + $pendingLoanCount + $pendingScCount;

// ── Latest Payroll Awaiting Approval ──────────────────────────────────────────

$latestPayroll = $pdo->query("
    SELECT pp.*,
           COUNT(pr.payroll_id)     AS emp_count,
           SUM(pr.gross_pay)        AS total_gross,
           SUM(pr.total_deductions) AS total_ded,
           SUM(pr.net_pay)          AS total_net
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pp.period_id = pr.period_id
    WHERE pp.status = 'PROCESSING'
    GROUP BY pp.period_id
    ORDER BY pp.pay_period_start DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// If no pending payroll, fall back to the most recently released/approved period
$lastApproved = null;
if (!$latestPayroll) {
    $lastApproved = $pdo->query("
        SELECT pp.*,
               COUNT(pr.payroll_id) AS emp_count,
               SUM(pr.net_pay)      AS total_net
        FROM payroll_periods pp
        LEFT JOIN payroll_records pr ON pp.period_id = pr.period_id
        WHERE pp.status IN ('APPROVED','RELEASED')
        GROUP BY pp.period_id
        ORDER BY pp.pay_period_start DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
}

// ── Attendance Overview ────────────────────────────────────────────────────────

$totalActive = (int)$pdo->query("
    SELECT COUNT(*) FROM employees WHERE employee_status = 'ACTIVE'
")->fetchColumn();

$attStmt = $pdo->prepare("
    SELECT
        SUM(attendance_status = 'PRESENT')  AS present_cnt,
        SUM(attendance_status = 'LATE')     AS late_cnt,
        SUM(attendance_status = 'HALF_DAY') AS halfday_cnt
    FROM attendance_records
    WHERE attendance_date = :today
");
$attStmt->execute([':today' => $today]);
$attRow = $attStmt->fetch(PDO::FETCH_ASSOC);

$presentCount = (int)($attRow['present_cnt']  ?? 0);
$lateCount    = (int)($attRow['late_cnt']     ?? 0);
$halfDayCount = (int)($attRow['halfday_cnt']  ?? 0);

$absentStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM employees e
    WHERE e.employee_status = 'ACTIVE'
    AND NOT EXISTS (
        SELECT 1 FROM attendance_records ar
        WHERE ar.employee_id = e.employee_id
        AND ar.attendance_date = :today
    )
");
$absentStmt->execute([':today' => $today]);
$absentCount = (int)$absentStmt->fetchColumn();

$onLeaveStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT lr.employee_id)
    FROM leave_request_dates lrd
    JOIN leave_requests lr ON lrd.leave_id = lr.leave_id
    WHERE lrd.leave_date = :today AND lrd.status = 'APPROVED'
");
$onLeaveStmt->execute([':today' => $today]);
$onLeaveCount = (int)$onLeaveStmt->fetchColumn();

$attendedToday  = $presentCount + $lateCount + $halfDayCount;
$attendanceRate = $totalActive > 0 ? round(($attendedToday / $totalActive) * 100) : 0;

// ── Pending Approval Queue ─────────────────────────────────────────────────────

$queueLeaves = $pdo->query("
    SELECT
        lr.leave_id AS item_id,
        CONCAT(e.first_name,' ',e.last_name) AS employee_name,
        CONCAT(LEFT(e.first_name,1), LEFT(e.last_name,1)) AS initials,
        p.position_name,
        lt.leave_name,
        lr.created_at AS submitted_at,
        COUNT(lrd.date_id) AS pending_count
    FROM leave_requests lr
    JOIN employees e   ON lr.employee_id   = e.employee_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    LEFT JOIN positions p ON e.position_id = p.position_id
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id AND lrd.status = 'PENDING'
    GROUP BY lr.leave_id
    ORDER BY lr.created_at ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($queueLeaves as &$row) {
    $cnt = (int)$row['pending_count'];
    $row['item_type']   = 'leave';
    $row['description'] = $row['leave_name'] . ' (' . $cnt . ' date' . ($cnt > 1 ? 's' : '') . ')';
}
unset($row);

$queueLoans = $pdo->query("
    SELECT
        el.loan_id AS item_id,
        CONCAT(e.first_name,' ',e.last_name) AS employee_name,
        CONCAT(LEFT(e.first_name,1), LEFT(e.last_name,1)) AS initials,
        p.position_name,
        lt.loan_name,
        el.total_amount,
        el.created_at AS submitted_at
    FROM employee_loans el
    JOIN employees e   ON el.employee_id  = e.employee_id
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    LEFT JOIN positions p ON e.position_id = p.position_id
    WHERE el.status = 'PENDING'
    ORDER BY el.created_at ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($queueLoans as &$row) {
    $row['item_type']   = 'loan';
    $row['description'] = $row['loan_name'] . ' — ₱' . number_format((float)$row['total_amount'], 2);
}
unset($row);

$queueSCs = $pdo->query("
    SELECT
        sc.service_credit_id AS item_id,
        CONCAT(e.first_name,' ',e.last_name) AS employee_name,
        CONCAT(LEFT(e.first_name,1), LEFT(e.last_name,1)) AS initials,
        p.position_name,
        sc.equivalent_pay,
        sc.created_at AS submitted_at,
        (SELECT COUNT(*) FROM service_credit_dates scd
         WHERE scd.service_credit_id = sc.service_credit_id) AS date_count
    FROM service_credits sc
    JOIN employees e ON sc.employee_id = e.employee_id
    LEFT JOIN positions p ON e.position_id = p.position_id
    WHERE sc.status = 'PENDING'
    ORDER BY sc.created_at ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($queueSCs as &$row) {
    $cnt = (int)$row['date_count'];
    $row['item_type']   = 'service_credit';
    $row['description'] = $cnt . ' work date' . ($cnt > 1 ? 's' : '') . ' — ₱' . number_format((float)$row['equivalent_pay'], 2);
}
unset($row);

// Merge and sort oldest-first (most waiting = most urgent)
$pendingQueue = array_merge($queueLeaves, $queueLoans, $queueSCs);
usort($pendingQueue, fn($a, $b) => strtotime($a['submitted_at']) - strtotime($b['submitted_at']));
$pendingQueue = array_slice($pendingQueue, 0, 10);

// ── Page Bootstrap ────────────────────────────────────────────────────────────

$pageTitle = 'Dashboard — Principal Portal';
$extraCSS  = [
    BASE_URL . 'assets/css/principal.css',
    BASE_URL . 'assets/css/principal-dashboard.css',
];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/principal-sidebar.php'; ?>

<div class="main">

  <!-- ── Top Bar ─────────────────────────────────────────────────────────────── -->
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-house" style="color:#0f766e;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Principal Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <?php if ($totalPending > 0): ?>
      <div class="pdb-notif-badge" title="<?= $totalPending ?> item(s) awaiting action">
        <i class="fa fa-bell"></i>
        <span><?= $totalPending ?></span>
      </div>
      <?php else: ?>
      <button style="background:none;border:none;cursor:pointer;">
        <i class="fa fa-bell" style="font-size:16px;color:#94a3b8;"></i>
      </button>
      <?php endif; ?>
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;">
          <?= htmlspecialchars($_SESSION['user']['role_name'] ?? 'Principal') ?>
        </div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(
            substr($_SESSION['user']['first_name'] ?? 'P', 0, 1) .
            substr($_SESSION['user']['last_name']  ?? 'R', 0, 1)
        ) ?>
      </div>
    </div>
  </div><!-- .header -->

  <div class="main-content">
  <div class="principal-page">

    <!-- ── Greeting ─────────────────────────────────────────────────────────── -->
    <div class="pdb-greeting">
      <div>
        <h1><?= $greeting ?>, <?= htmlspecialchars($_SESSION['user']['first_name'] ?? 'Principal') ?></h1>
        <p>Workflow overview for <?= date('l, F j, Y') ?></p>
      </div>
      <?php if ($totalPending > 0): ?>
      <div class="pdb-status-pill pdb-status-pill--warn">
        <i class="fa fa-circle-exclamation"></i>
        <?= $totalPending ?> item<?= $totalPending > 1 ? 's' : '' ?> awaiting your action
      </div>
      <?php else: ?>
      <div class="pdb-status-pill pdb-status-pill--ok">
        <i class="fa fa-circle-check"></i>
        All caught up — nothing pending
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Summary Cards ─────────────────────────────────────────────────────── -->
    <div class="pdb-cards-row">

      <a class="pdb-card pdb-card--amber <?= $pendingPayrollCount > 0 ? 'pdb-card--lit' : '' ?>"
         href="<?= BASE_URL ?>modules/principal/payroll-approval/index.php">
        <div class="pdb-card-icon"><i class="fa fa-file-invoice-dollar"></i></div>
        <div class="pdb-card-body">
          <div class="pdb-card-value"><?= $pendingPayrollCount ?></div>
          <div class="pdb-card-label">Payroll Approvals</div>
          <div class="pdb-card-sub">
            <?= $pendingPayrollCount > 0 ? 'Awaiting your review' : 'No pending periods' ?>
          </div>
        </div>
        <?php if ($pendingPayrollCount > 0): ?>
        <div class="pdb-card-caret"><i class="fa fa-chevron-right"></i></div>
        <?php endif; ?>
      </a>

      <a class="pdb-card pdb-card--blue <?= $pendingLeaveCount > 0 ? 'pdb-card--lit' : '' ?>"
         href="<?= BASE_URL ?>modules/principal/leave-approval/index.php">
        <div class="pdb-card-icon"><i class="fa fa-calendar-days"></i></div>
        <div class="pdb-card-body">
          <div class="pdb-card-value"><?= $pendingLeaveCount ?></div>
          <div class="pdb-card-label">Leave Requests</div>
          <div class="pdb-card-sub">
            <?= $pendingLeaveCount > 0 ? 'Dates pending approval' : 'No pending requests' ?>
          </div>
        </div>
        <?php if ($pendingLeaveCount > 0): ?>
        <div class="pdb-card-caret"><i class="fa fa-chevron-right"></i></div>
        <?php endif; ?>
      </a>

      <a class="pdb-card pdb-card--purple <?= $pendingLoanCount > 0 ? 'pdb-card--lit' : '' ?>"
         href="<?= BASE_URL ?>modules/principal/loan-approval/index.php">
        <div class="pdb-card-icon"><i class="fa fa-hand-holding-dollar"></i></div>
        <div class="pdb-card-body">
          <div class="pdb-card-value"><?= $pendingLoanCount ?></div>
          <div class="pdb-card-label">Loan Approvals</div>
          <div class="pdb-card-sub">
            <?= $pendingLoanCount > 0 ? 'Applications pending' : 'No pending loans' ?>
          </div>
        </div>
        <?php if ($pendingLoanCount > 0): ?>
        <div class="pdb-card-caret"><i class="fa fa-chevron-right"></i></div>
        <?php endif; ?>
      </a>

      <a class="pdb-card pdb-card--teal <?= $pendingScCount > 0 ? 'pdb-card--lit' : '' ?>"
         href="<?= BASE_URL ?>modules/principal/service-credit-approval/index.php">
        <div class="pdb-card-icon"><i class="fa fa-medal"></i></div>
        <div class="pdb-card-body">
          <div class="pdb-card-value"><?= $pendingScCount ?></div>
          <div class="pdb-card-label">Service Credits</div>
          <div class="pdb-card-sub">
            <?= $pendingScCount > 0 ? 'Claims awaiting review' : 'No pending claims' ?>
          </div>
        </div>
        <?php if ($pendingScCount > 0): ?>
        <div class="pdb-card-caret"><i class="fa fa-chevron-right"></i></div>
        <?php endif; ?>
      </a>

    </div><!-- .pdb-cards-row -->

    <!-- ── Middle Row: Payroll + Attendance ──────────────────────────────────── -->
    <div class="pdb-middle-row">

      <!-- Latest Payroll Panel -->
      <div class="pdb-panel">
        <div class="pdb-panel-header">
          <div class="pdb-panel-title">
            <i class="fa fa-file-invoice-dollar" style="color:#d97706;"></i>
            Latest Payroll Period
          </div>
          <?php if ($latestPayroll): ?>
          <span class="pr-badge pr-badge--awaiting">Awaiting Approval</span>
          <?php elseif ($lastApproved): ?>
          <span class="pr-badge pr-badge--<?= strtolower($lastApproved['status']) ?>">
            <i class="fa fa-lock" style="font-size:10px;"></i>
            <?= ucfirst(strtolower($lastApproved['status'])) ?>
          </span>
          <?php endif; ?>
        </div>

        <?php if ($latestPayroll):
            $plabel = date('M j', strtotime($latestPayroll['pay_period_start']))
                    . ' – '
                    . date('M j, Y', strtotime($latestPayroll['pay_period_end']));
            $waitDays = (int)((time() - strtotime($latestPayroll['created_at'])) / 86400);
        ?>

        <div class="pdb-period-tag">
          <i class="fa fa-calendar-days"></i>
          <?= htmlspecialchars($plabel) ?>
          <?php if ($waitDays > 0): ?>
          <span class="pdb-wait-chip <?= $waitDays >= 3 ? 'pdb-wait-chip--urgent' : '' ?>">
            <?= $waitDays ?>d waiting
          </span>
          <?php else: ?>
          <span class="pdb-wait-chip">Today</span>
          <?php endif; ?>
        </div>

        <div class="pdb-payroll-stats">
          <div class="pdb-pstat">
            <div class="pdb-pstat-label">Employees</div>
            <div class="pdb-pstat-value"><?= (int)$latestPayroll['emp_count'] ?></div>
          </div>
          <div class="pdb-pstat">
            <div class="pdb-pstat-label">Total Gross</div>
            <div class="pdb-pstat-value">₱<?= number_format((float)$latestPayroll['total_gross'], 2) ?></div>
          </div>
          <div class="pdb-pstat">
            <div class="pdb-pstat-label">Total Deductions</div>
            <div class="pdb-pstat-value pdb-val-red">₱<?= number_format((float)$latestPayroll['total_ded'], 2) ?></div>
          </div>
          <div class="pdb-pstat">
            <div class="pdb-pstat-label">Net Payable</div>
            <div class="pdb-pstat-value pdb-val-teal">₱<?= number_format((float)$latestPayroll['total_net'], 2) ?></div>
          </div>
        </div>

        <div class="pdb-payroll-actions">
          <a href="<?= BASE_URL ?>modules/principal/payroll-approval/index.php"
             class="pdb-btn-primary">
            <i class="fa fa-circle-check"></i>
            Review &amp; Approve
          </a>
          <a href="<?= BASE_URL ?>modules/payroll/batch-detail.php?period_id=<?= $latestPayroll['period_id'] ?>"
             class="pdb-btn-secondary">
            <i class="fa fa-eye"></i>
            View Details
          </a>
        </div>

        <?php elseif ($lastApproved):
            $llabel = date('M j', strtotime($lastApproved['pay_period_start']))
                    . ' – '
                    . date('M j, Y', strtotime($lastApproved['pay_period_end']));
        ?>

        <div class="pdb-empty-state">
          <i class="fa fa-circle-check" style="color:#10b981;font-size:32px;"></i>
          <strong>No payroll pending approval</strong>
          <p>Last period: <strong><?= htmlspecialchars($llabel) ?></strong><br>
             Net ₱<?= number_format((float)$lastApproved['total_net'], 2) ?> — <?= ucfirst(strtolower($lastApproved['status'])) ?></p>
        </div>
        <div style="text-align:center;margin-top:4px;">
          <a href="<?= BASE_URL ?>modules/principal/payroll-approval/index.php" class="pdb-btn-secondary">
            <i class="fa fa-clock-rotate-left"></i> View Payroll History
          </a>
        </div>

        <?php else: ?>
        <div class="pdb-empty-state">
          <i class="fa fa-file-invoice-dollar" style="color:#94a3b8;font-size:32px;"></i>
          <strong>No payroll periods yet</strong>
          <p>Payroll will appear here once the Admin generates a period.</p>
        </div>
        <?php endif; ?>

      </div><!-- payroll panel -->

      <!-- Attendance Overview Panel -->
      <div class="pdb-panel">
        <div class="pdb-panel-header">
          <div class="pdb-panel-title">
            <i class="fa fa-user-check" style="color:#0f766e;"></i>
            Attendance Today
          </div>
          <span style="font-size:12px;color:#94a3b8;"><?= date('M j, Y') ?></span>
        </div>

        <!-- Rate Ring -->
        <div class="pdb-att-ring-row">
          <?php
            $r           = 26;
            $circumf     = 2 * M_PI * $r;
            $dashOffset  = $circumf - ($attendanceRate / 100) * $circumf;
          ?>
          <div class="pdb-ring-wrap">
            <svg viewBox="0 0 64 64" class="pdb-ring-svg">
              <circle class="pdb-ring-track" cx="32" cy="32" r="<?= $r ?>"/>
              <circle class="pdb-ring-fill"
                      cx="32" cy="32" r="<?= $r ?>"
                      stroke-dasharray="<?= round($circumf, 2) ?>"
                      stroke-dashoffset="<?= round($dashOffset, 2) ?>"
                      transform="rotate(-90 32 32)"/>
              <text x="32" y="37" text-anchor="middle" class="pdb-ring-label"><?= $attendanceRate ?>%</text>
            </svg>
          </div>
          <div class="pdb-ring-meta">
            <strong>Today's Attendance Rate</strong>
            <span><?= $attendedToday ?> of <?= $totalActive ?> employees present</span>
            <?php if ($onLeaveCount > 0): ?>
            <div class="pdb-on-leave-note">
              <i class="fa fa-calendar-xmark"></i>
              <?= $onLeaveCount ?> on approved leave
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Status Grid -->
        <div class="pdb-att-grid">
          <div class="pdb-att-cell">
            <div class="pdb-att-dot" style="background:#10b981;"></div>
            <div class="pdb-att-num"><?= $presentCount ?></div>
            <div class="pdb-att-lbl">Present</div>
          </div>
          <div class="pdb-att-cell">
            <div class="pdb-att-dot" style="background:#f59e0b;"></div>
            <div class="pdb-att-num"><?= $lateCount ?></div>
            <div class="pdb-att-lbl">Late</div>
          </div>
          <div class="pdb-att-cell">
            <div class="pdb-att-dot" style="background:#f97316;"></div>
            <div class="pdb-att-num"><?= $halfDayCount ?></div>
            <div class="pdb-att-lbl">Half Day</div>
          </div>
          <div class="pdb-att-cell">
            <div class="pdb-att-dot" style="background:#ef4444;"></div>
            <div class="pdb-att-num"><?= $absentCount ?></div>
            <div class="pdb-att-lbl">Absent</div>
          </div>
        </div>

        <div style="text-align:center;margin-top:16px;">
          <a href="<?= BASE_URL ?>modules/principal/attendance/index.php" class="pdb-btn-secondary" style="display:inline-flex;">
            <i class="fa fa-table-list"></i>
            View Full Attendance
          </a>
        </div>

      </div><!-- attendance panel -->

    </div><!-- .pdb-middle-row -->

    <!-- ── Pending Approval Queue ─────────────────────────────────────────────── -->
    <div class="pdb-panel pdb-queue-panel">
      <div class="pdb-panel-header">
        <div class="pdb-panel-title">
          <i class="fa fa-hourglass-half" style="color:#6366f1;"></i>
          Pending Approval Queue
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <?php if (!empty($pendingQueue)): ?>
          <span class="pdb-queue-badge">
            <?= count($pendingQueue) ?> item<?= count($pendingQueue) > 1 ? 's' : '' ?> pending
          </span>
          <?php endif; ?>
          <div style="display:flex;gap:8px;">
            <a href="<?= BASE_URL ?>modules/principal/leave-approval/index.php"   class="pdb-chip pdb-chip--blue">Leave</a>
            <a href="<?= BASE_URL ?>modules/principal/loan-approval/index.php"    class="pdb-chip pdb-chip--purple">Loans</a>
            <a href="<?= BASE_URL ?>modules/principal/service-credit-approval/index.php" class="pdb-chip pdb-chip--teal">Credits</a>
          </div>
        </div>
      </div>

      <?php if (empty($pendingQueue)): ?>
      <div class="pdb-empty-state" style="padding:44px 24px;">
        <i class="fa fa-circle-check" style="color:#10b981;font-size:36px;"></i>
        <strong>All caught up!</strong>
        <p>No pending leave, loan, or service credit approvals at this time.</p>
      </div>

      <?php else: ?>
      <div class="pdb-table-wrap">
        <table class="pdb-queue-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Type</th>
              <th>Details</th>
              <th>Submitted</th>
              <th>Waiting</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($pendingQueue as $item):
              $typeLabel = match($item['item_type']) {
                  'leave'          => 'Leave',
                  'loan'           => 'Loan',
                  'service_credit' => 'Service Credit',
                  default          => '—',
              };
              $typeClass = match($item['item_type']) {
                  'leave'          => 'pdb-type--blue',
                  'loan'           => 'pdb-type--purple',
                  'service_credit' => 'pdb-type--teal',
                  default          => '',
              };
              $typeIcon = match($item['item_type']) {
                  'leave'          => 'fa-calendar-days',
                  'loan'           => 'fa-hand-holding-dollar',
                  'service_credit' => 'fa-medal',
                  default          => 'fa-circle',
              };
              $actionUrl = match($item['item_type']) {
                  'leave'          => BASE_URL . 'modules/principal/leave-approval/index.php',
                  'loan'           => BASE_URL . 'modules/principal/loan-approval/index.php',
                  'service_credit' => BASE_URL . 'modules/principal/service-credit-approval/index.php',
                  default          => '#',
              };
              $daysWaiting = (int)((time() - strtotime($item['submitted_at'])) / 86400);
          ?>
          <tr>
            <td>
              <div class="pdb-emp-cell">
                <div class="pdb-emp-avatar"><?= htmlspecialchars($item['initials']) ?></div>
                <div>
                  <div class="pdb-emp-name"><?= htmlspecialchars($item['employee_name']) ?></div>
                  <div class="pdb-emp-pos"><?= htmlspecialchars($item['position_name'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td>
              <span class="pdb-type-badge <?= $typeClass ?>">
                <i class="fa <?= $typeIcon ?>"></i>
                <?= $typeLabel ?>
              </span>
            </td>
            <td class="pdb-desc-cell"><?= htmlspecialchars($item['description']) ?></td>
            <td style="color:var(--text-secondary);font-size:13px;white-space:nowrap;">
              <?= date('M j, Y', strtotime($item['submitted_at'])) ?>
            </td>
            <td>
              <?php if ($daysWaiting === 0): ?>
              <span class="pdb-wait-chip">Today</span>
              <?php else: ?>
              <span class="pdb-wait-chip <?= $daysWaiting >= 3 ? 'pdb-wait-chip--urgent' : '' ?>">
                <?= $daysWaiting ?>d
              </span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <a href="<?= $actionUrl ?>" class="pdb-review-btn">
                Review <i class="fa fa-arrow-right"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

    </div><!-- .pdb-queue-panel -->

  </div><!-- .principal-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
