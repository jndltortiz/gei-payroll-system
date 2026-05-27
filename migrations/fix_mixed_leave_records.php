<?php
/**
 * migrations/fix_mixed_leave_records.php
 *
 * One-time retroactive fix for leave_requests rows that were approved while
 * containing a mix of APPROVED and REJECTED child dates in leave_request_dates.
 *
 * These records were created before recalcParentStatus() learned to split
 * mixed batches.  This script applies the same split logic retroactively:
 *   - Inserts a new REJECTED sibling leave_requests row
 *   - Moves the rejected leave_request_dates rows to that sibling
 *   - Updates the original to APPROVED with the correct total_days count
 *
 * Run once from the browser:  http://localhost/gei-payroll-system/migrations/fix_mixed_leave_records.php
 * or from CLI:                php migrations/fix_mixed_leave_records.php
 *
 * Safe to re-run — records already correctly split will have 0 rejected dates
 * under their APPROVED parent and will be skipped.
 */

require_once __DIR__ . '/../config/database.php';

// ── Detect optional migration columns ────────────────────────────────────────
function columnExists(PDO $pdo, string $table, string $col): bool {
    $r = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")->fetch();
    return (bool)$r;
}

$hasWorkflowStatus    = columnExists($pdo, 'leave_requests', 'workflow_status');
$hasSchoolYearId      = columnExists($pdo, 'leave_requests', 'school_year_id');
$hasIsBackdated       = columnExists($pdo, 'leave_requests', 'is_backdated');
$hasIsAttRecorded     = columnExists($pdo, 'leave_requests', 'is_attendance_recorded');

// ── Resolve an admin user_id for audit log rows ──────────────────────────────
// audit_logs.user_id is NOT NULL with a FK to users; use the first admin user.
$auditUserId = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();

