<?php
/**
 * modules/employee/service-credits/index.php
 * Employee Portal — My Service Credits (read-only)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployee();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT sc.service_credit_id, sc.status, sc.equivalent_pay, sc.days,
           sc.created_at, sc.approved_at, sc.remarks, sc.rejection_reason, sc.payroll_id,
           sc.target_period_id,
           CONCAT(apu.first_name,' ',apu.last_name) AS approved_by_name,
           pp.pay_period_start, pp.pay_period_end, pp.pay_date,
           tp.period_name AS target_period_name,
           tp.pay_period_start AS target_period_start,
           tp.pay_period_end   AS target_period_end,
           tp.pay_date         AS target_pay_date,
           (SELECT COUNT(*) FROM service_credit_dates scd
            WHERE scd.service_credit_id = sc.service_credit_id) AS date_count,
           (SELECT MIN(work_date) FROM service_credit_dates scd2
            WHERE scd2.service_credit_id = sc.service_credit_id) AS first_date,
           (SELECT MAX(work_date) FROM service_credit_dates scd3
            WHERE scd3.service_credit_id = sc.service_credit_id) AS last_date
    FROM service_credits sc
    LEFT JOIN users     au  ON au.user_id       = sc.approved_by
    LEFT JOIN employees apu ON apu.employee_id  = au.employee_id
    LEFT JOIN payroll_records pr ON pr.payroll_id = sc.payroll_id
    LEFT JOIN payroll_periods pp ON pp.period_id  = pr.period_id
    LEFT JOIN payroll_periods tp ON tp.period_id  = sc.target_period_id
    WHERE sc.employee_id = ? AND sc.status != 'ARCHIVED'
    ORDER BY sc.created_at DESC
");
$stmt->execute([$empId]);
$credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch-load child dates
$scDates = [];
if (!empty($credits)) {
    $scIds = array_column($credits, 'service_credit_id');
    $ph    = implode(',', array_fill(0, count($scIds), '?'));
    $dSt   = $pdo->prepare("
        SELECT service_credit_id, sc_date_id, work_date,
               CAST(days AS CHAR) AS days,
               CAST(equivalent_pay AS CHAR) AS equivalent_pay,
               status, rejection_reason
        FROM service_credit_dates
        WHERE service_credit_id IN ($ph)
        ORDER BY work_date ASC
    ");
    $dSt->execute($scIds);
    foreach ($dSt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $scDates[$d['service_credit_id']][] = [
            'sc_date_id'     => (int)$d['sc_date_id'],
            'work_date'      => $d['work_date'],
            'days'           => (float)$d['days'],
            'equivalent_pay' => (float)$d['equivalent_pay'],
            'status'         => $d['status'] ?? 'PENDING',
            'rejection_reason' => $d['rejection_reason'] ?? '',
        ];
    }
}

// Summary stats
$totalSubmissions = count($credits);
$totalApprovedPay = 0.0;
$pendingCount     = 0;
$appliedCount     = 0;
foreach ($credits as $c) {
    $s = strtoupper($c['status']);
    if (in_array($s, ['APPROVED','PARTIALLY_APPROVED'])) { $totalApprovedPay += (float)$c['equivalent_pay']; }
    if (in_array($s, ['PENDING','DRAFT'])) $pendingCount++;
    if (in_array($s, ['APPLIED','RELEASED'])) $appliedCount++;
}

$pageTitle = 'My Service Credits — Employee Portal';
$extraCSS  = [
    BASE_URL . 'assets/css/employee-portal.css',
    BASE_URL . 'assets/css/service-credits.css',
];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/employee-sidebar.php'; ?>

<div class="main">
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-medal" style="color:#0d9488;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Employee Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;">Employee</div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'E', 0, 1) . substr($_SESSION['user']['last_name'] ?? 'M', 0, 1)) ?>
      </div>
    </div>
  </div>

  <div class="main-content">
  <div class="emp-page">

    <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 4px;">My Service Credits</h1>
    <p style="font-size:12px;color:#94a3b8;margin:0 0 20px;">
      Extra work rendered beyond regular hours — credited as Additional Assignment Pay in payroll.
    </p>

    <!-- Stats -->
    <div class="emp-kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px;">
      <div class="emp-kpi-card emp-kpi--purple">
        <div class="emp-kpi-icon"><i class="fa fa-medal"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= $totalSubmissions ?></div>
          <div class="emp-kpi-label">Total Submissions</div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--green">
        <div class="emp-kpi-icon"><i class="fa fa-circle-check"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">₱<?= number_format($totalApprovedPay, 2) ?></div>
          <div class="emp-kpi-label">Approved — Awaiting Payroll</div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--amber">
        <div class="emp-kpi-icon"><i class="fa fa-hourglass-half"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= $pendingCount ?></div>
          <div class="emp-kpi-label">Pending Approval</div>
        </div>
      </div>
    </div>

    <!-- Service Credit List -->
    <div class="emp-panel" style="padding:0;overflow:hidden;">
      <?php if (!empty($credits)): ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
            <th style="padding:11px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Work Date(s)</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Days</th>
            <th style="padding:11px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Equivalent Pay</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Status</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($credits as $sc):
            $status   = strtoupper($sc['status']);
            $badgeCls = match($status) {
                'APPROVED'           => 'approved',
                'PARTIALLY_APPROVED' => 'partial',
                'REJECTED'           => 'rejected',
                'APPLIED','RELEASED' => 'applied',
                'DRAFT'              => 'draft',
                default              => 'pending',
            };
            $statusIcon = match($status) {
                'APPROVED'           => 'circle-check',
                'PARTIALLY_APPROVED' => 'circle-half-stroke',
                'APPLIED','RELEASED' => 'file-invoice-dollar',
                'REJECTED'           => 'circle-xmark',
                'DRAFT'              => 'floppy-disk',
                default              => 'hourglass-half',
            };
            $statusLabel = match($status) {
                'APPROVED'           => 'Approved',
                'PARTIALLY_APPROVED' => 'Partial',
                'APPLIED'            => 'In Payroll',
                'RELEASED'           => 'Released',
                'REJECTED'           => 'Rejected',
                'DRAFT'              => 'Draft',
                default              => 'Pending',
            };

            $fd = $sc['first_date'] ?? null;
            $ld = $sc['last_date']  ?? null;
            $dateRange = $fd
                ? ($fd === $ld || !$ld
                    ? date('M j, Y', strtotime($fd))
                    : date('M j', strtotime($fd)) . ' – ' . date('M j, Y', strtotime($ld)))
                : '—';

            $rDates = $scDates[$sc['service_credit_id']] ?? [];
            $rData  = array_merge($sc, ['dates' => $rDates]);
            $rJson  = htmlspecialchars(json_encode($rData), ENT_QUOTES);
        ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:13px 16px;">
            <span style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($dateRange) ?></span>
            <?php if ((int)$sc['date_count'] > 1): ?>
            <br><small style="color:#94a3b8;"><?= (int)$sc['date_count'] ?> dates</small>
            <?php endif; ?>
          </td>
          <td style="padding:13px 16px;text-align:center;font-weight:700;color:#374151;">
            <?= number_format((float)$sc['days'], 1) ?>
          </td>
          <td style="padding:13px 16px;text-align:right;font-weight:700;color:<?= in_array($status,['APPROVED','PARTIALLY_APPROVED','APPLIED','RELEASED']) ? '#059669' : '#374151' ?>;">
            ₱<?= number_format((float)$sc['equivalent_pay'], 2) ?>
          </td>
          <td style="padding:13px 16px;text-align:center;">
            <span class="emp-badge emp-badge--<?= $badgeCls ?>">
              <i class="fa fa-<?= $statusIcon ?>"></i>
              <?= $statusLabel ?>
            </span>
          </td>
          <td style="padding:13px 16px;text-align:center;">
            <button class="sc-icon-btn sc-icon-btn--view"
                    onclick="openEmpScView(<?= $rJson ?>)"
                    title="View Details" style="width:auto;padding:5px 10px;gap:5px;font-size:12px;font-weight:600;">
              <i class="fa fa-eye"></i> Details
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php else: ?>
      <div class="emp-empty" style="padding:60px 20px;">
        <i class="fa fa-medal"></i>
        <p>No service credit submissions yet.<br>Ask HR Admin to record extra work you've rendered.</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Info note -->
    <div style="margin-top:14px;padding:12px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;font-size:12px;color:#92400e;display:flex;align-items:flex-start;gap:8px;">
      <i class="fa fa-circle-info" style="margin-top:2px;flex-shrink:0;"></i>
      <div>
        Approved service credits are included as <strong>Additional Assignment Pay</strong> in your next payroll period.
        Contact HR Admin to submit extra work dates for approval.
      </div>
    </div>

  </div>
  </div>
</div>
</div>

<!-- ═══ View Details Modal (Employee) ═══ -->
<div class="sc-modal-overlay" id="empScViewOverlay" style="display:none;">
  <div class="sc-modal-box sc-modal-box--lg">
    <div class="sc-modal-header">
      <h3><i class="fa fa-medal" style="color:#0d9488;"></i> Service Credit Details</h3>
      <button onclick="document.getElementById('empScViewOverlay').style.display='none'">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <div class="sc-modal-body" id="empScViewBody"></div>
    <div class="sc-modal-footer">
      <button type="button" class="sc-btn-ghost"
              onclick="document.getElementById('empScViewOverlay').style.display='none'">Close</button>
    </div>
  </div>
</div>

<script>
function openEmpScView(r) {
    function escH(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }
    function fmtDate(d) {
        if (!d) return '—';
        const dt = new Date(d + (d.length===10?'T00:00:00':''));
        return dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
    }
    function fmtDateTime(d) {
        if (!d) return '—';
        const dt = new Date(d.length===10?d+'T00:00:00':d);
        return dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})
            + ' ' + dt.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'});
    }

    const DATE_STATUS = {
        PENDING:  {label:'Pending',  bg:'#fef3c7',color:'#92400e'},
        APPROVED: {label:'Approved', bg:'#d1fae5',color:'#065f46'},
        REJECTED: {label:'Rejected', bg:'#fee2e2',color:'#991b1b'},
    };

    const statusLabels = {
        DRAFT:'Draft', PENDING:'Pending Approval', APPROVED:'Approved',
        PARTIALLY_APPROVED:'Partially Approved',
        APPLIED:'In Payroll', RELEASED:'Released', REJECTED:'Rejected'
    };
    const statusColors = {
        DRAFT:'#9ca3af', PENDING:'#d97706', APPROVED:'#059669',
        PARTIALLY_APPROVED:'#0e7490',
        APPLIED:'#2563eb', RELEASED:'#7c3aed', REJECTED:'#ef4444'
    };
    const st    = r.status || 'PENDING';
    const stLbl = statusLabels[st] || st;
    const stCol = statusColors[st] || '#9ca3af';

    // Work dates table
    const dates = (r.dates && r.dates.length > 0) ? r.dates
        : (r.work_date ? [{work_date:r.work_date, days:r.days||0, equivalent_pay:r.equivalent_pay||0, status:'PENDING', rejection_reason:''}] : []);

    const hasMixed = dates.some(d => d.status && d.status !== 'PENDING');

    let dHtml = '';
    if (dates.length > 0) {
        const dateRows = dates.map(d => {
            const dst    = (d.status || 'PENDING').toUpperCase();
            const di     = DATE_STATUS[dst] || DATE_STATUS.PENDING;
            const isRej  = dst === 'REJECTED';
            const payTxt = isRej
                ? `<span style="color:#d1d5db;text-decoration:line-through;">₱${parseFloat(d.equivalent_pay).toLocaleString('en-PH',{minimumFractionDigits:2})}</span>`
                : `<span style="font-weight:600;color:#0f766e;">₱${parseFloat(d.equivalent_pay).toLocaleString('en-PH',{minimumFractionDigits:2})}</span>`;
            const statusCell = hasMixed
                ? `<td style="padding:7px 10px;text-align:center;">
                      <span style="display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:${di.bg};color:${di.color};">${di.label}</span>
                      ${isRej && d.rejection_reason ? `<br><small style="color:#ef4444;font-size:10px;font-style:italic;">${escH(d.rejection_reason)}</small>` : ''}
                   </td>`
                : '';
            return `<tr style="border-bottom:1px solid #f8fafc;${isRej?'background:#fef9f9;':''}">
                <td style="padding:7px 10px;${isRej?'color:#d1d5db;':''}">${fmtDate(d.work_date)}</td>
                <td style="padding:7px 10px;text-align:center;${isRej?'color:#d1d5db;':''}">${parseFloat(d.days).toFixed(1)} day${parseFloat(d.days)!==1?'s':''}</td>
                <td style="padding:7px 10px;text-align:right;">${payTxt}</td>
                ${statusCell}
            </tr>`;
        }).join('');

        const statusHeader = hasMixed ? '<th style="padding:7px 10px;text-align:center;font-size:11px;color:#64748b;font-weight:600;">Status</th>' : '';

        dHtml = `<div style="margin-bottom:14px;">
            <div class="sc-view-meta-label" style="margin-bottom:6px;">Work Dates</div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e5e7eb;">
                        <th style="padding:7px 10px;text-align:left;font-size:11px;color:#64748b;font-weight:600;">Date</th>
                        <th style="padding:7px 10px;text-align:center;font-size:11px;color:#64748b;font-weight:600;">Day Equivalent</th>
                        <th style="padding:7px 10px;text-align:right;font-size:11px;color:#64748b;font-weight:600;">Amount</th>
                        ${statusHeader}
                    </tr>
                </thead>
                <tbody>${dateRows}</tbody>
                <tfoot style="border-top:2px solid #e5e7eb;">
                    <tr style="background:#f0fdf9;">
                        <td style="padding:7px 10px;font-weight:700;" colspan="${hasMixed?3:3}">Total (approved)</td>
                        <td style="padding:7px 10px;text-align:center;font-weight:700;">${parseFloat(r.days||0).toFixed(1)} day${r.days!=1?'s':''}</td>
                        <td style="padding:7px 10px;text-align:right;font-weight:700;color:#0f766e;">₱${parseFloat(r.equivalent_pay||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
                        ${hasMixed ? '<td></td>' : ''}
                    </tr>
                </tfoot>
            </table>
        </div>`;
    }

    // Rejection reason block (parent-level, for fully rejected)
    let rejHtml = '';
    if (st === 'REJECTED' && r.rejection_reason) {
        rejHtml = `<div style="display:flex;gap:10px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:13px;color:#991b1b;margin-bottom:14px;">
            <i class="fa fa-circle-xmark" style="flex-shrink:0;margin-top:2px;"></i>
            <div><strong>Rejection Reason:</strong><br>${escH(r.rejection_reason)}</div>
        </div>`;
    }

    // Partial info block
    let partialHtml = '';
    if (st === 'PARTIALLY_APPROVED') {
        const apprCnt = dates.filter(d=>(d.status||'').toUpperCase()==='APPROVED').length;
        const rejCnt  = dates.filter(d=>(d.status||'').toUpperCase()==='REJECTED').length;
        partialHtml = `<div style="display:flex;gap:10px;padding:10px 12px;background:#cffafe;border:1px solid #a5f3fc;border-radius:8px;font-size:13px;color:#0e7490;margin-bottom:14px;">
            <i class="fa fa-circle-half-stroke" style="flex-shrink:0;margin-top:2px;"></i>
            <div><strong>Partially Approved</strong> — ${apprCnt} date${apprCnt!==1?'s':''} approved, ${rejCnt} date${rejCnt!==1?'s':''} rejected. Only approved dates are included in payroll.</div>
        </div>`;
    }

    // Payroll reference
    let payrollRef = '<span style="color:#94a3b8;">Not yet applied</span>';
    if (r.payroll_id && (st === 'APPLIED' || st === 'RELEASED')) {
        payrollRef = `<span style="font-weight:700;color:#2563eb;"><i class="fa fa-file-invoice-dollar" style="margin-right:4px;"></i>Payroll #${r.payroll_id}</span>`;
        if (r.pay_period_start && r.pay_period_end) {
            payrollRef += `<br><small style="color:#64748b;font-size:11px;">${fmtDate(r.pay_period_start)} – ${fmtDate(r.pay_period_end)}</small>`;
        }
        if (r.pay_date) {
            payrollRef += `<br><small style="color:#64748b;font-size:11px;">Pay date: ${fmtDate(r.pay_date)}</small>`;
        }
    }

    // Target payroll period
    let targetPeriodHtml = '';
    if (r.target_period_id) {
        let tLabel = r.target_period_name;
        if (!tLabel && r.target_period_start && r.target_period_end) {
            tLabel = fmtDate(r.target_period_start) + ' – ' + fmtDate(r.target_period_end);
        }
        tLabel = tLabel || ('Period #' + r.target_period_id);
        if (r.target_pay_date) tLabel += ' · Pay: ' + fmtDate(r.target_pay_date);
        targetPeriodHtml = `<div class="sc-view-meta-item">
            <div class="sc-view-meta-label">Target Payroll Period</div>
            <div class="sc-view-meta-val">${escH(tLabel)}</div>
        </div>`;
    }

    // Approval info row
    let approvedRow = '';
    if (r.approved_by_name && (st === 'APPROVED' || st === 'PARTIALLY_APPROVED' || st === 'APPLIED' || st === 'RELEASED')) {
        approvedRow = `
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Approved by</div>
                <div class="sc-view-meta-val">${escH(r.approved_by_name)}</div>
            </div>
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Approved on</div>
                <div class="sc-view-meta-val">${fmtDateTime(r.approved_at)}</div>
            </div>`;
    } else if (st === 'REJECTED' && r.approved_by_name) {
        approvedRow = `
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Rejected by</div>
                <div class="sc-view-meta-val" style="color:#ef4444;">${escH(r.approved_by_name)}</div>
            </div>
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Rejected on</div>
                <div class="sc-view-meta-val">${fmtDateTime(r.approved_at)}</div>
            </div>`;
    }

    document.getElementById('empScViewBody').innerHTML = `
        <div class="sc-view-header">
            <div>
                <div class="sc-view-emp" style="font-size:14px;">Service Credit #${r.service_credit_id}</div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">Submitted ${fmtDateTime(r.created_at)}</div>
            </div>
            <span class="sc-badge" style="background:${stCol}20;color:${stCol};">
                ${escH(stLbl)}
            </span>
        </div>

        ${r.remarks ? `<div class="sc-view-remarks"><strong>Description:</strong> ${escH(r.remarks)}</div>` : '<div style="font-size:12px;color:#94a3b8;margin-bottom:14px;font-style:italic;">No description provided.</div>'}

        ${rejHtml}${partialHtml}
        ${dHtml}

        <div class="sc-view-meta-grid">
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Total Days</div>
                <div class="sc-view-meta-val">${parseFloat(r.days||0).toFixed(1)} day${r.days!=1?'s':''}</div>
            </div>
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Equivalent Pay</div>
                <div class="sc-view-meta-val" style="color:#059669;font-size:15px;">₱${parseFloat(r.equivalent_pay||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
            </div>
            ${approvedRow}
            ${targetPeriodHtml}
            <div class="sc-view-meta-item" style="grid-column:1/-1;">
                <div class="sc-view-meta-label">Payroll Reference</div>
                <div class="sc-view-meta-val">${payrollRef}</div>
            </div>
        </div>`;

    document.getElementById('empScViewOverlay').style.display = 'flex';
}

document.getElementById('empScViewOverlay').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
