<?php
/**
 * modules/employee/profile/index.php
 * Employee Self-Service — My Profile
 * View: full profile  |  Edit: contact, address, personal email, emergency contact, docs
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployeeAccess();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

// Flash messages
$flash = getFlash();

// ── Fetch own employee record ────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT e.*,
           d.department_name,
           p.position_name,
           s.shift_name,
           s.start_time AS shift_start,
           s.end_time   AS shift_end,
           ec.monthly_salary,
           COALESCE(NULLIF(ec.daily_rate, 0), ROUND(ec.monthly_salary / 22, 2)) AS daily_rate,
           u.username,
           r.role_name
    FROM employees e
    LEFT JOIN departments d   ON e.department_id = d.department_id
    LEFT JOIN positions p     ON e.position_id   = p.position_id
    LEFT JOIN shifts s        ON e.shift_id       = s.shift_id
    LEFT JOIN employee_compensations ec ON e.employee_id = ec.employee_id AND ec.is_active = 1
    LEFT JOIN users u         ON e.employee_id = u.employee_id
    LEFT JOIN roles r         ON u.role_id = r.role_id
    WHERE e.employee_id = ?
");
$stmt->execute([$empId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) {
    header('Location: ' . BASE_URL . 'modules/employee/dashboard/index.php');
    exit;
}

// ── Education ────────────────────────────────────────────────────────────────
$eduStmt = $pdo->prepare("
    SELECT institution, year_obtained, description
    FROM employee_credentials
    WHERE employee_id = ? AND credential_type = 'Education'
    ORDER BY year_obtained DESC
");
$eduStmt->execute([$empId]);
$education = $eduStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Documents ────────────────────────────────────────────────────────────────
$docStmt = $pdo->prepare("
    SELECT doc_id, doc_type, doc_name, file_path, file_size, uploaded_at
    FROM employee_documents
    WHERE employee_id = ?
    ORDER BY uploaded_at DESC
");
$docStmt->execute([$empId]);
$documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Leave balances ────────────────────────────────────────────────────────────
$leaveBalances = [];
try {
    $lbStmt = $pdo->prepare("
        SELECT lt.leave_name, elc.allocated_days, elc.used_days,
               (elc.allocated_days - elc.used_days) AS remaining
        FROM employee_leave_credits elc
        JOIN leave_types lt   ON elc.leave_type_id  = lt.leave_type_id
        JOIN school_years sy  ON elc.school_year_id = sy.school_year_id
        WHERE elc.employee_id = ? AND sy.is_active = 1
        ORDER BY lt.leave_name
    ");
    $lbStmt->execute([$empId]);
    $leaveBalances = $lbStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* credit tables may not exist */ }

