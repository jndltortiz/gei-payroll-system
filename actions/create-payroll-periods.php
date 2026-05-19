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

// Load cutoff settings
$settings = $pdo->query("SELECT * FROM payroll_settings LIMIT 1")->fetch();
$c1Start  = (int)($settings['cutoff1_start_day'] ?? 1);
$c1End    = (int)($settings['cutoff1_end_day']   ?? 15);
$c2Start  = (int)($settings['cutoff2_start_day'] ?? 16);
$c2End    = (int)($settings['cutoff2_end_day']   ?? 31);

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
            (period_name, pay_period_start, pay_period_end, pay_date, status)
        VALUES (?, ?, ?, ?, 'OPEN')
    ");

    foreach ($months as $m) {
        $mName    = $monthNames[$m - 1];
        $lastDay  = (int)date('t', mktime(0, 0, 0, $m, 1, $year));

        // Clamp cutoff end days to the actual last day of the month
        $period1End = min($c1End, $lastDay);
        $period2End = min($c2End, $lastDay);

        // Period 1: e.g. "May 1–15, 2026"
        $p1Start = sprintf('%04d-%02d-%02d', $year, $m, $c1Start);
        $p1End   = sprintf('%04d-%02d-%02d', $year, $m, $period1End);
        $p1Name  = "{$mName} {$c1Start}–{$period1End}, {$year}";
        $p1Pay   = sprintf('%04d-%02d-%02d', $year, $m, $period1End); // pay on last day of period

        // Period 2: e.g. "May 16–31, 2026"
        $p2Start = sprintf('%04d-%02d-%02d', $year, $m, $c2Start);
        $p2End   = sprintf('%04d-%02d-%02d', $year, $m, $period2End);
        $p2Name  = "{$mName} {$c2Start}–{$period2End}, {$year}";
        $p2Pay   = sprintf('%04d-%02d-%02d', $year, $m, $period2End);

        // Create period 1
        $checkStmt->execute([$p1Start, $p1End]);
        if ($checkStmt->fetchColumn() > 0) {
            $skipped++;
        } else {
            $insertStmt->execute([$p1Name, $p1Start, $p1End, $p1Pay]);
            $created++;
        }

        // Create period 2
        $checkStmt->execute([$p2Start, $p2End]);
        if ($checkStmt->fetchColumn() > 0) {
            $skipped++;
        } else {
            $insertStmt->execute([$p2Name, $p2Start, $p2End, $p2Pay]);
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