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
      <h3>Review Loan Application <span style="font-size:13px;font-weight:400;color:#64748b;">(Principal Approval)</span></h3>
      <button onclick="closePrincipalReview()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body" id="principalReviewBody">
      <div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
    </div>
  </div>
</div>

<!-- DENY REASON SUB-MODAL -->
<div class="loan-modal-overlay" id="denyReasonOverlay" style="z-index:1100">
  <div class="loan-modal-box" style="max-width:420px">
    <div class="loan-modal-header">
      <h3><i class="fa fa-times-circle" style="color:#ef4444"></i> Deny Loan Application</h3>
      <button onclick="closeDenyReason()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body">
      <p style="font-size:13px;color:#374151;margin-bottom:12px;">
        You are about to <strong>deny</strong> the loan application for
        <strong id="deny-emp-name-display">—</strong>. This cannot be undone.
      </p>
      <label style="font-size:11px;font-weight:700;letter-spacing:.06em;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:6px;">
        REASON FOR DENIAL <span style="color:#ef4444">*</span>
      </label>
      <textarea id="principal-deny-reason" rows="3"
                style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;resize:vertical;"
                placeholder="Provide a reason for denying this application…"></textarea>
      <p id="deny-reason-error" style="display:none;font-size:12px;color:#ef4444;margin-top:4px;">Reason is required.</p>
      <div id="deny-reason-flash" style="display:none" class="loan-flash"></div>
    </div>
    <div class="loan-modal-footer">
      <button class="btn-outline" onclick="closeDenyReason()">Cancel</button>
      <button class="btn-deny" id="confirm-deny-btn" onclick="confirmPrincipalDeny()">
        <i class="fa fa-times"></i> Confirm Denial
      </button>
    </div>
  </div>
</div>

<!-- CONFIRM APPROVE SUB-MODAL -->
<div class="loan-modal-overlay" id="confirmApproveOverlay" style="z-index:1100">
  <div class="loan-modal-box" style="max-width:420px">
    <div class="loan-modal-header">
      <h3><i class="fa fa-circle-check" style="color:#16a34a"></i> Confirm Loan Approval</h3>
      <button onclick="closeConfirmApprove()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body">
      <div style="text-align:center;margin-bottom:16px;">
        <i class="fa fa-circle-check" style="font-size:2.5rem;color:#16a34a;"></i>
      </div>
      <p style="text-align:center;font-size:14px;color:#374151;margin-bottom:16px;">
        Approve the <strong id="ca-loan-type">—</strong> for
        <strong id="ca-emp-name">—</strong>?
      </p>
      <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px solid #f3f4f6;">
          <span style="color:#64748b">Amount</span><strong id="ca-amount">—</strong>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px solid #f3f4f6;">
          <span style="color:#64748b">Monthly Deduction</span><strong id="ca-monthly">—</strong>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;">
          <span style="color:#64748b">Term</span><strong id="ca-term">—</strong>
        </div>
      </div>
      <p style="text-align:center;font-size:12px;color:#9ca3af;">
        This will activate the loan and begin salary deductions on the next payroll period.
      </p>
      <div id="confirm-approve-flash" style="display:none" class="loan-flash"></div>
    </div>
    <div class="loan-modal-footer">
      <button class="btn-outline" onclick="closeConfirmApprove()">Cancel</button>
      <button class="btn-approve" id="confirm-approve-btn" onclick="submitPrincipalApprove()">
        <i class="fa fa-check"></i> Yes, Approve Loan
      </button>
    </div>
  </div>
</div>