// ── Active loans ──────────────────────────────────────────────────────────────
$loans = [];
try {
    $loanStmt = $pdo->prepare("
        SELECT lt.loan_name AS loan_type, el.total_amount AS principal_amount,
               el.balance_amount AS outstanding_balance, el.monthly_deduction, el.status
        FROM employee_loans el
        JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
        WHERE el.employee_id = ? AND el.status IN ('ACTIVE','PAUSED')
        ORDER BY el.loan_id DESC
        LIMIT 5
    ");
    $loanStmt->execute([$empId]);
    $loans = $loanStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* loan tables may not exist yet */ }

// ── Active tab from query string ──────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview','job','benefits','education','leave','account'];
if (!in_array($activeTab, $allowedTabs)) $activeTab = 'overview';

$pageTitle = 'My Profile';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css', BASE_URL . 'assets/css/employee.css'];
require_once __DIR__ . '/../../../includes/head.php';

function f(?string $v, string $fallback = '—'): string {
    return htmlspecialchars(trim($v ?? '')) ?: $fallback;
}
function fDate(?string $d): string {
    if (!$d) return '—';
    return date('F j, Y', strtotime($d));
}
function fNum($n): string {
    return '₱' . number_format((float)$n, 2);
}
function fSize(?int $bytes): string {
    if (!$bytes) return '';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
?>
<body>
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
  <?php include __DIR__ . '/../../../includes/header.php'; ?>
  <div class="content">

    <?php if ($flash): ?>
      <div class="emp-flash emp-flash--<?= $flash['type'] ?>">
        <i class="fa <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
        <?= htmlspecialchars($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- PAGE HEADER -->
    <div class="emp-profile-header-bar">
      <div class="emp-profile-avatar-hero">
        <?= strtoupper(substr($emp['first_name'] ?? 'E', 0, 1) . substr($emp['last_name'] ?? '', 0, 1)) ?>
      </div>
      <div class="emp-profile-hero-info">
        <h2><?= f($emp['first_name']) . ' ' . f($emp['middle_name'] ? $emp['middle_name'][0] . '. ' : '') . f($emp['last_name']) . ($emp['suffix'] ? ', ' . f($emp['suffix']) : '') ?></h2>
        <p><?= f($emp['position_name']) ?> &middot; <?= f($emp['department_name']) ?></p>
        <span class="badge <?= strtolower($emp['employee_status'] ?? '') ?>"><?= ucfirst(strtolower($emp['employee_status'] ?? '—')) ?></span>
        <span class="emp-emp-no"><?= f($emp['employee_no']) ?></span>
      </div>
      <div class="emp-profile-hero-actions">
        <button class="btn-primary" onclick="openEditProfile()">
          <i class="fa fa-pen"></i> Edit Profile
        </button>
      </div>
    </div>

    <!-- TABS -->
    <div class="emp-profile-tabs">
      <?php
      $tabs = [
        'overview'  => ['fa-user',          'Overview'],
        'job'       => ['fa-briefcase',     'Job & Pay'],
        'benefits'  => ['fa-id-card',       'Gov\'t IDs'],
        'education' => ['fa-graduation-cap','Education'],
        'leave'     => ['fa-calendar-days', 'Leave & Loans'],
        'account'   => ['fa-key',           'Account'],
      ];
      foreach ($tabs as $key => [$icon, $label]): ?>
        <button class="emp-ptab <?= $activeTab === $key ? 'active' : '' ?>"
                onclick="switchEmpTab('<?= $key ?>')">
          <i class="fa <?= $icon ?>"></i> <?= $label ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- TAB PANELS -->
    <div class="emp-profile-body">

      <!-- ── OVERVIEW ──────────────────────────────────────────── -->
      <div class="emp-tab-panel <?= $activeTab === 'overview' ? 'active' : '' ?>" id="emp-tab-overview">
        <div class="emp-info-grid">

          <div class="emp-info-card">
            <div class="emp-info-title"><i class="fa fa-address-card"></i> Personal Details</div>
            <?php
            $personalRows = [
              ['Legal Name',     implode(', ', array_filter([$emp['last_name'], $emp['first_name'], $emp['middle_name']])) . ($emp['suffix'] ? ' ' . $emp['suffix'] : '')],
              ['Date of Birth',  fDate($emp['birth_date'])],
              ['Sex',            $emp['sex'] ? ucfirst(strtolower($emp['sex'])) : '—'],
              ['Civil Status',   $emp['civil_status'] ? ucfirst(strtolower($emp['civil_status'])) : '—'],
            ];
            foreach ($personalRows as [$label, $val]): ?>
            <div class="emp-detail-row">
              <span class="emp-dr-label"><?= $label ?></span>
              <span class="emp-dr-val"><?= htmlspecialchars($val) ?></span>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="emp-info-card">
            <div class="emp-info-title"><i class="fa fa-phone"></i> Contact Information
              <button class="emp-edit-inline-btn" onclick="openEditProfile('contact')">
                <i class="fa fa-pen"></i> Edit
              </button>
            </div>
            <?php
            $contactRows = [
              ['Contact Number', $emp['contact_no'] ?? '—'],
              ['Personal Email', $emp['personal_email'] ?? '—'],
              ['School Email',   $emp['email'] ?? '—'],
              ['Address',        $emp['address'] ?? '—'],
            ];
            foreach ($contactRows as [$label, $val]): ?>
            <div class="emp-detail-row">
              <span class="emp-dr-label"><?= $label ?></span>
              <span class="emp-dr-val"><?= htmlspecialchars($val ?: '—') ?></span>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="emp-info-card">
            <div class="emp-info-title"><i class="fa fa-phone-volume"></i> Emergency Contact
              <button class="emp-edit-inline-btn" onclick="openEditProfile('emergency')">
                <i class="fa fa-pen"></i> Edit
              </button>
            </div>
            <?php
            $ecRows = [
              ['Name',         $emp['emergency_contact_name']     ?? '—'],
              ['Relationship', $emp['emergency_contact_relation'] ?? '—'],
              ['Contact No.',  $emp['emergency_contact_number']   ?? '—'],
            ];
            foreach ($ecRows as [$label, $val]): ?>
            <div class="emp-detail-row">
              <span class="emp-dr-label"><?= $label ?></span>
              <span class="emp-dr-val"><?= htmlspecialchars($val ?: '—') ?></span>
            </div>
            <?php endforeach; ?>
          </div>

        </div>
      </div>

      <!-- ── JOB & PAY ──────────────────────────────────────────── -->
      <div class="emp-tab-panel <?= $activeTab === 'job' ? 'active' : '' ?>" id="emp-tab-job">
        <div class="emp-info-grid">

          <div class="emp-info-card">
            <div class="emp-info-title"><i class="fa fa-briefcase"></i> Employment Details</div>
            <?php
            $jobRows = [
              ['Employee ID',       $emp['employee_no'] ?? '—'],
              ['Department',        $emp['department_name'] ?? '—'],
              ['Position',          $emp['position_name'] ?? '—'],
              ['Employment Type',   $emp['employment_type'] === 'FULL_TIME' ? 'Full-time' : ($emp['employment_type'] === 'PART_TIME' ? 'Part-time' : '—')],
              ['Date Hired',        fDate($emp['hire_date'])],
              ['Shift Schedule',    ($emp['shift_start'] && $emp['shift_end'])
                                       ? date('g:i A', strtotime($emp['shift_start'])) . ' – ' . date('g:i A', strtotime($emp['shift_end']))
                                       : ($emp['shift_name'] ?? '—')],
            ];
            foreach ($jobRows as [$label, $val]): ?>
            <div class="emp-detail-row">
              <span class="emp-dr-label"><?= $label ?></span>
              <span class="emp-dr-val"><?= htmlspecialchars($val) ?></span>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="emp-info-card">
            <div class="emp-info-title"><i class="fa fa-peso-sign"></i> Salary Information</div>
            <div class="emp-detail-row">
              <span class="emp-dr-label">Monthly Salary</span>
              <strong class="emp-dr-val emp-salary-val"><?= $emp['monthly_salary'] ? fNum($emp['monthly_salary']) : '—' ?></strong>
            </div>
            <div class="emp-detail-row">
              <span class="emp-dr-label">Daily Rate</span>
              <span class="emp-dr-val"><?= $emp['daily_rate'] ? fNum($emp['daily_rate']) : '—' ?></span>
            </div>
            <div class="emp-info-readonly-note">
              <i class="fa fa-lock"></i> Salary information is managed by Admin
            </div>
          </div>

        </div>
      </div>

      <!-- ── GOVERNMENT IDs ─────────────────────────────────────── -->
      <div class="emp-tab-panel <?= $activeTab === 'benefits' ? 'active' : '' ?>" id="emp-tab-benefits">
        <div class="emp-info-card emp-info-card--full">
          <div class="emp-info-title"><i class="fa fa-id-card"></i> Government IDs &amp; Contributions</div>
          <?php
          $govtRows = [
            ['SSS Number',        $emp['sss_no']        ?? '—'],
            ['PhilHealth Number', $emp['philhealth_no'] ?? '—'],
            ['Pag-IBIG / HDMF',   $emp['pagibig_no']    ?? '—'],
            ['TIN Number',        $emp['tin_no']         ?? '—'],
            ['PERAA Number',      $emp['peraa_no']       ?? '—'],
          ];
          foreach ($govtRows as [$label, $val]): ?>
          <div class="emp-detail-row">
            <span class="emp-dr-label"><?= $label ?></span>
            <span class="emp-dr-val"><?= htmlspecialchars($val ?: '—') ?></span>
          </div>
          <?php endforeach; ?>
          <div class="emp-info-readonly-note">
            <i class="fa fa-lock"></i> Government ID numbers are managed by Admin
          </div>
        </div>
      </div>

      <!-- ── EDUCATION & DOCUMENTS ──────────────────────────────── -->
      <div class="emp-tab-panel <?= $activeTab === 'education' ? 'active' : '' ?>" id="emp-tab-education">
        <div class="emp-info-card emp-info-card--full" style="margin-bottom:14px;">
          <div class="emp-info-title"><i class="fa fa-graduation-cap"></i> Educational Background</div>
          <?php if (empty($education)): ?>
            <p class="emp-empty-note">No education records on file.</p>
          <?php else: ?>
            <?php foreach ($education as $edu):
              $parts = explode(' — ', $edu['description'] ?? '');
              $degree = $parts[0] ?? '';
              $course = count($parts) > 1 ? implode(' — ', array_slice($parts, 1)) : '';
            ?>
            <div class="emp-edu-item">
              <div class="emp-edu-degree"><?= htmlspecialchars($degree) ?></div>
              <?php if ($course): ?>
                <div class="emp-edu-course"><?= htmlspecialchars($course) ?></div>
              <?php endif; ?>
              <div class="emp-edu-meta">
                <?= htmlspecialchars($edu['institution'] ?? '—') ?>
                <?= $edu['year_obtained'] ? ' &middot; ' . $edu['year_obtained'] : '' ?>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="emp-info-card emp-info-card--full">
          <div class="emp-info-title">
            <i class="fa fa-folder-open"></i> 201 File Documents
            <button class="emp-edit-inline-btn" onclick="openEditProfile('documents')">
              <i class="fa fa-upload"></i> Upload
            </button>
          </div>
          <?php if (empty($documents)): ?>
            <p class="emp-empty-note">No documents on file.</p>
          <?php else: ?>
            <?php foreach ($documents as $doc): ?>
            <div class="emp-doc-item">
              <i class="fa fa-file-lines"></i>
              <div>
                <strong><?= htmlspecialchars($doc['doc_type'] ?? 'Document') ?></strong>
                <a href="<?= BASE_URL . htmlspecialchars($doc['file_path']) ?>" target="_blank"
                   style="display:block;font-size:12px;color:#0369a1;text-decoration:none;word-break:break-all;">
                  <?= htmlspecialchars($doc['doc_name']) ?> &middot; <?= fSize((int)$doc['file_size']) ?>
                </a>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── LEAVE & LOANS ──────────────────────────────────────── -->
      <div class="emp-tab-panel <?= $activeTab === 'leave' ? 'active' : '' ?>" id="emp-tab-leave">
        <div class="emp-info-grid">

          <div class="emp-info-card">
            <div class="emp-info-title"><i class="fa fa-calendar-days"></i> Leave Balances</div>
            <?php if (empty($leaveBalances)): ?>
              <p class="emp-empty-note">No leave balance data. Contact Admin to allocate leave credits.</p>
            <?php else: ?>
              <?php foreach ($leaveBalances as $lb):
                $pct = $lb['allocated_days'] > 0 ? min(100, ($lb['used_days'] / $lb['allocated_days']) * 100) : 0;
              ?>
              <div class="emp-leave-item">
                <div class="emp-leave-top">
                  <span class="emp-leave-name"><?= htmlspecialchars($lb['leave_name']) ?></span>
                  <span class="emp-leave-nums"><?= $lb['remaining'] ?> / <?= $lb['allocated_days'] ?> days</span>
                </div>
                <div class="emp-leave-bar-wrap">
                  <div class="emp-leave-bar" style="width:<?= $pct ?>%"></div>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
            <div style="margin-top:12px;">
              <a href="<?= BASE_URL ?>modules/employee/leave/index.php" class="emp-link-btn">
                <i class="fa fa-calendar-days"></i> Full Leave History
              </a>
            </div>
          </div>

          <div class="emp-info-card">
            <div class="emp-info-title"><i class="fa fa-hand-holding-dollar"></i> Active Loans</div>
            <?php if (empty($loans)): ?>
              <p class="emp-empty-note">No active loans.</p>
            <?php else: ?>
              <?php foreach ($loans as $ln):
                $pctPaid = $ln['principal_amount'] > 0
                    ? min(100, (($ln['principal_amount'] - $ln['outstanding_balance']) / $ln['principal_amount']) * 100)
                    : 0;
              ?>
              <div class="emp-loan-item">
                <div class="emp-loan-top">
                  <strong><?= htmlspecialchars($ln['loan_type'] ?? 'Loan') ?></strong>
                  <span class="badge <?= strtolower($ln['status']) ?>"><?= ucfirst(strtolower($ln['status'])) ?></span>
                </div>
                <div class="emp-loan-bal">Balance: <?= fNum($ln['outstanding_balance']) ?> of <?= fNum($ln['principal_amount']) ?></div>
                <div class="emp-leave-bar-wrap">
                  <div class="emp-leave-bar" style="width:<?= $pctPaid ?>%;"></div>
                </div>
                <small class="emp-loan-ded">Monthly deduction: <?= fNum($ln['monthly_deduction'] ?? 0) ?></small>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
            <div style="margin-top:12px;">
              <a href="<?= BASE_URL ?>modules/employee/loans/index.php" class="emp-link-btn">
                <i class="fa fa-hand-holding-dollar"></i> Full Loan History
              </a>
            </div>
          </div>

        </div>
      </div>

      <!-- ── ACCOUNT ────────────────────────────────────────────── -->
      <div class="emp-tab-panel <?= $activeTab === 'account' ? 'active' : '' ?>" id="emp-tab-account">
        <div class="emp-info-card emp-info-card--full">
          <div class="emp-info-title"><i class="fa fa-key"></i> Account Information</div>
          <div class="emp-detail-row">
            <span class="emp-dr-label">Username</span>
            <span class="emp-dr-val"><?= f($emp['username']) ?></span>
          </div>
          <div class="emp-detail-row">
            <span class="emp-dr-label">School Email</span>
            <span class="emp-dr-val"><?= f($emp['email']) ?></span>
          </div>
          <div class="emp-detail-row">
            <span class="emp-dr-label">System Role</span>
            <span class="emp-dr-val"><?= f($emp['role_name']) ?></span>
          </div>
          <div class="emp-info-readonly-note">
            <i class="fa fa-lock"></i> Credentials are managed by Admin. For password reset, contact your administrator.
          </div>
        </div>
      </div>

    </div><!-- /emp-profile-body -->

  </div><!-- /content -->
</div><!-- /main -->

<!-- ── EDIT PROFILE MODAL ──────────────────────────────────────────────────── -->
<div class="modal" id="editProfileModal">
  <div class="modal-box" style="width:620px;max-width:96%;">
    <div class="modal-header">
      <div>
        <h3>Edit My Profile</h3>
        <p>You can update contact, address, emergency contact, and documents</p>
      </div>
      <span class="close-btn" onclick="closeModal('editProfileModal')">&#x2715;</span>
    </div>

    <form method="POST" action="<?= BASE_URL ?>actions/employee-profile-update.php"
          id="editProfileForm" enctype="multipart/form-data">

      <div class="modal-body">

        <!-- CONTACT -->
        <div class="form-section" id="editSection-contact">
          <div class="section-header">
            <div class="section-icon"><i class="fa fa-phone"></i></div>
            <div><h4>Contact Information</h4><p>Update your personal contact details</p></div>
          </div>
          <div class="form-row grid-2">
            <div class="form-group">
              <label>Contact Number <span class="req-star">*</span></label>
              <input type="tel" name="contact_no" id="epContactNo"
                     value="<?= htmlspecialchars($emp['contact_no'] ?? '') ?>"
                     placeholder="09123456789" maxlength="13">
              <small>Format: 09123456789 or +639123456789</small>
            </div>
            <div class="form-group">
              <label>Personal Email <span class="opt">(optional)</span></label>
              <input type="email" name="personal_email" id="epPersonalEmail"
                     value="<?= htmlspecialchars($emp['personal_email'] ?? '') ?>"
                     placeholder="personal@email.com">
            </div>
          </div>
          <div class="form-group">
            <label>Home Address <span class="req-star">*</span></label>
            <textarea name="address" rows="2"><?= htmlspecialchars($emp['address'] ?? '') ?></textarea>
          </div>
        </div>

        <!-- EMERGENCY CONTACT -->
        <div class="form-section" id="editSection-emergency">
          <div class="section-header">
            <div class="section-icon"><i class="fa fa-phone-volume"></i></div>
            <div><h4>Emergency Contact</h4><p>Update your emergency contact details</p></div>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label>Contact Name</label>
              <input type="text" name="emergency_contact_name" id="epEcName"
                     value="<?= htmlspecialchars($emp['emergency_contact_name'] ?? '') ?>"
                     placeholder="Full name">
            </div>
            <div class="form-group">
              <label>Relationship</label>
              <input type="text" name="emergency_contact_relation" id="epEcRelation"
                     value="<?= htmlspecialchars($emp['emergency_contact_relation'] ?? '') ?>"
                     placeholder="e.g. Spouse, Parent">
            </div>
            <div class="form-group">
              <label>Contact Number</label>
              <input type="text" name="emergency_contact_number" id="epEcNumber"
                     value="<?= htmlspecialchars($emp['emergency_contact_number'] ?? '') ?>"
                     placeholder="09XX-XXX-XXXX">
            </div>
          </div>
        </div>

        <!-- DOCUMENT UPLOAD -->
        <div class="form-section" id="editSection-documents">
          <div class="section-header">
            <div class="section-icon"><i class="fa fa-folder-open"></i></div>
            <div><h4>Upload Document</h4><p>Add a new document to your 201 file</p></div>
          </div>
          <div class="form-row grid-2">
            <div class="form-group">
              <label>Document Type</label>
              <select name="doc_type">
                <option value="">— Select type —</option>
                <option>Diploma / Transcript</option>
                <option>Employment Contract</option>
                <option>Government ID</option>
                <option>NBI Clearance</option>
                <option>Medical Certificate</option>
                <option>Certificate of Employment</option>
                <option>Other</option>
              </select>
            </div>
            <div class="form-group">
              <label>File <span class="opt">(PDF, JPG, PNG — max 5MB)</span></label>
              <input type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png">
            </div>
          </div>
        </div>

      </div><!-- /modal-body -->

      <div class="modal-footer">
        <small style="color:#6b7280;font-size:11px;">* Required fields</small>
        <div style="display:flex;gap:10px;">
          <button type="button" class="btn-cancel-modal" onclick="closeModal('editProfileModal')">Cancel</button>
          <button type="submit" class="btn-save"><i class="fa fa-floppy-disk"></i> Save Changes</button>
        </div>
      </div>
    </form>
  </div>
</div>

<style>
/* ── Profile page styles ─────────────────────────────────── */
.emp-flash { display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px; }
.emp-flash--success { background:#d1fae5;color:#065f46;border:1px solid #a7f3d0; }
.emp-flash--error   { background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5; }

.emp-profile-header-bar {
  display:flex;align-items:center;gap:18px;background:#fff;border:1px solid #e5e7eb;
  border-radius:16px;padding:20px 24px;margin-bottom:16px;flex-wrap:wrap;
}
.emp-profile-avatar-hero {
  width:68px;height:68px;background:linear-gradient(135deg,#0d3d36,#1db89a);
  color:#fff;border-radius:16px;display:flex;align-items:center;justify-content:center;
  font-size:24px;font-weight:800;flex-shrink:0;
}
.emp-profile-hero-info { flex:1;min-width:0; }
.emp-profile-hero-info h2 { font-size:18px;font-weight:800;margin:0 0 3px;color:#111827; }
.emp-profile-hero-info p  { font-size:13px;color:#6b7280;margin:0 0 6px; }
.emp-emp-no { font-size:11px;background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:999px;margin-left:8px; }
.emp-profile-hero-actions { flex-shrink:0; }

.emp-profile-tabs {
  display:flex;gap:0;border-bottom:1px solid #e5e7eb;background:#fff;
  border-radius:12px 12px 0 0;overflow-x:auto;flex-shrink:0;margin-bottom:0;
}
.emp-ptab {
  padding:12px 16px;border:none;background:none;cursor:pointer;font-size:13px;
  font-weight:500;color:#6b7280;white-space:nowrap;display:flex;align-items:center;
  gap:6px;border-bottom:2px solid transparent;transition:color 0.15s,border-color 0.15s;
}
.emp-ptab:hover { color:var(--accent); }
.emp-ptab.active { color:var(--accent);border-bottom-color:var(--accent);font-weight:700; }

.emp-profile-body { background:#f9fafb;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;padding:20px; }
.emp-tab-panel { display:none; }
.emp-tab-panel.active { display:block; }

.emp-info-grid { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
@media(max-width:640px) { .emp-info-grid { grid-template-columns:1fr; } }

.emp-info-card { background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px; }
.emp-info-card--full { grid-column:1/-1; }
.emp-info-title {
  font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;
  color:var(--accent);margin-bottom:12px;display:flex;align-items:center;gap:6px;
}
.emp-detail-row { display:flex;flex-direction:column;margin-bottom:10px; }
.emp-dr-label { font-size:10px;font-weight:700;color:#9ca3af;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:2px; }
.emp-dr-val   { font-size:13px;color:#111827;font-weight:500; }
.emp-salary-val { color:var(--accent);font-size:15px !important;font-weight:700 !important; }

.emp-info-readonly-note {
  display:flex;align-items:center;gap:6px;font-size:11px;color:#9ca3af;
  margin-top:12px;padding:8px 12px;background:#f9fafb;border-radius:8px;
}
.emp-edit-inline-btn {
  margin-left:auto;background:none;border:1px solid var(--accent-mid);color:var(--accent);
  border-radius:6px;padding:3px 10px;font-size:11px;cursor:pointer;
  display:flex;align-items:center;gap:4px;
}
.emp-edit-inline-btn:hover { background:var(--accent-light); }

.emp-edu-item { border-left:3px solid var(--accent-mid);padding:8px 12px;margin-bottom:8px;background:var(--accent-light);border-radius:0 8px 8px 0; }
.emp-edu-degree { font-size:13px;font-weight:700;color:#111827; }
.emp-edu-course { font-size:12px;color:#374151;margin-top:1px; }
.emp-edu-meta   { font-size:11px;color:#6b7280;margin-top:2px; }

.emp-doc-item { display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9; }
.emp-doc-item:last-child { border-bottom:none; }
.emp-doc-item i { font-size:18px;color:#9ca3af;flex-shrink:0;margin-top:2px; }
.emp-doc-item strong { display:block;font-size:13px;color:#111827; }
.emp-doc-item span   { font-size:11px;color:#6b7280; }

.emp-leave-item   { margin-bottom:12px; }
.emp-leave-top    { display:flex;justify-content:space-between;margin-bottom:4px; }
.emp-leave-name   { font-size:13px;font-weight:600;color:#111827; }
.emp-leave-nums   { font-size:11px;color:#6b7280; }
.emp-leave-bar-wrap { background:#e5e7eb;border-radius:999px;height:7px;overflow:hidden;margin-bottom:2px; }
.emp-leave-bar    { background:var(--accent);height:100%;border-radius:999px;transition:width 0.4s; }

.emp-loan-item  { margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #f1f5f9; }
.emp-loan-item:last-child { border-bottom:none; }
.emp-loan-top   { display:flex;align-items:center;justify-content:space-between;margin-bottom:3px; }
.emp-loan-top strong { font-size:13px;font-weight:700;color:#111827; }
.emp-loan-bal   { font-size:12px;color:#374151;margin-bottom:4px; }
.emp-loan-ded   { font-size:11px;color:#9ca3af; }

.emp-link-btn {
  display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--accent);
  text-decoration:none;padding:6px 12px;background:var(--accent-light);border-radius:8px;
}
.emp-link-btn:hover { background:var(--accent-mid); }

.emp-empty-note { font-size:12px;color:#9ca3af;font-style:italic; }
</style>

<script>
function switchEmpTab(key) {
    document.querySelectorAll('.emp-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.emp-ptab').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('emp-tab-' + key);
    if (panel) panel.classList.add('active');
    document.querySelectorAll('.emp-ptab').forEach(b => {
        if (b.getAttribute('onclick')?.includes("'" + key + "'")) b.classList.add('active');
    });
}

window.openEditProfile = function(section) {
    // Scroll to the relevant section after opening
    openModal('editProfileModal');
    if (section) {
        setTimeout(() => {
            const el = document.getElementById('editSection-' + section);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 120);
    }
};

window.openModal = function(id) {
    const m = document.getElementById(id);
    if (m) m.classList.add('active');
};
window.closeModal = function(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('active');
};
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) e.target.classList.remove('active');
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
