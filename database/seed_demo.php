<?php
/**
 * database/seed_demo.php
 * GEI Payroll System — Realistic Demo Seed
 * Based on GEI Transcript of Response, La Paz, Tarlac
 *
 * Passwords
 *   admin, treasurer, r.miguel → Admin@GEI2025
 *   all other employee accounts → Employee@GEI2025
 *
 * Run:  php database/seed_demo.php
 */

set_time_limit(300);
require_once __DIR__ . '/../config/config.php';

// ─── helpers ──────────────────────────────────────────────────────────────────

function sssEmployee(float $m): float {
    $msc = min(35000, max(5000, round($m / 500) * 500));
    return round($msc * 0.05, 2);
}
function sssEmployer(float $m): float {
    $msc = min(35000, max(5000, round($m / 500) * 500));
    return round($msc * 0.10, 2);
}
function phEmployee(float $m): float {
    return min(2500.00, max(250.00, round($m * 0.025, 2)));
}
function pagibig(float $m): float {
    return min(200.00, round($m * 0.02, 2));
}
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
echo "  GEI Payroll System — Demo Seed\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════\n\n";

// ─── 1. TRUNCATE DATA TABLES ───────────────────────────────────────────────────
echo "[1] Truncating data tables...\n";
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$tables = [
    'payslips','payroll_workflow_log','payroll_deductions','payroll_allowances',
    'payroll_records','payroll_periods',
    'service_credit_dates','service_credits',
    'loan_payment_log','loan_documents','employee_loans',
    'leave_attachments','leave_transactions','leave_request_dates','leave_requests',
    'employee_leave_credits',
    'dtr_attachments','attendance_corrections','attendance_audit_log',
    'attendance_records','biometric_logs',
    'employee_documents','employee_credentials','employee_calendar_entries',
    'notifications','audit_logs',
    'employee_compensations',
    'users','employees',
];
foreach ($tables as $t) { $pdo->exec("TRUNCATE TABLE `$t`"); }
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "  ✓ " . count($tables) . " tables cleared\n\n";

// ─── REFERENCE IDs (existing reference tables — do not truncate) ──────────────
// dept:  5=Administration  2=Teaching  3=Non-Teaching  4=Academic Support  6=Maintenance
// pos:   7=Principal  8=AsstPrincipal  14=PayrollOfficer  15=Treasurer  16=Bookkeeper
//        17=SpecialAsst  2=SeniorTeacher  3=TeacherI  4=TeacherII  5=TeacherIII
//        9=GuidanceCounselor  10=Librarian  11=Registrar  12=Physician  13=Dentist
//        18=HeadCustodian  19=Custodian
// shift: 4=Full-Time(7:30-16:30,grace90min)  5=Part-Time(8:00-12:00)
// role:  1=Admin  2=Accounting  3=Employee  4=Principal
// SY:    1=2025-2026 (active)
// leave types:    2=Vacation  3=Sick  4=Maternity  5=Paternity  6=Emergency
// allowance types: 4=Rice  5=Laundry  6=AdditionalAssignmentPay
// deduction types: 8=SSS  9=PhilHealth  10=PagIBIG  11=WithholdingTax  12=PERAA
//                  13=SSSLoan  14=HDMFLoan  15=PERAAloan  16=RuralBankLoan
//                  19=HalfDaySettlement  20=ExcessLeaveSettlement
// loan types:      4=SSSSalaryLoan  5=PagIBIGMPF  6=PagIBIGHousing  7=PERAAloan  8=RuralBankLoan

// ─── 2. EMPLOYEES ─────────────────────────────────────────────────────────────
echo "[2] Seeding employees...\n";

