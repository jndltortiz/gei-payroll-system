<?php
/**
 * actions/timeout-attendance.php
 * Records time_out for one or many attendance records using current server time.
 * Also computes overtime_minutes vs. the employee's shift end_time.
 * Writes an entry to attendance_audit_log for every processed record.
 *
 * POST params:
 *   attendance_id          — single record (int)
 *   attendance_ids[]       — multiple records (array of int)
 *   (both may be combined; one is required)
 *
 * Returns JSON:
 *   { success, processed, skipped, errors[], message }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Collect IDs from single or bulk param
$ids = [];
if (!empty($_POST['attendance_id'])) {
    $ids[] = (int)$_POST['attendance_id'];
}
if (!empty($_POST['attendance_ids']) && is_array($_POST['attendance_ids'])) {
    foreach ($_POST['attendance_ids'] as $v) {
        $v = (int)$v;
        if ($v > 0 && !in_array($v, $ids)) $ids[] = $v;
    }
}

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No attendance records specified.']);
    exit;
}

$userId  = $_SESSION['user']['user_id'] ?? null;
$timeNow = date('H:i:s');        // Current time for time_out
$toMins  = fn($t) => $t ? (int)explode(':', $t)[0] * 60 + (int)explode(':', $t)[1] : 0;

$processed = 0;
$skipped   = 0;
$errors    = [];

foreach ($ids as $attId) {
    // Fetch attendance row + shift info
    $rec = $pdo->prepare("
        SELECT ar.attendance_id, ar.time_in, ar.time_out,
               ar.attendance_status, ar.attendance_source, ar.remarks,
               s.end_time AS shift_end
        FROM attendance_records ar
        JOIN employees e ON ar.employee_id = e.employee_id
        LEFT JOIN shifts s ON e.shift_id = s.shift_id
        WHERE ar.attendance_id = ?
    ");
    $rec->execute([$attId]);
    $row = $rec->fetch(PDO::FETCH_ASSOC);

    if (!$row)             { $skipped++; continue; } // Record not found
    if (!$row['time_in'])  { $skipped++; continue; } // No time_in → nothing to close
    if ($row['time_out'])  { $skipped++; continue; } // Already timed out

    // Compute overtime: minutes beyond shift end (0 if shift not set or not past end)
    $overtimeMins = 0;
    if ($row['shift_end']) {
        $diff = $toMins($timeNow) - $toMins($row['shift_end']);
        $overtimeMins = max(0, $diff);
    }

    try {
        // Build UPDATE dynamically: include overtime_minutes only if column exists (migration 007)
        static $hasOtCol = null;
        if ($hasOtCol === null) {
            $hasOtCol = (bool)$pdo->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance_records' AND COLUMN_NAME='overtime_minutes'
            ")->fetchColumn();
        }

        if ($hasOtCol) {
            $pdo->prepare("
                UPDATE attendance_records
                SET time_out         = :tout,
                    overtime_minutes = :ot,
                    updated_at       = NOW()
                WHERE attendance_id  = :id
            ")->execute([':tout' => $timeNow, ':ot' => $overtimeMins, ':id' => $attId]);
        } else {
            $pdo->prepare("
                UPDATE attendance_records
                SET time_out   = :tout,
                    updated_at = NOW()
                WHERE attendance_id = :id
            ")->execute([':tout' => $timeNow, ':id' => $attId]);
        }

        // Audit log (requires migration 007; silently skipped if absent)
        try {
            $pdo->prepare("
                INSERT INTO attendance_audit_log
                    (attendance_id, changed_by, action_type,
                     old_time_out, new_time_out,
                     old_status,   new_status,
                     old_method,   new_method,
                     reason)
                VALUES (?, ?, 'TIMEOUT', NULL, ?, ?, ?, ?, ?, 'Admin Time Out')
            ")->execute([
                $attId, $userId,
                $timeNow,
                $row['attendance_status'], $row['attendance_status'],
                $row['attendance_source'], $row['attendance_source'],
            ]);
        } catch (PDOException $auditEx) {
            // Audit table absent — non-fatal
        }

        $processed++;
    } catch (PDOException $e) {
        $errors[] = "Record #{$attId}: " . $e->getMessage();
    }
}

echo json_encode([
    'success'   => $processed > 0,
    'processed' => $processed,
    'skipped'   => $skipped,
    'errors'    => $errors,
    'message'   => $processed > 0
        ? "Logged out {$processed} employee(s) at " . date('h:i A') . ($skipped > 0 ? " ({$skipped} skipped)" : '')
        : 'No records were updated. Records may already be timed out or have no time-in.',
]);
