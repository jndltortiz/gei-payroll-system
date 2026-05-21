<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireHR();

// TODAY'S DATE — defined once at top so all queries below can use it
$today = date('Y-m-d');

// ── Stat card queries ──
$totalEmployees = $pdo->query("SELECT COUNT(*) FROM employees WHERE employee_status = 'ACTIVE'")->fetchColumn();

$payrolls = $pdo->query("
    SELECT 
        e.first_name,
        e.last_name,
        d.department_name,
        pr.net_pay,
        pr.payroll_status
    FROM payroll_records pr
    JOIN employees e ON pr.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    ORDER BY pr.created_at DESC
    LIMIT 5
")->fetchAll();

$pendingLeaves = $pdo->query("
    SELECT COUNT(*) 
    FROM leave_requests 
    WHERE status = 'PENDING'
")->fetchColumn();

// ── Today's attendance ──
$presentEmployees = $pdo->prepare("
    SELECT e.first_name, e.last_name
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    WHERE ar.attendance_date = :today
    AND ar.attendance_status = 'PRESENT'
");
$presentEmployees->execute([':today' => $today]);
$presentEmployees = $presentEmployees->fetchAll();

$lateEmployees = $pdo->prepare("
    SELECT e.first_name, e.last_name
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    WHERE ar.attendance_date = :today
    AND ar.attendance_status = 'LATE'
");
$lateEmployees->execute([':today' => $today]);
$lateEmployees = $lateEmployees->fetchAll();

// ── FIX: fetch HALF_DAY employees (were previously invisible everywhere) ──
$halfDayEmployees = $pdo->prepare("
    SELECT e.first_name, e.last_name
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    WHERE ar.attendance_date = :today
    AND ar.attendance_status = 'HALF_DAY'
");
$halfDayEmployees->execute([':today' => $today]);
$halfDayEmployees = $halfDayEmployees->fetchAll();

// Absent = active employees with NO attendance record today at all
$absentEmployees = $pdo->prepare("
    SELECT e.first_name, e.last_name
    FROM employees e
    WHERE e.employee_status = 'ACTIVE'
    AND e.employee_id NOT IN (
        SELECT employee_id 
        FROM attendance_records 
        WHERE attendance_date = :today
    )
");
$absentEmployees->execute([':today' => $today]);
$absentEmployees = $absentEmployees->fetchAll();

// ── Derived counts ──
$totalEmployeesCount = $totalEmployees;
$presentCount  = count($presentEmployees);
$lateCount     = count($lateEmployees);
$halfDayCount  = count($halfDayEmployees);   // ← NEW
$absentCount   = count($absentEmployees);

// ── FIX: attendance rate counts PRESENT + LATE + HALF_DAY as "attended" ──
$presentToday = $presentCount + $lateCount + $halfDayCount;

// New employees this month
$newThisMonth = $pdo->prepare("
    SELECT COUNT(*) FROM employees 
    WHERE MONTH(hire_date) = MONTH(CURDATE()) 
    AND YEAR(hire_date) = YEAR(CURDATE())
");
$newThisMonth->execute();
$newThisMonth = (int)$newThisMonth->fetchColumn();

// ── Attendance rate ──
$attendanceRate = $totalEmployees > 0 
    ? round(($presentToday / $totalEmployees) * 100) 
    : 0;

$totalPayroll = $pdo->query("SELECT SUM(net_pay) FROM payroll_records")->fetchColumn();

// ── FIX: weekly chart also counts HALF_DAY as attended ──
$weeklyAttendance = $pdo->query("
    SELECT
        DATE(attendance_date) as date,
        SUM(attendance_status IN ('PRESENT','LATE','HALF_DAY')) as present_count,
        SUM(attendance_status = 'ABSENT') as absent_count
    FROM attendance_records
    WHERE attendance_date >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(attendance_date)
    ORDER BY date ASC
")->fetchAll();

$labels = [];
$presentData = [];
$absentData = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('M d', strtotime($date));

    $found = false;
    foreach ($weeklyAttendance as $row) {
        if ($row['date'] === $date) {
            $presentData[] = (int)$row['present_count'];
            $absentData[]  = (int)$row['absent_count'];
            $found = true;
            break;
        }
    }

    if (!$found) {
        $presentData[] = 0;
        $absentData[]  = 0;
    }
}

$activities = [];

// Attendance activity
$attendanceLogs = $pdo->query("
    SELECT e.first_name, e.last_name, ar.attendance_date
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    ORDER BY ar.attendance_date DESC
    LIMIT 3
")->fetchAll();

foreach ($attendanceLogs as $log) {
    $activities[] = [
        'text' => $log['first_name'] . ' ' . $log['last_name'] . ' checked in',
        'time' => $log['attendance_date']
    ];
}

// Leave activity
$leaveLogs = $pdo->query("
    SELECT e.first_name, e.last_name, lr.created_at
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.employee_id
    ORDER BY lr.created_at DESC
    LIMIT 2
")->fetchAll();

foreach ($leaveLogs as $log) {
    $activities[] = [
        'text' => $log['first_name'] . ' ' . $log['last_name'] . ' filed leave',
        'time' => $log['created_at']
    ];
}
?>
<?php
$pageTitle = 'Dashboard';
$extraCSS  = [BASE_URL . 'assets/css/dashboard.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<!-- Chart.js loaded here because it's only needed by the dashboard -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<body>

<!-- ═══ SIDEBAR ═══ -->
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>

<!-- ═══ MAIN ═══ -->
<div class="main">

<!-- HEADER -->
<?php include __DIR__ . '/../../includes/header.php'; ?>

  <!-- CONTENT -->
  <div class="content">

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <div class="page-title">Dashboard Overview</div>
        <div class="page-date">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Today, <span id="todayDate"></span>
        </div>
      </div>
    </div>

    <!-- Stat Cards -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-header">
          <div class="stat-label">Total Employees</div>
          <div class="stat-icon teal">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
        </div>
        <div class="stat-value"><?php echo $totalEmployees; ?></div>
        <div class="stat-sub <?= $newThisMonth > 0 ? 'positive' : 'neutral' ?>">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
          <?= $newThisMonth > 0 ? "+$newThisMonth this month" : "No new hires this month" ?>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-header">
          <div class="stat-label">Attendance Rate</div>
          <div class="stat-icon blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </div>
        </div>
        <div class="stat-value"><?php echo $attendanceRate; ?>%</div>
        <div class="stat-sub neutral">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          Present Today: <?php echo $presentToday; ?>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-header">
          <div class="stat-label">Pending Leave Requests</div>
          <div class="stat-icon yellow">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          </div>
        </div>
        <div class="stat-value"><?php echo $pendingLeaves; ?></div>
        <div class="stat-sub warning">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Needs approval
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-header">
          <div class="stat-label">Payroll Processed</div>
          <div class="stat-icon green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          </div>
        </div>
        <div class="stat-value">₱<?php echo number_format($totalPayroll ?? 0, 2); ?></div>
        <div class="stat-sub positive">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
          For <?= date('M') ?> <?= date('d') <= 15 ? '1–15' : '16–' . date('t') ?> period
        </div>
      </div>
    </div>

    <!-- Charts Row -->
    <div class="charts-row">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Weekly Attendance Trend</div>
          <div class="chart-legend">
            <div class="legend-item"><div class="legend-dot" style="background:var(--accent)"></div> Present</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--red)"></div> Absent</div>
          </div>
        </div>
        <div style="height:220px;">
          <canvas id="attendanceChart"></canvas>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Recent Activity</div>
        </div>
        <div class="activity-list">
            <?php foreach ($activities as $act): ?>
            <div class="activity-item">
                <div class="activity-dot green"></div>
                <div class="activity-text">
                <strong><?php echo $act['text']; ?></strong>
                <span><?php echo date('M d, Y', strtotime($act['time'])); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Bottom Row -->
    <div class="bottom-row">

      <!-- Payroll Summary Table -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Recent Payroll Disbursements</div>
          <span class="badge processing">
              <?= date('F Y') ?>
          </span>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Net Pay</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
                <?php foreach ($payrolls as $pay): ?>
                <tr>
                    <td><?php echo $pay['first_name'] . ' ' . $pay['last_name']; ?></td>
                    <td><?php echo $pay['department_name']; ?></td>
                    <td>₱<?php echo number_format($pay['net_pay'], 2); ?></td>
                    <td>
                        <span class="badge">
                            <?php echo $pay['payroll_status']; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Employees Present Today -->
      <div class="card">
        <div class="card-header">
          <div style="display:flex; align-items:center; gap:10px;">
            <div class="card-title">Employees Present Today</div>
            <div class="live-badge">Live</div>
          </div>
        </div>

        <!-- FIX: presence stats now includes Half Day pill -->
        <div class="presence-stats">
          <div class="pstat"><div class="pstat-dot" style="background:var(--green)"></div> <?php echo $presentCount; ?> Present</div>
          <div class="pstat"><div class="pstat-dot" style="background:var(--yellow)"></div> <?php echo $lateCount; ?> Late</div>
          <div class="pstat"><div class="pstat-dot" style="background:#f97316"></div> <?php echo $halfDayCount; ?> Half Day</div>
          <div class="pstat"><div class="pstat-dot" style="background:var(--red)"></div> <?php echo $absentCount; ?> Absent</div>
        </div>

        <!-- FIX: filter tabs now includes Half Day tab -->
        <div class="presence-tabs">
          <button class="presence-tab active" onclick="filterPresence('all', this)">All <strong><?php echo $totalEmployeesCount; ?></strong></button>
          <button class="presence-tab" onclick="filterPresence('present', this)">Present <strong><?php echo $presentCount; ?></strong></button>
          <button class="presence-tab" onclick="filterPresence('late', this)">Late <strong><?php echo $lateCount; ?></strong></button>
          <button class="presence-tab" onclick="filterPresence('half_day', this)">Half Day <strong><?php echo $halfDayCount; ?></strong></button>
          <button class="presence-tab" onclick="filterPresence('absent', this)">Absent <strong><?php echo $absentCount; ?></strong></button>
        </div>

        <div class="presence-grid" id="presenceGrid">
          <?php foreach ($presentEmployees as $emp): ?>
            <div class="presence-item" data-status="present">
                <span class="presence-name present">
                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                </span>
            </div>
          <?php endforeach; ?>

          <?php foreach ($lateEmployees as $emp): ?>
            <div class="presence-item" data-status="late">
                <span class="presence-name late">
                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                </span>
            </div>
          <?php endforeach; ?>

          <!-- FIX: half-day employees now rendered in the grid -->
          <?php foreach ($halfDayEmployees as $emp): ?>
            <div class="presence-item" data-status="half_day">
                <span class="presence-name half_day" style="color:#f97316;">
                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                </span>
            </div>
          <?php endforeach; ?>

          <?php foreach ($absentEmployees as $emp): ?>
            <div class="presence-item" data-status="absent">
                <span class="presence-name absent">
                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <script>
      const labels = <?= json_encode($labels); ?>;
      const presentData = <?= json_encode($presentData); ?>;
      const absentData = <?= json_encode($absentData); ?>;
      </script>

    </div>
  </div>
</div>
<script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>