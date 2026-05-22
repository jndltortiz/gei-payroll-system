<?php
/**
 * modules/employee/attendance/index.php
 * Employee Portal — My Attendance Records
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployee();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

// Month / Year filter
$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
$month = max(1, min(12, $month));
$year  = max(2020, min((int)date('Y') + 1, $year));

$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = date('Y-m-t', strtotime($monthStart));

// Attendance records for this month
$stmt = $pdo->prepare("
    SELECT ar.attendance_date, ar.attendance_status,
           ar.time_in, ar.time_out,
           ar.late_minutes, ar.undertime_minutes
    FROM attendance_records ar
    WHERE ar.employee_id = ?
      AND ar.attendance_date BETWEEN ? AND ?
    ORDER BY ar.attendance_date ASC
");
$stmt->execute([$empId, $monthStart, $monthEnd]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Index by date for easy lookup
$byDate = [];
foreach ($records as $r) {
    $byDate[$r['attendance_date']] = $r;
}

// Monthly summary
$summary = ['PRESENT' => 0, 'LATE' => 0, 'HALF_DAY' => 0];
foreach ($records as $r) {
    $s = $r['attendance_status'];
    if (isset($summary[$s])) $summary[$s]++;
}
$totalPresent = $summary['PRESENT'] + $summary['LATE'] + $summary['HALF_DAY'];

// Available months for the dropdown (months that have at least one record)
$availMonths = $pdo->prepare("
    SELECT DISTINCT YEAR(attendance_date) AS yr, MONTH(attendance_date) AS mo
    FROM attendance_records
    WHERE employee_id = ?
    ORDER BY yr DESC, mo DESC
    LIMIT 24
");
$availMonths->execute([$empId]);
$monthOptions = $availMonths->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Attendance Records — Employee Portal';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css'];
require_once __DIR__ . '/../../../includes/head.php';

$statusLabel = ['PRESENT' => 'Present', 'LATE' => 'Late', 'HALF_DAY' => 'Half Day'];
$statusColor = [
    'PRESENT'  => '#059669',
    'LATE'     => '#d97706',
    'HALF_DAY' => '#f97316',
];
$statusBg = [
    'PRESENT'  => '#d1fae5',
    'LATE'     => '#fef3c7',
    'HALF_DAY' => '#ffedd5',
];
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/employee-sidebar.php'; ?>

<div class="main">
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-user-clock" style="color:#2563eb;font-size:18px;"></i>
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

    <!-- Header + Filter -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
      <div>
        <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 2px;">Attendance Records</h1>
        <p style="font-size:12px;color:#94a3b8;margin:0;">Your daily attendance log.</p>
      </div>
      <form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <select name="month"
                style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);font-size:13px;background:#fff;">
          <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
            <?= date('F', mktime(0,0,0,$m,1)) ?>
          </option>
          <?php endfor; ?>
        </select>
        <select name="year"
                style="padding:7px 12px;border-radius:8px;border:1px solid var(--border);font-size:13px;background:#fff;">
          <?php for ($y = (int)date('Y'); $y >= 2022; $y--): ?>
          <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
        <button type="submit"
                style="padding:7px 16px;border-radius:8px;border:none;background:#2563eb;color:#fff;font-size:13px;font-weight:600;cursor:pointer;">
          <i class="fa fa-filter"></i> Filter
        </button>
      </form>
    </div>

    <!-- Monthly Summary Cards -->
    <div class="emp-kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px;">
      <div class="emp-kpi-card emp-kpi--green">
        <div class="emp-kpi-icon"><i class="fa fa-circle-check"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= $summary['PRESENT'] ?></div>
          <div class="emp-kpi-label">Present</div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--amber">
        <div class="emp-kpi-icon"><i class="fa fa-clock"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= $summary['LATE'] ?></div>
          <div class="emp-kpi-label">Late Arrivals</div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--purple">
        <div class="emp-kpi-icon"><i class="fa fa-circle-half-stroke"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= $summary['HALF_DAY'] ?></div>
          <div class="emp-kpi-label">Half Days</div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--blue">
        <div class="emp-kpi-icon"><i class="fa fa-calendar-check"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= $totalPresent ?></div>
          <div class="emp-kpi-label">Total Days</div>
          <div class="emp-kpi-sub"><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></div>
        </div>
      </div>
    </div>

    <!-- Attendance Table -->
    <div class="emp-panel" style="padding:0;overflow:hidden;">
      <?php if (!empty($records)): ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
            <th style="padding:11px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Date</th>
            <th style="padding:11px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Day</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Status</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Time In</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Time Out</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Late (min)</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $rec):
            $dt     = strtotime($rec['attendance_date']);
            $status = $rec['attendance_status'];
            $color  = $statusColor[$status] ?? '#64748b';
            $bg     = $statusBg[$status]    ?? '#f1f5f9';
            $label  = $statusLabel[$status] ?? $status;
        ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:13px 16px;font-weight:600;color:#0f172a;">
            <?= date('M j, Y', $dt) ?>
          </td>
          <td style="padding:13px 16px;color:#64748b;"><?= date('D', $dt) ?></td>
          <td style="padding:13px 16px;text-align:center;">
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;background:<?= $bg ?>;color:<?= $color ?>;">
              <?= htmlspecialchars($label) ?>
            </span>
          </td>
          <td style="padding:13px 16px;text-align:center;color:#374151;">
            <?= $rec['time_in'] ? date('h:i A', strtotime($rec['time_in'])) : '—' ?>
          </td>
          <td style="padding:13px 16px;text-align:center;color:#374151;">
            <?= $rec['time_out'] ? date('h:i A', strtotime($rec['time_out'])) : '—' ?>
          </td>
          <td style="padding:13px 16px;text-align:center;">
            <?php if ((int)$rec['late_minutes'] > 0): ?>
            <span style="color:#d97706;font-weight:600;"><?= (int)$rec['late_minutes'] ?></span>
            <?php else: ?>
            <span style="color:#94a3b8;">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php else: ?>
      <div class="emp-empty" style="padding:60px 20px;">
        <i class="fa fa-calendar-xmark"></i>
        <p>No attendance records found for <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>.</p>
      </div>
      <?php endif; ?>
    </div>

  </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
