<?php
/**
 * database/seed_complete_demo.php
 * GEI Payroll System — Complete Demo Seed (Thesis Presentation 2026-05-29)
 *
 * Replaces seed_demo.php + seed_supplement_may2026.php in a single run.
 * Designed for a live demo showing:
 *   - Historical payroll Aug 2025 – Apr 2026 (all RELEASED)
 *   - May 1–15 APPROVED (before-state for live Rice Subsidy change)
 *   - May 16–31 OPEN (live demo — generate → submit → approve → release)
 *   - EOSY Accrued Pay (RELEASED) with full showcase:
 *       EMP-2025-010 Eduardo A. Garcia (₱22,000/mo, ₱1,000/day)
 *       + 8 service credit days = ₱8,000
 *       - 4 half-day settlements = ₱2,000
 *       - 3 excess leave days = ₱3,000
 *       = ₱3,000 net EOSY pay
 *
 * Passwords:
 *   admin, treasurer, r.miguel → Admin@GEI2025
 *   all employees               → Employee@GEI2025
 *
 * Run:  php database/seed_complete_demo.php
 */

set_time_limit(600);
require_once __DIR__ . '/../config/config.php';

// ─── HELPERS ──────────────────────────────────────────────────────────────────

function sssEmployee(float $m): float {
    $msc = min(35000, max(5000, round($m / 500) * 500));
    return round($msc * 0.05, 2);
}
function sssEmployer(float $m): float {
    $msc = min(35000, max(5000, round($m / 500) * 500));
    $ec  = $msc >= 15000 ? 30.00 : 10.00;
    return round($msc * 0.10 + $ec, 2);
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
echo "  GEI Payroll System — Complete Demo Seed\n";
echo "  Thesis Presentation: May 29, 2026\n";
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

// ─── REFERENCE IDs ────────────────────────────────────────────────────────────
// dept:  5=Administration  2=Teaching  3=Non-Teaching  4=Academic Support  6=Maintenance
// pos:   7=Principal  8=AsstPrincipal  14=PayrollOfficer  15=Treasurer  16=Bookkeeper
//        17=SpecialAsst  2=SeniorTeacher  3=TeacherI  4=TeacherII  5=TeacherIII
//        9=GuidanceCounselor  10=Librarian  11=Registrar  12=Physician  13=Dentist
//        18=HeadCustodian  19=Custodian
// shift: 4=Full-Time  5=Part-Time
// role:  1=Admin  2=Accounting  3=Employee  4=Principal
// leave: 2=Vacation  3=Sick  4=Maternity  5=Paternity  6=Emergency
// allow: 4=Rice  5=Laundry  6=AdditionalAssignmentPay(service_credit_target)
// deduct:8=SSS  9=PhilHealth  10=PagIBIG  11=WithholdingTax  12=PERAA
//        13=SSSLoan  14=HDMFLoan  15=PERAAloan  16=RuralBankLoan
//        19=HalfDaySettlement  21=ExcessLeaveSettlement(dynamic)  22=EOSYBalanceRecovery(dynamic)
// loan:  4=SSSSalaryLoan  5=PagIBIGMPF  6=PagIBIGHousing  7=PERAAloan  8=RuralBankLoan

// Resolve school year IDs
$sy2526 = (int)$pdo->query("SELECT school_year_id FROM school_years WHERE year_name='2025-2026' LIMIT 1")->fetchColumn();
$sy2627 = (int)$pdo->query("SELECT school_year_id FROM school_years WHERE year_name='2026-2027' LIMIT 1")->fetchColumn();
if (!$sy2526 || !$sy2627) {
    echo "  ✗ Missing school year records. Ensure school_years table has 2025-2026 and 2026-2027.\n";
    exit(1);
}
echo "  ✓ School years: SY 2025-2026 id={$sy2526}, SY 2026-2027 id={$sy2627}\n\n";

// ─── 2. EMPLOYEES ─────────────────────────────────────────────────────────────
echo "[2] Seeding employees...\n";

// [emp_no, first, middle, last, sex, birth_date, civil_status, hire_date, type, dept, pos, shift, sss, ph, pagibig, tin, peraa, email]
// Employee numbers use EMP-YYYY-NNN format matching the system's auto-generation
$employees = [
 ['EMP-2025-001','Ruby Ann',    'B.','Miguel',    'FEMALE','1975-03-15','MARRIED', '2005-06-01','FULL_TIME',5, 7, 4,'34-1234001-8','01-0001000-0','1001-0001-0001','101-001-001-000','PERAA-001','ruby.miguel@gei.edu.ph'],
 ['EMP-2025-002','Maria Elena', 'R.','Santos',    'FEMALE','1978-07-22','MARRIED', '2008-06-01','FULL_TIME',5, 8, 4,'34-1234002-8','01-0002000-0','1001-0002-0002','101-002-002-000','PERAA-002','me.santos@gei.edu.ph'],
 ['EMP-2025-003','Josephine',   'M.','dela Cruz', 'FEMALE','1985-09-10','SINGLE',  '2012-06-01','FULL_TIME',3,14, 4,'34-1234003-8','01-0003000-0','1001-0003-0003','101-003-003-000','PERAA-003','j.delacruz@gei.edu.ph'],
 ['EMP-2025-004','Roberto',     'T.','Reyes',     'MALE',  '1972-11-05','MARRIED', '2003-06-01','FULL_TIME',3,15, 4,'34-1234004-8','01-0004000-0','1001-0004-0004','101-004-004-000','PERAA-004','r.reyes@gei.edu.ph'],
 ['EMP-2025-005','Gloria',      'P.','Aquino',    'FEMALE','1983-04-18','MARRIED', '2010-06-01','FULL_TIME',3,16, 4,'34-1234005-8','01-0005000-0','1001-0005-0005','101-005-005-000','PERAA-005','g.aquino@gei.edu.ph'],
 ['EMP-2025-006','Natividad',   'C.','Bautista',  'FEMALE','1980-01-30','MARRIED', '2009-06-01','FULL_TIME',3,17, 4,'34-1234006-8','01-0006000-0','1001-0006-0006','101-006-006-000','PERAA-006','n.bautista@gei.edu.ph'],
 ['EMP-2025-007','Ana Kristina','F.','Flores',    'FEMALE','1986-06-25','SINGLE',  '2013-06-01','FULL_TIME',2, 2, 4,'34-1234007-8','01-0007000-0','1001-0007-0007','101-007-007-000','PERAA-007','ak.flores@gei.edu.ph'],
 ['EMP-2025-008','Benjamin',    'L.','Cruz',      'MALE',  '1990-08-14','MARRIED', '2018-06-01','FULL_TIME',2, 3, 4,'34-1234008-8','01-0008000-0','1001-0008-0008','101-008-008-000','PERAA-008','b.cruz@gei.edu.ph'],
 ['EMP-2025-009','Carmelita',   'R.','Domingo',   'FEMALE','1988-12-03','MARRIED', '2015-06-01','FULL_TIME',2, 4, 4,'34-1234009-8','01-0009000-0','1001-0009-0009','101-009-009-000','PERAA-009','c.domingo@gei.edu.ph'],
 ['EMP-2025-010','Eduardo',     'A.','Garcia',    'MALE',  '1984-02-19','MARRIED', '2011-06-01','FULL_TIME',2, 5, 4,'34-1234010-8','01-0010000-0','1001-0010-0010','101-010-010-000','PERAA-010','e.garcia@gei.edu.ph'],
 ['EMP-2025-011','Felicia',     'B.','Hernandez', 'FEMALE','1992-05-07','SINGLE',  '2020-06-01','FULL_TIME',2, 3, 4,'34-1234011-8','01-0011000-0','1001-0011-0011','101-011-011-000','PERAA-011','f.hernandez@gei.edu.ph'],
 ['EMP-2025-012','Gerardo',     'M.','Ignacio',   'MALE',  '1987-10-28','SINGLE',  '2016-06-01','FULL_TIME',2, 4, 4,'34-1234012-8','01-0012000-0','1001-0012-0012','101-012-012-000','PERAA-012','g.ignacio@gei.edu.ph'],
 ['EMP-2025-013','Herminia',    'L.','Javier',    'FEMALE','1981-09-14','MARRIED', '2007-06-01','FULL_TIME',2, 5, 4,'34-1234013-8','01-0013000-0','1001-0013-0013','101-013-013-000','PERAA-013','h.javier@gei.edu.ph'],
 ['EMP-2025-014','Kevin',       'R.','Magno',     'MALE',  '1983-07-11','MARRIED', '2010-06-01','FULL_TIME',2, 2, 4,'34-1234014-8','01-0014000-0','1001-0014-0014','101-014-014-000','PERAA-014','k.magno@gei.edu.ph'],
 ['EMP-2025-015','Patricia',    'A.','Rosales',   'FEMALE','1989-03-22','MARRIED', '2017-06-01','FULL_TIME',4, 9, 4,'34-1234015-8','01-0015000-0','1001-0015-0015','101-015-015-000','PERAA-015','p.rosales@gei.edu.ph'],
 ['EMP-2025-016','Quirino',     'B.','Santos',    'MALE',  '1986-11-19','SINGLE',  '2014-06-01','FULL_TIME',4,10, 4,'34-1234016-8','01-0016000-0','1001-0016-0016','101-016-016-000','PERAA-016','q.santos@gei.edu.ph'],
 ['EMP-2025-017','Rosalinda',   'C.','Torres',    'FEMALE','1984-08-05','MARRIED', '2012-06-01','FULL_TIME',4,11, 4,'34-1234017-8','01-0017000-0','1001-0017-0017','101-017-017-000','PERAA-017','r.torres@gei.edu.ph'],
 ['EMP-2025-018','Nestor',      'T.','Pascual',   'MALE',  '1976-04-30','MARRIED', '2004-06-01','FULL_TIME',6,18, 4,'34-1234018-8','01-0018000-0','1001-0018-0018','101-018-018-000','PERAA-018','n.pascual@gei.edu.ph'],
 ['EMP-2025-019','Olivia',      'R.','Quilala',   'FEMALE','1991-01-17','SINGLE',  '2019-06-01','FULL_TIME',6,19, 4,'34-1234019-8','01-0019000-0','1001-0019-0019','101-019-019-000','PERAA-019','o.quilala@gei.edu.ph'],
 ['EMP-2025-020','Samuel',      'U.','Villanueva','MALE',  '1970-06-08','MARRIED', '2015-06-01','PART_TIME',4,12, 5,'34-1234020-8','01-0020000-0','1001-0020-0020','101-020-020-000',null,        'samuel.villanueva@gei.edu.ph'],
 ['EMP-2025-021','Teresa',      'V.','Wagas',     'FEMALE','1975-12-25','MARRIED', '2015-06-01','PART_TIME',4,13, 5,'34-1234021-8','01-0021000-0','1001-0021-0021','101-021-021-000',null,        'teresa.wagas@gei.edu.ph'],
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
$empId = []; // 1-based index → DB id

foreach ($employees as $i => $e) {
    $n = $i + 1;
    $empStmt->execute([
        $e[0],$e[1],$e[2],$e[3],$e[4],$e[5],$e[6],'La Paz, Tarlac',
        $e[7],$e[8],$e[9],$e[10],$e[11],
        $e[12],$e[13],$e[14],$e[15],$e[16],$e[17],
        'Emergency Contact','Relative','09XX-XXX-0000'
    ]);
    $empId[$n] = (int)$pdo->lastInsertId();
    echo "  ✓ [{$empId[$n]}] {$e[1]} {$e[3]} ({$e[0]})\n";
}

// ─── 3. USERS ─────────────────────────────────────────────────────────────────
echo "\n[3] Seeding users...\n";
$adminHash = password_hash('Admin@GEI2025',    PASSWORD_BCRYPT);
$empHash   = password_hash('Employee@GEI2025', PASSWORD_BCRYPT);

$users = [
    ['admin',        $adminHash, 1,  3],  // Josephine dela Cruz — payroll admin
    ['treasurer',    $adminHash, 1,  4],  // Roberto Reyes
    ['bookkeeper',   $empHash,   2,  5],  // Gloria Aquino
    ['r.miguel',     $adminHash, 4,  1],  // Ruby Ann Miguel — Principal
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
$userId = [];
foreach ($users as $u) {
    $uStmt->execute([$u[0],$u[1],$u[2],$empId[$u[3]]]);
    $userId[$u[0]] = (int)$pdo->lastInsertId();
    echo "  ✓ [{$userId[$u[0]]}] {$u[0]}\n";
}
$adminUid     = $userId['admin'];
$principalUid = $userId['r.miguel'];
$adminName     = 'Josephine M. dela Cruz';
$principalName = 'Ruby Ann B. Miguel';

// ─── 4. COMPENSATIONS ─────────────────────────────────────────────────────────
echo "\n[4] Seeding compensations...\n";
$cStmt = $pdo->prepare("INSERT INTO employee_compensations (employee_id,effective_date,monthly_salary,daily_rate,pay_basis,is_active) VALUES (?,?,?,?,?,1)");
foreach ($empId as $n => $id) {
    $m    = $monthly[$n];
    $type = $employees[$n-1][8];
    if ($type === 'PART_TIME') {
        $daily = round($m / 4, 2);
        $basis = 'DAILY';
    } else {
        $daily = round($m / 22, 2);
        $basis = 'MONTHLY';
    }
    $cStmt->execute([$id,'2025-06-01',$m,$daily,$basis]);
    echo "  ✓ {$employees[$n-1][1]} {$employees[$n-1][3]}: " . p($m) . "/mo  " . p($daily) . "/day\n";
}

// ─── 5. HOLIDAYS ──────────────────────────────────────────────────────────────
echo "\n[5] Seeding holidays...\n";
$hStmt = $pdo->prepare("INSERT IGNORE INTO holidays (holiday_date,holiday_name,holiday_type,school_year_id) VALUES (?,?,?,?)");

$holidays2526 = [
    ['2025-08-25','National Heroes Day',                  'REGULAR', $sy2526],
    ['2025-11-01','All Saints Day',                       'SPECIAL', $sy2526],
    ['2025-11-02','All Souls Day',                        'SPECIAL', $sy2526],
    ['2025-11-30','Bonifacio Day',                        'REGULAR', $sy2526],
    ['2025-12-08','Feast of the Immaculate Conception',   'SPECIAL', $sy2526],
    ['2025-12-24','Christmas Eve',                        'SCHOOL',  $sy2526],
    ['2025-12-25','Christmas Day',                        'REGULAR', $sy2526],
    ['2025-12-29','School Christmas Break',               'SCHOOL',  $sy2526],
    ['2025-12-30','Rizal Day',                            'REGULAR', $sy2526],
    ['2025-12-31','New Year\'s Eve',                      'SCHOOL',  $sy2526],
    ['2026-01-01','New Year\'s Day',                      'REGULAR', $sy2526],
    ['2026-02-25','EDSA People Power Anniversary',        'SPECIAL', $sy2526],
    ['2026-04-02','Maundy Thursday',                      'REGULAR', $sy2526],
    ['2026-04-03','Good Friday',                          'REGULAR', $sy2526],
    ['2026-04-04','Black Saturday',                       'SPECIAL', $sy2526],
    ['2026-04-09','Araw ng Kagitingan',                   'REGULAR', $sy2526],
    ['2026-05-01','Labor Day',                            'REGULAR', $sy2526],
];
$holidays2627 = [
    ['2026-06-12','Independence Day',                     'REGULAR', $sy2627],
    ['2026-08-21','Ninoy Aquino Day',                     'SPECIAL', $sy2627],
    ['2026-08-31','National Heroes Day',                  'REGULAR', $sy2627],
    ['2026-10-31','Halloween',                            'SPECIAL', $sy2627],
    ['2026-11-01','All Saints Day',                       'REGULAR', $sy2627],
    ['2026-11-02','All Souls Day',                        'SPECIAL', $sy2627],
    ['2026-11-30','Bonifacio Day',                        'REGULAR', $sy2627],
    ['2026-12-08','Feast of Immaculate Conception',       'SPECIAL', $sy2627],
    ['2026-12-24','Christmas Eve',                        'SCHOOL',  $sy2627],
    ['2026-12-25','Christmas Day',                        'REGULAR', $sy2627],
    ['2026-12-29','School Christmas Break',               'SCHOOL',  $sy2627],
    ['2026-12-30','Rizal Day',                            'REGULAR', $sy2627],
    ['2026-12-31','New Year\'s Eve',                      'SCHOOL',  $sy2627],
    ['2027-01-01','New Year\'s Day',                      'REGULAR', $sy2627],
    ['2027-02-25','EDSA Anniversary',                     'SPECIAL', $sy2627],
    ['2027-04-01','Maundy Thursday',                      'REGULAR', $sy2627],
    ['2027-04-02','Good Friday',                          'REGULAR', $sy2627],
    ['2027-04-09','Araw ng Kagitingan',                   'REGULAR', $sy2627],
    ['2027-05-01','Labor Day',                            'REGULAR', $sy2627],
];
foreach (array_merge($holidays2526, $holidays2627) as $h) { $hStmt->execute($h); }
echo "  ✓ " . count($holidays2526) . " SY 2025-2026 holidays added\n";
echo "  ✓ " . count($holidays2627) . " SY 2026-2027 holidays added\n";

$allHolidayDates = array_merge(array_column($holidays2526, 0), array_column($holidays2627, 0));

// ─── ATTENDANCE TIME BASE (per employee) ──────────────────────────────────────
// [time_in_hour, time_in_min, time_out_hour, time_out_min]
// Variation: time_in seconds = (day_of_month % 4) * 15 → 00,15,30,45 s
//            time_out seconds = (day_of_month % 3) * 20 → 00,20,40 s
$empTimeBase = [
    1  => [7,20,16,35],  // Ruby Ann      — early, dedicated
    2  => [7,22,16,30],  // Maria Elena   — punctual
    3  => [7,35,16,45],  // Josephine     — chronically close to 08:00 cutoff
    4  => [7,18,16,30],  // Roberto       — very early
    5  => [7,30,16,35],  // Gloria        — average
    6  => [7,25,16,32],  // Natividad     — slightly early
    7  => [7,28,16,33],  // Ana Kristina  — average
    8  => [7,32,16,38],  // Benjamin      — slightly late
    9  => [7,24,16,31],  // Carmelita     — slightly early
    10 => [7,27,16,30],  // Eduardo       — average
    11 => [7,33,16,40],  // Felicia       — slightly late
    12 => [7,29,16,34],  // Gerardo       — average
    13 => [7,26,16,32],  // Herminia      — slightly early
    14 => [7,21,16,35],  // Kevin         — early
    15 => [7,19,16,30],  // Patricia      — very early
    16 => [7,31,16,36],  // Quirino       — slightly late
    17 => [7,23,16,31],  // Rosalinda     — slightly early
    18 => [7,15,16,28],  // Nestor        — maintenance, earliest
    19 => [7,17,16,26],  // Olivia        — early
    20 => [8, 3,12, 7],  // Samuel        — part-time physician
    21 => [8, 1,12, 5],  // Teresa        — part-time dentist
];

// HALF_DAY specific time-in per employee per date [time_in_str, late_minutes_past_grace]
// Grace period ends at 09:00; late_minutes = minutes past 09:00
$halfDayTimes = [
    3  => [
        '2025-09-15' => ['09:08:00',  8],
        '2025-10-10' => ['09:12:00', 12],
        '2025-11-05' => ['09:17:00', 17],
        '2025-12-03' => ['09:05:00',  5],
        '2026-01-08' => ['09:20:00', 20],
        '2026-02-10' => ['09:08:00',  8],
        '2026-03-05' => ['09:14:00', 14],
    ],
    7  => [
        '2026-03-18' => ['09:07:00',  7],
    ],
    8  => [
        '2025-09-22' => ['09:15:00', 15],
        '2025-10-14' => ['09:10:00', 10],
    ],
    10 => [
        '2026-01-09' => ['09:12:00', 12],
        '2026-01-12' => ['09:08:00',  8],
        '2026-01-13' => ['09:18:00', 18],
        '2026-01-14' => ['09:06:00',  6],
    ],
    12 => [
        '2025-08-20' => ['09:10:00', 10],
        '2025-11-19' => ['09:15:00', 15],
    ],
    11 => [
        '2026-05-28' => ['09:13:00', 13],   // Felicia — 13 min late
    ],
    14 => [
        '2026-05-28' => ['09:09:00',  9],   // Kevin — 9 min late
    ],
    16 => [
        '2025-10-15' => ['09:20:00', 20],
        '2026-04-08' => ['09:17:00', 17],
    ],
];

// ─── 6. ATTENDANCE — SY 2025-2026 (Aug 1 – Jan 31) ───────────────────────────
echo "\n[6] Seeding attendance (Aug 2025 – Jan 2026)...\n";

$phHolidays1 = [
    '2025-08-25','2025-11-01','2025-11-02','2025-11-30',
    '2025-12-08','2025-12-24','2025-12-25','2025-12-29','2025-12-30','2025-12-31',
    '2026-01-01',
];
$workDays1 = getWorkingDays('2025-08-01','2026-01-31', $phHolidays1);

// HALF_DAY dates per employee (all within Aug 2025 – Jan 2026)
// Eduardo's 4 HALF_DAY dates are in Jan 2026 (safe from his leave/holiday dates)
$lateEx1 = [
    3  => ['2025-09-15','2025-10-10','2025-11-05','2025-12-03','2026-01-08'],
    8  => ['2025-09-22','2025-10-14'],
    10 => ['2026-01-09','2026-01-12','2026-01-13','2026-01-14'],  // Eduardo — 4 EOSY showcase half-days
    12 => ['2025-08-20','2025-11-19'],
    16 => ['2025-10-15'],
];
// Unexcused ABSENT dates (not on leave)
$absentEx1 = [
    12 => ['2025-09-08','2025-09-09','2025-09-10'],  // Gerardo — rejected sick leave = unexcused
];
// LEAVE dates
$leaveEx1 = [
    9  => ['2025-10-13','2025-10-14','2025-10-15'],
    11 => ['2025-11-10','2025-11-11'],
    16 => ['2025-09-16'],
    8  => ['2025-10-06','2025-10-07','2025-10-08','2025-10-09','2025-10-10'],
    5  => ['2026-01-15','2026-01-16','2026-01-17','2026-01-20','2026-01-21',
           '2026-01-22','2026-01-23','2026-01-24','2026-01-27','2026-01-28',
           '2026-01-29','2026-01-30','2026-01-31'],
    10 => [
        // Sick leave: Aug/Sep 2025 (15 days)
        '2025-08-04','2025-08-05','2025-08-06','2025-08-07','2025-08-08',
        '2025-09-01','2025-09-02','2025-09-03','2025-09-04','2025-09-05',
        '2025-09-08','2025-09-09','2025-09-10','2025-09-11','2025-09-12',
        // Vacation leave: Oct/Nov 2025 (15 days)
        '2025-10-20','2025-10-21','2025-10-22','2025-10-23','2025-10-24',
        '2025-10-27','2025-10-28','2025-10-29','2025-10-30','2025-10-31',
        '2025-11-03','2025-11-04','2025-11-05','2025-11-06','2025-11-07',
        // Emergency leave: Dec 2025 (3 days)
        '2025-12-01','2025-12-02','2025-12-04',
    ],
];

$aStmt1 = $pdo->prepare("
    INSERT INTO attendance_records
    (employee_id,attendance_date,time_in,time_out,attendance_status,
     review_status,school_year_id,attendance_source,verification_status,late_minutes)
    VALUES (?,?,?,?,?,'REVIEWED',{$sy2526},'MANUAL','VERIFIED',?)
");

$attCount1 = 0;
foreach ($empId as $n => $id) {
    foreach ($workDays1 as $date) {
        $isPartTime = ($employees[$n-1][8] === 'PART_TIME');

        // Part-time: Samuel (20) = Fridays only, Teresa (21) = Thursdays only
        if ($n === 20 && date('N', strtotime($date)) !== '5') continue;
        if ($n === 21 && date('N', strtotime($date)) !== '4') continue;

        [$ih,$im,$oh,$om] = $empTimeBase[$n];
        $dayNum  = (int)substr($date, 8);
        $inSecs  = ($dayNum % 4) * 15;   // 0,15,30,45 seconds
        $outSecs = ($dayNum % 3) * 20;   // 0,20,40 seconds

        $timeIn  = sprintf('%02d:%02d:%02d', $ih, $im, $inSecs);
        $timeOut = sprintf('%02d:%02d:%02d', $oh, $om, $outSecs);
        $status  = 'PRESENT';
        $lateMin = 0;

        if (isset($leaveEx1[$n]) && in_array($date, $leaveEx1[$n])) {
            $status  = 'LEAVE';
            $timeIn  = null; $timeOut = null;
        } elseif (isset($absentEx1[$n]) && in_array($date, $absentEx1[$n])) {
            $status  = 'ABSENT';
            $timeIn  = null; $timeOut = null;
        } elseif (isset($lateEx1[$n]) && in_array($date, $lateEx1[$n])) {
            $status  = 'HALF_DAY';
            $hdInfo  = $halfDayTimes[$n][$date] ?? ['09:10:00', 10];
            $timeIn  = $hdInfo[0];
            $lateMin = $hdInfo[1];
            $timeOut = sprintf('%02d:%02d:%02d', $oh, $om, $outSecs);
        }

        $aStmt1->execute([$id,$date,$timeIn,$timeOut,$status,$lateMin]);
        $attCount1++;
    }
}
echo "  ✓ {$attCount1} attendance records (Aug 2025 – Jan 2026)\n";
echo "  ✓ Part-time: Samuel on Fridays only, Teresa on Thursdays only\n";

// ─── 7. ATTENDANCE — SY 2026-2027 (Feb 2 – May 27, 2026) ─────────────────────
echo "\n[7] Seeding attendance (Feb–May 27, 2026)...\n";

$phHolidays2 = ['2026-02-25','2026-04-02','2026-04-03','2026-04-04','2026-04-09','2026-05-01'];
$workDays2   = getWorkingDays('2026-02-02','2026-05-28', $phHolidays2);

$lateEx2 = [
    3  => ['2026-02-10','2026-03-05'],
    7  => ['2026-03-18'],
    11 => ['2026-05-28'],   // Felicia — arrives late
    14 => ['2026-05-28'],   // Kevin — arrives late
    16 => ['2026-04-08'],
];
$absentEx2 = [
    16 => ['2026-04-20'],            // Quirino — unexcused absence
    11 => ['2026-02-17'],            // Felicia — unexcused absence
    19 => ['2026-04-22'],            // Olivia — disputed absence (attendance correction demo)
    6  => ['2026-05-28'],            // Natividad — unexcused absence
    17 => ['2026-05-28'],            // Rosalinda — unexcused absence
];
$leaveEx2 = [
    // Gloria — maternity leave continues Feb 2 – Mar 13 (Mar 15 is Saturday)
    5  => ['2026-02-02','2026-02-03','2026-02-04','2026-02-05','2026-02-06',
           '2026-02-09','2026-02-10','2026-02-11','2026-02-12','2026-02-13',
           '2026-02-16','2026-02-17','2026-02-18','2026-02-19','2026-02-20',
           '2026-02-23','2026-02-24','2026-02-26','2026-02-27',  // Feb 25 = EDSA holiday
           '2026-03-02','2026-03-03','2026-03-04','2026-03-05','2026-03-06',
           '2026-03-09','2026-03-10','2026-03-11','2026-03-12','2026-03-13'],
    14 => ['2026-05-12','2026-05-13'],
    9  => ['2026-05-19','2026-05-20'],
];

$aStmt2 = $pdo->prepare("
    INSERT INTO attendance_records
    (employee_id,attendance_date,time_in,time_out,attendance_status,
     review_status,school_year_id,attendance_source,verification_status,late_minutes)
    VALUES (?,?,?,?,?,'REVIEWED',{$sy2627},'MANUAL','VERIFIED',?)
");

$attCount2 = 0;
foreach ($empId as $n => $id) {
    foreach ($workDays2 as $date) {
        // Part-time: Samuel (20) = Fridays only, Teresa (21) = Thursdays only
        if ($n === 20 && date('N', strtotime($date)) !== '5') continue;
        if ($n === 21 && date('N', strtotime($date)) !== '4') continue;

        [$ih,$im,$oh,$om] = $empTimeBase[$n];
        $dayNum  = (int)substr($date, 8);
        $inSecs  = ($dayNum % 4) * 15;
        $outSecs = ($dayNum % 3) * 20;

        $timeIn  = sprintf('%02d:%02d:%02d', $ih, $im, $inSecs);
        $timeOut = sprintf('%02d:%02d:%02d', $oh, $om, $outSecs);
        $status  = 'PRESENT';
        $lateMin = 0;

        if (isset($leaveEx2[$n]) && in_array($date, $leaveEx2[$n])) {
            $status  = 'LEAVE';
            $timeIn  = null; $timeOut = null;
        } elseif (isset($absentEx2[$n]) && in_array($date, $absentEx2[$n])) {
            $status  = 'ABSENT';
            $timeIn  = null; $timeOut = null;
        } elseif (isset($lateEx2[$n]) && in_array($date, $lateEx2[$n])) {
            $status  = 'HALF_DAY';
            $hdInfo  = $halfDayTimes[$n][$date] ?? ['09:10:00', 10];
            $timeIn  = $hdInfo[0];
            $lateMin = $hdInfo[1];
            $timeOut = sprintf('%02d:%02d:%02d', $oh, $om, $outSecs);
        }

        $aStmt2->execute([$id,$date,$timeIn,$timeOut,$status,$lateMin]);
        $attCount2++;
    }
}
echo "  ✓ {$attCount2} attendance records (Feb–May 28, 2026)\n";

// ─── 8. LEAVE CREDITS — SY 2025-2026 ─────────────────────────────────────────
echo "\n[8] Seeding leave credits (SY 2025-2026)...\n";

$lcStmt1 = $pdo->prepare("INSERT INTO employee_leave_credits (employee_id,school_year_id,leave_type_id,allocated_days,used_days) VALUES (?,{$sy2526},?,?,?)");

// Eduardo (emp 10): 33 leave days = 15 sick + 15 vacation + 3 emergency = 3 excess over 30-day pool
$usedLeaves1 = [
    5  => [0.0, 0.0, 0.0],  // Gloria — maternity is statutory (separate entry)
    8  => [0.0, 0.0, 0.0],  // Benjamin — paternity tracked separately below
    9  => [3.0, 0.0, 0.0],  // Carmelita — 3 vacation
    10 => [15.0,15.0, 3.0], // Eduardo EOSY showcase: 33 total (3 over 30-day limit)
    11 => [0.0, 2.0, 0.0],  // Felicia — 2 sick
    16 => [0.0, 0.0, 1.0],  // Quirino — 1 emergency
];

foreach ($empId as $n => $id) {
    if ($employees[$n-1][8] === 'PART_TIME') continue;
    $u = $usedLeaves1[$n] ?? [0.0, 0.0, 0.0];
    $lcStmt1->execute([$id, 2, 30.00, $u[0]]); // Vacation
    $lcStmt1->execute([$id, 3, 15.00, $u[1]]); // Sick
    $lcStmt1->execute([$id, 6,  3.00, $u[2]]); // Emergency
    if ($n === 8) $lcStmt1->execute([$id, 5, 7.00, 5.0]);    // Benjamin paternity — 5 of 7 days used
    if ($n === 5) $lcStmt1->execute([$id, 4, 60.00, 42.0]);  // Gloria maternity (42 working days)
}
echo "  ✓ Leave credits seeded (SY 2025-2026)\n";
echo "  ✓ Eduardo Garcia: Vacation=15 Sick=15 Emergency=3 → 33 total used (3 excess)\n";

// ─── 9. LEAVE CREDITS — SY 2026-2027 ─────────────────────────────────────────
echo "\n[9] Seeding leave credits (SY 2026-2027)...\n";

$lcStmt2 = $pdo->prepare("
    INSERT INTO employee_leave_credits (employee_id,school_year_id,leave_type_id,allocated_days,used_days)
    VALUES (?,{$sy2627},?,?,?)
");
$lcCount2 = 0;
foreach ($empId as $n => $id) {
    if ($employees[$n-1][8] === 'PART_TIME') continue;
    $lcStmt2->execute([$id, 2, 30.00, 0.00]);
    $lcStmt2->execute([$id, 3, 15.00, 0.00]);
    $lcStmt2->execute([$id, 6,  3.00, 0.00]);
    if ($n === 8) $lcStmt2->execute([$id, 5, 7.00, 0.00]);
    if ($n === 5) $lcStmt2->execute([$id, 4, 60.00, 0.00]);
    $lcCount2++;
}
echo "  ✓ Leave credits allocated for {$lcCount2} full-time employees (SY 2026-2027)\n";

// ─── 10. LEAVE REQUESTS — SY 2025-2026 ────────────────────────────────────────
echo "\n[10] Seeding leave requests (SY 2025-2026)...\n";

$lrStmt = $pdo->prepare("
    INSERT INTO leave_requests
    (employee_id,leave_type_id,school_year_id,reason,start_date,end_date,total_days,
     status,workflow_status,approved_by,approved_at,is_attendance_recorded,recorded_at,recorded_by)
    VALUES (?,?,{$sy2526},?,?,?,?,?,?,?,?,1,NOW(),?)
");
$ldStmt = $pdo->prepare("INSERT INTO leave_request_dates (leave_id,leave_date,status,actioned_by,actioned_at) VALUES (?,?,?,?,?)");

$leaves1 = [
    // Eduardo — EOSY showcase: sick leave Aug 2025 (5 days)
    [10, 3, 'Fever and body pain — medical certificate submitted',
        '2025-08-04','2025-08-08', 5.0, 'APPROVED','RECORDED',
        ['2025-08-04'=>'APPROVED','2025-08-05'=>'APPROVED','2025-08-06'=>'APPROVED',
         '2025-08-07'=>'APPROVED','2025-08-08'=>'APPROVED']],
    // Eduardo — sick leave Sep 2025 (10 days)
    [10, 3, 'Recurring respiratory illness — medical certificate attached',
        '2025-09-01','2025-09-12', 10.0, 'APPROVED','RECORDED',
        ['2025-09-01'=>'APPROVED','2025-09-02'=>'APPROVED','2025-09-03'=>'APPROVED',
         '2025-09-04'=>'APPROVED','2025-09-05'=>'APPROVED',
         '2025-09-08'=>'APPROVED','2025-09-09'=>'APPROVED','2025-09-10'=>'APPROVED',
         '2025-09-11'=>'APPROVED','2025-09-12'=>'APPROVED']],
    // Eduardo — 15 vacation days (Oct–Nov 2025)
    [10, 2, 'Family vacation and rest',
        '2025-10-20','2025-11-07', 15.0, 'APPROVED','RECORDED',
        ['2025-10-20'=>'APPROVED','2025-10-21'=>'APPROVED','2025-10-22'=>'APPROVED',
         '2025-10-23'=>'APPROVED','2025-10-24'=>'APPROVED',
         '2025-10-27'=>'APPROVED','2025-10-28'=>'APPROVED','2025-10-29'=>'APPROVED',
         '2025-10-30'=>'APPROVED','2025-10-31'=>'APPROVED',
         '2025-11-03'=>'APPROVED','2025-11-04'=>'APPROVED','2025-11-05'=>'APPROVED',
         '2025-11-06'=>'APPROVED','2025-11-07'=>'APPROVED']],
    // Eduardo — 3 emergency days (Dec 2025)
    [10, 6, 'Family bereavement',
        '2025-12-01','2025-12-04', 3.0, 'APPROVED','RECORDED',
        ['2025-12-01'=>'APPROVED','2025-12-02'=>'APPROVED','2025-12-04'=>'APPROVED']],
    // Carmelita
    [9,  2, 'Rest and recreation',
        '2025-10-13','2025-10-15', 3.0, 'APPROVED','RECORDED',
        ['2025-10-13'=>'APPROVED','2025-10-14'=>'APPROVED','2025-10-15'=>'APPROVED']],
    // Felicia
    [11, 3, 'Fever and flu symptoms',
        '2025-11-10','2025-11-11', 2.0, 'APPROVED','RECORDED',
        ['2025-11-10'=>'APPROVED','2025-11-11'=>'APPROVED']],
    // Quirino
    [16, 6, 'Family emergency — parent hospitalized',
        '2025-09-16','2025-09-16', 1.0, 'APPROVED','RECORDED',
        ['2025-09-16'=>'APPROVED']],
    // Benjamin — paternity (all 5 days approved)
    [8,  5, 'Paternity leave — newborn child',
        '2025-10-06','2025-10-10', 5.0, 'APPROVED','RECORDED',
        ['2025-10-06'=>'APPROVED','2025-10-07'=>'APPROVED','2025-10-08'=>'APPROVED',
         '2025-10-09'=>'APPROVED','2025-10-10'=>'APPROVED']],
    // Gloria — maternity leave (42 working days: Jan 13 + Feb 19 + Mar 10)
    [5,  4, 'Maternity leave',
        '2026-01-15','2026-03-15', 42.0, 'APPROVED','RECORDED',
        ['2026-01-15'=>'APPROVED','2026-01-16'=>'APPROVED','2026-01-17'=>'APPROVED',
         '2026-01-20'=>'APPROVED','2026-01-21'=>'APPROVED','2026-01-22'=>'APPROVED',
         '2026-01-23'=>'APPROVED','2026-01-24'=>'APPROVED','2026-01-27'=>'APPROVED',
         '2026-01-28'=>'APPROVED','2026-01-29'=>'APPROVED','2026-01-30'=>'APPROVED',
         '2026-01-31'=>'APPROVED',
         '2026-02-02'=>'APPROVED','2026-02-03'=>'APPROVED','2026-02-04'=>'APPROVED',
         '2026-02-05'=>'APPROVED','2026-02-06'=>'APPROVED','2026-02-09'=>'APPROVED',
         '2026-02-10'=>'APPROVED','2026-02-11'=>'APPROVED','2026-02-12'=>'APPROVED',
         '2026-02-13'=>'APPROVED','2026-02-16'=>'APPROVED','2026-02-17'=>'APPROVED',
         '2026-02-18'=>'APPROVED','2026-02-19'=>'APPROVED','2026-02-20'=>'APPROVED',
         '2026-02-23'=>'APPROVED','2026-02-24'=>'APPROVED','2026-02-26'=>'APPROVED',
         '2026-02-27'=>'APPROVED',
         '2026-03-02'=>'APPROVED','2026-03-03'=>'APPROVED','2026-03-04'=>'APPROVED',
         '2026-03-05'=>'APPROVED','2026-03-06'=>'APPROVED','2026-03-09'=>'APPROVED',
         '2026-03-10'=>'APPROVED','2026-03-11'=>'APPROVED','2026-03-12'=>'APPROVED',
         '2026-03-13'=>'APPROVED']],
    // Gerardo — REJECTED sick leave (absence in attendance shows as ABSENT)
    [12, 3, 'Not feeling well',
        '2025-09-08','2025-09-10', 3.0, 'REJECTED','RECORDED',
        ['2025-09-08'=>'REJECTED','2025-09-09'=>'REJECTED','2025-09-10'=>'REJECTED']],
];

foreach ($leaves1 as $lv) {
    $approved   = in_array($lv[6], ['APPROVED','PARTIALLY_APPROVED']) ? 1 : 0;
    $appBy      = $approved ? $principalUid : null;
    $appAt      = $approved ? date('Y-m-d H:i:s', strtotime($lv[3]) + 43200) : null;
    $recBy      = ($lv[7] === 'RECORDED') ? $adminUid : null;
    $lrStmt->execute([
        $empId[$lv[0]],$lv[1],$lv[2],$lv[3],$lv[4],$lv[5],
        $lv[6],$lv[7],$appBy,$appAt,($recBy)
    ]);
    $lvId = (int)$pdo->lastInsertId();
    foreach ($lv[8] as $date => $dStat) {
        $actBy = ($dStat !== 'PENDING') ? $principalUid : null;
        $actAt = ($dStat !== 'PENDING') ? date('Y-m-d H:i:s', strtotime($date) + 50400) : null;
        $ldStmt->execute([$lvId,$date,$dStat,$actBy,$actAt]);
    }
    $eName = $employees[$lv[0]-1][1].' '.$employees[$lv[0]-1][3];
    echo "  ✓ [{$lv[6]}] {$eName} — {$lv[3]} to {$lv[4]} ({$lv[5]}d)\n";
}

// ─── 11. LEAVE REQUESTS — SY 2026-2027 ────────────────────────────────────────
echo "\n[11] Seeding leave requests (SY 2026-2027 / May 2026)...\n";

$lrStmt2 = $pdo->prepare("
    INSERT INTO leave_requests
    (employee_id,leave_type_id,school_year_id,reason,start_date,end_date,total_days,
     status,workflow_status,approved_by,approved_at,is_attendance_recorded,recorded_at,recorded_by)
    VALUES (?,?,{$sy2627},?,?,?,?,?,?,?,?,1,NOW(),?)
");

$leaves2 = [
    [14, 3, 'Fever and colds — consulted clinic',
        '2026-05-12','2026-05-13', 2.0, 'APPROVED','RECORDED',
        ['2026-05-12'=>'APPROVED','2026-05-13'=>'APPROVED']],
    [9,  2, 'Family vacation and rest',
        '2026-05-19','2026-05-20', 2.0, 'APPROVED','RECORDED',
        ['2026-05-19'=>'APPROVED','2026-05-20'=>'APPROVED']],
];

foreach ($leaves2 as $lv) {
    $approved = ($lv[7] !== 'PENDING') ? 1 : 0;
    $appBy    = $approved ? $principalUid : null;
    $appAt    = $approved ? date('Y-m-d H:i:s', strtotime($lv[3]) + 43200) : null;
    $recBy    = ($lv[7] === 'RECORDED') ? $adminUid : null;
    $lrStmt2->execute([
        $empId[$lv[0]],$lv[1],$lv[2],$lv[3],$lv[4],$lv[5],
        $lv[6],$lv[7],$appBy,$appAt,$recBy
    ]);
    $lvId = (int)$pdo->lastInsertId();
    foreach ($lv[8] as $date => $dStat) {
        $actBy = ($dStat !== 'PENDING') ? $principalUid : null;
        $actAt = ($dStat !== 'PENDING') ? date('Y-m-d H:i:s', strtotime($date) + 50400) : null;
        $ldStmt->execute([$lvId,$date,$dStat,$actBy,$actAt]);
    }
    if ($approved && $lv[5] > 0) {
        $pdo->prepare("
            UPDATE employee_leave_credits
            SET used_days = used_days + ?
            WHERE employee_id=? AND school_year_id={$sy2627} AND leave_type_id=?
        ")->execute([$lv[5], $empId[$lv[0]], $lv[1]]);
    }
    $eName = $employees[$lv[0]-1][1].' '.$employees[$lv[0]-1][3];
    echo "  ✓ [{$lv[6]}] {$eName} — {$lv[3]}\n";
}

// ─── 12. LOANS ────────────────────────────────────────────────────────────────
echo "\n[12] Seeding employee loans...\n";

$loanStmt = $pdo->prepare("
    INSERT INTO employee_loans
    (employee_id,loan_type_id,account_reference,provider_name,total_amount,balance_amount,
     monthly_deduction,interest_rate,total_payable,reason,approved_by,approved_at,
     start_date,end_date,status,filed_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

// [empN, loan_type_id, ref, provider, total, balance, monthly, rate, payable, reason, approvedDate, endDate, status]
// balance_amount = state BEFORE seed-period payments (payment log sync UPDATE below will reduce these)
$loans = [
    [1,  8,'RBL-2025-001','Rural Bank of La Paz', 120000,120000, 5000, 3.00,125000,'Home renovation',   '2025-08-01','2027-07-31','ACTIVE'],
    [4,  5,'MPF-2025-004','Pag-IBIG Fund',          45000, 35625, 1875, 3.00, 49000,'Medical expenses', '2025-03-01','2027-02-28','ACTIVE'],
    [7,  5,'MPF-2025-007','Pag-IBIG Fund',          60000, 60000, 2000, 3.00, 64000,'Educational',      '2025-10-01','2028-09-30','ACTIVE'],
    [10, 7,'PRF-2025-010','PERAA',                   50000, 50000, 2500, 0.00, 50000,'Emergency needs', '2025-09-01','2027-08-31','ACTIVE'],
    [14, 8,'RBL-2025-014','Rural Bank of La Paz',   80000, 80000, 4000, 3.00, 85000,'Vehicle loan',     '2025-08-01','2027-07-31','ACTIVE'],
    [18, 4,'SSL-2025-018','SSS',                     10000, 10000, 2000, 0.00, 10000,'Calamity loan',   '2025-08-01','2025-12-31','COMPLETED'],
    [19, 6,'HFL-2020-019','Pag-IBIG Fund',          300000,132500, 2500, 6.00,380000,'Housing purchase','2020-01-01','2030-01-01','ACTIVE'],
];

$loanId = [];
foreach ($loans as $l) {
    $isActive  = in_array($l[12], ['ACTIVE','COMPLETED']);
    $appBy     = $isActive ? $principalUid : null;
    $appAt     = $isActive ? date('Y-m-d H:i:s', strtotime($l[11] ?? $l[10]) - 86400*3) : null;
    $loanStmt->execute([
        $empId[$l[0]],$l[1],$l[2],$l[3],$l[4],$l[5],
        $l[6],$l[7],$l[8],$l[9],$appBy,$appAt,
        $l[10],$l[11],$l[12],'ADMIN'
    ]);
    $lid = (int)$pdo->lastInsertId();
    if (!isset($loanId[$l[0]])) $loanId[$l[0]] = $lid;
    $eName = $employees[$l[0]-1][1].' '.$employees[$l[0]-1][3];
    echo "  ✓ Loan [{$l[12]}]: {$eName} — {$l[3]} " . p($l[4]) . "\n";
}

// Active loan deductions per emp for payroll:
// [deduct_type_id, monthly_amount, loan_db_id, start_period_idx, end_period_idx]
$activeLoanDeductions = [
    1  => [16, 5000.00, null, 1, 99],   // Rural Bank — from period 1
    4  => [14, 1875.00, null, 1, 99],   // HDMF MPF
    7  => [14, 2000.00, null, 5, 99],   // HDMF MPF — starts Oct 2025 (period 5)
    10 => [15, 2500.00, null, 3, 99],   // PERAA — starts Sep 2025 (period 3)
    14 => [16, 4000.00, null, 1, 99],   // Rural Bank
    18 => [13, 2000.00, null, 1, 10],   // SSS Loan — completed after period 10
    19 => [14, 2500.00, null, 1, 99],   // HDMF Housing
];
foreach ($activeLoanDeductions as $n => &$ld) {
    $ld[2] = $loanId[$n] ?? null;
}
unset($ld);

// ─── 13. SERVICE CREDITS ──────────────────────────────────────────────────────
echo "\n[13] Seeding service credits...\n";

$scStmt = $pdo->prepare("
    INSERT INTO service_credits
    (employee_id,work_date,days,equivalent_pay,is_approved,approved_at,
     status,approved_by,created_by,remarks)
    VALUES (?,?,?,?,?,?,?,?,?,?)
");
$scdStmt = $pdo->prepare("
    INSERT INTO service_credit_dates
    (service_credit_id,work_date,days,equivalent_pay,status,approved_by,approved_at)
    VALUES (?,?,?,?,?,?,?)
");

// Eduardo EOSY showcase: 8 service credit days = ₱8,000 (₱1,000/day)
$svcCredits = [
    // RELEASED — included in EOSY ACCRUED_PAY (Eduardo: 5 + 3 = 8 days)
    [10,'2025-12-20',5.0,'RELEASED',
        ['2025-12-20'=>1.0,'2025-12-21'=>1.0,'2025-12-22'=>1.0,'2025-12-23'=>1.0,'2025-12-27'=>1.0]],
    [10,'2026-01-25',3.0,'RELEASED',
        ['2026-01-25'=>1.0,'2026-01-26'=>1.0,'2026-01-31'=>1.0]],
    // Ana Kristina — RELEASED (2 days)
    [7, '2025-12-20',2.0,'RELEASED',
        ['2025-12-20'=>1.0,'2025-12-21'=>1.0]],
];

$scIdMap = []; // [empN => ['scId'=>id,'eqPay'=>total_eqPay,'allIds'=>[...]]]
foreach ($svcCredits as $sc) {
    $daily = round($monthly[$sc[0]] / 22, 2);
    $eqPay = round($daily * $sc[2], 2);
    $isApp = in_array($sc[3], ['APPROVED','RELEASED']) ? 1 : 0;
    $appAt = $isApp ? date('Y-m-d H:i:s', strtotime($sc[1]) + 86400*2 + 36000) : null;
    $appBy = $isApp ? $principalUid : null;
    $scStmt->execute([
        $empId[$sc[0]],$sc[1],$sc[2],$eqPay,$isApp,$appAt,
        $sc[3],$appBy,$adminUid,'Board resolution service work'
    ]);
    $scId = (int)$pdo->lastInsertId();

    if ($sc[3] === 'RELEASED') {
        if (!isset($scIdMap[$sc[0]])) {
            $scIdMap[$sc[0]] = ['scId'=>$scId,'eqPay'=>0.0,'allIds'=>[]];
        }
        $scIdMap[$sc[0]]['eqPay'] += $eqPay;
        $scIdMap[$sc[0]]['allIds'][] = $scId;
    }

    foreach ($sc[4] as $d => $days) {
        $dPay  = round($daily * $days, 2);
        $dStat = ($sc[3] === 'PENDING') ? 'PENDING' : 'APPROVED';
        $scdStmt->execute([$scId,$d,$days,$dPay,$dStat,$appBy,$appAt]);
    }
    $eName = $employees[$sc[0]-1][1].' '.$employees[$sc[0]-1][3];
    echo "  ✓ Service Credit [{$sc[3]}]: {$eName} — {$sc[2]} day(s) " . p($eqPay) . "\n";
}
echo "  ✓ Eduardo Garcia total RELEASED service credits: " . p($scIdMap[10]['eqPay']) . " (8 days × ₱1,000)\n";

// ─── 14a. ENSURE ExcessLeaveSettlement deduction type exists ──────────────────
echo "\n[14a] Ensuring ExcessLeaveSettlement deduction type exists...\n";
$excessDtId = (int)$pdo->query("SELECT deduction_type_id FROM deduction_types WHERE deduction_name LIKE '%Excess%Leave%' LIMIT 1")->fetchColumn();
if (!$excessDtId) {
    $pdo->exec("INSERT INTO deduction_types (deduction_name, deduction_value_type, deduction_amount, is_government, is_loan, is_active)
                VALUES ('Excess Leave Settlement', 'FIXED', 0.00, 0, 0, 1)");
    $excessDtId = (int)$pdo->lastInsertId();
    echo "  ✓ Created ExcessLeaveSettlement deduction type id={$excessDtId}\n";
} else {
    echo "  ✓ ExcessLeaveSettlement already exists id={$excessDtId}\n";
}

// ─── 14. PAYROLL PERIODS ──────────────────────────────────────────────────────
echo "\n[14] Seeding payroll periods...\n";

$ppStmt = $pdo->prepare("INSERT INTO payroll_periods (payroll_number,period_name,period_type,pay_period_start,pay_period_end,pay_date,status) VALUES (?,?,?,?,?,?,?)");

// 21 total periods — EOSY is index 16 (ACCRUED_PAY); regular Apr 1-15 is index 17
$periods = [
    ['PR-2025-08-001','August 1–15, 2025',           'REGULAR',    '2025-08-01','2025-08-15','2025-08-15','RELEASED'],  // 1
    ['PR-2025-08-002','August 16–31, 2025',          'REGULAR',    '2025-08-16','2025-08-31','2025-08-29','RELEASED'],  // 2
    ['PR-2025-09-001','September 1–15, 2025',        'REGULAR',    '2025-09-01','2025-09-15','2025-09-15','RELEASED'],  // 3
    ['PR-2025-09-002','September 16–30, 2025',       'REGULAR',    '2025-09-16','2025-09-30','2025-09-30','RELEASED'],  // 4
    ['PR-2025-10-001','October 1–15, 2025',          'REGULAR',    '2025-10-01','2025-10-15','2025-10-15','RELEASED'],  // 5
    ['PR-2025-10-002','October 16–31, 2025',         'REGULAR',    '2025-10-16','2025-10-31','2025-10-31','RELEASED'],  // 6
    ['PR-2025-11-001','November 1–15, 2025',         'REGULAR',    '2025-11-01','2025-11-15','2025-11-14','RELEASED'],  // 7
    ['PR-2025-11-002','November 16–30, 2025',        'REGULAR',    '2025-11-16','2025-11-30','2025-11-28','RELEASED'],  // 8
    ['PR-2025-12-001','December 1–15, 2025',         'REGULAR',    '2025-12-01','2025-12-15','2025-12-13','RELEASED'],  // 9
    ['PR-2025-12-002','December 16–31, 2025',        'REGULAR',    '2025-12-16','2025-12-31','2025-12-19','RELEASED'],  // 10
    ['PR-2026-01-001','January 1–15, 2026',          'REGULAR',    '2026-01-01','2026-01-15','2026-01-15','RELEASED'],  // 11
    ['PR-2026-01-002','January 16–31, 2026',         'REGULAR',    '2026-01-16','2026-01-31','2026-01-30','RELEASED'],  // 12
    ['PR-2026-02-001','February 1–15, 2026',         'REGULAR',    '2026-02-01','2026-02-15','2026-02-13','RELEASED'],  // 13
    ['PR-2026-02-002','February 16–28, 2026',        'REGULAR',    '2026-02-16','2026-02-28','2026-02-27','RELEASED'],  // 14
    ['PR-2026-03-001','March 1–15, 2026',            'REGULAR',    '2026-03-01','2026-03-15','2026-03-14','RELEASED'],  // 15
    ['PR-2026-04-001','EOSY Accrued Pay 2025–2026',  'ACCRUED_PAY','2026-04-01','2026-04-15','2026-04-17','RELEASED'],  // 16 — EOSY
    ['PR-2026-04-002','April 1–15, 2026',            'REGULAR',    '2026-04-01','2026-04-15','2026-04-15','RELEASED'],  // 17 — regular Apr 1-15 (alongside EOSY)
    ['PR-2026-03-002','March 16–31, 2026',           'REGULAR',    '2026-03-16','2026-03-31','2026-03-31','RELEASED'],  // 18
    ['PR-2026-04-003','April 16–30, 2026',           'REGULAR',    '2026-04-16','2026-04-30','2026-04-29','RELEASED'],  // 19
    ['PR-2026-05-001','May 1–15, 2026',              'REGULAR',    '2026-05-01','2026-05-15','2026-05-15','APPROVED'],  // 20
    ['PR-2026-05-002','May 16–31, 2026',             'REGULAR',    '2026-05-16','2026-05-31','2026-05-30','OPEN'],      // 21
];

$periodId = [];
foreach ($periods as $i => $p) {
    $ppStmt->execute($p);
    $periodId[$i+1] = (int)$pdo->lastInsertId();
    echo "  ✓ [{$periodId[$i+1]}] {$p[1]} [{$p[6]}]\n";
}

// ─── 15. PAYROLL RECORDS, ALLOWANCES, DEDUCTIONS ──────────────────────────────
echo "\n[15] Seeding payroll records...\n";

$prStmt = $pdo->prepare("
    INSERT INTO payroll_records
    (period_id,employee_id,basic_pay,gross_pay,total_allowances,total_deductions,
     net_pay,employer_sss_share,employer_philhealth_share,employer_pagibig_share,
     remarks,payroll_status,approved_by,approved_at,released_by,released_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
$paStmt = $pdo->prepare("INSERT INTO payroll_allowances (payroll_id,allowance_type_id,amount) VALUES (?,?,?)");
$pdStmt = $pdo->prepare("INSERT INTO payroll_deductions (payroll_id,deduction_type_id,loan_id,amount) VALUES (?,?,?,?)");

$totalRecords = 0;
$allPayrollIds = []; // [periodIdx][empN] = payroll_id

// EOSY period is index 16
// Eduardo (empN=10): basic=0, +₱8,000 svc credits, -₱2,000 half-day (4×₱500), -₱3,000 excess (3×₱1,000) = ₱3,000

// Half-day incidents for EOSY settlement (full SY 2025-2026, through Apr 2026)
// Includes both lateEx1 (Aug-Jan) and lateEx2 (Feb-Apr) incidents
$halfDayIncidents = [
    3  => 7,  // Josephine: 5 (Aug-Jan) + 2 (Feb-Mar)
    7  => 1,  // Ana Kristina: 1 (Mar)
    8  => 2,  // Benjamin: 2 (Aug-Jan)
    10 => 4,  // Eduardo EOSY showcase: 4 (Jan)
    12 => 2,  // Gerardo: 2 (Aug-Jan)
    16 => 2,  // Quirino: 1 (Aug-Jan) + 1 (Apr)
];

foreach ($periods as $pi => $per) {
    $pIdx   = $pi + 1;
    $pId    = $periodId[$pIdx];
    $isAcc  = ($per[2] === 'ACCRUED_PAY');
    $isOpen = ($per[6] === 'OPEN');

    if ($isOpen) {
        echo "  ↩ Period {$pIdx}: {$per[1]} [OPEN — skipped, demo generates live]\n";
        continue;
    }

    $prStat = match($per[6]) {
        'RELEASED' => 'RELEASED',
        'APPROVED' => 'APPROVED',
        default    => 'DRAFT',
    };
    $appBy = in_array($per[6], ['RELEASED','APPROVED']) ? $principalUid : null;
    $appAt = $appBy ? date('Y-m-d H:i:s', strtotime($per[4]) + 86400*2 + 50400) : null;
    $relBy = ($per[6] === 'RELEASED') ? $adminUid : null;
    $relAt = $relBy ? date('Y-m-d H:i:s', strtotime($per[4]) + 86400*3 + 54000) : null;

    foreach ($empId as $n => $eId) {
        $m = (float)$monthly[$n];

        $sssEmpM  = sssEmployee($m);  $sssErM  = sssEmployer($m);
        $phEmpM   = phEmployee($m);
        $pagibigM = pagibig($m);
        $peraaM   = ($employees[$n-1][8] === 'FULL_TIME') ? 200.00 : 0.00;
        $wtM      = withholdingTax($m, $sssEmpM, $phEmpM, $pagibigM);

        $sssP     = round($sssEmpM / 2, 2);  $sssErP = round($sssErM / 2, 2);
        $phP      = round($phEmpM / 2, 2);
        $pagibigP = round($pagibigM / 2, 2);
        $peraaP   = round($peraaM / 2, 2);
        $wtP      = round($wtM / 2, 2);

        // Rice Subsidy ₱1,000 for all periods (demo "before state"; live demo changes this)
        $rice    = 1000.00;
        $laundry = 500.00;
        $assignPay = match($n) { 1=>1500.00, 2=>1000.00, default=>0.00 };

        $basicPay = $isAcc ? 0.00 : round($m / 2, 2);

        $halfDayDeduct      = 0.00;
        $svcCreditAllowance = 0.00;
        $excessLeaveDeduct  = 0.00;

        if ($isAcc) {
            // Half-day settlements (full SY count)
            $incidents = $halfDayIncidents[$n] ?? 0;
            if ($incidents > 0) {
                $dailyRate     = round($m / 22, 2);
                $halfDayDeduct = round($dailyRate / 2 * $incidents, 2);
            }
            // Service credits (RELEASED only)
            if (isset($scIdMap[$n])) {
                $svcCreditAllowance = $scIdMap[$n]['eqPay'];
            }
            // Excess leave (Eduardo: 3 days over 30-day annual limit)
            if ($n === 10) {
                $dailyRate         = round($m / 22, 2);
                $excessLeaveDeduct = round($dailyRate * 3, 2);
            }
            // ACCRUED_PAY: no government contributions, no loans
            $sssP = 0.00; $phP = 0.00; $pagibigP = 0.00; $peraaP = 0.00; $wtP = 0.00;
            $sssErP = 0.00;
        }

        // Loan deductions (not in ACCRUED_PAY)
        $loanAmt  = 0.00;
        $loanDtId = null;
        $loanDbId = null;
        if (!$isAcc && isset($activeLoanDeductions[$n])) {
            [$loanDtId, $loanMonthly, $loanDbId, $startP, $endP] = $activeLoanDeductions[$n];
            if ($pIdx >= $startP && $pIdx <= $endP) {
                $loanAmt = round($loanMonthly / 2, 2);
            }
        }

        // All employees (including part-time) receive Rice + Laundry per TOR Q66
        $totalAllowances = $rice + $laundry + $assignPay + $svcCreditAllowance;
        if ($isAcc) $totalAllowances = $svcCreditAllowance; // ACCRUED_PAY: no rice/laundry
        $govDeductions   = $sssP + $phP + $pagibigP + $peraaP + $wtP;
        $totalDeductions = $govDeductions + $loanAmt + $halfDayDeduct + $excessLeaveDeduct;
        $grossPay        = $basicPay + $totalAllowances;
        // ACCRUED_PAY: allow true negative net_pay so EOSY Balance Recovery triggers correctly
        $netPay = $isAcc
            ? round($grossPay - $totalDeductions, 2)
            : max(0.00, round($grossPay - $totalDeductions, 2));

        $prStmt->execute([
            $pId,$eId,$basicPay,$grossPay,$totalAllowances,$totalDeductions,
            $netPay,$sssErP,round($phEmpM/2,2),$pagibigP,
            null,$prStat,$appBy,$appAt,$relBy,$relAt
        ]);
        $prId = (int)$pdo->lastInsertId();
        $allPayrollIds[$pIdx][$n] = $prId;
        $totalRecords++;

        // Allowances
        if (!$isAcc) {
            $paStmt->execute([$prId, 4, $rice]);
            $paStmt->execute([$prId, 5, $laundry]);
            if ($assignPay > 0) $paStmt->execute([$prId, 6, $assignPay]);
        }
        if ($svcCreditAllowance > 0) $paStmt->execute([$prId, 6, $svcCreditAllowance]);

        // Deductions
        if (!$isAcc) {
            $pdStmt->execute([$prId, 8,  null, $sssP]);
            $pdStmt->execute([$prId, 9,  null, $phP]);
            $pdStmt->execute([$prId, 10, null, $pagibigP]);
            if ($peraaP > 0) $pdStmt->execute([$prId, 12, null, $peraaP]);
            if ($wtP    > 0) $pdStmt->execute([$prId, 11, null, $wtP]);
            if ($loanAmt > 0) $pdStmt->execute([$prId, $loanDtId, $loanDbId, $loanAmt]);
        }
        if ($halfDayDeduct   > 0) $pdStmt->execute([$prId, 19, null, $halfDayDeduct]);
        if ($excessLeaveDeduct > 0) $pdStmt->execute([$prId, $excessDtId, null, $excessLeaveDeduct]);
    }
    echo "  ✓ Period {$pIdx}: {$per[1]} [{$per[6]}]\n";
}
echo "  ✓ {$totalRecords} payroll records created\n";

// Spot-check Eduardo EOSY (period index 16)
if (isset($allPayrollIds[16][10])) {
    $eoRow = $pdo->query("SELECT basic_pay,gross_pay,total_allowances,total_deductions,net_pay FROM payroll_records WHERE payroll_id={$allPayrollIds[16][10]}")->fetch();
    echo "\n  ── EOSY Spot-Check: Eduardo A. Garcia (EMP-2025-010) ──\n";
    echo "  Basic Pay       : " . p((float)$eoRow['basic_pay']) . "  (should be ₱0.00)\n";
    echo "  Service Credits : " . p($scIdMap[10]['eqPay']) . "  (should be ₱8,000.00)\n";
    echo "  Total Allow     : " . p((float)$eoRow['total_allowances']) . "\n";
    echo "  Total Deductions: " . p((float)$eoRow['total_deductions']) . "  (should be ₱5,000.00)\n";
    echo "  Net Pay         : " . p((float)$eoRow['net_pay']) . "  (should be ₱3,000.00)\n";
    echo "  ─────────────────────────────────────────────────────────\n\n";
}

// ─── Link RELEASED service credits to ACCRUED_PAY payroll records ─────────────
if (!empty($scIdMap) && isset($periodId[16])) {
    $scLinkStmt = $pdo->prepare("UPDATE service_credits SET payroll_id=?,applied_to_payroll_at=NOW(),status='RELEASED' WHERE service_credit_id=?");
    foreach ($scIdMap as $empN => $sc) {
        if (isset($allPayrollIds[16][$empN])) {
            foreach ($sc['allIds'] as $scId) {
                $scLinkStmt->execute([$allPayrollIds[16][$empN], $scId]);
            }
        }
    }
    echo "  ✓ Service credits linked to ACCRUED_PAY payroll records\n";
}

// ─── 15b. EOSY BALANCE RECOVERY — backfill into period 17 (Apr 1–15 regular) ──
echo "\n[15b] Backfilling EOSY Balance Recovery deductions...\n";

// Ensure recovery deduction type exists
$recovDtRow = $pdo->query("
    SELECT deduction_type_id FROM deduction_types
    WHERE deduction_name = 'EOSY Balance Recovery' AND is_active = 1
    LIMIT 1
")->fetch();
if ($recovDtRow) {
    $recovDtId = (int)$recovDtRow['deduction_type_id'];
} else {
    $pdo->exec("INSERT INTO deduction_types
        (deduction_name, deduction_value_type, deduction_amount, is_government, is_loan, is_active)
        VALUES ('EOSY Balance Recovery', 'FIXED', 0.00, 0, 0, 1)");
    $recovDtId = (int)$pdo->lastInsertId();
}
echo "  ✓ Recovery deduction type id={$recovDtId}\n";

// Employees with negative EOSY net_pay (period 16)
$negStmt = $pdo->prepare("
    SELECT employee_id, ABS(net_pay) AS owed
    FROM payroll_records WHERE period_id = ? AND net_pay < 0
");
$negStmt->execute([$periodId[16]]);
$negEmployees = $negStmt->fetchAll();

$recovCount = 0;
foreach ($negEmployees as $ne) {
    $eId  = (int)$ne['employee_id'];
    $owed = round((float)$ne['owed'], 2);

    // Find their period 17 (Apr 1–15 regular) record
    $prReg = $pdo->prepare("
        SELECT payroll_id, basic_pay, total_allowances, total_deductions
        FROM payroll_records WHERE period_id = ? AND employee_id = ? LIMIT 1
    ");
    $prReg->execute([$periodId[17], $eId]);
    $pr = $prReg->fetch();
    if (!$pr) continue;

    // Idempotent: skip if recovery already applied
    $chk = $pdo->prepare("SELECT COUNT(*) FROM payroll_deductions WHERE payroll_id=? AND deduction_type_id=?");
    $chk->execute([$pr['payroll_id'], $recovDtId]);
    if ((int)$chk->fetchColumn() > 0) continue;

    // Insert recovery deduction row
    $pdStmt->execute([$pr['payroll_id'], $recovDtId, null, $owed]);

    // Recalculate period 17 (Apr 1-15 regular) totals for this employee
    $newTotalDed = round((float)$pr['total_deductions'] + $owed, 2);
    $newNetPay   = round((float)$pr['basic_pay'] + (float)$pr['total_allowances'] - $newTotalDed, 2);
    $pdo->prepare("UPDATE payroll_records SET total_deductions=?, net_pay=? WHERE payroll_id=?")
        ->execute([$newTotalDed, $newNetPay, $pr['payroll_id']]);

    $empN  = array_search($eId, $empId);
    $eName = $employees[$empN-1][1].' '.$employees[$empN-1][3];
    echo "  ✓ {$eName}: " . p($owed) . " recovery → Apr 1-15 regular (new net: " . p($newNetPay) . ")\n";
    $recovCount++;
}
echo "  ✓ {$recovCount} EOSY Balance Recovery deduction(s) backfilled\n";

// ─── 16. PAYROLL WORKFLOW LOGS ─────────────────────────────────────────────────
echo "\n[16] Seeding payroll workflow logs...\n";

$wlStmt = $pdo->prepare("
    INSERT INTO payroll_workflow_log
    (period_id,event_type,performed_by,performer_name,gross_total,net_total,emp_count,created_at)
    VALUES (?,?,?,?,?,?,?,?)
");

foreach ($periods as $pi => $per) {
    $pIdx = $pi + 1;
    $pId  = $periodId[$pIdx];

    if ($per[6] === 'OPEN') continue;

    $totRow = $pdo->prepare("SELECT COALESCE(SUM(gross_pay),0) AS g,COALESCE(SUM(net_pay),0) AS n,COUNT(*) AS c FROM payroll_records WHERE period_id=?");
    $totRow->execute([$pId]);
    $t   = $totRow->fetch();
    $g   = (float)$t['g']; $n = (float)$t['n']; $ec = (int)$t['c'];
    $base = strtotime($per[4]);

    $wlStmt->execute([$pId,'GENERATED',$adminUid,$adminName,$g,$n,$ec,
        date('Y-m-d H:i:s',$base-86400*5+32400)]);

    if (in_array($per[6],['PROCESSING','APPROVED','RELEASED'])) {
        $wlStmt->execute([$pId,'SUBMITTED',$adminUid,$adminName,$g,$n,$ec,
            date('Y-m-d H:i:s',$base-86400*4+36000)]);
    }
    if (in_array($per[6],['APPROVED','RELEASED'])) {
        $wlStmt->execute([$pId,'APPROVED',$principalUid,$principalName,$g,$n,$ec,
            date('Y-m-d H:i:s',$base-86400*3+50400)]);
    }
    if ($per[6] === 'RELEASED') {
        $wlStmt->execute([$pId,'RELEASED',$adminUid,$adminName,$g,$n,$ec,
            date('Y-m-d H:i:s',$base-86400*2+54000)]);
    }
}
echo "  ✓ Workflow logs created\n";

// ─── 17. PAYSLIPS ─────────────────────────────────────────────────────────────
echo "\n[17] Seeding payslips (RELEASED periods only)...\n";

$psStmt  = $pdo->prepare("INSERT INTO payslips (payroll_id,payslip_number,generated_at,received_by,date_received) VALUES (?,?,?,?,?)");
$psCount = 0;
foreach ($periods as $pi => $per) {
    $pIdx = $pi + 1;
    if ($per[6] !== 'RELEASED') continue;
    if (!isset($allPayrollIds[$pIdx])) continue;
    $genAt = date('Y-m-d H:i:s', strtotime($per[5]) + 39600);
    foreach ($allPayrollIds[$pIdx] as $empN => $prId) {
        $psNum = sprintf('PSL%s%02d%03d', str_replace('-','',substr($per[3],0,7)), $pIdx, $empN);
        $psStmt->execute([$prId,$psNum,$genAt,$employees[$empN-1][1].' '.$employees[$empN-1][3],$per[5]]);
        $psCount++;
    }
}
echo "  ✓ {$psCount} payslips generated\n";

// ─── 18. LOAN PAYMENT LOG — backfill for all RELEASED regular periods ──────────
echo "\n[18] Backfilling loan payment log...\n";

$lplStmt = $pdo->prepare("
    INSERT INTO loan_payment_log
        (loan_id, payment_date, amount, payment_channel, notes, encoded_by)
    VALUES (?, ?, ?, 'PAYROLL', 'Payroll deduction — auto-applied on release', ?)
");
$lplCount = 0;

foreach ($periods as $pi => $per) {
    $pIdx = $pi + 1;
    if ($per[6] !== 'RELEASED' || $per[2] === 'ACCRUED_PAY') continue;
    $pId     = $periodId[$pIdx];
    $payDate = $per[5];

    $dedRows = $pdo->prepare("
        SELECT pd.loan_id, pd.amount
        FROM payroll_records pr
        JOIN payroll_deductions pd ON pr.payroll_id = pd.payroll_id
        WHERE pr.period_id = ?
          AND pd.loan_id IS NOT NULL
          AND pd.amount > 0
    ");
    $dedRows->execute([$pId]);
    foreach ($dedRows->fetchAll() as $d) {
        $lplStmt->execute([$d['loan_id'], $payDate, $d['amount'], $adminUid]);
        $lplCount++;
    }
}
echo "  ✓ {$lplCount} loan payment log entries backfilled\n";

// Reduce balance_amount by the total recorded in the payment log so the
// displayed balance matches what was actually deducted in seed payrolls.
$pdo->exec("
    UPDATE employee_loans el
    JOIN (
        SELECT loan_id, SUM(amount) AS total_paid
        FROM loan_payment_log
        GROUP BY loan_id
    ) lpl ON lpl.loan_id = el.loan_id
    SET el.balance_amount = GREATEST(0, el.balance_amount - lpl.total_paid)
");
echo "  ✓ Loan balances synced with payment log\n";

// ─── 19. ATTENDANCE CORRECTIONS ───────────────────────────────────────────────
echo "\n[19] Seeding attendance corrections...\n";

$acStmt = $pdo->prepare("
    INSERT INTO attendance_corrections
        (attendance_id, employee_id, attendance_date, issue_type, explanation, status, admin_note, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

// Correction 1: Quirino — his Oct 15 half-day disputed (biometric showed correct time)
$qAtRow = $pdo->prepare("
    SELECT attendance_id FROM attendance_records
    WHERE employee_id=? AND attendance_date='2025-10-15' LIMIT 1
");
$qAtRow->execute([$empId[16]]);
$qAttId = $qAtRow->fetchColumn() ?: null;

$acStmt->execute([
    $qAttId, $empId[16], '2025-10-15',
    'LATE_INCORRECT',
    'Biometric log shows check-in at 07:55. System marked as half-day in error. Requesting status correction to PRESENT.',
    'RESOLVED',
    'Reviewed DTR archive — confirmed 07:55 biometric entry. Status corrected to PRESENT.',
    '2025-10-16 08:15:00', '2025-10-20 10:30:00'
]);
echo "  ✓ Correction 1: Quirino Santos — LATE_INCORRECT 2025-10-15 [RESOLVED]\n";

// Correction 2: Felicia — missing time-out on Sep 3
$fAtRow = $pdo->prepare("
    SELECT attendance_id FROM attendance_records
    WHERE employee_id=? AND attendance_date='2025-09-03' LIMIT 1
");
$fAtRow->execute([$empId[11]]);
$fAttId = $fAtRow->fetchColumn() ?: null;

$acStmt->execute([
    $fAttId, $empId[11], '2025-09-03',
    'MISSING_TIME_OUT',
    'Forgot to scan out at end of day. Supervisor can confirm I was at work until 5 PM.',
    'RESOLVED',
    'Confirmed with department head. Time-out manually recorded as 16:40.',
    '2025-09-04 07:45:00', '2025-09-05 09:00:00'
]);
echo "  ✓ Correction 2: Felicia Hernandez — MISSING_TIME_OUT 2025-09-03 [RESOLVED]\n";

// Correction 3: Olivia — disputes absent status on Apr 22 (PENDING review)
$oAtRow = $pdo->prepare("
    SELECT attendance_id FROM attendance_records
    WHERE employee_id=? AND attendance_date='2026-04-22' LIMIT 1
");
$oAtRow->execute([$empId[19]]);
$oAttId = $oAtRow->fetchColumn() ?: null;

$acStmt->execute([
    $oAttId, $empId[19], '2026-04-22',
    'WRONG_STATUS',
    'I was present at work on this date. The system may have an issue with my biometric scan. Please verify.',
    'PENDING',
    null,
    '2026-04-23 07:30:00', '2026-04-23 07:30:00'
]);
echo "  ✓ Correction 3: Olivia Quilala — WRONG_STATUS 2026-04-22 [PENDING]\n";

// ─── 20. EMPLOYEE DOCUMENTS ───────────────────────────────────────────────────
echo "\n[20] Seeding employee documents...\n";

$docStmt = $pdo->prepare("
    INSERT INTO employee_documents
        (employee_id, doc_type, doc_name, file_path, file_size, uploaded_by, uploaded_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$docs = [
    // Ruby Ann — Employment Contract
    [$empId[1],  'Employment Contract',  'Contract of Employment — Ruby Ann B. Miguel',
        'uploads/employee_docs/'.$empId[1].'/contract_ruby_miguel.pdf',    524288, $adminUid, '2025-06-02 09:00:00'],
    // Josephine (admin) — Employment Contract + Diploma
    [$empId[3],  'Employment Contract',  'Contract of Employment — Josephine M. dela Cruz',
        'uploads/employee_docs/'.$empId[3].'/contract_j_delacruz.pdf',    524288, $adminUid, '2025-06-02 09:15:00'],
    [$empId[3],  'Academic Diploma',     'Bachelor of Science in Accountancy — Diploma',
        'uploads/employee_docs/'.$empId[3].'/diploma_j_delacruz.pdf',     716800, $adminUid, '2025-06-02 09:20:00'],
    // Gloria — Maternity Leave Authorization + OB Certificate
    [$empId[5],  'Maternity Leave Authorization', 'SSS Maternity Notification — Gloria P. Aquino',
        'uploads/employee_docs/'.$empId[5].'/maternity_auth_g_aquino.pdf', 307200, $adminUid, '2026-01-10 10:00:00'],
    [$empId[5],  'Medical Certificate',  'OB-GYN Medical Certificate — Expected Delivery Jan 2026',
        'uploads/employee_docs/'.$empId[5].'/medcert_ob_g_aquino.pdf',    204800, $adminUid, '2026-01-10 10:05:00'],
    // Benjamin — Birth Certificate (for paternity leave)
    [$empId[8],  'Birth Certificate',    'PSA Birth Certificate of Child — Benjamin L. Cruz Jr.',
        'uploads/employee_docs/'.$empId[8].'/birthcert_child_b_cruz.pdf', 409600, $adminUid, '2025-10-15 14:30:00'],
    // Eduardo — Medical Certificates for sick leaves
    [$empId[10], 'Medical Certificate',  'Medical Certificate — Aug 4-8, 2025 (Fever)',
        'uploads/employee_docs/'.$empId[10].'/medcert_aug2025_e_garcia.pdf', 204800, $adminUid, '2025-08-10 08:30:00'],
    [$empId[10], 'Medical Certificate',  'Medical Certificate — Sep 1-12, 2025 (Respiratory)',
        'uploads/employee_docs/'.$empId[10].'/medcert_sep2025_e_garcia.pdf', 204800, $adminUid, '2025-09-15 08:45:00'],
];

$docCount = 0;
foreach ($docs as $d) {
    $docStmt->execute($d);
    $docCount++;
}
echo "  ✓ {$docCount} employee documents seeded\n";

// ─── 21. SCHOOL YEAR: Activate SY 2026-2027 ───────────────────────────────────
echo "\n[21] Updating school year status...\n";
$pdo->exec("UPDATE school_years SET is_active = 0");
$pdo->prepare("UPDATE school_years SET start_date='2026-05-01', is_active=1 WHERE school_year_id={$sy2627}")->execute();
echo "  ✓ SY 2025-2026 deactivated; SY 2026-2027 activated (start: 2026-05-01)\n";

// ─── SUMMARY REPORT ────────────────────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════\n";
echo "  COMPLETE DEMO SEED — Summary\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════\n";

$counts = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM employees)              AS employees,
      (SELECT COUNT(*) FROM users)                  AS users,
      (SELECT COUNT(*) FROM attendance_records)     AS attendance,
      (SELECT COUNT(*) FROM attendance_corrections) AS att_corrections,
      (SELECT COUNT(*) FROM leave_requests)         AS leaves,
      (SELECT COUNT(*) FROM employee_loans)         AS loans,
      (SELECT COUNT(*) FROM loan_payment_log)       AS loan_pay_log,
      (SELECT COUNT(*) FROM service_credits)        AS svc_credits,
      (SELECT COUNT(*) FROM employee_documents)     AS emp_documents,
      (SELECT COUNT(*) FROM payroll_periods)        AS pp_total,
      (SELECT COUNT(*) FROM payroll_periods WHERE status='RELEASED') AS pp_released,
      (SELECT COUNT(*) FROM payroll_records)        AS pr_records,
      (SELECT COUNT(*) FROM payroll_records WHERE payroll_status='RELEASED') AS pr_released,
      (SELECT COUNT(*) FROM payslips)               AS payslips,
      (SELECT year_name FROM school_years WHERE is_active=1 LIMIT 1) AS active_sy
")->fetch(PDO::FETCH_ASSOC);

foreach ($counts as $k => $v) {
    echo sprintf("  %-32s %s\n", str_replace('_',' ',ucfirst($k)).':', $v);
}

echo "\n  ── DEMO FLOW READINESS ─────────────────────────────\n";
echo "  Screen 1 (Admin)      : admin / Admin@GEI2025\n";
echo "  Screen 2 (Principal)  : r.miguel / Admin@GEI2025\n";
echo "  Active SY             : 2026-2027\n";
echo "  Live Demo Period      : May 16–31, 2026 [OPEN]\n";
echo "  Before-state Period   : May 1–15, 2026 [APPROVED, Rice ₱1,000]\n";
echo "  Live change           : Rice Subsidy ₱1,000 → ₱1,500 in payroll settings\n";
echo "\n  ── EOSY SHOWCASE (Eduardo A. Garcia / EMP-2025-010) ─\n";
echo "  Service Credits       : 8 days × ₱1,000 = ₱8,000  (+)\n";
echo "  Half-Day Settlement   : 4 incidents × ₱500 = ₱2,000  (-)\n";
echo "  Excess Leave (3 days) : 3 days × ₱1,000 = ₱3,000  (-)\n";
echo "  EOSY Net Pay          : ₱3,000\n";
echo "  ─────────────────────────────────────────────────────\n";

$eoRow = $pdo->query("
    SELECT pr.basic_pay, pr.gross_pay, pr.total_allowances, pr.total_deductions, pr.net_pay
    FROM payroll_records pr
    JOIN payroll_periods pp ON pp.period_id = pr.period_id
    WHERE pp.period_type = 'ACCRUED_PAY'
      AND pr.employee_id = {$empId[10]}
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if ($eoRow) {
    echo "  DB: gross=" . p((float)$eoRow['gross_pay'])
       . " deduct=" . p((float)$eoRow['total_deductions'])
       . " net=" . p((float)$eoRow['net_pay']) . "\n";
    $ok = abs((float)$eoRow['net_pay'] - 3000.00) < 0.01;
    echo "  Net Pay Check         : " . ($ok ? "✓ PASS (₱3,000.00)" : "✗ FAIL — got " . p((float)$eoRow['net_pay'])) . "\n";
}

echo "\n  ── NEW IN THIS SEED ─────────────────────────────────\n";
echo "  Employee numbers      : EMP-2025-001 … EMP-2025-021\n";
echo "  Part-time attendance  : Samuel = Fridays only; Teresa = Thursdays only\n";
echo "  Time variety          : Per-employee base + seconds variation\n";
echo "  Gerardo absent days   : Sep 8-10 (matches rejected sick leave request)\n";
echo "  Half-day times        : Realistic 09:05-09:20 per incident\n";
echo "  EOSY negative net_pay : Stored as true negative (no floor) for ACCRUED_PAY\n";
echo "  EOSY recovery deduct  : Auto-injected into Apr 1-15 (regular) for affected employees\n";
echo "  Loan payment log      : Backfilled for all 17 RELEASED regular periods\n";
echo "  Attendance corrections: 3 records (RESOLVED x2, PENDING x1)\n";
echo "  Employee documents    : 9 records across 6 employees\n";
echo "  ─────────────────────────────────────────────────────\n";

echo "\n  LOGIN ACCOUNTS\n";
echo "  ─────────────────────────────────────────────────────\n";
echo "  Username      Role        Password\n";
echo "  ─────────────────────────────────────────────────────\n";
echo "  admin         Admin       Admin@GEI2025\n";
echo "  treasurer     Admin       Admin@GEI2025\n";
echo "  r.miguel      Principal   Admin@GEI2025\n";
echo "  bookkeeper    Accounting  Employee@GEI2025\n";
echo "  e.garcia      Employee    Employee@GEI2025  (EOSY showcase)\n";
echo "  (all others)  Employee    Employee@GEI2025\n";
echo "\n  ✓ Seed finished at " . date('H:i:s') . "\n\n";
