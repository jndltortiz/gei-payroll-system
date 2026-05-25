<?php
/**
 * modal-create.php — Create / Edit service credit
 * Variables expected from parent: $employees, $openPeriods
 */
if (!isset($employees))   $employees   = [];
if (!isset($openPeriods)) $openPeriods = [];
?>
<div class="sc-modal-overlay" id="createModal" style="display:none;">
  <div class="sc-modal-box">
    <div class="sc-modal-header">
      <h3 id="createModalTitle"><i class="fa fa-plus-circle"></i> Create Service Credit</h3>
      <button onclick="closeModal('createModal')"><i class="fa fa-times"></i></button>
    </div>

    <form id="scCreateForm" method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php">
      <input type="hidden" name="action"            id="scFormAction"   value="submit">
      <input type="hidden" name="service_credit_id" id="scFormId"       value="">

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
            &nbsp;·&nbsp; Computed Pay = Days × Daily Rate
          </div>
        </div>

        <!-- Target Payroll Period -->
        <div class="sc-form-group">
          <label>Target Payroll Period</label>
          <select name="target_period_id" id="scTargetPeriod">
            <option value="">— Not specified (auto-applied on next payroll run) —</option>
            <?php foreach ($openPeriods as $p):
              $pLabel = $p['period_name']
                  ?: (date('M j', strtotime($p['pay_period_start'])) . '–' . date('j, Y', strtotime($p['pay_period_end'])));
              $pPayDate = $p['pay_date'] ? ' · Pay: ' . date('M j, Y', strtotime($p['pay_date'])) : '';
            ?>
            <option value="<?= $p['period_id'] ?>">
              <?= htmlspecialchars($pLabel . $pPayDate) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <small>Informational — shows which payroll period this credit is intended for.</small>
        </div>

        <!-- Description -->
        <div class="sc-form-group">
          <label>Description of Extra Work</label>
          <textarea name="remarks" id="scRemarks" rows="2"
                    placeholder="e.g. Saturday tutorial, enrollment duty, school event…"
                    maxlength="255"></textarea>
          <span class="sc-char-count" id="scCharCount">0 / 255</span>
        </div>

        <!-- Work Date Table -->
        <div class="sc-form-group">
          <div class="sc-dates-hdr">
            <label>Work Dates <span class="req">*</span></label>
            <button type="button" class="sc-btn-add-date" onclick="addScDateRow()">
              <i class="fa fa-plus"></i> Add Date
            </button>
          </div>
          <div class="sc-dates-table-wrap">
            <table class="sc-dates-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Day Equivalent</th>
                  <th>Computed Pay (₱)</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="scDateRows">
                <!-- Populated by renderScDateRows() -->
              </tbody>
            </table>
          </div>
          <div class="sc-date-totals" id="scDateTotals">
            <span>Total: <strong id="scTotalDays">0.0</strong> day(s)</span>
            <span>Total Pay: <strong id="scTotalPay" style="color:#0f766e;">₱0.00</strong></span>
          </div>
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