// ── Find all APPROVED leave_requests that still carry REJECTED child dates ───
$affected = $pdo->query("
    SELECT
        lr.leave_id,
        lr.employee_id,
        lr.leave_type_id,
        lr.start_date,
        lr.end_date,
        lr.total_days,
        lr.reason,
        lr.approved_by,
        lr.approved_at,
        lr.created_at,
        " . ($hasSchoolYearId   ? "lr.school_year_id,"   : "NULL AS school_year_id,") . "
        " . ($hasIsBackdated    ? "lr.is_backdated,"     : "0 AS is_backdated,") . "
        " . ($hasWorkflowStatus ? "lr.workflow_status,"  : "NULL AS workflow_status,") . "
        " . ($hasIsAttRecorded  ? "lr.is_attendance_recorded," : "0 AS is_attendance_recorded,") . "
        SUM(lrd.status = 'APPROVED') AS approved_cnt,
        SUM(lrd.status = 'REJECTED') AS rejected_cnt
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lrd.leave_id = lr.leave_id
    WHERE lr.status = 'APPROVED'
    GROUP BY lr.leave_id
    HAVING rejected_cnt > 0 AND approved_cnt > 0
")->fetchAll(PDO::FETCH_ASSOC);

$total   = count($affected);
$fixed   = 0;
$skipped = 0;
$errors  = [];

echo "<pre>\n";
echo "=== Leave Mixed-Batch Retroactive Fix ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Records to process: {$total}\n\n";

if ($total === 0) {
    echo "Nothing to fix — no APPROVED leave_requests with mixed child statuses found.\n";
    echo "</pre>";
    exit;
}

foreach ($affected as $lr) {
    $leaveId = (int)$lr['leave_id'];

    try {
        $pdo->beginTransaction();

        // ── Gather rejected-date range ────────────────────────────────────────
        $ri = $pdo->prepare("
            SELECT MIN(leave_date) AS start_d,
                   MAX(leave_date) AS end_d,
                   COUNT(*)        AS cnt
            FROM   leave_request_dates
            WHERE  leave_id = ? AND status = 'REJECTED'
        ");
        $ri->execute([$leaveId]);
        $rejected = $ri->fetch(PDO::FETCH_ASSOC);

        if (!$rejected || (int)$rejected['cnt'] === 0) {
            // Already clean — no rejected child rows (shouldn't reach here, but guard it)
            $pdo->rollBack();
            $skipped++;
            echo "[SKIP] leave_id={$leaveId} — no rejected dates found under APPROVED parent\n";
            continue;
        }

        $approvedCnt = (int)$lr['approved_cnt'];
        $approvedBy  = $lr['approved_by'];

        // ── Insert REJECTED sibling ───────────────────────────────────────────
        $pdo->prepare("
            INSERT INTO leave_requests
                (employee_id, leave_type_id, start_date, end_date, total_days,
                 reason, status, approved_by, approved_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'REJECTED', ?, ?, ?, NOW())
        ")->execute([
            $lr['employee_id'],
            $lr['leave_type_id'],
            $rejected['start_d'],
            $rejected['end_d'],
            (int)$rejected['cnt'],
            $lr['reason'],
            $approvedBy,
            $lr['approved_at'],
            $lr['created_at'],
        ]);
        $newLeaveId = (int)$pdo->lastInsertId();

        // ── Copy optional migration columns to sibling ────────────────────────
        $optCols   = [];
        $optVals   = [];

        if ($hasSchoolYearId) {
            $optCols[] = 'school_year_id = ?';
            $optVals[] = $lr['school_year_id'];
        }
        if ($hasWorkflowStatus) {
            $optCols[] = 'workflow_status = ?';
            $optVals[] = 'FORWARDED';
        }
        if ($hasIsBackdated) {
            $optCols[] = 'is_backdated = ?';
            $optVals[] = (int)$lr['is_backdated'];
        }
        if ($hasIsAttRecorded) {
            $optCols[] = 'is_attendance_recorded = ?';
            $optVals[] = 0; // rejected → never recorded
        }

        if ($optCols) {
            $optVals[] = $newLeaveId;
            $pdo->prepare("
                UPDATE leave_requests SET " . implode(', ', $optCols) . " WHERE leave_id = ?
            ")->execute($optVals);
        }

        // ── Move rejected dates to sibling ────────────────────────────────────
        $pdo->prepare("
            UPDATE leave_request_dates
            SET    leave_id = ?
            WHERE  leave_id = ? AND status = 'REJECTED'
        ")->execute([$newLeaveId, $leaveId]);

        // ── Update original to APPROVED with correct total_days ───────────────
        $pdo->prepare("
            UPDATE leave_requests
            SET    total_days  = ?,
                   updated_at  = NOW()
            WHERE  leave_id    = ?
        ")->execute([$approvedCnt, $leaveId]);

        // ── Audit both records ────────────────────────────────────────────────
        $auditRows = [
            [$auditUserId, 'RETRO_SPLIT_SOURCE',  'leave_requests', $leaveId,
             "Retroactive split: leave #{$leaveId} corrected to APPROVED ({$approvedCnt} day(s)); sibling #{$newLeaveId} created as REJECTED"],
            [$auditUserId, 'RETRO_SPLIT_SIBLING', 'leave_requests', $newLeaveId,
             "Retroactive split: new REJECTED sibling #{$newLeaveId} created from leave #{$leaveId} ({$rejected['cnt']} day(s))"],
        ];
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        foreach ($auditRows as $row) {
            $auditStmt->execute($row);
        }

        $pdo->commit();

        $fixed++;
        echo "[FIXED] leave_id={$leaveId} → approved {$approvedCnt} day(s), rejected sibling={$newLeaveId} ({$rejected['cnt']} day(s))\n";

    } catch (PDOException $e) {
        $pdo->rollBack();
        $errors[] = "leave_id={$leaveId}: " . $e->getMessage();
        echo "[ERROR] leave_id={$leaveId}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Summary ===\n";
echo "Fixed:   {$fixed}\n";
echo "Skipped: {$skipped}\n";
echo "Errors:  " . count($errors) . "\n";
if ($errors) {
    echo "\nError details:\n";
    foreach ($errors as $err) echo "  - {$err}\n";
}
echo "\nDone.\n</pre>";
