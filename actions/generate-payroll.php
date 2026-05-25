<?php
/**
 * actions/generate-payroll.php
 * Generates payroll records for a pay period.
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

$period = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_id = ? AND status = 'OPEN'");
$period->execute([$periodId]);
$period = $period->fetch();
if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Pay period not found or not open.']);
    exit;
}

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
$governmentCalcMode = $settings['government_calc_mode'] ?? 'STANDARD';
$isSemiMonthly      = ($settings['payroll_frequency'] ?? 'SEMI_MONTHLY') === 'SEMI_MONTHLY';
$periodDivisor      = $isSemiMonthly ? 2 : 1; // contributions stored as monthly; divide for semi-monthly

// ── Pre-fetch deduction types (assignment rules are checked per employee) ─────
// Absence deduction (is_absence_deduction=1) is computed separately; exclude here.
$dtypes = $autoApplyDeductions
    ? $pdo->query("SELECT * FROM deduction_types WHERE is_active = 1 AND COALESCE(is_absence_deduction,0) = 0")->fetchAll()
    : [];

// Pre-fetch absence deduction type once (requires migration 004).
// Wrapped in try-catch so payroll generation still works if migration not yet run.
$absDedTypeId = null;
try {
    $absDedRow = $pdo->query("
        SELECT deduction_type_id FROM deduction_types
        WHERE is_absence_deduction = 1 AND is_active = 1 LIMIT 1
    ")->fetch();
    if ($absDedRow) $absDedTypeId = (int)$absDedRow['deduction_type_id'];
} catch (PDOException $e) {
    // Migration 004 not yet run — absence deductions disabled for this run
}

// ── Generate records ──────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();
    $generated = 0;
    $skipped   = 0;
    $replaced  = 0;

    foreach ($employees as $emp) {
        $employeeId = $emp['employee_id'];
        $basic      = (float)($emp['monthly_salary'] ?? 0);

        // Check for existing record
        $dup = $pdo->prepare("
            SELECT payroll_id FROM payroll_records
            WHERE employee_id = ? AND period_id = ?
        ");
        $dup->execute([$employeeId, $periodId]);
        $existingId = $dup->fetchColumn();

        if ($existingId) {
            if ($forceRegenerate) {
                $pdo->prepare("DELETE FROM payroll_allowances WHERE payroll_id=?")->execute([$existingId]);
                $pdo->prepare("DELETE FROM payroll_deductions WHERE payroll_id=?")->execute([$existingId]);
                // Reset service credits linked to this payroll back to pre-applied status
                // so the re-generation loop picks them up again.
                // PARTIALLY_APPROVED records are restored correctly via date-count check.
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

        // Insert base payroll record
        $pdo->prepare("
            INSERT INTO payroll_records
                (period_id, employee_id, basic_pay, gross_pay,
                 total_allowances, total_deductions, net_pay, payroll_status)
            VALUES (?, ?, ?, 0, 0, 0, 0, 'DRAFT')
        ")->execute([$periodId, $employeeId, $basic]);
        $payrollId = (int)$pdo->lastInsertId();

        // Allowances — based on assignment rules. Service credits are still
        // applied below because approved credits are payroll-ready earnings.
        $assignedAllowanceTypes = [];
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
            $assignedAllowanceTypes = $atypes->fetchAll();
        }

        // ── Fetch APPROVED service credits for this employee ─────────────────────
        // Picks up ALL approved, unpaid credits regardless of work date.
        // Service credits are always for past work; they should be paid in the
        // next available payroll run. Date-range filtering caused a deadlock when
        // credits were approved after the matching period was already released.
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

        $totalAllowances = 0;
        $insA = $pdo->prepare("
            INSERT INTO payroll_allowances (payroll_id, allowance_type_id, amount)
            VALUES (?,?,?)
        ");

        // Track if Additional Assignment allowance was found + updated
        $addlAssignTypeId  = null;
        $addlAssignBaseAmt = 0;

        foreach ($assignedAllowanceTypes as $type) {
            $amt = (float)$type['default_amount'];

            // Merge service credit pay into the designated target allowance type.
            // Detection uses the is_service_credit_target DB flag — not name strings.
            if (!empty($type['is_service_credit_target'])) {
                $addlAssignTypeId  = $type['allowance_type_id'];
                $addlAssignBaseAmt = $amt;
                $amt              += $serviceCreditPay;
                $serviceCreditPay  = 0;
            }

            $insA->execute([$payrollId, $type['allowance_type_id'], $amt]);
            $totalAllowances += $amt;
        }

        // Fallback: service credit pay was not merged above because the target
        // allowance type was not assigned to this employee (narrow assignment rule).
        // Find the target type by flag and upsert the row directly.
        if ($serviceCreditPay > 0) {
            $addlType = $pdo->prepare("
                SELECT allowance_type_id FROM allowance_types
                WHERE is_active = 1 AND is_service_credit_target = 1
                LIMIT 1
            ");
            $addlType->execute();
            $addlTypeRow = $addlType->fetch();

            if ($addlTypeRow) {
                $updScAllowance = $pdo->prepare("
                    UPDATE payroll_allowances
                    SET amount = amount + ?
                    WHERE payroll_id = ? AND allowance_type_id = ?
                ");
                $updScAllowance->execute([$serviceCreditPay, $payrollId, $addlTypeRow['allowance_type_id']]);
                if ($updScAllowance->rowCount() === 0) {
                    $insA->execute([$payrollId, $addlTypeRow['allowance_type_id'], $serviceCreditPay]);
                }
            } else {
                throw new RuntimeException(
                    'Approved service credit exists but no allowance type has is_service_credit_target = 1. '
                    . 'Run migration 001 or go to Payroll Settings → Allowances and mark the target type.'
                );
            }
            $totalAllowances += $serviceCreditPay;
        }

        // Mark all merged service credits as APPLIED
        if (!empty($scIds)) {
            $ph = implode(',', array_fill(0, count($scIds), '?'));
            $pdo->prepare("
                UPDATE service_credits
                SET status='APPLIED', payroll_id=?, applied_to_payroll_at=NOW()
                WHERE service_credit_id IN ($ph)
            ")->execute(array_merge([$payrollId], $scIds));
        }

        // Deductions — use government bracket tables where applicable
        $totalDeductions    = 0;
        $govContribMonthly  = 0.0; // monthly SSS+PhilHealth+Pag-IBIG; used as withholding tax base
        $wtaxDtypeRow       = null; // withholding tax type processed after gov contributions
        $employerSss        = 0.0; // employer-side contributions (informational; not deducted from pay)
        $employerPhilHealth = 0.0;
        $employerPagibig    = 0.0;
        $insD = $pdo->prepare("
            INSERT INTO payroll_deductions (payroll_id, deduction_type_id, amount, loan_id)
            VALUES (?,?,?,?)
        ");

        // Fetch this employee's ACTIVE loans upfront for use in loan deductions.
        // loan_types.is_active is the Payroll Settings auto-deduct toggle.
        // loan_types.deduction_type_id links each loan to its payroll deduction row (migration 011).
        $empLoanStmt = $pdo->prepare("
            SELECT el.loan_id, el.monthly_deduction, el.balance_amount, lt.loan_name,
                   lt.deduction_type_id,
                   (lt.loan_name LIKE '%PERAA%') AS is_peraa
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
        ");
        $empLoanStmt->execute([
            ':eid'        => $employeeId,
            ':eid_assign' => $employeeId,
            ':dept'       => $emp['department_id'],
            ':pos'        => $emp['position_id'],
        ]);
        $empActiveLoansList = $empLoanStmt->fetchAll();

        // Build lookup: deduction_type_id → [per-payroll amount, loan_id]
        // monthly_deduction stores the MONTHLY AMORTIZATION; divide by periodDivisor
        // for the per-payroll deduction amount. PAUSED loans are excluded (status='ACTIVE' above).
        $loanByDedType = [];
        foreach ($empActiveLoansList as $el) {
            // PERAA loan: FULL_TIME employees only
            if ($el['is_peraa'] && ($emp['employment_type'] ?? 'FULL_TIME') !== 'FULL_TIME') continue;

            $dtId = (int)($el['deduction_type_id'] ?? 0);
            if (!$dtId) {
                // Fallback: loan type has no deduction_type_id mapping (run migration 011)
                // — skip silently so the payroll still generates without breaking
                continue;
            }

            // Per-payroll amount = monthly amortization ÷ payrolls_per_month
            $perPayroll = round((float)$el['monthly_deduction'] / $periodDivisor, 2);
            // Never deduct more than the remaining balance
            $perPayroll = min($perPayroll, (float)$el['balance_amount']);

            if (!isset($loanByDedType[$dtId])) {
                $loanByDedType[$dtId] = ['amount' => 0.0, 'loan_id' => (int)$el['loan_id']];
            }
            $loanByDedType[$dtId]['amount'] += $perPayroll;
        }

        foreach ($dtypes as $type) {
            // Respect deduction assignment rules. Older installs may not have
            // assignment rows for built-in deductions, so "no rows" means all.
            $assignCheck = $pdo->prepare("
                SELECT COUNT(*)
                FROM deduction_assignments da
                WHERE da.deduction_type_id = ?
                  AND da.is_active = 1
            ");
            $assignCheck->execute([$type['deduction_type_id']]);
            if ((int)$assignCheck->fetchColumn() > 0) {
                $appliesStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM deduction_assignments da
                    WHERE da.deduction_type_id = :did
                      AND da.is_active = 1
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
                if ((int)$appliesStmt->fetchColumn() === 0) {
                    continue;
                }
            }

            $amt          = 0;
            $loanIdForRow = null; // populated only for loan deductions (stored in payroll_deductions)
            $name         = strtolower($type['deduction_name']);

            // ── LOAN CHECK: runs FIRST, regardless of is_government flag ─────────
            // A deduction row is a loan if is_loan = 1 OR name contains 'loan'.
            $isLoanDeduction = $type['is_loan'] || strpos($name, 'loan') !== false;

            if ($isLoanDeduction) {
                // Look up by deduction_type_id FK (migration 011).
                $dtId = (int)$type['deduction_type_id'];
                if (isset($loanByDedType[$dtId]) && $loanByDedType[$dtId]['amount'] > 0) {
                    $amt          = $loanByDedType[$dtId]['amount'];
                    $loanIdForRow = $loanByDedType[$dtId]['loan_id'];
                }
                // amt stays 0 if employee has no active loan of this type — correct

            } elseif ($type['is_government']) {
                // Government CONTRIBUTIONS (non-loan) ─────────────────────────────
                // PERAA premium — FULL_TIME only
                if (strpos($name, 'peraa') !== false) {
                    if (($emp['employment_type'] ?? 'FULL_TIME') !== 'FULL_TIME') continue;
                }

                if ($governmentCalcMode === 'MANUAL') {
                    if ($type['deduction_value_type'] === 'PERCENTAGE') {
                        $amt = round($basic * ($type['deduction_rate'] / 100), 2);
                    } else {
                        $amt = (float)$type['deduction_amount'];
                    }
                } elseif (strpos($name, 'withholding') !== false || strpos($name, 'w/tax') !== false) {
                    // Deferred: withholding tax needs SSS+Phil+Pag-IBIG totals first
                    $wtaxDtypeRow = $type;
                    continue;

                } elseif (strpos($name, 'sss') !== false) {
                    // Bracket table stores monthly amounts → divide by periodDivisor
                    $row = $pdo->prepare("
                        SELECT employee_share, employer_share FROM sss_contribution_table
                        WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                        ORDER BY effective_date DESC LIMIT 1
                    ");
                    $row->execute([$basic, $basic]);
                    $r = $row->fetch();
                    $monthly     = $r ? (float)$r['employee_share'] : round($basic * 0.045, 2);
                    $employerSss = $r ? (float)$r['employer_share'] : round($basic * 0.095, 2);
                    $govContribMonthly += $monthly;
                    $amt = round($monthly / $periodDivisor, 2);

                } elseif (strpos($name, 'philhealth') !== false || strpos($name, 'phil') !== false) {
                    // Use fixed employee_share when non-zero; else compute basic × rate
                    $row = $pdo->prepare("
                        SELECT employee_share, employee_share_rate,
                               employer_share, employer_share_rate
                        FROM philhealth_contribution_table
                        WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                        ORDER BY effective_date DESC LIMIT 1
                    ");
                    $row->execute([$basic, $basic]);
                    $r = $row->fetch();
                    if ($r) {
                        $monthly            = (float)$r['employee_share'] > 0
                            ? (float)$r['employee_share']
                            : round($basic * (float)$r['employee_share_rate'], 2);
                        $employerPhilHealth = (float)$r['employer_share'] > 0
                            ? (float)$r['employer_share']
                            : round($basic * (float)$r['employer_share_rate'], 2);
                    } else {
                        $monthly            = round($basic * 0.025, 2);
                        $employerPhilHealth = $monthly;
                    }
                    $govContribMonthly += $monthly;
                    $amt = round($monthly / $periodDivisor, 2);

                } elseif (strpos($name, 'pag-ibig') !== false || strpos($name, 'hdmf') !== false
                          || strpos($name, 'pagibig') !== false) {
                    // Compute from employee_rate; cap at max_employee_contribution
                    $row = $pdo->prepare("
                        SELECT employee_rate, employer_rate, employee_share, employer_share,
                               max_employee_contribution
                        FROM pagibig_contribution_table
                        WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                        ORDER BY effective_date DESC LIMIT 1
                    ");
                    $row->execute([$basic, $basic]);
                    $r = $row->fetch();
                    if ($r) {
                        $monthly         = (float)$r['employee_share'] > 0
                            ? (float)$r['employee_share']
                            : round($basic * (float)$r['employee_rate'], 2);
                        $cap = ($r['max_employee_contribution'] !== null) ? (float)$r['max_employee_contribution'] : 0;
                        if ($cap > 0) $monthly = min($monthly, $cap);
                        $employerPagibig = (float)$r['employer_share'] > 0
                            ? (float)$r['employer_share']
                            : round($basic * (float)$r['employer_rate'], 2);
                    } else {
                        $monthly         = 100.00;
                        $employerPagibig = 100.00;
                    }
                    $govContribMonthly += $monthly;
                    $amt = round($monthly / $periodDivisor, 2);

                } elseif (strpos($name, 'peraa') !== false) {
                    // PERAA premium — fixed per-period amount from deduction_types
                    $amt = (float)$type['deduction_amount'];
                }

            } elseif ($type['deduction_value_type'] === 'PERCENTAGE') {
                $amt = round($basic * ($type['deduction_rate'] / 100), 2);

            } elseif ($type['deduction_value_type'] === 'FIXED') {
                // PERAA premium — FULL_TIME only
                if (strpos($name, 'peraa') !== false) {
                    if (($emp['employment_type'] ?? 'FULL_TIME') !== 'FULL_TIME') continue;
                }
                $amt = (float)$type['deduction_amount'];
            }

            if ($amt > 0) {
                $insD->execute([$payrollId, $type['deduction_type_id'], $amt, $loanIdForRow]);
                $totalDeductions += $amt;
            }
        }

        // ── Withholding tax — BIR TRAIN Law (2023+) ──────────────────────────────
        // Computed here, after SSS+PhilHealth+Pag-IBIG, so they can be subtracted
        // from the taxable base per BIR rules.
        if ($wtaxDtypeRow !== null && $autoApplyDeductions) {
            if ($governmentCalcMode === 'MANUAL') {
                $wtaxAmt = $wtaxDtypeRow['deduction_value_type'] === 'PERCENTAGE'
                    ? round($basic * ($wtaxDtypeRow['deduction_rate'] / 100), 2)
                    : (float)$wtaxDtypeRow['deduction_amount'];
            } else {
                // Taxable = (monthly_basic - monthly mandatory contributions) × 12
                $annualTaxable = max(0.0, ($basic - $govContribMonthly)) * 12;
                if ($annualTaxable <= 250000) {
                    $annualTax = 0.0;
                } elseif ($annualTaxable <= 400000) {
                    $annualTax = ($annualTaxable - 250000) * 0.15;
                } elseif ($annualTaxable <= 800000) {
                    $annualTax = 22500.0 + ($annualTaxable - 400000) * 0.20;
                } elseif ($annualTaxable <= 2000000) {
                    $annualTax = 102500.0 + ($annualTaxable - 800000) * 0.25;
                } elseif ($annualTaxable <= 8000000) {
                    $annualTax = 402500.0 + ($annualTaxable - 2000000) * 0.30;
                } else {
                    $annualTax = 2202500.0 + ($annualTaxable - 8000000) * 0.35;
                }
                $taxPeriods = $isSemiMonthly ? 24 : 12;
                $wtaxAmt = round($annualTax / $taxPeriods, 2);
            }
            if ($wtaxAmt > 0) {
                $insD->execute([$payrollId, $wtaxDtypeRow['deduction_type_id'], $wtaxAmt, null]);
                $totalDeductions += $wtaxAmt;
            }
        }

        // ── Absence deduction (excess leave days beyond credit balance) ──────────
        if ($absDedTypeId && $autoApplyDeductions && (float)($emp['daily_rate'] ?? 0) > 0) {
            $absResult = computeAbsenceDeduction($pdo, $employeeId, $periodId, (float)$emp['daily_rate']);
            if ($absResult !== null) {
                $pdo->prepare("
                    INSERT INTO payroll_deductions
                        (payroll_id, deduction_type_id, amount, absence_days, notes)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([
                    $payrollId,
                    $absDedTypeId,
                    $absResult['amount'],
                    $absResult['days'],
                    $absResult['notes'],
                ]);
            }
        }

        // Final totals — derived from child rows to ensure accuracy
        recalculatePayrollTotals($pdo, $payrollId);

        // Store employer-side contributions (informational; not deducted from employee pay)
        // Wrapped in try-catch: silently skipped if migration 005 has not been run yet.
        try {
            $pdo->prepare("
                UPDATE payroll_records
                SET employer_sss_share        = ?,
                    employer_philhealth_share = ?,
                    employer_pagibig_share    = ?
                WHERE payroll_id = ?
            ")->execute([$employerSss, $employerPhilHealth, $employerPagibig, $payrollId]);
        } catch (PDOException $e) {
            // Migration 005 not yet applied — employer shares not stored this run
        }

        $generated++;
    }

    $pdo->commit();

    // Audit
    $uid = $_SESSION['user']['user_id'] ?? null;
    if ($uid) {
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (?,?,?,?,?)
        ")->execute([$uid, 'GENERATE', 'payroll_records', $periodId,
                     "Generated payroll for {$scopeLabel} — period #{$periodId}"]);
    }

    $parts = [];
    if ($generated) $parts[] = "{$generated} record(s) generated";
    if ($replaced)  $parts[] = "{$replaced} re-generated";
    if ($skipped)   $parts[] = "{$skipped} already existed (skipped)";

    echo json_encode([
        'success'   => true,
        'message'   => implode(', ', $parts) . ".",
        'generated' => $generated,
        'replaced'  => $replaced,
        'skipped'   => $skipped,
    ]);

} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Payroll generation error: ' . $e->getMessage()]);
}
