<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../includes/head.php';

$dateToday = date('Y-m-d');
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'today';

// Summary counts
$present = $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date='$dateToday' AND attendance_status='PRESENT'")->fetchColumn();
$late    = $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date='$dateToday' AND attendance_status='LATE'")->fetchColumn();
$absent  = $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date='$dateToday' AND attendance_status='ABSENT'")->fetchColumn();
$leave   = $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date='$dateToday' AND attendance_status='INC'")->fetchColumn();

// TODAY tab filters
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterDate   = isset($_GET['date'])   ? $_GET['date']   : $dateToday;

$limit  = 10;
$page   = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$where  = "WHERE ar.attendance_date = :adate";
$params = [':adate' => $filterDate];
if ($search !== '') { $where .= " AND (e.first_name LIKE :s OR e.last_name LIKE :s)"; $params[':s'] = '%'.$search.'%'; }
if ($filterStatus !== '') { $where .= " AND ar.attendance_status = :st"; $params[':st'] = $filterStatus; }

$totalRecords = $pdo->prepare("SELECT COUNT(*) FROM attendance_records ar JOIN employees e ON ar.employee_id=e.employee_id $where");
$totalRecords->execute($params);
$totalRecords = $totalRecords->fetchColumn();
$totalPages   = max(1, ceil($totalRecords / $limit));

