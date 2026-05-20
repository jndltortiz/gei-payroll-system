<?php
// $leaveTypes is passed from index.php via include scope.
// This guard silences static analysis warnings and prevents errors
// if this file is ever loaded in isolation.
if (!isset($leaveTypes)) {
    $leaveTypes = [];
}
?>
<!-- FILE A LEAVE MODAL -->
<div class="modal-overlay" id="fileLeaveOverlay" style="display:none;" onclick="closeFileLeave(event)">
    <div class="modal-box modal-box--md" onclick="event.stopPropagation()">

        <div class="modal-header">
            <h3 class="modal-title">File a Leave Request</h3>
            <button class="modal-close" onclick="closeFileLeaveModal()">×</button>
        </div>

        <div class="modal-body" id="fileLeaveBody">
            <form id="fileLeaveForm">

                <!-- Leave Type -->
                <div class="form-group">
                    <label class="form-label">Leave Type</label>
                    <select class="form-select" id="leaveTypeSelect" name="leave_type_id" required>
                        <option value="">Select leave type...</option>
                        <?php foreach ($leaveTypes as $lt): ?>
                            <option value="<?= $lt['leave_type_id'] ?>">
                                <?= htmlspecialchars($lt['leave_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Calendar Date Picker -->
                <div class="form-group">
                    <label class="form-label">
                        Select Dates
                        <span class="form-label--hint">(Non-continuous dates allowed)</span>
                    </label>

                    <div class="calendar-wrapper">
                        <div class="calendar-nav">
                            <span class="cal-month-label" id="calMonthLabel">May 2026</span>
                            <div class="cal-nav-btns">
                                <button type="button" class="cal-nav-btn" id="calPrev">&#8249;</button>
                                <button type="button" class="cal-nav-btn" id="calNext">&#8250;</button>
                            </div>
                        </div>
                        <table class="calendar-table" id="calendarGrid">
                            <thead>
                                <tr>
                                    <th>SU</th><th>MO</th><th>TU</th><th>WE</th><th>TH</th><th>FR</th><th>SA</th>
                                </tr>
                            </thead>
                            <tbody id="calendarBody"></tbody>
                        </table>
                        <p class="cal-hint">Click on individual dates to select them.</p>
                    </div>

                    <!-- Selected Date Tags -->
                    <div class="selected-dates" id="selectedDateTags"></div>

                    <!-- Notice -->
                    <div class="cal-notice">
                        <i class="fa fa-circle-exclamation"></i>
                        Each selected date will be reviewed individually by the approver.
                    </div>
                </div>

                <!-- Reason -->
                <div class="form-group">
                    <label class="form-label">Reason</label>
                    <textarea class="form-textarea" id="leaveReason" name="reason" rows="3"
                        placeholder="Please provide a brief description..." required></textarea>
                </div>

                <div class="leave-form-group" style="margin-top: 14px;">
                    <label class="form-label">
                        Supporting Document
                        <span style="font-size:11px;color:#9ca3af;font-weight:400;">
                            (optional — required for Sick Leave)
                        </span>
                    </label>
                    <div class="file-upload-area" id="leaveFileArea"
                         onclick="document.getElementById('leaveFileInput').click()"
                         ondragover="event.preventDefault();this.classList.add('drag-over')"
                         ondragleave="this.classList.remove('drag-over')"
                         ondrop="handleLeaveFileDrop(event)">
                        <i class="fa fa-file-arrow-up" style="font-size:24px;color:#94a3b8;margin-bottom:6px;"></i>
                        <p style="font-size:13px;color:#6b7280;margin:0;">
                            Click or drag to upload<br>
                            <small style="color:#9ca3af;">PDF, JPG, PNG — max 5MB</small>
                        </p>
                        <input type="file" id="leaveFileInput" name="attachment"
                               accept=".pdf,.jpg,.jpeg,.png" style="display:none"
                               onchange="onLeaveFileSelected(this)">
                    </div>
                    <div id="leaveFilePreview" style="display:none;margin-top:8px;padding:10px 12px;
                         background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;
                         display:none;align-items:center;gap:10px;font-size:13px;">
                        <i class="fa fa-file" style="color:#0f766e;font-size:18px;"></i>
                        <span id="leaveFileName" style="flex:1;">—</span>
                        <button type="button" onclick="clearLeaveFile()"
                                style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:12px;">
                            <i class="fa fa-times"></i> Remove
                        </button>
                    </div>
                </div>

                <div class="modal-footer modal-footer--form">
                    <button type="button" class="btn btn--ghost" onclick="closeFileLeaveModal()">Cancel</button>
                    <button type="button" class="btn btn--teal" id="btnSubmitLeave" onclick="submitFileLeave()">
                        <i class="fa fa-calendar-check"></i> Submit Request
                    </button>
                </div>

            </form>
        </div>

    </div>
</div>