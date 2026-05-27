<?php
/**
 * actions/create-payroll-periods.php
 * Creates payroll period records for a given month/year (or full year).
 * Uses cutoff settings from payroll_settings.
 * Returns JSON.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

/**
 * Advance a date to the previous Friday if it falls on a Saturday or Sunday.
 * Returns the date unchanged on any other weekday.
 */
function advancePayDate(string $dateStr): string {
    $ts  = strtotime($dateStr);
    $dow = (int)date('N', $ts); // ISO 8601: 1=Mon … 7=Sun
    if ($dow === 6) return date('Y-m-d', strtotime('-1 day',  $ts)); // Sat → Fri
    if ($dow === 7) return date('Y-m-d', strtotime('-2 days', $ts)); // Sun → Fri
    return $dateStr;
}

// ── Update handler (edit OPEN period name / pay date) ───────────────────────
if (($_POST['action'] ?? '') === 'update') {
    $periodId   = (int)($_POST['period_id']  ?? 0);
    $periodName = trim($_POST['period_name'] ?? '');
    $payDate    = trim($_POST['pay_date']    ?? '');

    if (!$periodId || !$periodName || !$payDate) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
        echo json_encode(['success' => false, 'message' => 'Invalid pay date format.']);
        exit;
    }

    $rowStmt = $pdo->prepare("SELECT status FROM payroll_periods WHERE period_id = ?");
    $rowStmt->execute([$periodId]);
    $periodRow = $rowStmt->fetch();

    if (!$periodRow) {
        echo json_encode(['success' => false, 'message' => 'Period not found.']);
        exit;
    }
    if ($periodRow['status'] !== 'OPEN') {
        echo json_encode(['success' => false, 'message' => 'Only OPEN periods can be edited.']);
        exit;
    }

    $pdo->prepare("UPDATE payroll_periods SET period_name = ?, pay_date = ? WHERE period_id = ? AND status = 'OPEN'")
        ->execute([$periodName, $payDate, $periodId]);

    $uid = $_SESSION['user']['user_id'] ?? null;
    if ($uid) {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?,?,?,?,?)")
            ->execute([$uid, 'UPDATE', 'payroll_periods', $periodId, "Edited pay period #{$periodId}: name/pay_date"]);
    }

    echo json_encode(['success' => true, 'message' => 'Pay period updated.']);
    exit;
}

