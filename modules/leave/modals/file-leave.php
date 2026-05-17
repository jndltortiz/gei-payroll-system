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