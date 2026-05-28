<?php
/**
 * modules/employee/leave/index.php
 * Employee Portal — Leave Balance, History, and File Leave
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployeeAccess();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

// ── Migration guards ──────────────────────────────────────────────────────────
$hasMig016    = (bool)$pdo->query("SHOW COLUMNS FROM `leave_requests` LIKE 'workflow_status'")->fetch();
$hasBackdated = (bool)$pdo->query("SHOW COLUMNS FROM `leave_types` LIKE 'allow_backdated'")->fetch();
$hasMig017    = (bool)$pdo->query("SHOW COLUMNS FROM `leave_types` LIKE 'allow_future'")->fetch();

// ── Leave Balance ─────────────────────────────────────────────────────────────
$leaveCredits     = [];
$totalAllocated   = 0;
$totalUsed        = 0;
$leaveBalance     = 0;
$usesCreditSystem = false;
$activeSYLabel    = '';

if ($empId) {
    $syRow = $pdo->query("SELECT school_year_id, year_name FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
    $activeSYId    = $syRow ? (int)$syRow['school_year_id'] : 0;
    $activeSYLabel = $syRow['year_name'] ?? '';

    if ($activeSYId) {
        $stmt = $pdo->prepare("
            SELECT elc.allocated_days, elc.used_days, lt.leave_name, lt.leave_type_id
            FROM employee_leave_credits elc
            JOIN leave_types lt ON elc.leave_type_id = lt.leave_type_id
            WHERE elc.employee_id = ? AND elc.school_year_id = ?
            ORDER BY lt.leave_name
        ");
        $stmt->execute([$empId, $activeSYId]);
        $leaveCredits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($leaveCredits)) {
            $totalAllocated   = array_sum(array_column($leaveCredits, 'allocated_days'));
            $totalUsed        = array_sum(array_column($leaveCredits, 'used_days'));
            $leaveBalance     = $totalAllocated - $totalUsed;
            $usesCreditSystem = true;
        }
    }

    if (!$usesCreditSystem) {
        $settings       = $pdo->query("SELECT default_paid_leave_days FROM payroll_settings LIMIT 1")->fetch();
        $totalAllocated = (float)($settings['default_paid_leave_days'] ?? 30);
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_days),0) FROM leave_requests WHERE employee_id=? AND status='APPROVED'");
        $stmt->execute([$empId]);
        $totalUsed    = (float)$stmt->fetchColumn();
        $leaveBalance = $totalAllocated - $totalUsed;
    }
}

// ── Summary counts ────────────────────────────────────────────────────────────
$cPending  = 0;
$cApproved = 0;
$cRejected = 0;
if ($empId) {
    $stmtC = $pdo->prepare("
        SELECT
            SUM(status='PENDING')  AS pending,
            SUM(status='APPROVED') AS approved,
            SUM(status='REJECTED') AS rejected
        FROM leave_requests WHERE employee_id = ? AND school_year_id = ?
    ");
    $stmtC->execute([$empId, $activeSYId]);
    $counts    = $stmtC->fetch();
    $cPending  = (int)($counts['pending']  ?? 0);
    $cApproved = (int)($counts['approved'] ?? 0);
    $cRejected = (int)($counts['rejected'] ?? 0);
}

// ── Leave Request History ─────────────────────────────────────────────────────
$history = [];
if ($empId) {
    $wfSel = $hasMig016 ? ', lr.workflow_status, lr.is_backdated' : ", 'PENDING_REVIEW' AS workflow_status, 0 AS is_backdated";
    $fmSel = (bool)$pdo->query("SHOW COLUMNS FROM `leave_requests` LIKE 'filing_mode'")->fetch()
             ? ', lr.filing_mode' : ", 'MULTIPLE' AS filing_mode";

    $stmt = $pdo->prepare("
        SELECT lr.leave_id, lr.created_at, lt.leave_name, lr.status, lr.reason
               {$wfSel} {$fmSel},
               COUNT(lrd.date_id)                        AS total_dates,
               MIN(lrd.leave_date)                       AS first_date,
               MAX(lrd.leave_date)                       AS last_date,
               COALESCE(SUM(lrd.status='APPROVED'), 0)   AS approved,
               COALESCE(SUM(lrd.status='PENDING'),  0)   AS pending,
               COALESCE(SUM(lrd.status='REJECTED'), 0)   AS rejected
        FROM leave_requests lr
        JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
        JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
        WHERE lr.employee_id = ?
        GROUP BY lr.leave_id
        ORDER BY lr.created_at DESC
    ");
    $stmt->execute([$empId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Leave types for file modal ────────────────────────────────────────────────
if ($hasBackdated && $hasMig017) {
    $ltSel = "SELECT leave_type_id, leave_name, allow_backdated, allow_future FROM leave_types ORDER BY leave_name";
} elseif ($hasBackdated) {
    // Derive allow_future: if allow_backdated=1 (sick/emergency) then allow_future=0
    $ltSel = "SELECT leave_type_id, leave_name, allow_backdated, IF(allow_backdated=1,0,1) AS allow_future FROM leave_types ORDER BY leave_name";
} else {
    $ltSel = "SELECT leave_type_id, leave_name, 0 AS allow_backdated, 1 AS allow_future FROM leave_types ORDER BY leave_name";
}
$leaveTypesAll = $pdo->query($ltSel)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Leave — Employee Portal';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php
if (isAdmin()):
    include __DIR__ . '/../../../includes/sidebar.php';
elseif (isPrincipalRole()):
    include __DIR__ . '/../../../includes/principal-sidebar.php';
else:
    include __DIR__ . '/../../../includes/employee-sidebar.php';
endif;
?>

<div class="main">
  <?php $empPortalIcon = 'fa-calendar-days'; include __DIR__ . '/../../../includes/employee-header.php'; ?>

  <div class="main-content">
  <div class="emp-page">

    <!-- Page Header -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;gap:12px;flex-wrap:wrap;">
      <div>
        <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 4px;">My Leave</h1>
        <p style="font-size:12px;color:#94a3b8;margin:0;">
          <?= $usesCreditSystem && $activeSYLabel
              ? 'School Year: <strong>' . htmlspecialchars($activeSYLabel) . '</strong>'
              : 'Leave allocation for current period' ?>
        </p>
      </div>
      <button class="emp-btn-file-leave" onclick="openFileLeaveModal()">
        <i class="fa fa-plus"></i> File a Leave
      </button>
    </div>

    <!-- KPI Cards -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;">
      <div class="emp-panel" style="padding:18px 20px;background:linear-gradient(135deg,#e6f7f4,#b2e8df);">
        <div style="font-size:11px;font-weight:600;color:#065f46;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">Leave Balance</div>
        <div style="font-size:28px;font-weight:800;color:<?= $leaveBalance < 0 ? '#dc2626' : '#1db89a' ?>;"><?= number_format(max(0,$leaveBalance),1) ?></div>
        <div style="font-size:11px;color:#64748b;margin-top:2px;"><?= number_format($totalUsed,1) ?> used of <?= number_format($totalAllocated,1) ?> allocated</div>
      </div>
      <div class="emp-panel" style="padding:18px 20px;">
        <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">Pending</div>
        <div style="font-size:28px;font-weight:800;color:#d97706;"><?= $cPending ?></div>
        <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Awaiting decision</div>
      </div>
      <div class="emp-panel" style="padding:18px 20px;">
        <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">Approved</div>
        <div style="font-size:28px;font-weight:800;color:#059669;"><?= $cApproved ?></div>
        <div style="font-size:11px;color:#94a3b8;margin-top:2px;">This school year</div>
      </div>
      <div class="emp-panel" style="padding:18px 20px;">
        <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">Rejected</div>
        <div style="font-size:28px;font-weight:800;color:#dc2626;"><?= $cRejected ?></div>
        <div style="font-size:11px;color:#94a3b8;margin-top:2px;">This school year</div>
      </div>
    </div>

    <!-- Leave Credits Balance -->
    <?php if (!empty($leaveCredits) || $totalAllocated > 0): ?>
    <div class="emp-panel" style="margin-bottom:16px;">
      <div class="emp-panel-header">
        <div class="emp-panel-title"><i class="fa fa-calendar-check" style="color:#059669;"></i> Leave Credits</div>
        <div style="font-size:12px;font-weight:700;color:#374151;">
          <?= number_format(max(0,$leaveBalance),1) ?> day<?= $leaveBalance!=1?'s':'' ?> remaining
        </div>
      </div>
      <?php if (!empty($leaveCredits)): ?>
      <div class="emp-leave-list">
        <?php foreach ($leaveCredits as $lc):
            $alloc  = (float)$lc['allocated_days'];
            $used   = (float)$lc['used_days'];
            $remain = max(0, $alloc - $used);
            $pct    = $alloc > 0 ? min(100, round(($used/$alloc)*100)) : 0;
            $cls    = $remain <= 0 ? 'zero' : ($remain <= 3 ? 'low' : '');
        ?>
        <div class="emp-leave-row">
          <div class="emp-leave-name"><?= htmlspecialchars($lc['leave_name']) ?></div>
          <div class="emp-leave-used" style="white-space:nowrap;"><?= number_format($used,1) ?> used / <?= number_format($alloc,1) ?> alloc.</div>
          <div class="emp-leave-bar-wrap" style="width:80px;">
            <div class="emp-leave-bar <?= $cls?'emp-leave-bar--'.$cls:'' ?>" style="width:<?= $pct ?>%;"></div>
          </div>
          <div class="emp-leave-balance <?= $cls?'emp-leave-balance--'.$cls:'' ?>"><?= number_format($remain,1) ?> left</div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <?php $pct = $totalAllocated>0?min(100,round(($totalUsed/$totalAllocated)*100)):0; ?>
      <div class="emp-leave-list">
        <div class="emp-leave-row">
          <div class="emp-leave-name">Paid Leave</div>
          <div class="emp-leave-used"><?= number_format($totalUsed,1) ?> used / <?= number_format($totalAllocated,1) ?> alloc.</div>
          <div class="emp-leave-bar-wrap" style="width:80px;"><div class="emp-leave-bar" style="width:<?= $pct ?>%;"></div></div>
          <div class="emp-leave-balance"><?= number_format(max(0,$leaveBalance),1) ?> left</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Leave History Table -->
    <div class="emp-panel" style="padding:0;overflow:hidden;">
      <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;">
        <i class="fa fa-clock-rotate-left" style="color:#6366f1;"></i>
        <span style="font-size:13px;font-weight:700;color:#0f172a;">Leave Request History</span>
        <span style="margin-left:auto;font-size:11px;color:#94a3b8;"><?= count($history) ?> request<?= count($history)!==1?'s':'' ?></span>
      </div>

      <?php if (!empty($history)): ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
            <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Leave Type</th>
            <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Date(s)</th>
            <th style="padding:10px 8px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Days</th>
            <th style="padding:10px 8px;text-align:center;font-size:11px;font-weight:700;color:#059669;text-transform:uppercase;letter-spacing:.04em;">Approved</th>
            <th style="padding:10px 8px;text-align:center;font-size:11px;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.04em;">Pending</th>
            <th style="padding:10px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Status</th>
            <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Filed</th>
            <th style="padding:10px 16px;"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $hr):
            $total    = (int)$hr['total_dates'];
            $approved = (int)$hr['approved'];
            $pending  = (int)$hr['pending'];
            $rejected = (int)$hr['rejected'];
            $wfStatus = $hr['workflow_status'] ?? 'PENDING_REVIEW';
            $isBack   = !empty($hr['is_backdated']);
            $fMode    = $hr['filing_mode'] ?? 'MULTIPLE';

            if ($pending > 0 && ($approved > 0 || $rejected > 0)) { $statusKey = 'partial'; $statusText = 'Partial'; }
            elseif ($pending > 0) { $statusKey = 'pending'; $statusText = 'Pending'; }
            elseif ($approved === $total && $total > 0) { $statusKey = 'approved'; $statusText = 'Approved'; }
            elseif ($rejected === $total && $total > 0) { $statusKey = 'rejected'; $statusText = 'Rejected'; }
            else { $statusKey = 'partial'; $statusText = 'Mixed'; }

            $wfLabel = '';
            if ($hasMig016) {
                $wfLabel = match($wfStatus) {
                    'PENDING_REVIEW' => '<span style="font-size:10px;color:#92400e;background:#fef3c7;padding:2px 7px;border-radius:99px;">Under Admin Review</span>',
                    'FORWARDED'      => '<span style="font-size:10px;color:#065f46;background:#d1fae5;padding:2px 7px;border-radius:99px;">With Principal</span>',
                    'RECORDED'       => '<span style="font-size:10px;color:#065f46;background:#d1fae5;padding:2px 7px;border-radius:99px;">Finalised</span>',
                    default          => '',
                };
            }

            $fModeLabel = ['SINGLE'=>'Single','MULTIPLE'=>'Multiple','RANGE'=>'Range'][$fMode] ?? $fMode;

            $dateRange = $hr['first_date'] === $hr['last_date']
                ? date('M j, Y', strtotime($hr['first_date']))
                : date('M j', strtotime($hr['first_date'])) . ' – ' . date('M j, Y', strtotime($hr['last_date']));
        ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:13px 16px;font-weight:600;color:#0f172a;">
            <?= htmlspecialchars($hr['leave_name']) ?>
            <?php if ($isBack): ?>
              <span style="font-size:10px;background:#ede9fe;color:#6d28d9;padding:2px 6px;border-radius:99px;margin-left:4px;font-weight:500;">Backdated</span>
            <?php endif; ?>
            <span style="font-size:10px;background:#f1f5f9;color:#64748b;padding:2px 6px;border-radius:99px;margin-left:3px;font-weight:500;"><?= $fModeLabel ?></span>
          </td>
          <td style="padding:13px 16px;color:#374151;"><?= htmlspecialchars($dateRange) ?></td>
          <td style="padding:13px 8px;text-align:center;font-weight:700;color:#374151;"><?= $total ?></td>
          <td style="padding:13px 8px;text-align:center;font-weight:700;color:#059669;"><?= $approved ?></td>
          <td style="padding:13px 8px;text-align:center;font-weight:700;color:#d97706;"><?= $pending ?></td>
          <td style="padding:13px 16px;text-align:center;">
            <span class="emp-badge emp-badge--<?= $statusKey ?>"><?= htmlspecialchars($statusText) ?></span>
            <?php if ($wfLabel): ?><br><span style="margin-top:3px;display:inline-block;"><?= $wfLabel ?></span><?php endif; ?>
          </td>
          <td style="padding:13px 16px;color:#64748b;font-size:12px;"><?= date('M j, Y', strtotime($hr['created_at'])) ?></td>
          <td style="padding:13px 16px;">
            <button class="emp-btn-view-leave" onclick="openEmpLeaveDetail(<?= $hr['leave_id'] ?>)">
              <i class="fa fa-eye"></i> Details
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php else: ?>
      <div class="emp-empty" style="padding:50px 20px;">
        <i class="fa fa-calendar-days"></i>
        <p>No leave requests yet. Use "File a Leave" to submit your first request.</p>
      </div>
      <?php endif; ?>
    </div>

  </div>
  </div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     FILE LEAVE MODAL
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="emp-modal-overlay" id="fileLeaveOverlay" style="display:none;" onclick="closeFileLeave(event)">
  <div class="emp-modal-box emp-modal-box--md" onclick="event.stopPropagation()">

    <div class="emp-modal-header">
      <h3 class="emp-modal-title"><i class="fa fa-calendar-plus" style="color:var(--accent);margin-right:7px;"></i>File a Leave Request</h3>
      <button class="emp-modal-close" onclick="closeFileLeaveModal()">×</button>
    </div>

    <div class="emp-modal-body">

      <!-- Leave Type -->
      <div class="emp-form-group">
        <label class="emp-form-label">Leave Type <span style="color:#ef4444;">*</span></label>
        <select class="emp-form-control" id="empLeaveTypeSelect" onchange="onLeaveTypeChange()">
          <option value="">Select leave type…</option>
          <?php foreach ($leaveTypesAll as $lt): ?>
            <option value="<?= $lt['leave_type_id'] ?>"
                    data-backdated="<?= (int)$lt['allow_backdated'] ?>"
                    data-allow-future="<?= (int)$lt['allow_future'] ?>">
              <?= htmlspecialchars($lt['leave_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Filing Mode Selector -->
      <div class="emp-form-group">
        <label class="emp-form-label">Filing Mode <span style="color:#ef4444;">*</span></label>
        <div class="emp-mode-selector" id="empModeSelector">
          <button type="button" class="emp-mode-btn emp-mode-btn--active" data-mode="SINGLE"
                  onclick="onFilingModeChange('SINGLE')">
            <i class="fa fa-calendar-day"></i> Single Date
          </button>
          <button type="button" class="emp-mode-btn" data-mode="MULTIPLE"
                  onclick="onFilingModeChange('MULTIPLE')">
            <i class="fa fa-calendar-week"></i> Multiple Dates
          </button>
          <button type="button" class="emp-mode-btn" data-mode="RANGE"
                  onclick="onFilingModeChange('RANGE')">
            <i class="fa fa-calendar-range"></i> Date Range
          </button>
        </div>
        <div id="empModeDesc" class="emp-mode-desc">Select one specific date for this leave request.</div>
      </div>

      <!-- Future date warning (shown when leave type blocks future) -->
      <div id="empFutureWarning" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:9px 13px;font-size:12px;color:#991b1b;margin-bottom:12px;">
        <i class="fa fa-ban"></i>
        <strong>Future dates are not allowed for this leave type.</strong>
        You may only select today or earlier dates.
      </div>

      <!-- Backdated info banner -->
      <div id="empBackdatedInfo" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:9px 13px;font-size:12px;color:#15803d;margin-bottom:12px;">
        <i class="fa fa-circle-info"></i>
        <strong>Backdated filing allowed</strong> — you may select past dates.
        A written explanation will be required.
      </div>

      <!-- CALENDAR SECTION (Single + Multiple modes) -->
      <div id="empCalSection" class="emp-form-group">
        <label class="emp-form-label">
          Select Date(s)
          <span id="empCalHint" style="font-size:11px;color:#94a3b8;font-weight:400;">(click a date)</span>
        </label>
        <div class="emp-cal-wrap">
          <div class="emp-cal-nav">
            <span class="emp-cal-month" id="empCalMonth">—</span>
            <div>
              <button type="button" class="emp-cal-btn" id="empCalPrev">&#8249;</button>
              <button type="button" class="emp-cal-btn" id="empCalNext">&#8250;</button>
            </div>
          </div>
          <table class="emp-cal-table" id="empCalGrid">
            <thead><tr><th>SU</th><th>MO</th><th>TU</th><th>WE</th><th>TH</th><th>FR</th><th>SA</th></tr></thead>
            <tbody id="empCalBody"></tbody>
          </table>
          <p style="font-size:11px;color:#94a3b8;margin:6px 0 0 0;padding:0 8px 8px;">
            <span id="empCalNote">Click a date to select it.</span>
          </p>
        </div>
        <div id="empSelectedTags" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;"></div>
      </div>

      <!-- RANGE SECTION (Date Range mode) -->
      <div id="empRangeSection" style="display:none;" class="emp-form-group">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label class="emp-form-label">From Date <span style="color:#ef4444;">*</span></label>
            <input type="date" class="emp-form-control" id="empRangeFrom" onchange="onRangeChange()">
          </div>
          <div>
            <label class="emp-form-label">To Date <span style="color:#ef4444;">*</span></label>
            <input type="date" class="emp-form-control" id="empRangeTo" onchange="onRangeChange()">
          </div>
        </div>
        <div id="empRangeError" style="display:none;color:#dc2626;font-size:12px;margin-top:6px;padding:7px 11px;background:#fef2f2;border-radius:7px;border:1px solid #fca5a5;"></div>
        <div id="empRangePreview" style="display:none;margin-top:10px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;"></div>
      </div>

      <!-- Backdated Reason -->
      <div id="empBackdatedSection" style="display:none;">
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:10px;">
          <i class="fa fa-triangle-exclamation"></i>
          <strong>You have selected past date(s).</strong>
          Please explain why this leave is being filed after the date(s) occurred.
        </div>
        <div class="emp-form-group">
          <label class="emp-form-label">Reason for Late Filing <span style="color:#ef4444;">*</span></label>
          <textarea class="emp-form-control" id="empBackdateReason" rows="2"
              placeholder="e.g. I was hospitalized and unable to file on time."></textarea>
        </div>
      </div>

      <!-- Reason -->
      <div class="emp-form-group">
        <label class="emp-form-label">Reason / Description <span style="color:#ef4444;">*</span></label>
        <textarea class="emp-form-control" id="empLeaveReason" rows="3"
            placeholder="Describe the reason for your leave request…"></textarea>
      </div>

      <!-- Attachment -->
      <div class="emp-form-group">
        <label class="emp-form-label">
          Supporting Document
          <span style="font-size:11px;color:#9ca3af;font-weight:400;">(optional — required for Sick Leave)</span>
        </label>
        <div class="emp-file-area" id="empFileArea"
             onclick="document.getElementById('empFileInput').click()"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleEmpFileDrop(event)">
          <i class="fa fa-file-arrow-up" style="font-size:22px;color:#94a3b8;margin-bottom:4px;"></i>
          <p style="font-size:12px;color:#6b7280;margin:0;">Click or drag to upload <small style="display:block;color:#9ca3af;">PDF, JPG, PNG — max 5 MB</small></p>
          <input type="file" id="empFileInput" accept=".pdf,.jpg,.jpeg,.png" style="display:none"
                 onchange="onEmpFileSelected(this)">
        </div>
        <div id="empFilePreview" style="display:none;margin-top:8px;padding:9px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;align-items:center;gap:10px;font-size:13px;">
          <i class="fa fa-file" style="color:var(--accent);font-size:16px;"></i>
          <span id="empFileName" style="flex:1;">—</span>
          <button type="button" onclick="clearEmpFile()" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:12px;"><i class="fa fa-times"></i> Remove</button>
        </div>
      </div>

    </div>

    <div class="emp-modal-footer">
      <button type="button" class="emp-btn-ghost" onclick="closeFileLeaveModal()">Cancel</button>
      <button type="button" class="emp-btn-primary" id="empBtnSubmitLeave" onclick="submitEmpLeave()">
        <i class="fa fa-calendar-check"></i> Submit Request
      </button>
    </div>

  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     LEAVE DETAILS VIEW MODAL
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="emp-modal-overlay" id="empLeaveDetailOverlay" style="display:none;"
     onclick="if(event.target===this)closeEmpLeaveDetail()">
  <div class="emp-modal-box emp-modal-box--lg" onclick="event.stopPropagation()">

    <div class="emp-modal-header">
      <h3 class="emp-modal-title"><i class="fa fa-calendar-days" style="color:var(--accent);margin-right:7px;"></i>Leave Request Details</h3>
      <button class="emp-modal-close" onclick="closeEmpLeaveDetail()">×</button>
    </div>

    <div class="emp-modal-body" id="empLeaveDetailBody">
      <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-lg"></i></div>
    </div>

    <div class="emp-modal-footer">
      <button class="emp-btn-ghost" onclick="closeEmpLeaveDetail()">Close</button>
    </div>

  </div>
</div>

<style>
/* ── Employee leave-specific styles ──────────────────────── */
.emp-btn-file-leave {
    display:inline-flex;align-items:center;gap:7px;
    background:var(--accent);color:#fff;border:none;border-radius:9px;
    padding:10px 20px;font-size:13px;font-weight:600;cursor:pointer;
    font-family:inherit;transition:background .15s;white-space:nowrap;
}
.emp-btn-file-leave:hover { background:#17a085; }
.emp-btn-view-leave {
    display:inline-flex;align-items:center;gap:5px;padding:6px 12px;
    background:var(--accent-light);color:var(--accent);border:1px solid var(--accent-mid);
    border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;
    font-family:inherit;transition:background .15s;
}
.emp-btn-view-leave:hover { background:var(--accent-mid); }

/* Modals */
.emp-modal-overlay {
    position:fixed;inset:0;background:rgba(0,0,0,.45);
    display:none;align-items:center;justify-content:center;z-index:1000;padding:20px;
}
.emp-modal-box {
    background:#fff;border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.18);
    max-height:90vh;overflow-y:auto;display:flex;flex-direction:column;
    width:100%;animation:empModalIn .2s ease;
}
.emp-modal-box--md { max-width:600px; }
.emp-modal-box--lg { max-width:760px; }
@keyframes empModalIn { from{opacity:0;transform:scale(.97) translateY(8px)} to{opacity:1;transform:scale(1) translateY(0)} }
.emp-modal-header {
    display:flex;align-items:center;justify-content:space-between;
    padding:16px 22px;border-bottom:1px solid #f1f5f9;
    position:sticky;top:0;background:#fff;z-index:2;
}
.emp-modal-title { font-size:15px;font-weight:700;color:#0f172a;margin:0; }
.emp-modal-close { background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;line-height:1; }
.emp-modal-close:hover { color:#374151; }
.emp-modal-body { padding:20px 22px;flex:1; }
.emp-modal-footer {
    padding:14px 22px;border-top:1px solid #f1f5f9;
    display:flex;align-items:center;justify-content:flex-end;gap:10px;
    background:#fafafa;border-radius:0 0 14px 14px;
}

/* Form */
.emp-form-group { margin-bottom:14px; }
.emp-form-label { display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px; }
.emp-form-control {
    width:100%;padding:9px 12px;font-size:13px;font-family:inherit;
    border:1.5px solid #e2e8f0;border-radius:8px;outline:none;color:#334155;
    transition:border-color .15s;resize:vertical;box-sizing:border-box;
}
.emp-form-control:focus { border-color:var(--accent); }

/* Buttons */
.emp-btn-ghost {
    display:inline-flex;align-items:center;gap:6px;padding:9px 18px;
    border:1px solid #e2e8f0;background:#fff;color:#64748b;border-radius:8px;
    font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s;
}
.emp-btn-ghost:hover { background:#f8fafc; }
.emp-btn-primary {
    display:inline-flex;align-items:center;gap:6px;padding:9px 18px;
    background:var(--accent);color:#fff;border:none;border-radius:8px;
    font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s;
}
.emp-btn-primary:hover { background:#17a085; }
.emp-btn-primary:disabled { background:#6ee7d4;cursor:not-allowed; }

/* Filing Mode Selector */
.emp-mode-selector {
    display:flex;gap:0;border:1.5px solid #e2e8f0;border-radius:9px;overflow:hidden;
}
.emp-mode-btn {
    flex:1;padding:9px 8px;background:#f8fafc;border:none;cursor:pointer;
    font-size:12px;font-weight:600;color:#64748b;font-family:inherit;
    transition:background .15s,color .15s;display:flex;align-items:center;
    justify-content:center;gap:5px;border-right:1px solid #e2e8f0;
}
.emp-mode-btn:last-child { border-right:none; }
.emp-mode-btn:hover { background:var(--accent-light);color:var(--accent); }
.emp-mode-btn--active { background:var(--accent);color:#fff; }
.emp-mode-btn--active:hover { background:#17a085;color:#fff; }
.emp-mode-desc { font-size:11px;color:#64748b;margin-top:5px; }

/* Calendar */
.emp-cal-wrap { border:1px solid #e2e8f0;border-radius:10px;overflow:hidden; }
.emp-cal-nav {
    display:flex;align-items:center;justify-content:space-between;
    padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;
}
.emp-cal-month { font-size:13px;font-weight:700;color:#0f172a; }
.emp-cal-btn {
    background:none;border:1px solid #e2e8f0;border-radius:6px;
    padding:4px 9px;font-size:14px;cursor:pointer;color:#475569;line-height:1;transition:background .15s;
}
.emp-cal-btn:hover { background:#e2e8f0; }
.emp-cal-table { width:100%;border-collapse:collapse; }
.emp-cal-table th {
    padding:7px 4px;text-align:center;font-size:11px;font-weight:700;
    color:#94a3b8;background:#f8fafc;border-bottom:1px solid #e2e8f0;
}
.emp-cal-table td { padding:2px; }
.emp-cal-day {
    display:flex;align-items:center;justify-content:center;
    width:34px;height:34px;border-radius:8px;margin:auto;
    font-size:13px;font-weight:500;cursor:pointer;
    transition:background .1s,color .1s;color:#374151;
}
.emp-cal-day:hover { background:var(--accent-light);color:var(--accent); }
.emp-cal-day--today    { background:var(--accent-light);color:var(--accent);font-weight:700; }
.emp-cal-day--selected { background:var(--accent);color:#fff;font-weight:700; }
.emp-cal-day--selected:hover { background:#17a085; }
/* Past dates: allowed but neutral color */
.emp-cal-day--past     { color:#94a3b8; }
.emp-cal-day--past:hover { background:#f1f5f9;color:#475569; }
/* Past + backdatable: slightly stronger color to indicate they're selectable */
.emp-cal-day--backdatable { color:#374151;cursor:pointer; }
.emp-cal-day--backdatable:hover { background:var(--accent-light);color:var(--accent); }
/* Future dates that are BLOCKED (Sick/Emergency leave) */
.emp-cal-day--disabled {
    color:#e2e8f0 !important;cursor:not-allowed !important;
    background:none !important;
}
.emp-cal-day--empty    { cursor:default;color:transparent; }

/* Selected date tags */
.emp-selected-tag {
    display:inline-flex;align-items:center;gap:5px;padding:4px 10px;
    background:var(--accent-light);border:1px solid var(--accent-mid);border-radius:99px;
    font-size:12px;font-weight:500;color:var(--accent);
}
.emp-selected-tag button {
    background:none;border:none;cursor:pointer;color:var(--accent-mid);font-size:13px;line-height:1;padding:0;
}
.emp-selected-tag button:hover { color:var(--accent); }

/* Range preview tags */
.emp-range-tag {
    display:inline-block;padding:3px 9px;background:var(--accent-light);border:1px solid var(--accent-mid);
    border-radius:99px;font-size:11px;font-weight:500;color:var(--accent);
}
.emp-range-tag--past { background:#fef3c7;border-color:#fde68a;color:#92400e; }

/* File upload area */
.emp-file-area {
    border:1.5px dashed #e2e8f0;border-radius:8px;padding:18px;text-align:center;
    cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;
    transition:border-color .15s,background .15s;
}
.emp-file-area:hover { border-color:var(--accent);background:var(--accent-light); }
.emp-file-area.drag-over { border-color:var(--accent);background:var(--accent-light); }

/* Detail modal */
.emp-detail-emp-row  { display:flex;align-items:center;gap:14px;padding:16px 20px;background:#f8fafc;border-bottom:1px solid #f1f5f9; }
.emp-detail-avatar   { width:44px;height:44px;min-width:44px;background:var(--accent-light);color:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700; }
.emp-detail-info-grid { display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px; }
.emp-detail-info-cell label { display:block;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px; }
.emp-detail-info-cell span  { font-size:13px;font-weight:600;color:#0f172a; }
.emp-detail-section-label   { font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin:12px 0 7px; }
.emp-detail-reason-box { background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;font-size:13px;color:#374151;margin-bottom:12px; }
.emp-detail-dates-table { width:100%;border-collapse:collapse;font-size:13px;margin-bottom:12px; }
.emp-detail-dates-table th { padding:8px 12px;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;background:#f8fafc;border-bottom:1px solid #e2e8f0;text-align:left; }
.emp-detail-dates-table td { padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle; }
.emp-detail-dates-table tr:last-child td { border-bottom:none; }
.emp-status-badge { display:inline-block;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600; }
.emp-status-badge--pending  { background:#fef9c3;color:#a16207; }
.emp-status-badge--approved { background:#d1fae5;color:#065f46; }
.emp-status-badge--rejected { background:#fee2e2;color:#991b1b; }
.emp-status-badge--partial  { background:#ede9fe;color:#7c3aed; }
</style>

<script>
const BASE_URL        = '<?= BASE_URL ?>';
const EMP_LEAVE_TYPES = <?= json_encode(array_column($leaveTypesAll, null, 'leave_type_id')) ?>;

/* ── Calendar State ─────────────────────────────────────── */
const empCal = {
    year:           new Date().getFullYear(),
    month:          new Date().getMonth(),
    selected:       [],
    allowBackdated: false,
    allowFuture:    true,
    mode:           'SINGLE',   // 'SINGLE' | 'MULTIPLE' | 'RANGE'
};

function toYMD(y, m, d) {
    return `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
}
function todayYMD() {
    const t = new Date();
    return toYMD(t.getFullYear(), t.getMonth(), t.getDate());
}

/* ── Calendar Render ────────────────────────────────────── */
function renderEmpCalendar() {
    const months = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];
    document.getElementById('empCalMonth').textContent = `${months[empCal.month]} ${empCal.year}`;

    const tbody    = document.getElementById('empCalBody');
    tbody.innerHTML = '';
    const firstDay  = new Date(empCal.year, empCal.month, 1).getDay();
    const daysInMon = new Date(empCal.year, empCal.month + 1, 0).getDate();
    const today     = todayYMD();
    let day = 1;

    for (let row = 0; row < 6; row++) {
        if (day > daysInMon) break;
        const tr = document.createElement('tr');
        for (let col = 0; col < 7; col++) {
            const td = document.createElement('td');
            if ((row === 0 && col < firstDay) || day > daysInMon) {
                td.innerHTML = '<span class="emp-cal-day emp-cal-day--empty"></span>';
            } else {
                const ymd      = toYMD(empCal.year, empCal.month, day);
                const isPast   = ymd < today;
                const isFuture = ymd > today;
                const isToday  = ymd === today;
                const isSel    = empCal.selected.includes(ymd);
                const futureBlocked = isFuture && !empCal.allowFuture;

                let cls = 'emp-cal-day';
                if (futureBlocked) {
                    cls += ' emp-cal-day--disabled';
                } else if (isPast) {
                    cls += ' emp-cal-day--past';
                    if (empCal.allowBackdated) cls += ' emp-cal-day--backdatable';
                }
                if (isToday) cls += ' emp-cal-day--today';
                if (isSel)   cls += ' emp-cal-day--selected';

                td.innerHTML = `<span class="${cls}" data-date="${ymd}">${day}</span>`;
                if (!futureBlocked) {
                    td.querySelector('.emp-cal-day').addEventListener('click', () => toggleEmpDate(ymd));
                }
                day++;
            }
            tr.appendChild(td);
        }
        tbody.appendChild(tr);
    }
    renderEmpSelectedTags();
}

function toggleEmpDate(ymd) {
    const today = todayYMD();
    if (ymd > today && !empCal.allowFuture) return; // UI already blocks, safety guard

    if (empCal.mode === 'SINGLE') {
        empCal.selected = (empCal.selected[0] === ymd) ? [] : [ymd];
    } else {
        const idx = empCal.selected.indexOf(ymd);
        if (idx === -1) empCal.selected.push(ymd);
        else empCal.selected.splice(idx, 1);
    }
    empCal.selected.sort();
    renderEmpCalendar();
    checkBackdatedSection();
}

function renderEmpSelectedTags() {
    if (empCal.mode === 'RANGE') return;
    const container = document.getElementById('empSelectedTags');
    container.innerHTML = '';
    empCal.selected.forEach(ymd => {
        const [y, m, d] = ymd.split('-').map(Number);
        const label = new Date(y, m-1, d).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
        const tag = document.createElement('span');
        tag.className = 'emp-selected-tag';
        tag.innerHTML = `${label}<button data-date="${ymd}" title="Remove">×</button>`;
        tag.querySelector('button').addEventListener('click', () => {
            const i = empCal.selected.indexOf(ymd);
            if (i !== -1) empCal.selected.splice(i, 1);
            renderEmpCalendar();
            checkBackdatedSection();
        });
        container.appendChild(tag);
    });
}

/* ── Backdated Section ──────────────────────────────────── */
function checkBackdatedSection() {
    const today   = todayYMD();
    const hasPast = empCal.selected.some(d => d < today);
    const show    = empCal.allowBackdated && hasPast;
    document.getElementById('empBackdatedSection').style.display = show ? 'block' : 'none';
}

/* ── Leave Type Change ──────────────────────────────────── */
function onLeaveTypeChange() {
    const sel  = document.getElementById('empLeaveTypeSelect');
    const opt  = sel.options[sel.selectedIndex];
    const allowBack   = opt ? parseInt(opt.dataset.backdated   || '0', 10) : 0;
    const allowFuture = opt ? parseInt(opt.dataset.allowFuture || '1', 10) : 1;

    empCal.allowBackdated = !!allowBack;
    empCal.allowFuture    = !!allowFuture;

    // Show/hide info banners
    document.getElementById('empBackdatedInfo').style.display   = allowBack   ? 'block' : 'none';
    document.getElementById('empFutureWarning').style.display    = allowFuture ? 'none'  : (empCal.selected.some(d => d > todayYMD()) ? 'block' : 'none');

    // If future dates now disallowed, remove any future-dated selections
    if (!empCal.allowFuture) {
        const today = todayYMD();
        empCal.selected = empCal.selected.filter(d => d <= today);
    }

    updateRangeDateConstraints();
    renderEmpCalendar();
    checkBackdatedSection();
    if (empCal.mode === 'RANGE') onRangeChange();
}

function updateRangeDateConstraints() {
    const from = document.getElementById('empRangeFrom');
    const to   = document.getElementById('empRangeTo');
    if (!empCal.allowFuture) {
        const today = todayYMD();
        from.max = today;
        to.max   = today;
        if (from.value > today) from.value = '';
        if (to.value   > today) to.value   = '';
    } else {
        from.removeAttribute('max');
        to.removeAttribute('max');
    }
}

/* ── Filing Mode Switch ─────────────────────────────────── */
function onFilingModeChange(mode) {
    empCal.mode     = mode;
    empCal.selected = [];

    // Update button states
    document.querySelectorAll('.emp-mode-btn').forEach(btn => {
        btn.classList.toggle('emp-mode-btn--active', btn.dataset.mode === mode);
    });

    const isRange = mode === 'RANGE';
    document.getElementById('empCalSection').style.display   = isRange ? 'none'  : 'block';
    document.getElementById('empRangeSection').style.display = isRange ? 'block' : 'none';
    document.getElementById('empSelectedTags').innerHTML = '';

    if (isRange) {
        document.getElementById('empRangeFrom').value = '';
        document.getElementById('empRangeTo').value   = '';
        document.getElementById('empRangePreview').style.display = 'none';
        document.getElementById('empRangeError').style.display   = 'none';
        updateRangeDateConstraints();
    }

    const descs = {
        SINGLE:   'Select one specific date for this leave request.',
        MULTIPLE: 'Click individual dates to select or deselect them (consecutive or non-consecutive).',
        RANGE:    'Choose a start and end date. Every date in between will be included automatically.',
    };
    const hints = {
        SINGLE:   '(click one date)',
        MULTIPLE: '(click dates to select/deselect)',
        RANGE:    '',
    };
    document.getElementById('empModeDesc').textContent    = descs[mode] || '';
    document.getElementById('empCalHint').textContent     = hints[mode] || '';
    document.getElementById('empCalNote').textContent     =
        mode === 'SINGLE' ? 'Click a date to select it. Click again to deselect.' :
        mode === 'MULTIPLE' ? 'Click multiple dates. Click again to deselect.' : '';

    renderEmpCalendar();
    checkBackdatedSection();
}

/* ── Date Range Logic ───────────────────────────────────── */
function onRangeChange() {
    if (empCal.mode !== 'RANGE') return;

    const from    = document.getElementById('empRangeFrom').value;
    const to      = document.getElementById('empRangeTo').value;
    const preview = document.getElementById('empRangePreview');
    const errorEl = document.getElementById('empRangeError');
    const today   = todayYMD();

    errorEl.style.display = 'none';
    preview.style.display = 'none';
    empCal.selected = [];

    if (!from || !to) { checkBackdatedSection(); return; }

    if (to < from) {
        errorEl.textContent   = 'End date cannot be before start date.';
        errorEl.style.display = 'block';
        checkBackdatedSection(); return;
    }

    if (!empCal.allowFuture && to > today) {
        errorEl.textContent   = 'Future dates are not allowed for this leave type. The end date must be today or earlier.';
        errorEl.style.display = 'block';
        checkBackdatedSection(); return;
    }
    if (!empCal.allowFuture && from > today) {
        errorEl.textContent   = 'Future dates are not allowed for this leave type.';
        errorEl.style.display = 'block';
        checkBackdatedSection(); return;
    }

    // Generate dates
    const dates = [];
    const d1 = new Date(from + 'T00:00:00');
    const d2 = new Date(to   + 'T00:00:00');
    for (let d = new Date(d1); d <= d2; d.setDate(d.getDate() + 1)) {
        dates.push(d.toISOString().split('T')[0]);
    }

    if (dates.length > 90) {
        errorEl.textContent   = 'Date range cannot exceed 90 days. Please shorten the range.';
        errorEl.style.display = 'block';
        checkBackdatedSection(); return;
    }

    empCal.selected = dates;
    checkBackdatedSection();

    // Build preview
    const tags = dates.map(ymd => {
        const [y, m, d] = ymd.split('-').map(Number);
        const label = new Date(y, m-1, d).toLocaleDateString('en-US', {weekday:'short', month:'short', day:'numeric'});
        const isPast = ymd < today;
        const cls    = (isPast && empCal.allowBackdated) ? 'emp-range-tag emp-range-tag--past' : 'emp-range-tag';
        return `<span class="${cls}">${label}</span>`;
    }).join('');

    preview.innerHTML = `
        <div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:7px;">
            ${dates.length} date${dates.length !== 1 ? 's' : ''} will be filed:
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:4px;">${tags}</div>`;
    preview.style.display = 'block';
}

/* ── Calendar Navigation ────────────────────────────────── */
document.getElementById('empCalPrev').addEventListener('click', () => {
    if (empCal.month === 0) { empCal.month = 11; empCal.year--; }
    else empCal.month--;
    renderEmpCalendar();
});

document.getElementById('empCalNext').addEventListener('click', () => {
    const now = new Date();
    // Block forward navigation past the current month when future dates are disabled
    if (!empCal.allowFuture) {
        if (empCal.year === now.getFullYear() && empCal.month === now.getMonth()) return;
        // Also block going into future months from any month in same year
        const nextYear  = empCal.month === 11 ? empCal.year + 1 : empCal.year;
        const nextMonth = empCal.month === 11 ? 0 : empCal.month + 1;
        const nextFirst = `${nextYear}-${String(nextMonth+1).padStart(2,'0')}-01`;
        if (nextFirst > todayYMD()) return;
    }
    if (empCal.month === 11) { empCal.month = 0; empCal.year++; }
    else empCal.month++;
    renderEmpCalendar();
});

/* ── File Leave Modal Open / Close ──────────────────────── */
function openFileLeaveModal() {
    empCal.selected       = [];
    empCal.allowBackdated = false;
    empCal.allowFuture    = true;
    empCal.mode           = 'SINGLE';
    empCal.year           = new Date().getFullYear();
    empCal.month          = new Date().getMonth();

    document.getElementById('empLeaveTypeSelect').value    = '';
    document.getElementById('empLeaveReason').value        = '';
    document.getElementById('empBackdateReason').value     = '';
    document.getElementById('empRangeFrom').value          = '';
    document.getElementById('empRangeTo').value            = '';
    document.getElementById('empSelectedTags').innerHTML   = '';

    document.getElementById('empBackdatedInfo').style.display    = 'none';
    document.getElementById('empFutureWarning').style.display     = 'none';
    document.getElementById('empBackdatedSection').style.display  = 'none';
    document.getElementById('empRangeSection').style.display      = 'none';
    document.getElementById('empRangePreview').style.display      = 'none';
    document.getElementById('empRangeError').style.display        = 'none';
    document.getElementById('empCalSection').style.display        = 'block';

    document.querySelectorAll('.emp-mode-btn').forEach(btn => {
        btn.classList.toggle('emp-mode-btn--active', btn.dataset.mode === 'SINGLE');
    });
    document.getElementById('empModeDesc').textContent = 'Select one specific date for this leave request.';
    document.getElementById('empCalHint').textContent  = '(click one date)';
    document.getElementById('empCalNote').textContent  = 'Click a date to select it. Click again to deselect.';

    document.getElementById('fileLeaveOverlay').style.display = 'flex';
    renderEmpCalendar();
}

function closeFileLeaveModal() {
    document.getElementById('fileLeaveOverlay').style.display = 'none';
}
function closeFileLeave(e) {
    if (e.target === document.getElementById('fileLeaveOverlay')) closeFileLeaveModal();
}

/* ── Submit ─────────────────────────────────────────────── */
async function submitEmpLeave() {
    const typeId  = document.getElementById('empLeaveTypeSelect').value;
    const reason  = document.getElementById('empLeaveReason').value.trim();
    const mode    = empCal.mode;
    const today   = todayYMD();

    if (!typeId)  { alert('Please select a leave type.'); return; }
    if (!reason)  { alert('Please enter a reason for your leave.'); return; }

    // Mode-specific date pre-checks
    if (mode === 'RANGE') {
        const from = document.getElementById('empRangeFrom').value;
        const to   = document.getElementById('empRangeTo').value;
        if (!from || !to) { alert('Please enter both From and To dates.'); return; }
        if (to < from)    { alert('End date cannot be before start date.'); return; }
    }

    if (empCal.selected.length === 0) {
        alert('Please select at least one date.'); return;
    }

    const hasPast = empCal.selected.some(d => d < today);
    if (empCal.allowBackdated && hasPast) {
        const backReason = document.getElementById('empBackdateReason').value.trim();
        if (!backReason) {
            alert('Please explain why this leave is being filed after the date(s) occurred.'); return;
        }
    }

    const btn = document.getElementById('empBtnSubmitLeave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting…';

    const fd = new FormData();
    fd.append('leave_type_id', typeId);
    fd.append('reason', reason);
    fd.append('filing_mode', mode);

    if (mode === 'RANGE') {
        fd.append('date_from', document.getElementById('empRangeFrom').value);
        fd.append('date_to',   document.getElementById('empRangeTo').value);
    } else {
        empCal.selected.forEach(d => fd.append('dates[]', d));
    }

    if (empCal.allowBackdated && hasPast) {
        fd.append('is_backdated', '1');
        fd.append('backdate_reason', document.getElementById('empBackdateReason').value.trim());
    }

    const fileInput = document.getElementById('empFileInput');
    if (fileInput.files[0]) fd.append('attachment', fileInput.files[0]);

    try {
        const res  = await fetch(`${BASE_URL}actions/leave-file.php`, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeFileLeaveModal();
            showEmpFlash('success', data.message || 'Leave request submitted!');
            setTimeout(() => location.reload(), 1200);
        } else {
            showEmpFlash('error', data.message || 'Failed to submit leave request.');
        }
    } catch {
        showEmpFlash('error', 'Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-calendar-check"></i> Submit Request';
    }
}

/* ── View Leave Details ─────────────────────────────────── */
async function openEmpLeaveDetail(leaveId) {
    document.getElementById('empLeaveDetailOverlay').style.display = 'flex';
    const body = document.getElementById('empLeaveDetailBody');
    body.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-lg"></i></div>';

    try {
        const res  = await fetch(`${BASE_URL}actions/leave-get-details.php?leave_id=${leaveId}`);
        const data = await res.json();
        if (data.success) body.innerHTML = buildEmpDetailHtml(data);
        else body.innerHTML = `<div style="padding:20px;color:#ef4444;">${escHtml(data.message)}</div>`;
    } catch {
        body.innerHTML = '<div style="padding:20px;color:#ef4444;">Network error loading details.</div>';
    }
}

function buildEmpDetailHtml(data) {
    const r    = data.record;
    const dates = data.dates || [];
    const bal  = data.balance;
    const atts = data.attachments || [];
    const wf   = r.workflow_status || 'PENDING_REVIEW';
    const fm   = r.filing_mode     || 'MULTIPLE';

    const fmLabel = { SINGLE: 'Single Date', MULTIPLE: 'Multiple Dates', RANGE: 'Date Range' }[fm] || fm;
    const initials = ((r.employee_name || '').split(' ').map(w => w[0]).join('').slice(0, 2)).toUpperCase();

    const empRow = `
        <div class="emp-detail-emp-row">
            <div class="emp-detail-avatar">${escHtml(initials)}</div>
            <div>
                <div style="font-size:15px;font-weight:700;color:#0f172a;">${escHtml(r.employee_name)}</div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">${escHtml(r.department_name || '—')} · ${escHtml(r.position_name || '—')}</div>
            </div>
        </div>`;

    let wfBadge = '';
    if      (wf === 'PENDING_REVIEW') wfBadge = '<span style="background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:600;">Under Admin Review</span>';
    else if (wf === 'FORWARDED')      wfBadge = '<span style="background:#d1fae5;color:#065f46;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:600;">With Principal</span>';
    else if (wf === 'RECORDED')       wfBadge = '<span style="background:#d1fae5;color:#065f46;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:600;">Finalised</span>';

    const infoGrid = `
        <div class="emp-detail-info-grid">
            <div class="emp-detail-info-cell"><label>Leave Type</label><span>${escHtml(r.leave_name)}</span></div>
            <div class="emp-detail-info-cell"><label>Filing Mode</label><span>${escHtml(fmLabel)}</span></div>
            <div class="emp-detail-info-cell"><label>Filed On</label><span>${escHtml(r.applied_date)}</span></div>
            <div class="emp-detail-info-cell"><label>Total Days</label><span>${escHtml(String(r.total_days))}</span></div>
            <div class="emp-detail-info-cell"><label>Stage</label><span>${wfBadge || escHtml(r.status)}</span></div>
            <div class="emp-detail-info-cell"><label>Decision</label>
                <span class="emp-status-badge emp-status-badge--${(r.status||'pending').toLowerCase()}">${escHtml(r.status || 'Pending')}</span>
            </div>
        </div>`;

    let backdatedHtml = '';
    if (parseInt(r.is_backdated)) {
        backdatedHtml = `
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:8px;padding:10px 14px;font-size:12px;color:#5b21b6;margin-bottom:12px;">
                <i class="fa fa-triangle-exclamation"></i>
                <strong>Backdated Filing:</strong> ${escHtml(r.backdate_reason || 'No explanation provided.')}
            </div>`;
    }

    const dateRows = dates.map(d => {
        const sc       = `emp-status-badge--${(d.status || 'pending').toLowerCase()}`;
        const lbl      = (d.status || '').charAt(0).toUpperCase() + (d.status || '').slice(1).toLowerCase();
        const noteHtml = d.principal_note
            ? `<em style="font-size:12px;color:#475569;">${escHtml(d.principal_note)}</em>`
            : '<span style="color:#cbd5e1;font-size:11px;">—</span>';
        return `<tr>
            <td style="font-weight:600;">${escHtml(d.date_formatted)}</td>
            <td><span class="emp-status-badge ${sc}">${lbl}</span></td>
            <td>${noteHtml}</td>
        </tr>`;
    }).join('');

    const datesTable = `
        <div class="emp-detail-section-label">Date Breakdown — ${dates.length} Date${dates.length !== 1 ? 's' : ''}</div>
        <table class="emp-detail-dates-table">
            <thead><tr><th>Date</th><th>Status</th><th>Principal Note</th></tr></thead>
            <tbody>${dateRows}</tbody>
        </table>`;

    let balHtml = '';
    if (bal) {
        balHtml = `
            <div class="emp-detail-section-label">Leave Balance (${escHtml(r.leave_name)})</div>
            <div style="display:flex;gap:16px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:12px 16px;margin-bottom:12px;flex-wrap:wrap;">
                <div><div style="font-size:10px;font-weight:600;color:#065f46;text-transform:uppercase;letter-spacing:.05em;">Allocated</div><div style="font-size:20px;font-weight:800;color:#065f46;">${escHtml(String(bal.allocated))}</div></div>
                <div><div style="font-size:10px;font-weight:600;color:#065f46;text-transform:uppercase;letter-spacing:.05em;">Used</div><div style="font-size:20px;font-weight:800;color:#065f46;">${escHtml(String(bal.used))}</div></div>
                <div><div style="font-size:10px;font-weight:600;color:#065f46;text-transform:uppercase;letter-spacing:.05em;">Remaining</div><div style="font-size:20px;font-weight:800;color:#065f46;">${escHtml(String(bal.remaining))}</div></div>
            </div>`;
    }

    let attHtml = '';
    if (atts.length) {
        const items = atts.map(a => {
            const icon = (a.file_type || '').includes('pdf') ? 'fa-file-pdf' : 'fa-file-image';
            return `<a href="${BASE_URL}${escHtml(a.file_path)}" target="_blank"
                style="display:flex;align-items:center;gap:9px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;text-decoration:none;color:#334155;font-size:13px;transition:background .15s;"
                onmouseover="this.style.background='#e6f7f4'" onmouseout="this.style.background='#f8fafc'">
                <i class="fa ${icon}" style="color:#1db89a;"></i>
                <span>${escHtml(a.file_name)}</span>
            </a>`;
        }).join('');
        attHtml = `
            <div class="emp-detail-section-label">Supporting Documents</div>
            <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px;">${items}</div>`;
    }

    return empRow
        + `<div style="padding:16px 20px;">`
        + infoGrid
        + backdatedHtml
        + `<div class="emp-detail-section-label">Reason</div>`
        + `<div class="emp-detail-reason-box">${escHtml(r.reason)}</div>`
        + datesTable
        + balHtml
        + attHtml
        + `</div>`;
}

function closeEmpLeaveDetail() {
    document.getElementById('empLeaveDetailOverlay').style.display = 'none';
}

/* ── File Upload ────────────────────────────────────────── */
function onEmpFileSelected(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('File too large — max 5 MB.'); input.value = ''; return; }
    document.getElementById('empFileName').textContent =
        file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
    document.getElementById('empFilePreview').style.display = 'flex';
    document.getElementById('empFileArea').style.borderColor = '#1db89a';
}
function handleEmpFileDrop(e) {
    e.preventDefault();
    document.getElementById('empFileArea').classList.remove('drag-over');
    if (e.dataTransfer?.files?.length) {
        const input = document.getElementById('empFileInput');
        input.files = e.dataTransfer.files;
        onEmpFileSelected(input);
    }
}
function clearEmpFile() {
    document.getElementById('empFileInput').value = '';
    document.getElementById('empFilePreview').style.display = 'none';
    document.getElementById('empFileArea').style.borderColor = '';
}

/* ── Flash Messages ─────────────────────────────────────── */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function showEmpFlash(type, msg) {
    const existing = document.querySelector('.emp-flash');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.className = `emp-flash emp-flash--${type}`;
    el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:13px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 14px rgba(0,0,0,.12);max-width:380px;';
    el.style.background = type === 'success' ? '#ecfdf5' : '#fef2f2';
    el.style.border     = type === 'success' ? '1px solid #6ee7b7' : '1px solid #fca5a5';
    el.style.color      = type === 'success' ? '#065f46' : '#991b1b';
    el.innerHTML = `${escHtml(msg)}<button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-size:16px;color:inherit;flex-shrink:0;">×</button>`;
    document.body.appendChild(el);
    setTimeout(() => { if (el.parentElement) el.remove(); }, 5000);
}

/* ── Init ───────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    renderEmpCalendar();
});
</script>
<?php include __DIR__ . '/../../../includes/footer.php'; ?>
