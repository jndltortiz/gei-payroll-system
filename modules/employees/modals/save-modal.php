<!-- ADD CONFIRMATION MODAL -->
<div class="modal small" id="saveAddModal">
  <div class="modal-box modal-box-confirm">
    <div class="confirm-icon success"><i class="fa fa-circle-check"></i></div>
    <h3 id="saveAddTitle">Add New Employee</h3>
    <p id="saveAddMsg">Add this employee? Their account credentials will be sent via email.</p>
    <div class="confirm-footer">
      <button class="btn-cancel-modal" onclick="closeModal('saveAddModal')">Cancel</button>
      <button type="button" class="btn-save" onclick="confirmSave('add')">
        Yes, Add Employee
      </button>
    </div>
  </div>
</div>

<!-- EDIT CONFIRMATION MODAL -->
<div class="modal small" id="saveEditModal">
  <div class="modal-box modal-box-confirm">
    <div class="confirm-icon success"><i class="fa fa-circle-check"></i></div>
    <h3>Save Employee Changes</h3>
    <p>Are you sure you want to save the changes to this employee record?</p>
    <div class="confirm-footer">
      <button class="btn-cancel-modal" onclick="closeModal('saveEditModal')">Cancel</button>
      <button type="button" class="btn-save" id="confirmEditBtn" onclick="confirmSave('edit')">
        Yes, Save Changes
      </button>
    </div>
  </div>
</div>
