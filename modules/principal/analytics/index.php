<?php
/**
 * modules/principal/analytics/index.php
 * Principal Portal – Analytics Overview
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requirePrincipal();

// ── Payroll Cost Trend (last 6 finalized periods) ─────────────────────────────
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
    LEFT JOIN payroll_records pr ON pp.period_id = pr.period_id
    WHERE pp.status IN ('APPROVED', 'RELEASED')
    GROUP BY pp.period_id
    ORDER BY pp.pay_period_start DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);
$trendRows = array_reverse($trendRows);

// KPIs from trend
$totalReleasedNet  = array_sum(array_column($trendRows, 'total_net'));
$totalReleasedGross = array_sum(array_column($trendRows, 'total_gross'));
$periodCount       = count($trendRows);
$avgNetPerPeriod   = $periodCount > 0 ? $totalReleasedNet / $periodCount : 0;

// ── Payroll Status Counts ─────────────────────────────────────────────────────
$payrollStatusRow = $pdo->query("
    SELECT
        SUM(status = 'PROCESSING') AS processing_count,
        SUM(status = 'APPROVED')   AS approved_count,
        SUM(status = 'RELEASED')   AS released_count
    FROM payroll_periods
")->fetch(PDO::FETCH_ASSOC);

$returnedCount = (int)$pdo->query("
    SELECT COUNT(DISTINCT period_id)
    FROM payroll_workflow_log
    WHERE event_type = 'RETURNED'
")->fetchColumn();

// ── Active Employees ──────────────────────────────────────────────────────────
$totalActive = (int)$pdo->query("
    SELECT COUNT(*) FROM employees WHERE employee_status = 'ACTIVE'
")->fetchColumn();

// ── Attendance: This Month ────────────────────────────────────────────────────
$attMonth = $pdo->query("
    SELECT
        COALESCE(SUM(attendance_status IN ('PRESENT','LATE','HALF_DAY')), 0) AS attended,
        COALESCE(SUM(attendance_status = 'PRESENT'), 0)   AS present_cnt,
        COALESCE(SUM(attendance_status = 'LATE'), 0)      AS late_cnt,
        COALESCE(SUM(attendance_status = 'HALF_DAY'), 0)  AS halfday_cnt,
        COUNT(DISTINCT attendance_date)                    AS days_with_data
    FROM attendance_records
    WHERE YEAR(attendance_date)  = YEAR(CURDATE())
      AND MONTH(attendance_date) = MONTH(CURDATE())
")->fetch(PDO::FETCH_ASSOC);

$attMonthRate = 0;
if (!empty($attMonth['days_with_data']) && $totalActive > 0) {
    $attMonthRate = min(100, round(($attMonth['attended'] / ((int)$attMonth['days_with_data'] * $totalActive)) * 100));
}

// ── Attendance: Weekly Trend (last 4 weeks) ───────────────────────────────────
$attWeekly = $pdo->query("
    SELECT
        YEARWEEK(attendance_date, 1) AS yw,
        MIN(attendance_date)          AS week_start,
        COALESCE(SUM(attendance_status IN ('PRESENT','LATE','HALF_DAY')), 0) AS attended,
        COALESCE(SUM(attendance_status = 'LATE'), 0) AS late_count
    FROM attendance_records
    WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK)
    GROUP BY yw
    ORDER BY yw ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Leave Analytics ───────────────────────────────────────────────────────────
$leaveMonthly = $pdo->query("
    SELECT DATE_FORMAT(lrd.leave_date, '%Y-%m') AS ym,
           DATE_FORMAT(lrd.leave_date, '%b %Y')  AS label,
           COUNT(*) AS date_count
    FROM leave_request_dates lrd
    WHERE lrd.leave_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym, label
    ORDER BY ym ASC
")->fetchAll(PDO::FETCH_ASSOC);

$leaveTypes = $pdo->query("
    SELECT lt.leave_name,
           COUNT(DISTINCT lr.leave_id) AS request_count
    FROM leave_requests lr
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    GROUP BY lt.leave_type_id, lt.leave_name
    ORDER BY request_count DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$leaveStatusRow = $pdo->query("
    SELECT
        COALESCE(SUM(status = 'PENDING'), 0)  AS pending_count,
        COALESCE(SUM(status = 'APPROVED'), 0) AS approved_count,
        COALESCE(SUM(status = 'REJECTED'), 0) AS rejected_count
    FROM leave_request_dates
")->fetch(PDO::FETCH_ASSOC);

// ── Approval Workflow Pending Counts ─────────────────────────────────────────
$pendingPayroll = (int)$pdo->query("SELECT COUNT(*) FROM payroll_periods WHERE status='PROCESSING'")->fetchColumn();

$pendingLeave = (int)$pdo->query("
    SELECT COUNT(DISTINCT lr.leave_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id AND lrd.status = 'PENDING'
")->fetchColumn();

$pendingLoans = (int)$pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='PENDING'")->fetchColumn();
$pendingSC    = (int)$pdo->query("SELECT COUNT(*) FROM service_credits WHERE status='PENDING'")->fetchColumn();

// Average waiting days
$avgWaitPayroll = round((float)$pdo->query("
    SELECT COALESCE(AVG(DATEDIFF(CURDATE(), created_at)), 0)
    FROM payroll_periods WHERE status = 'PROCESSING'
")->fetchColumn());

$avgWaitLeave = round((float)$pdo->query("
    SELECT COALESCE(AVG(DATEDIFF(CURDATE(), lr.created_at)), 0)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id AND lrd.status = 'PENDING'
")->fetchColumn());

$avgWaitLoans = round((float)$pdo->query("
    SELECT COALESCE(AVG(DATEDIFF(CURDATE(), created_at)), 0)
    FROM employee_loans WHERE status = 'PENDING'
")->fetchColumn());

$avgWaitSC = round((float)$pdo->query("
    SELECT COALESCE(AVG(DATEDIFF(CURDATE(), created_at)), 0)
    FROM service_credits WHERE status = 'PENDING'
")->fetchColumn());

// ── Department attendance rate — this month ───────────────────────────────────
$deptAtt = $pdo->query("
    SELECT d.department_name,
           COUNT(DISTINCT e.employee_id) AS emp_count,
           COALESCE(SUM(ar.attendance_status IN ('PRESENT','LATE','HALF_DAY')), 0) AS attended,
           COUNT(ar.attendance_id) AS total_records
    FROM departments d
    LEFT JOIN employees e ON e.department_id=d.department_id AND e.employee_status='ACTIVE'
    LEFT JOIN attendance_records ar ON ar.employee_id=e.employee_id
          AND YEAR(ar.attendance_date)=YEAR(CURDATE())
          AND MONTH(ar.attendance_date)=MONTH(CURDATE())
    GROUP BY d.department_id, d.department_name
    HAVING COUNT(DISTINCT e.employee_id) > 0
    ORDER BY (COALESCE(SUM(ar.attendance_status IN ('PRESENT','LATE','HALF_DAY')),0) / GREATEST(COUNT(ar.attendance_id),1)) DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Leave credit utilization (active school year) — migration-safe ────────────
$lcRows       = [];
$lcSchoolYear = '';
try {
    $syRow = $pdo->query("SELECT school_year_id, year_name FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
    if ($syRow) {
        $lcSchoolYear = $syRow['year_name'];
        $lcStmt = $pdo->prepare("
            SELECT lt.leave_name,
                   COALESCE(SUM(elc.allocated_days), 0) AS total_allocated,
                   COALESCE(SUM(elc.used_days), 0)      AS total_used,
                   COUNT(DISTINCT elc.employee_id)       AS emp_count
            FROM employee_leave_credits elc
            JOIN leave_types lt ON elc.leave_type_id=lt.leave_type_id
            WHERE elc.school_year_id=?
            GROUP BY lt.leave_type_id, lt.leave_name
            ORDER BY total_allocated DESC
        ");
        $lcStmt->execute([$syRow['school_year_id']]);
        $lcRows = $lcStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

// ── JSON for Charts ───────────────────────────────────────────────────────────
$payrollTrendJson = json_encode(array_values(array_map(fn($r) => [
    'label' => date('M j', strtotime($r['pay_period_start'])) . '–' . date('M j', strtotime($r['pay_period_end'])),
    'net'   => round((float)$r['total_net'], 2),
    'gross' => round((float)$r['total_gross'], 2),
], $trendRows)));

$leaveMonthlyJson = json_encode(array_values(array_map(fn($r) => [
    'label' => $r['label'],
    'count' => (int)$r['date_count'],
], $leaveMonthly)));

$leaveTypeJson = json_encode(array_values(array_map(fn($r) => [
    'label' => $r['leave_name'],
    'count' => (int)$r['request_count'],
], $leaveTypes)));

$attWeeklyJson = json_encode(array_values(array_map(fn($r) => [
    'label'    => 'Wk ' . date('M j', strtotime($r['week_start'])),
    'attended' => (int)$r['attended'],
    'late'     => (int)$r['late_count'],
], $attWeekly)));

// ── Page Init ─────────────────────────────────────────────────────────────────
$pageTitle = 'Analytics — Principal Portal';
$extraCSS  = [
    BASE_URL . 'assets/css/principal.css',
    BASE_URL . 'assets/css/principal-analytics.css',
];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/principal-sidebar.php'; ?>

<div class="main">

  <!-- ── Header ─────────────────────────────────────────────────────────────── -->
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-chart-bar" style="color:#0f766e;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Principal Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <button style="background:none;border:none;cursor:pointer;">
        <i class="fa fa-bell" style="font-size:16px;color:#64748b;"></i>
      </button>
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;">School Principal</div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'P', 0, 1) .
                       substr($_SESSION['user']['last_name']  ?? 'R', 0, 1)) ?>
      </div>
    </div>
  </div>

  <div class="main-content">
  <div class="principal-page">

    <!-- ── Page Header ──────────────────────────────────────────────────────── -->
    <div class="principal-page-header">
      <h1>Analytics Overview</h1>
      <p>Operational insights for <?= date('F Y') ?> — read-only management summary.</p>
    </div>

    <!-- ── KPI Cards ────────────────────────────────────────────────────────── -->
    <div class="pan-kpi-row">

      <div class="pan-kpi-card pan-kpi--teal">
        <div class="pan-kpi-icon"><i class="fa fa-peso-sign"></i></div>
        <div class="pan-kpi-body">
          <div class="pan-kpi-value">₱<?= number_format($totalReleasedNet, 0) ?></div>
          <div class="pan-kpi-label">Total Net Released</div>
          <div class="pan-kpi-sub">
            <?= $periodCount > 0 ? "Across $periodCount finalized period" . ($periodCount !== 1 ? 's' : '') : 'No finalized periods yet' ?>
          </div>
        </div>
      </div>

      <div class="pan-kpi-card pan-kpi--amber">
        <div class="pan-kpi-icon"><i class="fa fa-calculator"></i></div>
        <div class="pan-kpi-body">
          <div class="pan-kpi-value">₱<?= number_format($avgNetPerPeriod, 0) ?></div>
          <div class="pan-kpi-label">Avg Net Per Period</div>
          <div class="pan-kpi-sub">Last <?= $periodCount ?> period<?= $periodCount !== 1 ? 's' : '' ?></div>
        </div>
      </div>

      <div class="pan-kpi-card pan-kpi--green">
        <div class="pan-kpi-icon"><i class="fa fa-user-check"></i></div>
        <div class="pan-kpi-body">
          <div class="pan-kpi-value"><?= $attMonthRate ?>%</div>
          <div class="pan-kpi-label">Attendance Rate</div>
          <div class="pan-kpi-sub"><?= date('F Y') ?></div>
        </div>
      </div>

      <div class="pan-kpi-card pan-kpi--blue">
        <div class="pan-kpi-icon"><i class="fa fa-users"></i></div>
        <div class="pan-kpi-body">
          <div class="pan-kpi-value"><?= $totalActive ?></div>
          <div class="pan-kpi-label">Active Employees</div>
          <div class="pan-kpi-sub">Currently enrolled</div>
        </div>
      </div>

    </div><!-- .pan-kpi-row -->

    <!-- ── Row 2: Payroll Trend + Approval Queue ────────────────────────────── -->
    <div class="pan-grid-2-1">

      <!-- Payroll Cost Trend -->
      <div class="pan-panel" style="margin-bottom:0;">
        <div class="pan-panel-header">
          <div class="pan-panel-title">
            <i class="fa fa-file-invoice-dollar" style="color:#d97706;"></i>
            Payroll Cost Trend
          </div>
          <span class="pan-panel-meta">Last <?= max(1, $periodCount) ?> periods</span>
        </div>

        <?php if (empty($trendRows)): ?>
        <div class="pan-empty">
          <i class="fa fa-chart-line"></i>
          <p>No finalized payroll periods yet.<br>Charts will appear once payroll is approved or released.</p>
        </div>
        <?php else: ?>
        <div class="pan-chart-wrap">
          <canvas id="payrollTrendChart"></canvas>
        </div>
        <div class="pan-chart-legend">
          <span class="pan-legend-dot pan-legend--teal"></span> Net Pay
          <span class="pan-legend-dot pan-legend--amber" style="margin-left:16px;"></span> Gross Pay (dashed)
        </div>
        <?php endif; ?>
      </div>

      <!-- Approval Queue -->
      <div class="pan-panel" style="margin-bottom:0;">
        <div class="pan-panel-header">
          <div class="pan-panel-title">
            <i class="fa fa-hourglass-half" style="color:#6366f1;"></i>
            Approval Queue
          </div>
          <span class="pan-panel-meta">Live</span>
        </div>

        <div class="pan-workflow-list">

          <div class="pan-wf-row <?= $pendingPayroll > 0 ? 'pan-wf-row--active' : '' ?>">
            <div class="pan-wf-icon pan-wf-icon--amber">
              <i class="fa fa-file-invoice-dollar"></i>
            </div>
            <div class="pan-wf-body">
              <div class="pan-wf-label">Payroll Approvals</div>
              <div class="pan-wf-meta">
                <?= $pendingPayroll > 0 ? "~{$avgWaitPayroll}d avg wait" : 'None pending' ?>
              </div>
            </div>
            <div class="pan-wf-count <?= $pendingPayroll > 0 ? 'pan-wf-count--amber' : 'pan-wf-count--zero' ?>">
              <?= $pendingPayroll ?>
            </div>
          </div>

          <div class="pan-wf-row <?= $pendingLeave > 0 ? 'pan-wf-row--active' : '' ?>">
            <div class="pan-wf-icon pan-wf-icon--blue">
              <i class="fa fa-calendar-days"></i>
            </div>
            <div class="pan-wf-body">
              <div class="pan-wf-label">Leave Requests</div>
              <div class="pan-wf-meta">
                <?= $pendingLeave > 0 ? "~{$avgWaitLeave}d avg wait" : 'None pending' ?>
              </div>
            </div>
            <div class="pan-wf-count <?= $pendingLeave > 0 ? 'pan-wf-count--blue' : 'pan-wf-count--zero' ?>">
              <?= $pendingLeave ?>
            </div>
          </div>

          <div class="pan-wf-row <?= $pendingLoans > 0 ? 'pan-wf-row--active' : '' ?>">
            <div class="pan-wf-icon pan-wf-icon--purple">
              <i class="fa fa-hand-holding-dollar"></i>
            </div>
            <div class="pan-wf-body">
              <div class="pan-wf-label">Loan Applications</div>
              <div class="pan-wf-meta">
                <?= $pendingLoans > 0 ? "~{$avgWaitLoans}d avg wait" : 'None pending' ?>
              </div>
            </div>
            <div class="pan-wf-count <?= $pendingLoans > 0 ? 'pan-wf-count--purple' : 'pan-wf-count--zero' ?>">
              <?= $pendingLoans ?>
            </div>
          </div>

          <div class="pan-wf-row <?= $pendingSC > 0 ? 'pan-wf-row--active' : '' ?>">
            <div class="pan-wf-icon pan-wf-icon--teal">
              <i class="fa fa-medal"></i>
            </div>
            <div class="pan-wf-body">
              <div class="pan-wf-label">Service Credits</div>
              <div class="pan-wf-meta">
                <?= $pendingSC > 0 ? "~{$avgWaitSC}d avg wait" : 'None pending' ?>
              </div>
            </div>
            <div class="pan-wf-count <?= $pendingSC > 0 ? 'pan-wf-count--teal' : 'pan-wf-count--zero' ?>">
              <?= $pendingSC ?>
            </div>
          </div>

        </div>

        <?php if ($returnedCount > 0): ?>
        <div class="pan-returned-note">
          <i class="fa fa-rotate-left"></i>
          <?= $returnedCount ?> payroll period<?= $returnedCount > 1 ? 's have' : ' has' ?> been returned for revision.
        </div>
        <?php endif; ?>

        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
          <a href="<?= BASE_URL ?>modules/principal/payroll-approval/index.php"
             style="font-size:12px;color:#0f766e;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:4px;">
            <i class="fa fa-arrow-right" style="font-size:10px;"></i> Payroll Approvals
          </a>
          <span style="color:#cbd5e1;">·</span>
          <a href="<?= BASE_URL ?>modules/principal/leave-approval/index.php"
             style="font-size:12px;color:#0f766e;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:4px;">
            <i class="fa fa-arrow-right" style="font-size:10px;"></i> Leave Requests
          </a>
        </div>

      </div>

    </div><!-- .pan-grid-2-1 -->

    <!-- ── Row 3: Leave Analytics ────────────────────────────────────────────── -->
    <div class="pan-grid-1-1">

      <!-- Leave by Month -->
      <div class="pan-panel" style="margin-bottom:0;">
        <div class="pan-panel-header">
          <div class="pan-panel-title">
            <i class="fa fa-calendar-days" style="color:#3b82f6;"></i>
            Leave Dates by Month
          </div>
          <span class="pan-panel-meta">Last 6 months</span>
        </div>

        <?php if (empty($leaveMonthly)): ?>
        <div class="pan-empty">
          <i class="fa fa-calendar-xmark"></i>
          <p>No leave data for the past 6 months.</p>
        </div>
        <?php else: ?>
        <div class="pan-chart-wrap--sm pan-chart-wrap">
          <canvas id="leaveMonthlyChart"></canvas>
        </div>
        <div class="pan-leave-summary">
          <div class="pan-leave-stat">
            <div class="pan-leave-dot" style="background:#f59e0b;"></div>
            <?= (int)($leaveStatusRow['pending_count'] ?? 0) ?> Pending
          </div>
          <div class="pan-leave-stat">
            <div class="pan-leave-dot" style="background:#10b981;"></div>
            <?= (int)($leaveStatusRow['approved_count'] ?? 0) ?> Approved
          </div>
          <div class="pan-leave-stat">
            <div class="pan-leave-dot" style="background:#ef4444;"></div>
            <?= (int)($leaveStatusRow['rejected_count'] ?? 0) ?> Rejected
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Leave Type Distribution -->
      <div class="pan-panel" style="margin-bottom:0;">
        <div class="pan-panel-header">
          <div class="pan-panel-title">
            <i class="fa fa-chart-pie" style="color:#6366f1;"></i>
            Leave Type Distribution
          </div>
          <span class="pan-panel-meta">All time</span>
        </div>

        <?php if (empty($leaveTypes)): ?>
        <div class="pan-empty">
          <i class="fa fa-chart-pie"></i>
          <p>No leave requests recorded yet.</p>
        </div>
        <?php else: ?>
        <div class="pan-donut-panel-inner">
          <div class="pan-donut-wrap">
            <canvas id="leaveTypeChart"></canvas>
          </div>
          <div class="pan-donut-legend" id="leaveTypeLegend"></div>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- .pan-grid-1-1 -->

    <!-- ── Row 4: Attendance Weekly Trend ───────────────────────────────────── -->
    <div class="pan-panel">
      <div class="pan-panel-header">
        <div class="pan-panel-title">
          <i class="fa fa-user-check" style="color:#10b981;"></i>
          Weekly Attendance Trend
        </div>
        <span class="pan-panel-meta">Last 4 weeks</span>
      </div>

      <?php if (empty($attWeekly)): ?>
      <div class="pan-empty">
        <i class="fa fa-user-check"></i>
        <p>No attendance records for the past 4 weeks.</p>
      </div>
      <?php else: ?>
      <div class="pan-chart-wrap">
        <canvas id="attWeeklyChart"></canvas>
      </div>
      <div class="pan-chart-legend" style="margin-top:12px;">
        <span class="pan-legend-dot pan-legend--green"></span> Total Attended
        <span class="pan-legend-dot pan-legend--amber" style="margin-left:16px;"></span> Arrived Late
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Row 5: Dept Attendance + Leave Credit Utilization ───────────────────── -->
    <div class="pan-grid-1-1">

      <div class="pan-panel" style="margin-bottom:0;">
        <div class="pan-panel-header">
          <div class="pan-panel-title">
            <i class="fa fa-building-user" style="color:#0f766e;"></i>
            Dept Attendance Rate
          </div>
          <span class="pan-panel-meta"><?= date('F Y') ?></span>
        </div>
        <?php if (empty($deptAtt)): ?>
          <div class="pan-empty"><i class="fa fa-building-user"></i><p>No department attendance data this month.</p></div>
        <?php else: ?>
          <?php foreach ($deptAtt as $da):
            $rate   = $da['total_records'] > 0
                ? min(100, round(($da['attended'] / $da['total_records']) * 100)) : 0;
            $barCol = $rate >= 90 ? '#0f766e' : ($rate >= 70 ? '#d97706' : '#ef4444');
          ?>
          <div class="pan-dept-row">
            <div class="pan-dept-name"><?= htmlspecialchars($da['department_name']) ?></div>
            <div class="pan-dept-track">
              <div class="pan-dept-bar" style="width:<?= $rate ?>%;background:<?= $barCol ?>;"></div>
            </div>
            <div class="pan-dept-pct"><?= $rate ?>%</div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="pan-panel" style="margin-bottom:0;">
        <div class="pan-panel-header">
          <div class="pan-panel-title">
            <i class="fa fa-battery-three-quarters" style="color:#6366f1;"></i>
            Leave Credit Utilization
          </div>
          <span class="pan-panel-meta"><?= $lcSchoolYear ? htmlspecialchars($lcSchoolYear) : 'Active year' ?></span>
        </div>
        <?php if (empty($lcRows)): ?>
          <div class="pan-empty"><i class="fa fa-battery-three-quarters"></i><p>No leave credit data<?= $lcSchoolYear ? '' : ' — no active school year set' ?>.</p></div>
        <?php else: ?>
          <?php foreach ($lcRows as $lc):
            $alloc = (float)$lc['total_allocated'];
            $used  = (float)$lc['total_used'];
            $util  = $alloc > 0 ? min(100, round($used / $alloc * 100)) : 0;
            $barCol = $util >= 90 ? '#ef4444' : ($util >= 70 ? '#d97706' : '#0f766e');
          ?>
          <div class="pan-lc-row">
            <div>
              <div class="pan-lc-name"><?= htmlspecialchars($lc['leave_name']) ?></div>
              <div class="pan-lc-meta"><?= $lc['emp_count'] ?> employee<?= $lc['emp_count'] != 1 ? 's' : '' ?> · <?= number_format($used,1) ?> / <?= number_format($alloc,1) ?> days used</div>
            </div>
            <div class="pan-lc-track">
              <div class="pan-lc-bar" style="width:<?= $util ?>%;background:<?= $barCol ?>;"></div>
            </div>
            <div class="pan-lc-pct"><?= $util ?>%</div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div><!-- row 5 -->

  </div><!-- .principal-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- ── Chart.js ───────────────────────────────────────────────────────────── -->
<script src="<?= BASE_URL ?>assets/js/chart.umd.min.js"></script>
<script>
const BASE_URL = '<?= BASE_URL ?>';

Chart.defaults.font.family  = "'Plus Jakarta Sans', 'DM Sans', sans-serif";
Chart.defaults.font.size    = 12;
Chart.defaults.color        = '#6b8886';
Chart.defaults.animation    = false;
Chart.defaults.transitions  = {};

// ── Payroll Cost Trend (Line) ─────────────────────────────────────────────────
(function () {
    const data = <?= $payrollTrendJson ?>;
    if (!data.length) return;
    new Chart(document.getElementById('payrollTrendChart'), {
        type: 'line',
        data: {
            labels: data.map(d => d.label),
            datasets: [
                {
                    label: 'Net Pay',
                    data: data.map(d => d.net),
                    borderColor: '#0f766e',
                    backgroundColor: 'rgba(15,118,110,0.07)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#0f766e',
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    borderWidth: 2.5,
                },
                {
                    label: 'Gross Pay',
                    data: data.map(d => d.gross),
                    borderColor: '#d97706',
                    backgroundColor: 'transparent',
                    fill: false,
                    tension: 0.4,
                    pointBackgroundColor: '#d97706',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2,
                    borderDash: [5, 4],
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ₱' + ctx.parsed.y.toLocaleString('en-PH', { minimumFractionDigits: 2 }),
                    },
                },
            },
            scales: {
                y: {
                    ticks: {
                        callback: v => '₱' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v),
                    },
                    grid: { color: 'rgba(0,0,0,0.04)' },
                },
                x: { grid: { display: false } },
            },
        },
    });
})();

// ── Leave Dates by Month (Bar) ────────────────────────────────────────────────
(function () {
    const data = <?= $leaveMonthlyJson ?>;
    if (!data.length) return;
    new Chart(document.getElementById('leaveMonthlyChart'), {
        type: 'bar',
        data: {
            labels: data.map(d => d.label),
            datasets: [{
                label: 'Leave Dates',
                data: data.map(d => d.count),
                backgroundColor: 'rgba(59,130,246,0.70)',
                borderColor: '#3b82f6',
                borderWidth: 1.5,
                borderRadius: 5,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                    grid: { color: 'rgba(0,0,0,0.04)' },
                },
                x: { grid: { display: false } },
            },
        },
    });
})();

// ── Leave Type Doughnut ───────────────────────────────────────────────────────
(function () {
    const data   = <?= $leaveTypeJson ?>;
    const legend = document.getElementById('leaveTypeLegend');
    if (!data.length) return;
    const COLORS = ['#3b82f6', '#0f766e', '#d97706', '#6366f1', '#10b981', '#ef4444'];
    new Chart(document.getElementById('leaveTypeChart'), {
        type: 'doughnut',
        data: {
            labels: data.map(d => d.label),
            datasets: [{
                data: data.map(d => d.count),
                backgroundColor: COLORS.slice(0, data.length),
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' request' + (ctx.parsed !== 1 ? 's' : ''),
                    },
                },
            },
        },
    });
    if (legend) {
        data.forEach((d, i) => {
            const item = document.createElement('div');
            item.className = 'pan-legend-item';
            item.innerHTML =
                '<span class="pan-legend-swatch" style="background:' + COLORS[i] + ';"></span>' +
                '<span>' + d.label + '</span>' +
                '<span class="pan-legend-val">' + d.count + '</span>';
            legend.appendChild(item);
        });
    }
})();

// ── Weekly Attendance (Bar) ───────────────────────────────────────────────────
(function () {
    const data = <?= $attWeeklyJson ?>;
    if (!data.length) return;
    new Chart(document.getElementById('attWeeklyChart'), {
        type: 'bar',
        data: {
            labels: data.map(d => d.label),
            datasets: [
                {
                    label: 'Total Attended',
                    data: data.map(d => d.attended),
                    backgroundColor: 'rgba(16,185,129,0.70)',
                    borderColor: '#10b981',
                    borderWidth: 1.5,
                    borderRadius: 5,
                },
                {
                    label: 'Late',
                    data: data.map(d => d.late),
                    backgroundColor: 'rgba(245,158,11,0.70)',
                    borderColor: '#f59e0b',
                    borderWidth: 1.5,
                    borderRadius: 5,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                    grid: { color: 'rgba(0,0,0,0.04)' },
                },
                x: { grid: { display: false } },
            },
        },
    });
})();
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
