<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

$dateToday = date('Y-m-d');
$tab = $_GET['tab'] ?? 'today';

// ── Summary cards (always today) ──────────────────────────────────────────────
$present = $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date='$dateToday' AND attendance_status='PRESENT'")->fetchColumn();
$late    = $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date='$dateToday' AND attendance_status='LATE'")->fetchColumn();
$halfDay = $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date='$dateToday' AND attendance_status='HALF_DAY'")->fetchColumn();
$absent  = $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date='$dateToday' AND attendance_status='ABSENT'")->fetchColumn();
$leave   = $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date='$dateToday' AND attendance_status='LEAVE'")->fetchColumn();

// ── TODAY tab ─────────────────────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$filterDate   = $_GET['date']   ?? $dateToday;

$limit  = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where  = "WHERE ar.attendance_date = :adate";
$params = [':adate' => $filterDate];
if ($search !== '') {
    $where .= " AND (e.first_name LIKE :s OR e.last_name LIKE :s OR e.employee_no LIKE :s)";
    $params[':s'] = '%' . $search . '%';
}
if ($filterStatus !== '') {
    $where .= " AND ar.attendance_status = :st";
    $params[':st'] = $filterStatus;
}

$totalRec = $pdo->prepare("SELECT COUNT(*) FROM attendance_records ar JOIN employees e ON ar.employee_id=e.employee_id $where");
$totalRec->execute($params);
$totalRec   = $totalRec->fetchColumn();
$totalPages = max(1, ceil($totalRec / $limit));

