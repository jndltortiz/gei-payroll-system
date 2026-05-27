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

// Employees with approved leave on the filter date — used for leave-sync indicators
$approvedLeaveEmpIds = [];
try {
    $lvSt = $pdo->prepare("
        SELECT DISTINCT lr.employee_id
        FROM leave_requests lr
        JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
        WHERE lrd.leave_date = ? AND lr.status = 'APPROVED'
    ");
    $lvSt->execute([$filterDate]);
    $approvedLeaveEmpIds = array_flip($lvSt->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $_lvEx) {
    try {
        $lvSt = $pdo->prepare("
            SELECT DISTINCT employee_id FROM leave_requests
            WHERE ? BETWEEN start_date AND end_date AND status = 'APPROVED'
        ");
        $lvSt->execute([$filterDate]);
        $approvedLeaveEmpIds = array_flip($lvSt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $_lvEx2) {}
}

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

// ── Migration 007 detection — graceful column fallback ────────────────────────
$hasMig007 = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_records'
      AND COLUMN_NAME  = 'overtime_minutes'
")->fetchColumn();

// ── HISTORY tab ───────────────────────────────────────────────────────────────
$hDateFrom = $_GET['hfrom'] ?? date('Y-m-01');
$hDateTo   = $_GET['hto']   ?? $dateToday;
$hDept     = $_GET['hdept'] ?? '';
$hStatus   = $_GET['hst']   ?? '';
$hMethod   = $_GET['hmth']  ?? '';
$hRv       = $_GET['hrv']   ?? '';
$hSearch   = trim($_GET['hs'] ?? '');

$hPage   = max(1, (int)($_GET['hpage'] ?? 1));
$hLimit  = 15;
$hOffset = ($hPage - 1) * $hLimit;

$hWhere  = "WHERE ar.attendance_date BETWEEN :hfrom AND :hto";
$hParams = [':hfrom' => $hDateFrom, ':hto' => $hDateTo];
if ($hDept   !== '') { $hWhere .= " AND e.department_id = :hdept";       $hParams[':hdept'] = $hDept; }
if ($hStatus !== '') { $hWhere .= " AND ar.attendance_status = :hst";    $hParams[':hst']   = $hStatus; }
if ($hMethod !== '') { $hWhere .= " AND ar.attendance_source = :hmth";   $hParams[':hmth']  = $hMethod; }
if ($hasMig007 && $hRv !== '') { $hWhere .= " AND ar.review_status = :hrv"; $hParams[':hrv'] = $hRv; }
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

// ── DTR Attachments — active uploads (archived filtered out by default) ──────
$dtrFiles       = [];
$dtrArchivedCnt = 0;
$hasMig023Dtr   = false;
$showDtrArchived = isset($_GET['dtr_archived']);

if ($hasMig007) {
    try {
        $hasDeptColDtr = (bool)$pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dtr_attachments' AND COLUMN_NAME='department_id'
        ")->fetchColumn();

        $hasMig023Dtr = (bool)$pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dtr_attachments' AND COLUMN_NAME='is_archived'
        ")->fetchColumn();

        $dtrDeptCol   = $hasDeptColDtr ? 'da.department_id, dept.department_name AS dtr_dept_name,' : "NULL AS department_id, NULL AS dtr_dept_name,";
        $dtrDeptJoin  = $hasDeptColDtr ? "LEFT JOIN departments dept ON da.department_id = dept.department_id" : "";
        $dtrArchCol   = $hasMig023Dtr  ? 'COALESCE(da.is_archived,0) AS is_archived,' : '0 AS is_archived,';

        $dtrWhere = '';
        if ($hasMig023Dtr) {
            if ($showDtrArchived) {
                $dtrWhere = 'WHERE da.is_archived = 1';
            } else {
                $dtrWhere = 'WHERE COALESCE(da.is_archived,0) = 0';
            }
            $dtrArchivedCnt = (int)$pdo->query("SELECT COUNT(*) FROM dtr_attachments WHERE is_archived = 1")->fetchColumn();
        }

        $dtrStmt = $pdo->query("
            SELECT da.attachment_id, da.cutoff_start, da.cutoff_end,
                   da.file_name, da.file_path, da.file_type, da.notes, da.created_at,
                   {$dtrArchCol}
                   {$dtrDeptCol}
                   COALESCE(
                       CONCAT(emp.first_name, ' ', emp.last_name),
                       u.username,
                       'System'
                   ) AS uploaded_by_name
            FROM dtr_attachments da
            LEFT JOIN users    u   ON da.uploaded_by  = u.user_id
            LEFT JOIN employees emp ON u.employee_id  = emp.employee_id
            {$dtrDeptJoin}
            {$dtrWhere}
            ORDER BY da.created_at DESC
            LIMIT 50
        ");
        $dtrFiles = $dtrStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $dtrEx) {
        $dtrFiles = [];
    }
}

// ── Correction Requests tab ───────────────────────────────────────────────────
$corrPendingCount = 0;
$corrRows         = [];
$corrTotal        = 0;
$corrTotalPages   = 1;
$hasCorrTable     = false;

try {
    $hasCorrTable = (bool)$pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_corrections'
    ")->fetchColumn();
} catch (PDOException $_e) {}

if ($hasCorrTable) {
    $corrPendingCount = (int)$pdo->query("
        SELECT COUNT(*) FROM attendance_corrections WHERE status = 'PENDING'
    ")->fetchColumn();

    $cStatus  = $_GET['cst']     ?? '';
    $cSearch  = trim($_GET['cs'] ?? '');
    $cFrom    = $_GET['cfrom']   ?? '';
    $cTo      = $_GET['cto']     ?? '';
    $cPage    = max(1, (int)($_GET['cpage'] ?? 1));
    $cLimit   = 15;
    $cOffset  = ($cPage - 1) * $cLimit;

    $cWhere  = "WHERE 1=1";
    $cParams = [];
    if ($cStatus !== '') { $cWhere .= " AND ac.status = :cst";   $cParams[':cst']  = $cStatus; }
    if ($cFrom   !== '') { $cWhere .= " AND ac.attendance_date >= :cfrom"; $cParams[':cfrom'] = $cFrom; }
    if ($cTo     !== '') { $cWhere .= " AND ac.attendance_date <= :cto";   $cParams[':cto']   = $cTo; }
    if ($cSearch !== '') {
        $cWhere .= " AND (e.first_name LIKE :cs OR e.last_name LIKE :cs OR e.employee_no LIKE :cs)";
        $cParams[':cs'] = '%' . $cSearch . '%';
    }

    $cCntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM attendance_corrections ac
        JOIN employees e ON ac.employee_id = e.employee_id
        $cWhere
    ");
    $cCntStmt->execute($cParams);
    $corrTotal      = (int)$cCntStmt->fetchColumn();
    $corrTotalPages = max(1, ceil($corrTotal / $cLimit));

    $cStmt = $pdo->prepare("
        SELECT ac.correction_id, ac.attendance_id, ac.attendance_date, ac.issue_type,
               ac.explanation, ac.attachment_path, ac.attachment_name,
               ac.status, ac.admin_note, ac.created_at,
               e.first_name, e.last_name, e.employee_no,
               d.department_name
        FROM attendance_corrections ac
        JOIN employees e ON ac.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        $cWhere
        ORDER BY
            CASE ac.status WHEN 'PENDING' THEN 0 ELSE 1 END ASC,
            ac.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($cParams as $k => $v) $cStmt->bindValue($k, $v);
    $cStmt->bindValue(':lim',  $cLimit,  PDO::PARAM_INT);
    $cStmt->bindValue(':off',  $cOffset, PDO::PARAM_INT);
    $cStmt->execute();
    $corrRows = $cStmt->fetchAll(PDO::FETCH_ASSOC);
}

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
                <?php if ($hasCorrTable): ?>
                <button class="att-tab <?= $tab==='corrections'?'active':'' ?>" onclick="switchTab('corrections')"
                        style="<?= $tab!=='corrections' && $corrPendingCount>0 ? 'color:#dc2626;' : '' ?>">
                    <i class="fa fa-flag"></i> Correction Requests
                    <?php if ($corrPendingCount > 0): ?>
                    <span style="background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px;margin-left:2px;"><?= $corrPendingCount ?></span>
                    <?php endif; ?>
                </button>
                <?php endif; ?>
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
                                'MANUAL_ADMIN'      => 'Manual',
                                'MANUAL'            => 'Manual',
                                'RFID'              => 'RFID',
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
                            <td>
                                <span class="att-badge <?= $stClass ?>"><?= $stLabel ?></span>
                                <?php if ($stRaw === 'ABSENT' && isset($approvedLeaveEmpIds[(int)$r['employee_id']])): ?>
                                <span title="Employee has an approved leave for this date. Run &quot;Record Result&quot; in Leave Management to sync attendance."
                                      style="display:inline-flex;align-items:center;gap:3px;margin-left:4px;font-size:10px;font-weight:700;color:#7c3aed;background:#ede9fe;border:1px solid #c4b5fd;border-radius:6px;padding:2px 6px;cursor:default;">
                                    <i class="fa fa-calendar-check" style="font-size:9px;"></i> On Leave
                                </span>
                                <?php endif; ?>
                            </td>
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
                            <option value="MANUAL_ADMIN"      <?= $hMethod==='MANUAL_ADMIN'      ?'selected':'' ?>>Manual</option>
                            <option value="MANUAL"            <?= $hMethod==='MANUAL'            ?'selected':'' ?>>Manual (Self)</option>
                            <option value="RFID"              <?= $hMethod==='RFID'              ?'selected':'' ?>>RFID</option>
                            <option value="FACIAL_RECOGNITION"<?= $hMethod==='FACIAL_RECOGNITION'?'selected':'' ?>>Face ID</option>
                            <option value="AUTO"              <?= $hMethod==='AUTO'              ?'selected':'' ?>>Auto-tagged</option>
                        </select>
                        <?php if ($hasMig007): ?>
                        <select name="hrv" onchange="this.form.submit()">
                            <option value="">All Review Status</option>
                            <option value="PENDING"   <?= $hRv==='PENDING'   ?'selected':'' ?>>Pending Review</option>
                            <option value="REVIEWED"  <?= $hRv==='REVIEWED'  ?'selected':'' ?>>Reviewed</option>
                            <option value="APPROVED"  <?= $hRv==='APPROVED'  ?'selected':'' ?>>Verified</option>
                            <option value="FLAGGED"   <?= $hRv==='FLAGGED'   ?'selected':'' ?>>Needs Correction</option>
                            <option value="CORRECTED" <?= $hRv==='CORRECTED' ?'selected':'' ?>>Corrected</option>
                        </select>
                        <?php endif; ?>
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
                                'MANUAL_ADMIN'      => 'Manual',
                                'MANUAL'            => 'Manual',
                                'RFID'              => 'RFID',
                                'FACIAL_RECOGNITION'=> 'Face ID',
                                'AUTO'              => 'Auto',
                            ][$r['attendance_source'] ?? ''] ?? ($r['attendance_source'] ?? 'Manual');
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
                    'hrv'   => $hRv,
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

            <?php if ($hasCorrTable): ?>
            <!-- ── CORRECTIONS TAB ──────────────────────────────────────────── -->
            <div id="tabCorrections" class="att-tab-pane <?= $tab!=='corrections'?'hidden':'' ?>">

                <!-- Filters -->
                <form method="GET" id="corrForm">
                    <input type="hidden" name="tab" value="corrections">
                    <div class="att-filters">
                        <div class="att-search-box">
                            <i class="fa fa-magnifying-glass"></i>
                            <input type="text" name="cs" placeholder="Search employee name or no…"
                                   value="<?= htmlspecialchars($cSearch ?? '') ?>"
                                   oninput="debounce(()=>document.getElementById('corrForm').submit(),400)">
                        </div>
                        <select name="cst" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="PENDING"   <?= ($cStatus??'')==='PENDING'   ?'selected':'' ?>>Pending</option>
                            <option value="REVIEWED"  <?= ($cStatus??'')==='REVIEWED'  ?'selected':'' ?>>Reviewed</option>
                            <option value="RESOLVED"  <?= ($cStatus??'')==='RESOLVED'  ?'selected':'' ?>>Resolved</option>
                            <option value="DISMISSED" <?= ($cStatus??'')==='DISMISSED' ?'selected':'' ?>>Dismissed</option>
                        </select>
                        <input type="date" name="cfrom" value="<?= htmlspecialchars($cFrom??'') ?>"
                               title="From date" style="padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:13px;">
                        <span style="font-size:12px;color:#6b7280;">to</span>
                        <input type="date" name="cto" value="<?= htmlspecialchars($cTo??'') ?>"
                               title="To date" style="padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:13px;">
                        <button type="submit" class="att-btn primary" style="padding:9px 14px;">
                            <i class="fa fa-filter"></i> Apply
                        </button>
                        <?php if (($cStatus??'')!=='' || ($cSearch??'')!=='' || ($cFrom??'')!=='' || ($cTo??'')!==''): ?>
                        <a href="?tab=corrections" style="font-size:12px;color:#6b7280;text-decoration:none;white-space:nowrap;">
                            <i class="fa fa-rotate-left"></i> Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>

                <p class="att-period-note">
                    <i class="fa fa-circle-info"></i>
                    <?= $corrTotal ?> request<?= $corrTotal!==1?'s':'' ?> found
                    <?php if (($cStatus??'') === '' && $corrPendingCount > 0): ?>
                    &nbsp;·&nbsp; <strong style="color:#dc2626;"><?= $corrPendingCount ?> pending</strong>
                    <?php endif; ?>
                </p>

                <?php if (empty($corrRows)): ?>
                <div class="att-no-data">
                    <i class="fa fa-flag" style="font-size:28px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
                    No correction requests found.
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="att-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Attendance Date</th>
                            <th>Issue Type</th>
                            <th style="max-width:280px;">Explanation</th>
                            <th>Proof</th>
                            <th style="text-align:center;">Status</th>
                            <th>Submitted</th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $corrIssueLabels = [
                        'MISSING_TIME_IN'  => 'Missing Time In',
                        'MISSING_TIME_OUT' => 'Missing Time Out',
                        'WRONG_STATUS'     => 'Wrong Status',
                        'LATE_INCORRECT'   => 'Late Incorrectly Marked',
                        'OTHER'            => 'Other',
                    ];
                    $corrStatusMap = [
                        'PENDING'   => ['Pending',   'rv-pending',   '#f97316'],
                        'REVIEWED'  => ['Reviewed',  'rv-reviewed',  '#2563eb'],
                        'RESOLVED'  => ['Resolved',  'rv-approved',  '#059669'],
                        'DISMISSED' => ['Dismissed', 'rv-flagged',   '#dc2626'],
                    ];
                    foreach ($corrRows as $cr):
                        [$cLabel, $cCls] = $corrStatusMap[$cr['status']] ?? ['Unknown', 'rv-pending'];
                        $issueLabel = $corrIssueLabels[$cr['issue_type']] ?? $cr['issue_type'];
                        $initials   = strtoupper(substr($cr['first_name'],0,1) . substr($cr['last_name'],0,1));
                        $cjson      = json_encode([
                            'id'          => (int)$cr['correction_id'],
                            'name'        => $cr['first_name'] . ' ' . $cr['last_name'],
                            'empNo'       => $cr['employee_no'] ?? '',
                            'date'        => $cr['attendance_date'],
                            'issue'       => $issueLabel,
                            'explanation' => $cr['explanation'],
                            'adminNote'   => $cr['admin_note'] ?? '',
                            'status'      => $cr['status'],
                        ]);
                    ?>
                    <tr style="<?= $cr['status']==='PENDING' ? 'background:#fffbeb;' : '' ?>">
                        <td>
                            <div class="att-emp-cell">
                                <div class="att-av"><?= $initials ?></div>
                                <div>
                                    <div style="font-weight:600;"><?= htmlspecialchars($cr['first_name'].' '.$cr['last_name']) ?></div>
                                    <?php if ($cr['employee_no']): ?><div class="att-empno"><?= htmlspecialchars($cr['employee_no']) ?></div><?php endif; ?>
                                    <?php if ($cr['department_name']): ?><span class="att-role-tag"><?= htmlspecialchars($cr['department_name']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td style="white-space:nowrap;font-weight:600;">
                            <?= date('M j, Y', strtotime($cr['attendance_date'])) ?>
                            <div style="font-size:10px;color:#94a3b8;"><?= date('D', strtotime($cr['attendance_date'])) ?></div>
                        </td>
                        <td>
                            <span class="att-method-tag"><?= htmlspecialchars($issueLabel) ?></span>
                        </td>
                        <td style="max-width:280px;">
                            <div style="font-size:12px;color:#374151;line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;">
                                <?= htmlspecialchars($cr['explanation']) ?>
                            </div>
                            <?php if ($cr['admin_note']): ?>
                            <div style="font-size:11px;color:#0f766e;margin-top:4px;font-style:italic;">
                                <i class="fa fa-reply"></i> <?= htmlspecialchars($cr['admin_note']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($cr['attachment_path'] && file_exists(__DIR__ . '/../../' . $cr['attachment_path'])): ?>
                            <a href="<?= BASE_URL . htmlspecialchars($cr['attachment_path']) ?>" target="_blank"
                               class="att-action-btn" title="<?= htmlspecialchars($cr['attachment_name'] ?? 'View proof') ?>">
                                <i class="fa fa-paperclip"></i> View
                            </a>
                            <?php else: ?>
                            <span style="color:#94a3b8;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <span class="att-rv-badge <?= $cCls ?>"><?= $cLabel ?></span>
                        </td>
                        <td style="white-space:nowrap;font-size:12px;color:#64748b;">
                            <?= date('M j, Y', strtotime($cr['created_at'])) ?><br>
                            <?= date('g:i A', strtotime($cr['created_at'])) ?>
                        </td>
                        <td style="text-align:center;">
                            <button class="att-action-btn" onclick="openCorrResolveModal(<?= htmlspecialchars($cjson, ENT_QUOTES) ?>)">
                                <i class="fa fa-pen-to-square"></i> Resolve
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Pagination -->
                <div class="att-pagination">
                    <span>Showing <?= $corrTotal>0?$cOffset+1:0 ?>–<?= min($cOffset+$cLimit,$corrTotal) ?> of <?= $corrTotal ?></span>
                    <div class="att-pg-btns">
                        <?php
                        $cBase = http_build_query(array_filter(['tab'=>'corrections','cst'=>$cStatus??'','cs'=>$cSearch??'','cfrom'=>$cFrom??'','cto'=>$cTo??'']));
                        ?>
                        <a class="att-pg <?= $cPage<=1?'disabled':'' ?>" href="?<?= $cBase ?>&cpage=<?= max(1,$cPage-1) ?>">
                            <i class="fa fa-chevron-left" style="font-size:10px;"></i> Prev
                        </a>
                        <?php foreach (range(max(1,$cPage-2),min($corrTotalPages,$cPage+2)) as $pg): ?>
                        <a class="att-pg <?= $pg===$cPage?'active':'' ?>" href="?<?= $cBase ?>&cpage=<?= $pg ?>"><?= $pg ?></a>
                        <?php endforeach; ?>
                        <a class="att-pg <?= $cPage>=$corrTotalPages?'disabled':'' ?>" href="?<?= $cBase ?>&cpage=<?= min($corrTotalPages,$cPage+1) ?>">
                            Next <i class="fa fa-chevron-right" style="font-size:10px;"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /tabCorrections -->
            <?php endif; ?>

        </div><!-- /att-main-card -->

        <?php if ($hasMig007): ?>
        <!-- ── DTR BACKUP FILES (always visible, below tabs) ──────────────── -->
        <div class="att-dtr-outer">
            <div class="att-dtr-section-title">
                <i class="fa fa-paperclip"></i>
                DTR Backup Files
                <?php if (!empty($dtrFiles)): ?>
                <span class="att-dtr-count"><?= count($dtrFiles) ?></span>
                <?php endif; ?>
                <div style="display:flex;gap:8px;align-items:center;margin-left:auto;flex-wrap:wrap;">
                    <?php if ($hasMig023Dtr): ?>
                    <?php if ($dtrArchivedCnt > 0): ?>
                    <a href="?tab=<?= $tab ?>&dtr_archived<?= $showDtrArchived ? '=1' : '' ?>"
                       class="att-btn outline"
                       style="font-size:11px;padding:5px 11px;<?= $showDtrArchived ? 'background:#fef3c7;border-color:#f59e0b;color:#92400e;' : 'color:#64748b;' ?>">
                        <?php if ($showDtrArchived): ?>
                        <i class="fa fa-eye"></i> Active Files
                        <?php else: ?>
                        <i class="fa fa-archive"></i> Archived (<?= $dtrArchivedCnt ?>)
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <button class="att-btn outline" onclick="openDtrModal()"
                            style="font-size:12px;padding:6px 14px;border-color:#3b82f6;color:#1d4ed8;">
                        <i class="fa fa-file-arrow-up"></i> Upload DTR
                    </button>
                </div>
            </div>

            <?php if (!empty($dtrFiles)): ?>
            <div class="att-dtr-list">
            <?php foreach ($dtrFiles as $df):
                $ftIcon = match($df['file_type']) {
                    'EXCEL' => 'fa-file-excel',
                    'PDF'   => 'fa-file-pdf',
                    'IMAGE' => 'fa-file-image',
                    default => 'fa-file',
                };
                $ftColor = match($df['file_type']) {
                    'EXCEL' => '#16a34a',
                    'PDF'   => '#dc2626',
                    'IMAGE' => '#7c3aed',
                    default => '#6b7280',
                };
            ?>
            <div class="att-dtr-item <?= (int)($df['is_archived']??0) ? 'att-dtr-item--archived' : '' ?>">
                <i class="fa <?= $ftIcon ?>" style="color:<?= $ftColor ?>;font-size:22px;flex-shrink:0;<?= (int)($df['is_archived']??0)?'opacity:.4;':'' ?>"></i>
                <div class="att-dtr-info">
                    <div class="att-dtr-filename"><?= htmlspecialchars($df['file_name']) ?>
                        <?php if ((int)($df['is_archived']??0)): ?>
                        <span style="font-size:10px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;padding:1px 6px;margin-left:4px;font-weight:700;">Archived</span>
                        <?php endif; ?>
                    </div>
                    <div class="att-dtr-meta">
                        <strong>Period:</strong>
                        <?= date('M j, Y', strtotime($df['cutoff_start'])) ?>
                        <?= $df['cutoff_start'] !== $df['cutoff_end'] ? ' – ' . date('M j, Y', strtotime($df['cutoff_end'])) : '' ?>
                        <?php if (!empty($df['dtr_dept_name'])): ?>
                        &nbsp;·&nbsp; <span class="att-dept-tag" style="font-size:10px;"><?= htmlspecialchars($df['dtr_dept_name']) ?></span>
                        <?php endif; ?>
                        &nbsp;·&nbsp; Uploaded by <?= htmlspecialchars($df['uploaded_by_name'] ?? 'System') ?>
                        on <?= date('M j, Y g:i A', strtotime($df['created_at'])) ?>
                        <?php if ($df['notes']): ?>
                        <br><em><?= htmlspecialchars($df['notes']) ?></em>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;align-items:center;flex-wrap:wrap;">
                    <?php if (!(int)($df['is_archived']??0)): ?>
                    <a href="<?= BASE_URL ?>actions/download-dtr.php?id=<?= $df['attachment_id'] ?>"
                       target="_blank" class="att-btn outline"
                       style="font-size:12px;padding:6px 12px;white-space:nowrap;">
                        <i class="fa fa-download"></i> Download
                    </a>
                    <?php if ($hasMig023Dtr): ?>
                    <button class="att-btn outline" title="Archive this DTR (file kept, hidden from active list)"
                            onclick="dtrAction('archive', <?= $df['attachment_id'] ?>, '<?= htmlspecialchars(addslashes($df['file_name'])) ?>')"
                            style="font-size:12px;padding:6px 12px;white-space:nowrap;color:#92400e;border-color:#fde68a;">
                        <i class="fa fa-archive"></i> Archive
                    </button>
                    <?php endif; ?>
                    <?php else: ?>
                    <?php if ($hasMig023Dtr): ?>
                    <button class="att-btn outline" title="Restore this upload to active list"
                            onclick="dtrAction('unarchive', <?= $df['attachment_id'] ?>, '<?= htmlspecialchars(addslashes($df['file_name'])) ?>')"
                            style="font-size:12px;padding:6px 12px;white-space:nowrap;color:#059669;border-color:#6ee7b7;">
                        <i class="fa fa-rotate-left"></i> Restore
                    </button>
                    <button class="att-btn outline" title="Permanently delete this DTR record and file"
                            onclick="dtrAction('delete', <?= $df['attachment_id'] ?>, '<?= htmlspecialchars(addslashes($df['file_name'])) ?>')"
                            style="font-size:12px;padding:6px 12px;white-space:nowrap;color:#dc2626;border-color:#fca5a5;">
                        <i class="fa fa-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="att-dtr-empty">
                <i class="fa fa-folder-open" style="opacity:0.25;font-size:24px;"></i>
                <span>No DTR backup files uploaded yet. Use the <strong>Upload DTR</strong> button above to add a file.</span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
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
<?php if ($hasMig007) include __DIR__ . '/modals/dtr-upload-modal.php'; ?>

<?php if ($hasCorrTable): ?>
<!-- ── Correction Resolve Modal ───────────────────────────────────────────── -->
<div id="corrResolveModal" class="att-modal" style="display:none;">
  <div class="att-modal-box" style="max-width:500px;">
    <div class="att-modal-header" style="background:#1e3a5f;">
      <div>
        <h3><i class="fa fa-flag"></i> Resolve Correction Request</h3>
        <p style="font-size:12px;opacity:.8;margin-top:2px;">Review the employee's issue and mark a resolution.</p>
      </div>
      <button type="button" class="att-modal-close" onclick="closeCorrResolveModal()"><i class="fa fa-times"></i></button>
    </div>
    <div class="att-modal-body">
      <!-- Employee + request info (read-only) -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;">
        <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">Request Details</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;">
          <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Employee</div><div style="font-size:13px;font-weight:600;color:#0f172a;" id="crName">—</div></div>
          <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Date</div><div style="font-size:13px;font-weight:600;color:#0f172a;" id="crDate">—</div></div>
          <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Issue Type</div><div style="font-size:13px;font-weight:600;color:#0f172a;" id="crIssue">—</div></div>
          <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Current Status</div><div id="crStatus">—</div></div>
          <div style="grid-column:span 2;"><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Employee Explanation</div><div style="font-size:13px;color:#374151;line-height:1.5;" id="crExplanation">—</div></div>
        </div>
      </div>

      <!-- Resolution decision -->
      <div>
        <div style="font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">Resolution</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:.15s;" id="optReviewed" onclick="selectCorrOpt(this,'REVIEWED')">
            <input type="radio" name="corrStatus" value="REVIEWED" style="margin-top:2px;accent-color:#2563eb;">
            <div><div style="font-size:13px;font-weight:600;color:#1e293b;"><i class="fa fa-eye" style="color:#2563eb;margin-right:4px;"></i>Mark Reviewed</div><div style="font-size:11px;color:#64748b;">Acknowledged — admin has reviewed, may need further action.</div></div>
          </label>
          <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:.15s;" id="optResolved" onclick="selectCorrOpt(this,'RESOLVED')">
            <input type="radio" name="corrStatus" value="RESOLVED" style="margin-top:2px;accent-color:#059669;">
            <div><div style="font-size:13px;font-weight:600;color:#1e293b;"><i class="fa fa-circle-check" style="color:#059669;margin-right:4px;"></i>Resolved</div><div style="font-size:11px;color:#64748b;">Issue addressed — attendance has been corrected or confirmed.</div></div>
          </label>
          <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:.15s;" id="optDismissed" onclick="selectCorrOpt(this,'DISMISSED')">
            <input type="radio" name="corrStatus" value="DISMISSED" style="margin-top:2px;accent-color:#dc2626;">
            <div><div style="font-size:13px;font-weight:600;color:#1e293b;"><i class="fa fa-ban" style="color:#dc2626;margin-right:4px;"></i>Dismiss</div><div style="font-size:11px;color:#64748b;">Request is invalid or not actionable — close without changes.</div></div>
          </label>
        </div>
      </div>

      <!-- Admin note -->
      <div>
        <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">
          Admin Note <span style="color:#94a3b8;font-weight:400;">(optional — visible to admin only)</span>
        </label>
        <textarea id="crAdminNote" rows="2" placeholder="Add a note about the resolution or reason for dismissal…"
                  style="width:100%;padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:13px;resize:vertical;box-sizing:border-box;font-family:inherit;"></textarea>
      </div>

      <div id="crError" style="display:none;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;"></div>
    </div>
    <div class="att-modal-footer">
      <button type="button" class="att-btn outline" onclick="closeCorrResolveModal()">Cancel</button>
      <button type="button" class="att-btn primary" id="crSaveBtn" onclick="submitCorrResolve()" disabled>
        <i class="fa fa-save"></i> Save Resolution
      </button>
    </div>
  </div>
</div>

<!-- Toast for correction resolve -->
<div id="corrToast" style="position:fixed;bottom:24px;right:24px;z-index:2000;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.18);display:none;"></div>
<?php endif; ?>

<script src="<?= BASE_URL ?>assets/js/attendance.js"></script>
<script>
const _ATT_BASE = '<?= BASE_URL ?>';

// ── DTR archive / delete ──────────────────────────────────────────────────────
window.dtrAction = function(action, id, name) {
    const msgs = {
        archive:   `Archive "${name}"?\n\nThe file will be hidden from the active list but kept on disk and in the audit trail.`,
        unarchive: `Restore "${name}" to the active list?`,
        delete:    `Permanently delete "${name}"?\n\nThis removes the database record and the physical file. This cannot be undone.`,
    };
    if (!confirm(msgs[action] || 'Proceed?')) return;
    const fd = new FormData();
    fd.append('action',        action);
    fd.append('attachment_id', id);
    fetch(_ATT_BASE + 'actions/dtr-action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                alert(d.message);
                location.reload();
            } else {
                alert('Error: ' + d.message);
            }
        })
        .catch(() => alert('Request failed — check your connection.'));
};

// Auto-Absent modal helpers (inline — simple enough)
window.openAutoAbsentModal = function() {
    document.getElementById('autoAbsentModal').style.display = 'flex';
    document.getElementById('autoAbsentResult').style.display = 'none';
};
window.closeAutoAbsentModal = function() {
    document.getElementById('autoAbsentModal').style.display = 'none';
};
// ── Correction Resolve Modal ──────────────────────────────────────────────────
<?php if ($hasCorrTable): ?>
let _crId = null, _crChoice = null;

window.openCorrResolveModal = function(data) {
    _crId = data.id; _crChoice = null;
    document.getElementById('crName').textContent        = data.name + (data.empNo ? ' (' + data.empNo + ')' : '');
    document.getElementById('crDate').textContent        = data.date;
    document.getElementById('crIssue').textContent       = data.issue;
    document.getElementById('crExplanation').textContent = data.explanation;
    document.getElementById('crAdminNote').value         = data.adminNote || '';
    document.getElementById('crError').style.display     = 'none';

    const rvMap = { PENDING:'rv-pending', REVIEWED:'rv-reviewed', RESOLVED:'rv-approved', DISMISSED:'rv-flagged' };
    const rvLbl = { PENDING:'Pending', REVIEWED:'Reviewed', RESOLVED:'Resolved', DISMISSED:'Dismissed' };
    document.getElementById('crStatus').innerHTML = `<span class="att-rv-badge ${rvMap[data.status]||'rv-pending'}">${rvLbl[data.status]||data.status}</span>`;

    ['optReviewed','optResolved','optDismissed'].forEach(id => {
        document.getElementById(id).style.borderColor = '#e2e8f0';
        document.getElementById(id).style.background  = '#fff';
        document.getElementById(id).querySelector('input').checked = false;
    });
    document.getElementById('crSaveBtn').disabled = true;
    document.getElementById('corrResolveModal').style.display = 'flex';
};

window.closeCorrResolveModal = function() {
    document.getElementById('corrResolveModal').style.display = 'none';
    _crId = null; _crChoice = null;
};

window.selectCorrOpt = function(el, val) {
    ['optReviewed','optResolved','optDismissed'].forEach(id => {
        document.getElementById(id).style.borderColor = '#e2e8f0';
        document.getElementById(id).style.background  = '#fff';
    });
    el.style.borderColor = '#2563eb';
    el.style.background  = '#eff6ff';
    el.querySelector('input').checked = true;
    _crChoice = val;
    document.getElementById('crSaveBtn').disabled = false;
};

window.submitCorrResolve = function() {
    if (!_crId || !_crChoice) return;
    const btn    = document.getElementById('crSaveBtn');
    const errBox = document.getElementById('crError');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';
    errBox.style.display = 'none';

    const fd = new FormData();
    fd.append('correction_id', _crId);
    fd.append('status',        _crChoice);
    fd.append('admin_note',    document.getElementById('crAdminNote').value.trim());

    fetch('<?= BASE_URL ?>actions/admin-resolve-correction.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeCorrResolveModal();
                const t = document.getElementById('corrToast');
                t.textContent = data.message; t.style.background = '#059669';
                t.style.display = 'block';
                setTimeout(() => { t.style.display = 'none'; location.reload(); }, 1600);
            } else {
                errBox.textContent = data.message || 'Failed to save.';
                errBox.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-save"></i> Save Resolution';
            }
        })
        .catch(() => {
            errBox.textContent = 'Network error. Please try again.';
            errBox.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-save"></i> Save Resolution';
        });
};
<?php endif; ?>

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
