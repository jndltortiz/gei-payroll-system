<!-- Payslip View Modal — unified design matching employee portal -->
<div id="payslipOverlay" class="ps-overlay">
  <div class="ps-modal" id="ps-printable">

    <!-- Header -->
    <div class="ps-modal-header">
      <div class="ps-header-left">
        <div class="ps-school-name">Great Eastern Institute</div>
        <div class="ps-modal-title">Employee Payslip</div>
        <div class="ps-period-label" id="ps-period"></div>
      </div>
      <button onclick="closePayslip()" class="ps-close-btn no-print">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <!-- Content -->
    <div id="ps-content" style="padding:20px 22px;">

      <!-- Payroll reference numbers -->
      <div id="ps-numbers" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;
           font-size:12px;color:#64748b;padding:0 0 8px;"></div>

      <!-- Employee info -->
      <div class="ps-emp-info">
        <div class="ps-emp-name" id="ps-empname"></div>
        <div class="ps-emp-id-row">
          <span class="ps-emp-id-label">Employee ID:</span>
          <code class="ps-emp-id" id="ps-empid"></code>
        </div>
        <div class="ps-emp-meta-row">
          <span><strong>Position:</strong> <span id="ps-position"></span></span>
          <span class="ps-meta-sep">|</span>
          <span><strong>Dept:</strong> <span id="ps-dept"></span></span>
        </div>
      </div>

      <!-- Attendance Summary -->
      <div id="ps-att-section" style="display:none;margin-bottom:14px;">
        <div class="ps-section-label ps-section-label--blue">
          <i class="fa fa-calendar-check"></i> Attendance Summary
        </div>
        <div class="ps-att-grid">
          <div class="ps-att-cell">
            <div class="ps-att-num" id="ps-att-present">—</div>
            <div class="ps-att-lbl">Present</div>
          </div>
          <div class="ps-att-cell">
            <div class="ps-att-num ps-att-num--amber" id="ps-att-late">—</div>
            <div class="ps-att-lbl">Late</div>
          </div>
          <div class="ps-att-cell">
            <div class="ps-att-num ps-att-num--orange" id="ps-att-halfday">—</div>
            <div class="ps-att-lbl">Half-Day</div>
          </div>
          <div class="ps-att-cell">
            <div class="ps-att-num ps-att-num--red" id="ps-att-absent">—</div>
            <div class="ps-att-lbl">Absent</div>
          </div>
          <div class="ps-att-cell">
            <div class="ps-att-num ps-att-num--teal" id="ps-att-leave">—</div>
            <div class="ps-att-lbl">Leave</div>
          </div>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:6px;font-style:italic;">
          Attendance shown for reference. Late/undertime incur no salary deduction per GEI policy.
        </div>
      </div>

      <!-- Salary for Payroll Period (Basic Pay) -->
      <div style="margin-bottom:14px;">
        <div class="ps-section-label ps-section-label--green">
          <i class="fa fa-money-bill-wave"></i> Salary for Payroll Period
        </div>
        <div id="ps-basicpay" class="ps-row-list ps-row-list--green"></div>
      </div>

      <!-- Overload / Additional Allowances -->
      <div id="ps-allowances-section" style="display:none;margin-bottom:14px;">
        <div class="ps-section-label ps-section-label--purple">
          <i class="fa fa-circle-plus"></i> Overload / Additional Allowances
        </div>
        <div id="ps-allowances" class="ps-row-list ps-row-list--purple"></div>
        <div class="ps-total-bar ps-total-bar--purple">
          <span>Total Allowances</span>
          <span id="ps-total-allowances"></span>
        </div>
      </div>

      <!-- Gross Pay -->
      <div class="ps-net-box ps-net-box--green" style="margin-bottom:14px;">
        <span class="ps-net-label">GROSS PAY</span>
        <span class="ps-net-amount" id="ps-gross"></span>
      </div>

      <!-- Less: Deductions -->
      <div style="margin-bottom:14px;">
        <div class="ps-section-label ps-section-label--red">
          <i class="fa fa-circle-minus"></i> Less: Deductions
        </div>
        <div id="ps-deductions" class="ps-row-list ps-row-list--red"></div>
        <div class="ps-total-bar ps-total-bar--red">
          <span>Total Deductions</span>
          <span id="ps-totalded"></span>
        </div>
        <div id="ps-gov-note" style="font-size:11px;color:#94a3b8;margin-top:5px;font-style:italic;"></div>
      </div>

      <!-- Net Pay -->
      <div class="ps-net-box ps-net-box--blue">
        <span class="ps-net-label">NET PAY</span>
        <span class="ps-net-amount" id="ps-net"></span>
      </div>

      <!-- Employer Contributions (informational, admin/principal only) -->
      <div id="ps-employer-section" style="display:none;margin-top:14px;">
        <div class="ps-section-label" style="background:#f8fafc;border-left:3px solid #94a3b8;color:#64748b;">
          <i class="fa fa-building" style="color:#94a3b8;"></i> Employer Contributions
          <span style="font-size:10px;font-weight:400;">(GEI share — not deducted from employee pay)</span>
        </div>
        <div id="ps-employer-rows" class="ps-row-list"
             style="border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;background:#f8fafc;"></div>
        <div class="ps-total-bar"
             style="background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 6px 6px;">
          <span>Total Employer Share</span>
          <span id="ps-employer-total" style="font-weight:700;"></span>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:5px;font-style:italic;">
          * Employer contributions are not deducted from employee pay.
        </div>
      </div>

      <!-- Released info -->
      <div class="ps-released-info" id="ps-released-info"></div>
      <div class="ps-print-footer">
        This payslip is system-generated. Great Eastern Institute, La Paz, Tarlac.
      </div>

    </div>

    <!-- Modal footer -->
    <div class="ps-modal-footer no-print">
      <button onclick="closePayslip()" class="ps-btn ps-btn--outline">Close</button>
      <button onclick="printPayslip()" class="ps-btn ps-btn--primary">
        <i class="fa fa-print"></i> Print
      </button>
    </div>

  </div>
</div>