// ── Delete handler ──────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'delete') {
    $periodId = (int)($_POST['period_id'] ?? 0);
    if (!$periodId) {
        echo json_encode(['success' => false, 'message' => 'Invalid period ID.']);
        exit;
    }
    // Only allow deleting OPEN periods with no payroll records
    $status = $pdo->prepare("SELECT status FROM payroll_periods WHERE period_id = ?");
    $status->execute([$periodId]);
    $row = $status->fetch();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Period not found.']);
        exit;
    }
    if ($row['status'] !== 'OPEN') {
        echo json_encode(['success' => false, 'message' => 'Only OPEN periods can be deleted.']);
        exit;
    }
    $hasRecords = $pdo->prepare("SELECT COUNT(*) FROM payroll_records WHERE period_id = ?");
    $hasRecords->execute([$periodId]);
    if ((int)$hasRecords->fetchColumn() > 0) {
        echo json_encode(['success' => false,
            'message' => 'Cannot delete — this period already has payroll records. Delete the records first.']);
        exit;
    }
    $pdo->prepare("DELETE FROM payroll_periods WHERE period_id = ? AND status = 'OPEN'")
        ->execute([$periodId]);
    echo json_encode(['success' => true, 'message' => 'Pay period deleted.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// ── Create ACCRUED_PAY period ───────────────────────────────────────────────
if (($_POST['create_for'] ?? '') === 'accrued_pay') {
    $periodName = trim($_POST['period_name']      ?? '');
    $startDate  = trim($_POST['pay_period_start'] ?? '');
    $endDate    = trim($_POST['pay_period_end']   ?? '');
    $payDate    = trim($_POST['pay_date']         ?? '');

    $dateRx = '/^\d{4}-\d{2}-\d{2}$/';
    if (!$periodName) {
        echo json_encode(['success' => false, 'message' => 'Period name is required.']);
        exit;
    }
    if (!preg_match($dateRx, $startDate) || !preg_match($dateRx, $endDate) || !preg_match($dateRx, $payDate)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
        exit;
    }
    if ($startDate > $endDate) {
        echo json_encode(['success' => false, 'message' => 'Period start must be before period end.']);
        exit;
    }

    // Check if period_type column exists (migration 020)
    $hasPeriodType = false;
    try {
        $hasPeriodType = (bool)$pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'payroll_periods'
              AND COLUMN_NAME  = 'period_type'
        ")->fetchColumn();
    } catch (PDOException $_e) {}

    if (!$hasPeriodType) {
        echo json_encode(['success' => false, 'message' => 'Migration 020 has not been run. Please run migrations/020_accrued_pay.sql first.']);
        exit;
    }

    // Prevent duplicate (same name or same date range)
    $dup = $pdo->prepare("SELECT COUNT(*) FROM payroll_periods WHERE pay_period_start = ? AND pay_period_end = ?");
    $dup->execute([$startDate, $endDate]);
    if ((int)$dup->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'A period with these dates already exists.']);
        exit;
    }

    $settings = $pdo->query("SELECT * FROM payroll_settings LIMIT 1")->fetch();
    $yr = date('Y', strtotime($startDate));
    $mo = date('m', strtotime($startDate));
    $seqStmt = $pdo->prepare("SELECT COUNT(*) FROM payroll_periods WHERE YEAR(pay_period_start) = ? AND MONTH(pay_period_start) = ?");
    $seqStmt->execute([$yr, $mo]);
    $seq = (int)$seqStmt->fetchColumn() + 1;
    $payrollNumber = 'AP-' . $yr . '-' . $mo . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

    $pdo->prepare("
        INSERT INTO payroll_periods
            (payroll_number, period_name, period_type, pay_period_start, pay_period_end, pay_date, status)
        VALUES (?, ?, 'ACCRUED_PAY', ?, ?, ?, 'OPEN')
    ")->execute([$payrollNumber, $periodName, $startDate, $endDate, $payDate]);

    $uid = $_SESSION['user']['user_id'] ?? null;
    if ($uid) {
        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?,?,?,?,?)")
            ->execute([$uid, 'CREATE', 'payroll_periods', $newId, "Created ACCRUED_PAY period: {$periodName}"]);
    }

    echo json_encode(['success' => true, 'message' => "Accrued Pay period \"{$periodName}\" created.", 'created' => 1]);
    exit;
}

$createFor = $_POST['create_for'] ?? 'month'; // 'month' or 'year'
$month     = (int)($_POST['month'] ?? 0);
$year      = (int)($_POST['year']  ?? 0);

if (!$year || $year < 2020 || $year > 2099) {
    echo json_encode(['success' => false, 'message' => 'Invalid year.']);
    exit;
}
if ($createFor === 'month' && ($month < 1 || $month > 12)) {
    echo json_encode(['success' => false, 'message' => 'Invalid month.']);
    exit;
}

// Load cutoff settings (including weekend_pay_date_rule if migration 010 has run)
$settings    = $pdo->query("SELECT * FROM payroll_settings LIMIT 1")->fetch();
$c1Start     = (int)($settings['cutoff1_start_day']   ?? 1);
$c1End       = (int)($settings['cutoff1_end_day']     ?? 15);
$c2Start     = (int)($settings['cutoff2_start_day']   ?? 16);
$c2End       = (int)($settings['cutoff2_end_day']     ?? 31);
$weekendRule = $settings['weekend_pay_date_rule']      ?? 'ADVANCE';

// Months to create for
$months = $createFor === 'year'
    ? range(1, 12)
    : [$month];

$created  = 0;
$skipped  = 0;
$errors   = [];

