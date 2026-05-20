<?php // modules/principal/leave-approval/modals/file-leave-modal.php ?>
<div class="modal-overlay" id="fileLeaveModal" role="dialog" aria-modal="true" aria-labelledby="fileLeaveTitle">
    <div class="modal-box modal-md">
        <div class="modal-header">
            <h3 class="modal-title" id="fileLeaveTitle">File a Leave</h3>
            <button class="modal-close" data-close="fileLeaveModal"><i data-lucide="x"></i></button>
        </div>
        <form id="fileLeaveForm" novalidate>
            <div class="modal-body">

                <div class="form-group">
                    <label class="form-label" for="leaveType">Leave Type <span class="req">*</span></label>
                    <select id="leaveType" name="leave_type_id" class="form-control" required>
                        <option value="">Select leave type...</option>
                        <?php $leave_types = $leave_types ?? []; foreach ($leave_types as $lt): ?>
                        <option value="<?= $lt['leave_type_id'] ?>"><?= htmlspecialchars($lt['leave_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="leaveStart">Start Date <span class="req">*</span></label>
                        <input type="date" id="leaveStart" name="start_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="leaveEnd">End Date <span class="req">*</span></label>
                        <input type="date" id="leaveEnd" name="end_date" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="leaveReason">Reason <span class="req">*</span></label>
                    <textarea id="leaveReason" name="reason" class="form-control" rows="3"
                              placeholder="Briefly describe the reason for your leave..." required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="leaveAttachment">Attachment <span class="optional">(optional)</span></label>
                    <div class="file-upload-area" id="fileUploadArea">
                        <i data-lucide="paperclip"></i>
                        <span>Drop file here or <label for="leaveAttachment" class="file-link">browse</label></span>
                        <span class="file-hint">PDF, JPG, PNG – max 5MB</span>
                        <input type="file" id="leaveAttachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png" class="file-input-hidden">
                    </div>
                    <div id="fileNameDisplay" class="file-name-display hidden"></div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-close="fileLeaveModal">Cancel</button>
                <button type="submit" class="btn-primary" id="submitLeaveBtn">
                    <i data-lucide="send"></i> Submit Leave
                </button>
            </div>
        </form>
    </div>
</div>