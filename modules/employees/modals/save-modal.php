<!-- ADD CONFIRMATION MODAL -->
<div class="modal small" id="saveAddModal">
  <div class="modal-box modal-box-confirm">
    <div class="confirm-icon success"><i class="fa fa-circle-check"></i></div>
    <h3 id="saveAddTitle">Add New Employee</h3>
    <p id="saveAddMsg">Add this employee and create their login account?</p>
    <div class="confirm-footer">
      <button class="btn-cancel-modal" onclick="closeModal('saveAddModal')">Cancel</button>
      <button type="button" class="btn-save" onclick="confirmSave('add')">
        Yes, Add Employee
      </button>
    </div>
  </div>
</div>

<!-- CREATED CREDENTIALS MODAL -->
<div class="modal small" id="createdCredentialsModal">
  <div class="modal-box modal-box-confirm">
    <div class="confirm-icon success"><i class="fa fa-key"></i></div>
    <h3>Employee Account Created</h3>
    <p id="createdCredentialsMsg">Copy these credentials now and relay them to the employee.</p>

    <div style="text-align:left;margin:16px 0;display:grid;gap:12px;">
      <label style="display:grid;gap:6px;font-size:13px;color:#374151;font-weight:700;">
        Username
        <div style="display:flex;gap:8px;">
          <input type="text" id="createdUsername" readonly
                 style="flex:1;background:#f9fafb;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-weight:700;">
          <button type="button" class="btn-cancel-modal" onclick="copyCreatedCredential('createdUsername')">
            <i class="fa fa-copy"></i>
          </button>
        </div>
      </label>

      <label style="display:grid;gap:6px;font-size:13px;color:#374151;font-weight:700;">
        Temporary Password
        <div style="display:flex;gap:8px;">
          <input type="text" id="createdPassword" readonly
                 style="flex:1;background:#f9fafb;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-weight:700;">
          <button type="button" class="btn-cancel-modal" onclick="copyCreatedCredential('createdPassword')">
            <i class="fa fa-copy"></i>
          </button>
        </div>
      </label>

      <button type="button" class="btn-save" onclick="copyCreatedCredentials()">
        <i class="fa fa-copy"></i> Copy Username and Password
      </button>
    </div>

    <p style="font-size:12px;color:#6b7280;margin-top:4px;">
      Store or share these carefully. The temporary password will not be shown again after this screen closes.
    </p>

    <div class="confirm-footer">
      <button type="button" class="btn-save" onclick="finishCreatedCredentials()">
        Done
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
