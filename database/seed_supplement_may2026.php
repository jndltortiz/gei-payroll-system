<?php
/**
 * database/seed_supplement_may2026.php
 * Adds Feb–May 2026 data on top of an existing seed_demo.php run.
 * INSERT IGNORE throughout — safe to run multiple times.
 *
 * Run:  php database/seed_supplement_may2026.php
 */

set_time_limit(600);
require_once __DIR__ . '/../config/config.php';

// ── Helpers (same formulas as seed_demo.php) ──────────────────────────────
function sssEmployee(float $m): float {
    $msc = min(35000, max(5000, round($m / 500) * 500));
    return round($msc * 0.05, 2);
}
function sssEmployer(float $m): float {
    $msc = min(35000, max(5000, round($m / 500) * 500));
    return round($msc * 0.10, 2);
}
function phEmployee(float $m): float { return min(2500.00, max(250.00, round($m * 0.025, 2))); }
function pagibig(float $m): float { return min(200.00, round($m * 0.02, 2)); }
function withholdingTax(float $m, float $sssM, float $phM, float $pagibigM): float {
    $taxable = ($m * 12) - (($sssM + $phM + $pagibigM) * 12);
    if ($taxable <= 250000) return 0.00;
    if ($taxable <= 400000) return round(($taxable - 250000) * 0.15 / 12, 2);
    if ($taxable <= 800000) return round((22500 + ($taxable - 400000) * 0.20) / 12, 2);
    return round((102500 + ($taxable - 800000) * 0.25) / 12, 2);
}
function getWorkingDays(string $from, string $to, array $holidays): array {
    $days = [];
    $cur  = new DateTime($from);
    $end  = new DateTime($to);
    while ($cur <= $end) {
        $dow = (int)$cur->format('N');
        $ds  = $cur->format('Y-m-d');
        if ($dow <= 5 && !in_array($ds, $holidays)) $days[] = $ds;
        $cur->modify('+1 day');
    }
    return $days;
}
function p(float $n): string { return '₱' . number_format($n, 2); }

echo "═══════════════════════════════════════════════════\n";
echo "  GEI Payroll System — Supplement Seed (May 2026)\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════\n\n";

// ── 1. RESOLVE IDs FROM EXISTING DATA ────────────────────────────────────

echo "[1] Resolving existing IDs...\n";

// Employee IDs by employee_no
$empRows = $pdo->query("SELECT employee_id, employee_no FROM employees ORDER BY employee_no")->fetchAll(PDO::FETCH_ASSOC);
$empByNo = [];
foreach ($empRows as $r) { $empByNo[$r['employee_no']] = (int)$r['employee_id']; }

// Map emp_no → index (GEI-001 → 1, etc.)
$empId = []; // 1-based index → employee_id
for ($i = 1; $i <= 21; $i++) {
    $no = sprintf('GEI-%03d', $i);
    if (isset($empByNo[$no])) $empId[$i] = $empByNo[$no];
}

if (count($empId) < 21) {
    echo "  ✗ Could not resolve all employee IDs. Run seed_demo.php first.\n";
    exit(1);
}
echo "  ✓ " . count($empId) . " employees resolved\n";

// User IDs
$adminUid     = (int)$pdo->query("SELECT user_id FROM users WHERE username='admin' LIMIT 1")->fetchColumn();
$principalUid = (int)$pdo->query("SELECT user_id FROM users WHERE username='r.miguel' LIMIT 1")->fetchColumn();
$adminName     = 'Josephine M. dela Cruz';
$principalName = 'Ruby Ann B. Miguel';
echo "  ✓ Admin uid={$adminUid}, Principal uid={$principalUid}\n";

// School year IDs
$sy2526 = (int)$pdo->query("SELECT school_year_id FROM school_years WHERE year_name='2025-2026' LIMIT 1")->fetchColumn();
$sy2627 = (int)$pdo->query("SELECT school_year_id FROM school_years WHERE year_name='2026-2027' LIMIT 1")->fetchColumn();
echo "  ✓ SY 2025-2026 id={$sy2526}, SY 2026-2027 id={$sy2627}\n";

