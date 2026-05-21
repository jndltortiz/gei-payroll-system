<?php
/**
 * modal-create.php — Create / Edit service credit
 * Variables: $employees (from parent index.php)
 */
if (!isset($employees)) $employees = [];
?>
<div class="sc-modal-overlay" id="createModal" style="display:none;">
  <div class="sc-modal-box">
    <div class="sc-modal-header">
      <h3 id="createModalTitle"><i class="fa fa-plus-circle"></i> Create Service Credit</h3>
      <button onclick="closeModal('createModal')"><i class="fa fa-times"></i></button>
    </div>

    <form id="scCreateForm" method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php">
      <input type="hidden" name="action"            id="scFormAction" value="submit">
      <input type="hidden" name="service_credit_id" id="scFormId"     value="">

      <div class="sc-modal-body">

        <!-- Employee -->
        <div class="sc-form-group">
          <label>Employee <span class="req">*</span></label>
          <select name="employee_id" id="scEmployee" required
                  onchange="onScEmployeeChange(this.value)">
            <option value="">— Select employee —</option>
            <?php foreach ($employees as $e): ?>
            <option value="<?= $e['employee_id'] ?>"
                    data-rate="<?= number_format((float)($e['daily_rate'] ?? 0), 2, '.', '') ?>">
              <?= htmlspecialchars($e['full_name'] . ' — ' . $e['position_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div id="scRateHint" style="display:none;" class="sc-rate-hint">
            <i class="fa fa-circle-info"></i>
            Daily rate: <strong id="scRateDisplay">—</strong>
            &nbsp;·&nbsp; Each row auto-computes Days × Daily Rate
          </div>
        </div>

        <!-- Work Date Rows (multi-date, rendered by JS) -->
        <div class="sc-form-group">
          <div class="sc-dates-hdr">
            <label>Work Dates <span class="req">*</span></label>
            <button type="button" class="sc-btn-add-date" onclick="addScDateRow()">
              <i class="fa fa-plus"></i> Add Date
            </button>
          </div>
          <div id="scDateRows">
            <!-- Populated by renderScDateRows() on DOMContentLoaded / resetCreateModal() -->
          </div>
          <div class="sc-date-totals" id="scDateTotals" style="display:none;">
            Total: <strong id="scTotalDays">0.0</strong> day(s)
            &nbsp;·&nbsp;
            Equiv. Pay: ₱<strong id="scTotalPay">0.00</strong>
          </div>
        </div>

        <!-- Description -->
        <div class="sc-form-group">
          <label>Description of Extra Work</label>
          <textarea name="remarks" id="scRemarks" rows="3"
                    placeholder="e.g. Saturday tutorial, enrollment duty, school event…"
                    maxlength="255"></textarea>
          <span class="sc-char-count" id="scCharCount">0 / 255</span>
        </div>

      </div><!-- .sc-modal-body -->

      <div class="sc-modal-footer">
        <button type="button" class="sc-btn-ghost" onclick="closeModal('createModal')">Cancel</button>
        <button type="button" class="sc-btn-outline" id="btnSaveDraft"
                onclick="setScAction('save_draft')">
          <i class="fa fa-floppy-disk"></i> Save as Draft
        </button>
        <button type="submit" class="sc-btn-primary" id="btnSubmitApproval"
                onclick="setScAction('submit')">
          <i class="fa fa-paper-plane"></i> Submit for Approval
        </button>
      </div>
    </form>
  </div>
</div>
