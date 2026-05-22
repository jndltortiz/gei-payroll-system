<?php
/**
 * modules/principal/attendance/index.php
 * Principal Portal — Attendance Monitor (read-only)
 * Principal may view and filter; no add/edit/delete actions exposed.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requirePrincipal();

$today = date('Y-m-d');
$tab   = $_GET['tab'] ?? 'today';

// ── Today's summary stats (always anchored to TODAY, not the date filter) ─────
$statStmt = $pdo->prepare("
    SELECT
        SUM(attendance_status = 'PRESENT')  AS present_cnt,
        SUM(attendance_status = 'LATE')     AS late_cnt,
        SUM(attendance_status = 'HALF_DAY') AS halfday_cnt,
        SUM(attendance_status = 'ABSENT')   AS absent_cnt,
        SUM(attendance_status = 'LEAVE')    AS leave_cnt
    FROM attendance_records
    WHERE attendance_date = :today
");
$statStmt->execute([':today' => $today]);
$stats = $statStmt->fetch(PDO::FETCH_ASSOC);

$presentCount  = (int)($stats['present_cnt']  ?? 0);
$lateCount     = (int)($stats['late_cnt']     ?? 0);
$halfDayCount  = (int)($stats['halfday_cnt']  ?? 0);
$absentCount   = (int)($stats['absent_cnt']   ?? 0);
$leaveCount    = (int)($stats['leave_cnt']    ?? 0);

$totalActive = (int)$pdo->query("
    SELECT COUNT(*) FROM employees WHERE employee_status = 'ACTIVE'
")->fetchColumn();

$attendedToday  = $presentCount + $lateCount + $halfDayCount;
$attendanceRate = $totalActive > 0 ? round(($attendedToday / $totalActive) * 100) : 0;

// ── Departments & Shifts for filters ─────────────────────────────────────────
$depts  = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$shifts = $pdo->query("SELECT shift_id, shift_name FROM shifts WHERE is_active = 1 ORDER BY shift_name")->fetchAll();

// ── TODAY TAB ─────────────────────────────────────────────────────────────────
$filterDate   = $_GET['date']   ?? $today;
$filterStatus = $_GET['status'] ?? '';
$filterDept   = (int)($_GET['dept'] ?? 0);
$search       = trim($_GET['search'] ?? '');

$limit  = 15;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Build WHERE for today tab
$where  = "WHERE ar.attendance_date = :adate";
$params = [':adate' => $filterDate];

if ($search !== '') {
    $where   .= " AND (e.first_name LIKE :s OR e.last_name LIKE :s OR CONCAT(e.first_name,' ',e.last_name) LIKE :s)";
    $params[':s'] = '%' . $search . '%';
}
if ($filterStatus !== '') {
    $where   .= " AND ar.attendance_status = :st";
    $params[':st'] = $filterStatus;
}
if ($filterDept > 0) {
    $where   .= " AND e.department_id = :dept";
    $params[':dept'] = $filterDept;
}

// Count
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    $where
");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages   = max(1, ceil($totalRecords / $limit));

// Records with shift JOIN + computed late/undertime
$recStmt = $pdo->prepare("
    SELECT
        ar.attendance_id,
        ar.attendance_date,
        ar.time_in,
        ar.time_out,
        ar.attendance_status,
        ar.attendance_source,
        ar.remarks,
        e.first_name,
        e.last_name,
        d.department_name,
        p.position_name,
        s.shift_name,
        s.start_time  AS shift_start,
        s.end_time    AS shift_end,
        CASE
            WHEN ar.attendance_status IN ('LATE','HALF_DAY')
             AND ar.time_in IS NOT NULL
             AND s.start_time IS NOT NULL
            THEN GREATEST(0,
                TIMESTAMPDIFF(MINUTE,
                    CONCAT(ar.attendance_date, ' ', s.start_time),
                    CONCAT(ar.attendance_date, ' ', ar.time_in)))
            ELSE 0
        END AS late_minutes,
        CASE
            WHEN ar.time_out IS NOT NULL
             AND s.end_time IS NOT NULL
             AND TIME(ar.time_out) < s.end_time
            THEN GREATEST(0,
                TIMESTAMPDIFF(MINUTE,
                    CONCAT(ar.attendance_date, ' ', ar.time_out),
                    CONCAT(ar.attendance_date, ' ', s.end_time)))
            ELSE 0
        END AS undertime_minutes
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN shifts      s ON e.shift_id      = s.shift_id
    $where
    ORDER BY e.last_name ASC, e.first_name ASC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
    $recStmt->bindValue($k, $v);
}
$recStmt->bindValue(':lim',  $limit,  PDO::PARAM_INT);
$recStmt->bindValue(':off',  $offset, PDO::PARAM_INT);
$recStmt->execute();
$rows = $recStmt->fetchAll(PDO::FETCH_ASSOC);

// ── CUTOFF TAB ────────────────────────────────────────────────────────────────
$cutoffPeriod = $_GET['cutoff'] ?? '';
$cutoffDept   = $_GET['cdept']  ?? '';
$cutoffSearch = trim($_GET['csearch'] ?? '');

// Build 6-month period options (same as admin)
$cutoffOptions = [];
for ($i = 0; $i < 6; $i++) {
    $ts   = strtotime("-$i months");
    $yr   = date('Y', $ts);
    $mo   = date('m', $ts);
    $last = date('t', mktime(0, 0, 0, $mo, 1, $yr));
    $cutoffOptions[] = ['label' => date('M', $ts) . " 1–15, $yr",      'val' => "$yr-$mo-01|$yr-$mo-15"];
    $cutoffOptions[] = ['label' => date('M', $ts) . " 16–$last, $yr",  'val' => "$yr-$mo-16|$yr-$mo-$last"];
}

if ($cutoffPeriod === '') {
    $day = (int)date('d');
    $cutoffPeriod = $day <= 15
        ? date('Y-m') . '-01|' . date('Y-m') . '-15'
        : date('Y-m') . '-16|' . date('Y-m') . '-' . date('t');
}
[$cutoffStart, $cutoffEnd] = explode('|', $cutoffPeriod) + [date('Y-m-01'), date('Y-m-15')];

// Working-day count for the cutoff window
$workingDays = 0;
$d = strtotime($cutoffStart);
while ($d <= strtotime($cutoffEnd)) {
    if (date('N', $d) < 7) $workingDays++;
    $d = strtotime('+1 day', $d);
}

// Cutoff aggregate stats
$ctWhere  = "WHERE ar.attendance_date BETWEEN :cs AND :ce";
$ctParams = [':cs' => $cutoffStart, ':ce' => $cutoffEnd];
if ($cutoffDept !== '') {
    $ctWhere   .= " AND e.department_id = :cd";
    $ctParams[':cd'] = $cutoffDept;
}
if ($cutoffSearch !== '') {
    $ctWhere   .= " AND (e.first_name LIKE :csr OR e.last_name LIKE :csr)";
    $ctParams[':csr'] = '%' . $cutoffSearch . '%';
}

$ctStmt = $pdo->prepare("
    SELECT
        SUM(ar.attendance_status = 'PRESENT')  AS tp,
        SUM(ar.attendance_status = 'LATE')     AS tl,
        SUM(ar.attendance_status = 'HALF_DAY') AS thd,
        SUM(ar.attendance_status = 'ABSENT')   AS ta,
        COUNT(DISTINCT ar.employee_id)         AS te
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    $ctWhere
");
$ctStmt->execute($ctParams);
$ct = $ctStmt->fetch(PDO::FETCH_ASSOC);

$avgRate = ($ct['te'] > 0 && $workingDays > 0)
    ? round((($ct['tp'] + $ct['tl'] + $ct['thd']) / ($ct['te'] * $workingDays)) * 100)
    : 0;

// Per-employee summary for cutoff
$empStmt = $pdo->prepare("
    SELECT
        e.employee_id,
        e.first_name,
        e.last_name,
        d.department_name,
        s.shift_name,
        SUM(ar.attendance_status = 'PRESENT')  AS ep,
        SUM(ar.attendance_status = 'LATE')     AS el,
        SUM(ar.attendance_status = 'HALF_DAY') AS ehd,
        SUM(ar.attendance_status = 'ABSENT')   AS ea,
        SUM(ar.attendance_status = 'LEAVE')    AS ev
    FROM employees e
    LEFT JOIN attendance_records ar
        ON e.employee_id = ar.employee_id
        AND ar.attendance_date BETWEEN :cs2 AND :ce2
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN shifts      s ON e.shift_id      = s.shift_id
    WHERE e.employee_status = 'ACTIVE'
    GROUP BY e.employee_id
    ORDER BY e.last_name ASC, e.first_name ASC
");

$empBindParams = [':cs2' => $cutoffStart, ':ce2' => $cutoffEnd];
if ($cutoffDept !== '') {
    $empStmt = $pdo->prepare("
        SELECT
            e.employee_id,
            e.first_name,
            e.last_name,
            d.department_name,
            s.shift_name,
            SUM(ar.attendance_status = 'PRESENT')  AS ep,
            SUM(ar.attendance_status = 'LATE')     AS el,
            SUM(ar.attendance_status = 'HALF_DAY') AS ehd,
            SUM(ar.attendance_status = 'ABSENT')   AS ea,
            SUM(ar.attendance_status = 'LEAVE')    AS ev
        FROM employees e
        LEFT JOIN attendance_records ar
            ON e.employee_id = ar.employee_id
            AND ar.attendance_date BETWEEN :cs2 AND :ce2
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN shifts      s ON e.shift_id      = s.shift_id
        WHERE e.employee_status = 'ACTIVE'
          AND e.department_id = :cd2
        GROUP BY e.employee_id
        ORDER BY e.last_name ASC, e.first_name ASC
    ");
    $empBindParams[':cd2'] = $cutoffDept;
}
$empStmt->execute($empBindParams);
$empRows = $empStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Status labels & classes ───────────────────────────────────────────────────
$STATUS_LABELS = [
    'PRESENT'    => 'Present',
    'LATE'       => 'Late',
    'HALF_DAY'   => 'Half Day',
    'ABSENT'     => 'Absent',
    'LEAVE'      => 'On Leave',
    'INCOMPLETE' => 'Incomplete',
    'HOLIDAY'    => 'Holiday',
];

// ── Page Setup ────────────────────────────────────────────────────────────────
$pageTitle = 'Attendance Monitor — Principal Portal';
$extraCSS  = [
    BASE_URL . 'assets/css/principal.css',
    BASE_URL . 'assets/css/attendance.css',
];
require_once __DIR__ . '/../../../includes/head.php';
?>
<style>
/* ── Principal Attendance overrides ── */
.patt-readonly-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 99px;
    background: #f1f5f9; border: 1px solid #e2e8f0;
    font-size: 11px; font-weight: 600; color: #64748b;
}
/* 5-column stat grid override */
.att-summary-cards { grid-template-columns: repeat(5, 1fr); }
@media (max-width: 1100px) { .att-summary-cards { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 680px)  { .att-summary-cards { grid-template-columns: repeat(2, 1fr); } }

/* Attendance rate bar in page header */
.patt-rate-bar-wrap {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px; background: #f0fdfa;
    border: 1px solid #ccfbf1; border-radius: 10px;
    font-size: 13px;
}
.patt-rate-bar {
    flex: 1; height: 8px; background: #e2e8f0;
    border-radius: 99px; overflow: hidden; max-width: 180px;
}
.patt-rate-fill { height: 100%; border-radius: 99px; background: #0f766e; }
.patt-rate-val  { font-size: 16px; font-weight: 800; color: #0f766e; white-space: nowrap; }

/* Viewing non-today notice */
.patt-date-notice {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px; margin-bottom: 12px;
    background: #fefce8; border: 1px solid #fde68a;
    border-radius: 8px; font-size: 12px; color: #92400e; font-weight: 500;
}

/* Shift tag */
.att-shift-tag {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; color: #6366f1; font-weight: 500;
}

/* Late / undertime chips */
.patt-late-chip {
    display: inline-block; padding: 2px 8px;
    border-radius: 99px; font-size: 10px; font-weight: 700;
    background: #fef3c7; color: #92400e; white-space: nowrap;
}
.patt-undertime-chip {
    display: inline-block; padding: 2px 8px;
    border-radius: 99px; font-size: 10px; font-weight: 700;
    background: #f1f5f9; color: #64748b; white-space: nowrap;
}
/* Use principal layout within att-main-card */
.att-main-card { border-radius: var(--radius, 12px); margin-top: 0; }

/* Read-only row — remove pointer cursor */
.att-table tbody tr { cursor: default; }

/* Dept filter inline with today tab filters */
.patt-filters-row {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 14px; flex-wrap: wrap;
}
.patt-filters-row .att-search-box { flex: 2; min-width: 200px; }
.patt-filters-row select {
    padding: 9px 12px; border-radius: 8px;
    border: 1px solid #d1d5db; font-size: 13px;
    background: #fff; cursor: pointer;
}
.patt-filters-row select:focus { outline: none; border-color: #0f766e; }
.patt-date-input {
    padding: 9px 12px; border-radius: 8px;
    border: 1px solid #d1d5db; font-size: 13px;
    background: #fff; cursor: pointer;
}
.patt-date-input:focus { outline: none; border-color: #0f766e; }
.patt-apply-btn {
    padding: 9px 16px; border-radius: 8px;
    background: #0f766e; color: #fff; border: none;
    font-size: 13px; font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: background 0.15s;
}
.patt-apply-btn:hover { background: #0d5f58; }
.patt-reset-link {
    font-size: 12px; color: #64748b; text-decoration: none; white-space: nowrap;
}
.patt-reset-link:hover { color: #0f766e; }

/* Absent / unrecorded note */
.patt-unrecorded-note {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px; margin-top: 10px;
    background: #fff1f2; border: 1px solid #fecdd3;
    border-radius: 8px; font-size: 12px; color: #9f1239;
}
</style>

<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/principal-sidebar.php'; ?>

<div class="main">

  <!-- ── Header ─────────────────────────────────────────────────────────────── -->
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-user-check" style="color:#0f766e;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Principal Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <button style="background:none;border:none;cursor:pointer;">
        <i class="fa fa-bell" style="font-size:16px;color:#94a3b8;"></i>
      </button>
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($_SESSION['user']['role_name'] ?? 'Principal') ?></div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'P', 0, 1) . substr($_SESSION['user']['last_name'] ?? 'R', 0, 1)) ?>
      </div>
    </div>
  </div>

  <div class="main-content">
  <div class="principal-page">

    <!-- ── Page Header ────────────────────────────────────────────────────── -->
    <div class="principal-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      <div>
        <h1>Attendance Monitor</h1>
        <p>View and monitor employee attendance records — read-only.</p>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <!-- Attendance rate pill -->
        <div class="patt-rate-bar-wrap">
          <span style="font-size:12px;color:#64748b;font-weight:600;white-space:nowrap;">Today's Rate</span>
          <div class="patt-rate-bar">
            <div class="patt-rate-fill" style="width:<?= $attendanceRate ?>%;"></div>
          </div>
          <span class="patt-rate-val"><?= $attendanceRate ?>%</span>
        </div>
        <span class="patt-readonly-badge">
          <i class="fa fa-eye"></i> View Only
        </span>
      </div>
    </div>

    <!-- ── Summary Cards (always today) ─────────────────────────────────── -->
    <div class="att-summary-cards">
      <div class="att-sum-card green">
        <div>
          <div class="att-sum-label">PRESENT TODAY</div>
          <div class="att-sum-val"><?= $presentCount ?></div>
        </div>
        <div class="att-sum-icon"><i class="fa fa-circle-check"></i></div>
      </div>
      <div class="att-sum-card yellow">
        <div>
          <div class="att-sum-label">LATE TODAY</div>
          <div class="att-sum-val"><?= $lateCount ?></div>
        </div>
        <div class="att-sum-icon"><i class="fa fa-clock"></i></div>
      </div>
      <div class="att-sum-card orange">
        <div>
          <div class="att-sum-label">HALF DAY</div>
          <div class="att-sum-val"><?= $halfDayCount ?></div>
        </div>
        <div class="att-sum-icon"><i class="fa fa-circle-half-stroke"></i></div>
      </div>
      <div class="att-sum-card red">
        <div>
          <div class="att-sum-label">ABSENT TODAY</div>
          <div class="att-sum-val"><?= $absentCount ?></div>
        </div>
        <div class="att-sum-icon"><i class="fa fa-circle-xmark"></i></div>
      </div>
      <div class="att-sum-card blue">
        <div>
          <div class="att-sum-label">ON LEAVE</div>
          <div class="att-sum-val"><?= $leaveCount ?></div>
        </div>
        <div class="att-sum-icon"><i class="fa fa-plane-departure"></i></div>
      </div>
    </div>

    <!-- ── Table Card ─────────────────────────────────────────────────────── -->
    <div class="att-main-card">

      <!-- Tabs -->
      <div class="att-tabs-row">
        <button class="att-tab <?= $tab === 'today' ? 'active' : '' ?>"
                onclick="switchTab('today', this)">
          <i class="fa fa-calendar-day"></i>
          Daily View
          <span class="tab-date"><?= date('M j, Y') ?></span>
        </button>
        <button class="att-tab <?= $tab === 'cutoff' ? 'active' : '' ?>"
                onclick="switchTab('cutoff', this)">
          <i class="fa fa-calendar-days"></i>
          By Cutoff Period
        </button>
      </div>

      <!-- ═══ TODAY TAB ═══════════════════════════════════════════════════════ -->
      <div id="tabToday" class="att-tab-pane <?= $tab !== 'today' ? 'hidden' : '' ?>">

        <form method="GET" id="todayForm">
          <input type="hidden" name="tab" value="today">
          <div class="patt-filters-row">
            <!-- Date picker -->
            <input type="date" name="date" class="patt-date-input"
                   value="<?= htmlspecialchars($filterDate) ?>"
                   onchange="document.getElementById('todayForm').submit()">

            <!-- Department -->
            <select name="dept" onchange="this.form.submit()">
              <option value="">All Departments</option>
              <?php foreach ($depts as $dep): ?>
              <option value="<?= $dep['department_id'] ?>"
                      <?= $filterDept == $dep['department_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($dep['department_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>

            <!-- Status -->
            <select name="status" onchange="this.form.submit()">
              <option value="">All Status</option>
              <option value="PRESENT"    <?= $filterStatus === 'PRESENT'    ? 'selected' : '' ?>>Present</option>
              <option value="LATE"       <?= $filterStatus === 'LATE'       ? 'selected' : '' ?>>Late</option>
              <option value="HALF_DAY"   <?= $filterStatus === 'HALF_DAY'   ? 'selected' : '' ?>>Half Day</option>
              <option value="ABSENT"     <?= $filterStatus === 'ABSENT'     ? 'selected' : '' ?>>Absent</option>
              <option value="LEAVE"      <?= $filterStatus === 'LEAVE'      ? 'selected' : '' ?>>On Leave</option>
              <option value="INCOMPLETE" <?= $filterStatus === 'INCOMPLETE' ? 'selected' : '' ?>>Incomplete</option>
            </select>

            <!-- Search -->
            <div class="att-search-box">
              <i class="fa fa-magnifying-glass"></i>
              <input type="text" name="search" placeholder="Search employee..."
                     value="<?= htmlspecialchars($search) ?>"
                     oninput="debounceSubmit(400)">
            </div>

            <button type="submit" class="patt-apply-btn">
              <i class="fa fa-filter"></i> Apply
            </button>

            <?php if ($filterDate !== $today || $filterStatus !== '' || $filterDept > 0 || $search !== ''): ?>
            <a href="?tab=today" class="patt-reset-link">
              <i class="fa fa-rotate-left"></i> Reset
            </a>
            <?php endif; ?>
          </div>
        </form>

        <?php if ($filterDate !== $today): ?>
        <div class="patt-date-notice">
          <i class="fa fa-calendar-days"></i>
          Viewing records for <strong><?= date('l, F j, Y', strtotime($filterDate)) ?></strong>
          — Summary cards above always show today.
        </div>
        <?php endif; ?>

        <!-- Table -->
        <div style="overflow-x:auto;">
        <table class="att-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Department</th>
              <th>Shift</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Status</th>
              <th>Late</th>
              <th>Undertime</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
          <tr>
            <td colspan="8" class="att-no-data">
              <i class="fa fa-calendar-xmark" style="font-size:24px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
              No attendance records found for
              <strong><?= date('M j, Y', strtotime($filterDate)) ?></strong>.
            </td>
          </tr>
          <?php else: foreach ($rows as $r):
              $stRaw    = $r['attendance_status'];
              $stClass  = strtolower($stRaw);
              $stLabel  = $STATUS_LABELS[$stRaw] ?? ucfirst(strtolower($stRaw));
              $tin      = $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '—';
              $tout     = $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—';
              $timeClass = match($stClass) {
                  'late'     => 'late',
                  'half_day' => 'half_day',
                  'present'  => 'ok',
                  default    => '',
              };
              $lateMin  = (int)($r['late_minutes']      ?? 0);
              $utMin    = (int)($r['undertime_minutes']  ?? 0);
              $initials = strtoupper(substr($r['first_name'], 0, 1) . substr($r['last_name'], 0, 1));
          ?>
          <tr>
            <td>
              <div class="att-emp-cell">
                <div class="att-av"><?= $initials ?></div>
                <div>
                  <div style="font-weight:600;"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                  <?php if ($r['position_name']): ?>
                  <span class="att-role-tag"><?= htmlspecialchars($r['position_name']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <?= $r['department_name']
                  ? '<span class="att-dept-tag">' . htmlspecialchars($r['department_name']) . '</span>'
                  : '—' ?>
            </td>
            <td>
              <?php if ($r['shift_name']): ?>
              <span class="att-shift-tag">
                <i class="fa fa-clock"></i>
                <?= htmlspecialchars($r['shift_name']) ?>
              </span>
              <?php else: ?>
              <span style="color:#94a3b8;font-size:12px;">—</span>
              <?php endif; ?>
            </td>
            <td><span class="att-time <?= $timeClass ?>"><?= $tin ?></span></td>
            <td><?= $tout ?></td>
            <td><span class="att-badge <?= $stClass ?>"><?= $stLabel ?></span></td>
            <td>
              <?php if ($lateMin > 0): ?>
              <span class="patt-late-chip"><?= $lateMin ?>m late</span>
              <?php else: ?>
              <span style="color:#94a3b8;font-size:12px;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($utMin > 0): ?>
              <span class="patt-undertime-chip"><?= $utMin ?>m early</span>
              <?php else: ?>
              <span style="color:#94a3b8;font-size:12px;">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>

        <!-- Pagination -->
        <div class="att-pagination">
          <span>
            Showing <?= $totalRecords > 0 ? $offset + 1 : 0 ?>–<?= min($offset + $limit, $totalRecords) ?>
            of <?= $totalRecords ?> record<?= $totalRecords !== 1 ? 's' : '' ?>
          </span>
          <div class="att-pg-btns">
            <a class="att-pg <?= $page <= 1 ? 'disabled' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>">
              <i class="fa fa-chevron-left" style="font-size:10px;"></i> Prev
            </a>
            <?php
            $range = range(max(1, $page - 2), min($totalPages, $page + 2));
            foreach ($range as $pg):
            ?>
            <a class="att-pg <?= $pg === $page ? 'active' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => $pg])) ?>">
              <?= $pg ?>
            </a>
            <?php endforeach; ?>
            <a class="att-pg <?= $page >= $totalPages ? 'disabled' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])) ?>">
              Next <i class="fa fa-chevron-right" style="font-size:10px;"></i>
            </a>
          </div>
        </div>

      </div><!-- #tabToday -->

      <!-- ═══ CUTOFF TAB ════════════════════════════════════════════════════ -->
      <div id="tabCutoff" class="att-tab-pane <?= $tab !== 'cutoff' ? 'hidden' : '' ?>">

        <form method="GET" id="cutoffForm">
          <input type="hidden" name="tab" value="cutoff">
          <div class="patt-filters-row">
            <!-- Cutoff period -->
            <div class="att-cutoff-sel-wrap">
              <i class="fa fa-calendar-days"></i>
              <select name="cutoff" onchange="this.form.submit()">
                <?php foreach ($cutoffOptions as $co): ?>
                <option value="<?= $co['val'] ?>" <?= $cutoffPeriod === $co['val'] ? 'selected' : '' ?>>
                  <?= $co['label'] ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Department -->
            <select name="cdept" onchange="this.form.submit()">
              <option value="">All Departments</option>
              <?php foreach ($depts as $dep): ?>
              <option value="<?= $dep['department_id'] ?>"
                      <?= $cutoffDept == $dep['department_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($dep['department_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>

            <!-- Search -->
            <div class="att-search-box">
              <i class="fa fa-magnifying-glass"></i>
              <input type="text" name="csearch" placeholder="Search employee..."
                     value="<?= htmlspecialchars($cutoffSearch) ?>">
            </div>

            <button type="submit" class="patt-apply-btn">
              <i class="fa fa-filter"></i> Apply
            </button>

            <?php if ($cutoffDept !== '' || $cutoffSearch !== ''): ?>
            <a href="?tab=cutoff&cutoff=<?= urlencode($cutoffPeriod) ?>" class="patt-reset-link">
              <i class="fa fa-rotate-left"></i> Reset
            </a>
            <?php endif; ?>
          </div>
        </form>

        <!-- Cutoff mini-stats -->
        <div class="att-cutoff-stats">
          <div class="co-stat">
            <div class="co-label">WORKING DAYS</div>
            <div class="co-val"><?= $workingDays ?></div>
          </div>
          <div class="co-stat green">
            <div class="co-label">TOTAL PRESENT</div>
            <div class="co-val"><?= (int)($ct['tp'] ?? 0) ?></div>
          </div>
          <div class="co-stat yellow">
            <div class="co-label">TOTAL LATE</div>
            <div class="co-val"><?= (int)($ct['tl'] ?? 0) ?></div>
          </div>
          <div class="co-stat orange">
            <div class="co-label">TOTAL HALF DAY</div>
            <div class="co-val"><?= (int)($ct['thd'] ?? 0) ?></div>
          </div>
          <div class="co-stat red">
            <div class="co-label">TOTAL ABSENT</div>
            <div class="co-val"><?= (int)($ct['ta'] ?? 0) ?></div>
          </div>
          <div class="co-stat blue">
            <div class="co-label">AVG ATTENDANCE</div>
            <div class="co-val"><?= $avgRate ?>%</div>
          </div>
        </div>

        <p class="att-period-note">
          <i class="fa fa-circle-info"></i>
          Period: <strong><?= date('M j', strtotime($cutoffStart)) ?> – <?= date('M j, Y', strtotime($cutoffEnd)) ?></strong>
          &nbsp;·&nbsp; <?= $workingDays ?> working days
          &nbsp;·&nbsp; <?= count($empRows) ?> employee<?= count($empRows) !== 1 ? 's' : '' ?>
        </p>

        <!-- Employee summary table -->
        <div style="overflow-x:auto;">
        <table class="att-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Department</th>
              <th>Shift</th>
              <th class="th-present">Present</th>
              <th class="th-late">Late</th>
              <th style="color:#ea580c;">Half Day</th>
              <th class="th-absent">Absent</th>
              <th class="th-leave">On Leave</th>
              <th>Attendance Rate</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($empRows)): ?>
          <tr>
            <td colspan="9" class="att-no-data">No employee records found for this period.</td>
          </tr>
          <?php else: foreach ($empRows as $er):
              $total   = max($workingDays, 1);
              $rate    = min(100, round((((int)$er['ep'] + (int)$er['el'] + (int)$er['ehd']) / $total) * 100));
              $rateClass = $rate >= 90 ? 'rc-green' : ($rate >= 75 ? 'rc-yellow' : 'rc-red');
          ?>
          <tr>
            <td>
              <div class="att-emp-cell">
                <div class="att-av">
                  <?= strtoupper(substr($er['first_name'], 0, 1) . substr($er['last_name'], 0, 1)) ?>
                </div>
                <strong><?= htmlspecialchars($er['first_name'] . ' ' . $er['last_name']) ?></strong>
              </div>
            </td>
            <td><?= htmlspecialchars($er['department_name'] ?? '—') ?></td>
            <td>
              <?php if ($er['shift_name']): ?>
              <span class="att-shift-tag"><i class="fa fa-clock"></i> <?= htmlspecialchars($er['shift_name']) ?></span>
              <?php else: ?>
              <span style="color:#94a3b8;font-size:12px;">—</span>
              <?php endif; ?>
            </td>
            <td><span class="co-num green"><?= (int)$er['ep'] ?></span></td>
            <td><span class="co-num yellow"><?= (int)$er['el'] ?></span></td>
            <td><span class="co-num orange"><?= (int)$er['ehd'] ?></span></td>
            <td><span class="co-num red"><?= (int)$er['ea'] ?></span></td>
            <td><span class="co-num blue"><?= (int)$er['ev'] ?></span></td>
            <td>
              <div class="att-rate-row">
                <div class="att-rate-bar">
                  <div class="att-rate-fill <?= $rateClass ?>" style="width:<?= $rate ?>%;"></div>
                </div>
                <span><?= $rate ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>

      </div><!-- #tabCutoff -->

    </div><!-- .att-main-card -->

  </div><!-- .principal-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<script>
function switchTab(name, btn) {
    document.querySelectorAll('.att-tab-pane').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.att-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab' + name.charAt(0).toUpperCase() + name.slice(1)).classList.remove('hidden');
    btn.classList.add('active');
}

let _debounceTimer;
function debounceSubmit(ms) {
    clearTimeout(_debounceTimer);
    _debounceTimer = setTimeout(() => document.getElementById('todayForm').submit(), ms);
}
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
