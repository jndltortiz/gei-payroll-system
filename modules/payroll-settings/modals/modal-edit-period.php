<?php
/**
 * modals/modal-edit-period.php
 * Edit an OPEN pay period (name and pay date only).
 * $pdo and $settings are available from parent include.
 */
?>

<!-- ========== EDIT PAY PERIOD MODAL ========== -->
<div class="modal fade" id="modalEditPeriod" tabindex="-1" aria-labelledby="modalEditPeriodLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;border:none;">

      <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:18px 20px 14px;">
        <h5 class="modal-title" id="modalEditPeriodLabel" style="font-size:16px;font-weight:700;">
          <i class="bi bi-pencil-square" style="color:#0d9488;margin-right:6px;"></i>
          Edit Pay Period
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body" style="padding:18px 20px;">
        <input type="hidden" id="epId">

        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
            Period Name <span style="color:#ef4444;">*</span>
          </label>
          <input type="text" id="epName" class="ps-form-select"
                 placeholder="e.g. June 1–15, 2026" style="width:100%;">
        </div>

        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
            Pay Date <span style="color:#ef4444;">*</span>
          </label>
          <input type="date" id="epPayDate" class="ps-form-select" style="width:100%;">
          <div style="font-size:11.5px;color:#64748b;margin-top:5px;">
            Adjust if the pay day falls on a weekend or public holiday.
          </div>
        </div>

        <div id="epRecordNote" style="display:none;background:#fff7ed;border:1px solid #fed7aa;
             border-radius:8px;padding:10px 14px;font-size:12px;color:#92400e;">
          <i class="bi bi-exclamation-triangle-fill" style="margin-right:5px;"></i>
          <span id="epRecordNoteText"></span>
        </div>
      </div>

      <div class="modal-footer" style="border-top:1px solid #f1f5f9;padding:14px 20px;">
        <button type="button" class="ps-btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="ps-btn-primary" id="epSaveBtn" onclick="submitEditPeriod()">
          <i class="bi bi-floppy"></i> Save Changes
        </button>
      </div>

    </div>
  </div>
</div>
