<?php
/**
 * modals/modal-thirteenth-month-period.php
 * Create a single THIRTEENTH_MONTH payroll period for a calendar year.
 */
$curYear = (int)date('Y');
?>

<!-- ========== CREATE 13TH MONTH PERIOD MODAL ========== -->
<div class="modal fade" id="modalThirteenthMonthPeriod" tabindex="-1" aria-labelledby="modalThirteenthMonthLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;border:none;">

      <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:18px 20px 14px;">
        <h5 class="modal-title" id="modalThirteenthMonthLabel" style="font-size:16px;font-weight:700;">
          <i class="bi bi-calendar2-event" style="color:#7c3aed;margin-right:6px;"></i>
          Create 13th Month Pay Period
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body" style="padding:18px 20px;">

        <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:10px 14px;
                    font-size:12.5px;color:#4c1d95;margin-bottom:16px;">
          <i class="bi bi-info-circle-fill" style="margin-right:5px;color:#7c3aed;"></i>
          13th month pay is generated from released REGULAR payroll basic pay for the selected calendar year, divided by 12. Allowances, de minimis benefits, and deductions are excluded.
        </div>

        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
            Calendar Year <span style="color:#ef4444;">*</span>
          </label>
          <input type="number" id="tmYear" class="ps-form-select" style="width:100%;"
                 min="2020" max="2099" value="<?= $curYear ?>">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
          <div>
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
              Coverage
            </label>
            <div id="tmCoverage" style="padding:9px 11px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#f8fafc;">
              Jan 1 - Dec 31, <?= $curYear ?>
            </div>
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#374151;">
              Pay Date
            </label>
            <div id="tmPayDate" style="padding:9px 11px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#f8fafc;">
              Dec 24, <?= $curYear ?>
            </div>
          </div>
        </div>

        <div id="tmFlash" style="display:none;"></div>

      </div>

      <div class="modal-footer" style="border-top:1px solid #f1f5f9;padding:14px 20px;">
        <button type="button" class="ps-btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="ps-btn-primary" id="tmCreateBtn" onclick="submitCreateThirteenthMonth()"
                style="background:#7c3aed;border-color:#7c3aed;">
          <i class="bi bi-plus-lg"></i> Create 13th Month Pay
        </button>
      </div>

    </div>
  </div>
</div>
