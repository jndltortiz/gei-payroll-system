<?php
/**
 * modals/modal-principal-details.php
 * Read-only Loan Details modal for Principal.
 * Content populated by principal-loan-approval.js → buildPrincipalDetailsContent()
 */
?>
<!-- PRINCIPAL LOAN DETAILS MODAL -->
<div class="loan-modal-overlay" id="principalDetailsOverlay">
  <div class="loan-modal-box">
    <div class="loan-modal-header">
      <h3>Loan Details</h3>
      <button onclick="closePrincipalDetails()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body" id="principalDetailsBody">
      <div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
    </div>
  </div>
</div>