<?php
/**
 * modules/employee/dashboard/index.php
 * Employee Self-Service Portal — Dashboard
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployee();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');

// ── Latest Released Payslip ───────────────────────────────────────────────────
$latestPayslip = null;
if ($empId) {
    $stmt = $pdo->prepare("
        SELECT pr.gross_pay, pr.total_deductions, pr.net_pay,
               pp.pay_period_start, pp.pay_period_end, pp.status
        FROM payroll_records pr
        JOIN payroll_periods pp ON pr.period_id = pp.period_id
        WHERE pr.employee_id = ? AND pp.status = 'RELEASED'
        ORDER BY pp.pay_period_start DESC
        LIMIT 1
    ");
    $stmt->execute([$empId]);
    $latestPayslip = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── Leave Balance (active school year credit system, or legacy fallback) ──────
$leaveCredits     = [];
$totalAllocated   = 0;
$totalUsed        = 0;
$leaveBalance     = 0;
$usesCreditSystem = false;

if ($empId) {
    $activeSYId = (int)($pdo->query(
        "SELECT COALESCE(school_year_id,0) FROM school_years WHERE is_active=1 LIMIT 1"
    )->fetchColumn());

    if ($activeSYId) {
        $stmt = $pdo->prepare("
            SELECT elc.allocated_days, elc.used_days, lt.leave_name
            FROM employee_leave_credits elc
            JOIN leave_types lt ON elc.leave_type_id = lt.leave_type_id
            WHERE elc.employee_id = ? AND elc.school_year_id = ?
            ORDER BY lt.leave_name
        ");
        $stmt->execute([$empId, $activeSYId]);
        $leaveCredits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($leaveCredits)) {
            $totalAllocated   = array_sum(array_column($leaveCredits, 'allocated_days'));
            $totalUsed        = array_sum(array_column($leaveCredits, 'used_days'));
            $leaveBalance     = $totalAllocated - $totalUsed;
            $usesCreditSystem = true;
        }
    }

    // Legacy fallback
    if (!$usesCreditSystem) {
        $settings = $pdo->query("SELECT default_paid_leave_days FROM payroll_settings LIMIT 1")->fetch();
        $totalAllocated = (float)($settings['default_paid_leave_days'] ?? 30);
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_days), 0)
            FROM leave_requests
            WHERE employee_id = ? AND status = 'APPROVED'
        ");
        $stmt->execute([$empId]);
        $totalUsed    = (float)$stmt->fetchColumn();
        $leaveBalance = $totalAllocated - $totalUsed;
    }
}

// ── Attendance This Month ─────────────────────────────────────────────────────
$attPresent = 0;
$attLate    = 0;
$attHalfDay = 0;

if ($empId) {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(attendance_status = 'PRESENT'),  0) AS present,
            COALESCE(SUM(attendance_status = 'LATE'),     0) AS late,
            COALESCE(SUM(attendance_status = 'HALF_DAY'), 0) AS half_day
        FROM attendance_records
        WHERE employee_id = ?
          AND YEAR(attendance_date)  = YEAR(CURDATE())
          AND MONTH(attendance_date) = MONTH(CURDATE())
    ");
    $stmt->execute([$empId]);
    $attRow     = $stmt->fetch(PDO::FETCH_ASSOC);
    $attPresent = (int)($attRow['present']  ?? 0);
    $attLate    = (int)($attRow['late']     ?? 0);
    $attHalfDay = (int)($attRow['half_day'] ?? 0);
}
$attTotal = $attPresent + $attLate + $attHalfDay;

// ── Active Loans ──────────────────────────────────────────────────────────────
$activeLoans      = [];
$totalOutstanding = 0;

if ($empId) {
    $stmt = $pdo->prepare("
        SELECT el.loan_id, lt.loan_name, el.total_amount,
               el.balance_amount, el.monthly_deduction
        FROM employee_loans el
        JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
        WHERE el.employee_id = ? AND el.status = 'ACTIVE'
        ORDER BY el.start_date DESC
    ");
    $stmt->execute([$empId]);
    $activeLoans      = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalOutstanding = array_sum(array_column($activeLoans, 'balance_amount'));
}

// ── Latest Leave Request ──────────────────────────────────────────────────────
$latestLeave = null;
if ($empId) {
    $stmt = $pdo->prepare("
        SELECT lr.leave_id, lr.created_at, lt.leave_name,
               COUNT(lrd.date_id)                        AS total_dates,
               COALESCE(SUM(lrd.status = 'APPROVED'),  0) AS approved_count,
               COALESCE(SUM(lrd.status = 'PENDING'),   0) AS pending_count,
               COALESCE(SUM(lrd.status = 'REJECTED'),  0) AS rejected_count
        FROM leave_requests lr
        JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
        JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
        WHERE lr.employee_id = ?
        GROUP BY lr.leave_id
        ORDER BY lr.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$empId]);
    $latestLeave = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── Latest Service Credit ─────────────────────────────────────────────────────
$latestSC = null;
if ($empId) {
    $stmt = $pdo->prepare("
        SELECT sc.service_credit_id, sc.status, sc.equivalent_pay, sc.created_at,
               (SELECT COUNT(*) FROM service_credit_dates scd
                WHERE scd.service_credit_id = sc.service_credit_id) AS date_count
        FROM service_credits sc
        WHERE sc.employee_id = ?
        ORDER BY sc.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$empId]);
    $latestSC = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── Page Init ─────────────────────────────────────────────────────────────────
$pageTitle = 'My Dashboard — Employee Portal';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/employee-sidebar.php'; ?>

<div class="main">

  <!-- ── Topbar ────────────────────────────────────────────────────────────── -->
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-house" style="color:#2563eb;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Employee Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;">Employee</div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(
            substr($_SESSION['user']['first_name'] ?? 'E', 0, 1) .
            substr($_SESSION['user']['last_name']  ?? 'M', 0, 1)
        ) ?>
      </div>
    </div>
  </div><!-- .header -->

  <div class="main-content">
  <div class="emp-page">

    <!-- ── Greeting ─────────────────────────────────────────────────────────── -->
    <div class="emp-greeting">
      <div>
        <h1><?= $greeting ?>, <?= htmlspecialchars($_SESSION['user']['first_name'] ?? 'Employee') ?></h1>
        <p><?= date('l, F j, Y') ?></p>
      </div>
      <div class="emp-greeting-pill">
        <i class="fa fa-shield-halved"></i>
        Your personal payroll &amp; leave portal
      </div>
    </div>

    <!-- ── KPI Cards ─────────────────────────────────────────────────────────── -->
    <div class="emp-kpi-row">

      <!-- Latest Net Pay -->
      <div class="emp-kpi-card emp-kpi--blue">
        <div class="emp-kpi-icon"><i class="fa fa-money-bill-wave"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">
            <?= $latestPayslip
                ? '₱' . number_format((float)$latestPayslip['net_pay'], 2)
                : '—' ?>
          </div>
          <div class="emp-kpi-label">Latest Net Pay</div>
          <div class="emp-kpi-sub">
            <?= $latestPayslip
                ? date('M j', strtotime($latestPayslip['pay_period_start'])) . '–' . date('M j, Y', strtotime($latestPayslip['pay_period_end']))
                : 'No released payslip yet' ?>
          </div>
        </div>
      </div>

      <!-- Leave Balance -->
      <div class="emp-kpi-card emp-kpi--green">
        <div class="emp-kpi-icon"><i class="fa fa-calendar-check"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= number_format(max(0, $leaveBalance), 1) ?> <span style="font-size:13px;font-weight:600;">days</span></div>
          <div class="emp-kpi-label">Leave Balance</div>
          <div class="emp-kpi-sub">
            <?= number_format($totalUsed, 1) ?> of <?= number_format($totalAllocated, 1) ?> days used
          </div>
        </div>
      </div>

      <!-- This Month Attendance -->
      <div class="emp-kpi-card emp-kpi--teal">
        <div class="emp-kpi-icon"><i class="fa fa-user-check"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= $attTotal ?> <span style="font-size:13px;font-weight:600;">days</span></div>
          <div class="emp-kpi-label">Present This Month</div>
          <div class="emp-kpi-sub">
            <?= date('F Y') ?> &nbsp;·&nbsp;
            <?= $attLate ?> late<?= $attHalfDay ? ', ' . $attHalfDay . ' half-day' : '' ?>
          </div>
        </div>
      </div>

      <!-- Loan Balance -->
      <div class="emp-kpi-card emp-kpi--amber">
        <div class="emp-kpi-icon"><i class="fa fa-hand-holding-dollar"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">
            <?= $totalOutstanding > 0
                ? '₱' . number_format($totalOutstanding, 2)
                : '—' ?>
          </div>
          <div class="emp-kpi-label">Loan Balance</div>
          <div class="emp-kpi-sub">
            <?= count($activeLoans) > 0
                ? count($activeLoans) . ' active loan' . (count($activeLoans) > 1 ? 's' : '')
                : 'No active loans' ?>
          </div>
        </div>
      </div>

    </div><!-- .emp-kpi-row -->

    <!-- ── Row 1: Latest Payslip + Leave Balance ─────────────────────────────── -->
    <div class="emp-grid-1-1">

      <!-- Latest Payslip Panel -->
      <div class="emp-panel">
        <div class="emp-panel-header">
          <div class="emp-panel-title">
            <i class="fa fa-file-invoice-dollar" style="color:#2563eb;"></i>
            Latest Released Payslip
          </div>
          <?php if ($latestPayslip): ?>
          <span class="emp-panel-meta">
            <?= date('M j', strtotime($latestPayslip['pay_period_start'])) ?>
            –
            <?= date('M j, Y', strtotime($latestPayslip['pay_period_end'])) ?>
          </span>
          <?php endif; ?>
        </div>

        <?php if ($latestPayslip): ?>
        <div class="emp-payslip-grid">
          <div class="emp-pstat">
            <div class="emp-pstat-label">Gross Pay</div>
            <div class="emp-pstat-value">₱<?= number_format((float)$latestPayslip['gross_pay'], 2) ?></div>
          </div>
          <div class="emp-pstat">
            <div class="emp-pstat-label">Total Deductions</div>
            <div class="emp-pstat-value emp-pstat-value--red">₱<?= number_format((float)$latestPayslip['total_deductions'], 2) ?></div>
          </div>
          <div class="emp-pstat" style="grid-column:span 2; background:#eff6ff; border:1px solid #bfdbfe;">
            <div class="emp-pstat-label">Net Pay Received</div>
            <div class="emp-pstat-value emp-pstat-value--blue" style="font-size:18px;">₱<?= number_format((float)$latestPayslip['net_pay'], 2) ?></div>
          </div>
        </div>
        <a class="emp-link-btn" href="<?= BASE_URL ?>modules/employee/payslips/index.php">
          <i class="fa fa-clock-rotate-left"></i> View All Payslips
        </a>

        <?php else: ?>
        <div class="emp-empty">
          <i class="fa fa-file-invoice-dollar"></i>
          <p>No released payslips yet.<br>Your payslip will appear here once payroll is processed.</p>
        </div>
        <?php endif; ?>
      </div><!-- payslip panel -->

      <!-- Leave Balance Panel -->
      <div class="emp-panel">
        <div class="emp-panel-header">
          <div class="emp-panel-title">
            <i class="fa fa-calendar-days" style="color:#059669;"></i>
            Leave Balance
          </div>
          <span class="emp-panel-meta">
            <?= $usesCreditSystem ? 'This school year' : 'Current year' ?>
          </span>
        </div>

        <?php if (!empty($leaveCredits)): ?>
        <div class="emp-leave-list">
          <?php foreach ($leaveCredits as $lc):
              $alloc   = (float)$lc['allocated_days'];
              $used    = (float)$lc['used_days'];
              $remain  = max(0, $alloc - $used);
              $pct     = $alloc > 0 ? min(100, round(($used / $alloc) * 100)) : 0;
              $cls     = $remain <= 0 ? 'zero' : ($remain <= 3 ? 'low' : '');
          ?>
          <div class="emp-leave-row">
            <div class="emp-leave-name"><?= htmlspecialchars($lc['leave_name']) ?></div>
            <div class="emp-leave-used"><?= number_format($used, 1) ?>/<?= number_format($alloc, 1) ?></div>
            <div class="emp-leave-bar-wrap">
              <div class="emp-leave-bar <?= $cls ? 'emp-leave-bar--' . $cls : '' ?>"
                   style="width:<?= $pct ?>%;"></div>
            </div>
            <div class="emp-leave-balance <?= $cls ? 'emp-leave-balance--' . $cls : '' ?>">
              <?= number_format($remain, 1) ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php elseif ($totalAllocated > 0): ?>
        <!-- Legacy single balance display -->
        <div class="emp-leave-row">
          <div class="emp-leave-name">Paid Leave</div>
          <div class="emp-leave-used"><?= number_format($totalUsed, 1) ?>/<?= number_format($totalAllocated, 1) ?></div>
          <?php
            $pct  = $totalAllocated > 0 ? min(100, round(($totalUsed / $totalAllocated) * 100)) : 0;
            $rem  = max(0, $leaveBalance);
            $cls  = $rem <= 0 ? 'zero' : ($rem <= 3 ? 'low' : '');
          ?>
          <div class="emp-leave-bar-wrap">
            <div class="emp-leave-bar <?= $cls ? 'emp-leave-bar--' . $cls : '' ?>"
                 style="width:<?= $pct ?>%;"></div>
          </div>
          <div class="emp-leave-balance <?= $cls ? 'emp-leave-balance--' . $cls : '' ?>">
            <?= number_format($rem, 1) ?>
          </div>
        </div>

        <?php else: ?>
        <div class="emp-empty">
          <i class="fa fa-calendar-xmark"></i>
          <p>Leave credits have not been allocated yet.<br>Contact HR for assistance.</p>
        </div>
        <?php endif; ?>

        <?php if (!empty($leaveCredits) || $totalAllocated > 0): ?>
        <a class="emp-link-btn" href="<?= BASE_URL ?>modules/employee/leave/index.php"
           style="margin-top:12px;">
          <i class="fa fa-calendar-plus"></i> View Leave History
        </a>
        <?php endif; ?>
      </div><!-- leave balance panel -->

    </div><!-- emp-grid-1-1 row 1 -->

    <!-- ── Row 2: Loans + Latest Leave + Latest SC ──────────────────────────── -->
    <div class="emp-grid-1-1">

      <!-- Active Loans Panel -->
      <div class="emp-panel">
        <div class="emp-panel-header">
          <div class="emp-panel-title">
            <i class="fa fa-hand-holding-dollar" style="color:#d97706;"></i>
            Active Loans
          </div>
          <?php if ($totalOutstanding > 0): ?>
          <span class="emp-panel-meta">₱<?= number_format($totalOutstanding, 2) ?> outstanding</span>
          <?php endif; ?>
        </div>

        <?php if (!empty($activeLoans)): ?>
        <?php foreach ($activeLoans as $loan): ?>
        <div class="emp-loan-row">
          <div class="emp-loan-icon"><i class="fa fa-coins"></i></div>
          <div style="flex:1;min-width:0;">
            <div class="emp-loan-name"><?= htmlspecialchars($loan['loan_name']) ?></div>
            <div class="emp-loan-meta">
              ₱<?= number_format((float)$loan['monthly_deduction'], 2) ?>/month deduction
            </div>
          </div>
          <div>
            <div class="emp-loan-balance">₱<?= number_format((float)$loan['balance_amount'], 2) ?></div>
            <div class="emp-loan-balance-label">remaining</div>
          </div>
        </div>
        <?php endforeach; ?>
        <a class="emp-link-btn" href="<?= BASE_URL ?>modules/employee/loans/index.php">
          <i class="fa fa-list"></i> View All Loans
        </a>

        <?php else: ?>
        <div class="emp-empty">
          <i class="fa fa-hand-holding-dollar"></i>
          <p>No active loans at this time.</p>
        </div>
        <?php endif; ?>
      </div><!-- loans panel -->

      <!-- Latest Activity Panel: Leave Request + Service Credit -->
      <div class="emp-panel">
        <div class="emp-panel-header">
          <div class="emp-panel-title">
            <i class="fa fa-clock-rotate-left" style="color:#6366f1;"></i>
            Recent Requests
          </div>
        </div>

        <!-- Latest Leave Request -->
        <?php if ($latestLeave): ?>
        <?php
          $leaveTotal    = (int)$latestLeave['total_dates'];
          $leaveApproved = (int)$latestLeave['approved_count'];
          $leavePending  = (int)$latestLeave['pending_count'];
          $leaveRejected = (int)$latestLeave['rejected_count'];

          if ($leavePending > 0) {
              $leaveStatus = 'pending';
              $leaveLabel  = 'Pending';
          } elseif ($leaveRejected === $leaveTotal) {
              $leaveStatus = 'rejected';
              $leaveLabel  = 'Rejected';
          } elseif ($leaveApproved === $leaveTotal) {
              $leaveStatus = 'approved';
              $leaveLabel  = 'Approved';
          } else {
              $leaveStatus = 'approved';
              $leaveLabel  = 'Partial';
          }
        ?>
        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--border);">
          <div class="emp-item-icon"><i class="fa fa-calendar-days"></i></div>
          <div style="flex:1;min-width:0;">
            <div class="emp-item-title"><?= htmlspecialchars($latestLeave['leave_name']) ?> Leave</div>
            <div class="emp-item-sub">
              <?= $leaveTotal ?> day<?= $leaveTotal !== 1 ? 's' : '' ?> requested
            </div>
            <div style="margin-top:6px;">
              <span class="emp-badge emp-badge--<?= $leaveStatus ?>">
                <i class="fa fa-<?= $leaveStatus === 'approved' ? 'circle-check' : ($leaveStatus === 'pending' ? 'hourglass-half' : 'circle-xmark') ?>"></i>
                <?= $leaveLabel ?>
              </span>
            </div>
            <div class="emp-item-date">Filed <?= date('M j, Y', strtotime($latestLeave['created_at'])) ?></div>
          </div>
        </div>
        <?php else: ?>
        <div style="padding:12px 0 14px;border-bottom:1px solid var(--border);">
          <div style="display:flex;align-items:center;gap:10px;color:var(--text-muted);font-size:12px;">
            <div class="emp-item-icon" style="background:#f1f5f9;color:#94a3b8;">
              <i class="fa fa-calendar-days"></i>
            </div>
            No leave requests filed yet.
          </div>
        </div>
        <?php endif; ?>

        <!-- Latest Service Credit -->
        <?php if ($latestSC): ?>
        <?php
          $scStatus = strtolower($latestSC['status']);
          $scBadge  = match($scStatus) {
              'approved' => 'approved',
              'pending'  => 'pending',
              'rejected' => 'rejected',
              default    => 'pending',
          };
          $scIcon = match($scStatus) {
              'approved' => 'circle-check',
              'rejected' => 'circle-xmark',
              default    => 'hourglass-half',
          };
        ?>
        <div style="display:flex;align-items:flex-start;gap:12px;margin-top:14px;">
          <div class="emp-item-icon" style="background:#ede9fe;color:#6366f1;">
            <i class="fa fa-medal"></i>
          </div>
          <div style="flex:1;min-width:0;">
            <div class="emp-item-title">Service Credit</div>
            <div class="emp-item-sub">
              <?= (int)$latestSC['date_count'] ?> work date<?= (int)$latestSC['date_count'] !== 1 ? 's' : '' ?>
              · ₱<?= number_format((float)$latestSC['equivalent_pay'], 2) ?>
            </div>
            <div style="margin-top:6px;">
              <span class="emp-badge emp-badge--<?= $scBadge ?>">
                <i class="fa fa-<?= $scIcon ?>"></i>
                <?= ucfirst($scStatus) ?>
              </span>
            </div>
            <div class="emp-item-date">Submitted <?= date('M j, Y', strtotime($latestSC['created_at'])) ?></div>
          </div>
        </div>

        <?php else: ?>
        <div style="margin-top:14px;">
          <div style="display:flex;align-items:center;gap:10px;color:var(--text-muted);font-size:12px;">
            <div class="emp-item-icon" style="background:#f1f5f9;color:#94a3b8;">
              <i class="fa fa-medal"></i>
            </div>
            No service credit submissions yet.
          </div>
        </div>
        <?php endif; ?>

        <!-- Attendance quick breakdown -->
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">
          <div style="font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.3px;margin-bottom:10px;">
            <i class="fa fa-user-clock" style="color:#0f766e;margin-right:4px;"></i>
            Attendance — <?= date('F Y') ?>
          </div>
          <div class="emp-att-grid">
            <div class="emp-att-cell">
              <div class="emp-att-dot" style="background:#10b981;"></div>
              <div class="emp-att-num"><?= $attPresent ?></div>
              <div class="emp-att-lbl">Present</div>
            </div>
            <div class="emp-att-cell">
              <div class="emp-att-dot" style="background:#f59e0b;"></div>
              <div class="emp-att-num"><?= $attLate ?></div>
              <div class="emp-att-lbl">Late</div>
            </div>
            <div class="emp-att-cell">
              <div class="emp-att-dot" style="background:#f97316;"></div>
              <div class="emp-att-num"><?= $attHalfDay ?></div>
              <div class="emp-att-lbl">Half Day</div>
            </div>
          </div>
          <a class="emp-link-btn" href="<?= BASE_URL ?>modules/employee/attendance/index.php">
            <i class="fa fa-table-list"></i> Full Attendance Records
          </a>
        </div>

      </div><!-- recent requests panel -->

    </div><!-- emp-grid-1-1 row 2 -->

  </div><!-- .emp-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
