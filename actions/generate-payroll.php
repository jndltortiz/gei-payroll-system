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

// ── Inline helper: re-derive payroll totals from child rows ───────────────────
// Defined here (not in a separate include) so static analysis tools can resolve it.
function recalculatePayrollTotals(PDO $pdo, int $payrollId): void
{
    $pdo->prepare("
        UPDATE payroll_records pr
        SET
            total_allowances = (
                SELECT COALESCE(SUM(pa.amount), 0)
                FROM payroll_allowances pa
                WHERE pa.payroll_id = pr.payroll_id
            ),
            total_deductions = (
                SELECT COALESCE(SUM(pd.amount), 0)
                FROM payroll_deductions pd
                WHERE pd.payroll_id = pr.payroll_id
            ),
            gross_pay = pr.basic_pay + (
                SELECT COALESCE(SUM(pa.amount), 0)
                FROM payroll_allowances pa
                WHERE pa.payroll_id = pr.payroll_id
            ),
            net_pay = (
                pr.basic_pay
                + (SELECT COALESCE(SUM(pa.amount), 0) FROM payroll_allowances pa WHERE pa.payroll_id = pr.payroll_id)
                - (SELECT COALESCE(SUM(pd.amount), 0) FROM payroll_deductions pd WHERE pd.payroll_id = pr.payroll_id)
            ),
            updated_at = NOW()
        WHERE pr.payroll_id = ?
    ")->execute([$payrollId]);
}
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

// ── Pre-fetch deduction types (assignment rules are checked per employee) ─────
$dtypes = $autoApplyDeductions
    ? $pdo->query("SELECT * FROM deduction_types WHERE is_active = 1")->fetchAll()
    : [];

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
                // Reset any service credits that were linked to this payroll back to APPROVED
                // so the re-generation loop can pick them up again.
                $pdo->prepare("
                    UPDATE service_credits
                    SET payroll_id = NULL, status = 'APPROVED', applied_to_payroll_at = NULL
                    WHERE payroll_id = ?
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
                SELECT DISTINCT at2.allowance_type_id, at2.allowance_name, at2.default_amount
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

        // ── Fetch APPROVED service credits for this employee in this payroll period ──
        // Approved credits are merged into Additional Assignment Payment
        $scStmt = $pdo->prepare("
            SELECT service_credit_id, equivalent_pay
            FROM service_credits
            WHERE employee_id = :eid
              AND status = 'APPROVED'
              AND payroll_id IS NULL
              AND work_date BETWEEN :start AND :end
        ");
        $scStmt->execute([
            ':eid'   => $employeeId,
            ':start' => $period['pay_period_start'],
            ':end'   => $period['pay_period_end'],
        ]);
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
            $amt  = (float)$type['default_amount'];
            $name = strtolower($type['allowance_name'] ?? '');

            // Merge service credit pay into Additional Assignment allowance
            if ((strpos($name, 'additional') !== false && strpos($name, 'assign') !== false)
                 || strpos($name, 'addl') !== false || strpos($name, 'add\'l') !== false) {
                $addlAssignTypeId  = $type['allowance_type_id'];
                $addlAssignBaseAmt = $amt;
                $amt               = $amt + $serviceCreditPay; // merge here
                $serviceCreditPay  = 0; // already merged, don't add again
            }

            $insA->execute([$payrollId, $type['allowance_type_id'], $amt]);
            $totalAllowances += $amt;
        }

        // If no Additional Assignment allowance type exists yet, add service credit pay separately
        // (edge case: allowance type not configured)
        if ($serviceCreditPay > 0) {
            // Try to find Additional Assignment allowance type
            $addlType = $pdo->prepare("
                SELECT allowance_type_id FROM allowance_types
                WHERE is_active = 1
                  AND (allowance_name LIKE '%Additional Assignment%'
                    OR allowance_name LIKE '%Add%l%Assign%')
                LIMIT 1
            ");
            $addlType->execute();
            $addlTypeRow = $addlType->fetch();

            if ($addlTypeRow) {
                // Update an existing row, or insert one when the base allowance
                // was not assigned to this employee. Approved service credits
                // must not vanish because an allowance assignment is narrow.
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
                    'Approved service credit exists, but no active Additional Assignment allowance type is configured.'
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
        $totalDeductions = 0;
        $insD = $pdo->prepare("
            INSERT INTO payroll_deductions (payroll_id, deduction_type_id, amount)
            VALUES (?,?,?)
        ");

        // Fetch this employee's ACTIVE loans upfront for use in loan deductions.
        // loan_types.is_active is the Payroll Settings auto-deduct toggle.
        $empLoanStmt = $pdo->prepare("
            SELECT el.loan_id, el.monthly_deduction, el.balance_amount, lt.loan_name
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

        // Build lookup: keyword → total monthly deduction + loan IDs to update balance
        $loanAmounts = ['sss'=>0,'hdmf'=>0,'peraa'=>0,'rural'=>0];
        $loanIds     = ['sss'=>[],'hdmf'=>[],'peraa'=>[],'rural'=>[]];
        foreach ($empActiveLoansList as $el) {
            $ln = strtolower($el['loan_name']);
            $ma = (float)$el['monthly_deduction'];
            $ma = min($ma, (float)$el['balance_amount']); // never deduct more than balance
            if      (strpos($ln,'sss')   !== false) { $loanAmounts['sss']   += $ma; $loanIds['sss'][]   = [$el['loan_id'],$ma]; }
            elseif  (strpos($ln,'rural') !== false) { $loanAmounts['rural'] += $ma; $loanIds['rural'][] = [$el['loan_id'],$ma]; }
            elseif  (strpos($ln,'peraa') !== false) { $loanAmounts['peraa'] += $ma; $loanIds['peraa'][] = [$el['loan_id'],$ma]; }
            elseif  (strpos($ln,'pag-ibig') !== false || strpos($ln,'hdmf') !== false) {
                $loanAmounts['hdmf'] += $ma; $loanIds['hdmf'][] = [$el['loan_id'],$ma];
            }
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

            $amt  = 0;
            $name = strtolower($type['deduction_name']);

            // ── LOAN CHECK: runs FIRST, regardless of is_government flag ─────────
            // A deduction is a loan if:
            //   (a) is_loan = 1, OR
            //   (b) the name contains 'loan' (catches cases where is_government is
            //       set incorrectly on loan deduction types)
            $isLoanDeduction = $type['is_loan'] || strpos($name, 'loan') !== false;

            if ($isLoanDeduction) {
                // PERAA loan — FULL_TIME only
                if (strpos($name, 'peraa') !== false) {
                    if (($emp['employment_type'] ?? 'FULL_TIME') !== 'FULL_TIME') continue;
                    $amt = $loanAmounts['peraa'];
                } elseif (strpos($name, 'sss') !== false) {
                    $amt = $loanAmounts['sss'];
                } elseif (strpos($name, 'hdmf') !== false || strpos($name, 'pag-ibig') !== false
                          || strpos($name, 'pagibig') !== false) {
                    $amt = $loanAmounts['hdmf'];
                } elseif (strpos($name, 'rural') !== false) {
                    $amt = $loanAmounts['rural'];
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
                } elseif (strpos($name, 'sss') !== false) {
                    $row = $pdo->prepare("
                        SELECT employee_share FROM sss_contribution_table
                        WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                        ORDER BY effective_date DESC LIMIT 1
                    ");
                    $row->execute([$basic, $basic]);
                    $r = $row->fetch();
                    $amt = $r ? (float)$r['employee_share'] : round($basic * 0.045, 2);

                } elseif (strpos($name, 'philhealth') !== false || strpos($name, 'phil') !== false) {
                    $row = $pdo->prepare("
                        SELECT employee_share FROM philhealth_contribution_table
                        WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                        ORDER BY effective_date DESC LIMIT 1
                    ");
                    $row->execute([$basic, $basic]);
                    $r = $row->fetch();
                    $amt = $r ? (float)$r['employee_share'] : round($basic * 0.025, 2);

                } elseif (strpos($name, 'pag-ibig') !== false || strpos($name, 'hdmf') !== false
                          || strpos($name, 'pagibig') !== false) {
                    $row = $pdo->prepare("
                        SELECT employee_share FROM pagibig_contribution_table
                        WHERE min_salary <= ? AND max_salary >= ? AND is_active = 1
                        ORDER BY effective_date DESC LIMIT 1
                    ");
                    $row->execute([$basic, $basic]);
                    $r = $row->fetch();
                    $amt = $r ? (float)$r['employee_share'] : 200;

                } elseif (strpos($name, 'peraa') !== false) {
                    // PERAA premium (FIXED amount from deduction_types)
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

            if ($amt >= 0) {
                $insD->execute([$payrollId, $type['deduction_type_id'], $amt]);
                $totalDeductions += $amt;
            }
        }

        // Final totals — derived from child rows to ensure accuracy
        recalculatePayrollTotals($pdo, $payrollId);

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