// [emp_no, first, middle, last, sex, birth_date, civil_status, hire_date, type, dept, pos, shift, sss, ph, pagibig, tin, peraa, email]
$employees = [
 ['GEI-001','Ruby Ann',   'B.', 'Miguel',    'FEMALE','1975-03-15','MARRIED', '2005-06-01','FULL_TIME',5, 7, 4,'34-1234001-8','01-0001000-0','1001-0001-0001','101-001-001-000','PERAA-001','ruby.miguel@gei.edu.ph'],
 ['GEI-002','Maria Elena','R.', 'Santos',    'FEMALE','1978-07-22','MARRIED', '2008-06-01','FULL_TIME',5, 8, 4,'34-1234002-8','01-0002000-0','1001-0002-0002','101-002-002-000','PERAA-002','me.santos@gei.edu.ph'],
 ['GEI-003','Josephine',  'M.', 'dela Cruz', 'FEMALE','1985-09-10','SINGLE',  '2012-06-01','FULL_TIME',3,14, 4,'34-1234003-8','01-0003000-0','1001-0003-0003','101-003-003-000','PERAA-003','j.delacruz@gei.edu.ph'],
 ['GEI-004','Roberto',    'T.', 'Reyes',     'MALE',  '1972-11-05','MARRIED', '2003-06-01','FULL_TIME',3,15, 4,'34-1234004-8','01-0004000-0','1001-0004-0004','101-004-004-000','PERAA-004','r.reyes@gei.edu.ph'],
 ['GEI-005','Gloria',     'P.', 'Aquino',    'FEMALE','1983-04-18','MARRIED', '2010-06-01','FULL_TIME',3,16, 4,'34-1234005-8','01-0005000-0','1001-0005-0005','101-005-005-000','PERAA-005','g.aquino@gei.edu.ph'],
 ['GEI-006','Natividad',  'C.', 'Bautista',  'FEMALE','1980-01-30','MARRIED', '2009-06-01','FULL_TIME',3,17, 4,'34-1234006-8','01-0006000-0','1001-0006-0006','101-006-006-000','PERAA-006','n.bautista@gei.edu.ph'],
 ['GEI-007','Ana Kristina','F.','Flores',    'FEMALE','1986-06-25','SINGLE',  '2013-06-01','FULL_TIME',2, 2, 4,'34-1234007-8','01-0007000-0','1001-0007-0007','101-007-007-000','PERAA-007','ak.flores@gei.edu.ph'],
 ['GEI-008','Benjamin',   'L.', 'Cruz',      'MALE',  '1990-08-14','MARRIED', '2018-06-01','FULL_TIME',2, 3, 4,'34-1234008-8','01-0008000-0','1001-0008-0008','101-008-008-000','PERAA-008','b.cruz@gei.edu.ph'],
 ['GEI-009','Carmelita',  'R.', 'Domingo',   'FEMALE','1988-12-03','MARRIED', '2015-06-01','FULL_TIME',2, 4, 4,'34-1234009-8','01-0009000-0','1001-0009-0009','101-009-009-000','PERAA-009','c.domingo@gei.edu.ph'],
 ['GEI-010','Eduardo',    'A.', 'Garcia',    'MALE',  '1984-02-19','MARRIED', '2011-06-01','FULL_TIME',2, 5, 4,'34-1234010-8','01-0010000-0','1001-0010-0010','101-010-010-000','PERAA-010','e.garcia@gei.edu.ph'],
 ['GEI-011','Felicia',    'B.', 'Hernandez', 'FEMALE','1992-05-07','SINGLE',  '2020-06-01','FULL_TIME',2, 3, 4,'34-1234011-8','01-0011000-0','1001-0011-0011','101-011-011-000','PERAA-011','f.hernandez@gei.edu.ph'],
 ['GEI-012','Gerardo',    'M.', 'Ignacio',   'MALE',  '1987-10-28','SINGLE',  '2016-06-01','FULL_TIME',2, 4, 4,'34-1234012-8','01-0012000-0','1001-0012-0012','101-012-012-000','PERAA-012','g.ignacio@gei.edu.ph'],
 ['GEI-013','Herminia',   'L.', 'Javier',    'FEMALE','1981-09-14','MARRIED', '2007-06-01','FULL_TIME',2, 5, 4,'34-1234013-8','01-0013000-0','1001-0013-0013','101-013-013-000','PERAA-013','h.javier@gei.edu.ph'],
 ['GEI-014','Kevin',      'R.', 'Magno',     'MALE',  '1983-07-11','MARRIED', '2010-06-01','FULL_TIME',2, 2, 4,'34-1234014-8','01-0014000-0','1001-0014-0014','101-014-014-000','PERAA-014','k.magno@gei.edu.ph'],
 ['GEI-015','Patricia',   'A.', 'Rosales',   'FEMALE','1989-03-22','MARRIED', '2017-06-01','FULL_TIME',4, 9, 4,'34-1234015-8','01-0015000-0','1001-0015-0015','101-015-015-000','PERAA-015','p.rosales@gei.edu.ph'],
 ['GEI-016','Quirino',    'B.', 'Santos',    'MALE',  '1986-11-19','SINGLE',  '2014-06-01','FULL_TIME',4,10, 4,'34-1234016-8','01-0016000-0','1001-0016-0016','101-016-016-000','PERAA-016','q.santos@gei.edu.ph'],
 ['GEI-017','Rosalinda',  'C.', 'Torres',    'FEMALE','1984-08-05','MARRIED', '2012-06-01','FULL_TIME',4,11, 4,'34-1234017-8','01-0017000-0','1001-0017-0017','101-017-017-000','PERAA-017','r.torres@gei.edu.ph'],
 ['GEI-018','Nestor',     'T.', 'Pascual',   'MALE',  '1976-04-30','MARRIED', '2004-06-01','FULL_TIME',6,18, 4,'34-1234018-8','01-0018000-0','1001-0018-0018','101-018-018-000','PERAA-018','n.pascual@gei.edu.ph'],
 ['GEI-019','Olivia',     'R.', 'Quilala',   'FEMALE','1991-01-17','SINGLE',  '2019-06-01','FULL_TIME',6,19, 4,'34-1234019-8','01-0019000-0','1001-0019-0019','101-019-019-000','PERAA-019','o.quilala@gei.edu.ph'],
 ['GEI-020','Samuel',     'U.', 'Villanueva','MALE',  '1970-06-08','MARRIED', '2015-06-01','PART_TIME',4,12, 5,'34-1234020-8','01-0020000-0','1001-0020-0020','101-020-020-000',null,       'samuel.villanueva@gei.edu.ph'],
 ['GEI-021','Teresa',     'V.', 'Wagas',     'FEMALE','1975-12-25','MARRIED', '2015-06-01','PART_TIME',4,13, 5,'34-1234021-8','01-0021000-0','1001-0021-0021','101-021-021-000',null,       'teresa.wagas@gei.edu.ph'],
];

// monthly salary indexed 1-21
$monthly = [
    1=>35000,2=>30000,3=>22000,4=>28000,5=>18000,6=>20000,
    7=>25000,8=>18000,9=>20000,10=>22000,11=>18000,12=>20000,
    13=>22000,14=>25000,15=>20000,16=>18000,17=>18000,
    18=>16000,19=>14000,20=>8000,21=>8000,
];

