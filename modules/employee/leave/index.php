<?php
/**
 * modules/employee/leave/index.php
 * Employee Portal — Leave Balance & History
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployee();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

// ── Leave Balance (credit system or legacy) ───────────────────────────────────
$leaveCredits     = [];
$totalAllocated   = 0;
$totalUsed        = 0;
$leaveBalance     = 0;
$usesCreditSystem = false;
$activeSYLabel    = '';

if ($empId) {
    $syRow = $pdo->query("SELECT school_year_id, year_name FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
    $activeSYId = $syRow ? (int)$syRow['school_year_id'] : 0;
    $activeSYLabel = $syRow['year_name'] ?? '';

    if ($activeSYId) {
        $stmt = $pdo->prepare("
            SELECT elc.allocated_days, elc.used_days, lt.leave_name, lt.leave_type_id
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

    if (!$usesCreditSystem) {
        $settings = $pdo->query("SELECT default_paid_leave_days FROM payroll_settings LIMIT 1")->fetch();
        $totalAllocated = (float)($settings['default_paid_leave_days'] ?? 30);
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_days),0) FROM leave_requests WHERE employee_id=? AND status='APPROVED'
        ");
        $stmt->execute([$empId]);
        $totalUsed    = (float)$stmt->fetchColumn();
        $leaveBalance = $totalAllocated - $totalUsed;
    }
}

// ── Leave Request History ─────────────────────────────────────────────────────
$history = [];
if ($empId) {
    $stmt = $pdo->prepare("
        SELECT lr.leave_id, lr.created_at, lt.leave_name,
               COUNT(lrd.date_id)                        AS total_dates,
               MIN(lrd.leave_date)                       AS first_date,
               MAX(lrd.leave_date)                       AS last_date,
               COALESCE(SUM(lrd.status = 'APPROVED'),  0) AS approved,
               COALESCE(SUM(lrd.status = 'PENDING'),   0) AS pending,
               COALESCE(SUM(lrd.status = 'REJECTED'),  0) AS rejected
        FROM leave_requests lr
        JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
        JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
        WHERE lr.employee_id = ?
        GROUP BY lr.leave_id
        ORDER BY lr.created_at DESC
    ");
    $stmt->execute([$empId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Leave Balance & History — Employee Portal';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/employee-sidebar.php'; ?>

<div class="main">
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-calendar-days" style="color:#2563eb;font-size:18px;"></i>
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
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'E', 0, 1) . substr($_SESSION['user']['last_name'] ?? 'M', 0, 1)) ?>
      </div>
    </div>
  </div>

  <div class="main-content">
  <div class="emp-page">

    <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 4px;">Leave Balance &amp; History</h1>
    <p style="font-size:12px;color:#94a3b8;margin:0 0 20px;">
      <?= $usesCreditSystem && $activeSYLabel
          ? 'Credits for school year: <strong>' . htmlspecialchars($activeSYLabel) . '</strong>'
          : 'Leave allocation for current period' ?>
    </p>

    <!-- ── Balance Section ─────────────────────────────────────────────────── -->
    <?php if (!empty($leaveCredits) || $totalAllocated > 0): ?>
    <div class="emp-panel" style="margin-bottom:16px;">
      <div class="emp-panel-header">
        <div class="emp-panel-title">
          <i class="fa fa-calendar-check" style="color:#059669;"></i>
          Leave Credits Balance
        </div>
        <div style="font-size:12px;font-weight:700;color:#374151;">
          <?= number_format(max(0, $leaveBalance), 1) ?> day<?= $leaveBalance != 1 ? 's' : '' ?> remaining
        </div>
      </div>

      <?php if (!empty($leaveCredits)): ?>
      <div class="emp-leave-list">
        <?php foreach ($leaveCredits as $lc):
            $alloc  = (float)$lc['allocated_days'];
            $used   = (float)$lc['used_days'];
            $remain = max(0, $alloc - $used);
            $pct    = $alloc > 0 ? min(100, round(($used / $alloc) * 100)) : 0;
            $cls    = $remain <= 0 ? 'zero' : ($remain <= 3 ? 'low' : '');
        ?>
        <div class="emp-leave-row">
          <div class="emp-leave-name"><?= htmlspecialchars($lc['leave_name']) ?></div>
          <div class="emp-leave-used" style="white-space:nowrap;">
            <?= number_format($used, 1) ?> used / <?= number_format($alloc, 1) ?> alloc.
          </div>
          <div class="emp-leave-bar-wrap" style="width:80px;">
            <div class="emp-leave-bar <?= $cls ? 'emp-leave-bar--' . $cls : '' ?>"
                 style="width:<?= $pct ?>%;"></div>
          </div>
          <div class="emp-leave-balance <?= $cls ? 'emp-leave-balance--' . $cls : '' ?>">
            <?= number_format($remain, 1) ?> left
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php else: ?>
      <!-- Legacy single-pool balance -->
      <?php
        $pct    = $totalAllocated > 0 ? min(100, round(($totalUsed / $totalAllocated) * 100)) : 0;
        $remain = max(0, $leaveBalance);
        $cls    = $remain <= 0 ? 'zero' : ($remain <= 3 ? 'low' : '');
      ?>
      <div class="emp-leave-row">
        <div class="emp-leave-name">Paid Leave</div>
        <div class="emp-leave-used"><?= number_format($totalUsed, 1) ?> used / <?= number_format($totalAllocated, 1) ?> alloc.</div>
        <div class="emp-leave-bar-wrap" style="width:80px;">
          <div class="emp-leave-bar <?= $cls ? 'emp-leave-bar--' . $cls : '' ?>"
               style="width:<?= $pct ?>%;"></div>
        </div>
        <div class="emp-leave-balance <?= $cls ? 'emp-leave-balance--' . $cls : '' ?>">
          <?= number_format($remain, 1) ?> left
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="emp-panel" style="margin-bottom:16px;">
      <div class="emp-empty">
        <i class="fa fa-calendar-xmark"></i>
        <p>Leave credits have not been allocated yet. Contact HR Admin.</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Leave Request History ─────────────────────────────────────────── -->
    <div class="emp-panel" style="padding:0;overflow:hidden;">
      <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;">
        <i class="fa fa-clock-rotate-left" style="color:#6366f1;"></i>
        <span style="font-size:13px;font-weight:700;color:#0f172a;">Leave Request History</span>
        <span style="margin-left:auto;font-size:11px;color:#94a3b8;"><?= count($history) ?> request<?= count($history) !== 1 ? 's' : '' ?></span>
      </div>

      <?php if (!empty($history)): ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
            <th style="padding:11px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Leave Type</th>
            <th style="padding:11px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Date Range</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Days</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Status</th>
            <th style="padding:11px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Filed</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $hr):
            $total    = (int)$hr['total_dates'];
            $approved = (int)$hr['approved'];
            $pending  = (int)$hr['pending'];
            $rejected = (int)$hr['rejected'];

            if ($pending > 0) {
                $statusKey = 'pending';
                $statusText = 'Pending (' . $pending . ')';
            } elseif ($approved === $total) {
                $statusKey  = 'approved';
                $statusText = 'Approved';
            } elseif ($rejected === $total) {
                $statusKey  = 'rejected';
                $statusText = 'Rejected';
            } else {
                $statusKey  = 'approved';
                $statusText = 'Partial (' . $approved . '/' . $total . ')';
            }

            $dateRange = $hr['first_date'] === $hr['last_date']
                ? date('M j, Y', strtotime($hr['first_date']))
                : date('M j', strtotime($hr['first_date'])) . ' – ' . date('M j, Y', strtotime($hr['last_date']));
        ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:13px 16px;font-weight:600;color:#0f172a;">
            <?= htmlspecialchars($hr['leave_name']) ?>
          </td>
          <td style="padding:13px 16px;color:#374151;"><?= htmlspecialchars($dateRange) ?></td>
          <td style="padding:13px 16px;text-align:center;font-weight:700;color:#374151;">
            <?= $total ?>
          </td>
          <td style="padding:13px 16px;text-align:center;">
            <span class="emp-badge emp-badge--<?= $statusKey ?>"><?= htmlspecialchars($statusText) ?></span>
          </td>
          <td style="padding:13px 16px;color:#64748b;font-size:12px;">
            <?= date('M j, Y', strtotime($hr['created_at'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php else: ?>
      <div class="emp-empty" style="padding:50px 20px;">
        <i class="fa fa-calendar-days"></i>
        <p>No leave requests on file. Contact HR Admin to file leave on your behalf.</p>
      </div>
      <?php endif; ?>
    </div>

  </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