// Existing period IDs for the ones we need to promote to RELEASED
$period12Id = (int)$pdo->query("SELECT period_id FROM payroll_periods WHERE payroll_number='PR-2026-01-002' LIMIT 1")->fetchColumn(); // Jan 16-31 APPROVED
$period13Id = (int)$pdo->query("SELECT period_id FROM payroll_periods WHERE payroll_number='PR-2026-02-001' LIMIT 1")->fetchColumn(); // Feb 1-15 PROCESSING
$period14Id = (int)$pdo->query("SELECT period_id FROM payroll_periods WHERE payroll_number='PR-2026-03-001' LIMIT 1")->fetchColumn(); // Mar 1-15 OPEN
echo "  ✓ Existing periods: p12={$period12Id}, p13={$period13Id}, p14={$period14Id}\n";

// Monthly salaries from employee_compensations (is_active=1)
$compRows = $pdo->query("
    SELECT ec.employee_id, ec.monthly_salary, e.employee_no, e.employment_type
    FROM employee_compensations ec
    JOIN employees e ON e.employee_id = ec.employee_id
    WHERE ec.is_active = 1
")->fetchAll(PDO::FETCH_ASSOC);
$monthly = [];      // empIndex → monthly_salary
$empType = [];      // empIndex → FULL_TIME/PART_TIME
foreach ($compRows as $r) {
    foreach ($empId as $idx => $id) {
        if ($id === (int)$r['employee_id']) {
            $monthly[$idx] = (float)$r['monthly_salary'];
            $empType[$idx] = $r['employment_type'];
        }
    }
}
echo "  ✓ Salaries loaded for " . count($monthly) . " employees\n";

// Active loan deductions
// loan_type_id → deduction_type_id mapping
$loanTypeToDeductType = [4=>13, 5=>14, 6=>14, 7=>15, 8=>16];
$loanStmt = $pdo->query("
    SELECT el.loan_id, el.employee_id, el.loan_type_id, el.monthly_deduction
    FROM employee_loans el
    WHERE el.status = 'ACTIVE'
");
$activeLoanMap = []; // empIndex → [loan_id, deduct_type_id, monthly_amt]
foreach ($loanStmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
    foreach ($empId as $idx => $id) {
        if ($id === (int)$l['employee_id']) {
            // If employee has multiple active loans, we simplify to first found.
            // In seed_demo we only have one active loan per emp for payroll.
            if (!isset($activeLoanMap[$idx])) {
                $dtId = $loanTypeToDeductType[$l['loan_type_id']] ?? null;
                if ($dtId) {
                    $activeLoanMap[$idx] = [
                        'loan_id'          => (int)$l['loan_id'],
                        'deduct_type_id'   => $dtId,
                        'monthly_deduction'=> (float)$l['monthly_deduction'],
                    ];
                }
            }
        }
    }
}
echo "  ✓ Active loan data loaded for " . count($activeLoanMap) . " employees\n";

// ── 2. UPDATE SY 2026-2027 (start May 1) AND ACTIVATE ───────────────────
echo "\n[2] Updating SY 2026-2027 start date and activating...\n";

$pdo->exec("UPDATE school_years SET is_active = 0");
$pdo->prepare("UPDATE school_years SET start_date='2026-05-01', is_active=1 WHERE school_year_id=?")->execute([$sy2627]);
echo "  ✓ SY 2025-2026 deactivated; SY 2026-2027 start moved to 2026-05-01, set ACTIVE\n";

// ── 3. LEAVE CREDITS FOR SY 2026-2027 ────────────────────────────────────
echo "\n[3] Allocating leave credits for SY 2026-2027...\n";

$lcUpsert = $pdo->prepare("
    INSERT INTO employee_leave_credits (employee_id, school_year_id, leave_type_id, allocated_days, used_days)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE allocated_days = VALUES(allocated_days)
");
// Leave type IDs: 2=Vacation, 3=Sick, 4=Maternity, 5=Paternity, 6=Emergency
$lcCount = 0;
foreach ($empId as $n => $id) {
    if (($empType[$n] ?? '') !== 'FULL_TIME') continue;
    // Vacation 30d, Sick 15d, Emergency 3d for everyone
    // Used days will be updated after leave requests are inserted
    $lcUpsert->execute([$id, $sy2627, 2, 30.00, 0.00]); // Vacation
    $lcUpsert->execute([$id, $sy2627, 3, 15.00, 0.00]); // Sick
    $lcUpsert->execute([$id, $sy2627, 6,  3.00, 0.00]); // Emergency
    $lcCount++;
    // Special: Benjamin gets Paternity
    if ($n === 8) $lcUpsert->execute([$id, $sy2627, 5, 7.00, 0.00]);
    // Gloria gets Maternity (statutory)
    if ($n === 5) $lcUpsert->execute([$id, $sy2627, 4, 60.00, 0.00]);
}
echo "  ✓ Leave credits allocated for {$lcCount} full-time employees\n";

// ── 4. PROMOTE EXISTING PERIODS 12, 13, 14 TO RELEASED ───────────────────
echo "\n[4] Promoting periods 12/13/14 to RELEASED...\n";

$periodsToRelease = [
    $period12Id => ['pay_end'=>'2026-01-31', 'rel_offset'=>'+4 days', 'pr_was'=>'APPROVED'],
    $period13Id => ['pay_end'=>'2026-02-15', 'rel_offset'=>'+5 days', 'pr_was'=>'DRAFT'],
    $period14Id => ['pay_end'=>'2026-03-15', 'rel_offset'=>'+6 days', 'pr_was'=>'DRAFT'],
];

$wlStmt = $pdo->prepare("
    INSERT IGNORE INTO payroll_workflow_log
        (period_id, event_type, performed_by, performer_name, gross_total, net_total, emp_count, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$psInsert = $pdo->prepare("
    INSERT IGNORE INTO payslips (payroll_id, payslip_number, generated_at, received_by, date_received)
    VALUES (?, ?, ?, ?, ?)
");

foreach ($periodsToRelease as $pid => $pInfo) {
    ['pay_end'=>$payEnd, 'rel_offset'=>$relOffset, 'pr_was'=>$prWas] = $pInfo;
    if (!$pid) { echo "  ⚠ Period ID=0, skipping\n"; continue; }

    // Compute totals for workflow log
    $totals = $pdo->prepare("
        SELECT COALESCE(SUM(gross_pay),0) AS g, COALESCE(SUM(net_pay),0) AS n, COUNT(*) AS c
        FROM payroll_records WHERE period_id=?
    ");
    $totals->execute([$pid]);
    $t = $totals->fetch();
    [$g, $n, $ec] = [(float)$t['g'], (float)$t['n'], (int)$t['c']];

    $base     = strtotime($payEnd);
    $relTime  = date('Y-m-d H:i:s', strtotime($payEnd . ' ' . $relOffset . ' 15:00:00'));
    $appTime  = date('Y-m-d H:i:s', strtotime($payEnd . ' +2 days 14:00:00'));
    $subTime  = date('Y-m-d H:i:s', strtotime($payEnd . ' +1 day 10:00:00'));

    // Add missing workflow events based on what status the period had
    if ($prWas === 'DRAFT') {
        $wlStmt->execute([$pid, 'SUBMITTED',  $adminUid,     $adminName,     $g, $n, $ec, $subTime]);
        $wlStmt->execute([$pid, 'APPROVED',   $principalUid, $principalName, $g, $n, $ec, $appTime]);
    } elseif ($prWas === 'APPROVED') {
        // SUBMITTED + APPROVED already exist, just need RELEASED
    }
    $wlStmt->execute([$pid, 'RELEASED', $adminUid, $adminName, $g, $n, $ec, $relTime]);

    // Update period and records
    $pdo->prepare("UPDATE payroll_periods SET status='RELEASED' WHERE period_id=?")->execute([$pid]);
    $pdo->prepare("
        UPDATE payroll_records
        SET payroll_status='RELEASED', released_by=?, released_at=?
        WHERE period_id=?
    ")->execute([$adminUid, $relTime, $pid]);

    // Payslips
    $prRows = $pdo->prepare("SELECT payroll_id FROM payroll_records WHERE period_id=?");
    $prRows->execute([$pid]);
    $psPayDate = date('Y-m-d', strtotime($payEnd . ' ' . $relOffset));
    foreach ($prRows->fetchAll(PDO::FETCH_COLUMN) as $prId) {
        $psNum = sprintf('PSL%s%06d', str_replace('-','',substr($payEnd,0,7)), $prId);
        $psInsert->execute([$prId, $psNum, $relTime, null, $psPayDate]);
    }
    echo "  ✓ Period {$pid} → RELEASED (g=" . p($g) . " n=" . p($n) . " emp={$ec})\n";
}

// ── 5. NEW PAYROLL PERIODS (Mar 16-31, Apr 16-30, May 1-15, May 16-31) ──
echo "\n[5] Adding new payroll periods and records...\n";

$ppInsert = $pdo->prepare("
    INSERT IGNORE INTO payroll_periods
        (payroll_number, period_name, period_type, pay_period_start, pay_period_end, pay_date, status)
    VALUES (?,?,?,?,?,?,?)
");
$prInsert = $pdo->prepare("
    INSERT INTO payroll_records
        (period_id, employee_id, basic_pay, gross_pay, total_allowances, total_deductions,
         net_pay, employer_sss_share, employer_philhealth_share, employer_pagibig_share,
         remarks, payroll_status, approved_by, approved_at, released_by, released_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
$paInsert = $pdo->prepare("INSERT INTO payroll_allowances (payroll_id, allowance_type_id, amount) VALUES (?,?,?)");
$pdInsert = $pdo->prepare("INSERT INTO payroll_deductions (payroll_id, deduction_type_id, loan_id, amount) VALUES (?,?,?,?)");

$newPeriods = [
    // [payroll_number, name, type, start, end, pay_date, status]
    ['PR-2026-03-002','March 16–31, 2026',   'REGULAR','2026-03-16','2026-03-31','2026-03-31','RELEASED'],
    ['PR-2026-04-002','April 16–30, 2026',   'REGULAR','2026-04-16','2026-04-30','2026-04-29','RELEASED'],
    ['PR-2026-05-001','May 1–15, 2026',      'REGULAR','2026-05-01','2026-05-15','2026-05-15','APPROVED'],
    ['PR-2026-05-002','May 16–31, 2026',     'REGULAR','2026-05-16','2026-05-31','2026-05-30','OPEN'],
];

$newPeriodIds = [];
foreach ($newPeriods as $pp) {
    // Check if already exists
    $existing = $pdo->prepare("SELECT period_id FROM payroll_periods WHERE payroll_number=? LIMIT 1");
    $existing->execute([$pp[0]]);
    $existingId = $existing->fetchColumn();
    if ($existingId) {
        $newPeriodIds[] = ['id' => (int)$existingId, 'data' => $pp];
        echo "  ↩ Period {$pp[0]} already exists (id={$existingId}), skipping records\n";
        continue;
    }

    $ppInsert->execute($pp);
    $pid   = (int)$pdo->lastInsertId();
    $newPeriodIds[] = ['id' => $pid, 'data' => $pp];

    $status  = $pp[6];
    $prStat  = match($status) { 'RELEASED'=>'RELEASED','APPROVED'=>'APPROVED', default=>'DRAFT' };
    $appBy   = in_array($status,['RELEASED','APPROVED']) ? $principalUid : null;
    $appAt   = $appBy ? date('Y-m-d H:i:s', strtotime($pp[4] . ' +2 days 14:00:00')) : null;
    $relBy   = ($status==='RELEASED') ? $adminUid : null;
    $relAt   = $relBy ? date('Y-m-d H:i:s', strtotime($pp[4] . ' +3 days 15:00:00')) : null;

    $periodPayrollIds = [];
    foreach ($empId as $n => $eId) {
        $m = $monthly[$n] ?? 0;
        if (!$m) continue;

        $sssEmpM  = sssEmployee($m);  $sssErM  = sssEmployer($m);
        $phEmpM   = phEmployee($m);   $phErM   = $phEmpM;
        $pagibigM = pagibig($m);
        $peraaM   = (($empType[$n] ?? '') === 'FULL_TIME') ? 200.00 : 0.00;
        $wtM      = withholdingTax($m, $sssEmpM, $phEmpM, $pagibigM);

        $sssP     = round($sssEmpM / 2, 2);  $sssErP  = round($sssErM / 2, 2);
        $phP      = round($phEmpM  / 2, 2);  $phErP   = round($phErM  / 2, 2);
        $pagibigP = round($pagibigM / 2, 2);
        $peraaP   = round($peraaM / 2, 2);
        $wtP      = round($wtM / 2, 2);

        $rice = 1000.00; $laundry = 500.00;
        $assignPay = match($n) { 1=>1500.00, 2=>1000.00, default=>0.00 };
        $basicPay  = round($m / 2, 2);

        // Loan deduction
        $loanAmt  = 0.00; $loanDtId = null; $loanDbId = null;
        if (isset($activeLoanMap[$n])) {
            $loanAmt  = round($activeLoanMap[$n]['monthly_deduction'] / 2, 2);
            $loanDtId = $activeLoanMap[$n]['deduct_type_id'];
            $loanDbId = $activeLoanMap[$n]['loan_id'];
        }

        $totalAllowances = $rice + $laundry + $assignPay;
        $govDeductions   = $sssP + $phP + $pagibigP + $peraaP + $wtP;
        $totalDeductions = $govDeductions + $loanAmt;
        $grossPay        = $basicPay + $totalAllowances;
        $netPay          = max(0, $grossPay - $totalDeductions);

        $prInsert->execute([
            $pid, $eId, $basicPay, $grossPay, $totalAllowances, $totalDeductions,
            $netPay, $sssErP, $phErP, round($pagibigM/2, 2),
            null, $prStat, $appBy, $appAt, $relBy, $relAt,
        ]);
        $prId = (int)$pdo->lastInsertId();
        $periodPayrollIds[$n] = $prId;

        $paInsert->execute([$prId, 4, $rice]);
        $paInsert->execute([$prId, 5, $laundry]);
        if ($assignPay > 0) $paInsert->execute([$prId, 6, $assignPay]);

        $pdInsert->execute([$prId, 8,  null, $sssP]);
        $pdInsert->execute([$prId, 9,  null, $phP]);
        $pdInsert->execute([$prId, 10, null, $pagibigP]);
        if ($peraaP > 0)  $pdInsert->execute([$prId, 12, null, $peraaP]);
        if ($wtP   > 0)   $pdInsert->execute([$prId, 11, null, $wtP]);
        if ($loanAmt > 0) $pdInsert->execute([$prId, $loanDtId, $loanDbId, $loanAmt]);
    }

    // Workflow logs
    [$g2, $n2] = [0.0, 0.0]; $ec2 = 0;
    foreach ($periodPayrollIds as $prId) {
        $r = $pdo->query("SELECT gross_pay,net_pay FROM payroll_records WHERE payroll_id={$prId}")->fetch();
        $g2 += $r['gross_pay']; $n2 += $r['net_pay']; $ec2++;
    }
    $base = strtotime($pp[4]);
    $wlStmt->execute([$pid,'GENERATED', $adminUid,$adminName,$g2,$n2,$ec2, date('Y-m-d H:i:s',$base-86400*5+32400)]);
    if (in_array($status,['PROCESSING','APPROVED','RELEASED'])) {
        $wlStmt->execute([$pid,'SUBMITTED', $adminUid,$adminName,$g2,$n2,$ec2, date('Y-m-d H:i:s',$base-86400*4+36000)]);
    }
    if (in_array($status,['APPROVED','RELEASED'])) {
        $wlStmt->execute([$pid,'APPROVED', $principalUid,$principalName,$g2,$n2,$ec2, date('Y-m-d H:i:s',$base-86400*3+50400)]);
    }
    if ($status === 'RELEASED') {
        $wlStmt->execute([$pid,'RELEASED', $adminUid,$adminName,$g2,$n2,$ec2, date('Y-m-d H:i:s',$base-86400*2+54000)]);
        // Payslips for RELEASED
        foreach ($periodPayrollIds as $empN => $prId) {
            $psNum = sprintf('PSL%s%06d', str_replace('-','',substr($pp[3],0,7)), $prId);
            $psInsert->execute([$prId, $psNum, date('Y-m-d H:i:s',$base+39600), null, $pp[5]]);
        }
    }
    echo "  ✓ Period {$pp[0]} [{$status}] — " . count($periodPayrollIds) . " records, " . p($g2) . " gross\n";
}

// ── 6. ATTENDANCE RECORDS — Feb 1 to May 27, 2026 ────────────────────────
echo "\n[6] Seeding attendance records (Feb 1 – May 27, 2026)...\n";

// Philippine holidays in this range
$holidays2026 = [
    '2026-02-25', // EDSA Anniversary (Special Non-Working)
    '2026-04-02', // Maundy Thursday
    '2026-04-03', // Good Friday
    '2026-04-04', // Black Saturday
    '2026-04-09', // Araw ng Kagitingan
    '2026-05-01', // Labor Day
];
$workDays2026 = getWorkingDays('2026-02-02', '2026-05-27', $holidays2026);

// Late/half-day exceptions: [empIndex => [date, ...]]
$lateEx = [
    3  => ['2026-02-10', '2026-03-05'],
    7  => ['2026-03-18'],
    16 => ['2026-04-08'],
];
// Absent exceptions (no leave request, just absent)
$absentEx = [
    16 => ['2026-04-20'],
    11 => ['2026-02-17'],
];
// Leave exceptions (matched to leave requests below)
$leaveEx2026 = [
    14 => ['2026-05-12', '2026-05-13'],  // Kevin — sick leave (APPROVED)
    9  => ['2026-05-19', '2026-05-20'],  // Carmelita — vacation (APPROVED)
    13 => ['2026-05-26'],                // Herminia — emergency (PENDING → ABSENT)
];

$aStmt = $pdo->prepare("
    INSERT IGNORE INTO attendance_records
        (employee_id, attendance_date, time_in, time_out, attendance_status,
         review_status, school_year_id, attendance_source, verification_status, late_minutes)
    VALUES (?,?,?,?,?,'REVIEWED',{$sy2627},'MANUAL','VERIFIED',?)
");

$attCount = 0;
foreach ($empId as $n => $id) {
    foreach ($workDays2026 as $date) {
        $status  = 'PRESENT';
        $timeIn  = '07:29:00';
        $timeOut = '16:31:00';
        $lateMin = 0;

        if (isset($leaveEx2026[$n]) && in_array($date, $leaveEx2026[$n])) {
            // Herminia pending leave → absent; approved leave → LEAVE
            $status  = ($n === 13) ? 'ABSENT' : 'LEAVE';
            $timeIn  = null; $timeOut = null;
        } elseif (isset($absentEx[$n]) && in_array($date, $absentEx[$n])) {
            $status  = 'ABSENT';
            $timeIn  = null; $timeOut = null;
        } elseif (isset($lateEx[$n]) && in_array($date, $lateEx[$n])) {
            $status  = 'HALF_DAY';
            $timeIn  = '09:10:00'; $timeOut = '16:31:00';
            $lateMin = 100;
        } elseif (($empType[$n] ?? '') === 'PART_TIME') {
            $timeIn  = '08:01:00'; $timeOut = '12:04:00';
        }

        $aStmt->execute([$id, $date, $timeIn, $timeOut, $status, $lateMin]);
        $attCount++;
    }
}
echo "  ✓ {$attCount} attendance records inserted (Feb–May 2026)\n";

// ── 7. LEAVE REQUESTS FOR MAY 2026 (SY 2026-2027) ────────────────────────
echo "\n[7] Adding May 2026 leave requests (SY 2026-2027)...\n";

$lrInsert = $pdo->prepare("
    INSERT IGNORE INTO leave_requests
        (employee_id, leave_type_id, school_year_id, reason, start_date, end_date,
         total_days, status, workflow_status, approved_by, approved_at,
         is_attendance_recorded, recorded_at, recorded_by)
    VALUES (?,?,{$sy2627},?,?,?,?,?,?,?,?,1,NOW(),?)
");
$ldInsert = $pdo->prepare("
    INSERT IGNORE INTO leave_request_dates (leave_id, leave_date, status, actioned_by, actioned_at)
    VALUES (?,?,?,?,?)
");

$newLeaves = [
    // [empN, leaveTypeId, reason, start, end, days, status, workflow, dates=>[d=>status], usedType]
    [14, 3, 'Fever and colds — consulted clinic',
        '2026-05-12','2026-05-13', 2.0, 'APPROVED','RECORDED',
        ['2026-05-12'=>'APPROVED','2026-05-13'=>'APPROVED'], 'sick'],
    [9,  2, 'Family vacation and rest',
        '2026-05-19','2026-05-20', 2.0, 'APPROVED','RECORDED',
        ['2026-05-19'=>'APPROVED','2026-05-20'=>'APPROVED'], 'vacation'],
    [13, 6, 'Family emergency — spouse hospitalized',
        '2026-05-26','2026-05-26', 1.0, 'PENDING','PENDING_REVIEW',
        ['2026-05-26'=>'PENDING'], 'emergency'],
];

// Used days update: leaveTypeId → leave_credits field mapping
$leaveTypeToIdx = [2=>'vacation', 3=>'sick', 6=>'emergency'];

foreach ($newLeaves as $lv) {
    [$empN, $ltId, $reason, $start, $end, $days, $status, $workflow, $dates, $creditType] = $lv;
    $eId       = $empId[$empN];
    $approved  = ($status !== 'PENDING') ? 1 : 0;
    $appBy     = $approved ? $principalUid : null;
    $appAt     = $approved ? date('Y-m-d H:i:s', strtotime($start) + 43200) : null;
    $recBy     = ($workflow === 'RECORDED') ? $adminUid : null;

    $lrInsert->execute([$eId, $ltId, $reason, $start, $end, $days, $status, $workflow, $appBy, $appAt, $recBy]);
    $lvId = (int)$pdo->lastInsertId();

    if ($lvId) {
        foreach ($dates as $d => $dStatus) {
            $actBy = ($dStatus !== 'PENDING') ? $principalUid : null;
            $actAt = ($dStatus !== 'PENDING') ? date('Y-m-d H:i:s', strtotime($d) + 50400) : null;
            $ldInsert->execute([$lvId, $d, $dStatus, $actBy, $actAt]);
        }

        // Update leave credits used_days for APPROVED leaves
        if ($approved && $days > 0) {
            $pdo->prepare("
                UPDATE employee_leave_credits
                SET used_days = used_days + ?
                WHERE employee_id=? AND school_year_id=? AND leave_type_id=?
            ")->execute([$days, $eId, $sy2627, $ltId]);
        }
        $empName = $empByNo ? array_search($eId, $empId) : "emp{$empN}";
        echo "  ✓ Leave [{$status}]: emp#{$empN} — {$start} to {$end} ({$days}d)\n";
    } else {
        echo "  ↩ Leave for emp#{$empN} {$start} already exists (IGNORE)\n";
    }
}

// ── 8. ADD HOLIDAYS FOR SY 2026-2027 ─────────────────────────────────────
echo "\n[8] Adding 2026-2027 school-year holidays...\n";
$hStmt = $pdo->prepare("INSERT IGNORE INTO holidays (holiday_date, holiday_name, holiday_type, school_year_id) VALUES (?,?,?,{$sy2627})");
$holidays = [
    ['2026-06-12','Independence Day','REGULAR'],
    ['2026-08-21','Ninoy Aquino Day','SPECIAL'],
    ['2026-08-31','National Heroes Day','REGULAR'],
    ['2026-09-21','Military Coup Anniversary','SPECIAL'],
    ['2026-10-31','Halloween','SPECIAL'],
    ['2026-11-01','All Saints Day','REGULAR'],
    ['2026-11-02','All Souls Day','SPECIAL'],
    ['2026-11-30','Bonifacio Day','REGULAR'],
    ['2026-12-08','Feast of Immaculate Conception','SPECIAL'],
    ['2026-12-24','Christmas Eve','SCHOOL'],
    ['2026-12-25','Christmas Day','REGULAR'],
    ['2026-12-29','School Christmas Break','SCHOOL'],
    ['2026-12-30','Rizal Day','REGULAR'],
    ['2026-12-31','New Year\'s Eve','SCHOOL'],
    ['2027-01-01','New Year\'s Day','REGULAR'],
    ['2027-02-25','EDSA Anniversary','SPECIAL'],
    ['2027-04-01','Maundy Thursday','REGULAR'],
    ['2027-04-02','Good Friday','REGULAR'],
    ['2027-04-09','Araw ng Kagitingan','REGULAR'],
    ['2027-05-01','Labor Day','REGULAR'],
];
$hCount = 0;
foreach ($holidays as $h) { $hStmt->execute($h); $hCount++; }
echo "  ✓ {$hCount} holidays added for SY 2026-2027\n";

// ── SUMMARY ───────────────────────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════\n";
echo "  SUPPLEMENT COMPLETE\n";
echo "═══════════════════════════════════════════════════\n";

$counts = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM payroll_periods)                AS pp_total,
      (SELECT COUNT(*) FROM payroll_periods WHERE status='RELEASED') AS pp_released,
      (SELECT COUNT(*) FROM payroll_records WHERE payroll_status='RELEASED') AS pr_released,
      (SELECT COUNT(*) FROM attendance_records)             AS att_total,
      (SELECT MAX(attendance_date) FROM attendance_records) AS att_latest,
      (SELECT COUNT(*) FROM leave_requests)                 AS leave_total,
      (SELECT COUNT(*) FROM employee_leave_credits WHERE school_year_id={$sy2627}) AS credits_sy2627,
      (SELECT year_name FROM school_years WHERE is_active=1 LIMIT 1) AS active_sy
")->fetch(PDO::FETCH_ASSOC);

foreach ($counts as $k => $v) {
    echo sprintf("  %-30s %s\n", str_replace('_',' ',ucfirst($k)).':', $v);
}
echo "\n  ✓ Finished at " . date('H:i:s') . "\n\n";
echo "  Next steps:\n";
echo "  1. Verify attendance shows in dashboard weekly chart\n";
echo "  2. Check payroll archive — should show through Apr 2026\n";
echo "  3. Check analytics — payroll trend + attendance rate updated\n";
echo "  4. Check leave module — SY 2026-2027 active, credits allocated\n\n";
