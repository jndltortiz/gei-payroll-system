<!-- ── File a Leave Modal ──────────────────────────────────────────────────── -->
<div class="pr-modal-overlay" id="fileLeaveModal">
  <div class="pr-modal-box pr-modal-box--md">
    <div class="pr-modal-header">
      <h3><i class="fa fa-calendar-plus" style="color:#0f766e;margin-right:6px;"></i> File a Leave</h3>
      <button onclick="closeModal('fileLeaveModal')" title="Close"><i class="fa fa-times"></i></button>
    </div>
    <form id="fileLeaveForm" novalidate>
      <div class="pr-modal-body">

        <div class="lv-form-group">
          <label class="lv-form-label" for="leaveType">
            Leave Type <span class="req">*</span>
          </label>
          <select id="leaveType" name="leave_type_id" class="lv-form-control" required>
            <option value="">Select leave type...</option>
            <?php if (!empty($leave_types)): foreach ($leave_types as $lt): ?>
            <option value="<?= $lt['leave_type_id'] ?>">
              <?= htmlspecialchars($lt['leave_name']) ?>
            </option>
            <?php endforeach; endif; ?>
          </select>
        </div>

        <div class="lv-form-row">
          <div class="lv-form-group">
            <label class="lv-form-label" for="leaveStart">
              Start Date <span class="req">*</span>
            </label>
            <input type="date" id="leaveStart" name="start_date"
                   class="lv-form-control" required>
          </div>
          <div class="lv-form-group">
            <label class="lv-form-label" for="leaveEnd">
              End Date <span class="req">*</span>
            </label>
            <input type="date" id="leaveEnd" name="end_date"
                   class="lv-form-control" required>
          </div>
        </div>

        <div class="lv-form-group">
          <label class="lv-form-label" for="leaveReason">
            Reason <span class="req">*</span>
          </label>
          <textarea id="leaveReason" name="reason" class="lv-form-control" rows="3"
                    placeholder="Briefly describe the reason for your leave..."
                    required></textarea>
        </div>

        <div class="lv-form-group">
          <label class="lv-form-label">
            Attachment <span class="opt">(optional)</span>
          </label>
          <div class="lv-file-area" onclick="document.getElementById('leaveAttachment').click()">
            <i class="fa fa-paperclip"></i>
            <span>Drop file here or <span class="lv-file-link">browse</span></span>
            <span class="lv-file-hint">PDF, JPG, PNG — max 5 MB</span>
            <input type="file" id="leaveAttachment" name="attachment"
                   accept=".pdf,.jpg,.jpeg,.png" class="lv-file-hidden">
          </div>
          <div id="fileNameDisplay" class="lv-file-name"></div>
        </div>

      </div>
      <div class="pr-modal-footer">
        <button type="button" class="pr-btn-cancel"
                onclick="closeModal('fileLeaveModal')">Cancel</button>
        <button type="submit" class="pr-btn-approve-confirm" id="submitLeaveBtn">
          <i class="fa fa-paper-plane"></i> Submit Leave
        </button>
      </div>
    </form>
  </div>
</div>