$stmt = $pdo->prepare("
    SELECT ar.*, e.first_name, e.last_name, e.employee_no,
           d.department_name, p.position_name
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    $where ORDER BY e.last_name ASC, e.first_name ASC LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── CUTOFF tab ────────────────────────────────────────────────────────────────
$cutoffPeriod = $_GET['cutoff'] ?? '';
$cutoffDept   = $_GET['cdept']  ?? '';
$cutoffSearch = trim($_GET['csearch'] ?? '');

$cutoffOptions = [];
for ($i = 0; $i < 6; $i++) {
    $ts   = strtotime("-$i months");
    $yr   = date('Y', $ts);
    $mo   = date('m', $ts);
    $last = date('t', mktime(0, 0, 0, $mo, 1, $yr));
    $cutoffOptions[] = ['label' => date('M', $ts) . " 1 – 15, $yr",        'val' => "$yr-$mo-01|$yr-$mo-15"];
    $cutoffOptions[] = ['label' => date('M', $ts) . " 16 – $last, $yr",    'val' => "$yr-$mo-16|$yr-$mo-$last"];
}

if ($cutoffPeriod === '') {
    $day = (int)date('d');
    $cutoffPeriod = $day <= 15
        ? date('Y-m') . '-01|' . date('Y-m') . '-15'
        : date('Y-m') . '-16|' . date('Y-m') . '-' . date('t');
}
[$cutoffStart, $cutoffEnd] = explode('|', $cutoffPeriod) + [date('Y-m-01'), date('Y-m-15')];

$workingDays = 0;
for ($d = strtotime($cutoffStart); $d <= strtotime($cutoffEnd); $d = strtotime('+1 day', $d)) {
    if (date('N', $d) < 7) $workingDays++;
}

$ctWhere = "WHERE ar.attendance_date BETWEEN :cs AND :ce";
$ctP     = [':cs' => $cutoffStart, ':ce' => $cutoffEnd];
if ($cutoffDept   !== '') { $ctWhere .= " AND e.department_id = :cd";  $ctP[':cd']  = $cutoffDept; }
if ($cutoffSearch !== '') { $ctWhere .= " AND (e.first_name LIKE :csr OR e.last_name LIKE :csr OR e.employee_no LIKE :csr)"; $ctP[':csr'] = '%' . $cutoffSearch . '%'; }

$ct = $pdo->prepare("
    SELECT SUM(ar.attendance_status='PRESENT')  tp,
           SUM(ar.attendance_status='LATE')     tl,
           SUM(ar.attendance_status='HALF_DAY') thd,
           SUM(ar.attendance_status='ABSENT')   ta,
           COUNT(DISTINCT ar.employee_id)       te
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id=e.employee_id $ctWhere
");
$ct->execute($ctP);
$ct = $ct->fetch(PDO::FETCH_ASSOC);

$avgRate = ($ct['te'] > 0 && $workingDays > 0)
    ? round((($ct['tp'] + $ct['tl'] + $ct['thd']) / ($ct['te'] * $workingDays)) * 100)
    : 0;

$empStmt = $pdo->prepare("
    SELECT e.employee_id, e.employee_no, e.first_name, e.last_name, d.department_name,
        SUM(ar.attendance_status='PRESENT')  ep,
        SUM(ar.attendance_status='LATE')     el,
        SUM(ar.attendance_status='HALF_DAY') ehd,
        SUM(ar.attendance_status='ABSENT')   ea,
        SUM(ar.attendance_status='LEAVE')    ev
    FROM employees e
    LEFT JOIN attendance_records ar ON e.employee_id=ar.employee_id
          AND ar.attendance_date BETWEEN :cs3 AND :ce3
    LEFT JOIN departments d ON e.department_id=d.department_id
    WHERE e.employee_status='ACTIVE'
    GROUP BY e.employee_id ORDER BY e.last_name ASC, e.first_name ASC
");
$empStmt->execute([':cs3' => $cutoffStart, ':ce3' => $cutoffEnd]);
$empRows = $empStmt->fetchAll(PDO::FETCH_ASSOC);

// ── HISTORY tab ───────────────────────────────────────────────────────────────
$hDateFrom = $_GET['hfrom'] ?? date('Y-m-01');
$hDateTo   = $_GET['hto']   ?? $dateToday;
$hDept     = $_GET['hdept'] ?? '';
$hStatus   = $_GET['hst']   ?? '';
$hMethod   = $_GET['hmth']  ?? '';
$hSearch   = trim($_GET['hs'] ?? '');

$hPage   = max(1, (int)($_GET['hpage'] ?? 1));
$hLimit  = 15;
$hOffset = ($hPage - 1) * $hLimit;

$hWhere  = "WHERE ar.attendance_date BETWEEN :hfrom AND :hto";
$hParams = [':hfrom' => $hDateFrom, ':hto' => $hDateTo];
if ($hDept   !== '') { $hWhere .= " AND e.department_id = :hdept";       $hParams[':hdept'] = $hDept; }
if ($hStatus !== '') { $hWhere .= " AND ar.attendance_status = :hst";    $hParams[':hst']   = $hStatus; }
if ($hMethod !== '') { $hWhere .= " AND ar.attendance_source = :hmth";   $hParams[':hmth']  = $hMethod; }
if ($hSearch !== '') {
    $hWhere .= " AND (e.first_name LIKE :hs OR e.last_name LIKE :hs OR e.employee_no LIKE :hs)";
    $hParams[':hs'] = '%' . $hSearch . '%';
}

$hTotal = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id=e.employee_id
    $hWhere
");
$hTotal->execute($hParams);
$hTotal = $hTotal->fetchColumn();
$hPages = max(1, ceil($hTotal / $hLimit));

// ── Migration 007 detection — graceful column fallback ────────────────────────
// If migration 007 has not been run yet, use placeholder SELECTs so the page
// still loads; new features simply show default values.
$hasMig007 = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_records'
      AND COLUMN_NAME  = 'overtime_minutes'
")->fetchColumn();

// Rebuild history query with the right column list
$hOtCol  = $hasMig007 ? 'ar.overtime_minutes,'  : '0 AS overtime_minutes,';
$hRvCol  = $hasMig007 ? 'ar.review_status,'      : "'PENDING' AS review_status,";

$hStmt = $pdo->prepare("
    SELECT ar.attendance_id, ar.attendance_date, ar.time_in, ar.time_out,
           ar.attendance_status, ar.attendance_source, ar.remarks,
           ar.late_minutes, {$hOtCol} {$hRvCol}
           e.first_name, e.last_name, e.employee_no,
           d.department_name, p.position_name
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    $hWhere
    ORDER BY ar.attendance_date DESC, e.last_name ASC
    LIMIT :lim OFFSET :off
");
foreach ($hParams as $k => $v) $hStmt->bindValue($k, $v);
$hStmt->bindValue(':lim', $hLimit,  PDO::PARAM_INT);
$hStmt->bindValue(':off', $hOffset, PDO::PARAM_INT);
$hStmt->execute();
$hRows = $hStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Shared data ───────────────────────────────────────────────────────────────
$depts     = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$positions = $pdo->query("SELECT position_id, position_name FROM positions ORDER BY position_name")->fetchAll();

// ── Labels ────────────────────────────────────────────────────────────────────
$stLabels = [
    'PRESENT'    => 'Present',
    'LATE'       => 'Late',
    'HALF_DAY'   => 'Half Day',
    'ABSENT'     => 'Absent',
    'LEAVE'      => 'On Leave',
    'INCOMPLETE' => 'Incomplete',
    'HOLIDAY'    => 'Holiday',
];
$rvLabels = [
    'PENDING'   => ['Pending',   'rv-pending'],
    'REVIEWED'  => ['Reviewed',  'rv-reviewed'],
    'APPROVED'  => ['Approved',  'rv-approved'],
    'FLAGGED'   => ['Flagged',   'rv-flagged'],
    'CORRECTED' => ['Corrected', 'rv-corrected'],
];

$pageTitle = 'Attendance Records';
$extraCSS  = [BASE_URL . 'assets/css/attendance.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <div class="content att-page">

        <!-- PAGE HEADER -->
        <div class="att-page-header">
            <div>
                <h2 class="att-title">Attendance Records</h2>
                <p class="att-subtitle">View, filter, and manage employee attendance</p>
            </div>
            <div class="att-header-actions">
                <button class="att-btn outline" onclick="openAutoAbsentModal()">
                    <i class="fa fa-robot"></i> Auto-Absent
                </button>
                <button class="att-btn primary" onclick="openAttModal()">
                    <i class="fa fa-arrow-right-to-bracket"></i> Log Employee In
                </button>
            </div>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="att-summary-cards">
            <div class="att-sum-card green">
                <div><div class="att-sum-label">PRESENT TODAY</div><div class="att-sum-val"><?= $present ?></div></div>
                <div class="att-sum-icon"><i class="fa fa-circle-check"></i></div>
            </div>
            <div class="att-sum-card yellow">
                <div><div class="att-sum-label">LATE TODAY</div><div class="att-sum-val"><?= $late ?></div></div>
                <div class="att-sum-icon"><i class="fa fa-clock"></i></div>
            </div>
            <div class="att-sum-card orange">
                <div><div class="att-sum-label">HALF DAY</div><div class="att-sum-val"><?= $halfDay ?></div></div>
                <div class="att-sum-icon"><i class="fa fa-circle-half-stroke"></i></div>
            </div>
            <div class="att-sum-card red">
                <div><div class="att-sum-label">ABSENT TODAY</div><div class="att-sum-val"><?= $absent ?></div></div>
                <div class="att-sum-icon"><i class="fa fa-circle-xmark"></i></div>
            </div>
            <div class="att-sum-card blue">
                <div><div class="att-sum-label">ON LEAVE</div><div class="att-sum-val"><?= $leave ?></div></div>
                <div class="att-sum-icon"><i class="fa fa-plane-departure"></i></div>
            </div>
        </div>

        <!-- MAIN CARD -->
        <div class="att-main-card">

            <!-- TABS -->
            <div class="att-tabs-row">
                <button class="att-tab <?= $tab==='today'  ?'active':'' ?>" onclick="switchTab('today')">
                    <i class="fa fa-calendar-day"></i> Today's Attendance
                    <span class="tab-date"><?= date('M j, Y') ?></span>
                </button>
                <button class="att-tab <?= $tab==='cutoff' ?'active':'' ?>" onclick="switchTab('cutoff')">
                    <i class="fa fa-calendar-days"></i> By Cutoff Period
                </button>
                <button class="att-tab <?= $tab==='history'?'active':'' ?>" onclick="switchTab('history')">
                    <i class="fa fa-clock-rotate-left"></i> Attendance History
                </button>
            </div>

            <!-- ── TODAY TAB ───────────────────────────────────────────────── -->
            <div id="tabToday" class="att-tab-pane <?= $tab!=='today'?'hidden':'' ?>">

                <!-- Filter form -->
                <form method="GET" id="todayForm">
                    <input type="hidden" name="tab" value="today">
                    <div class="att-filters">
                        <div class="att-search-box">
                            <i class="fa fa-magnifying-glass"></i>
                            <input type="text" name="search" placeholder="Search by name or Emp. No…"
                                   value="<?= htmlspecialchars($search) ?>"
                                   oninput="debounce(()=>document.getElementById('todayForm').submit(),400)">
                        </div>
                        <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>"
                               onchange="this.form.submit()" style="padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:13px;">
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="PRESENT"  <?= $filterStatus==='PRESENT'  ?'selected':'' ?>>Present</option>
                            <option value="LATE"     <?= $filterStatus==='LATE'     ?'selected':'' ?>>Late</option>
                            <option value="HALF_DAY" <?= $filterStatus==='HALF_DAY' ?'selected':'' ?>>Half Day</option>
                            <option value="ABSENT"   <?= $filterStatus==='ABSENT'   ?'selected':'' ?>>Absent</option>
                            <option value="LEAVE"    <?= $filterStatus==='LEAVE'    ?'selected':'' ?>>On Leave</option>
                        </select>
                    </div>
                </form>

                <!-- Bulk timeout bar (shows when rows are checked) -->
                <div id="bulkTimeoutBar" class="att-bulk-bar" style="display:none;">
                    <span id="bulkCount" class="att-bulk-count"></span>
                    <button class="att-btn warning" onclick="confirmBulkTimeout()">
                        <i class="fa fa-clock"></i> Time Out Selected
                    </button>
                    <button class="att-btn outline" onclick="clearRowSelection()" style="font-size:12px;padding:7px 12px;">
                        Clear
                    </button>
                </div>

                <?php if ($filterDate !== $dateToday): ?>
                <div class="att-date-notice">
                    <i class="fa fa-circle-info"></i>
                    Viewing attendance for <strong><?= date('F j, Y', strtotime($filterDate)) ?></strong>
                    — <a href="?tab=today">Back to today</a>
                </div>
                <?php endif; ?>

                <div style="overflow-x:auto;">
                <table class="att-table">
                    <thead>
                        <tr>
                            <th class="att-check-cell">
                                <input type="checkbox" id="selectAllRows"
                                       onchange="toggleSelectAll(this)" title="Select all with active check-in">
                            </th>
                            <th>Emp. No.</th>
                            <th>Employee <i class="fa fa-sort fa-xs"></i></th>
                            <th>Department</th>
                            <th>Role</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                            <th>Method</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="att-no-data">No attendance records for this date.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $stRaw    = $r['attendance_status'];
                            $stClass  = strtolower($stRaw);
                            $stLabel  = $stLabels[$stRaw] ?? ucfirst(strtolower($stRaw));
                            $tin      = $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '—';
                            $tout     = $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—';
                            $timeClass= $stClass === 'late' ? 'late' : ($stClass === 'half_day' ? 'half_day' : ($stClass === 'present' ? 'ok' : ''));
                            $canTimeout = $r['time_in'] && !$r['time_out'];
                            $empNameEsc = addslashes($r['first_name'] . ' ' . $r['last_name']);
                            $sourceLabel = [
                                'MANUAL_ADMIN'      => 'Manual (Admin)',
                                'MANUAL'            => 'Manual',
                                'FACIAL_RECOGNITION'=> 'Face ID',
                                'AUTO'              => 'Auto',
                            ][$r['attendance_source'] ?? ''] ?? ($r['attendance_source'] ?? 'Manual');
                        ?>
                        <tr>
                            <td class="att-check-cell">
                                <?php if ($canTimeout): ?>
                                <input type="checkbox" class="row-select"
                                       value="<?= $r['attendance_id'] ?>"
                                       data-name="<?= htmlspecialchars($empNameEsc) ?>"
                                       onchange="updateBulkBar()">
                                <?php else: ?>
                                <input type="checkbox" disabled class="row-select-disabled" title="No active check-in">
                                <?php endif; ?>
                            </td>
                            <td class="att-empno"><?= htmlspecialchars($r['employee_no'] ?? '—') ?></td>
                            <td>
                                <div class="att-emp-cell">
                                    <div class="att-av"><?= strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1)) ?></div>
                                    <span><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></span>
                                </div>
                            </td>
                            <td><?= $r['department_name'] ? '<span class="att-dept-tag">'.htmlspecialchars($r['department_name']).'</span>' : '—' ?></td>
                            <td><?= $r['position_name']   ? '<span class="att-role-tag">'.htmlspecialchars($r['position_name']).'</span>'   : '—' ?></td>
                            <td><span class="att-time <?= $timeClass ?>"><?= $tin ?></span></td>
                            <td><?= $tout ?></td>
                            <td><span class="att-badge <?= $stClass ?>"><?= $stLabel ?></span></td>
                            <td><span class="att-method-tag"><?= htmlspecialchars($sourceLabel) ?></span></td>
                            <td>
                                <div class="att-action-group">
                                    <?php if ($canTimeout): ?>
                                    <button class="att-action-btn timeout" title="Log time out"
                                        onclick="openTimeoutModal([<?= $r['attendance_id'] ?>],['<?= $empNameEsc ?>'])">
                                        <i class="fa fa-clock"></i> Time Out
                                    </button>
                                    <?php endif; ?>
                                    <button class="att-action-btn edit" title="Edit record"
                                        onclick="openEditModal(
                                            '<?= $r['attendance_id'] ?>',
                                            '<?= $empNameEsc ?>',
                                            '<?= htmlspecialchars($r['employee_no']??'') ?>',
                                            '<?= $r['attendance_date'] ?>',
                                            '<?= $r['time_in'] ?>',
                                            '<?= $r['time_out'] ?>',
                                            '<?= $r['attendance_status'] ?>',
                                            '<?= addslashes($r['remarks']??'') ?>',
                                            '<?= htmlspecialchars($r['attendance_source']??'MANUAL_ADMIN') ?>'
                                        )">
                                        <i class="fa fa-pen-to-square"></i> Edit
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>

                <!-- Pagination -->
                <div class="att-pagination">
                    <span>Showing <?= $totalRec>0?$offset+1:0 ?>–<?= min($offset+$limit,$totalRec) ?> of <?= $totalRec ?> records</span>
                    <div class="att-pg-btns">
                        <a class="att-pg <?= $page<=1?'disabled':'' ?>"
                           href="?tab=today&page=<?= max(1,$page-1) ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>&date=<?= urlencode($filterDate) ?>">Previous</a>
                        <?php for ($i=1;$i<=$totalPages;$i++): ?>
                        <a class="att-pg <?= $i==$page?'active':'' ?>"
                           href="?tab=today&page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>&date=<?= urlencode($filterDate) ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <a class="att-pg <?= $page>=$totalPages?'disabled':'' ?>"
                           href="?tab=today&page=<?= min($totalPages,$page+1) ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>&date=<?= urlencode($filterDate) ?>">Next</a>
                    </div>
                </div>
            </div><!-- /tabToday -->

            <!-- ── CUTOFF TAB ──────────────────────────────────────────────── -->
            <div id="tabCutoff" class="att-tab-pane <?= $tab!=='cutoff'?'hidden':'' ?>">
                <form method="GET" id="cutoffForm">
                    <input type="hidden" name="tab" value="cutoff">
                    <div class="att-filters">
                        <div class="att-cutoff-sel-wrap">
                            <i class="fa fa-calendar-days"></i>
                            <select name="cutoff" onchange="this.form.submit()">
                                <?php foreach ($cutoffOptions as $co): ?>
                                <option value="<?= $co['val'] ?>" <?= $cutoffPeriod===$co['val']?'selected':'' ?>><?= $co['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <span class="att-period-closed">Closed</span>
                        <div class="att-search-box">
                            <i class="fa fa-magnifying-glass"></i>
                            <input type="text" name="csearch" placeholder="Search by name or Emp. No…"
                                   value="<?= htmlspecialchars($cutoffSearch) ?>"
                                   oninput="debounce(()=>document.getElementById('cutoffForm').submit(),400)">
                        </div>
                        <select name="cdept" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach ($depts as $d): ?>
                            <option value="<?= $d['department_id'] ?>" <?= $cutoffDept==$d['department_id']?'selected':'' ?>>
                                <?= htmlspecialchars($d['department_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <div class="att-cutoff-stats">
                    <div class="co-stat"><div class="co-label">WORKING DAYS</div><div class="co-val"><?= $workingDays ?></div></div>
                    <div class="co-stat green"><div class="co-label">TOTAL PRESENT</div><div class="co-val"><?= $ct['tp']??0 ?></div></div>
                    <div class="co-stat yellow"><div class="co-label">TOTAL LATE</div><div class="co-val"><?= $ct['tl']??0 ?></div></div>
                    <div class="co-stat orange"><div class="co-label">TOTAL HALF DAY</div><div class="co-val"><?= $ct['thd']??0 ?></div></div>
                    <div class="co-stat red"><div class="co-label">TOTAL ABSENT</div><div class="co-val"><?= $ct['ta']??0 ?></div></div>
                    <div class="co-stat blue"><div class="co-label">AVG ATTENDANCE</div><div class="co-val"><?= $avgRate ?>%</div></div>
                </div>

                <p class="att-period-note">
                    <i class="fa fa-circle-info"></i>
                    Cutoff: <strong><?= date('M j', strtotime($cutoffStart)) ?> – <?= date('M j, Y', strtotime($cutoffEnd)) ?></strong>
                    &nbsp;·&nbsp; <?= $workingDays ?> working days
                    &nbsp;·&nbsp; <?= count($empRows) ?> employees
                </p>

                <table class="att-table">
                    <thead>
                        <tr>
                            <th>Emp. No.</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th class="th-present">Present</th>
                            <th class="th-late">Late</th>
                            <th class="th-half-day">Half Day</th>
                            <th class="th-absent">Absent</th>
                            <th class="th-leave">On Leave</th>
                            <th>Attendance Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empRows as $er):
                            $total = max($workingDays, 1);
                            $rate  = min(100, round((($er['ep']+$er['el']+$er['ehd']) / $total) * 100));
                            $rc    = $rate >= 90 ? 'rc-green' : ($rate >= 75 ? 'rc-yellow' : 'rc-red');
                        ?>
                        <tr>
                            <td class="att-empno"><?= htmlspecialchars($er['employee_no'] ?? '—') ?></td>
                            <td><strong><?= htmlspecialchars($er['first_name'].' '.$er['last_name']) ?></strong></td>
                            <td><?= htmlspecialchars($er['department_name']??'—') ?></td>
                            <td><span class="co-num green"><?= (int)$er['ep'] ?></span></td>
                            <td><span class="co-num yellow"><?= (int)$er['el'] ?></span></td>
                            <td><span class="co-num orange"><?= (int)$er['ehd'] ?></span></td>
                            <td><span class="co-num red"><?= (int)$er['ea'] ?></span></td>
                            <td><span class="co-num blue"><?= (int)$er['ev'] ?></span></td>
                            <td>
                                <div class="att-rate-row">
                                    <div class="att-rate-bar"><div class="att-rate-fill <?= $rc ?>" style="width:<?= $rate ?>%"></div></div>
                                    <span><?= $rate ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div><!-- /tabCutoff -->

            <!-- ── HISTORY TAB ─────────────────────────────────────────────── -->
            <div id="tabHistory" class="att-tab-pane <?= $tab!=='history'?'hidden':'' ?>">
                <form method="GET" id="historyForm">
                    <input type="hidden" name="tab" value="history">
                    <div class="att-filters" style="flex-wrap:wrap;gap:8px;">
                        <div class="att-search-box" style="min-width:200px;flex:1;">
                            <i class="fa fa-magnifying-glass"></i>
                            <input type="text" name="hs" placeholder="Name or Emp. No…"
                                   value="<?= htmlspecialchars($hSearch) ?>"
                                   oninput="debounce(()=>document.getElementById('historyForm').submit(),400)">
                        </div>
                        <input type="date" name="hfrom" value="<?= htmlspecialchars($hDateFrom) ?>"
                               onchange="this.form.submit()" style="padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:13px;">
                        <input type="date" name="hto"   value="<?= htmlspecialchars($hDateTo) ?>"
                               onchange="this.form.submit()" style="padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:13px;">
                        <select name="hdept" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach ($depts as $d): ?>
                            <option value="<?= $d['department_id'] ?>" <?= $hDept==$d['department_id']?'selected':'' ?>>
                                <?= htmlspecialchars($d['department_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="hst" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="PRESENT"  <?= $hStatus==='PRESENT'  ?'selected':'' ?>>Present</option>
                            <option value="LATE"     <?= $hStatus==='LATE'     ?'selected':'' ?>>Late</option>
                            <option value="HALF_DAY" <?= $hStatus==='HALF_DAY' ?'selected':'' ?>>Half Day</option>
                            <option value="ABSENT"   <?= $hStatus==='ABSENT'   ?'selected':'' ?>>Absent</option>
                            <option value="LEAVE"    <?= $hStatus==='LEAVE'    ?'selected':'' ?>>On Leave</option>
                            <option value="INCOMPLETE" <?= $hStatus==='INCOMPLETE'?'selected':'' ?>>Incomplete</option>
                            <option value="HOLIDAY"  <?= $hStatus==='HOLIDAY'  ?'selected':'' ?>>Holiday</option>
                        </select>
                        <select name="hmth" onchange="this.form.submit()">
                            <option value="">All Methods</option>
                            <option value="MANUAL_ADMIN"      <?= $hMethod==='MANUAL_ADMIN'      ?'selected':'' ?>>Manual (Admin)</option>
                            <option value="MANUAL"            <?= $hMethod==='MANUAL'            ?'selected':'' ?>>Manual</option>
                            <option value="FACIAL_RECOGNITION"<?= $hMethod==='FACIAL_RECOGNITION'?'selected':'' ?>>Face ID</option>
                            <option value="AUTO"              <?= $hMethod==='AUTO'              ?'selected':'' ?>>Auto-tagged</option>
                        </select>
                    </div>
                </form>

                <p class="att-period-note" style="margin-top:8px;">
                    <i class="fa fa-circle-info"></i>
                    Showing <strong><?= $hTotal ?></strong> records
                    from <strong><?= date('M j, Y', strtotime($hDateFrom)) ?></strong>
                    to <strong><?= date('M j, Y', strtotime($hDateTo)) ?></strong>
                </p>

                <div style="overflow-x:auto;">
                <table class="att-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Emp. No.</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>OT (min)</th>
                            <th>Status</th>
                            <th>Method</th>
                            <th>Review</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($hRows)): ?>
                        <tr><td colspan="11" class="att-no-data">No attendance records found for this filter.</td></tr>
                        <?php else: foreach ($hRows as $r):
                            $stRaw    = $r['attendance_status'];
                            $stClass  = strtolower($stRaw);
                            $stLabel  = $stLabels[$stRaw] ?? ucfirst(strtolower($stRaw));
                            $tin      = $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '—';
                            $tout     = $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—';
                            $rv       = $r['review_status'] ?? 'PENDING';
                            [$rvLabel, $rvClass] = $rvLabels[$rv] ?? ['Pending','rv-pending'];
                            $canTimeout = $r['time_in'] && !$r['time_out'];
                            $empNameEsc = addslashes($r['first_name'].' '.$r['last_name']);
                            $srcLabel = [
                                'MANUAL_ADMIN'      => 'Manual (Admin)',
                                'MANUAL'            => 'Manual',
                                'FACIAL_RECOGNITION'=> 'Face ID',
                                'AUTO'              => 'Auto',
                            ][$r['attendance_source'] ?? ''] ?? ($r['attendance_source'] ?? '—');
                        ?>
                        <tr>
                            <td style="white-space:nowrap;font-weight:600;"><?= date('M j, Y', strtotime($r['attendance_date'])) ?></td>
                            <td class="att-empno"><?= htmlspecialchars($r['employee_no']??'—') ?></td>
                            <td>
                                <div class="att-emp-cell">
                                    <div class="att-av"><?= strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1)) ?></div>
                                    <span><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></span>
                                </div>
                            </td>
                            <td><?= $r['department_name'] ? '<span class="att-dept-tag">'.htmlspecialchars($r['department_name']).'</span>' : '—' ?></td>
                            <td><?= $tin ?></td>
                            <td><?= $tout ?></td>
                            <td>
                                <?php if ($r['overtime_minutes'] > 0): ?>
                                <span class="att-ot-badge"><?= (int)$r['overtime_minutes'] ?> min</span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><span class="att-badge <?= $stClass ?>"><?= $stLabel ?></span></td>
                            <td><span class="att-method-tag"><?= htmlspecialchars($srcLabel) ?></span></td>
                            <td><span class="att-rv-badge <?= $rvClass ?>"><?= $rvLabel ?></span></td>
                            <td>
                                <div class="att-action-group">
                                    <?php if ($canTimeout): ?>
                                    <button class="att-action-btn timeout" title="Log time out"
                                        onclick="openTimeoutModal([<?= $r['attendance_id'] ?>],['<?= $empNameEsc ?>'])">
                                        <i class="fa fa-clock"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button class="att-action-btn edit" title="Edit record"
                                        onclick="openEditModal(
                                            '<?= $r['attendance_id'] ?>',
                                            '<?= $empNameEsc ?>',
                                            '<?= htmlspecialchars($r['employee_no']??'') ?>',
                                            '<?= $r['attendance_date'] ?>',
                                            '<?= $r['time_in'] ?>',
                                            '<?= $r['time_out'] ?>',
                                            '<?= $r['attendance_status'] ?>',
                                            '<?= addslashes($r['remarks']??'') ?>',
                                            '<?= htmlspecialchars($r['attendance_source']??'MANUAL_ADMIN') ?>'
                                        )">
                                        <i class="fa fa-pen-to-square"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>

                <!-- History Pagination -->
                <?php
                $hLinkBase = http_build_query([
                    'tab'   => 'history',
                    'hfrom' => $hDateFrom,
                    'hto'   => $hDateTo,
                    'hdept' => $hDept,
                    'hst'   => $hStatus,
                    'hmth'  => $hMethod,
                    'hs'    => $hSearch,
                ]);
                ?>
                <div class="att-pagination">
                    <span>Showing <?= $hTotal>0?$hOffset+1:0 ?>–<?= min($hOffset+$hLimit,$hTotal) ?> of <?= $hTotal ?> records</span>
                    <div class="att-pg-btns">
                        <a class="att-pg <?= $hPage<=1?'disabled':'' ?>"
                           href="?<?= $hLinkBase ?>&hpage=<?= max(1,$hPage-1) ?>">Previous</a>
                        <?php for ($i=1;$i<=$hPages;$i++): ?>
                        <a class="att-pg <?= $i==$hPage?'active':'' ?>"
                           href="?<?= $hLinkBase ?>&hpage=<?= $i ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <a class="att-pg <?= $hPage>=$hPages?'disabled':'' ?>"
                           href="?<?= $hLinkBase ?>&hpage=<?= min($hPages,$hPage+1) ?>">Next</a>
                    </div>
                </div>
            </div><!-- /tabHistory -->

        </div><!-- /att-main-card -->
    </div>
</div>

<!-- Auto-Absent modal (inline, simple) -->
<div id="autoAbsentModal" class="att-modal" style="display:none;">
    <div class="att-modal-box" style="max-width:380px;">
        <div class="att-modal-header" style="background:#4b5563;">
            <div><h3><i class="fa fa-robot"></i> Auto-Absent</h3>
                <p style="font-size:12px;opacity:0.8;margin-top:2px;">Tags employees with no attendance log as Absent or On Leave</p>
            </div>
            <button type="button" class="att-modal-close" onclick="closeAutoAbsentModal()"><i class="fa fa-times"></i></button>
        </div>
        <div class="att-modal-body" style="gap:12px;">
            <div class="att-field">
                <label>Date</label>
                <input type="date" id="autoAbsentDate" value="<?= $dateToday ?>" max="<?= $dateToday ?>">
            </div>
            <div class="att-notice-box" style="background:#fefce8;border-color:#fde047;color:#713f12;">
                <strong><i class="fa fa-triangle-exclamation"></i> Important</strong>
                <p>Only employees WITHOUT any record on this date are tagged. Does NOT deduct payroll.</p>
            </div>
            <div id="autoAbsentResult" style="display:none;padding:10px;border-radius:8px;font-size:13px;"></div>
        </div>
        <div class="att-modal-footer">
            <button type="button" class="att-btn outline" onclick="closeAutoAbsentModal()">Cancel</button>
            <button type="button" class="att-btn primary" id="autoAbsentBtn" onclick="runAutoAbsent()">
                <i class="fa fa-robot"></i> Run Auto-Absent
            </button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/modals/add-attendance-modal.php'; ?>
<?php include __DIR__ . '/modals/edit-attendance-modal.php'; ?>
<?php include __DIR__ . '/modals/timeout-modal.php'; ?>
<script src="<?= BASE_URL ?>assets/js/attendance.js"></script>
<script>
// Auto-Absent modal helpers (inline — simple enough)
window.openAutoAbsentModal = function() {
    document.getElementById('autoAbsentModal').style.display = 'flex';
    document.getElementById('autoAbsentResult').style.display = 'none';
};
window.closeAutoAbsentModal = function() {
    document.getElementById('autoAbsentModal').style.display = 'none';
};
window.runAutoAbsent = function() {
    const btn    = document.getElementById('autoAbsentBtn');
    const result = document.getElementById('autoAbsentResult');
    const date   = document.getElementById('autoAbsentDate').value;
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Running…';
    result.style.display = 'none';

    const fd = new FormData();
    fd.append('date', date);
    fetch('<?= BASE_URL ?>actions/auto-absent.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            result.style.display = 'block';
            result.style.background = data.success ? '#f0fdf4' : '#fef2f2';
            result.style.color      = data.success ? '#065f46' : '#991b1b';
            result.style.border     = data.success ? '1px solid #bbf7d0' : '1px solid #fecaca';
            result.textContent      = data.message || 'Done.';
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa fa-robot"></i> Run Auto-Absent';
            if (data.success && (data.tagged > 0 || data.on_leave > 0)) {
                setTimeout(() => { closeAutoAbsentModal(); location.reload(); }, 1800);
            }
        })
        .catch(() => {
            result.style.display  = 'block';
            result.textContent    = 'Network error. Please try again.';
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa fa-robot"></i> Run Auto-Absent';
        });
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
