<div class="modal" id="viewEmployeeModal">
  <div class="modal-box modal-box-profile">

    <!-- HEADER (dynamic) -->
    <div class="profile-header" id="profileHeader">
      <div class="profile-header-avatar" id="profileAvatar">MS</div>
      <div class="profile-header-info">
        <h3 id="profileName"></h3>
        <p id="profileSubtitle"></p>
        <span class="badge" id="profileStatusBadge"></span>
      </div>
      <span class="close-btn-plain" onclick="closeModal('viewEmployeeModal')">&#x2715;</span>
    </div>

    <!-- TABS -->
    <div class="profile-tabs">
      <button class="ptab active" data-tab="ptab-overview" onclick="switchProfileTab(this,'ptab-overview')">
        <i class="fa fa-user"></i> Overview
      </button>
      <button class="ptab" data-tab="ptab-job" onclick="switchProfileTab(this,'ptab-job')">
        <i class="fa fa-briefcase"></i> Job &amp; Payroll
      </button>
      <button class="ptab" data-tab="ptab-govt" onclick="switchProfileTab(this,'ptab-govt')">
        <i class="fa fa-id-card"></i> Benefits
      </button>
      <button class="ptab" data-tab="ptab-edu" onclick="switchProfileTab(this,'ptab-edu')">
        <i class="fa fa-graduation-cap"></i> Education
      </button>
      <button class="ptab" data-tab="ptab-leave" onclick="switchProfileTab(this,'ptab-leave')">
        <i class="fa fa-calendar-days"></i> Leave &amp; Loans
      </button>
      <button class="ptab" data-tab="ptab-account" onclick="switchProfileTab(this,'ptab-account')">
        <i class="fa fa-key"></i> Account
      </button>
    </div>

    <div class="modal-body profile-body">

      <!-- ── TAB: OVERVIEW ─────────────────────────────────────── -->
      <div class="ptab-panel active" id="ptab-overview">
        <div class="profile-section-grid">

          <div class="profile-info-card">
            <div class="profile-info-title"><i class="fa fa-address-card"></i> Personal Details</div>
            <div class="profile-detail-row">
              <span class="pdr-label">Full Legal Name</span>
              <span class="pdr-val" id="pvLegalName"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Date of Birth</span>
              <span class="pdr-val" id="pvBirthDate"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Sex</span>
              <span class="pdr-val" id="pvSex"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Civil Status</span>
              <span class="pdr-val" id="pvCivilStatus"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Contact Number</span>
              <span class="pdr-val" id="pvContact"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Personal Email</span>
              <span class="pdr-val" id="pvPersonalEmail"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">School Email</span>
              <span class="pdr-val" id="pvSchoolEmail"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Address</span>
              <span class="pdr-val" id="pvAddress"></span>
            </div>
          </div>

          <div class="profile-info-card">
            <div class="profile-info-title"><i class="fa fa-phone-volume"></i> Emergency Contact</div>
            <div class="profile-detail-row">
              <span class="pdr-label">Contact Name</span>
              <span class="pdr-val" id="pvEcName"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Relationship</span>
              <span class="pdr-val" id="pvEcRelation"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Contact Number</span>
              <span class="pdr-val" id="pvEcNumber"></span>
            </div>
          </div>

        </div>
      </div><!-- /ptab-overview -->

      <!-- ── TAB: JOB & PAYROLL ────────────────────────────────── -->
      <div class="ptab-panel" id="ptab-job">
        <div class="profile-section-grid">

          <div class="profile-info-card">
            <div class="profile-info-title"><i class="fa fa-briefcase"></i> Employment Details</div>
            <div class="profile-detail-row">
              <span class="pdr-label">Employee ID</span>
              <span class="pdr-val" id="pvEmpNo"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Department</span>
              <span class="pdr-val" id="pvDept"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Position</span>
              <span class="pdr-val" id="pvPosition"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Employment Type</span>
              <span class="pdr-val" id="pvEmpType"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Date Hired</span>
              <span class="pdr-val" id="pvHireDate"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Shift Schedule</span>
              <span class="pdr-val" id="pvShift"></span>
            </div>
          </div>

          <div class="profile-info-card">
            <div class="profile-info-title"><i class="fa fa-peso-sign"></i> Payroll Summary</div>
            <div class="profile-detail-row">
              <span class="pdr-label">Monthly Salary</span>
              <strong class="pdr-val pdr-highlight" id="pvSalary"></strong>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Daily Rate</span>
              <span class="pdr-val" id="pvDailyRate"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Last Payslip</span>
              <span class="pdr-val" id="pvLastPayslip"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Last Net Pay</span>
              <span class="pdr-val" id="pvLastNetPay"></span>
            </div>
          </div>

        </div>
      </div><!-- /ptab-job -->

      <!-- ── TAB: BENEFITS / GOVT IDs ──────────────────────────── -->
      <div class="ptab-panel" id="ptab-govt">
        <div class="profile-info-card profile-info-card--full">
          <div class="profile-info-title"><i class="fa fa-id-card"></i> Government IDs &amp; Contributions</div>
          <div class="profile-detail-grid">
            <div class="profile-detail-row">
              <span class="pdr-label">SSS Number</span>
              <span class="pdr-val" id="pvSss"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">PhilHealth Number</span>
              <span class="pdr-val" id="pvPhilhealth"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Pag-IBIG / HDMF</span>
              <span class="pdr-val" id="pvPagibig"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">TIN Number</span>
              <span class="pdr-val" id="pvTin"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">PERAA Number</span>
              <span class="pdr-val" id="pvPeraa"></span>
            </div>
          </div>
        </div>
      </div><!-- /ptab-govt -->

      <!-- ── TAB: EDUCATION & DOCUMENTS ───────────────────────── -->
      <div class="ptab-panel" id="ptab-edu">
        <div class="profile-info-card profile-info-card--full" style="margin-bottom:14px;">
          <div class="profile-info-title"><i class="fa fa-graduation-cap"></i> Educational Background</div>
          <div id="pvEduList">
            <p class="pv-empty-note">No education records on file.</p>
          </div>
        </div>
        <div class="profile-info-card profile-info-card--full">
          <div class="profile-info-title"><i class="fa fa-folder-open"></i> 201 File Documents</div>
          <div id="pvDocList">
            <p class="pv-empty-note">No documents on file.</p>
          </div>
        </div>
      </div><!-- /ptab-edu -->

      <!-- ── TAB: LEAVE & LOANS ───────────────────────────────── -->
      <div class="ptab-panel" id="ptab-leave">
        <div class="profile-section-grid">

          <div class="profile-info-card">
            <div class="profile-info-title"><i class="fa fa-calendar-days"></i> Leave Balances</div>
            <div id="pvLeaveBalances">
              <p class="pv-empty-note">No leave balance data available.</p>
            </div>
          </div>

          <div class="profile-info-card">
            <div class="profile-info-title"><i class="fa fa-hand-holding-dollar"></i> Active Loans</div>
            <div id="pvLoans">
              <p class="pv-empty-note">No active loans.</p>
            </div>
          </div>

        </div>

        <div class="profile-info-card profile-info-card--full" style="margin-top:14px;">
          <div class="profile-info-title"><i class="fa fa-medal"></i> Service Credits</div>
          <div id="pvServiceCredits">
            <p class="pv-empty-note">No service credit data available.</p>
          </div>
        </div>
      </div><!-- /ptab-leave -->

      <!-- ── TAB: ACCOUNT ─────────────────────────────────────── -->
      <div class="ptab-panel" id="ptab-account">
        <div class="profile-info-card profile-info-card--full">
          <div class="profile-info-title"><i class="fa fa-key"></i> Account Information</div>
          <div class="profile-detail-grid">
            <div class="profile-detail-row">
              <span class="pdr-label">Username</span>
              <span class="pdr-val" id="pvUsername"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">School Email</span>
              <span class="pdr-val" id="pvAccountEmail"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">System Role</span>
              <span class="pdr-val" id="pvRole"></span>
            </div>
            <div class="profile-detail-row">
              <span class="pdr-label">Account Status</span>
              <span class="pdr-val" id="pvAccountStatus"></span>
            </div>
          </div>
          <div class="info-box" style="margin-top:16px;">
            <i class="fa fa-lock" style="color:#d97706;margin-right:6px;"></i>
            Password is not displayed for security. Use the Edit form to change credentials.
          </div>
        </div>
      </div><!-- /ptab-account -->

    </div><!-- /profile-body -->

    <div class="modal-footer">
      <button type="button" class="btn-outline" onclick="openEditFromView()">
        <i class="fa fa-pen"></i> Edit
      </button>
      <button type="button" class="btn-cancel-modal" onclick="closeModal('viewEmployeeModal')">Close</button>
    </div>

  </div>
</div>
