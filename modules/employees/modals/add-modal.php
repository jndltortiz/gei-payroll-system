<?php
// Departments
$departments = $pdo->query("SELECT department_id, department_name FROM departments")->fetchAll();

// Positions
$positions = $pdo->query("SELECT position_id, position_name, department_id FROM positions")->fetchAll();

// Shifts — include all fields for preview
$shifts = $pdo->query("SELECT shift_id, shift_name, start_time, end_time, grace_period_minutes FROM shifts")->fetchAll();
?>

<div class="modal" id="addEmployeeModal">
  <div class="modal-box">

    <!-- HEADER -->
    <div class="modal-header">
      <div>
        <h3>Add New Employee</h3>
        <p>Fill in the details to create a new employee account</p>
      </div>
      <span class="close-btn" onclick="closeModal('addEmployeeModal')">&#x2715;</span>
    </div>

  <form method="POST" action="<?= BASE_URL ?>actions/employee-save.php" id="addEmployeeForm" enctype="multipart/form-data" novalidate>

    <div class="modal-body">

      <!-- PERSONAL INFORMATION -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon"><i class="fa fa-user"></i></div>
          <div>
            <h4>Personal Information</h4>
            <p>Employee's legal name and identification</p>
          </div>
        </div>

        <div class="form-row grid-3">
          <div class="form-group">
            <label>First Name <span class="req">*</span></label>
            <input type="text" name="first_name" placeholder="e.g. Maria" required>
          </div>
          <div class="form-group">
            <label>Middle Name <span class="opt">(optional)</span></label>
            <input type="text" name="middle_name" placeholder="e.g. Dela">
          </div>
          <div class="form-group">
            <label>Last Name <span class="req">*</span></label>
            <input type="text" name="last_name" placeholder="e.g. Santos" required>
          </div>
        </div>

        <div class="form-row grid-3">
           <div class="form-row">
            <div class="form-group">
              <label>Suffix<span class="opt">(optional)</span></label>
              <input type="text" name="suffix" id="editSuffix" placeholder="e.g. Jr.">
            </div>
          </div>
          <div class="form-group">
            <label>Civil Status</label>
            <select name="civil_status">
              <option value="">Select...</option>
              <option value="SINGLE">Single</option>
              <option value="MARRIED">Married</option>
              <option value="WIDOWED">Widowed</option>
              <option value="SEPARATED">Separated</option>
            </select>
          </div>
          <div class="form-group">
            <label>Sex</label>
            <select name="sex">
              <option value="">Select...</option>
              <option value="MALE">Male</option>
              <option value="FEMALE">Female</option>
              <option value="OTHER">Other</option>
            </select>
          </div>
        </div>

        <div class="form-row grid-3">
          <div class="form-group">
              <label>Birth Date</label>
              <input type="date" name="birth_date">
          </div>
          <div class="form-group">
            <label>Contact Number</label>
            <input 
              type="tel" 
              name="contact_no" 
              id="addContactNo"
              placeholder="09123456789"
              pattern="^(09|\+639)\d{9}$"
              maxlength="13"
              required
            >
            <small>Format: 09123456789 or +639123456789</small>
          </div>
          <div class="form-group" style="max-width:220px;">
              <label>Employee ID</label>
              <input type="text" id="addEmployeeNo" placeholder="EMP-2024-007" readonly style="background:#f3f4f6;color:#9ca3af;">
              <small>Auto-generated on save</small>
          </div>
        </div>

        <div class="form-row grid-3">
          <div class="form-group">
            <label>Address</label>
            <textarea name="address" rows="2" placeholder="Full address"></textarea>
          </div>
        </div>
      </div>

      <!-- JOB DETAILS -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon"><i class="fa fa-briefcase"></i></div>
          <div>
            <h4>Job Details</h4>
            <p>Position, department, and employment information</p>
          </div>
        </div>

        <div class="form-row grid-2">
          <div class="form-group">
            <label>Department <span class="req">*</span></label>
            <select name="department_id" id="addDeptSelect" onchange="loadPositions('addDeptSelect','addPosSelect')" required>
              <option value="">Select department...</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= $d['department_id'] ?>">
                  <?= htmlspecialchars($d['department_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Position <span class="req">*</span></label>
            <select name="position_id" id="addPosSelect" required>
              <option value="">Select department first...</option>
              <?php foreach ($positions as $p): ?>
                <option 
                  value="<?= $p['position_id'] ?>" 
                  data-department="<?= $p['department_id'] ?>"
                  style="display:none;">
                  <?= htmlspecialchars($p['position_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row grid-3">
          <div class="form-group">
            <label>Employment Type <span class="req">*</span></label>
            <select name="employment_type" required>
              <option value="FULL_TIME">Full-time</option>
              <option value="PART_TIME">Part-time</option>
            </select>
          </div>
          <div class="form-group">
            <label>Hire Date <span class="req">*</span></label>
            <input type="date" name="hire_date" id="addHireDate" required>
          </div>
          <div class="form-group">
            <label>Employment Status <span class="req">*</span></label>
            <select name="employee_status" required>
              <option value="ACTIVE">Active</option>
              <option value="INACTIVE">Inactive</option>
            </select>
          </div>
        </div>
      </div>

      <!-- SALARY INFORMATION -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon"><i class="fa fa-dollar-sign"></i></div>
          <div>
            <h4>Salary Information</h4>
            <p>Base compensation used for payroll</p>
          </div>
        </div>

        <div class="form-group" style="max-width:300px;">
          <label>Basic Salary (PHP) <span class="req">*</span></label>
          <div class="input-prefix-wrap">
            <span class="input-prefix">&#8369;</span>
            <input type="number" name="basic_salary" placeholder="0.00" step="0.01" min="0" required>
          </div>
          <small>This will be used as the base for payroll computation</small>
        </div>
      </div>

      <!-- SHIFT ASSIGNMENT -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon"><i class="fa fa-clock"></i></div>
          <div>
            <h4>Shift Assignment</h4>
            <p>Controls attendance validation and auto-logout</p>
          </div>
        </div>

        <div class="form-group" style="max-width:300px;">
          <label>Shift Schedule <span class="req">*</span></label>
          <select name="shift_id" id="addShiftSelect" onchange="previewShift('addShiftSelect','addShiftPreview','addShiftEmpty')" required>
            <option value="">Select shift...</option>
            <?php if (isset($shifts)): foreach ($shifts as $sh): ?>
              <option value="<?= $sh['shift_id'] ?>"
                data-start="<?= date('g:i A', strtotime($sh['start_time'])) ?>"
                data-end="<?= date('g:i A', strtotime($sh['end_time'])) ?>"
                data-grace="<?= $sh['grace_period_minutes'] ?> minutes"
                data-name="<?= htmlspecialchars($sh['shift_name']) ?>">
                <?= $sh['shift_name'] ?>
              </option>
            <?php endforeach; endif; ?>
          </select>
        </div>

        <div class="shift-preview" id="addShiftPreview" style="display:none;">
          <div class="shift-preview-title" id="addShiftPreviewTitle"></div>
          <div class="shift-preview-grid">
            <div><span class="shift-label">START TIME</span><strong id="addShiftStart"></strong></div>
            <div><span class="shift-label">END TIME</span><strong id="addShiftEnd"></strong></div>
            <div><span class="shift-label">GRACE PERIOD</span><strong id="addShiftGrace"></strong></div>
          </div>
        </div>

        <div class="shift-empty-preview" id="addShiftEmpty">
          <i class="fa fa-clock"></i>
          <span>Select a shift to preview its schedule details</span>
        </div>

        <small class="shift-note">This shift will be used for attendance validation and automatic logout if the employee forgets to log out. If employee fails to log out, the system will assign the scheduled time-out to prevent payroll errors.</small>
      </div>

      <!-- ACCOUNT CREDENTIALS -->

      <!-- ── SECTION: Government IDs ────────────────────────────────── -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon"><i class="fa fa-id-card"></i></div>
          <div>
            <h4>Government IDs & Benefits</h4>
            <p>For payroll deductions and statutory contributions</p>
          </div>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>SSS Number</label>
            <input type="text" name="sss_no" placeholder="e.g. 33-1234567-8">
          </div>
          <div class="form-group">
            <label>PhilHealth Number</label>
            <input type="text" name="philhealth_no" placeholder="e.g. 12-123456789-1">
          </div>
          <div class="form-group">
            <label>Pag-IBIG / HDMF Number</label>
            <input type="text" name="pagibig_no" placeholder="e.g. 1234-5678-9012">
          </div>
          <div class="form-group">
            <label>TIN Number</label>
            <input type="text" name="tin_no" placeholder="e.g. 123-456-789-000">
          </div>
          <div class="form-group">
            <label>PERAA Number <span class="opt">(permanent employees)</span></label>
            <input type="text" name="peraa_no" placeholder="e.g. PERAA-00001">
          </div>
        </div>
      </div>

      <!-- ── SECTION: Emergency Contact ────────────────────────────── -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon"><i class="fa fa-phone-volume"></i></div>
          <div>
            <h4>Emergency Contact</h4>
            <p>Person to contact in case of emergency</p>
          </div>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>Contact Name</label>
            <input type="text" name="emergency_contact_name" placeholder="Full name">
          </div>
          <div class="form-group">
            <label>Relationship</label>
            <input type="text" name="emergency_contact_relation" placeholder="e.g. Spouse, Parent">
          </div>
          <div class="form-group form-group--full">
            <label>Contact Number</label>
            <input type="text" name="emergency_contact_number" placeholder="e.g. 09XX-XXX-XXXX">
          </div>
        </div>
      </div>

      <!-- ── SECTION: Educational Background (multiple entries) ─────── -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon"><i class="fa fa-graduation-cap"></i></div>
          <div>
            <h4>Educational Background</h4>
            <p>Add all educational attainments (201 file)</p>
          </div>
        </div>

        <div id="eduEntriesContainer">
          <div class="edu-entry" data-index="0">
            <div class="edu-entry-header">
              <span class="edu-entry-label">Entry 1</span>
              <button type="button" class="edu-remove-btn" onclick="removeEduEntry(this)"
                      style="display:none;">
                <i class="fa fa-times"></i> Remove
              </button>
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label>Level / Degree</label>
                <select name="edu_degree[]">
                  <option value="">— Select —</option>
                  <option>Bachelor's Degree</option>
                  <option>Master's Degree</option>
                  <option>Doctorate</option>
                  <option>Senior High School</option>
                  <option>High School</option>
                  <option>Vocational / Tech</option>
                  <option>Elementary</option>
                </select>
              </div>
              <div class="form-group">
                <label>Course / Major</label>
                <input type="text" name="edu_course[]"
                       placeholder="e.g. Bachelor of Secondary Education">
              </div>
              <div class="form-group">
                <label>School / University</label>
                <input type="text" name="edu_school[]"
                       placeholder="e.g. Tarlac State University">
              </div>
              <div class="form-group">
                <label>Year Graduated</label>
                <input type="number" name="edu_year[]" min="1950" max="2030"
                       placeholder="e.g. 2018">
              </div>
            </div>
          </div>
        </div>

        <button type="button" class="edu-add-btn" onclick="addEduEntry()">
          <i class="fa fa-plus"></i> Add Another Education Entry
        </button>
      </div>

      <!-- ── SECTION: Document Attachments ─────────────────────────── -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon"><i class="fa fa-folder-open"></i></div>
          <div>
            <h4>201 File Documents</h4>
            <p>Upload supporting documents (diplomas, IDs, contracts, etc.)</p>
          </div>
        </div>

        <div id="docUploadList">
          <div class="doc-upload-row" data-index="0">
            <div class="form-grid" style="align-items:end;">
              <div class="form-group">
                <label>Document Type</label>
                <select name="doc_type[]">
                  <option value="">— Select type —</option>
                  <option>Diploma / Transcript</option>
                  <option>Employment Contract</option>
                  <option>SSS Card / Number</option>
                  <option>PhilHealth Card</option>
                  <option>Pag-IBIG Card</option>
                  <option>TIN Card</option>
                  <option>Government ID</option>
                  <option>NBI Clearance</option>
                  <option>Medical Certificate</option>
                  <option>Certificate of Employment</option>
                  <option>Other</option>
                </select>
              </div>
              <div class="form-group">
                <label>File</label>
                <input type="file" name="doc_file[]" accept=".pdf,.jpg,.jpeg,.png"
                       class="doc-file-input">
                <small style="font-size:11px;color:#9ca3af;">PDF, JPG, PNG — max 5MB each</small>
              </div>
              <div class="form-group" style="flex:0;min-width:80px;">
                <button type="button" class="edu-remove-btn" onclick="removeDocRow(this)"
                        style="display:none;margin-top:24px;">
                  <i class="fa fa-trash"></i>
                </button>
              </div>
            </div>
          </div>
        </div>

        <button type="button" class="edu-add-btn" onclick="addDocRow()">
          <i class="fa fa-plus"></i> Add Another Document
        </button>
      </div>

      <div class="form-section">
        <div class="section-header">
          <div class="section-icon"><i class="fa fa-key"></i></div>
          <div>
            <h4>Account Credentials</h4>
            <p>System login access — managed by admin only</p>
          </div>
        </div>

        <div class="form-row grid-2">
          <div class="form-group">
            <label>Email Address <span class="req">*</span></label>
            <input type="email" name="email" id="addEmail" placeholder="name@gei.edu.ph" oninput="autoFillUsername('addEmail','addUsername')" required>
          </div>
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" id="addUsername" placeholder="Auto-filled from email" readonly style="background:#f3f4f6;color:#9ca3af;">
            <small>Auto-filled from email prefix</small>
          </div>
        </div>

        <div class="form-row grid-2">
          <div class="form-group">
            <label>System Role <span class="req">*</span></label>
            <select name="role" required>
              <option value="">Select role...</option>
              <option value="Admin">Admin</option>
              <option value="Accounting">Accounting</option>
              <option value="Employee" selected>Employee</option>
            </select>
          </div>
          <div class="form-group">
            <label>Password <span class="req">*</span></label>
            <div class="input-toggle-wrap">
              <input type="password" name="password" id="addPassword" placeholder="••••••••••" required>
              <span class="toggle-eye" onclick="togglePassword('addPassword', this)"><i class="fa fa-eye"></i></span>
            </div>
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <a href="#" class="gen-password-link" onclick="generatePassword('addPassword'); return false;"><i class="fa fa-rotate"></i> Generate Strong Password</a>
        </div>

        <hr class="section-divider">

        <label class="check-row">
          <input type="checkbox" name="send_credentials" checked>
          <div>
            <strong>Send credentials via email</strong>
            <span>Employee will receive their login details at the provided email address</span>
          </div>
        </label>

        <label class="toggle-row">
          <div class="toggle-switch on" id="addForceToggle" onclick="toggleSwitch(this, 'addForceHidden')"></div>
          <input type="hidden" name="force_password_change" id="addForceHidden" value="1">
          <div>
            <strong>Force password change on first login</strong>
            <span>Employee must set a new password upon first system access</span>
          </div>
        </label>

        <div class="info-box">
          <i class="fa fa-key" style="color:#d97706;margin-right:6px;"></i>
          This account will be used to log into the system. Employee accounts can only be created by an administrator.
        </div>
      </div>

    </div><!-- END modal-body -->

    <div class="modal-footer">
      <small style="color:#6b7280;">* Required fields</small>
      <div style="display:flex;gap:10px;">
        <button type="button" class="btn-cancel-modal" onclick="closeModal('addEmployeeModal')">Cancel</button>
        <button type="button" class="btn-save" onclick="prepareSave('addEmployeeForm', 'add')">
          &rsaquo; Save Employee
        </button>
      </div>
    </div>

  </form>

  </div>
</div>