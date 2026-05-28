<?php
/**
 * actions/generate-payroll.php
 * Generates payroll records for a pay period.
 *
 * Period types (migration 020):
 *   REGULAR     — standard semi-monthly payroll (basic pay + allowances + gov + loans).
 *                 Attendance-based deductions (absence, half-day, late) are NOT applied
 *                 here per GEI policy (TOR Q14, Q68). Attendance is monitoring-only during
 *                 regular payroll; settlement happens at EOSY.
 *   ACCRUED_PAY — end-of-school-year settlement run only. Contains:
 *                 Allowances : approved service credits (all unpaid for school year)
 *                 Deductions : half-day settlement + excess leave settlement
 *                 No basic pay, no regular allowances, no gov premiums, no loan deductions.
 *
 * Scope options (POST: scope):
 *   all        → all active employees
 *   department → employees in department_id
 *   position   → employees with position_id
 *   specific   → employees in employee_ids[] (one or many)
 *
 * Optional: force_regenerate=1 → delete & rebuild existing records
 * Returns JSON.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payroll-utils.php';
header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$periodId        = (int)($_POST['period_id'] ?? 0);
$scope           = in_array($_POST['scope'] ?? '', ['all','department','position','specific'])
                   ? $_POST['scope'] : 'all';
$deptId          = (int)($_POST['department_id'] ?? 0);
$posId           = (int)($_POST['position_id']   ?? 0);
$empIds          = array_values(array_filter(array_map('intval', $_POST['employee_ids'] ?? [])));
$forceRegenerate = !empty($_POST['force_regenerate']);

// ── Validate period ───────────────────────────────────────────────────────────
if (!$periodId) {
    echo json_encode(['success' => false, 'message' => 'No pay period selected.']);
    exit;
}

$periodStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_id = ? AND status = 'OPEN'");
$periodStmt->execute([$periodId]);
$period = $periodStmt->fetch();
if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Pay period not found or not open.']);
    exit;
}

// Detect period type — default REGULAR for rows created before migration 020
$periodType = $period['period_type'] ?? 'REGULAR';
if (!in_array($periodType, ['REGULAR', 'ACCRUED_PAY'], true)) {
    $periodType = 'REGULAR';
}
$isAccruedPay = $periodType === 'ACCRUED_PAY';

// ── Validate scope sub-selection ──────────────────────────────────────────────
if ($scope === 'department' && !$deptId) {
    echo json_encode(['success' => false, 'message' => 'Please select a department.']);
    exit;
}
if ($scope === 'position' && !$posId) {
    echo json_encode(['success' => false, 'message' => 'Please select a position.']);
    exit;
}
if ($scope === 'specific' && empty($empIds)) {
    echo json_encode(['success' => false, 'message' => 'Please select at least one employee.']);
    exit;
}

// ── Build employee list ───────────────────────────────────────────────────────
$baseSQL = "
    SELECT e.employee_id, e.department_id, e.position_id, e.employment_type,
           ec.monthly_salary, ec.daily_rate
    FROM employees e
    JOIN employee_compensations ec ON e.employee_id = ec.employee_id AND ec.is_active = 1
    WHERE e.employee_status = 'ACTIVE'
";

switch ($scope) {
    case 'department':
        $empStmt = $pdo->prepare($baseSQL . " AND e.department_id = ?");
        $empStmt->execute([$deptId]);
        $scopeLabel = "department #{$deptId}";
        break;

    case 'position':
        $empStmt = $pdo->prepare($baseSQL . " AND e.position_id = ?");
        $empStmt->execute([$posId]);
        $scopeLabel = "position #{$posId}";
        break;

    case 'specific':
        $ph = implode(',', array_fill(0, count($empIds), '?'));
        $empStmt = $pdo->prepare($baseSQL . " AND e.employee_id IN ($ph)");
        $empStmt->execute($empIds);
        $scopeLabel = count($empIds) . ' selected employee(s)';
        break;

    default: // all
        $empStmt = $pdo->query($baseSQL);
        $scopeLabel = 'all employees';
}

$employees = $empStmt->fetchAll();

if (empty($employees)) {
    echo json_encode(['success' => false,
        'message' => 'No active employees with compensation records found for the selected scope.']);
    exit;
}

// ── Payroll automation settings ───────────────────────────────────────────────
$settings = $pdo->query("SELECT * FROM payroll_settings LIMIT 1")->fetch() ?: [];
$autoApplyAllowances = (int)($settings['auto_apply_allowances'] ?? 1) === 1;
$autoApplyDeductions = (int)($settings['auto_apply_deductions'] ?? 1) === 1;
$governmentCalcMode  = $settings['government_calc_mode'] ?? 'STANDARD';
$isSemiMonthly       = ($settings['payroll_frequency'] ?? 'SEMI_MONTHLY') === 'SEMI_MONTHLY';
$periodDivisor       = $isSemiMonthly ? 2 : 1;
// Annual leave allocation for ACCRUED_PAY excess leave computation (mig 020, default 30 days)
$leaveAllocationDays = (float)($settings['leave_allocation_days'] ?? 30.00);
// ISO weekday numbers (1=Mon … 6=Sat … 7=Sun) counted as workdays for full-time calendar math
$workingDaysPerWeek  = (int)($settings['working_days_per_week'] ?? 5);
$workWeekdays        = ($workingDaysPerWeek >= 7) ? [1,2,3,4,5,6,7]
                     : ($workingDaysPerWeek >= 6  ? [1,2,3,4,5,6] : [1,2,3,4,5]);

// ── Pre-fetch non-absence, non-accrued deduction types (REGULAR use) ──────────
// Excludes: is_absence_deduction=1 (never in any payroll — settled at EOSY)
//           is_accrued_pay_deduction=1 (only in ACCRUED_PAY periods)
// Backward-compatible: is_accrued_pay_deduction column added in mig 020; defaults to 0 if absent.
$dtypes = [];
if ($autoApplyDeductions && !$isAccruedPay) {
    try {
        $dtypes = $pdo->query("
            SELECT * FROM deduction_types
            WHERE is_active = 1
              AND COALESCE(is_absence_deduction,  0) = 0
              AND COALESCE(is_accrued_pay_deduction, 0) = 0
        ")->fetchAll();
    } catch (PDOException $e) {
        // is_accrued_pay_deduction column not yet present (mig 020 not run)
        $dtypes = $pdo->query("
            SELECT * FROM deduction_types
            WHERE is_active = 1
              AND COALESCE(is_absence_deduction, 0) = 0
        ")->fetchAll();
    }
}

// ── Pre-fetch ACCRUED_PAY settlement deduction type IDs (mig 020) ─────────────
$halfDayDedTypeId    = null;
$excessLeaveDedTypeId = null;
if ($isAccruedPay) {
    try {
        $hdRow = $pdo->query("
            SELECT deduction_type_id FROM deduction_types
            WHERE is_accrued_pay_deduction = 1
              AND deduction_name = 'Half-Day Settlement'
              AND is_active = 1
            LIMIT 1
        ")->fetch();
        if ($hdRow) $halfDayDedTypeId = (int)$hdRow['deduction_type_id'];

        $elRow = $pdo->query("
            SELECT deduction_type_id FROM deduction_types
            WHERE is_accrued_pay_deduction = 1
              AND deduction_name = 'Excess Leave Settlement'
              AND is_active = 1
            LIMIT 1
        ")->fetch();
        if ($elRow) $excessLeaveDedTypeId = (int)$elRow['deduction_type_id'];
    } catch (PDOException $e) {
        // Migration 020 not yet run — settlement deduction types unavailable
    }
}

// ── Pre-fetch EOSY Balance Recovery deduction type (REGULAR periods only) ────
// If a prior ACCRUED_PAY period resulted in negative net_pay, the shortfall is
// automatically deducted in the same-month regular payroll period.
$eosyRecoveryDtId = null;
if (!$isAccruedPay) {
    try {
        $recovRow = $pdo->query("
            SELECT deduction_type_id FROM deduction_types
            WHERE deduction_name = 'EOSY Balance Recovery' AND is_active = 1
            LIMIT 1
        ")->fetch();
        if ($recovRow) {
            $eosyRecoveryDtId = (int)$recovRow['deduction_type_id'];
        } else {
            $pdo->exec("INSERT INTO deduction_types
                (deduction_name, deduction_value_type, deduction_amount, is_government, is_loan, is_active)
                VALUES ('EOSY Balance Recovery', 'FIXED', 0.00, 0, 0, 1)");
            $eosyRecoveryDtId = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        // Silently skip — not critical
    }
}

// ── Generate records ──────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();
    $generated = 0;
    $skipped   = 0;
    $replaced  = 0;

    foreach ($employees as $emp) {
        $employeeId     = (int)$emp['employee_id'];
        $dailyRate      = (float)($emp['daily_rate']     ?? 0);
        $monthlySalary  = (float)($emp['monthly_salary'] ?? 0); // used for gov bracket lookups only
        $employmentType = $emp['employment_type'] ?? 'FULL_TIME';
        $periodStart    = $period['pay_period_start'] ?? '';
        $periodEnd      = $period['pay_period_end']   ?? '';

        // ── Compute payable days and basic pay ───────────────────────────────────
        if ($isAccruedPay) {
            // Settlement period: no salary component
            $basicPay    = 0.0;
            $payableDays = 0;
        } elseif ($employmentType === 'PART_TIME') {
            // Part-time: count actual attendance records within the cutoff.
            // GEI part-time employees (physician/dentist) may work irregular days so
            // a fixed weekly schedule is NOT required — only confirmed attendance counts.
            $attStmt = $pdo->prepare("
                SELECT attendance_status FROM attendance_records
                WHERE employee_id = ?
                  AND attendance_date BETWEEN ? AND ?
                  AND attendance_status IN ('PRESENT', 'LATE', 'HALF_DAY')
            ");
            $attStmt->execute([$employeeId, $periodStart, $periodEnd]);
            $payableDays = 0.0;
            foreach ($attStmt->fetchAll(PDO::FETCH_COLUMN) as $status) {
                $payableDays += ($status === 'HALF_DAY') ? 0.5 : 1.0;
            }
            $basicPay = round($dailyRate * $payableDays, 2);
        } else {
            // Full-time: count calendar workdays (Mon-Fri or Mon-Sat per setting)
            $payableDays = 0;
            if ($periodStart && $periodEnd) {
                $d    = new DateTime($periodStart);
                $last = new DateTime($periodEnd);
                while ($d <= $last) {
                    if (in_array((int)$d->format('N'), $workWeekdays)) {
                        $payableDays++;
                    }
                    $d->modify('+1 day');
                }
            }
            $basicPay = round($dailyRate * $payableDays, 2);
        }

        // ── Check for existing record ─────────────────────────────────────────
        $dup = $pdo->prepare("
            SELECT payroll_id FROM payroll_records
            WHERE employee_id = ? AND period_id = ?
        ");
        $dup->execute([$employeeId, $periodId]);
        $existingId = $dup->fetchColumn();

        if ($existingId) {
            if ($forceRegenerate) {
                $pdo->prepare("DELETE FROM payroll_allowances WHERE payroll_id=?")->execute([$existingId]);

                // Reverse loan payment log entries before deleting deduction rows
                try {
                    $revStmt = $pdo->prepare("
                        SELECT pd.loan_id, pd.amount
                        FROM payroll_deductions pd
                        WHERE pd.payroll_id = ? AND pd.loan_id IS NOT NULL
                    ");
                    $revStmt->execute([$existingId]);
                    $toReverse = $revStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($toReverse as $rev) {
                        $hasLog = $pdo->prepare("
                            SELECT COUNT(*) FROM loan_payment_log
                            WHERE loan_id=? AND payroll_id=? AND payment_channel='PAYROLL'
                        ");
                        $hasLog->execute([$rev['loan_id'], $existingId]);
                        if ((int)$hasLog->fetchColumn() > 0) {
                            $pdo->prepare("
                                UPDATE employee_loans
                                SET balance_amount = balance_amount + ?,
                                    status = CASE WHEN status='COMPLETED' THEN 'ACTIVE' ELSE status END
                                WHERE loan_id = ?
                            ")->execute([$rev['amount'], $rev['loan_id']]);
                            $pdo->prepare("
                                DELETE FROM loan_payment_log
                                WHERE loan_id=? AND payroll_id=? AND payment_channel='PAYROLL'
                            ")->execute([$rev['loan_id'], $existingId]);
                        }
                    }
                } catch (PDOException $e) {
                    // payroll_id column not yet in loan_payment_log — skip reversal
                }

                $pdo->prepare("DELETE FROM payroll_deductions WHERE payroll_id=?")->execute([$existingId]);

                // Restore service credits that were applied through this payroll record
                $pdo->prepare("
                    UPDATE service_credits sc
                    SET sc.payroll_id = NULL, sc.applied_to_payroll_at = NULL,
                        sc.status = CASE
                            WHEN (SELECT SUM(scd.status='REJECTED') FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) > 0
                             AND (SELECT SUM(scd.status='APPROVED') FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) > 0
                            THEN 'PARTIALLY_APPROVED'
                            ELSE 'APPROVED'
                        END
                    WHERE sc.payroll_id = ?
                ")->execute([$existingId]);

                $pdo->prepare("DELETE FROM payroll_records WHERE payroll_id=?")->execute([$existingId]);
                $replaced++;
            } else {
                $skipped++;
                continue;
            }
        }

        // ── Insert base payroll record ────────────────────────────────────────
        $pdo->prepare("
            INSERT INTO payroll_records
                (period_id, employee_id, basic_pay, gross_pay,
                 total_allowances, total_deductions, net_pay, payroll_status)
            VALUES (?, ?, ?, 0, 0, 0, 0, 'DRAFT')
        ")->execute([$periodId, $employeeId, $basicPay]);
        $payrollId = (int)$pdo->lastInsertId();

        $insA = $pdo->prepare("
            INSERT INTO payroll_allowances (payroll_id, allowance_type_id, amount)
            VALUES (?,?,?)
        ");
        $insD = $pdo->prepare("
            INSERT INTO payroll_deductions (payroll_id, deduction_type_id, amount, loan_id)
            VALUES (?,?,?,?)
        ");
        $totalAllowances = 0.0;
        $totalDeductions = 0.0;

        // ══════════════════════════════════════════════════════════════════════
        // ACCRUED_PAY path — service credits + half-day + excess leave only
        // ══════════════════════════════════════════════════════════════════════
        if ($isAccruedPay) {

            // ── Service credits ───────────────────────────────────────────────
            // Pick up ALL approved, unpaid credits for this employee.
            // Per GEI policy (TOR Q60), service credits are released only at EOSY.
            $scStmt = $pdo->prepare("
                SELECT sc.service_credit_id, sc.equivalent_pay
                FROM service_credits sc
                WHERE sc.employee_id = :eid
                  AND sc.status IN ('APPROVED','PARTIALLY_APPROVED')
                  AND sc.payroll_id IS NULL
            ");
            $scStmt->execute([':eid' => $employeeId]);
            $approvedCredits  = $scStmt->fetchAll();
            $serviceCreditPay = array_sum(array_column($approvedCredits, 'equivalent_pay'));
            $scIds            = array_column($approvedCredits, 'service_credit_id');

            if ($serviceCreditPay > 0) {
                // Find the designated service-credit allowance type
                $addlType = $pdo->query("
                    SELECT allowance_type_id FROM allowance_types
                    WHERE is_active = 1 AND is_service_credit_target = 1
                    LIMIT 1
                ")->fetch();

                if (!$addlType) {
                    throw new RuntimeException(
                        'Approved service credits exist but no allowance type has is_service_credit_target = 1. '
                        . 'Run migration 001 or mark the target allowance type in Payroll Settings → Allowances.'
                    );
                }
                $insA->execute([$payrollId, $addlType['allowance_type_id'], $serviceCreditPay]);
                $totalAllowances += $serviceCreditPay;
            }

            // Mark service credits as APPLIED
            if (!empty($scIds)) {
                $ph = implode(',', array_fill(0, count($scIds), '?'));
                $pdo->prepare("
                    UPDATE service_credits
                    SET status='APPLIED', payroll_id=?, applied_to_payroll_at=NOW()
                    WHERE service_credit_id IN ($ph)
                ")->execute(array_merge([$payrollId], $scIds));
            }

            // ── Half-day settlement ───────────────────────────────────────────
            // Count accumulated HALF_DAY records for the school year and deduct
            // (half_day_count × daily_rate ÷ 2) per GEI policy (TOR Q19–Q22).
            if ($halfDayDedTypeId && $dailyRate > 0) {
                $hdResult = computeHalfDaySettlement($pdo, $employeeId, $periodId, $dailyRate);
                if ($hdResult !== null && $hdResult['amount'] > 0) {
                    $pdo->prepare("
                        INSERT INTO payroll_deductions
                            (payroll_id, deduction_type_id, amount, absence_days, notes)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([
                        $payrollId,
                        $halfDayDedTypeId,
                        $hdResult['amount'],
                        $hdResult['days'],
                        $hdResult['notes'],
                    ]);
                    $totalDeductions += $hdResult['amount'];
                }
            }

            // ── Excess leave settlement ───────────────────────────────────────
            // Total leave used across all types vs. the configured annual allocation.
            // Excess days × daily_rate = deduction per GEI policy (TOR Q13, Q42).
            if ($excessLeaveDedTypeId && $dailyRate > 0) {
                $elResult = computeExcessLeaveSettlement(
                    $pdo, $employeeId, $periodId, $dailyRate, $leaveAllocationDays
                );
                if ($elResult !== null && $elResult['amount'] > 0) {
                    $pdo->prepare("
                        INSERT INTO payroll_deductions
                            (payroll_id, deduction_type_id, amount, absence_days, notes)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([
                        $payrollId,
                        $excessLeaveDedTypeId,
                        $elResult['amount'],
                        $elResult['days'],
                        $elResult['notes'],
                    ]);
                    $totalDeductions += $elResult['amount'];
                }
            }

        // ══════════════════════════════════════════════════════════════════════
        // REGULAR path — basic pay + allowances + gov contributions + loans
        // Attendance-based deductions intentionally excluded per GEI policy.
        // ══════════════════════════════════════════════════════════════════════
        } else {

            // ── Regular allowances ────────────────────────────────────────────
            // Service credits are excluded from REGULAR payroll (ACCRUED_PAY only).
            if ($autoApplyAllowances) {
                $atypes = $pdo->prepare("
                    SELECT DISTINCT at2.allowance_type_id, at2.allowance_name,
                                    at2.default_amount, at2.is_service_credit_target
                    FROM allowance_types at2
                    JOIN allowance_assignments aa ON at2.allowance_type_id = aa.allowance_type_id
                    WHERE at2.is_active = 1 AND aa.is_active = 1
                      AND (
                            aa.applies_to = 'ALL'
                         OR (aa.applies_to = 'EMPLOYEE'   AND aa.target_id = :eid)
                         OR (aa.applies_to = 'DEPARTMENT' AND aa.target_id = :dept)
                         OR (aa.applies_to = 'POSITION'   AND aa.target_id = :pos)
                          )
                ");
                $atypes->execute([
                    ':eid'  => $employeeId,
                    ':dept' => $emp['department_id'],
                    ':pos'  => $emp['position_id'],
                ]);

                foreach ($atypes->fetchAll() as $type) {
                    // Skip service-credit allowance type in REGULAR payroll —
                    // service credits are settled exclusively in ACCRUED_PAY periods.
                    if (!empty($type['is_service_credit_target'])) continue;

                    $amt = (float)$type['default_amount'];
                    if ($amt <= 0) continue;
                    $insA->execute([$payrollId, $type['allowance_type_id'], $amt]);
                    $totalAllowances += $amt;
                }
            }

            // ── Government contributions + loan deductions ────────────────────
            $govContribMonthly  = 0.0;
            $wtaxDtypeRow       = null;
            $employerSss        = 0.0;
            $employerPhilHealth = 0.0;
            $employerPagibig    = 0.0;

            // Fetch this employee's ACTIVE loans
            $loanBaseSQL = "
                FROM employee_loans el
                JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
                WHERE el.employee_id = :eid
                  AND lt.is_active = 1
                  AND el.status = 'ACTIVE'
                  AND el.start_date <= CURDATE()
                  AND (el.end_date IS NULL OR el.end_date >= CURDATE())
                  AND el.balance_amount > 0
                  AND (
                        NOT EXISTS (
                            SELECT 1 FROM loan_type_assignments lta0
                            WHERE lta0.loan_type_id = lt.loan_type_id AND lta0.is_active = 1
                        )
                     OR EXISTS (
                            SELECT 1 FROM loan_type_assignments lta
                            WHERE lta.loan_type_id = lt.loan_type_id
                              AND lta.is_active = 1
                              AND (
                                    lta.applies_to = 'ALL'
                                 OR (lta.applies_to = 'EMPLOYEE'   AND lta.target_id = :eid_assign)
                                 OR (lta.applies_to = 'DEPARTMENT' AND lta.target_id = :dept)
                                 OR (lta.applies_to = 'POSITION'   AND lta.target_id = :pos)
                                  )
                        )
                  )
            ";
            $loanBinds = [
                ':eid'        => $employeeId,
                ':eid_assign' => $employeeId,
                ':dept'       => $emp['department_id'],
                ':pos'        => $emp['position_id'],
            ];
            try {
                $empLoanStmt = $pdo->prepare("
                    SELECT el.loan_id, el.monthly_deduction, el.balance_amount, lt.loan_name,
                           lt.deduction_type_id,
                           (lt.loan_name LIKE '%PERAA%') AS is_peraa,
                           el.skip_next_deduction, el.next_deduction_override
                    $loanBaseSQL
                ");
                $empLoanStmt->execute($loanBinds);
                $empActiveLoansList = $empLoanStmt->fetchAll();
            } catch (PDOException $e) {
                $empLoanStmt = $pdo->prepare("
                    SELECT el.loan_id, el.monthly_deduction, el.balance_amount, lt.loan_name,
                           lt.deduction_type_id,
                           (lt.loan_name LIKE '%PERAA%') AS is_peraa,
                           0 AS skip_next_deduction, NULL AS next_deduction_override
                    $loanBaseSQL
                ");
                $empLoanStmt->execute($loanBinds);
                $empActiveLoansList = $empLoanStmt->fetchAll();
            }

            $loanByDedType   = [];
            $skipFlagResets  = [];
            $overrideFlagResets = [];

            foreach ($empActiveLoansList as $el) {
                if ($el['is_peraa'] && ($emp['employment_type'] ?? 'FULL_TIME') !== 'FULL_TIME') continue;
                $dtId = (int)($el['deduction_type_id'] ?? 0);
                if (!$dtId) continue;

                if ((int)($el['skip_next_deduction'] ?? 0) === 1) {
                    $skipFlagResets[] = (int)$el['loan_id'];
                    continue;
                }

                $override = $el['next_deduction_override'] ?? null;
                if ($override !== null && (float)$override > 0) {
                    $perPayroll = (float)$override;
                    $overrideFlagResets[] = (int)$el['loan_id'];
                } else {
                    $perPayroll = round((float)$el['monthly_deduction'] / $periodDivisor, 2);
                }
                $perPayroll = min($perPayroll, (float)$el['balance_amount']);

                if (!isset($loanByDedType[$dtId])) {
                    $loanByDedType[$dtId] = ['amount' => 0.0, 'loan_id' => (int)$el['loan_id']];
                }
                $loanByDedType[$dtId]['amount'] += $perPayroll;
            }

            foreach ($dtypes as $type) {
                $assignCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM deduction_assignments da
                    WHERE da.deduction_type_id = ? AND da.is_active = 1
                ");
                $assignCheck->execute([$type['deduction_type_id']]);
                if ((int)$assignCheck->fetchColumn() > 0) {
                    $appliesStmt = $pdo->prepare("
                        SELECT COUNT(*) FROM deduction_assignments da
                        WHERE da.deduction_type_id = :did AND da.is_active = 1
                          AND (
                                da.applies_to = 'ALL'
                             OR (da.applies_to = 'EMPLOYEE'   AND da.target_id = :eid)
                             OR (da.applies_to = 'DEPARTMENT' AND da.target_id = :dept)
                             OR (da.applies_to = 'POSITION'   AND da.target_id = :pos)
                              )
                    ");
                    $appliesStmt->execute([
                        ':did'  => $type['deduction_type_id'],
                        ':eid'  => $employeeId,
                        ':dept' => $emp['department_id'],
                        ':pos'  => $emp['position_id'],
                    ]);
                    if ((int)$appliesStmt->fetchColumn() === 0) continue;
                }

                $amt          = 0.0;
                $loanIdForRow = null;
                $name         = strtolower($type['deduction_name']);
                $isLoanDeduction = $type['is_loan'] || strpos($name, 'loan') !== false;

                if ($isLoanDeduction) {
                    $dtId = (int)$type['deduction_type_id'];
                    if (isset($loanByDedType[$dtId]) && $loanByDedType[$dtId]['amount'] > 0) {
                        $amt          = $loanByDedType[$dtId]['amount'];
                        $loanIdForRow = $loanByDedType[$dtId]['loan_id'];
                    }

                } elseif ($type['is_government']) {
                    if (strpos($name, 'peraa') !== false) {
                        if (($emp['employment_type'] ?? 'FULL_TIME') !== 'FULL_TIME') continue;
                    }

                    if ($governmentCalcMode === 'MANUAL') {
                        $amt = $type['deduction_value_type'] === 'PERCENTAGE'
                            ? round($basicPay * ($type['deduction_rate'] / 100), 2)
                            : (float)$type['deduction_amount'];

                    } elseif (strpos($name, 'withholding') !== false || strpos($name, 'w/tax') !== false) {
                        $wtaxDtypeRow = $type;
                        continue;

                    } elseif (strpos($name, 'sss') !== false) {
                        $row = $pdo->prepare("
                            SELECT employee_share, employer_share FROM sss_contribution_table
                            WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                            ORDER BY effective_date DESC LIMIT 1
                        ");
                        $row->execute([$monthlySalary, $monthlySalary]);
                        $r = $row->fetch();
                        $monthly     = $r ? (float)$r['employee_share'] : round($monthlySalary * 0.05, 2);
                        $employerSss = $r ? (float)$r['employer_share'] : round($monthlySalary * 0.10, 2);
                        $govContribMonthly += $monthly;
                        $amt = round($monthly / $periodDivisor, 2);

                    } elseif (strpos($name, 'philhealth') !== false || strpos($name, 'phil') !== false) {
                        $row = $pdo->prepare("
                            SELECT employee_share, employee_share_rate,
                                   employer_share, employer_share_rate
                            FROM philhealth_contribution_table
                            WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                            ORDER BY effective_date DESC LIMIT 1
                        ");
                        $row->execute([$monthlySalary, $monthlySalary]);
                        $r = $row->fetch();
                        if ($r) {
                            $monthly            = (float)$r['employee_share'] > 0
                                ? (float)$r['employee_share']
                                : round($monthlySalary * (float)$r['employee_share_rate'], 2);
                            $employerPhilHealth = (float)$r['employer_share'] > 0
                                ? (float)$r['employer_share']
                                : round($monthlySalary * (float)$r['employer_share_rate'], 2);
                        } else {
                            $monthly            = round($monthlySalary * 0.025, 2);
                            $employerPhilHealth = $monthly;
                        }
                        $govContribMonthly += $monthly;
                        $amt = round($monthly / $periodDivisor, 2);

                    } elseif (strpos($name, 'pag-ibig') !== false || strpos($name, 'hdmf') !== false
                              || strpos($name, 'pagibig') !== false) {
                        $row = $pdo->prepare("
                            SELECT employee_rate, employer_rate, employee_share, employer_share,
                                   max_employee_contribution
                            FROM pagibig_contribution_table
                            WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                            ORDER BY effective_date DESC LIMIT 1
                        ");
                        $row->execute([$monthlySalary, $monthlySalary]);
                        $r = $row->fetch();
                        if ($r) {
                            $monthly         = (float)$r['employee_share'] > 0
                                ? (float)$r['employee_share']
                                : round($monthlySalary * (float)$r['employee_rate'], 2);
                            $cap = ($r['max_employee_contribution'] !== null) ? (float)$r['max_employee_contribution'] : 0;
                            if ($cap > 0) $monthly = min($monthly, $cap);
                            $employerPagibig = (float)$r['employer_share'] > 0
                                ? (float)$r['employer_share']
                                : round($monthlySalary * (float)$r['employer_rate'], 2);
                        } else {
                            $monthly         = 200.00;
                            $employerPagibig = 200.00;
                        }
                        $govContribMonthly += $monthly;
                        $amt = round($monthly / $periodDivisor, 2);

                    } elseif (strpos($name, 'peraa') !== false) {
                        $amt = (float)$type['deduction_amount'];
                    }

                } elseif ($type['deduction_value_type'] === 'PERCENTAGE') {
                    if (strpos($name, 'peraa') !== false) {
                        if (($emp['employment_type'] ?? 'FULL_TIME') !== 'FULL_TIME') continue;
                        // PERAA: "percentage of basic monthly salary" — use monthly salary
                        // split evenly per period, not tied to period workdays
                        $amt = round($monthlySalary * ($type['deduction_rate'] / 100) / $periodDivisor, 2);
                    } else {
                        $amt = round($basicPay * ($type['deduction_rate'] / 100), 2);
                    }

                } elseif ($type['deduction_value_type'] === 'FIXED') {
                    if (strpos($name, 'peraa') !== false) {
                        if (($emp['employment_type'] ?? 'FULL_TIME') !== 'FULL_TIME') continue;
                    }
                    $amt = (float)$type['deduction_amount'];
                }

                if ($amt > 0) {
                    $insD->execute([$payrollId, $type['deduction_type_id'], $amt, $loanIdForRow]);
                    $totalDeductions += $amt;

                    // Auto-reconcile loan balance and log the deduction
                    if ($loanIdForRow) {
                        try {
                            $pdo->prepare("
                                UPDATE employee_loans
                                SET balance_amount = GREATEST(0, balance_amount - ?),
                                    status = CASE
                                        WHEN (balance_amount - ?) <= 0 THEN 'COMPLETED'
                                        ELSE status
                                    END
                                WHERE loan_id = ? AND status = 'ACTIVE'
                            ")->execute([$amt, $amt, $loanIdForRow]);

                            $encodedBy = $_SESSION['user']['user_id'] ?? null;
                            try {
                                $pdo->prepare("
                                    INSERT INTO loan_payment_log
                                        (loan_id, payroll_id, payment_date, amount, payment_channel, notes, encoded_by)
                                    VALUES (?, ?, ?, ?, 'PAYROLL', ?, ?)
                                ")->execute([
                                    $loanIdForRow, $payrollId,
                                    $period['pay_period_start'] ?? date('Y-m-d'),
                                    $amt,
                                    'Auto-deducted — Payroll Period #' . $periodId,
                                    $encodedBy,
                                ]);
                            } catch (PDOException $e2) {
                                // payroll_id column not in loan_payment_log (mig 019 not run)
                                $pdo->prepare("
                                    INSERT INTO loan_payment_log
                                        (loan_id, payment_date, amount, payment_channel, notes, encoded_by)
                                    VALUES (?, ?, ?, 'PAYROLL', ?, ?)
                                ")->execute([
                                    $loanIdForRow,
                                    $period['pay_period_start'] ?? date('Y-m-d'),
                                    $amt,
                                    'Auto-deducted — Payroll Period #' . $periodId,
                                    $encodedBy,
                                ]);
                            }
                        } catch (PDOException $e) {
                            // Skip silently — payroll record is still valid
                        }
                    }
                }
            }

            // ── Withholding tax (BIR TRAIN Law 2023+) ────────────────────────
            if ($wtaxDtypeRow !== null && $autoApplyDeductions) {
                if ($governmentCalcMode === 'MANUAL') {
                    $wtaxAmt = $wtaxDtypeRow['deduction_value_type'] === 'PERCENTAGE'
                        ? round($basicPay * ($wtaxDtypeRow['deduction_rate'] / 100), 2)
                        : (float)$wtaxDtypeRow['deduction_amount'];
                } else {
                    // Reconstruct monthly gross from period pay, then subtract monthly gov contribs
                    $annualTaxable = max(0.0, ($basicPay * $periodDivisor - $govContribMonthly)) * 12;
                    if ($annualTaxable <= 250000)      $annualTax = 0.0;
                    elseif ($annualTaxable <= 400000)  $annualTax = ($annualTaxable - 250000)  * 0.15;
                    elseif ($annualTaxable <= 800000)  $annualTax = 22500.0  + ($annualTaxable - 400000)  * 0.20;
                    elseif ($annualTaxable <= 2000000) $annualTax = 102500.0 + ($annualTaxable - 800000)  * 0.25;
                    elseif ($annualTaxable <= 8000000) $annualTax = 402500.0 + ($annualTaxable - 2000000) * 0.30;
                    else                               $annualTax = 2202500.0 + ($annualTaxable - 8000000) * 0.35;
                    $taxPeriods = $isSemiMonthly ? 24 : 12;
                    $wtaxAmt    = round($annualTax / $taxPeriods, 2);
                }
                if ($wtaxAmt > 0) {
                    $insD->execute([$payrollId, $wtaxDtypeRow['deduction_type_id'], $wtaxAmt, null]);
                    $totalDeductions += $wtaxAmt;
                }
            }

            // ── EOSY Balance Recovery ─────────────────────────────────────────
            // When a prior RELEASED ACCRUED_PAY period produced a negative net_pay
            // (deductions exceeded service credits), carry the shortfall into the
            // first subsequent regular payroll that is generated after the EOSY.
            // "Already recovered" is determined by the existence of a recovery
            // deduction row in any regular period after the EOSY — regardless of
            // that period's workflow status — so force-regeneration is safe.
            if ($eosyRecoveryDtId) {
                try {
                    $recovStmt = $pdo->prepare("
                        SELECT COALESCE(SUM(ABS(pr_e.net_pay)), 0) AS total_owed
                        FROM payroll_records pr_e
                        JOIN payroll_periods pp_e ON pp_e.period_id = pr_e.period_id
                        WHERE pr_e.employee_id  = :eid
                          AND pp_e.period_type  = 'ACCRUED_PAY'
                          AND pr_e.net_pay      < 0
                          AND pp_e.status       = 'RELEASED'
                          AND YEAR(pp_e.pay_period_start)  = YEAR(:pstart)
                          AND MONTH(pp_e.pay_period_start) = MONTH(:pstart2)
                          AND NOT EXISTS (
                              SELECT 1
                              FROM payroll_deductions pd_r
                              JOIN payroll_records   pr_r ON pr_r.payroll_id  = pd_r.payroll_id
                              JOIN payroll_periods   pp_r ON pp_r.period_id   = pr_r.period_id
                              WHERE pr_r.employee_id      = :eid2
                                AND pd_r.deduction_type_id = :dtid
                                AND YEAR(pp_r.pay_period_start)  = YEAR(pp_e.pay_period_start)
                                AND MONTH(pp_r.pay_period_start) = MONTH(pp_e.pay_period_start)
                          )
                    ");
                    $recovStmt->execute([
                        ':eid'    => $employeeId,
                        ':pstart' => $periodStart,
                        ':pstart2'=> $periodStart,
                        ':eid2'   => $employeeId,
                        ':dtid'   => $eosyRecoveryDtId,
                    ]);
                    $eosyOwed = round((float)$recovStmt->fetchColumn(), 2);
                    if ($eosyOwed > 0) {
                        $insD->execute([$payrollId, $eosyRecoveryDtId, $eosyOwed, null]);
                        $totalDeductions += $eosyOwed;
                    }
                } catch (PDOException $e) {
                    // Skip silently — non-critical; main payroll is unaffected
                }
            }

            // ── Reset one-time loan deduction control flags (mig 019) ─────────
            try {
                if (!empty($skipFlagResets)) {
                    $ph = implode(',', array_fill(0, count($skipFlagResets), '?'));
                    $pdo->prepare("UPDATE employee_loans SET skip_next_deduction=0 WHERE loan_id IN ($ph)")
                        ->execute($skipFlagResets);
                }
                if (!empty($overrideFlagResets)) {
                    $ph = implode(',', array_fill(0, count($overrideFlagResets), '?'));
                    $pdo->prepare("UPDATE employee_loans SET next_deduction_override=NULL WHERE loan_id IN ($ph)")
                        ->execute($overrideFlagResets);
                }
            } catch (PDOException $e) {
                // Migration 019 columns not present — skip
            }

            // ── Store employer-side contributions (informational; mig 005) ────
            try {
                $pdo->prepare("
                    UPDATE payroll_records
                    SET employer_sss_share        = ?,
                        employer_philhealth_share = ?,
                        employer_pagibig_share    = ?
                    WHERE payroll_id = ?
                ")->execute([$employerSss, $employerPhilHealth, $employerPagibig, $payrollId]);
            } catch (PDOException $e) {
                // Migration 005 not yet applied
            }

        } // end REGULAR path

        // ── Final totals — always recalculate from child rows ─────────────────
        recalculatePayrollTotals($pdo, $payrollId);
        $generated++;
    }

    $pdo->commit();

    // Audit
    $uid = $_SESSION['user']['user_id'] ?? null;
    if ($uid) {
        $typeLabel = $isAccruedPay ? 'ACCRUED_PAY' : 'REGULAR';
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (?,?,?,?,?)
        ")->execute([$uid, 'GENERATE', 'payroll_records', $periodId,
                     "Generated {$typeLabel} payroll for {$scopeLabel} — period #{$periodId}"]);
    }

    $parts = [];
    if ($generated) $parts[] = "{$generated} record(s) generated";
    if ($replaced)  $parts[] = "{$replaced} re-generated";
    if ($skipped)   $parts[] = "{$skipped} already existed (skipped)";

    echo json_encode([
        'success'     => true,
        'message'     => implode(', ', $parts) . ".",
        'period_type' => $periodType,
        'generated'   => $generated,
        'replaced'    => $replaced,
        'skipped'     => $skipped,
    ]);

} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Payroll generation error: ' . $e->getMessage()]);
}