$empSql = "INSERT INTO employees
    (employee_no,first_name,middle_name,last_name,sex,birth_date,civil_status,address,
     hire_date,employment_type,department_id,position_id,shift_id,
     sss_no,philhealth_no,pagibig_no,tin_no,peraa_no,email,
     emergency_contact_name,emergency_contact_relation,emergency_contact_number,employee_status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ACTIVE')";
$empStmt = $pdo->prepare($empSql);
$empId = []; // empId[1..21] = actual DB id

foreach ($employees as $i => $e) {
    $n = $i + 1;
    $empStmt->execute([
        $e[0],$e[1],$e[2],$e[3],$e[4],$e[5],$e[6],'La Paz, Tarlac',
        $e[7],$e[8],$e[9],$e[10],$e[11],
        $e[12],$e[13],$e[14],$e[15],$e[16],$e[17],
        'Emergency Contact','Relative','09XX-XXX-0000'
    ]);
    $empId[$n] = (int)$pdo->lastInsertId();
    echo "  ✓ [{$empId[$n]}] {$e[1]} {$e[3]} ({$e[8]})\n";
}

// ─── 3. USERS ─────────────────────────────────────────────────────────────────
echo "\n[3] Seeding users...\n";
$adminHash = password_hash('Admin@GEI2025',    PASSWORD_BCRYPT);
$empHash   = password_hash('Employee@GEI2025', PASSWORD_BCRYPT);

// [username, hash, role_id, emp_index (1-based)]
$users = [
    ['admin',        $adminHash, 1,  3],   // Josephine dela Cruz — payroll admin
    ['treasurer',    $adminHash, 1,  4],   // Roberto Reyes
    ['bookkeeper',   $empHash,   2,  5],   // Gloria Aquino
    ['r.miguel',     $adminHash, 4,  1],   // Ruby Ann Miguel — Principal
    ['m.santos',     $empHash,   3,  2],
    ['n.bautista',   $empHash,   3,  6],
    ['a.flores',     $empHash,   3,  7],
    ['b.cruz',       $empHash,   3,  8],
    ['c.domingo',    $empHash,   3,  9],
    ['e.garcia',     $empHash,   3, 10],
    ['f.hernandez',  $empHash,   3, 11],
    ['g.ignacio',    $empHash,   3, 12],
    ['h.javier',     $empHash,   3, 13],
    ['k.magno',      $empHash,   3, 14],
    ['p.rosales',    $empHash,   3, 15],
    ['q.santos',     $empHash,   3, 16],
    ['r.torres',     $empHash,   3, 17],
    ['n.pascual',    $empHash,   3, 18],
    ['o.quilala',    $empHash,   3, 19],
    ['s.villanueva', $empHash,   3, 20],
    ['t.wagas',      $empHash,   3, 21],
];
$uStmt = $pdo->prepare("INSERT INTO users (username,password_hash,role_id,employee_id,is_active,must_change_password) VALUES (?,?,?,?,1,0)");
$userId = []; // userId['admin'], userId['r.miguel'], etc.
foreach ($users as $u) {
    $uStmt->execute([$u[0],$u[1],$u[2],$empId[$u[3]]]);
    $userId[$u[0]] = (int)$pdo->lastInsertId();
    echo "  ✓ [{$userId[$u[0]]}] {$u[0]} → emp {$u[3]}\n";
}
$adminUid     = $userId['admin'];       // performs payroll generate/submit/release
$principalUid = $userId['r.miguel'];    // performs approval

// ─── 4. COMPENSATIONS ─────────────────────────────────────────────────────────
echo "\n[4] Seeding compensations...\n";
$cStmt = $pdo->prepare("INSERT INTO employee_compensations (employee_id,effective_date,monthly_salary,daily_rate,pay_basis,is_active) VALUES (?,?,?,?,?,1)");
foreach ($empId as $n => $id) {
    $m    = $monthly[$n];
    $type = $employees[$n-1][8]; // FULL_TIME / PART_TIME
    if ($type === 'PART_TIME') {
        // 4 sessions/month (once a week, half-day): daily = monthly/4
        $daily = round($m / 4, 2);
        $basis = 'DAILY';
    } else {
        $daily = round($m / 22, 2);
        $basis = 'MONTHLY';
    }
    $cStmt->execute([$id,'2025-06-01',$m,$daily,$basis]);
    echo "  ✓ {$employees[$n-1][1]} {$employees[$n-1][3]}: " . p($m) . "/mo  " . p($daily) . "/day\n";
}

// ─── 5. ATTENDANCE RECORDS ────────────────────────────────────────────────────
echo "\n[5] Seeding attendance records (Aug 2025 – Jan 2026)...\n";

// Holidays to skip (PH + GEI school holidays in period)
$phHolidays = [
    '2025-08-25', // National Heroes Day
    '2025-11-01', // All Saints Day
    '2025-11-02', // All Souls Day
    '2025-12-08', // Feast of Immaculate Conception
    '2025-12-25', // Christmas Day
    '2025-12-30', // Rizal Day
    '2025-12-31', // New Year's Eve
    '2026-01-01', // New Year's Day
];
$allWorkDays = getWorkingDays('2025-08-01','2026-01-31', $phHolidays);

// Specific exceptions: [empIndex => [date => status, ...]]
// LATE means arrived after 9AM → counted as HALF_DAY per GEI policy
$lateExceptions = [
    3  => ['2025-09-15','2025-10-10','2025-11-05','2025-12-03','2026-01-08'],
    8  => ['2025-09-22','2025-10-14'],
    12 => ['2025-08-20','2025-11-19'],
    16 => ['2025-10-15'],
];
// Leave dates (attendance_status = LEAVE), matched to leave_requests below
$leaveExceptions = [
    9  => ['2025-10-13','2025-10-14','2025-10-15'],           // Carmelita — vacation
    11 => ['2025-11-10','2025-11-11'],                        // Felicia — sick
    16 => ['2025-09-16'],                                     // Quirino — emergency
    8  => ['2025-10-06','2025-10-07','2025-10-08','2025-10-09','2025-10-10'], // Benjamin — paternity
    5  => ['2026-01-15','2026-01-16','2026-01-17','2026-01-20','2026-01-21', // Gloria — maternity
            '2026-01-22','2026-01-23','2026-01-24','2026-01-27','2026-01-28',
            '2026-01-29','2026-01-30','2026-01-31'],
];

$aStmt = $pdo->prepare("
    INSERT INTO attendance_records
    (employee_id,attendance_date,time_in,time_out,attendance_status,
     review_status,school_year_id,attendance_source,verification_status,late_minutes)
    VALUES (?,?,?,?,?,'REVIEWED',1,'MANUAL','VERIFIED',?)
");

$attCount = 0;
foreach ($empId as $n => $id) {
    foreach ($allWorkDays as $date) {
        $status     = 'PRESENT';
        $timeIn     = '07:28:00';
        $timeOut    = '16:32:00';
        $lateMin    = 0;

        // Check leave
        if (isset($leaveExceptions[$n]) && in_array($date, $leaveExceptions[$n])) {
            $status  = 'LEAVE';
            $timeIn  = null;
            $timeOut = null;
        }
        // Check late/half-day
        elseif (isset($lateExceptions[$n]) && in_array($date, $lateExceptions[$n])) {
            $status  = 'HALF_DAY';
            $timeIn  = '09:15:00';
            $timeOut = '16:32:00';
            $lateMin = 105;
        }
        // Part-time employees: different schedule
        elseif ($employees[$n-1][8] === 'PART_TIME') {
            $timeIn  = '08:02:00';
            $timeOut = '12:05:00';
        }

        $aStmt->execute([$id,$date,$timeIn,$timeOut,$status,$lateMin]);
        $attCount++;
    }
}
echo "  ✓ {$attCount} attendance records generated\n";

// ─── 6. LEAVE CREDITS ─────────────────────────────────────────────────────────
echo "\n[6] Seeding leave credits (SY 2025-2026)...\n";
// Full-time employees get credits per SY
// Vacation=30, Sick=15, Emergency=3 days allocated
$lcStmt = $pdo->prepare("INSERT INTO employee_leave_credits (employee_id,school_year_id,leave_type_id,allocated_days,used_days) VALUES (?,1,?,?,?)");
// usedDays per emp: [vacUsed, sickUsed, emergencyUsed]
$usedLeaves = [
    9  => [3.0, 0.0, 0.0],  // Carmelita — 3 vacation
    11 => [0.0, 2.0, 0.0],  // Felicia — 2 sick
    16 => [0.0, 0.0, 1.0],  // Quirino — 1 emergency
    8  => [3.0, 0.0, 0.0],  // Benjamin — 3 approved paternity mapped to vacation partial
    5  => [0.0, 0.0, 0.0],  // Gloria — maternity is statutory, separate
];
foreach ($empId as $n => $id) {
    $type = $employees[$n-1][8];
    if ($type === 'PART_TIME') continue; // no credits for part-time
    $u = $usedLeaves[$n] ?? [0.0, 0.0, 0.0];
    $lcStmt->execute([$id, 2, 30.00, $u[0]]); // Vacation
    $lcStmt->execute([$id, 3, 15.00, $u[1]]); // Sick
    $lcStmt->execute([$id, 6,  3.00, $u[2]]); // Emergency
    if ($n === 8) { // Benjamin — has paternity
        $lcStmt->execute([$id, 5, 7.00, 3.0]); // Paternity (partial)
    }
    if ($n === 5) { // Gloria — maternity
        $lcStmt->execute([$id, 4, 60.00, 13.0]); // Maternity (statutory 60 days, 13 used so far)
    }
}
echo "  ✓ Leave credits seeded for all full-time employees\n";

// ─── 7. LEAVE REQUESTS ────────────────────────────────────────────────────────
echo "\n[7] Seeding leave requests...\n";

$lrStmt = $pdo->prepare("
    INSERT INTO leave_requests
    (employee_id,leave_type_id,school_year_id,reason,start_date,end_date,total_days,
     status,workflow_status,approved_by,approved_at,is_attendance_recorded,recorded_at,recorded_by)
    VALUES (?,?,1,?,?,?,?,?,?,?,?,1,NOW(),?)
");
$ldStmt = $pdo->prepare("INSERT INTO leave_request_dates (leave_id,leave_date,status,actioned_by,actioned_at) VALUES (?,?,?,?,?)");

$leaves = [
    // [empN, leaveTypeId, reason, startDate, endDate, days, status, workflow, dates=>[date=>approved/rejected]]
    [9,  2,'Rest and recreation','2025-10-13','2025-10-15',3.0,'APPROVED','RECORDED',
        ['2025-10-13'=>'APPROVED','2025-10-14'=>'APPROVED','2025-10-15'=>'APPROVED']],
    [11, 3,'Fever and flu symptoms','2025-11-10','2025-11-11',2.0,'APPROVED','RECORDED',
        ['2025-11-10'=>'APPROVED','2025-11-11'=>'APPROVED']],
    [16, 6,'Family emergency — parent hospitalized','2025-09-16','2025-09-16',1.0,'APPROVED','RECORDED',
        ['2025-09-16'=>'APPROVED']],
    // Benjamin — paternity, partially approved (3 of 5 days)
    [8,  5,'Paternity leave — newborn child','2025-10-06','2025-10-10',5.0,'PARTIALLY_APPROVED','FORWARDED',
        ['2025-10-06'=>'APPROVED','2025-10-07'=>'APPROVED','2025-10-08'=>'APPROVED',
         '2025-10-09'=>'REJECTED','2025-10-10'=>'REJECTED']],
    // Gloria — maternity leave
    [5,  4,'Maternity leave','2026-01-15','2026-03-15',60.0,'APPROVED','RECORDED',
        ['2026-01-15'=>'APPROVED','2026-01-16'=>'APPROVED','2026-01-17'=>'APPROVED',
         '2026-01-20'=>'APPROVED','2026-01-21'=>'APPROVED','2026-01-22'=>'APPROVED',
         '2026-01-23'=>'APPROVED','2026-01-24'=>'APPROVED','2026-01-27'=>'APPROVED',
         '2026-01-28'=>'APPROVED','2026-01-29'=>'APPROVED','2026-01-30'=>'APPROVED',
         '2026-01-31'=>'APPROVED']],
    // Pending — Nestor
    [18, 2,'Family vacation','2026-02-10','2026-02-11',2.0,'PENDING','PENDING_REVIEW',
        ['2026-02-10'=>'PENDING','2026-02-11'=>'PENDING']],
    // Rejected — Gerardo (missed documentation)
    [12, 3,'Not feeling well','2025-09-08','2025-09-10',3.0,'REJECTED','FORWARDED',
        ['2025-09-08'=>'REJECTED','2025-09-09'=>'REJECTED','2025-09-10'=>'REJECTED']],
];

$leaveIds = [];
foreach ($leaves as $lv) {
    $approved = ($lv[7] === 'RECORDED') ? 1 : (($lv[7] === 'FORWARDED' && $lv[6] !== 'PENDING') ? 1 : 0);
    $approvedBy = $approved ? $principalUid : null;
    $approvedAt = $approved ? date('Y-m-d H:i:s', strtotime($lv[3] . ' -1 day') + 43200) : null;
    $lrStmt->execute([
        $empId[$lv[0]],$lv[1],$lv[2],$lv[3],$lv[4],$lv[5],
        $lv[6],$lv[7],$approvedBy,$approvedAt,
        ($lv[7]==='RECORDED' ? $adminUid : null)
    ]);
    $lvId = (int)$pdo->lastInsertId();
    $leaveIds[] = $lvId;

    foreach ($lv[8] as $date => $status) {
        $actioned   = ($status !== 'PENDING') ? $principalUid : null;
        $actionedAt = ($status !== 'PENDING') ? date('Y-m-d H:i:s', strtotime($date) + 50400) : null;
        $ldStmt->execute([$lvId, $date, $status, $actioned, $actionedAt]);
    }
    $empName = $employees[$lv[0]-1][1] . ' ' . $employees[$lv[0]-1][3];
    echo "  ✓ Leave [{$lv[6]}]: {$empName} — {$lv[3]} to {$lv[4]}\n";
}

// ─── 8. LOANS ─────────────────────────────────────────────────────────────────
echo "\n[8] Seeding employee loans...\n";

$loanStmt = $pdo->prepare("
    INSERT INTO employee_loans
    (employee_id,loan_type_id,account_reference,provider_name,total_amount,balance_amount,
     monthly_deduction,interest_rate,total_payable,reason,approved_by,approved_at,
     start_date,end_date,status,filed_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

$loans = [
    // [empN, loanTypeId, ref, provider, total, balance, monthlyDeduct, interestRate, totalPayable, reason, startDate, endDate, status]
    [1,  8,'RBL-2025-001','Rural Bank of La Paz', 120000,75000, 5000,  3.00,125000,'Home renovation',    '2025-08-01','2027-07-31','ACTIVE'],
    [4,  5,'MPF-2025-004','Pag-IBIG Fund',          45000,26250, 1875,  3.00, 49000,'Medical expenses',  '2025-03-01','2027-02-28','ACTIVE'],
    [7,  5,'MPF-2025-007','Pag-IBIG Fund',          60000,48000, 2000,  3.00, 64000,'Educational',       '2025-10-01','2028-09-30','ACTIVE'],
    [10, 7,'PRF-2025-010','PERAA',                   50000,37500, 2500,  0.00, 50000,'Emergency needs',  '2025-09-01','2027-08-31','ACTIVE'],
    [13, 4,'SSL-2026-013','SSS',                     36000,36000, 1500,  0.00, 36000,'Personal use',     '2026-01-16','2028-01-15','ACTIVE'],
    [14, 8,'RBL-2025-014','Rural Bank of La Paz',   80000,32000, 4000,  3.00, 85000,'Vehicle loan',      '2025-08-01','2027-07-31','ACTIVE'],
    // Nestor — completed Dec 2025 (₱10,000 over 5 months = ₱2,000/mo)
    [18, 4,'SSL-2025-018','SSS',                     10000,    0, 2000,  0.00, 10000,'Calamity loan',     '2025-08-01','2025-12-31','COMPLETED'],
    // Olivia — Pag-IBIG Housing, ongoing since 2020
    [19, 6,'HFL-2020-019','Pag-IBIG Fund',          300000,120000,2500,  6.00,380000,'Housing purchase',  '2020-01-01','2030-01-01','ACTIVE'],
    // Pending
    [2,  7,'PRF-2026-002','PERAA',                   80000, 80000, 3000,  0.00, 80000,'Investment',       '2026-03-01',null,        'PENDING'],
    [3,  8,'RBL-2026-003','Rural Bank of La Paz',    50000, 50000, 2500,  3.00, 53000,'Home improvement', '2026-03-01',null,        'PENDING'],
];
$loanId = []; // loanId[empN] = actual DB id (for active/completed loans)
foreach ($loans as $l) {
    $approvedBy = in_array($l[12], ['ACTIVE','COMPLETED']) ? $principalUid : null;
    $approvedAt = in_array($l[12], ['ACTIVE','COMPLETED']) ? date('Y-m-d H:i:s', strtotime($l[11] ?? $l[10]) - 86400*3) : null;
    $loanStmt->execute([
        $empId[$l[0]],$l[1],$l[2],$l[3],$l[4],$l[5],
        $l[6],$l[7],$l[8],$l[9],$approvedBy,$approvedAt,
        $l[10],$l[11],$l[12],'ADMIN'
    ]);
    $lid = (int)$pdo->lastInsertId();
    $loanId[$l[0]] = $lid; // keep first loan per emp for payroll deduction references
    $empName = $employees[$l[0]-1][1] . ' ' . $employees[$l[0]-1][3];
    echo "  ✓ Loan [{$l[12]}]: {$empName} — {$l[3]} " . p($l[4]) . "\n";
}

// ─── 9. SERVICE CREDITS ───────────────────────────────────────────────────────
echo "\n[9] Seeding service credits...\n";
$scStmt = $pdo->prepare("
    INSERT INTO service_credits
    (employee_id,work_date,days,equivalent_pay,is_approved,approved_at,
     status,approved_by,created_by,remarks)
    VALUES (?,?,?,?,?,?,?,?,?,?)
");
$scdStmt = $pdo->prepare("INSERT INTO service_credit_dates (service_credit_id,work_date,days,equivalent_pay,status,approved_by,approved_at) VALUES (?,?,?,?,?,?,?)");

$svcCredits = [
    // [empN, workDate, days, status, datesArray=[workDate=>days,...]]
    // RELEASED — included in April ACCRUED_PAY payroll
    [7,  '2025-12-20', 2.0, 'RELEASED', ['2025-12-20'=>1.0,'2025-12-21'=>1.0]],
    [10, '2025-12-20', 3.0, 'RELEASED', ['2025-12-20'=>1.0,'2025-12-21'=>1.0,'2025-12-22'=>1.0]],
    // APPROVED — board-resolved, pending next EOSY
    [1,  '2026-01-25', 2.0, 'APPROVED', ['2026-01-25'=>1.0,'2026-01-26'=>1.0]],
    [2,  '2026-01-25', 1.5, 'APPROVED', ['2026-01-25'=>1.0,'2026-01-26'=>0.5]],
    [14, '2025-11-30', 1.0, 'APPROVED', ['2025-11-30'=>1.0]],
    // PENDING — submitted, awaiting principal approval
    [3,  '2025-12-14', 1.0, 'PENDING',  ['2025-12-14'=>1.0]],
    [8,  '2025-10-11', 1.0, 'PENDING',  ['2025-10-11'=>1.0]],
];

$scIdMap = []; // used for payroll linkage (RELEASED credits)
foreach ($svcCredits as $sc) {
    $daily  = round($monthly[$sc[0]] / 22, 2);
    $eqPay  = round($daily * $sc[2], 2);
    $isApp  = in_array($sc[3], ['APPROVED','RELEASED']) ? 1 : 0;
    $appAt  = $isApp ? date('Y-m-d H:i:s', strtotime($sc[1]) + 86400*2 + 36000) : null;
    $appBy  = $isApp ? $principalUid : null;
    $scStmt->execute([
        $empId[$sc[0]],$sc[1],$sc[2],$eqPay,$isApp,$appAt,
        $sc[3],$appBy,$adminUid,'Board resolution service work'
    ]);
    $scId = (int)$pdo->lastInsertId();
    if ($sc[3] === 'RELEASED') $scIdMap[$sc[0]] = ['scId'=>$scId,'eqPay'=>$eqPay];

    foreach ($sc[4] as $d => $days) {
        $dPay  = round($daily * $days, 2);
        $dStat = ($sc[3] === 'PENDING') ? 'PENDING' : 'APPROVED';
        $scdStmt->execute([$scId,$d,$days,$dPay,$dStat,$appBy,$appAt]);
    }
    $empName = $employees[$sc[0]-1][1].' '.$employees[$sc[0]-1][3];
    echo "  ✓ Service Credit [{$sc[3]}]: {$empName} — {$sc[2]} day(s)\n";
}

// ─── 10. PAYROLL PERIODS ──────────────────────────────────────────────────────
echo "\n[10] Seeding payroll periods...\n";

$ppStmt = $pdo->prepare("INSERT INTO payroll_periods (payroll_number,period_name,period_type,pay_period_start,pay_period_end,pay_date,status) VALUES (?,?,?,?,?,?,?)");

$periods = [
    // [payrollNumber, name, type, start, end, payDate, status]
    ['PR-2025-08-001','August 1–15, 2025',      'REGULAR','2025-08-01','2025-08-15','2025-08-15','RELEASED'],
    ['PR-2025-08-002','August 16–31, 2025',     'REGULAR','2025-08-16','2025-08-31','2025-08-29','RELEASED'],
    ['PR-2025-09-001','September 1–15, 2025',   'REGULAR','2025-09-01','2025-09-15','2025-09-15','RELEASED'],
    ['PR-2025-09-002','September 16–30, 2025',  'REGULAR','2025-09-16','2025-09-30','2025-09-30','RELEASED'],
    ['PR-2025-10-001','October 1–15, 2025',     'REGULAR','2025-10-01','2025-10-15','2025-10-15','RELEASED'],
    ['PR-2025-10-002','October 16–31, 2025',    'REGULAR','2025-10-16','2025-10-31','2025-10-31','RELEASED'],
    ['PR-2025-11-001','November 1–15, 2025',    'REGULAR','2025-11-01','2025-11-15','2025-11-14','RELEASED'],
    ['PR-2025-11-002','November 16–30, 2025',   'REGULAR','2025-11-16','2025-11-30','2025-11-28','RELEASED'],
    ['PR-2025-12-001','December 1–15, 2025',    'REGULAR','2025-12-01','2025-12-15','2025-12-13','RELEASED'],
    ['PR-2025-12-002','December 16–31, 2025',   'REGULAR','2025-12-16','2025-12-31','2025-12-19','RELEASED'],
    ['PR-2026-01-001','January 1–15, 2026',     'REGULAR','2026-01-01','2026-01-15','2026-01-15','RELEASED'],
    ['PR-2026-01-002','January 16–31, 2026',    'REGULAR','2026-01-16','2026-01-31','2026-01-30','APPROVED'],
    ['PR-2026-02-001','February 1–15, 2026',    'REGULAR','2026-02-01','2026-02-15','2026-02-13','PROCESSING'],
    ['PR-2026-03-001','March 1–15, 2026',       'REGULAR','2026-03-01','2026-03-15','2026-03-14','OPEN'],
    ['PR-2026-04-001','EOSY Accrued Pay 2025–2026','ACCRUED_PAY','2026-04-01','2026-04-15','2026-04-18','RELEASED'],
];

$periodId = [];
foreach ($periods as $i => $p) {
    $ppStmt->execute($p);
    $periodId[$i+1] = (int)$pdo->lastInsertId();
    echo "  ✓ [{$periodId[$i+1]}] {$p[1]} [{$p[6]}]\n";
}

// Active loan deductions per period (empN => [loanTypeDeductionId, monthlyAmount])
// These are included in payroll_deductions for ACTIVE loans during that period
$activeLoanDeductions = [
    1  => [16, 5000.00],   // Rural Bank
    4  => [14, 1875.00],   // HDMF MPF
    7  => [14, 2000.00],   // HDMF MPF (starts Oct 2025 = period 5)
    10 => [15, 2500.00],   // PERAA (starts Sep 2025 = period 3)
    13 => [13, 1500.00],   // SSS Loan (starts Jan 16 = period 12)
    14 => [16, 4000.00],   // Rural Bank
    18 => [13, 2000.00],   // SSS Loan (completed after period 10)
    19 => [14, 2500.00],   // HDMF Housing (ongoing)
];
// period number from which loan starts (1-indexed)
$loanStartPeriod = [1=>1, 4=>1, 7=>5, 10=>3, 13=>12, 14=>1, 18=>1, 19=>1];
$loanEndPeriod   = [18=>10]; // SSS loan completed after Oct 16-31

// ─── 11. PAYROLL RECORDS, ALLOWANCES, DEDUCTIONS ─────────────────────────────
echo "\n[11] Seeding payroll records...\n";

$prStmt   = $pdo->prepare("
    INSERT INTO payroll_records
    (period_id,employee_id,basic_pay,gross_pay,total_allowances,total_deductions,
     net_pay,employer_sss_share,employer_philhealth_share,employer_pagibig_share,
     remarks,payroll_status,approved_by,approved_at,released_by,released_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
$paStmt   = $pdo->prepare("INSERT INTO payroll_allowances (payroll_id,allowance_type_id,amount) VALUES (?,?,?)");
$pdStmt   = $pdo->prepare("INSERT INTO payroll_deductions (payroll_id,deduction_type_id,loan_id,amount) VALUES (?,?,?,?)");
$payslipStmt = $pdo->prepare("INSERT INTO payslips (payroll_id,payslip_number,generated_at,received_by,date_received) VALUES (?,?,?,?,?)");

$totalRecords = 0;
$allPayrollIds = []; // allPayrollIds[periodIdx][empN] = payroll_id

foreach ($periods as $pi => $per) {
    $pIdx    = $pi + 1;   // 1-based period index
    $pId     = $periodId[$pIdx];
    $isAcc   = ($per[2] === 'ACCRUED_PAY');
    $status  = match($per[6]) {
        'RELEASED'   => 'RELEASED',
        'APPROVED'   => 'APPROVED',
        'PROCESSING' => 'DRAFT',
        'OPEN'       => 'DRAFT',
        default      => 'DRAFT',
    };
    $appBy  = in_array($per[6],['RELEASED','APPROVED']) ? $principalUid : null;
    $appAt  = $appBy ? date('Y-m-d H:i:s', strtotime($per[4]) + 86400*2 + 50400) : null;
    $relBy  = ($per[6]==='RELEASED') ? $adminUid : null;
    $relAt  = $relBy ? date('Y-m-d H:i:s', strtotime($per[4]) + 86400*3 + 54000) : null;

    foreach ($empId as $n => $eId) {
        $m = (float)$monthly[$n];
        // Contribution amounts (monthly basis)
        $sssEmpM = sssEmployee($m);
        $sssErM  = sssEmployer($m);
        $phEmpM  = phEmployee($m);
        $phErM   = $phEmpM;
        $pagibigM = pagibig($m);
        $pagibigErM = $pagibigM;
        $peraaMo = ($employees[$n-1][8] === 'FULL_TIME') ? 200.00 : 0.00;
        $wtM     = withholdingTax($m, $sssEmpM, $phEmpM, $pagibigM);

        // Per-period amounts (monthly / 2)
        $sssP     = round($sssEmpM / 2, 2);
        $sssErP   = round($sssErM / 2, 2);
        $phP      = round($phEmpM / 2, 2);
        $phErP    = round($phErM / 2, 2);
        $pagibigP = round($pagibigM / 2, 2);
        $pagibigErP = round($pagibigErM / 2, 2);
        $peraaP   = round($peraaMo / 2, 2);
        $wtP      = round($wtM / 2, 2);

        // Allowances per period
        $rice    = 1000.00;
        $laundry = 500.00;
        $assignPay = 0.00;
        if ($n === 1) $assignPay = 1500.00; // Principal
        if ($n === 2) $assignPay = 1000.00; // Asst Principal

        $basicPay = round($m / 2, 2);

        // ── ACCRUED PAY period logic ──────────────────────────────────────────
        $halfDayDeduct  = 0.00;
        $svcCreditAdd   = 0.00;
        if ($isAcc) {
            // Half-day settlements
            $halfDayIncidents = match($n) {
                3  => 5, // Josephine: 5 half-days
                8  => 2, // Benjamin: 2 half-days
                12 => 2, // Gerardo: 2 late/half-days
                16 => 1, // Quirino: 1 half-day
                default => 0,
            };
            if ($halfDayIncidents > 0) {
                $dailyRate     = round($m / 22, 2);
                $halfDayDeduct = round($dailyRate / 2 * $halfDayIncidents, 2);
            }
            // Service credits (RELEASED)
            if (isset($scIdMap[$n])) {
                $svcCreditAdd = $scIdMap[$n]['eqPay'];
            }
        }

        // ── Loan deductions ───────────────────────────────────────────────────
        $loanDeductAmount = 0.00;
        $loanDeductTypeId = null;
        $loanDbId = null;
        if (isset($activeLoanDeductions[$n])) {
            $startP = $loanStartPeriod[$n] ?? 1;
            $endP   = $loanEndPeriod[$n]  ?? 99;
            // Accrued pay period is period 15 — loans still apply
            if ($pIdx >= $startP && $pIdx <= $endP && !$isAcc) {
                [$loanDeductTypeId, $loanMonthly] = $activeLoanDeductions[$n];
                $loanDeductAmount = round($loanMonthly / 2, 2);
                // Find the loan_id in loanId map
                $loanDbId = $loanId[$n] ?? null;
            }
        }

        // ── Totals ────────────────────────────────────────────────────────────
        $totalAllowances  = $rice + $laundry + $assignPay + $svcCreditAdd;
        $govDeductions    = $sssP + $phP + $pagibigP + $peraaP + $wtP;
        $totalDeductions  = $govDeductions + $loanDeductAmount + $halfDayDeduct;
        $grossPay         = $basicPay + $totalAllowances;
        $netPay           = max(0, $grossPay - $totalDeductions);

        // ── Insert payroll record ─────────────────────────────────────────────
        $prStmt->execute([
            $pId, $eId, $basicPay, $grossPay, $totalAllowances, $totalDeductions,
            $netPay, $sssErP, $phErP, $pagibigErP,
            null, $status, $appBy, $appAt, $relBy, $relAt
        ]);
        $prId = (int)$pdo->lastInsertId();
        $allPayrollIds[$pIdx][$n] = $prId;
        $totalRecords++;

        // ── Payroll allowances ────────────────────────────────────────────────
        $paStmt->execute([$prId, 4, $rice]);    // Rice Subsidy
        $paStmt->execute([$prId, 5, $laundry]); // Laundry
        if ($assignPay > 0) $paStmt->execute([$prId, 6, $assignPay]);
        if ($svcCreditAdd > 0) $paStmt->execute([$prId, 6, $svcCreditAdd]);

        // ── Payroll deductions ────────────────────────────────────────────────
        $pdStmt->execute([$prId, 8,  null, $sssP]);
        $pdStmt->execute([$prId, 9,  null, $phP]);
        $pdStmt->execute([$prId, 10, null, $pagibigP]);
        if ($peraaP > 0)  $pdStmt->execute([$prId, 12, null, $peraaP]);
        if ($wtP > 0)     $pdStmt->execute([$prId, 11, null, $wtP]);
        if ($loanDeductAmount > 0) $pdStmt->execute([$prId, $loanDeductTypeId, $loanDbId, $loanDeductAmount]);
        if ($halfDayDeduct > 0)   $pdStmt->execute([$prId, 19, null, $halfDayDeduct]);
    }
    echo "  ✓ Period {$pIdx}: {$per[1]} [{$per[6]}]\n";
}
echo "  ✓ {$totalRecords} payroll records created\n";

// ─── Link RELEASED service credits to ACCRUED_PAY payroll records ─────────────
$accPeriodIdx = 15; // ACCRUED_PAY period index
if (!empty($scIdMap) && isset($periodId[$accPeriodIdx])) {
    $scLinkStmt = $pdo->prepare("UPDATE service_credits SET payroll_id=?, applied_to_payroll_at=NOW(), status='RELEASED' WHERE service_credit_id=?");
    foreach ($scIdMap as $empN => $sc) {
        if (isset($allPayrollIds[$accPeriodIdx][$empN])) {
            $scLinkStmt->execute([$allPayrollIds[$accPeriodIdx][$empN], $sc['scId']]);
        }
    }
    echo "  ✓ Service credits linked to ACCRUED_PAY payroll records\n";
}

// ─── 12. PAYROLL WORKFLOW LOGS ────────────────────────────────────────────────
echo "\n[12] Seeding payroll workflow logs...\n";
$wlStmt = $pdo->prepare("
    INSERT INTO payroll_workflow_log (period_id,event_type,performed_by,performer_name,gross_total,net_total,emp_count,created_at)
    VALUES (?,?,?,?,?,?,?,?)
");

// Compute per-period totals from allPayrollIds
$periodTotals = [];
foreach ($allPayrollIds as $pIdx => $empMap) {
    $gross = 0; $net = 0;
    foreach ($empMap as $prId) {
        $row = $pdo->query("SELECT gross_pay, net_pay FROM payroll_records WHERE payroll_id={$prId}")->fetch();
        $gross += (float)$row['gross_pay'];
        $net   += (float)$row['net_pay'];
    }
    $periodTotals[$pIdx] = ['gross'=>$gross,'net'=>$net,'count'=>count($empMap)];
}

$adminName     = 'Josephine M. dela Cruz';
$principalName = 'Ruby Ann B. Miguel';

foreach ($periods as $pi => $per) {
    $pIdx = $pi + 1;
    $pId  = $periodId[$pIdx];
    $g    = $periodTotals[$pIdx]['gross'];
    $n    = $periodTotals[$pIdx]['net'];
    $ec   = $periodTotals[$pIdx]['count'];
    $base = strtotime($per[4]); // pay_period_end as base

    // GENERATED (always)
    $wlStmt->execute([$pId,'GENERATED',$adminUid,$adminName,$g,$n,$ec,
        date('Y-m-d H:i:s',$base-86400*5+32400)]);

    // SUBMITTED (PROCESSING, APPROVED, RELEASED)
    if (in_array($per[6],['PROCESSING','APPROVED','RELEASED'])) {
        $wlStmt->execute([$pId,'SUBMITTED',$adminUid,$adminName,$g,$n,$ec,
            date('Y-m-d H:i:s',$base-86400*4+36000)]);
    }
    // APPROVED (APPROVED, RELEASED)
    if (in_array($per[6],['APPROVED','RELEASED'])) {
        $wlStmt->execute([$pId,'APPROVED',$principalUid,$principalName,$g,$n,$ec,
            date('Y-m-d H:i:s',$base-86400*3+50400)]);
    }
    // RELEASED
    if ($per[6] === 'RELEASED') {
        $wlStmt->execute([$pId,'RELEASED',$adminUid,$adminName,$g,$n,$ec,
            date('Y-m-d H:i:s',$base-86400*2+54000)]);
    }
}
echo "  ✓ Workflow logs created for all periods\n";

// ─── 13. PAYSLIPS ─────────────────────────────────────────────────────────────
echo "\n[13] Seeding payslips...\n";
$payslipCount = 0;
foreach ($periods as $pi => $per) {
    $pIdx = $pi + 1;
    if ($per[6] !== 'RELEASED') continue; // only RELEASED get payslips
    $ym = str_replace(['-','–',' '],'',$per[3]); // e.g. 20250801
    foreach ($allPayrollIds[$pIdx] as $empN => $prId) {
        $psNum = sprintf('PSL%s%02d%03d', substr($per[3],0,7), $pIdx, $empN);
        $psNum = str_replace('-','',$psNum);
        $genAt = date('Y-m-d H:i:s', strtotime($per[5]) + 39600); // pay date + 11:00 AM
        $empName = $employees[$empN-1][1].' '.$employees[$empN-1][3];
        $payslipStmt->execute([$prId, $psNum, $genAt, $empName, $per[5]]);
        $payslipCount++;
    }
}
echo "  ✓ {$payslipCount} payslips generated\n";

// ─── 14. ADD 2025 HOLIDAYS (INSERT IGNORE to avoid duplicates) ────────────────
echo "\n[14] Adding 2025 school-year holidays...\n";
$hStmt = $pdo->prepare("INSERT IGNORE INTO holidays (holiday_date,holiday_name,holiday_type,school_year_id) VALUES (?,?,?,1)");
$holidays2025 = [
    ['2025-08-25','National Heroes Day','REGULAR'],
    ['2025-11-01','All Saints Day','SPECIAL'],
    ['2025-11-02','All Souls Day','SPECIAL'],
    ['2025-12-08','Feast of the Immaculate Conception','SPECIAL'],
    ['2025-12-24','Christmas Eve','SCHOOL'],
    ['2025-12-25','Christmas Day','REGULAR'],
    ['2025-12-29','School Christmas Break','SCHOOL'],
    ['2025-12-30','Rizal Day','REGULAR'],
    ['2025-12-31','New Year\'s Eve','SCHOOL'],
];
foreach ($holidays2025 as $h) {
    $hStmt->execute($h);
}
echo "  ✓ 2025 holidays added\n";

// ─── SUMMARY REPORT ───────────────────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════\n";
echo "  SEED COMPLETE — Summary\n";
echo "═══════════════════════════════════════════════════\n";

$counts = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM employees)         AS employees,
      (SELECT COUNT(*) FROM users)             AS users,
      (SELECT COUNT(*) FROM attendance_records)AS attendance,
      (SELECT COUNT(*) FROM leave_requests)    AS leaves,
      (SELECT COUNT(*) FROM employee_loans)    AS loans,
      (SELECT COUNT(*) FROM service_credits)   AS svc_credits,
      (SELECT COUNT(*) FROM payroll_periods)   AS pp_periods,
      (SELECT COUNT(*) FROM payroll_records)   AS pr_records,
      (SELECT COUNT(*) FROM payslips)          AS payslips
")->fetch();

foreach ($counts as $k => $v) {
    echo sprintf("  %-22s %s\n", str_replace('_',' ',ucfirst($k)).':', $v);
}

echo "\n  LOGIN ACCOUNTS\n";
echo "  ─────────────────────────────────────────\n";
echo "  Username      Role          Password\n";
echo "  ─────────────────────────────────────────\n";
echo "  admin         Admin         Admin@GEI2025\n";
echo "  treasurer     Admin         Admin@GEI2025\n";
echo "  r.miguel      Principal     Admin@GEI2025\n";
echo "  bookkeeper    Accounting    Employee@GEI2025\n";
echo "  a.flores      Employee      Employee@GEI2025\n";
echo "  b.cruz        Employee      Employee@GEI2025\n";
echo "  (all others)  Employee      Employee@GEI2025\n";
echo "\n  ✓ Seed finished at " . date('H:i:s') . "\n\n";
