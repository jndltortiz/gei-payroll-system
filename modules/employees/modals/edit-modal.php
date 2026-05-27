<?php
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$positions   = $pdo->query("SELECT position_id, position_name, department_id FROM positions ORDER BY position_name")->fetchAll();
$shifts      = $pdo->query("SELECT shift_id, shift_name, start_time, end_time, grace_period_minutes FROM shifts ORDER BY shift_name")->fetchAll();
?>

<div class="modal" id="editEmployeeModal">
  <div class="modal-box modal-box-wizard">

    <!-- HEADER -->
    <div class="modal-header">
      <div>
        <h3>Edit Employee Record</h3>
        <p id="editModalSubtitle">Editing: —</p>
      </div>
      <span class="close-btn" onclick="closeEditWizard()">&#x2715;</span>
    </div>

    <!-- WIZARD PROGRESS -->
    <div class="wiz-progress" id="editWizProgress">
      <div class="wiz-step-item active" data-modal="editEmployeeModal" data-step="1">
        <div class="wiz-circle"><span>1</span></div>
        <span class="wiz-label">Personal</span>
      </div>
      <div class="wiz-connector"></div>
      <div class="wiz-step-item" data-modal="editEmployeeModal" data-step="2">
        <div class="wiz-circle"><span>2</span></div>
        <span class="wiz-label">Job</span>
      </div>
      <div class="wiz-connector"></div>
      <div class="wiz-step-item" data-modal="editEmployeeModal" data-step="3">
        <div class="wiz-circle"><span>3</span></div>
        <span class="wiz-label">Benefits</span>
      </div>
      <div class="wiz-connector"></div>
      <div class="wiz-step-item" data-modal="editEmployeeModal" data-step="4">
        <div class="wiz-circle"><span>4</span></div>
        <span class="wiz-label">Education</span>
      </div>
      <div class="wiz-connector"></div>
      <div class="wiz-step-item" data-modal="editEmployeeModal" data-step="5">
        <div class="wiz-circle"><span>5</span></div>
        <span class="wiz-label">Account</span>
      </div>
    </div>

    <form method="POST" action="<?= BASE_URL ?>actions/employee-update.php"
          id="editEmployeeForm" enctype="multipart/form-data">
      <input type="hidden" name="employee_id" id="editEmployeeId">

      <div class="modal-body">

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- STEP 1 — PERSONAL INFORMATION                          -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <div class="wiz-panel active" id="editEmployeeModalStep1">
          <div class="wiz-step-hint"><i class="fa fa-circle-info"></i> <span class="req-star">*</span> Required fields must be completed before proceeding</div>

          <div class="form-section">
            <div class="section-header">
              <div class="section-icon"><i class="fa fa-user"></i></div>
              <div><h4>Personal Information</h4><p>Legal name and identification details</p></div>
            </div>

            <div class="form-row grid-3">
              <div class="form-group">
                <label>First Name <span class="req-star">*</span></label>
                <input type="text" name="first_name" id="editFirstName" placeholder="e.g. Maria" data-req="1" data-label="First Name">
              </div>
              <div class="form-group">
                <label>Middle Name <span class="opt">(optional)</span></label>
                <input type="text" name="middle_name" id="editMiddleName" placeholder="e.g. Dela">
              </div>
              <div class="form-group">
                <label>Last Name <span class="req-star">*</span></label>
                <input type="text" name="last_name" id="editLastName" placeholder="e.g. Santos" data-req="1" data-label="Last Name">
              </div>
            </div>

            <div class="form-row grid-3">
              <div class="form-group">
                <label>Suffix <span class="opt">(optional)</span></label>
                <input type="text" name="suffix" id="editSuffix" placeholder="e.g. Jr., Sr.">
              </div>
              <div class="form-group">
                <label>Sex <span class="req-star">*</span></label>
                <select name="sex" id="editSex" data-req="1" data-label="Sex">
                  <option value="">Select...</option>
                  <option value="MALE">Male</option>
                  <option value="FEMALE">Female</option>
                  <option value="OTHER">Other</option>
                </select>
              </div>
              <div class="form-group">
                <label>Civil Status <span class="opt">(optional)</span></label>
                <select name="civil_status" id="editCivilStatus">
                  <option value="">Select...</option>
                  <option value="SINGLE">Single</option>
                  <option value="MARRIED">Married</option>
                  <option value="WIDOWED">Widowed</option>
                  <option value="SEPARATED">Separated</option>
                </select>
              </div>
            </div>

            <div class="form-row grid-3">
              <div class="form-group">
                <label>Birth Date <span class="opt">(optional)</span></label>
                <input type="date" name="birth_date" id="editBirthDate">
              </div>
              <div class="form-group">
                <label>Contact Number <span class="req-star">*</span></label>
                <input type="tel" name="contact_no" id="editContactNo" placeholder="09123456789"
                       maxlength="13" data-req="1" data-label="Contact Number">
                <small>Format: 09123456789 or +639123456789</small>
              </div>
              <div class="form-group">
                <label>Personal Email <span class="opt">(optional)</span></label>
                <input type="email" name="personal_email" id="editPersonalEmail" placeholder="personal@email.com">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Home Address <span class="req-star">*</span></label>
                <textarea name="address" id="editAddress" rows="2" placeholder="Street, Barangay, City, Province" data-req="1" data-label="Address"></textarea>
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="section-header">
              <div class="section-icon"><i class="fa fa-phone-volume"></i></div>
              <div><h4>Emergency Contact</h4><p>Person to contact in case of emergency</p></div>
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label>Contact Name <span class="req-star">*</span></label>
                <input type="text" name="emergency_contact_name" id="editEcName" placeholder="Full name" data-req="1" data-label="Emergency Contact Name">
              </div>
              <div class="form-group">
                <label>Relationship <span class="opt">(optional)</span></label>
                <input type="text" name="emergency_contact_relation" id="editEcRelation" placeholder="e.g. Spouse, Parent">
              </div>
              <div class="form-group">
                <label>Contact Number <span class="req-star">*</span></label>
                <input type="text" name="emergency_contact_number" id="editEcNumber" placeholder="09XX-XXX-XXXX" data-req="1" data-label="Emergency Contact Number">
              </div>
            </div>
          </div>
        </div><!-- /step1 -->

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- STEP 2 — JOB DETAILS                                   -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <div class="wiz-panel" id="editEmployeeModalStep2">
          <div class="wiz-step-hint"><i class="fa fa-circle-info"></i> <span class="req-star">*</span> Required fields must be completed before proceeding</div>

          <div class="form-section">
            <div class="section-header">
              <div class="section-icon"><i class="fa fa-briefcase"></i></div>
              <div><h4>Job Details</h4><p>Position, department, and employment information</p></div>
            </div>

            <div class="form-group" style="max-width:260px;">
              <label>Employee ID</label>
              <input type="text" id="editEmployeeNo" readonly style="background:#f3f4f6;color:#9ca3af;">
            </div>

            <div class="form-row grid-2">
              <div class="form-group">
                <label>Department <span class="req-star">*</span></label>
                <select name="department_id" id="editDeptSelect"
                        onchange="loadPositions('editDeptSelect','editPosSelect')"
                        data-req="1" data-label="Department">
                  <option value="">Select department...</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Position <span class="req-star">*</span></label>
                <select name="position_id" id="editPosSelect" data-req="1" data-label="Position">
                  <option value="">Select department first</option>
                  <?php foreach ($positions as $p): ?>
                    <option value="<?= $p['position_id'] ?>" data-department="<?= $p['department_id'] ?>" style="display:none;">
                      <?= htmlspecialchars($p['position_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-row grid-3">
              <div class="form-group">
                <label>Employment Type <span class="req-star">*</span></label>
                <select name="employment_type" id="editEmploymentType" data-req="1" data-label="Employment Type">
                  <option value="">Select...</option>
                  <option value="FULL_TIME">Full-time</option>
                  <option value="PART_TIME">Part-time</option>
                </select>
              </div>
              <div class="form-group">
                <label>Employment Status <span class="req-star">*</span></label>
                <select name="employee_status" id="editEmployeeStatus" data-req="1" data-label="Employment Status">
                  <option value="ACTIVE">Active</option>
                  <option value="INACTIVE">Inactive</option>
                </select>
              </div>
              <div class="form-group">
                <label>Hire Date <span class="req-star">*</span></label>
                <input type="date" name="hire_date" id="editHireDate" data-req="1" data-label="Hire Date">
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="section-header">
              <div class="section-icon"><i class="fa fa-clock"></i></div>
              <div><h4>Shift Assignment</h4><p>Controls attendance validation and auto-logout</p></div>
            </div>
            <div class="form-group" style="max-width:320px;">
              <label>Shift Schedule <span class="req-star">*</span></label>
              <select name="shift_id" id="editShiftSelect"
                      onchange="previewShift('editShiftSelect','editShiftPreview','editShiftEmpty')"
                      data-req="1" data-label="Shift">
                <option value="">Select shift...</option>
                <?php foreach ($shifts as $sh): ?>
                  <option value="<?= $sh['shift_id'] ?>"
                    data-start="<?= date('g:i A', strtotime($sh['start_time'])) ?>"
                    data-end="<?= date('g:i A', strtotime($sh['end_time'])) ?>"
                    data-grace="<?= $sh['grace_period_minutes'] ?> min"
                    data-name="<?= htmlspecialchars($sh['shift_name']) ?>">
                    <?= $sh['shift_name'] ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="shift-preview" id="editShiftPreview" style="display:none;">
              <div class="shift-preview-title" id="editShiftPreviewTitle"></div>
              <div class="shift-preview-grid">
                <div><span class="shift-label">START</span><strong id="editShiftStart"></strong></div>
                <div><span class="shift-label">END</span><strong id="editShiftEnd"></strong></div>
                <div><span class="shift-label">GRACE</span><strong id="editShiftGrace"></strong></div>
              </div>
            </div>
            <div class="shift-empty-preview" id="editShiftEmpty">
              <i class="fa fa-clock"></i><span>Select a shift to see schedule details</span>
            </div>
          </div>

          <div class="form-section">
            <div class="section-header">
              <div class="section-icon"><i class="fa fa-peso-sign"></i></div>
              <div><h4>Salary Information</h4><p>Base compensation used for payroll computation</p></div>
            </div>
            <div class="form-group" style="max-width:300px;">
              <label>Basic Monthly Salary (PHP) <span class="req-star">*</span></label>
              <div class="input-prefix-wrap">
                <span class="input-prefix">₱</span>
                <input type="number" name="basic_salary" id="editBasicSalary" placeholder="0.00" step="0.01" min="0"
                       data-req="1" data-label="Basic Salary">
              </div>
            </div>
          </div>
        </div><!-- /step2 -->

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- STEP 3 — GOVERNMENT & BENEFITS                         -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <div class="wiz-panel" id="editEmployeeModalStep3">
          <div class="wiz-step-hint"><i class="fa fa-circle-info"></i> Update government ID numbers for payroll statutory deductions</div>

          <div class="form-section">
            <div class="section-header">
              <div class="section-icon"><i class="fa fa-id-card"></i></div>
              <div><h4>Government IDs &amp; Benefits</h4><p>Required for statutory contributions and deductions</p></div>
            </div>
            <div class="form-grid">
              <div class="form-group">
                <label>SSS Number <span class="opt">(optional)</span></label>
                <input type="text" name="sss_no" id="editSssNo" placeholder="e.g. 33-1234567-8">
              </div>
              <div class="form-group">
                <label>PhilHealth Number <span class="opt">(optional)</span></label>
                <input type="text" name="philhealth_no" id="editPhilhealthNo" placeholder="e.g. 12-123456789-1">
              </div>
              <div class="form-group">
                <label>Pag-IBIG / HDMF Number <span class="opt">(optional)</span></label>
                <input type="text" name="pagibig_no" id="editPagibigNo" placeholder="e.g. 1234-5678-9012">
              </div>
              <div class="form-group">
                <label>TIN Number <span class="opt">(optional)</span></label>
                <input type="text" name="tin_no" id="editTinNo" placeholder="e.g. 123-456-789-000">
              </div>
              <div class="form-group">
                <label>PERAA Number <span class="opt">(optional — permanent only)</span></label>
                <input type="text" name="peraa_no" id="editPeraaNo" placeholder="e.g. PERAA-00001">
              </div>
            </div>
          </div>
        </div><!-- /step3 -->

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- STEP 4 — EDUCATION & DOCUMENTS                         -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <div class="wiz-panel" id="editEmployeeModalStep4">
          <div class="wiz-step-hint"><i class="fa fa-circle-info"></i> Existing education entries will be replaced. New documents will be added alongside existing ones.</div>

          <div class="form-section">
            <div class="section-header">
              <div class="section-icon"><i class="fa fa-graduation-cap"></i></div>
              <div><h4>Educational Background</h4><p>Add or update educational attainments</p></div>
            </div>
            <div id="editEduEntriesContainer">
              <!-- Populated by JS when editing -->
            </div>
            <button type="button" class="edu-add-btn" onclick="addEduEntry('editEduEntriesContainer')">
              <i class="fa fa-plus"></i> Add Education Entry
            </button>
          </div>

          <div class="form-section">
            <div class="section-header">
              <div class="section-icon"><i class="fa fa-folder-open"></i></div>
              <div><h4>Add New Documents</h4><p>Upload new files to the 201 file (existing documents are kept)</p></div>
            </div>
            <!-- Existing documents display -->
            <div id="editExistingDocs" style="margin-bottom:14px;display:none;">
              <p style="font-size:12px;font-weight:600;color:#6b7280;margin-bottom:8px;">EXISTING DOCUMENTS</p>
              <div id="editExistingDocsList"></div>
            </div>
            <div id="editDocUploadList">
              <div class="doc-upload-row" data-index="0">
                <div class="form-grid" style="align-items:end;">
                  <div class="form-group">
                    <label>Document Type</label>
                    <select name="doc_type[]">
                      <option value="">— Select type —</option>
                      <option>Diploma / Transcript</option><option>Employment Contract</option>
                      <option>SSS Card / Number</option><option>PhilHealth Card</option>
                      <option>Pag-IBIG Card</option><option>TIN Card</option>
                      <option>Government ID</option><option>NBI Clearance</option>
                      <option>Medical Certificate</option><option>Certificate of Employment</option>
                      <option>Other</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>File</label>
                    <input type="file" name="doc_file[]" accept=".pdf,.jpg,.jpeg,.png" class="doc-file-input">
                    <small>PDF, JPG, PNG — max 5MB</small>
                  </div>
                  <div class="form-group" style="flex:0;min-width:80px;">
                    <button type="button" class="edu-remove-btn" onclick="removeDocRow(this)" style="display:none;margin-top:24px;">
                      <i class="fa fa-trash"></i>
                    </button>
                  </div>
                </div>
              </div>
            </div>
            <button type="button" class="edu-add-btn" onclick="addDocRow('editDocUploadList')">
              <i class="fa fa-plus"></i> Add Another Document
            </button>
          </div>
        </div><!-- /step4 -->

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- STEP 5 — ACCOUNT CREDENTIALS                           -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <div class="wiz-panel" id="editEmployeeModalStep5">
          <div class="wiz-step-hint"><i class="fa fa-circle-info"></i> Leave password blank to keep current password unchanged</div>

          <div class="form-section">
            <div class="section-header">
              <div class="section-icon"><i class="fa fa-key"></i></div>
              <div><h4>Account Credentials</h4><p>System login access — managed by admin only</p></div>
            </div>

            <div class="form-row grid-2">
              <div class="form-group">
                <label>School Email <span class="req-star">*</span></label>
                <input type="email" name="email" id="editEmail" placeholder="name@gei.edu.ph"
                       oninput="autoFillUsername('editEmail','editUsername')"
                       data-req="1" data-label="School Email">
                <small>Must end with @gei.edu.ph</small>
                <span id="editEmailError" class="field-error" style="display:none;"></span>
              </div>
              <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="editUsername" readonly style="background:#f3f4f6;color:#9ca3af;">
                <small id="editUsernameNote">Auto-filled from email prefix</small>
                <span id="editUsernameWarn" class="field-error" style="display:none;"></span>
              </div>
            </div>

            <div class="form-row grid-2">
              <div class="form-group">
                <label>System Role <span class="req-star">*</span></label>
                <select name="role" id="editRole" data-req="1" data-label="System Role">
                  <option value="">Select role...</option>
                  <option value="Admin">Admin</option>
                  <option value="Accounting">Accounting</option>
                  <option value="Employee">Employee</option>
                  <option value="Principal">Principal</option>
                </select>
              </div>
              <div class="form-group">
                <label>New Password <span class="opt">(leave blank to keep current)</span></label>
                <div class="input-toggle-wrap">
                  <input type="password" name="password" id="editPassword" placeholder="Leave blank to keep current">
                  <span class="toggle-eye" onclick="togglePassword('editPassword', this)"><i class="fa fa-eye"></i></span>
                </div>
              </div>
            </div>

            <div style="margin-bottom:14px;">
              <a href="#" class="gen-password-link" onclick="generatePassword('editPassword'); return false;">
                <i class="fa fa-rotate"></i> Generate Strong Password
              </a>
            </div>

            <hr class="section-divider">

            <label class="check-row">
              <input type="checkbox" name="send_credentials" id="editSendCreds">
              <div>
                <strong>Resend credentials via email</strong>
                <span>Employee will receive updated login details</span>
              </div>
            </label>

            <label class="toggle-row">
              <div class="toggle-switch" id="editForceToggle" onclick="toggleSwitch(this, 'editForceHidden')"></div>
              <input type="hidden" name="force_password_change" id="editForceHidden" value="0">
              <div>
                <strong>Force password change on next login</strong>
                <span>Employee must set a new password on next system access</span>
              </div>
            </label>
          </div>
        </div><!-- /step5 -->

      </div><!-- /modal-body -->

      <!-- WIZARD NAVIGATION -->
      <div class="wiz-nav">
        <div class="wiz-nav-left">
          <button type="button" class="btn-wiz-prev" id="editWizPrev" onclick="wizNav('editEmployeeModal',-1)" style="display:none;">
            <i class="fa fa-arrow-left"></i> Previous
          </button>
        </div>
        <div class="wiz-nav-right">
          <span class="wiz-req-caption"><span class="req-star">*</span> Required fields</span>
          <button type="button" class="btn-wiz-next" id="editWizNext" onclick="wizNav('editEmployeeModal',1)">
            Next <i class="fa fa-arrow-right"></i>
          </button>
          <button type="button" class="btn-save" id="editWizSave" style="display:none;"
                  onclick="prepareSave('editEmployeeForm','edit')">
            <i class="fa fa-floppy-disk"></i> Update Employee
          </button>
        </div>
      </div>

    </form>
  </div>
</div>
