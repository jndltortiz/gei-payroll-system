<?php
// Departments
$departments = $pdo->query("SELECT department_id, department_name FROM departments")->fetchAll();

// Positions
$positions = $pdo->query("SELECT position_id, position_name, department_id FROM positions")->fetchAll();

// Shifts — include all fields for preview
$shifts = $pdo->query("SELECT shift_id, shift_name, start_time, end_time, grace_period_minutes FROM shifts")->fetchAll();
?>

<div class="modal" id="editEmployeeModal">
  <div class="modal-box">

    <!-- HEADER -->
    <div class="modal-header">
      <div>
        <h3>Edit Employee Record</h3>
        <p id="editModalSubtitle">Editing: —</p>
      </div>
      <span class="close-btn" onclick="closeModal('editEmployeeModal')">&#x2715;</span>
    </div>

  <form method="POST" action="<?= BASE_URL ?>actions/employee-update.php" id="editEmployeeForm">
    <input type="hidden" name="employee_id" id="editEmployeeId">

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
            <label>First Name <span class="req"></span></label>
            <input type="text" name="first_name" id="editFirstName" placeholder="e.g. Maria">
          </div>
          <div class="form-group">
            <label>Middle Name <span class="opt">(optional)</span></label>
            <input type="text" name="middle_name" id="editMiddleName" placeholder="e.g. Dela">
          </div>
          <div class="form-group">
            <label>Last Name <span class="req"></span></label>
            <input type="text" name="last_name" id="editLastName" placeholder="e.g. Santos">
          </div>
        </div>

        <div class="form-row grid-3">
           <div class="form-row">
            <div class="form-group">
              <label>Suffix<span class="opt">(optional)</span></label>
              <input type="text" name="suffix" placeholder="e.g. Jr." id="editSuffix">
            </div>
          </div>
          <div class="form-group">
            <label>Civil Status</label>
            <select name="civil_status" id="editCivilStatus">
              <option value="">Select...</option>
              <option value="SINGLE">Single</option>
              <option value="MARRIED">Married</option>
              <option value="WIDOWED">Widowed</option>
              <option value="SEPARATED">Separated</option>
            </select>
          </div>
          <div class="form-group">
            <label>Sex</label>
            <select name="sex" id="editSex">
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
              <input type="date" name="birth_date" id="editBirthDate">
          </div>
          <div class="form-group">
            <label>Contact Number</label>
            <input 
              type="tel" 
              name="contact_no" 
              id="editContactNo"
              placeholder="09123456789"
              pattern="^(09|\+639)\d{9}$"
              maxlength="13"
            >
            <small>Format: 09123456789 or +639123456789</small>
          </div>
          <div class="form-group" style="max-width:220px;">
              <label>Employee ID</label>
              <input type="text" id="editEmployeeNo" placeholder="EMP-2024-007" readonly style="background:#f3f4f6;color:#9ca3af;">
              <small>Auto-generated on save</small>
          </div>
        </div>

        <div class="form-row grid-3">
          <div class="form-group">
            <label>Address</label>
            <textarea name="address" rows="2" id="editAddress" placeholder="Full address"></textarea>
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
            <label>Department <span class="req"></span></label>
            <select name="department_id" id="editDeptSelect" onchange="loadPositions('editDeptSelect','editPosSelect')" >
              <option value="">Select department...</option>
              <?php if (isset($departments)): foreach ($departments as $dept): ?>
                <option value="<?= $dept['department_id'] ?>"><?= $dept['department_name'] ?></option>
              <?php endforeach; endif; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Position <span class="req"></span></label>
            <select name="position_id" id="editPosSelect">
              <option value="">Select department first</option>
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
            <label>Employment Type <span class="req"></span></label>
            <select name="employment_type" id="editEmploymentType" >
              <option value="FULL_TIME">Full-time</option>
              <option value="PART_TIME">Part-time</option>
            </select>
          </div>
          <div class="form-group">
            <label>Hire Date <span class="req"></span></label>
            <input type="date" name="hire_date" id="editHireDate">
          </div>
          <div class="form-group">
            <label>Employment Status <span class="req"></span></label>
            <select name="employee_status" id="editEmployeeStatus">
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
          <label>Basic Salary (PHP) <span class="req"></span></label>
          <div class="input-prefix-wrap">
            <span class="input-prefix">&#8369;</span>
            <input type="number" name="basic_salary" id="editBasicSalary" placeholder="0.00" step="0.01" min="0" >
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
          <label>Shift Schedule <span class="req"></span></label>
          <select name="shift_id" id="editShiftSelect" onchange="previewShift('editShiftSelect','editShiftPreview','editShiftEmpty')" >
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

        <div class="shift-preview" id="editShiftPreview" style="display:none;">
          <div class="shift-preview-title" id="editShiftPreviewTitle"></div>
          <div class="shift-preview-grid">
            <div><span class="shift-label">START TIME</span><strong id="editShiftStart"></strong></div>
            <div><span class="shift-label">END TIME</span><strong id="editShiftEnd"></strong></div>
            <div><span class="shift-label">GRACE PERIOD</span><strong id="editShiftGrace"></strong></div>
          </div>
        </div>

        <div class="shift-empty-preview" id="editShiftEmpty">
          <i class="fa fa-clock"></i>
          <span>Select a shift to preview its schedule details</span>
        </div>

        <small class="shift-note">This shift will be used for attendance validation and automatic logout if the employee forgets to log out. If employee fails to log out, the system will assign the scheduled time-out to prevent payroll errors.</small>
      </div>

      <!-- ACCOUNT CREDENTIALS -->
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
            <label>Email Address <span class="req"></span></label>
            <input type="email" name="email" id="editEmail" placeholder="name@gei.edu.ph" oninput="autoFillUsername('editEmail','editUsername')">
          </div>
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" id="editUsername" placeholder="Auto-filled from email" readonly style="background:#f3f4f6;color:#9ca3af;">
            <small>Auto-filled from email prefix</small>
          </div>
        </div>

        <div class="form-row grid-2">
          <div class="form-group">
            <label>System Role <span class="req"></span></label>
            <select name="role" id="editRole" >
              <option value="">Select role...</option>
              <option value="Admin">Admin</option>
              <option value="Accounting">Accounting</option>
              <option value="Employee">Employee</option>
            </select>
          </div>
          <div class="form-group">
            <label>Password <span class="req"></span></label>
            <div class="input-toggle-wrap">
              <input type="password" name="password" id="editPassword" placeholder="••••••••••"  >
              <span class="toggle-eye" onclick="togglePassword('editPassword', this)"><i class="fa fa-eye"></i></span>
            </div>
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <a href="#" class="gen-password-link" onclick="generatePassword('editPassword'); return false;"><i class="fa fa-rotate"></i> Generate Strong Password</a>
        </div>

        <hr class="section-divider">

        <label class="check-row">
          <input type="checkbox" name="send_credentials" id="editSendCreds">
          <div>
            <strong>Send credentials via email</strong>
            <span>Employee will receive their login details at the provided email address</span>
          </div>
        </label>

        <label class="toggle-row">
          <div class="toggle-switch" id="editForceToggle" onclick="toggleSwitch(this, 'editForceHidden')"></div>
          <input type="hidden" name="force_password_change" id="editForceHidden" value="0">
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
      <div style="display:flex;gap:10px;">
        <button type="button" class="btn-cancel-modal" onclick="closeModal('editEmployeeModal')">Cancel</button>
        <button type="button" class="btn-save" onclick="prepareSave('editEmployeeForm', 'edit')">
          &rsaquo; Update Employee
        </button>
      </div>
    </div>

  </form>

  </div>
</div>