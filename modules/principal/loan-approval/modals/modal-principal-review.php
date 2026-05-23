<?php
/**
 * modals/modal-principal-review.php
 * Review Loan Application modal for Principal.
 * Dynamically populated by principal-loan-approval.js → buildPrincipalReviewContent()
 */
?>
<!-- PRINCIPAL REVIEW MODAL -->
<div class="loan-modal-overlay" id="principalReviewOverlay">
  <div class="loan-modal-box">
    <div class="loan-modal-header">
      <h3>Review Submitted Loan Document <span style="font-size:13px;font-weight:400;color:#64748b;">(Principal Verification)</span></h3>
      <button onclick="closePrincipalReview()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body" id="principalReviewBody">
      <div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
    </div>
  </div>
</div>

<!-- REJECT RECORD SUB-MODAL -->
<div class="loan-modal-overlay" id="denyReasonOverlay" style="z-index:1100">
  <div class="loan-modal-box" style="max-width:420px">
    <div class="loan-modal-header">
      <h3><i class="fa fa-times-circle" style="color:#ef4444"></i> Reject Loan Record</h3>
      <button onclick="closeDenyReason()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body">
      <p style="font-size:13px;color:#374151;margin-bottom:12px;">
        You are about to <strong>permanently reject</strong> the loan record for
        <strong id="deny-emp-name-display">—</strong>. This cannot be undone.
      </p>
      <label style="font-size:11px;font-weight:700;letter-spacing:.06em;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:6px;">
        REASON FOR REJECTION <span style="color:#ef4444">*</span>
      </label>
      <textarea id="principal-deny-reason" rows="3"
                style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;resize:vertical;"
                placeholder="Provide a reason for rejecting this record…"></textarea>
      <p id="deny-reason-error" style="display:none;font-size:12px;color:#ef4444;margin-top:4px;">Reason is required.</p>
      <div id="deny-reason-flash" style="display:none" class="loan-flash"></div>
    </div>
    <div class="loan-modal-footer">
      <button class="btn-outline" onclick="closeDenyReason()">Cancel</button>
      <button class="btn-deny" id="confirm-deny-btn" onclick="confirmPrincipalDeny()">
        <i class="fa fa-times"></i> Confirm Rejection
      </button>
    </div>
  </div>
</div>

<!-- RETURN FOR CORRECTION SUB-MODAL -->
<div class="loan-modal-overlay" id="returnForCorrectionOverlay" style="z-index:1100">
  <div class="loan-modal-box" style="max-width:420px">
    <div class="loan-modal-header">
      <h3><i class="fa fa-rotate-left" style="color:#f59e0b"></i> Return for Correction</h3>
      <button onclick="closeReturnForCorrection()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body">
      <p style="font-size:13px;color:#374151;margin-bottom:12px;">
        Return the loan record for <strong id="return-emp-name-display">—</strong> to Admin for correction.
        The record will remain in <em>Returned for Correction</em> status and payroll deductions
        will not be activated until the corrected record is re-reviewed and approved.
      </p>
      <label style="font-size:11px;font-weight:700;letter-spacing:.06em;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:6px;">
        REASON / WHAT NEEDS CORRECTION <span style="color:#f59e0b">*</span>
      </label>
      <textarea id="principal-return-reason" rows="3"
                style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;resize:vertical;"
                placeholder="Describe what needs to be corrected…"></textarea>
      <p id="return-reason-error" style="display:none;font-size:12px;color:#ef4444;margin-top:4px;">Reason is required.</p>
      <div id="return-reason-flash" style="display:none" class="loan-flash"></div>
    </div>
    <div class="loan-modal-footer">
      <button class="btn-outline" onclick="closeReturnForCorrection()">Cancel</button>
      <button class="btn-warn" id="confirm-return-btn" onclick="confirmPrincipalReturn()"
              style="background:#f59e0b;color:#fff;border:none;padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
        <i class="fa fa-rotate-left"></i> Return for Correction
      </button>
    </div>
  </div>
</div>

<!-- CONFIRM APPROVE SUB-MODAL -->
<div class="loan-modal-overlay" id="confirmApproveOverlay" style="z-index:1100">
  <div class="loan-modal-box" style="max-width:420px">
    <div class="loan-modal-header">
      <h3><i class="fa fa-circle-check" style="color:#16a34a"></i> Approve for Payroll Deduction</h3>
      <button onclick="closeConfirmApprove()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body">
      <div style="text-align:center;margin-bottom:16px;">
        <i class="fa fa-circle-check" style="font-size:2.5rem;color:#16a34a;"></i>
      </div>
      <p style="text-align:center;font-size:14px;color:#374151;margin-bottom:16px;">
        Approve payroll deduction for the <strong id="ca-loan-type">—</strong> of
        <strong id="ca-emp-name">—</strong>?
      </p>
      <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px solid #f3f4f6;">
          <span style="color:#64748b">Amount</span><strong id="ca-amount">—</strong>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px solid #f3f4f6;">
          <span style="color:#64748b">Monthly Amortization</span><strong id="ca-monthly">—</strong>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;">
          <span style="color:#64748b">Term</span><strong id="ca-term">—</strong>
        </div>
      </div>
      <p style="text-align:center;font-size:12px;color:#9ca3af;">
        This will activate the loan record and begin automatic payroll deductions on the next payroll run.
      </p>
      <div id="confirm-approve-flash" style="display:none" class="loan-flash"></div>
    </div>
    <div class="loan-modal-footer">
      <button class="btn-outline" onclick="closeConfirmApprove()">Cancel</button>
      <button class="btn-approve" id="confirm-approve-btn" onclick="submitPrincipalApprove()">
        <i class="fa fa-check"></i> Approve for Payroll Deduction
      </button>
    </div>
  </div>
</div>