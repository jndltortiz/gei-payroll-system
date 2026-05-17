<div class="modal small" id="deactEmployeeModal">
  <div class="modal-box modal-box-confirm">
    <div class="confirm-icon danger"><i class="fa fa-triangle-exclamation"></i></div>
    <h3>Deactivate Employee</h3>
    <p id="deactMsg">Are you sure you want to deactivate this employee? They will lose system access.</p>
    <form method="POST" action="<?= BASE_URL ?>actions/employee-deactivate.php">
      <input type="hidden" name="employee_id" id="deact_id">
      <div class="confirm-footer">
        <button type="button" class="btn-cancel-modal" onclick="closeModal('deactEmployeeModal')">Cancel</button>
        <button type="submit" class="btn-danger-confirm">Yes, Deactivate</button>
      </div>
    </form>
  </div>
</div>