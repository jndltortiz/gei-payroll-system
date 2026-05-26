<div class="modal small" id="deactEmployeeModal">
  <div class="modal-box modal-box-confirm">
    <div class="confirm-icon danger"><i class="fa fa-user-slash"></i></div>
    <h3>Deactivate Employee</h3>

    <!-- Employee snapshot (populated by JS) -->
    <div class="deact-employee-snap" id="deactEmployeeSnap" style="display:none;">
      <div class="deact-snap-avatar" id="deactSnapAvatar"></div>
      <div class="deact-snap-info">
        <strong id="deactSnapName"></strong>
        <span id="deactSnapDept"></span>
      </div>
    </div>

    <p id="deactMsg">Are you sure you want to deactivate this employee? They will lose system access.</p>
    <p style="font-size:12px;color:#6b7280;margin-top:4px;">
      <i class="fa fa-circle-info" style="margin-right:4px;"></i>
      Payroll history, leave records, loans, and all HR data remain intact.
    </p>

    <form method="POST" action="<?= BASE_URL ?>actions/employee-deactivate.php">
      <input type="hidden" name="employee_id" id="deact_id">
      <div class="confirm-footer">
        <button type="button" class="btn-cancel-modal" onclick="closeModal('deactEmployeeModal')">Cancel</button>
        <button type="submit" class="btn-danger-confirm">
          <i class="fa fa-user-slash"></i> Yes, Deactivate
        </button>
      </div>
    </form>
  </div>
</div>
