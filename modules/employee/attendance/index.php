<?php
/**
 * modules/employee/attendance/index.php
 * Employee Portal — My Attendance Records v2
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployeeAccess();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);
$today = date('Y-m-d');
$tab   = $_GET['tab'] ?? 'month';

// ── Migration & feature detection ─────────────────────────────────────────────
$hasMig007 = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'overtime_minutes'
")->fetchColumn();

$hasCorrections = (bool)$pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_corrections'
")->fetchColumn();

$selRv = $hasMig007 ? 'ar.review_status,' : "'PENDING' AS review_status,";
$selOt = $hasMig007 ? 'ar.overtime_minutes,' : '0 AS overtime_minutes,';

// ── KPI cards — always current month ─────────────────────────────────────────
$curStart = date('Y-m-01');
$curEnd   = date('Y-m-t');
$kpiStmt  = $pdo->prepare("
    SELECT SUM(attendance_status='PRESENT')  cp,
           SUM(attendance_status='LATE')     cl,
           SUM(attendance_status='HALF_DAY') chd,
           SUM(attendance_status='ABSENT')   ca,
           SUM(attendance_status='LEAVE')    cv
    FROM attendance_records
    WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
");
$kpiStmt->execute([$empId, $curStart, $curEnd]);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);
$kpiPresent = (int)($kpi['cp']??0) + (int)($kpi['cl']??0) + (int)($kpi['chd']??0);
$kpiWd = 0;
$_d = strtotime($curStart);
while ($_d <= strtotime($curEnd)) { if (date('N', $_d) < 7) $kpiWd++; $_d = strtotime('+1 day', $_d); }
$kpiRate = $kpiWd > 0 ? min(100, round(($kpiPresent / $kpiWd) * 100)) : 0;

// ── THIS MONTH tab ────────────────────────────────────────────────────────────
$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
$month = max(1, min(12, $month));
$year  = max(2020, min((int)date('Y') + 1, $year));

$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$mStmt = $pdo->prepare("
    SELECT ar.attendance_id, ar.attendance_date, ar.attendance_status,
           ar.time_in, ar.time_out, ar.late_minutes, ar.undertime_minutes,
           ar.attendance_source, ar.remarks, {$selRv} {$selOt}
           s.shift_name, s.start_time AS shift_start, s.end_time AS shift_end
    FROM attendance_records ar
    LEFT JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN shifts s ON e.shift_id = s.shift_id
    WHERE ar.employee_id = ? AND ar.attendance_date BETWEEN ? AND ?
    ORDER BY ar.attendance_date ASC
");
$mStmt->execute([$empId, $monthStart, $monthEnd]);
$mRecords = $mStmt->fetchAll(PDO::FETCH_ASSOC);

// ── CUTOFF tab ────────────────────────────────────────────────────────────────
$cutoffPeriod = $_GET['cutoff'] ?? '';
$cutoffOptions = [];
for ($i = 0; $i < 6; $i++) {
    $ts   = strtotime("-$i months");
    $yr   = date('Y', $ts); $mo = date('m', $ts);
    $last = date('t', mktime(0, 0, 0, $mo, 1, $yr));
    $cutoffOptions[] = ['label' => date('M', $ts) . " 1–15, $yr",     'val' => "$yr-$mo-01|$yr-$mo-15"];
    $cutoffOptions[] = ['label' => date('M', $ts) . " 16–$last, $yr", 'val' => "$yr-$mo-16|$yr-$mo-$last"];
}
if ($cutoffPeriod === '') {
    $day = (int)date('d');
    $cutoffPeriod = $day <= 15
        ? date('Y-m') . '-01|' . date('Y-m') . '-15'
        : date('Y-m') . '-16|' . date('Y-m') . '-' . date('t');
}
[$cutoffStart, $cutoffEnd] = explode('|', $cutoffPeriod) + [date('Y-m-01'), date('Y-m-15')];

$coWd = 0;
$_d = strtotime($cutoffStart);
while ($_d <= strtotime($cutoffEnd)) { if (date('N', $_d) < 7) $coWd++; $_d = strtotime('+1 day', $_d); }

$coSumStmt = $pdo->prepare("
    SELECT SUM(attendance_status='PRESENT') cp, SUM(attendance_status='LATE') cl,
           SUM(attendance_status='HALF_DAY') chd, SUM(attendance_status='ABSENT') ca,
           SUM(attendance_status='LEAVE') cv
    FROM attendance_records WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
");
$coSumStmt->execute([$empId, $cutoffStart, $cutoffEnd]);
$coStats   = $coSumStmt->fetch(PDO::FETCH_ASSOC);
$coPresent = (int)($coStats['cp']??0) + (int)($coStats['cl']??0) + (int)($coStats['chd']??0);
$coRate    = $coWd > 0 ? min(100, round(($coPresent / $coWd) * 100)) : 0;

$coDaysStmt = $pdo->prepare("
    SELECT ar.attendance_id, ar.attendance_date, ar.attendance_status,
           ar.time_in, ar.time_out, ar.late_minutes, ar.undertime_minutes,
           ar.attendance_source, ar.remarks, {$selRv} {$selOt} s.shift_name
    FROM attendance_records ar
    LEFT JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN shifts s ON e.shift_id = s.shift_id
    WHERE ar.employee_id = ? AND ar.attendance_date BETWEEN ? AND ?
    ORDER BY ar.attendance_date ASC
");
$coDaysStmt->execute([$empId, $cutoffStart, $cutoffEnd]);
$coDays = $coDaysStmt->fetchAll(PDO::FETCH_ASSOC);

// ── HISTORY tab ───────────────────────────────────────────────────────────────
$hStart  = $_GET['hstart']  ?? date('Y-m-01');
$hEnd    = $_GET['hend']    ?? $today;
$hStatus = $_GET['hstatus'] ?? '';
$hMethod = $_GET['hmth']    ?? '';
$hPage   = max(1, (int)($_GET['hpage'] ?? 1));
$hLimit  = 20;
$hOffset = ($hPage - 1) * $hLimit;

$hWhere  = "WHERE ar.employee_id = :eid AND ar.attendance_date BETWEEN :hs AND :he";
$hParams = [':eid' => $empId, ':hs' => $hStart, ':he' => $hEnd];
if ($hStatus !== '') { $hWhere .= " AND ar.attendance_status = :hst"; $hParams[':hst'] = $hStatus; }
if ($hMethod !== '') { $hWhere .= " AND ar.attendance_source = :hmth"; $hParams[':hmth'] = $hMethod; }

$hCntStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_records ar $hWhere");
$hCntStmt->execute($hParams);
$hTotal      = (int)$hCntStmt->fetchColumn();
$hTotalPages = max(1, ceil($hTotal / $hLimit));

$hStmt = $pdo->prepare("
    SELECT ar.attendance_id, ar.attendance_date, ar.attendance_status,
           ar.time_in, ar.time_out, ar.late_minutes, ar.undertime_minutes,
           ar.attendance_source, ar.remarks, {$selRv} {$selOt} s.shift_name
    FROM attendance_records ar
    LEFT JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN shifts s ON e.shift_id = s.shift_id
    $hWhere
    ORDER BY ar.attendance_date DESC
    LIMIT :lim OFFSET :off
");
foreach ($hParams as $k => $v) $hStmt->bindValue($k, $v);
$hStmt->bindValue(':lim',  $hLimit,  PDO::PARAM_INT);
$hStmt->bindValue(':off',  $hOffset, PDO::PARAM_INT);
$hStmt->execute();
$hRows = $hStmt->fetchAll(PDO::FETCH_ASSOC);

// ── My correction requests ────────────────────────────────────────────────────
$pendingCorrections = 0;
$myCorrections      = [];
if ($hasCorrections) {
    $pCStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_corrections WHERE employee_id = ? AND status = 'PENDING'");
    $pCStmt->execute([$empId]);
    $pendingCorrections = (int)$pCStmt->fetchColumn();

    $cListStmt = $pdo->prepare("
        SELECT correction_id, attendance_date, issue_type, status, created_at
        FROM attendance_corrections WHERE employee_id = ?
        ORDER BY created_at DESC LIMIT 10
    ");
    $cListStmt->execute([$empId]);
    $myCorrections = $cListStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Approved leave dates — for display-level ABSENT→LEAVE override ───────────
// Builds a set of dates where the employee has approved leave.
// The underlying attendance_status stays ABSENT in the DB until admin records it.
$leaveApprovedDates = [];
try {
    $lvQ = $pdo->prepare("
        SELECT lrd.leave_date
        FROM leave_requests lr
        JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
        WHERE lr.employee_id = ? AND lr.status = 'APPROVED'
    ");
    $lvQ->execute([$empId]);
    $leaveApprovedDates = array_flip($lvQ->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $_lvE) {
    // Fallback: date-range based (older schema without leave_request_dates)
    try {
        $lvQ = $pdo->prepare("
            SELECT start_date, end_date FROM leave_requests
            WHERE employee_id = ? AND status = 'APPROVED'
        ");
        $lvQ->execute([$empId]);
        foreach ($lvQ->fetchAll(PDO::FETCH_ASSOC) as $lr) {
            $d = strtotime($lr['start_date']);
            $e = strtotime($lr['end_date']);
            while ($d <= $e) { $leaveApprovedDates[date('Y-m-d', $d)] = 1; $d = strtotime('+1 day', $d); }
        }
    } catch (PDOException $_lvE2) {}
}

// ── Label maps ────────────────────────────────────────────────────────────────
$ST_LABELS = [
    'PRESENT'    => 'Present',    'LATE'    => 'Late',       'HALF_DAY'   => 'Half Day',
    'ABSENT'     => 'Absent',     'LEAVE'   => 'On Leave',   'HOLIDAY'    => 'Holiday',
    'INCOMPLETE' => 'Incomplete',
];
$RV_LABELS = [
    'PENDING'   => 'Pending Review', 'REVIEWED'  => 'Reviewed',
    'APPROVED'  => 'Verified',       'FLAGGED'   => 'Needs Correction',
    'CORRECTED' => 'Corrected',
];
$ISSUE_LABELS = [
    'MISSING_TIME_IN'  => 'Missing Time In',   'MISSING_TIME_OUT' => 'Missing Time Out',
    'WRONG_STATUS'     => 'Wrong Status',       'LATE_INCORRECT'   => 'Late Incorrectly Marked',
    'OTHER'            => 'Other',
];
$CORR_STATUS_LABELS = [
    'PENDING'   => ['Pending',   'rv-pending'],   'REVIEWED'  => ['Reviewed',  'rv-reviewed'],
    'RESOLVED'  => ['Resolved',  'rv-approved'],  'DISMISSED' => ['Dismissed', 'rv-flagged'],
];

// Render attendance table rows (shared across all tabs)
$renderRow = function(array $r) use ($ST_LABELS, $RV_LABELS, $hasMig007, $hasCorrections, $empId, $leaveApprovedDates): string {
    $st      = $r['attendance_status'];

    // Display-only override: ABSENT → On Leave when employee has approved leave for this date.
    // The DB record stays ABSENT until admin records it via Leave Management.
    $isLeaveOverride = ($st === 'ABSENT') && isset($leaveApprovedDates[$r['attendance_date']]);
    $stCls   = $isLeaveOverride ? 'leave' : strtolower($st);
    $stLabel = $isLeaveOverride
        ? htmlspecialchars('On Leave')
        : htmlspecialchars($ST_LABELS[$st] ?? ucfirst(strtolower($st)));
    $rv      = $r['review_status'] ?? 'PENDING';
    $rvCls   = 'rv-' . strtolower($rv);
    $rvLabel = htmlspecialchars($RV_LABELS[$rv] ?? $rv);
    $tin     = $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '';
    $tout    = $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '';
    $dt      = strtotime($r['attendance_date']);
    $src     = $r['attendance_source'] ?? '';
    $srcLabel = match($src) {
        'MANUAL_ADMIN'       => 'Manual',
        'MANUAL'             => 'Manual',
        'RFID'               => 'RFID',
        'FACIAL_RECOGNITION' => 'Biometric',
        'AUTO'               => 'Auto-Tagged',
        default              => ($src ?: 'Manual'),
    };
    $late  = (int)($r['late_minutes']     ?? 0);
    $ot    = (int)($r['overtime_minutes'] ?? 0);
    $jd = json_encode([
        'id' => (int)$r['attendance_id'], 'date' => $r['attendance_date'],
        'day' => date('l', $dt), 'shift' => $r['shift_name'] ?? '',
        'timeIn' => $r['time_in'] ?? '', 'timeOut' => $r['time_out'] ?? '',
        'lateMin' => $late, 'otMin' => $ot,
        'status' => $st, 'statusLabel' => $ST_LABELS[$st] ?? ucfirst(strtolower($st)),
        'source' => $src, 'srcLabel' => $srcLabel,
        'rv' => $rv, 'rvLabel' => $RV_LABELS[$rv] ?? $rv,
        'remarks' => $r['remarks'] ?? '',
    ]);
    $jdAttr = htmlspecialchars($jd, ENT_QUOTES);
    $attId  = (int)$r['attendance_id'];
    $date   = htmlspecialchars($r['attendance_date'], ENT_QUOTES);

    $rvCol  = $hasMig007
        ? "<td style=\"text-align:center;\"><span class=\"att-rv-badge $rvCls\">$rvLabel</span></td>"
        : '';
    $acts   = "<button class=\"eatt-btn-detail\" onclick=\"openDetailsModal($jdAttr)\"><i class=\"fa fa-eye\"></i> Details</button>";
    if ($hasCorrections) {
        $acts .= "<button class=\"eatt-btn-correct\" onclick=\"openCorrectionModal($attId,'$date')\"><i class=\"fa fa-flag\"></i> Correct</button>";
    }
    $shiftHtml = $r['shift_name']
        ? '<span class="att-shift-tag" style="font-size:11px;color:#6366f1;"><i class="fa fa-clock"></i> ' . htmlspecialchars($r['shift_name']) . '</span>'
        : '<span style="color:#94a3b8;font-size:12px;">—</span>';
    $tinClass = $st === 'LATE' ? 'late' : ($st === 'HALF_DAY' ? 'half_day' : 'ok');
    $tinHtml  = $tin  ? "<span class=\"att-time $tinClass\">$tin</span>" : '<span style="color:#94a3b8;">—</span>';
    $toutHtml = $tout ? $tout : '<span style="color:#94a3b8;">—</span>';

    return "
      <tr>
        <td style=\"white-space:nowrap;font-weight:600;color:#0f172a;\">" . date('M j, Y', $dt) . "</td>
        <td style=\"color:#64748b;\">" . date('D', $dt) . "</td>
        <td>$shiftHtml</td>
        <td style=\"text-align:center;\">$tinHtml</td>
        <td style=\"text-align:center;\">$toutHtml</td>
        <td style=\"text-align:center;\">
          <span class=\"att-badge $stCls\">$stLabel</span>"
          . ($isLeaveOverride ? ' <span title="Approved leave — attendance will be updated once admin records the result." style="font-size:10px;color:#7c3aed;vertical-align:middle;cursor:default;">&#9432;</span>' : '')
          . "</td>
        <td><span class=\"att-method-tag\">$srcLabel</span></td>
        $rvCol
        <td><div class=\"att-action-group\">$acts</div></td>
      </tr>";
};

$colCount = $hasMig007 ? 9 : 8;

$pageTitle = 'My Attendance — Employee Portal';
$extraCSS  = [
    BASE_URL . 'assets/css/employee-portal.css',
    BASE_URL . 'assets/css/attendance.css',
];
require_once __DIR__ . '/../../../includes/head.php';
?>
<style>
/* ── Employee attendance overrides (eatt- prefix) ──────────────────────────── */
.att-summary-cards.eatt-6col { grid-template-columns: repeat(6, 1fr); }
@media (max-width:1200px) { .att-summary-cards.eatt-6col { grid-template-columns: repeat(3, 1fr); } }
@media (max-width:680px)  { .att-summary-cards.eatt-6col { grid-template-columns: repeat(2, 1fr); } }

