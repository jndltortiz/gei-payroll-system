<?php
/**
 * modules/analytics/index.php
 * Admin Analytics — system-wide operational insights from current data.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$thisMonth = date('Y-m');
$today     = date('Y-m-d');

// ── Workforce ─────────────────────────────────────────────────────────────────
$totalActive = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employee_status='ACTIVE'")->fetchColumn();
$totalDepts  = (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();

$empTypeSplit = $pdo->query("
    SELECT
        COALESCE(SUM(employment_type='FULL_TIME'), 0) AS full_time,
        COALESCE(SUM(employment_type='PART_TIME'), 0) AS part_time
    FROM employees WHERE employee_status='ACTIVE'
")->fetch(PDO::FETCH_ASSOC);

$deptHeadcount = $pdo->query("
    SELECT d.department_name,
           COUNT(e.employee_id) AS headcount
    FROM departments d
    LEFT JOIN employees e ON e.department_id=d.department_id AND e.employee_status='ACTIVE'
    GROUP BY d.department_id, d.department_name
    ORDER BY headcount DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Payroll trend (last 6 finalized periods) ──────────────────────────────────
$trendRows = $pdo->query("
    SELECT pp.period_id,
           pp.pay_period_start,
           pp.pay_period_end,
           pp.status,
           COUNT(pr.payroll_id)                  AS emp_count,
           COALESCE(SUM(pr.gross_pay), 0)        AS total_gross,
           COALESCE(SUM(pr.total_deductions), 0) AS total_deductions,
           COALESCE(SUM(pr.net_pay), 0)          AS total_net
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pp.period_id=pr.period_id
    WHERE pp.status IN ('APPROVED','RELEASED')
    GROUP BY pp.period_id
    ORDER BY pp.pay_period_start DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);
$trendRows = array_reverse($trendRows);

$totalReleasedNet = array_sum(array_column($trendRows, 'total_net'));
$periodCount      = count($trendRows);
$avgNetPerPeriod  = $periodCount > 0 ? $totalReleasedNet / $periodCount : 0;

// Payroll period status counts
$ppStatus = $pdo->query("
    SELECT
        COALESCE(SUM(status='OPEN'),       0) AS open_cnt,
        COALESCE(SUM(status='PROCESSING'), 0) AS processing_cnt,
        COALESCE(SUM(status='APPROVED'),   0) AS approved_cnt,
        COALESCE(SUM(status='RELEASED'),   0) AS released_cnt
    FROM payroll_periods
")->fetch(PDO::FETCH_ASSOC);

// ── Attendance — this month ───────────────────────────────────────────────────
$attMonth = $pdo->query("
    SELECT
        COALESCE(SUM(attendance_status IN ('PRESENT','LATE','HALF_DAY')), 0) AS attended,
        COALESCE(SUM(attendance_status='ABSENT'),   0) AS absent_cnt,
        COALESCE(SUM(attendance_status='LATE'),     0) AS late_cnt,
        COALESCE(SUM(attendance_status='HALF_DAY'), 0) AS halfday_cnt,
        COALESCE(SUM(attendance_status='LEAVE'),    0) AS leave_cnt,
        COUNT(DISTINCT attendance_date)                AS days_logged
    FROM attendance_records
    WHERE DATE_FORMAT(attendance_date,'%Y-%m') = '$thisMonth'
")->fetch(PDO::FETCH_ASSOC);

$attRate = ($attMonth['days_logged'] > 0 && $totalActive > 0)
    ? min(100, round(($attMonth['attended'] / ((int)$attMonth['days_logged'] * $totalActive)) * 100))
    : 0;

// Weekly attendance trend (last 4 weeks)
$attWeekly = $pdo->query("
    SELECT
        YEARWEEK(attendance_date,1) AS yw,
        MIN(attendance_date)         AS week_start,
        COALESCE(SUM(attendance_status IN ('PRESENT','LATE','HALF_DAY')), 0) AS attended,
        COALESCE(SUM(attendance_status='LATE'),   0) AS late_cnt,
        COALESCE(SUM(attendance_status='ABSENT'), 0) AS absent_cnt
    FROM attendance_records
    WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK)
    GROUP BY yw ORDER BY yw ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Department attendance rate — this month
$deptAtt = $pdo->query("
    SELECT d.department_name,
           COUNT(DISTINCT e.employee_id)                                      AS emp_count,
           COALESCE(SUM(ar.attendance_status IN ('PRESENT','LATE','HALF_DAY')), 0) AS attended,
           COUNT(ar.attendance_id)                                             AS total_records
    FROM departments d
    LEFT JOIN employees e ON e.department_id=d.department_id AND e.employee_status='ACTIVE'
    LEFT JOIN attendance_records ar ON ar.employee_id=e.employee_id
          AND DATE_FORMAT(ar.attendance_date,'%Y-%m') = '$thisMonth'
    GROUP BY d.department_id, d.department_name
    HAVING COUNT(DISTINCT e.employee_id) > 0
    ORDER BY (COALESCE(SUM(ar.attendance_status IN ('PRESENT','LATE','HALF_DAY')), 0) / GREATEST(COUNT(ar.attendance_id), 1)) DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Leave analytics ───────────────────────────────────────────────────────────
$leaveMonthly = $pdo->query("
    SELECT DATE_FORMAT(lrd.leave_date,'%Y-%m') AS ym,
           DATE_FORMAT(lrd.leave_date,'%b %Y') AS label,
           COUNT(*)                             AS date_count
    FROM leave_request_dates lrd
    WHERE lrd.leave_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym, label ORDER BY ym ASC
")->fetchAll(PDO::FETCH_ASSOC);

$leaveTypes = $pdo->query("
    SELECT lt.leave_name,
           COUNT(DISTINCT lr.leave_id) AS request_count
    FROM leave_types lt
    LEFT JOIN leave_requests lr ON lr.leave_type_id=lt.leave_type_id
    GROUP BY lt.leave_type_id, lt.leave_name
    ORDER BY request_count DESC LIMIT 7
")->fetchAll(PDO::FETCH_ASSOC);

// Leave credit utilization (active school year — migration-safe)
$leaveCredits   = [];
$lcSchoolYear   = '';
try {
    $syRow = $pdo->query("SELECT school_year_id, year_name FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
    if ($syRow) {
        $lcSchoolYear = $syRow['year_name'];
        $lcStmt = $pdo->prepare("
            SELECT lt.leave_name,
                   COUNT(DISTINCT elc.employee_id) AS employee_count,
                   COALESCE(SUM(elc.allocated_days), 0) AS total_allocated,
                   COALESCE(SUM(elc.used_days), 0)      AS total_used
            FROM employee_leave_credits elc
            JOIN leave_types lt ON elc.leave_type_id=lt.leave_type_id
            WHERE elc.school_year_id = ?
            GROUP BY lt.leave_type_id, lt.leave_name
            ORDER BY total_allocated DESC
        ");
        $lcStmt->execute([$syRow['school_year_id']]);
        $leaveCredits = $lcStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { /* school_years table not yet created */ }