// WITH THIS:
$stmt = $pdo->prepare("
    SELECT ar.*, e.first_name, e.last_name,
           d.department_name, p.position_name
    FROM attendance_records ar
    JOIN employees e ON ar.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    $where ORDER BY e.first_name ASC LIMIT :lim OFFSET :off
");
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Departments
$depts = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();

// CUTOFF tab
$cutoffPeriod = isset($_GET['cutoff']) ? $_GET['cutoff'] : '';
$cutoffDept   = isset($_GET['cdept'])  ? $_GET['cdept']  : '';
$cutoffSearch = isset($_GET['csearch'])? trim($_GET['csearch']) : '';

// Build cutoff period options
$cutoffOptions = [];
for ($i = 0; $i < 6; $i++) {
    $ts   = strtotime("-$i months");
    $yr   = date('Y', $ts);
    $mo   = date('m', $ts);
    $last = date('t', mktime(0,0,0,$mo,1,$yr));
    $cutoffOptions[] = ['label'=>date('M',$ts)." 1 – 15, $yr",     'val'=>"$yr-$mo-01|$yr-$mo-15"];
    $cutoffOptions[] = ['label'=>date('M',$ts)." 16 – $last, $yr", 'val'=>"$yr-$mo-16|$yr-$mo-$last"];
}

if ($cutoffPeriod === '') {
    $day = (int)date('d');
    $cutoffPeriod = $day <= 15
        ? date('Y-m').'-01|'.date('Y-m').'-15'
        : date('Y-m').'-16|'.date('Y-m').'-'.date('t');
}
[$cutoffStart, $cutoffEnd] = explode('|', $cutoffPeriod) + [date('Y-m-01'), date('Y-m-15')];

$workingDays = 0;
$d = strtotime($cutoffStart);
while ($d <= strtotime($cutoffEnd)) { if (date('N',$d) < 7) $workingDays++; $d = strtotime('+1 day',$d); }

$ctWhere = "WHERE ar.attendance_date BETWEEN :cs AND :ce";
$ctP = [':cs'=>$cutoffStart, ':ce'=>$cutoffEnd];
if ($cutoffDept !== '') { $ctWhere .= " AND e.department_id=:cd"; $ctP[':cd'] = $cutoffDept; }
if ($cutoffSearch !== '') { $ctWhere .= " AND (e.first_name LIKE :csr OR e.last_name LIKE :csr)"; $ctP[':csr'] = '%'.$cutoffSearch.'%'; }

$ct = $pdo->prepare("SELECT SUM(ar.attendance_status='PRESENT') tp, SUM(ar.attendance_status='LATE') tl, SUM(ar.attendance_status='ABSENT') ta, COUNT(DISTINCT ar.employee_id) te FROM attendance_records ar JOIN employees e ON ar.employee_id=e.employee_id $ctWhere");
$ct->execute($ctP);
$ct = $ct->fetch(PDO::FETCH_ASSOC);
$avgRate = ($ct['te'] > 0 && $workingDays > 0) ? round(($ct['tp'] / ($ct['te'] * $workingDays)) * 100) : 0;

$empStmt = $pdo->prepare("SELECT e.employee_id, e.first_name, e.last_name, d.department_name,
    SUM(ar.attendance_status='PRESENT') ep, SUM(ar.attendance_status='LATE') el,
    SUM(ar.attendance_status='ABSENT') ea, SUM(ar.attendance_status='INC') ev
    FROM employees e
    LEFT JOIN attendance_records ar ON e.employee_id=ar.employee_id AND ar.attendance_date BETWEEN :cs3 AND :ce3
    LEFT JOIN departments d ON e.department_id=d.department_id
    WHERE e.employee_status='ACTIVE'
    GROUP BY e.employee_id ORDER BY e.first_name ASC");
$empStmt->execute([':cs3'=>$cutoffStart,':ce3'=>$cutoffEnd]);
$empRows = $empStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/global.css">
<link rel="stylesheet" href="../../assets/css/dashboard.css">    
<link rel="stylesheet" href="../../assets/css/attendance.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<body>
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
    <?php include __DIR__ . '/../../includes/navbar.php'; ?>
    <div class="content att-page">

        <!-- HEADER -->
        <div class="att-page-header">
            <div>
                <h2 class="att-title">Attendance Records</h2>
                <p class="att-subtitle">View, filter, and manage employee attendance</p>
            </div>
            <div class="att-header-actions">
                <button class="att-btn primary" onclick="openAttModal()">
                    <i class="fa fa-plus"></i> Add Attendance
                </button>
                <button class="att-btn outline">
                    <i class="fa fa-file-excel"></i> Export Excel
                </button>
                <button class="att-btn outline">
                    <i class="fa fa-file-pdf"></i> Export PDF
                </button>
            </div>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="att-summary-cards">
            <div class="att-sum-card green">
                <div>
                    <div class="att-sum-label">PRESENT TODAY</div>
                    <div class="att-sum-val"><?= $present ?></div>
                </div>
                <div class="att-sum-icon"><i class="fa fa-circle-check"></i></div>
            </div>
            <div class="att-sum-card yellow">
                <div>
                    <div class="att-sum-label">LATE TODAY</div>
                    <div class="att-sum-val"><?= $late ?></div>
                </div>
                <div class="att-sum-icon"><i class="fa fa-clock"></i></div>
            </div>
            <div class="att-sum-card red">
                <div>
                    <div class="att-sum-label">ABSENT TODAY</div>
                    <div class="att-sum-val"><?= $absent ?></div>
                </div>
                <div class="att-sum-icon"><i class="fa fa-circle-xmark"></i></div>
            </div>
            <div class="att-sum-card blue">
                <div>
                    <div class="att-sum-label">ON LEAVE</div>
                    <div class="att-sum-val"><?= $leave ?></div>
                </div>
                <div class="att-sum-icon"><i class="fa fa-plane-departure"></i></div>
            </div>
        </div>

        <!-- TABLE CARD -->
        <div class="att-main-card">

            <!-- TABS -->
            <div class="att-tabs-row">
                <button class="att-tab <?= $tab==='today'?'active':'' ?>" onclick="switchTab('today')">
                    Today's Attendance
                    <span class="tab-date"><?= date('M j, Y') ?></span>
                </button>
                <button class="att-tab <?= $tab==='cutoff'?'active':'' ?>" onclick="switchTab('cutoff')">
                    <i class="fa fa-calendar-days"></i> By Cutoff Period
                </button>
            </div>

            <!-- TODAY TAB -->
            <div id="tabToday" class="att-tab-pane <?= $tab!=='today'?'hidden':'' ?>">
                <form method="GET" id="todayForm">
                    <input type="hidden" name="tab" value="today">
                    <div class="att-filters">
                        <div class="att-search-box">
                            <i class="fa fa-magnifying-glass"></i>
                            <input type="text" name="search" placeholder="Search by employee name..."
                                   value="<?= htmlspecialchars($search) ?>"
                                   oninput="debounce(()=>document.getElementById('todayForm').submit(), 400)">
                        </div>
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="PRESENT" <?= $filterStatus==='PRESENT'?'selected':'' ?>>Present</option>
                            <option value="LATE"    <?= $filterStatus==='LATE'   ?'selected':'' ?>>Late</option>
                            <option value="ABSENT"  <?= $filterStatus==='ABSENT' ?'selected':'' ?>>Absent</option>
                            <option value="INC"     <?= $filterStatus==='INC'    ?'selected':'' ?>>On Leave</option>
                        </select>
                        <button type="submit" class="att-btn filter-btn">
                            <i class="fa fa-sliders"></i> Filters
                        </button>
                    </div>
                </form>

                <table class="att-table">
                    <thead>
                        <tr>
                            <th>Employee <i class="fa fa-sort fa-xs"></i></th>
                            <th>Department <i class="fa fa-sort fa-xs"></i></th>
                            <th>Role</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status <i class="fa fa-sort fa-xs"></i></th>
                            <th>Method</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($rows)): ?>
                        <tr><td colspan="8" class="att-no-data">No attendance records for this date.</td></tr>
                        <?php else: foreach($rows as $r):
                            $st = strtolower($r['attendance_status']);
                            $stLabel = ['present'=>'Present','late'=>'Late','absent'=>'Absent','inc'=>'On Leave'][$st] ?? ucfirst($st);
                            $tin  = $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '—';
                            $tout = $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—';
                        ?>
                        <tr>
                            <td>
                                <div class="att-emp-cell">
                                    <div class="att-av"><?= strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1)) ?></div>
                                    <span><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></span>
                                </div>
                            </td>
                            <td><?= $r['department_name'] ? '<span class="att-dept-tag">'.htmlspecialchars($r['department_name']).'</span>' : '—' ?></td>
                            <td><?= $r['position_name'] ? '<span class="att-role-tag">'.htmlspecialchars($r['position_name']).'</span>' : '—' ?></td>
                            <td><span class="att-time <?= $st==='late'?'late':($st==='present'?'ok':'') ?>"><?= $tin ?></span></td>
                            <td><?= $tout ?></td>
                            <td><span class="att-badge <?= $st ?>"><?= $stLabel ?></span></td>
                            <td><?= htmlspecialchars($r['attendance_source'] ?? 'Manual') ?></td>
                            <td>
                                <button class="att-action-btn"
                                    onclick="openEditModal('<?= $r['attendance_id'] ?>','<?= addslashes($r['first_name'].' '.$r['last_name']) ?>','<?= $r['attendance_date'] ?>','<?= $r['time_in'] ?>','<?= $r['time_out'] ?>','<?= $r['attendance_status'] ?>','<?= addslashes($r['remarks']??'') ?>')">
                                    <i class="fa fa-pen-to-square"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <div class="att-pagination">
                    <span>Showing <?= $totalRecords>0?$offset+1:0 ?>–<?= min($offset+$limit,$totalRecords) ?> of <?= $totalRecords ?> records</span>
                    <div class="att-pg-btns">
                        <a class="att-pg <?= $page<=1?'disabled':'' ?>"
                           href="?tab=today&page=<?= max(1,$page-1) ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>">Previous</a>
                        <?php for($i=1;$i<=$totalPages;$i++): ?>
                        <a class="att-pg <?= $i==$page?'active':'' ?>"
                           href="?tab=today&page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <a class="att-pg <?= $page>=$totalPages?'disabled':'' ?>"
                           href="?tab=today&page=<?= min($totalPages,$page+1) ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>">Next</a>
                    </div>
                </div>
            </div>

            <!-- CUTOFF TAB -->
            <div id="tabCutoff" class="att-tab-pane <?= $tab!=='cutoff'?'hidden':'' ?>">
                <form method="GET" id="cutoffForm">
                    <input type="hidden" name="tab" value="cutoff">
                    <div class="att-filters">
                        <div class="att-cutoff-sel-wrap">
                            <i class="fa fa-calendar-days"></i>
                            <select name="cutoff" onchange="this.form.submit()">
                                <?php foreach($cutoffOptions as $co): ?>
                                <option value="<?= $co['val'] ?>" <?= $cutoffPeriod===$co['val']?'selected':'' ?>>
                                    <?= $co['label'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <span class="att-period-closed">Closed</span>
                        <div class="att-search-box">
                            <i class="fa fa-magnifying-glass"></i>
                            <input type="text" name="csearch" placeholder="Search employee..."
                                   value="<?= htmlspecialchars($cutoffSearch) ?>">
                        </div>
                        <select name="cdept" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach($depts as $d): ?>
                            <option value="<?= $d['department_id'] ?>" <?= $cutoffDept==$d['department_id']?'selected':'' ?>>
                                <?= htmlspecialchars($d['department_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <!-- Cutoff mini stats -->
                <div class="att-cutoff-stats">
                    <div class="co-stat"><div class="co-label">WORKING DAYS</div><div class="co-val"><?= $workingDays ?></div></div>
                    <div class="co-stat green"><div class="co-label">TOTAL PRESENT</div><div class="co-val"><?= $ct['tp']??0 ?></div></div>
                    <div class="co-stat yellow"><div class="co-label">TOTAL LATE</div><div class="co-val"><?= $ct['tl']??0 ?></div></div>
                    <div class="co-stat red"><div class="co-label">TOTAL ABSENT</div><div class="co-val"><?= $ct['ta']??0 ?></div></div>
                    <div class="co-stat blue"><div class="co-label">AVG ATTENDANCE</div><div class="co-val"><?= $avgRate ?>%</div></div>
                </div>

                <p class="att-period-note">
                    <i class="fa fa-circle-info"></i>
                    Cutoff period: <strong><?= date('M j', strtotime($cutoffStart)) ?> – <?= date('M j, Y', strtotime($cutoffEnd)) ?></strong>
                    &nbsp;·&nbsp; <?= $workingDays ?> working days
                    &nbsp;·&nbsp; Showing <?= count($empRows) ?> employees
                </p>

                <table class="att-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th class="th-present">Present</th>
                            <th class="th-late">Late</th>
                            <th class="th-absent">Absent</th>
                            <th class="th-leave">On Leave</th>
                            <th>Attendance Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($empRows as $er):
                            $total = max($workingDays, 1);
                            $rate  = min(100, round((($er['ep']+$er['el']) / $total) * 100));
                            $rc    = $rate >= 90 ? 'rc-green' : ($rate >= 75 ? 'rc-yellow' : 'rc-red');
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($er['first_name'].' '.$er['last_name']) ?></strong></td>
                            <td><?= htmlspecialchars($er['department_name']??'—') ?></td>
                            <td><span class="co-num green"><?= (int)$er['ep'] ?></span></td>
                            <td><span class="co-num yellow"><?= (int)$er['el'] ?></span></td>
                            <td><span class="co-num red"><?= (int)$er['ea'] ?></span></td>
                            <td><span class="co-num blue"><?= (int)$er['ev'] ?></span></td>
                            <td>
                                <div class="att-rate-row">
                                    <div class="att-rate-bar"><div class="att-rate-fill <?= $rc ?>" style="width:<?= $rate ?>%"></div></div>
                                    <span><?= $rate ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/modals/add-attendance-modal.php'; ?>
<?php include __DIR__ . '/modals/edit-attendance-modal.php'; ?>
<script src="/gei-payroll-system/assets/js/attendance.js"></script>
</body>