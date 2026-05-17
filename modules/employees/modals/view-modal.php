<div class="modal" id="viewEmployeeModal">
  <div class="modal-box modal-box-sm">

    <div class="modal-header-plain">
      <h3>Employee Details</h3>
      <span class="close-btn-plain" onclick="closeModal('viewEmployeeModal')">&#x2715;</span>
    </div>

    <div class="modal-body">

      <!-- AVATAR + NAME -->
      <div class="view-profile-row">
        <div class="view-avatar" id="viewAvatar">MS</div>
        <div>
          <h4 id="viewName"></h4>
          <p id="viewPositionDept" style="color:#6b7280;font-size:13px;margin:2px 0 6px;"></p>
          <span class="badge" id="viewStatusBadge"></span>
        </div>
      </div>

      <hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;">

      <!-- DETAIL GRID -->
      <div class="view-grid">
        <div>
          <span class="view-label">EMPLOYEE NO</span>
          <strong id="viewEmpId"></strong>
        </div>
        <div>
          <span class="view-label">EMAIL</span>
          <strong id="viewEmail"></strong>
        </div>
        <div>
          <span class="view-label">EMPLOYMENT</span>
          <strong id="viewEmployment"></strong>
        </div>
        <div>
          <span class="view-label">SHIFT</span>
          <strong id="viewShift"></strong>
        </div>
        <div>
          <span class="view-label">BASIC SALARY</span>
          <strong id="viewSalary"></strong>
        </div>
        <div>
          <span class="view-label">SYSTEM ROLE</span>
          <strong id="viewRole"></strong>
        </div>
        <div>
          <span class="view-label">DATE HIRED</span>
          <strong id="viewDateHired"></strong>
        </div>
        <div>
          <span class="view-label">PHONE</span>
          <strong id="viewPhone"></strong>
        </div>
      </div>

    </div>

    <div class="modal-footer">
      <button type="button" class="btn-outline" onclick="openEditFromView()"><i class="fa fa-pen"></i> Edit</button>
      <button type="button" class="btn-cancel-modal" onclick="closeModal('viewEmployeeModal')">Close</button>
    </div>

  </div>
</div>