// ── Pending approval queue ────────────────────────────────────────────────────
$pendingPayroll = (int)$pdo->query("SELECT COUNT(*) FROM payroll_periods WHERE status='PROCESSING'")->fetchColumn();
$pendingLeave   = (int)$pdo->query("
    SELECT COUNT(DISTINCT lr.leave_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id=lrd.leave_id AND lrd.status='PENDING'
")->fetchColumn();
$pendingLoans   = (int)$pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='PENDING'")->fetchColumn();
$pendingSC      = (int)$pdo->query("SELECT COUNT(*) FROM service_credits WHERE status='PENDING'")->fetchColumn();
$totalPending   = $pendingPayroll + $pendingLeave + $pendingLoans + $pendingSC;

$avgWaitLeave = (int)round((float)$pdo->query("
    SELECT COALESCE(AVG(DATEDIFF(CURDATE(), lr.created_at)), 0)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id=lrd.leave_id AND lrd.status='PENDING'
")->fetchColumn());
$avgWaitLoans = (int)round((float)$pdo->query(
    "SELECT COALESCE(AVG(DATEDIFF(CURDATE(), created_at)), 0) FROM employee_loans WHERE status='PENDING'"
)->fetchColumn());
$avgWaitSC = (int)round((float)$pdo->query(
    "SELECT COALESCE(AVG(DATEDIFF(CURDATE(), created_at)), 0) FROM service_credits WHERE status='PENDING'"
)->fetchColumn());

// ── Loans ─────────────────────────────────────────────────────────────────────
$loanSummary = $pdo->query("
    SELECT lt.loan_name,
           COUNT(el.loan_id)                   AS active_count,
           COALESCE(SUM(el.balance_amount), 0) AS total_balance
    FROM employee_loans el
    JOIN loan_types lt ON el.loan_type_id=lt.loan_type_id
    WHERE el.status='ACTIVE'
    GROUP BY lt.loan_type_id, lt.loan_name
    ORDER BY total_balance DESC
")->fetchAll(PDO::FETCH_ASSOC);
$totalLoanBalance = array_sum(array_column($loanSummary, 'total_balance'));

// ── Service credits ───────────────────────────────────────────────────────────
$scRow = $pdo->query("
    SELECT
        COALESCE(SUM(status='PENDING'),  0) AS pending_cnt,
        COALESCE(SUM(status='APPROVED'), 0) AS approved_cnt,
        COALESCE(SUM(status='APPLIED'),  0) AS applied_cnt,
        COALESCE(SUM(CASE WHEN status='APPROVED' THEN equivalent_pay ELSE 0 END), 0) AS pending_payout
    FROM service_credits
")->fetch(PDO::FETCH_ASSOC);

// ── JSON for Chart.js ─────────────────────────────────────────────────────────
$payrollTrendJson = json_encode(array_values(array_map(fn($r) => [
    'label'      => date('M j', strtotime($r['pay_period_start'])) . '–' . date('j', strtotime($r['pay_period_end'])),
    'net'        => round((float)$r['total_net'],        2),
    'gross'      => round((float)$r['total_gross'],      2),
    'deductions' => round((float)$r['total_deductions'], 2),
], $trendRows)));

$attWeeklyJson = json_encode(array_values(array_map(fn($r) => [
    'label'   => 'Wk ' . date('M j', strtotime($r['week_start'])),
    'attended'=> (int)$r['attended'],
    'late'    => (int)$r['late_cnt'],
    'absent'  => (int)$r['absent_cnt'],
], $attWeekly)));

$deptHeadJson = json_encode(array_values(array_map(fn($r) => [
    'label' => $r['department_name'],
    'count' => (int)$r['headcount'],
], $deptHeadcount)));

$leaveTypeJson = json_encode(array_values(array_map(fn($r) => [
    'label' => $r['leave_name'],
    'count' => (int)$r['request_count'],
], $leaveTypes)));

$leaveMonthlyJson = json_encode(array_values(array_map(fn($r) => [
    'label' => $r['label'],
    'count' => (int)$r['date_count'],
], $leaveMonthly)));

$empTypeJson = json_encode([
    ['label' => 'Full-Time', 'count' => (int)($empTypeSplit['full_time'] ?? 0)],
    ['label' => 'Part-Time', 'count' => (int)($empTypeSplit['part_time'] ?? 0)],
]);

// ── Payroll deduction breakdown (last released period) ────────────────────────
$dedBreakdown  = [];
$dedPeriodLabel = '';
try {
    $lastRelPeriod = $pdo->query("
        SELECT period_id, pay_period_start, pay_period_end
        FROM payroll_periods WHERE status='RELEASED'
        ORDER BY pay_period_start DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if ($lastRelPeriod) {
        $dedPeriodLabel = date('M j', strtotime($lastRelPeriod['pay_period_start']))
                        . '–' . date('j', strtotime($lastRelPeriod['pay_period_end']));
        $dedStmt = $pdo->prepare("
            SELECT dt.deduction_name,
                   COALESCE(SUM(pd.amount), 0) AS total_amount
            FROM payroll_deductions pd
            JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
            JOIN payroll_records pr  ON pd.payroll_id = pr.payroll_id
            WHERE pr.period_id = ?
            GROUP BY dt.deduction_type_id, dt.deduction_name
            ORDER BY total_amount DESC LIMIT 10
        ");
        $dedStmt->execute([$lastRelPeriod['period_id']]);
        $dedBreakdown = $dedStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

// ── Leave approval stats ──────────────────────────────────────────────────────
$leaveApproval = $pdo->query("
    SELECT
        COALESCE(SUM(status='APPROVED'), 0) AS approved,
        COALESCE(SUM(status='REJECTED'), 0) AS rejected,
        COALESCE(SUM(status='PENDING'),  0) AS pending,
        COUNT(*) AS total
    FROM leave_requests
")->fetch(PDO::FETCH_ASSOC);

// ── Top late / absent employees this month ────────────────────────────────────
$topLate = $pdo->query("
    SELECT CONCAT(e.first_name,' ',e.last_name)       AS emp_name,
           d.department_name,
           COALESCE(SUM(ar.attendance_status='LATE'),   0) AS late_cnt,
           COALESCE(SUM(ar.attendance_status='ABSENT'), 0) AS absent_cnt,
           COALESCE(SUM(ar.late_minutes), 0)               AS total_late_min
    FROM attendance_records ar
    JOIN employees e   ON ar.employee_id   = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE DATE_FORMAT(ar.attendance_date,'%Y-%m') = '$thisMonth'
    GROUP BY ar.employee_id, emp_name, d.department_name
    HAVING late_cnt > 0 OR absent_cnt > 0
    ORDER BY late_cnt DESC, absent_cnt DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── Loan repayment progress ───────────────────────────────────────────────────
$loanProgress = $pdo->query("
    SELECT CONCAT(e.first_name,' ',e.last_name) AS emp_name,
           lt.loan_name,
           el.total_amount,
           el.balance_amount
    FROM employee_loans el
    JOIN employees e  ON el.employee_id  = e.employee_id
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    WHERE el.status='ACTIVE' AND el.total_amount > 0
    ORDER BY (el.balance_amount / el.total_amount) DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$dedBreakdownJson = json_encode(array_values(array_map(fn($r) => [
    'label'  => $r['deduction_name'],
    'amount' => round((float)$r['total_amount'], 2),
], $dedBreakdown)));

// ── Chart palette (consistent across all charts) ──────────────────────────────
$PALETTE = ['#0f766e','#2563eb','#7c3aed','#dc2626','#ea580c','#0891b2','#16a34a','#854d0e'];

// ── Page init ─────────────────────────────────────────────────────────────────
$pageTitle = 'Analytics';
$extraCSS  = [BASE_URL . 'assets/css/analytics.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">

<div class="an-page">

  <!-- ── Page header ─────────────────────────────────────────────────────────── -->
  <div class="an-hdr">
    <h1><i class="fa fa-chart-bar"></i> Analytics</h1>
    <p>System-wide operational insights — <?= date('F Y') ?></p>
  </div>

  <!-- ── KPI cards ────────────────────────────────────────────────────────────── -->
  <div class="an-kpi-grid">

    <div class="an-kpi">
      <div class="an-kpi-icon blue"><i class="fa fa-users"></i></div>
      <div>
        <div class="an-kpi-val"><?= number_format($totalActive) ?></div>
        <div class="an-kpi-label">Active Employees</div>
        <div class="an-kpi-sub"><?= $totalDepts ?> department<?= $totalDepts != 1 ? 's' : '' ?></div>
      </div>
    </div>

    <div class="an-kpi">
      <div class="an-kpi-icon green"><i class="fa fa-peso-sign"></i></div>
      <div>
        <div class="an-kpi-val">₱<?= number_format($totalReleasedNet, 0) ?></div>
        <div class="an-kpi-label">Net Pay Released</div>
        <div class="an-kpi-sub">
          <?php if ($periodCount): ?>
            Avg ₱<?= number_format($avgNetPerPeriod, 0) ?> / period (last <?= $periodCount ?>)
          <?php else: ?>
            No finalized periods yet
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="an-kpi">
      <div class="an-kpi-icon teal"><i class="fa fa-calendar-check"></i></div>
      <div>
        <div class="an-kpi-val"><?= $attRate ?>%</div>
        <div class="an-kpi-label">Attendance Rate</div>
        <div class="an-kpi-sub"><?= date('F Y') ?> · <?= number_format((int)$attMonth['days_logged']) ?> day<?= $attMonth['days_logged'] != 1 ? 's' : '' ?> logged</div>
      </div>
    </div>

    <div class="an-kpi <?= $totalPending > 0 ? 'an-kpi--warn' : '' ?>">
      <div class="an-kpi-icon <?= $totalPending > 0 ? 'orange' : 'gray' ?>">
        <i class="fa fa-clock-rotate-left"></i>
      </div>
      <div>
        <div class="an-kpi-val"><?= $totalPending ?></div>
        <div class="an-kpi-label">Pending Approvals</div>
        <div class="an-kpi-sub">Across all queues</div>
      </div>
    </div>

  </div><!-- .an-kpi-grid -->

  <!-- ── Row 1: Payroll Trend + Employment Type ────────────────────────────────── -->
  <div class="an-grid an-grid--wide">

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-chart-line"></i> Payroll Cost Trend</span>
        <span class="an-panel-sub">Last <?= $periodCount ?: '—' ?> finalized period<?= $periodCount != 1 ? 's' : '' ?></span>
      </div>
      <div class="an-panel-body">
        <?php if (empty($trendRows)): ?>
          <div class="an-empty"><i class="fa fa-chart-line"></i><p>No approved or released payroll periods yet</p></div>
        <?php else: ?>
          <!-- Payroll period status pills -->
          <div class="an-status-pills">
            <?php if ((int)$ppStatus['open_cnt'] > 0): ?>
              <span class="an-pill">
                <span class="an-pill-count"><?= $ppStatus['open_cnt'] ?></span> Open
              </span>
            <?php endif; ?>
            <?php if ((int)$ppStatus['processing_cnt'] > 0): ?>
              <span class="an-pill an-pill--warn">
                <span class="an-pill-count"><?= $ppStatus['processing_cnt'] ?></span> Processing
              </span>
            <?php endif; ?>
            <?php if ((int)$ppStatus['approved_cnt'] > 0): ?>
              <span class="an-pill an-pill--blue">
                <span class="an-pill-count"><?= $ppStatus['approved_cnt'] ?></span> Approved
              </span>
            <?php endif; ?>
            <?php if ((int)$ppStatus['released_cnt'] > 0): ?>
              <span class="an-pill an-pill--green">
                <span class="an-pill-count"><?= $ppStatus['released_cnt'] ?></span> Released
              </span>
            <?php endif; ?>
          </div>
          <div style="position:relative;height:170px;"><canvas id="payrollTrendChart"></canvas></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-id-badge"></i> Employment Type</span>
        <span class="an-panel-sub"><?= number_format($totalActive) ?> active</span>
      </div>
      <div class="an-panel-body an-panel-body--row">
        <?php if ($totalActive === 0): ?>
          <div class="an-empty"><i class="fa fa-id-badge"></i><p>No employee data</p></div>
        <?php else: ?>
          <div style="position:relative;width:110px;height:110px;flex-shrink:0;">
            <canvas id="empTypeChart"></canvas>
          </div>
          <div class="an-legend" style="max-height:none;">
            <div class="an-legend-item">
              <div class="an-legend-label"><span class="an-dot" style="background:#0f766e;"></span>Full-Time</div>
              <span class="an-legend-count"><?= number_format((int)$empTypeSplit['full_time']) ?></span>
            </div>
            <div class="an-legend-item">
              <div class="an-legend-label"><span class="an-dot" style="background:#64748b;"></span>Part-Time</div>
              <span class="an-legend-count"><?= number_format((int)$empTypeSplit['part_time']) ?></span>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- row 1 -->

  <!-- ── Row 2: Weekly Attendance + Dept Headcount ─────────────────────────────── -->
  <div class="an-grid">

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-chart-bar"></i> Weekly Attendance Trend</span>
        <span class="an-panel-sub">Last 4 weeks</span>
      </div>
      <div class="an-panel-body">
        <?php if (empty($attWeekly)): ?>
          <div class="an-empty"><i class="fa fa-chart-bar"></i><p>No attendance records in the past 4 weeks</p></div>
        <?php else: ?>
          <div style="position:relative;height:170px;"><canvas id="attWeeklyChart"></canvas></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-building"></i> Headcount by Department</span>
        <span class="an-panel-sub"><?= number_format($totalActive) ?> employees total</span>
      </div>
      <div class="an-panel-body">
        <?php if (empty($deptHeadcount)): ?>
          <div class="an-empty"><i class="fa fa-building"></i><p>No department data</p></div>
        <?php else: ?>
          <div style="position:relative;height:170px;"><canvas id="deptHeadChart"></canvas></div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- row 2 -->

  <!-- ── Row 3: Leave by Month + Leave Type Distribution ───────────────────────── -->
  <div class="an-grid">

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-calendar-xmark"></i> Leave Dates by Month</span>
        <span class="an-panel-sub">Last 6 months</span>
      </div>
      <div class="an-panel-body">
        <?php if (empty($leaveMonthly)): ?>
          <div class="an-empty"><i class="fa fa-calendar-xmark"></i><p>No leave dates filed in the past 6 months</p></div>
        <?php else: ?>
          <div style="position:relative;height:170px;"><canvas id="leaveMonthlyChart"></canvas></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-chart-pie"></i> Leave Type Distribution</span>
        <span class="an-panel-sub">All requests on record</span>
      </div>
      <div class="an-panel-body an-panel-body--row">
        <?php
          $leaveTypesWithData = array_filter($leaveTypes, fn($r) => $r['request_count'] > 0);
        ?>
        <?php if (empty($leaveTypesWithData)): ?>
          <div class="an-empty"><i class="fa fa-chart-pie"></i><p>No leave requests on record</p></div>
        <?php else: ?>
          <div style="position:relative;width:110px;height:110px;flex-shrink:0;">
            <canvas id="leaveTypeChart"></canvas>
          </div>
          <div class="an-legend" style="max-height:none;">
            <?php foreach ($leaveTypes as $i => $lt): if (!$lt['request_count']) continue; ?>
            <div class="an-legend-item">
              <div class="an-legend-label">
                <span class="an-dot" style="background:<?= $PALETTE[$i % count($PALETTE)] ?>;"></span>
                <?= htmlspecialchars($lt['leave_name']) ?>
              </div>
              <span class="an-legend-count"><?= number_format((int)$lt['request_count']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- row 3 -->

  <!-- ── Row 4: Leave Credit Utilization + Dept Attendance Rates ───────────────── -->
  <div class="an-grid">

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-battery-three-quarters"></i> Leave Credit Utilization</span>
        <span class="an-panel-sub"><?= $lcSchoolYear ? htmlspecialchars($lcSchoolYear) : 'Active school year' ?></span>
      </div>
      <div class="an-panel-body">
        <?php if (empty($leaveCredits)): ?>
          <div class="an-empty">
            <i class="fa fa-battery-three-quarters"></i>
            <p>No leave credit data<?= $lcSchoolYear ? '' : ' — no active school year set' ?></p>
          </div>
        <?php else: ?>
          <table class="an-table">
            <thead>
              <tr>
                <th>Leave Type</th>
                <th>Employees</th>
                <th>Allocated</th>
                <th>Used</th>
                <th>Utilization</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($leaveCredits as $lc):
                $allocated = (float)$lc['total_allocated'];
                $used      = (float)$lc['total_used'];
                $util      = $allocated > 0 ? min(100, round(($used / $allocated) * 100)) : 0;
                $barColor  = $util >= 90 ? 'var(--red)' : ($util >= 70 ? 'var(--yellow)' : 'var(--accent)');
              ?>
              <tr>
                <td><?= htmlspecialchars($lc['leave_name']) ?></td>
                <td class="muted"><?= $lc['employee_count'] ?></td>
                <td class="mono"><?= number_format($allocated, 1) ?>d</td>
                <td class="mono"><?= number_format($used, 1) ?>d</td>
                <td>
                  <div class="an-prog">
                    <div class="an-prog-track">
                      <div class="an-prog-bar" style="width:<?= $util ?>%;background:<?= $barColor ?>;"></div>
                    </div>
                    <span class="an-prog-pct"><?= $util ?>%</span>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-building-user"></i> Dept Attendance Rate</span>
        <span class="an-panel-sub"><?= date('F Y') ?></span>
      </div>
      <div class="an-panel-body">
        <?php if (empty($deptAtt)): ?>
          <div class="an-empty"><i class="fa fa-building-user"></i><p>No department attendance data this month</p></div>
        <?php else: ?>
          <?php foreach ($deptAtt as $da):
            $rate   = $da['total_records'] > 0
                ? min(100, round(($da['attended'] / $da['total_records']) * 100))
                : 0;
            $barCol = $rate >= 90 ? 'var(--accent)' : ($rate >= 70 ? 'var(--yellow)' : 'var(--red)');
          ?>
          <div class="an-dept-row">
            <div class="an-dept-name"><?= htmlspecialchars($da['department_name']) ?></div>
            <div class="an-dept-track">
              <div class="an-dept-bar" style="width:<?= $rate ?>%;background:<?= $barCol ?>;"></div>
            </div>
            <div class="an-dept-pct"><?= $rate ?>%</div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- row 4 -->

  <!-- ── Row 5: Active Loans + Pending Queue ───────────────────────────────────── -->
  <div class="an-grid">

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-hand-holding-dollar"></i> Active Loans</span>
        <span class="an-panel-sub">Outstanding: ₱<?= number_format($totalLoanBalance, 0) ?></span>
      </div>
      <div class="an-panel-body">
        <?php if (empty($loanSummary)): ?>
          <div class="an-empty"><i class="fa fa-hand-holding-dollar"></i><p>No active loans</p></div>
        <?php else: ?>
          <table class="an-table">
            <thead>
              <tr><th>Loan Type</th><th>Active</th><th>Outstanding Balance</th></tr>
            </thead>
            <tbody>
              <?php foreach ($loanSummary as $ls): ?>
              <tr>
                <td><?= htmlspecialchars($ls['loan_name']) ?></td>
                <td class="muted"><?= $ls['active_count'] ?></td>
                <td class="mono">₱<?= number_format((float)$ls['total_balance'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (!empty($scRow) && (float)$scRow['pending_payout'] > 0): ?>
          <div class="an-note">
            <i class="fa fa-circle-info"></i>
            ₱<?= number_format((float)$scRow['pending_payout'], 2) ?> in approved service credits awaiting next payroll run.
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-inbox"></i> Pending Approval Queue</span>
        <span class="an-panel-sub"><?= $totalPending ?> total pending</span>
      </div>
      <div class="an-panel-body">
        <div class="an-queue">

          <div class="an-queue-item">
            <div class="an-queue-icon teal"><i class="fa fa-money-bill-wave"></i></div>
            <div class="an-queue-info">
              <strong>Payroll</strong>
              <span>Awaiting principal approval</span>
            </div>
            <span class="an-badge <?= $pendingPayroll > 0 ? 'an-badge--warn' : 'an-badge--ok' ?>">
              <?= $pendingPayroll ?>
            </span>
          </div>

          <div class="an-queue-item">
            <div class="an-queue-icon blue"><i class="fa fa-calendar-minus"></i></div>
            <div class="an-queue-info">
              <strong>Leave Requests</strong>
              <span>
                Dates awaiting decision
                <?= $pendingLeave > 0 && $avgWaitLeave > 0 ? '· avg ' . $avgWaitLeave . 'd wait' : '' ?>
              </span>
            </div>
            <span class="an-badge <?= $pendingLeave > 0 ? 'an-badge--warn' : 'an-badge--ok' ?>">
              <?= $pendingLeave ?>
            </span>
          </div>

          <div class="an-queue-item">
            <div class="an-queue-icon purple"><i class="fa fa-file-invoice-dollar"></i></div>
            <div class="an-queue-info">
              <strong>Loan Applications</strong>
              <span>
                Pending review
                <?= $pendingLoans > 0 && $avgWaitLoans > 0 ? '· avg ' . $avgWaitLoans . 'd wait' : '' ?>
              </span>
            </div>
            <span class="an-badge <?= $pendingLoans > 0 ? 'an-badge--warn' : 'an-badge--ok' ?>">
              <?= $pendingLoans ?>
            </span>
          </div>

          <div class="an-queue-item">
            <div class="an-queue-icon orange"><i class="fa fa-star"></i></div>
            <div class="an-queue-info">
              <strong>Service Credits</strong>
              <span>
                Awaiting approval
                <?= $pendingSC > 0 && $avgWaitSC > 0 ? '· avg ' . $avgWaitSC . 'd wait' : '' ?>
              </span>
            </div>
            <span class="an-badge <?= $pendingSC > 0 ? 'an-badge--warn' : 'an-badge--ok' ?>">
              <?= $pendingSC ?>
            </span>
          </div>

        </div>
      </div>
    </div>

  </div><!-- row 5 -->

  <!-- ── Row 6: Payroll Deduction Breakdown + Leave Approval Stats ──────────────── -->
  <div class="an-grid an-grid--wide">

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-receipt"></i> Deduction Breakdown</span>
        <span class="an-panel-sub"><?= $dedPeriodLabel ? 'Period: ' . htmlspecialchars($dedPeriodLabel) : 'Last released period' ?></span>
      </div>
      <div class="an-panel-body">
        <?php if (empty($dedBreakdown)): ?>
          <div class="an-empty"><i class="fa fa-receipt"></i><p>No deduction data — no released payroll period found</p></div>
        <?php else: ?>
          <div style="position:relative;height:<?= min(40 + count($dedBreakdown) * 28, 260) ?>px;">
            <canvas id="dedBreakdownChart"></canvas>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-calendar-check"></i> Leave Approval Stats</span>
        <span class="an-panel-sub">All-time</span>
      </div>
      <div class="an-panel-body">
        <?php
          $laTotal    = (int)$leaveApproval['total'];
          $laApproved = (int)$leaveApproval['approved'];
          $laRejected = (int)$leaveApproval['rejected'];
          $laPending  = (int)$leaveApproval['pending'];
          $approvalRate = $laTotal > 0 ? round($laApproved / $laTotal * 100) : 0;
        ?>
        <?php if ($laTotal === 0): ?>
          <div class="an-empty"><i class="fa fa-calendar-check"></i><p>No leave requests on record</p></div>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <div style="flex:1;min-width:80px;background:var(--green-light);border-radius:10px;padding:12px 14px;">
                <div style="font-size:22px;font-weight:800;color:var(--green);"><?= $laApproved ?></div>
                <div style="font-size:11px;font-weight:700;color:var(--green);text-transform:uppercase;letter-spacing:.4px;margin-top:2px;">Approved</div>
              </div>
              <div style="flex:1;min-width:80px;background:var(--yellow-light);border-radius:10px;padding:12px 14px;">
                <div style="font-size:22px;font-weight:800;color:var(--yellow);"><?= $laPending ?></div>
                <div style="font-size:11px;font-weight:700;color:var(--yellow);text-transform:uppercase;letter-spacing:.4px;margin-top:2px;">Pending</div>
              </div>
              <div style="flex:1;min-width:80px;background:var(--red-light);border-radius:10px;padding:12px 14px;">
                <div style="font-size:22px;font-weight:800;color:var(--red);"><?= $laRejected ?></div>
                <div style="font-size:11px;font-weight:700;color:var(--red);text-transform:uppercase;letter-spacing:.4px;margin-top:2px;">Rejected</div>
              </div>
            </div>
            <div>
              <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-bottom:5px;">
                <span>Approval rate</span><span style="font-weight:700;color:var(--green);"><?= $approvalRate ?>%</span>
              </div>
              <div class="an-prog-track" style="height:8px;">
                <div class="an-prog-bar" style="width:<?= $approvalRate ?>%;background:var(--green);"></div>
              </div>
            </div>
            <?php if ($laPending > 0): ?>
            <div class="an-note" style="margin-top:0;">
              <i class="fa fa-circle-info"></i>
              <?= $laPending ?> request<?= $laPending != 1 ? 's' : '' ?> awaiting decision.
            </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- row 6 -->

  <!-- ── Row 7: Top Late/Absent + Loan Repayment Progress ─────────────────────── -->
  <div class="an-grid">

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-user-clock"></i> Top Late / Absent</span>
        <span class="an-panel-sub"><?= date('F Y') ?></span>
      </div>
      <div class="an-panel-body" style="padding:0;">
        <?php if (empty($topLate)): ?>
          <div class="an-empty"><i class="fa fa-user-clock"></i><p>No late or absent records this month</p></div>
        <?php else: ?>
          <table class="an-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th style="text-align:center;">Late</th>
                <th style="text-align:center;">Absent</th>
                <th>Late Time</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topLate as $tl):
                $totalMin = (int)$tl['total_late_min'];
                $lateDisp = $totalMin >= 60
                    ? floor($totalMin/60).'h '.($totalMin%60).'m'
                    : $totalMin.'m';
              ?>
              <tr>
                <td>
                  <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($tl['emp_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($tl['department_name'] ?? '—') ?></div>
                </td>
                <td class="mono" style="text-align:center;color:var(--yellow);font-weight:700;"><?= (int)$tl['late_cnt'] ?></td>
                <td class="mono" style="text-align:center;color:var(--red);font-weight:700;"><?= (int)$tl['absent_cnt'] ?></td>
                <td class="muted mono"><?= $totalMin > 0 ? $lateDisp : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="an-panel">
      <div class="an-panel-hdr">
        <span class="an-panel-title"><i class="fa fa-chart-simple"></i> Loan Repayment Progress</span>
        <span class="an-panel-sub">Active loans</span>
      </div>
      <div class="an-panel-body" style="padding:0;">
        <?php if (empty($loanProgress)): ?>
          <div class="an-empty"><i class="fa fa-chart-simple"></i><p>No active loans</p></div>
        <?php else: ?>
          <table class="an-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Progress</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($loanProgress as $lp):
                $paid    = (float)$lp['total_amount'] - (float)$lp['balance_amount'];
                $paidPct = (float)$lp['total_amount'] > 0
                    ? min(100, round($paid / (float)$lp['total_amount'] * 100))
                    : 0;
                $barCol  = $paidPct >= 75 ? 'var(--green)' : ($paidPct >= 40 ? 'var(--accent)' : 'var(--yellow)');
              ?>
              <tr>
                <td>
                  <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($lp['emp_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);">₱<?= number_format((float)$lp['balance_amount'], 0) ?> left</div>
                </td>
                <td class="muted" style="font-size:12px;"><?= htmlspecialchars($lp['loan_name']) ?></td>
                <td>
                  <div class="an-prog">
                    <div class="an-prog-track">
                      <div class="an-prog-bar" style="width:<?= $paidPct ?>%;background:<?= $barCol ?>;"></div>
                    </div>
                    <span class="an-prog-pct"><?= $paidPct ?>%</span>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- row 7 -->

</div><!-- .an-page -->

<!-- ── Chart.js ──────────────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const PAYROLL_TREND  = <?= $payrollTrendJson ?>;
const ATT_WEEKLY     = <?= $attWeeklyJson ?>;
const DEPT_HEAD      = <?= $deptHeadJson ?>;
const LEAVE_TYPE     = <?= $leaveTypeJson ?>;
const LEAVE_MONTHLY  = <?= $leaveMonthlyJson ?>;
const EMP_TYPE       = <?= $empTypeJson ?>;
const DED_BREAKDOWN  = <?= $dedBreakdownJson ?>;
const PALETTE = <?= json_encode($PALETTE) ?>;

Chart.defaults.font.family  = 'Inter, system-ui, sans-serif';
Chart.defaults.font.size    = 12;
Chart.defaults.animation    = false;
Chart.defaults.transitions  = {};
const gridColor = '#f1f5f9';

// ── 1. Payroll Cost Trend ─────────────────────────────────────────────────────
if (PAYROLL_TREND.length) {
    new Chart(document.getElementById('payrollTrendChart'), {
        type: 'line',
        data: {
            labels: PAYROLL_TREND.map(r => r.label),
            datasets: [
                {
                    label: 'Gross Pay',
                    data: PAYROLL_TREND.map(r => r.gross),
                    borderColor: '#0f766e',
                    backgroundColor: 'rgba(15,118,110,.08)',
                    fill: true, tension: .35,
                    pointRadius: 4, pointBackgroundColor: '#0f766e',
                },
                {
                    label: 'Net Pay',
                    data: PAYROLL_TREND.map(r => r.net),
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,.07)',
                    fill: true, tension: .35,
                    pointRadius: 4, pointBackgroundColor: '#2563eb',
                },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: { callbacks: { label: ctx => ' ₱' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits:0}) } }
            },
            scales: {
                y: { ticks: { callback: v => '₱' + (v/1000).toFixed(0) + 'k' }, grid: { color: gridColor } },
                x: { grid: { display: false } }
            }
        }
    });
}

// ── 2. Employment Type Doughnut ───────────────────────────────────────────────
if (EMP_TYPE.some(r => r.count > 0)) {
    new Chart(document.getElementById('empTypeChart'), {
        type: 'doughnut',
        data: {
            labels: EMP_TYPE.map(r => r.label),
            datasets: [{
                data: EMP_TYPE.map(r => r.count),
                backgroundColor: ['#0f766e', '#64748b'],
                borderWidth: 2, borderColor: '#fff',
            }]
        },
        options: {
            responsive: true, cutout: '62%',
            plugins: { legend: { display: false } }
        }
    });
}

// ── 3. Weekly Attendance Trend ────────────────────────────────────────────────
if (ATT_WEEKLY.length) {
    new Chart(document.getElementById('attWeeklyChart'), {
        type: 'bar',
        data: {
            labels: ATT_WEEKLY.map(r => r.label),
            datasets: [
                { label: 'Attended', data: ATT_WEEKLY.map(r => r.attended), backgroundColor: '#0f766ecc', borderRadius: 4 },
                { label: 'Late',     data: ATT_WEEKLY.map(r => r.late),     backgroundColor: '#ea580ccc', borderRadius: 4 },
                { label: 'Absent',   data: ATT_WEEKLY.map(r => r.absent),   backgroundColor: '#dc2626cc', borderRadius: 4 },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: gridColor } }
            }
        }
    });
}

// ── 4. Dept Headcount Horizontal Bar ─────────────────────────────────────────
if (DEPT_HEAD.length) {
    new Chart(document.getElementById('deptHeadChart'), {
        type: 'bar',
        data: {
            labels: DEPT_HEAD.map(r => r.label),
            datasets: [{
                label: 'Employees',
                data: DEPT_HEAD.map(r => r.count),
                backgroundColor: DEPT_HEAD.map((_, i) => PALETTE[i % PALETTE.length] + 'cc'),
                borderRadius: 5, borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: gridColor } },
                y: { grid: { display: false } }
            }
        }
    });
}

// ── 5. Leave Dates by Month ───────────────────────────────────────────────────
if (LEAVE_MONTHLY.length) {
    new Chart(document.getElementById('leaveMonthlyChart'), {
        type: 'bar',
        data: {
            labels: LEAVE_MONTHLY.map(r => r.label),
            datasets: [{
                label: 'Leave Dates',
                data: LEAVE_MONTHLY.map(r => r.count),
                backgroundColor: '#2563ebcc',
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: gridColor } }
            }
        }
    });
}

// ── 6. Leave Type Doughnut ────────────────────────────────────────────────────
if (LEAVE_TYPE.some(r => r.count > 0)) {
    new Chart(document.getElementById('leaveTypeChart'), {
        type: 'doughnut',
        data: {
            labels: LEAVE_TYPE.map(r => r.label),
            datasets: [{
                data: LEAVE_TYPE.map(r => r.count),
                backgroundColor: PALETTE.slice(0, LEAVE_TYPE.length),
                borderWidth: 2, borderColor: '#fff',
            }]
        },
        options: {
            responsive: true, cutout: '60%',
            plugins: { legend: { display: false } }
        }
    });
}

// ── 7. Deduction Breakdown Horizontal Bar ─────────────────────────────────────
if (DED_BREAKDOWN.length) {
    new Chart(document.getElementById('dedBreakdownChart'), {
        type: 'bar',
        data: {
            labels: DED_BREAKDOWN.map(r => r.label),
            datasets: [{
                label: 'Total',
                data: DED_BREAKDOWN.map(r => r.amount),
                backgroundColor: PALETTE.map(c => c + 'cc'),
                borderRadius: 4,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' ₱' + ctx.parsed.x.toLocaleString(undefined, {minimumFractionDigits:2}) } }
            },
            scales: {
                x: { ticks: { callback: v => '₱' + (v/1000).toFixed(0) + 'k' }, grid: { color: gridColor } },
                y: { grid: { display: false } }
            }
        }
    });
}
</script>

</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>
