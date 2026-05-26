<?php
/**
 * actions/leave-file.php
 * Submits a new leave request.
 *
 * Date validation rules:
 *   allow_future = 0 (Sick, Emergency): only past/current dates allowed
 *   allow_future = 1 (Vacation, Maternity, Paternity): all dates allowed
 *   allow_backdated = 1 (Sick, Emergency): explanation required when past dates selected
 *
 * POST params:
 *   filing_mode     = SINGLE | MULTIPLE | RANGE
 *   leave_type_id   = int
 *   reason          = string
 *   dates[]         = YYYY-MM-DD  (SINGLE and MULTIPLE modes)
 *   date_from       = YYYY-MM-DD  (RANGE mode)
 *   date_to         = YYYY-MM-DD  (RANGE mode)
 *   backdate_reason = string (required for Sick/Emergency when past dates selected)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

requireLogin();

$employeeId     = $_SESSION['user']['employee_id'] ?? null;
$userId         = $_SESSION['user']['user_id'] ?? null;
$leaveTypeId    = (int)($_POST['leave_type_id'] ?? 0);
$reason         = trim($_POST['reason'] ?? '');
$backdateReason = trim($_POST['backdate_reason'] ?? '');
$filingMode     = strtoupper(trim($_POST['filing_mode'] ?? 'MULTIPLE'));

if (!in_array($filingMode, ['SINGLE', 'MULTIPLE', 'RANGE'], true)) {
    $filingMode = 'MULTIPLE';
}

// ── Basic validation ──────────────────────────────────────────────────────────
if (!$employeeId) {
    echo json_encode(['success' => false, 'message' => 'No employee linked to this account.']);
    exit;
}
if (!$leaveTypeId) {
    echo json_encode(['success' => false, 'message' => 'Please select a leave type.']);
    exit;
}
if (!$reason) {
    echo json_encode(['success' => false, 'message' => 'Please enter a reason.']);
    exit;
}

// ── Build date list ───────────────────────────────────────────────────────────
$rawDates = [];

if ($filingMode === 'RANGE') {
    $dateFrom = trim($_POST['date_from'] ?? '');
    $dateTo   = trim($_POST['date_to']   ?? '');

    if (!$dateFrom || !$dateTo) {
        echo json_encode(['success' => false, 'message' => 'Please provide both From and To dates.']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
        exit;
    }
    if ($dateTo < $dateFrom) {
        echo json_encode(['success' => false, 'message' => 'End date cannot be before start date.']);
        exit;
    }

    $d1       = new DateTime($dateFrom);
    $d2       = new DateTime($dateTo);
    $interval = new DateInterval('P1D');
    $period   = new DatePeriod($d1, $interval, (clone $d2)->modify('+1 day'));
    foreach ($period as $dt) {
        $rawDates[] = $dt->format('Y-m-d');
    }

    if (count($rawDates) > 90) {
        echo json_encode(['success' => false, 'message' => 'Date range cannot exceed 90 days.']);
        exit;
    }
} else {
    $rawDates = $_POST['dates'] ?? [];
}

// Sanitise and sort
$cleanDates = [];
foreach ($rawDates as $d) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $cleanDates[] = $d;
    }
}
$cleanDates = array_unique($cleanDates);
sort($cleanDates);

if (empty($cleanDates)) {
    echo json_encode(['success' => false, 'message' => 'Please select at least one date.']);
    exit;
}

// Single mode: enforce exactly one date
if ($filingMode === 'SINGLE' && count($cleanDates) > 1) {
    $cleanDates = [$cleanDates[0]];
}

$today = date('Y-m-d');

// ── Fetch leave type flags from database (migration-safe) ─────────────────────
$allowBackdated = 0;
$allowFuture    = 1; // safe default

$hasMig016Type = (bool)$pdo->query("SHOW COLUMNS FROM `leave_types` LIKE 'allow_backdated'")->fetch();
$hasMig017Type = (bool)$pdo->query("SHOW COLUMNS FROM `leave_types` LIKE 'allow_future'")->fetch();

if ($hasMig016Type || $hasMig017Type) {
    $colSel = 'leave_type_id';
    if ($hasMig016Type) $colSel .= ', allow_backdated';
    if ($hasMig017Type) $colSel .= ', allow_future';

    $stmtType = $pdo->prepare("SELECT {$colSel} FROM leave_types WHERE leave_type_id = ?");
    $stmtType->execute([$leaveTypeId]);
    $typeRow = $stmtType->fetch();

    if ($typeRow) {
        $allowBackdated = $hasMig016Type ? (int)($typeRow['allow_backdated'] ?? 0) : 0;
        // Derive allow_future: if column missing but allow_backdated exists,
        // infer that backdatable types (sick/emergency) do not allow future dates.
        if ($hasMig017Type) {
            $allowFuture = (int)($typeRow['allow_future'] ?? 1);
        } elseif ($hasMig016Type) {
            $allowFuture = $allowBackdated ? 0 : 1;
        }
    }
}

// ── Date rule validation ──────────────────────────────────────────────────────
$hasFutureDates = false;
$hasPastDates   = false;
foreach ($cleanDates as $d) {
    if ($d > $today) $hasFutureDates = true;
    if ($d < $today) $hasPastDates   = true;
}

// Block future dates for leave types that don't allow them (Sick, Emergency)
if ($hasFutureDates && !$allowFuture) {
    echo json_encode([
        'success' => false,
        'message' => 'Future dates are not allowed for this leave type. Please select today or earlier dates only.',
    ]);
    exit;
}

// Require explanation when filing past dates for Sick/Emergency leave
$isBackdated = 0;
if ($hasPastDates && $allowBackdated) {
    if (!$backdateReason) {
        echo json_encode([
            'success' => false,
            'message' => 'Please explain why this leave is being filed after the date(s) occurred.',
        ]);
        exit;
    }
    $isBackdated = 1;
}

$startDate = $cleanDates[0];
$endDate   = end($cleanDates);
$totalDays = count($cleanDates);

// ── Resolve active school year ────────────────────────────────────────────────
$stmtSY       = $pdo->query("SELECT school_year_id FROM school_years WHERE is_active = 1 LIMIT 1");
$schoolYearId = $stmtSY ? ($stmtSY->fetchColumn() ?: null) : null;

// ── Migration guards for leave_requests columns ───────────────────────────────
$hasMig016LR = (bool)$pdo->query("SHOW COLUMNS FROM `leave_requests` LIKE 'workflow_status'")->fetch();
$hasMig017LR = (bool)$pdo->query("SHOW COLUMNS FROM `leave_requests` LIKE 'filing_mode'")->fetch();

try {
    $pdo->beginTransaction();

    // Build dynamic INSERT
    $cols   = ['employee_id', 'leave_type_id', 'school_year_id', 'reason',
                'start_date', 'end_date', 'total_days', 'status', 'created_at', 'updated_at'];
    $vals   = ['?', '?', '?', '?', '?', '?', '?', "'PENDING'", 'NOW()', 'NOW()'];
    $params = [$employeeId, $leaveTypeId, $schoolYearId, $reason,
               $startDate, $endDate, $totalDays];

    if ($hasMig016LR) {
        $cols[]   = 'workflow_status'; $vals[]   = "'PENDING_REVIEW'";
        $cols[]   = 'is_backdated';    $vals[]   = '?'; $params[] = $isBackdated;
        $cols[]   = 'backdate_reason'; $vals[]   = '?'; $params[] = $backdateReason ?: null;
    }
    if ($hasMig017LR) {
        $cols[]   = 'filing_mode';     $vals[]   = '?'; $params[] = $filingMode;
    }

    $pdo->prepare(
        'INSERT INTO leave_requests (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')'
    )->execute($params);
    $leaveId = (int)$pdo->lastInsertId();

    // One row per date in leave_request_dates
    $stmtDate = $pdo->prepare(
        "INSERT INTO leave_request_dates (leave_id, leave_date, status, created_at) VALUES (?, ?, 'PENDING', NOW())"
    );
    foreach ($cleanDates as $date) {
        $stmtDate->execute([$leaveId, $date]);
    }

    // Audit log
    $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
         VALUES (?, 'CREATE', 'leave_requests', ?, ?, NOW())"
    )->execute([
        $userId, $leaveId,
        "Filed {$filingMode} leave #{$leaveId} for {$totalDays} date(s) starting {$startDate}"
        . ($isBackdated ? ' [BACKDATED]' : ''),
    ]);

    // Optional attachment upload
    $attachFile = $_FILES['attachment'] ?? null;
    if ($attachFile && $attachFile['error'] === UPLOAD_ERR_OK) {
        if ($attachFile['size'] <= 5 * 1024 * 1024) {
            $uploadDir = __DIR__ . '/../uploads/leaves/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($attachFile['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                $fileName = 'leave_' . $leaveId . '_' . time() . '.' . $ext;
                $filePath = 'uploads/leaves/' . $fileName;
                if (move_uploaded_file($attachFile['tmp_name'], $uploadDir . $fileName)) {
                    $pdo->prepare(
                        "INSERT INTO leave_attachments
                             (leave_id, file_path, file_name, file_type, file_size)
                         VALUES (?, ?, ?, ?, ?)"
                    )->execute([
                        $leaveId, $filePath, $attachFile['name'],
                        $attachFile['type'], $attachFile['size'],
                    ]);
                }
            }
        }
    }

    $pdo->commit();

    echo json_encode([
        'success'  => true,
        'message'  => "Leave request submitted for {$totalDays} date(s)!"
                    . ($isBackdated ? ' (Backdated filing noted.)' : ''),
        'leave_id' => $leaveId,
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
