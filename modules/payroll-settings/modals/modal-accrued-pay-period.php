<?php
/**
 * modals/modal-accrued-pay-period.php
 * Create a single ACCRUED_PAY payroll period (EOSY settlement run).
 * $settings and $pdo available from parent include.
 */
$curYear = (int)date('Y');
?>

<!-- ========== CREATE ACCRUED PAY PERIOD MODAL ========== -->
<div class="modal fade" id="modalAccruedPayPeriod" tabindex="-1" aria-labelledby="modalAccruedPayLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;border:none;">

      <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:18px 20px 14px;">
        <h5 class="modal-title" id="modalAccruedPayLabel" style="font-size:16px;font-weight:700;">
          <i class="bi bi-calendar-check" style="color:#d97706;margin-right:6px;"></i>
          Create EOSY Accrued Pay Period
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body" style="padding:18px 20px;">

        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;
                    font-size:12.5px;color:#78350f;margin-bottom:16px;">
          <i class="bi bi-info-circle-fill" style="margin-right:5px;color:#d97706;"></i>
          <strong>Accrued Pay</strong> periods are for EOSY settlement only — service credits, half-day settlement, and excess leave deductions. No regular salary is included.
        </div>

        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
            Period Name <span style="color:#ef4444;">*</span>
          </label>
          <input type="text" id="apName" class="ps-form-select" style="width:100%;"
                 placeholder="e.g. Accrued Pay <?= $curYear ?>">
        </div>

        <div style="display:flex;gap:10px;margin-bottom:14px;">
          <div style="flex:1;">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
              Period Start <span style="color:#ef4444;">*</span>
            </label>
            <input type="date" id="apStart" class="ps-form-select" style="width:100%;">
          </div>
          <div style="flex:1;">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
              Period End <span style="color:#ef4444;">*</span>
            </label>
            <input type="date" id="apEnd" class="ps-form-select" style="width:100%;">
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
            Pay Date <span style="color:#ef4444;">*</span>
          </label>
          <input type="date" id="apPayDate" class="ps-form-select" style="width:100%;">
          <div style="font-size:11.5px;color:#64748b;margin-top:5px;">
            GEI EOSY accrued pay is typically released during the 3rd week of April.
          </div>
        </div>

        <div id="apFlash" style="display:none;"></div>

      </div>

      <div class="modal-footer" style="border-top:1px solid #f1f5f9;padding:14px 20px;">
        <button type="button" class="ps-btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="ps-btn-primary" id="apCreateBtn" onclick="submitCreateAccruedPay()"
                style="background:#d97706;border-color:#d97706;">
          <i class="bi bi-plus-lg"></i> Create Accrued Pay Period
        </button>
      </div>

    </div>
  </div>
</div>