$monthNames = ['January','February','March','April','May','June',
               'July','August','September','October','November','December'];

try {
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) FROM payroll_periods
        WHERE pay_period_start = ? AND pay_period_end = ?
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO payroll_periods
            (payroll_number, period_name, pay_period_start, pay_period_end, pay_date, status)
        VALUES (?, ?, ?, ?, ?, 'OPEN')
    ");
    // Helper: generate next sequential payroll_number for a given pay period start date.
    // Counts existing periods in the same year-month (including ones just inserted this run).
    $seqStmt = $pdo->prepare("
        SELECT COUNT(*) FROM payroll_periods
        WHERE YEAR(pay_period_start) = ? AND MONTH(pay_period_start) = ?
    ");
    $nextPayrollNumber = function(string $startDate) use ($pdo, $seqStmt): string {
        $yr = date('Y', strtotime($startDate));
        $mo = date('m', strtotime($startDate));
        $seqStmt->execute([$yr, $mo]);
        $seq = (int)$seqStmt->fetchColumn() + 1;
        return 'PR-' . $yr . '-' . $mo . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
    };

    foreach ($months as $m) {
        $mName    = $monthNames[$m - 1];
        $lastDay  = (int)date('t', mktime(0, 0, 0, $m, 1, $year));

        // Clamp cutoff end days to the actual last day of the month
        $period1End = min($c1End, $lastDay);
        $period2End = min($c2End, $lastDay);

        // Period 1: e.g. "May 1–15, 2026"
        $p1Start   = sprintf('%04d-%02d-%02d', $year, $m, $c1Start);
        $p1End     = sprintf('%04d-%02d-%02d', $year, $m, $period1End);
        $p1Name    = "{$mName} {$c1Start}–{$period1End}, {$year}";
        $p1PayRaw  = sprintf('%04d-%02d-%02d', $year, $m, $period1End);
        $p1Pay     = $weekendRule === 'ADVANCE' ? advancePayDate($p1PayRaw) : $p1PayRaw;

        // Period 2: e.g. "May 16–31, 2026"
        $p2Start   = sprintf('%04d-%02d-%02d', $year, $m, $c2Start);
        $p2End     = sprintf('%04d-%02d-%02d', $year, $m, $period2End);
        $p2Name    = "{$mName} {$c2Start}–{$period2End}, {$year}";
        $p2PayRaw  = sprintf('%04d-%02d-%02d', $year, $m, $period2End);
        $p2Pay     = $weekendRule === 'ADVANCE' ? advancePayDate($p2PayRaw) : $p2PayRaw;

        // Create period 1
        $checkStmt->execute([$p1Start, $p1End]);
        if ($checkStmt->fetchColumn() > 0) {
            $skipped++;
        } else {
            $p1Number = $nextPayrollNumber($p1Start);
            $insertStmt->execute([$p1Number, $p1Name, $p1Start, $p1End, $p1Pay]);
            $created++;
        }

        // Create period 2
        $checkStmt->execute([$p2Start, $p2End]);
        if ($checkStmt->fetchColumn() > 0) {
            $skipped++;
        } else {
            $p2Number = $nextPayrollNumber($p2Start);
            $insertStmt->execute([$p2Number, $p2Name, $p2Start, $p2End, $p2Pay]);
            $created++;
        }
    }

    // Audit
    $uid = $_SESSION['user']['user_id'] ?? null;
    if ($uid) {
        $scope = $createFor === 'year' ? "all of {$year}" : "{$monthNames[$month-1]} {$year}";
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?,?,?,?,?)")
            ->execute([$uid, 'CREATE', 'payroll_periods', 0, "Created {$created} pay period(s) for {$scope}"]);
    }

    $parts = [];
    if ($created) $parts[] = "{$created} period(s) created";
    if ($skipped) $parts[] = "{$skipped} already existed (skipped)";

    echo json_encode([
        'success' => true,
        'message' => implode(', ', $parts) . '.',
        'created' => $created,
        'skipped' => $skipped,
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}