.att-sum-card.teal               { border-left: 4px solid #0f766e; }
.att-sum-card.teal .att-sum-label { color: #065f46; }
.att-sum-card.teal .att-sum-val   { color: #0f766e; }
.att-sum-card.teal .att-sum-icon  { color: #0f766e; }

.att-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.att-tab:hover  { color: var(--accent); }

/* Rate ring */
.eatt-rate-wrap { display:flex;align-items:center;gap:12px; }
.eatt-rate-bar  { flex:1;height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;max-width:160px; }
.eatt-rate-fill { height:100%;border-radius:99px;background:#0f766e; }

/* Action buttons */
.eatt-btn-detail {
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 10px;border-radius:6px;
    border:1px solid #e2e8f0;background:#f8fafc;
    color:#374151;font-size:11px;font-weight:600;cursor:pointer;
    transition:all .15s;white-space:nowrap;
}
.eatt-btn-detail:hover { background:#e2e8f0; }
.eatt-btn-correct {
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 10px;border-radius:6px;
    border:1px solid var(--accent-mid);background:var(--accent-light);
    color:var(--accent);font-size:11px;font-weight:600;cursor:pointer;
    transition:all .15s;white-space:nowrap;
}
.eatt-btn-correct:hover { background:var(--accent-mid); }

/* Corrections panel */
.eatt-corrections-panel {
    margin-top:20px;background:#fff;border:1px solid #e2e8f0;
    border-radius:12px;overflow:hidden;
    box-shadow:0 1px 3px rgba(0,0,0,.05);
}
.eatt-corr-header {
    display:flex;align-items:center;gap:8px;
    padding:14px 18px;background:#f8fafc;
    border-bottom:1px solid #e2e8f0;
    font-size:13px;font-weight:700;color:#374151;
}
.eatt-corr-badge {
    background:#ef4444;color:#fff;font-size:10px;font-weight:700;
    padding:2px 8px;border-radius:20px;
}
.eatt-corr-item {
    display:flex;align-items:center;gap:12px;
    padding:12px 18px;border-bottom:1px solid #f1f5f9;
    font-size:13px;
}
.eatt-corr-item:last-child { border-bottom:none; }

/* Modals */
.eatt-modal-backdrop {
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,0.45);z-index:1050;
    align-items:center;justify-content:center;
}
.eatt-modal-backdrop.open { display:flex; }
.eatt-modal-box {
    background:#fff;border-radius:14px;
    width:100%;max-width:520px;max-height:90vh;
    overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);
}
.eatt-modal-header {
    background:var(--sidebar-bg);color:#fff;
    padding:18px 22px;
    display:flex;align-items:center;justify-content:space-between;
    border-radius:14px 14px 0 0;
}
.eatt-modal-header h3 { font-size:16px;font-weight:700; }
.eatt-modal-close {
    background:rgba(255,255,255,.15);border:none;color:#fff;
    width:30px;height:30px;border-radius:8px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;font-size:14px;
}
.eatt-modal-close:hover { background:rgba(255,255,255,.25); }
.eatt-modal-body { padding:22px;display:flex;flex-direction:column;gap:14px; }
.eatt-modal-footer {
    display:flex;justify-content:flex-end;gap:10px;
    padding:14px 22px;border-top:1px solid #e2e8f0;
}
.eatt-detail-grid {
    display:grid;grid-template-columns:1fr 1fr;
    gap:8px 16px;background:#f8fafc;border:1px solid #e2e8f0;
    border-radius:10px;padding:14px;
}
.eatt-detail-item label { display:block;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px; }
.eatt-detail-item span  { font-size:13px;font-weight:600;color:#1e293b; }
.eatt-field label { display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px; }
.eatt-field select, .eatt-field textarea, .eatt-field input[type="date"] {
    width:100%;padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;
    font-size:13px;font-family:inherit;box-sizing:border-box;
}
.eatt-field select:focus, .eatt-field textarea:focus, .eatt-field input:focus {
    outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(29,184,154,.12);
}
.eatt-btn-cancel {
    padding:9px 20px;border-radius:8px;background:#f1f5f9;border:1px solid #e2e8f0;
    color:#64748b;font-size:13px;font-weight:600;cursor:pointer;
}
.eatt-btn-submit {
    padding:9px 20px;border-radius:8px;background:var(--accent);border:none;
    color:#fff;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s;
    display:inline-flex;align-items:center;gap:6px;
}
.eatt-btn-submit:hover { background:#17a085; }
.eatt-btn-submit:disabled { opacity:.6;cursor:not-allowed; }
.eatt-error-box {
    padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;
    border-radius:8px;color:#991b1b;font-size:13px;
}

/* Toast */
.eatt-toast {
    position:fixed;bottom:24px;right:24px;z-index:2000;
    padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;color:#fff;
    box-shadow:0 4px 16px rgba(0,0,0,.18);display:none;
    animation:eattToastIn .2s ease;
}
.eatt-toast.show  { display:block; }
.eatt-toast.ok    { background:#059669; }
.eatt-toast.error { background:#dc2626; }
@keyframes eattToastIn { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:none} }

/* Shift tag */
.att-shift-tag { display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#6366f1;font-weight:500; }

/* Apply btn */
.eatt-apply-btn {
    padding:9px 16px;border-radius:8px;background:var(--accent);color:#fff;
    border:none;font-size:13px;font-weight:600;cursor:pointer;
    display:inline-flex;align-items:center;gap:6px;
}
.eatt-apply-btn:hover { background:#17a085; }
.eatt-reset-link { font-size:12px;color:#64748b;text-decoration:none;white-space:nowrap; }
.eatt-reset-link:hover { color:var(--accent); }

/* Filter row */
.eatt-filters { display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap; }
.eatt-filters select, .eatt-filters input[type="date"] {
    padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;
    font-size:13px;background:#fff;cursor:pointer;
}
.eatt-filters select:focus, .eatt-filters input:focus { outline:none;border-color:var(--accent); }

/* File upload area */
.eatt-file-zone {
    border:2px dashed #d1d5db;border-radius:8px;padding:14px;
    text-align:center;cursor:pointer;transition:.15s;background:#f9fafb;
}
.eatt-file-zone:hover { border-color:var(--accent);background:var(--accent-light); }
.eatt-file-name { font-size:12px;color:#059669;font-weight:600;margin-top:6px; }

/* Tab context note */
.tab-ctx-note {
    display:flex;align-items:flex-start;gap:8px;
    background:#f0f9ff;border-left:3px solid #0ea5e9;
    color:#0369a1;font-size:12px;padding:8px 14px;border-radius:0 6px 6px 0;
    margin-bottom:14px;
}
.tab-ctx-note i { margin-top:1px;flex-shrink:0;color:#0ea5e9; }

/* Pending badge */
.eatt-pending-badge {
    display:inline-flex;align-items:center;gap:5px;
    padding:5px 12px;border-radius:99px;
    background:#fef3c7;color:#92400e;border:1px solid #fde68a;
    font-size:12px;font-weight:600;text-decoration:none;
}
</style>

<body>
<div class="layout">
<?php
if (isAdmin()):
    include __DIR__ . '/../../../includes/sidebar.php';
elseif (isPrincipalRole()):
    include __DIR__ . '/../../../includes/principal-sidebar.php';
else:
    include __DIR__ . '/../../../includes/employee-sidebar.php';
endif;
?>

<div class="main">
  <?php $empPortalIcon = 'fa-user-clock'; include __DIR__ . '/../../../includes/employee-header.php'; ?>

  <div class="main-content">
  <div class="emp-page">

    <!-- Page Header -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
      <div>
        <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 2px;">My Attendance</h1>
        <p style="font-size:12px;color:#94a3b8;margin:0;">View your attendance records, review status, and submit corrections.</p>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <?php if ($pendingCorrections > 0): ?>
        <a href="#corrPanel" class="eatt-pending-badge">
          <i class="fa fa-flag"></i> <?= $pendingCorrections ?> Pending Correction<?= $pendingCorrections !== 1 ? 's' : '' ?>
        </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Summary KPI Cards — Current Month -->
    <div class="att-summary-cards eatt-6col" style="margin-bottom:20px;">
      <div class="att-sum-card green">
        <div><div class="att-sum-label">PRESENT THIS MONTH</div><div class="att-sum-val"><?= (int)($kpi['cp']??0) ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-circle-check"></i></div>
      </div>
      <div class="att-sum-card yellow">
        <div><div class="att-sum-label">LATE THIS MONTH</div><div class="att-sum-val"><?= (int)($kpi['cl']??0) ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-clock"></i></div>
      </div>
      <div class="att-sum-card orange">
        <div><div class="att-sum-label">HALF DAYS</div><div class="att-sum-val"><?= (int)($kpi['chd']??0) ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-circle-half-stroke"></i></div>
      </div>
      <div class="att-sum-card red">
        <div><div class="att-sum-label">ABSENT</div><div class="att-sum-val"><?= (int)($kpi['ca']??0) ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-circle-xmark"></i></div>
      </div>
      <div class="att-sum-card blue">
        <div><div class="att-sum-label">ON LEAVE</div><div class="att-sum-val"><?= (int)($kpi['cv']??0) ?></div></div>
        <div class="att-sum-icon"><i class="fa fa-plane-departure"></i></div>
      </div>
      <div class="att-sum-card teal">
        <div>
          <div class="att-sum-label">ATTENDANCE RATE</div>
          <div class="att-sum-val"><?= $kpiRate ?>%</div>
          <div class="eatt-rate-wrap" style="margin-top:6px;">
            <div class="eatt-rate-bar"><div class="eatt-rate-fill" style="width:<?= $kpiRate ?>%;"></div></div>
          </div>
        </div>
        <div class="att-sum-icon"><i class="fa fa-chart-line"></i></div>
      </div>
    </div>
    <p style="font-size:11px;color:#94a3b8;margin-top:-14px;margin-bottom:14px;">
      <i class="fa fa-circle-info"></i> Summary cards reflect <?= date('F Y') ?>.
    </p>

    <!-- Main Card with Tabs -->
    <div class="att-main-card">

      <!-- Tab Bar -->
      <div class="att-tabs-row">
        <button class="att-tab <?= $tab === 'month'   ? 'active' : '' ?>" onclick="switchTab('month',this)">
          <i class="fa fa-calendar"></i> This Month
        </button>
        <button class="att-tab <?= $tab === 'cutoff'  ? 'active' : '' ?>" onclick="switchTab('cutoff',this)">
          <i class="fa fa-calendar-days"></i> By Cutoff Period
        </button>
        <button class="att-tab <?= $tab === 'history' ? 'active' : '' ?>" onclick="switchTab('history',this)">
          <i class="fa fa-clock-rotate-left"></i> Attendance History
        </button>
      </div>

      <!-- Tab context note — describes the active tab's purpose -->
      <div id="tab-ctx-month"   class="tab-ctx-note <?= $tab !== 'month'   ? 'hidden' : '' ?>">
        <i class="fa fa-circle-info"></i>
        <span><strong>This Month</strong> — calendar view of your attendance for the selected month. Useful for checking daily status at a glance.</span>
      </div>
      <div id="tab-ctx-cutoff"  class="tab-ctx-note <?= $tab !== 'cutoff'  ? 'hidden' : '' ?>">
        <i class="fa fa-circle-info"></i>
        <span><strong>By Cutoff Period</strong> — payroll-aligned view (1–15 and 16–end of month). Use this tab to verify your attendance for a specific payroll cutoff before your payslip is released.</span>
      </div>
      <div id="tab-ctx-history" class="tab-ctx-note <?= $tab !== 'history' ? 'hidden' : '' ?>">
        <i class="fa fa-circle-info"></i>
        <span><strong>Attendance History</strong> — full archive with free date-range filtering. Use this tab to look up records from any past period or review patterns over time.</span>
      </div>

      <!-- ═══ THIS MONTH pane ═══════════════════════════════════════════════ -->
      <div id="tabMonth" class="att-tab-pane <?= $tab !== 'month' ? 'hidden' : '' ?>">

        <form method="GET" id="monthForm" style="margin-bottom:14px;">
          <input type="hidden" name="tab" value="month">
          <div class="eatt-filters">
            <select name="month" onchange="this.form.submit()">
              <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
              <?php endfor; ?>
            </select>
            <select name="year" onchange="this.form.submit()">
              <?php for ($y = (int)date('Y'); $y >= 2022; $y--): ?>
              <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
            <button type="submit" class="eatt-apply-btn"><i class="fa fa-filter"></i> Filter</button>
            <?php if ($month != date('n') || $year != date('Y')): ?>
            <a href="?tab=month" class="eatt-reset-link"><i class="fa fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
          </div>
        </form>

        <p class="att-period-note">
          <i class="fa fa-circle-info"></i>
          <strong><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></strong>
          &nbsp;·&nbsp; <?= count($mRecords) ?> record<?= count($mRecords) !== 1 ? 's' : '' ?>
        </p>

        <?php if (empty($mRecords)): ?>
        <div class="att-no-data">
          <i class="fa fa-calendar-xmark" style="font-size:28px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
          No attendance records for <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>.
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="att-table">
          <thead><tr>
            <th>Date</th><th>Day</th><th>Shift</th>
            <th style="text-align:center;">Time In</th>
            <th style="text-align:center;">Time Out</th>
            <th style="text-align:center;">Status</th>
            <th>Method</th>
            <?php if ($hasMig007): ?><th style="text-align:center;">Review</th><?php endif; ?>
            <th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($mRecords as $r) echo $renderRow($r); ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>

      </div><!-- #tabMonth -->

      <!-- ═══ CUTOFF PERIOD pane ════════════════════════════════════════════ -->
      <div id="tabCutoff" class="att-tab-pane <?= $tab !== 'cutoff' ? 'hidden' : '' ?>">

        <form method="GET" id="cutoffForm" style="margin-bottom:14px;">
          <input type="hidden" name="tab" value="cutoff">
          <div class="eatt-filters">
            <div class="att-cutoff-sel-wrap">
              <i class="fa fa-calendar-days"></i>
              <select name="cutoff" onchange="this.form.submit()">
                <?php foreach ($cutoffOptions as $co): ?>
                <option value="<?= $co['val'] ?>" <?= $cutoffPeriod === $co['val'] ? 'selected' : '' ?>><?= $co['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="eatt-apply-btn"><i class="fa fa-filter"></i> Apply</button>
          </div>
        </form>

        <!-- Cutoff summary mini-stats -->
        <div class="att-cutoff-stats">
          <div class="co-stat"><div class="co-label">WORKING DAYS</div><div class="co-val"><?= $coWd ?></div></div>
          <div class="co-stat green"><div class="co-label">PRESENT</div><div class="co-val"><?= (int)($coStats['cp']??0) ?></div></div>
          <div class="co-stat yellow"><div class="co-label">LATE</div><div class="co-val"><?= (int)($coStats['cl']??0) ?></div></div>
          <div class="co-stat orange"><div class="co-label">HALF DAY</div><div class="co-val"><?= (int)($coStats['chd']??0) ?></div></div>
          <div class="co-stat red"><div class="co-label">ABSENT</div><div class="co-val"><?= (int)($coStats['ca']??0) ?></div></div>
          <div class="co-stat blue"><div class="co-label">ON LEAVE</div><div class="co-val"><?= (int)($coStats['cv']??0) ?></div></div>
        </div>

        <!-- Attendance rate bar -->
        <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;margin-bottom:14px;font-size:13px;">
          <span style="font-size:12px;color:#065f46;font-weight:600;white-space:nowrap;">Cutoff Attendance Rate</span>
          <div class="eatt-rate-bar" style="max-width:200px;">
            <div class="eatt-rate-fill" style="width:<?= $coRate ?>%;"></div>
          </div>
          <span style="font-size:20px;font-weight:800;color:#0f766e;white-space:nowrap;"><?= $coRate ?>%</span>
        </div>

        <p class="att-period-note">
          <i class="fa fa-circle-info"></i>
          Period: <strong><?= date('M j', strtotime($cutoffStart)) ?> – <?= date('M j, Y', strtotime($cutoffEnd)) ?></strong>
          &nbsp;·&nbsp; <?= count($coDays) ?> record<?= count($coDays) !== 1 ? 's' : '' ?>
        </p>

        <?php if (empty($coDays)): ?>
        <div class="att-no-data">
          <i class="fa fa-calendar-xmark" style="font-size:28px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
          No records for this cutoff period.
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="att-table">
          <thead><tr>
            <th>Date</th><th>Day</th><th>Shift</th>
            <th style="text-align:center;">Time In</th>
            <th style="text-align:center;">Time Out</th>
            <th style="text-align:center;">Status</th>
            <th>Method</th>
            <?php if ($hasMig007): ?><th style="text-align:center;">Review</th><?php endif; ?>
            <th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($coDays as $r) echo $renderRow($r); ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>

      </div><!-- #tabCutoff -->

      <!-- ═══ HISTORY pane ══════════════════════════════════════════════════ -->
      <div id="tabHistory" class="att-tab-pane <?= $tab !== 'history' ? 'hidden' : '' ?>">

        <form method="GET" id="historyForm">
          <input type="hidden" name="tab" value="history">
          <div class="eatt-filters">
            <input type="date" name="hstart" value="<?= htmlspecialchars($hStart) ?>">
            <span style="font-size:12px;color:#64748b;">to</span>
            <input type="date" name="hend" value="<?= htmlspecialchars($hEnd) ?>">
            <select name="hstatus">
              <option value="">All Status</option>
              <option value="PRESENT"  <?= $hStatus === 'PRESENT'  ? 'selected' : '' ?>>Present</option>
              <option value="LATE"     <?= $hStatus === 'LATE'     ? 'selected' : '' ?>>Late</option>
              <option value="HALF_DAY" <?= $hStatus === 'HALF_DAY' ? 'selected' : '' ?>>Half Day</option>
              <option value="ABSENT"   <?= $hStatus === 'ABSENT'   ? 'selected' : '' ?>>Absent</option>
              <option value="LEAVE"    <?= $hStatus === 'LEAVE'    ? 'selected' : '' ?>>On Leave</option>
              <option value="HOLIDAY"  <?= $hStatus === 'HOLIDAY'  ? 'selected' : '' ?>>Holiday</option>
            </select>
            <select name="hmth">
              <option value="">All Methods</option>
              <option value="MANUAL_ADMIN"       <?= $hMethod === 'MANUAL_ADMIN'       ? 'selected' : '' ?>>Manual (Admin)</option>
              <option value="MANUAL"             <?= $hMethod === 'MANUAL'             ? 'selected' : '' ?>>Manual</option>
              <option value="FACIAL_RECOGNITION" <?= $hMethod === 'FACIAL_RECOGNITION' ? 'selected' : '' ?>>Biometric</option>
              <option value="RFID"               <?= $hMethod === 'RFID'               ? 'selected' : '' ?>>RFID</option>
              <option value="AUTO"               <?= $hMethod === 'AUTO'               ? 'selected' : '' ?>>Auto-Tagged</option>
            </select>
            <button type="submit" class="eatt-apply-btn"><i class="fa fa-filter"></i> Apply</button>
            <?php if ($hStart !== date('Y-m-01') || $hEnd !== $today || $hStatus !== '' || $hMethod !== ''): ?>
            <a href="?tab=history" class="eatt-reset-link"><i class="fa fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
          </div>
        </form>

        <p class="att-period-note">
          <i class="fa fa-circle-info"></i>
          <?= $hTotal ?> record<?= $hTotal !== 1 ? 's' : '' ?> from
          <strong><?= date('M j, Y', strtotime($hStart)) ?></strong> to
          <strong><?= date('M j, Y', strtotime($hEnd)) ?></strong>
        </p>

        <?php if (empty($hRows)): ?>
        <div class="att-no-data">
          <i class="fa fa-clock-rotate-left" style="font-size:28px;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
          No records found for the selected filters.
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="att-table">
          <thead><tr>
            <th>Date</th><th>Day</th><th>Shift</th>
            <th style="text-align:center;">Time In</th>
            <th style="text-align:center;">Time Out</th>
            <th style="text-align:center;">Status</th>
            <th>Method</th>
            <?php if ($hasMig007): ?><th style="text-align:center;">Review</th><?php endif; ?>
            <th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($hRows as $r) echo $renderRow($r); ?>
          </tbody>
        </table>
        </div>

        <!-- History Pagination -->
        <div class="att-pagination">
          <span>Showing <?= $hTotal > 0 ? $hOffset + 1 : 0 ?>–<?= min($hOffset + $hLimit, $hTotal) ?> of <?= $hTotal ?></span>
          <div class="att-pg-btns">
            <a class="att-pg <?= $hPage <= 1 ? 'disabled' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['tab'=>'history','hpage'=>max(1,$hPage-1)])) ?>">
              <i class="fa fa-chevron-left" style="font-size:10px;"></i> Prev
            </a>
            <?php foreach (range(max(1,$hPage-2), min($hTotalPages,$hPage+2)) as $pg): ?>
            <a class="att-pg <?= $pg===$hPage ? 'active' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['tab'=>'history','hpage'=>$pg])) ?>"><?= $pg ?></a>
            <?php endforeach; ?>
            <a class="att-pg <?= $hPage >= $hTotalPages ? 'disabled' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['tab'=>'history','hpage'=>min($hTotalPages,$hPage+1)])) ?>">
              Next <i class="fa fa-chevron-right" style="font-size:10px;"></i>
            </a>
          </div>
        </div>
        <?php endif; ?>

      </div><!-- #tabHistory -->

    </div><!-- .att-main-card -->

    <!-- Correction Requests Panel -->
    <?php if ($hasCorrections): ?>
    <div class="eatt-corrections-panel" id="corrPanel" style="margin-top:20px;">
      <div class="eatt-corr-header">
        <i class="fa fa-flag" style="color:var(--accent);"></i>
        My Correction Requests
        <?php if ($pendingCorrections > 0): ?>
        <span class="eatt-corr-badge"><?= $pendingCorrections ?> Pending</span>
        <?php endif; ?>
        <button class="eatt-apply-btn" style="margin-left:auto;font-size:12px;padding:5px 12px;" onclick="openCorrectionModal(0,'')">
          <i class="fa fa-plus"></i> New Request
        </button>
      </div>
      <?php if (empty($myCorrections)): ?>
      <div style="padding:24px 18px;text-align:center;color:#94a3b8;font-size:13px;">
        <i class="fa fa-clipboard" style="font-size:24px;display:block;margin-bottom:8px;opacity:.3;"></i>
        No correction requests submitted yet.
      </div>
      <?php else: ?>
      <?php foreach ($myCorrections as $corr):
          [$corrLabel, $corrCls] = $CORR_STATUS_LABELS[$corr['status']] ?? ['Unknown', 'rv-pending'];
          $issueLabel = $ISSUE_LABELS[$corr['issue_type']] ?? $corr['issue_type'];
      ?>
      <div class="eatt-corr-item">
        <div style="flex:1;min-width:0;">
          <div style="font-weight:600;color:#0f172a;font-size:13px;">
            <?= date('M j, Y', strtotime($corr['attendance_date'])) ?>
          </div>
          <div style="font-size:12px;color:#64748b;margin-top:2px;"><?= htmlspecialchars($issueLabel) ?></div>
        </div>
        <div style="font-size:11px;color:#94a3b8;">
          <?= date('M j, Y', strtotime($corr['created_at'])) ?>
        </div>
        <span class="att-rv-badge <?= $corrCls ?>"><?= htmlspecialchars($corrLabel) ?></span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div><!-- .emp-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- ── View Details Modal ──────────────────────────────────────────────────── -->
<div class="eatt-modal-backdrop" id="detailsModal" onclick="if(event.target===this)closeDetailsModal()">
  <div class="eatt-modal-box">
    <div class="eatt-modal-header">
      <h3><i class="fa fa-eye" style="margin-right:8px;"></i>Attendance Details</h3>
      <button class="eatt-modal-close" onclick="closeDetailsModal()"><i class="fa fa-times"></i></button>
    </div>
    <div class="eatt-modal-body">
      <div class="eatt-detail-grid">
        <div class="eatt-detail-item"><label>Date</label><span id="dDate">—</span></div>
        <div class="eatt-detail-item"><label>Day</label><span id="dDay">—</span></div>
        <div class="eatt-detail-item"><label>Shift</label><span id="dShift">—</span></div>
        <div class="eatt-detail-item"><label>Method</label><span id="dMethod">—</span></div>
        <div class="eatt-detail-item"><label>Time In</label><span id="dTimeIn">—</span></div>
        <div class="eatt-detail-item"><label>Time Out</label><span id="dTimeOut">—</span></div>
        <div class="eatt-detail-item"><label>Late (min)</label><span id="dLate">—</span></div>
        <div class="eatt-detail-item"><label>Overtime (min)</label><span id="dOT">—</span></div>
        <div class="eatt-detail-item"><label>Status</label><span id="dStatus">—</span></div>
        <div class="eatt-detail-item"><label>Review Status</label><span id="dRv">—</span></div>
        <div class="eatt-detail-item" style="grid-column:span 2;"><label>Admin Notes / Remarks</label><span id="dRemarks">—</span></div>
      </div>
      <?php if ($hasMig007): ?>
      <div style="font-size:11px;color:#94a3b8;text-align:center;">
        <i class="fa fa-circle-info"></i> Review status reflects principal's verification. Contact admin for corrections.
      </div>
      <?php endif; ?>
    </div>
    <div class="eatt-modal-footer">
      <?php if ($hasCorrections): ?>
      <button class="eatt-btn-submit" style="background:var(--sidebar-bg);" id="detailsToCorrectBtn" onclick="switchToCorrect()">
        <i class="fa fa-flag"></i> Request Correction
      </button>
      <?php endif; ?>
      <button class="eatt-btn-cancel" onclick="closeDetailsModal()">Close</button>
    </div>
  </div>
</div>

<!-- ── Request Correction Modal ───────────────────────────────────────────── -->
<?php if ($hasCorrections): ?>
<div class="eatt-modal-backdrop" id="corrModal" onclick="if(event.target===this)closeCorrectionModal()">
  <div class="eatt-modal-box">
    <div class="eatt-modal-header" style="background:var(--sidebar-bg);">
      <h3><i class="fa fa-flag" style="margin-right:8px;"></i>Attendance Correction Request</h3>
      <button class="eatt-modal-close" onclick="closeCorrectionModal()"><i class="fa fa-times"></i></button>
    </div>
    <div class="eatt-modal-body">

      <div style="padding:10px 14px;background:var(--accent-light);border:1px solid var(--accent-mid);border-radius:8px;font-size:12px;color:#065f46;">
        <i class="fa fa-circle-info"></i>
        Your request will be sent to admin for review. This does <strong>not</strong> automatically change your record.
      </div>

      <div id="corrError" class="eatt-error-box" style="display:none;"></div>

      <div class="eatt-field">
        <label>Attendance Date <span style="color:#dc2626;">*</span></label>
        <input type="date" id="corrDate" max="<?= $today ?>">
        <input type="hidden" id="corrAttId">
      </div>

      <div class="eatt-field">
        <label>Issue Type <span style="color:#dc2626;">*</span></label>
        <select id="corrIssueType">
          <option value="">— Select issue —</option>
          <option value="MISSING_TIME_IN">Missing Time In</option>
          <option value="MISSING_TIME_OUT">Missing Time Out</option>
          <option value="WRONG_STATUS">Wrong Status Recorded</option>
          <option value="LATE_INCORRECT">Late Incorrectly Marked</option>
          <option value="OTHER">Other</option>
        </select>
      </div>

      <div class="eatt-field">
        <label>Explanation <span style="color:#dc2626;">*</span></label>
        <textarea id="corrExplanation" rows="4"
                  placeholder="Please describe the issue clearly. Include what happened and what the correct record should be."
                  style="resize:vertical;"></textarea>
      </div>

      <div class="eatt-field">
        <label>Supporting Proof <span style="color:#94a3b8;font-weight:400;">(optional — JPG, PNG, PDF, max 5 MB)</span></label>
        <div class="eatt-file-zone" onclick="document.getElementById('corrFile').click()"
             ondragover="event.preventDefault();this.style.borderColor='#1db89a'" ondrop="handleCorrFileDrop(event)">
          <i class="fa fa-cloud-arrow-up" style="font-size:22px;color:#94a3b8;display:block;margin-bottom:6px;"></i>
          <span style="font-size:13px;color:#64748b;">Click to browse or drag a file here</span>
          <div id="corrFileName" class="eatt-file-name"></div>
        </div>
        <input type="file" id="corrFile" accept=".jpg,.jpeg,.png,.pdf" style="display:none;" onchange="corrFileSelected(this)">
      </div>

    </div>
    <div class="eatt-modal-footer">
      <button class="eatt-btn-cancel" onclick="closeCorrectionModal()">Cancel</button>
      <button class="eatt-btn-submit" id="corrSubmitBtn" onclick="submitCorrection()">
        <i class="fa fa-paper-plane"></i> Submit Request
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="eattToast" class="eatt-toast"></div>

<script>
// ── Tab switching ──────────────────────────────────────────────────────────────
const _eattTabMap = { month:'Month', cutoff:'Cutoff', history:'History' };
function switchTab(name, btn) {
    document.querySelectorAll('.att-tab-pane').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.att-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-ctx-note').forEach(n => n.classList.add('hidden'));
    document.getElementById('tab' + _eattTabMap[name]).classList.remove('hidden');
    const ctx = document.getElementById('tab-ctx-' + name);
    if (ctx) ctx.classList.remove('hidden');
    if (btn) btn.classList.add('active');
}

// ── Toast ──────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    const t = document.getElementById('eattToast');
    t.textContent = msg;
    t.className = 'eatt-toast show ' + (type === 'ok' ? 'ok' : 'error');
    setTimeout(() => t.classList.remove('show'), 3500);
}

// ── View Details Modal ─────────────────────────────────────────────────────────
let _currentDetails = null;

function openDetailsModal(data) {
    _currentDetails = data;
    const fmt12h = t => {
        if (!t) return '—';
        const [h, m] = t.split(':').map(Number);
        return (h % 12 || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h >= 12 ? 'PM' : 'AM');
    };
    document.getElementById('dDate').textContent    = data.date || '—';
    document.getElementById('dDay').textContent     = data.day  || '—';
    document.getElementById('dShift').textContent   = data.shift || '—';
    document.getElementById('dMethod').textContent  = data.srcLabel || '—';
    document.getElementById('dTimeIn').textContent  = fmt12h(data.timeIn);
    document.getElementById('dTimeOut').textContent = fmt12h(data.timeOut);
    document.getElementById('dLate').textContent    = data.lateMin > 0 ? data.lateMin + ' min' : '—';
    document.getElementById('dOT').textContent      = data.otMin   > 0 ? data.otMin   + ' min' : '—';
    document.getElementById('dRemarks').textContent = data.remarks || '—';

    const stEl = document.getElementById('dStatus');
    stEl.innerHTML = `<span class="att-badge ${data.status.toLowerCase()}">${data.statusLabel}</span>`;

    const rvEl = document.getElementById('dRv');
    rvEl.innerHTML = `<span class="att-rv-badge rv-${data.rv.toLowerCase()}">${data.rvLabel}</span>`;

    document.getElementById('detailsModal').classList.add('open');
}

function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('open');
    _currentDetails = null;
}

function switchToCorrect() {
    if (_currentDetails) {
        closeDetailsModal();
        openCorrectionModal(_currentDetails.id, _currentDetails.date);
    }
}

<?php if ($hasCorrections): ?>
// ── Correction Modal ───────────────────────────────────────────────────────────
function openCorrectionModal(attId, date) {
    document.getElementById('corrAttId').value = attId || '';
    document.getElementById('corrDate').value  = date  || '';
    document.getElementById('corrIssueType').value   = '';
    document.getElementById('corrExplanation').value = '';
    document.getElementById('corrFile').value = '';
    document.getElementById('corrFileName').textContent = '';
    document.getElementById('corrError').style.display = 'none';
    document.getElementById('corrModal').classList.add('open');
}

function closeCorrectionModal() {
    document.getElementById('corrModal').classList.remove('open');
}

function corrFileSelected(input) {
    const name = input.files[0]?.name || '';
    document.getElementById('corrFileName').textContent = name ? '📎 ' + name : '';
}

function handleCorrFileDrop(e) {
    e.preventDefault();
    e.currentTarget.style.borderColor = '';
    const file = e.dataTransfer.files[0];
    if (file) {
        const dt  = new DataTransfer();
        dt.items.add(file);
        const inp = document.getElementById('corrFile');
        inp.files = dt.files;
        corrFileSelected(inp);
    }
}

function submitCorrection() {
    const date        = document.getElementById('corrDate').value;
    const issueType   = document.getElementById('corrIssueType').value;
    const explanation = document.getElementById('corrExplanation').value.trim();
    const errBox      = document.getElementById('corrError');
    const btn         = document.getElementById('corrSubmitBtn');

    errBox.style.display = 'none';
    if (!date)        { errBox.textContent = 'Please select an attendance date.'; errBox.style.display = 'block'; return; }
    if (!issueType)   { errBox.textContent = 'Please select an issue type.';     errBox.style.display = 'block'; return; }
    if (!explanation) { errBox.textContent = 'Please provide an explanation.';   errBox.style.display = 'block'; return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting...';

    const fd = new FormData();
    fd.append('attendance_id',   document.getElementById('corrAttId').value);
    fd.append('attendance_date', date);
    fd.append('issue_type',      issueType);
    fd.append('explanation',     explanation);
    const fileInp = document.getElementById('corrFile');
    if (fileInp.files.length > 0) fd.append('attachment', fileInp.files[0]);

    fetch('<?= BASE_URL ?>actions/employee-submit-correction.php', { method: 'POST', body: fd })
        .then(r => {
            if (!r.headers.get('content-type')?.includes('application/json'))
                return r.text().then(t => { throw new Error(t.substring(0, 200)); });
            return r.json();
        })
        .then(data => {
            if (data.success) {
                closeCorrectionModal();
                showToast(data.message, 'ok');
                setTimeout(() => location.reload(), 1800);
            } else {
                errBox.textContent   = data.message || 'Failed to submit.';
                errBox.style.display = 'block';
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Request';
            }
        })
        .catch(err => {
            errBox.textContent   = 'Error: ' + (err.message || 'Network error.');
            errBox.style.display = 'block';
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Request';
        });
}
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
