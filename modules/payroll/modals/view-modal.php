<!-- PAYSLIP / VIEW MODAL
     FIX #7 — pay period label and "Generated on" date were hardcoded strings.
     Added id="ps-period-label" and id="ps-generated-on" so the admin portal
     JS can populate them alongside all the other #ps-* fields.
-->
<div id="payslipModal" class="modal">

    <div class="modal-box payslip-box">

        <!-- HEADER -->
        <div class="modal-header">
            <h3>Employee Payslip</h3>
            <span class="close" onclick="closePayslip()">&times;</span>
        </div>

        <!-- GREEN BANNER -->
        <div class="payslip-banner">
            <div class="payslip-institution">Great Eastern Institute</div>
            <div class="payslip-institution-sub">Ala Paz, Tarlac</div>
            <h2>PAYSLIP</h2>
            <p>Pay Period: <span id="ps-period-label">—</span></p>
        </div>

        <!-- EMPLOYEE INFO -->
        <div class="payslip-info">
            <div>
                <small>EMPLOYEE NAME: </small>
                <strong id="ps-name">-</strong>
            </div>

            <div>
                <small>POSITION: </small>
                <strong id="ps-position">-</strong>
            </div>

            <div>
                <small>DEPARTMENT: </small>
                <strong id="ps-dept">-</strong>
            </div>

            <div>
                <small>EMPLOYEE ID: </small>
                <strong id="ps-empid">-</strong>
            </div>

            <div>
                <small>PAYROLL #: </small>
                <strong id="ps-payroll-no">—</strong>
            </div>
        </div>

        <!-- EARNINGS -->
        <div class="payslip-section">
            <h4 class="earnings-title">EARNINGS</h4>

            <div class="card">
                <div id="ps-earnings-rows"></div>

                <hr>

                <div class="row total">
                    <strong>Gross Pay</strong>
                    <strong id="ps-gross">-</strong>
                </div>
            </div>
        </div>

        <!-- DEDUCTIONS -->
        <div class="payslip-section">
            <h4 class="deductions-title">DEDUCTIONS</h4>

            <div class="card">
                <div id="ps-deductions-rows"></div>

                <hr>

                <div class="row total red">
                    <strong>Total Deductions</strong>
                    <strong id="ps-totalded">-</strong>
                </div>
            </div>
        </div>

        <!-- NET PAY (before post-deduction additions) -->
        <div class="net-box">
            <span>NET PAY</span>
            <strong id="ps-net">-</strong>
        </div>

        <!-- POST-DEDUCTION ADDITIONS (Rice Subsidy, Laundry — shown only when present) -->
        <div id="ps-postded-section" class="payslip-section" style="display:none;">
            <h4 class="additions-title">ADDITIONAL ALLOWANCES</h4>
            <div class="card">
                <div id="ps-postded-rows"></div>
                <hr>
                <div class="row total">
                    <strong>Total Additions</strong>
                    <strong id="ps-postded-total">-</strong>
                </div>
            </div>
        </div>

        <!-- TOTAL TAKE-HOME PAY (shown when post-deduction additions exist) -->
        <div id="ps-takehome-box" class="takehome-box" style="display:none;">
            <span>TOTAL TAKE-HOME PAY</span>
            <strong id="ps-takehome">-</strong>
        </div>

        <!-- EMPLOYER CONTRIBUTIONS — admin/principal view only; hidden on print -->
        <div id="ps-employer-section" class="payslip-section payslip-employer-contrib" style="display:none;">
            <h4 class="employer-title">EMPLOYER CONTRIBUTIONS</h4>
            <div class="card" style="border:1px dashed #d1d5db;background:#f9fafb;">
                <div id="ps-employer-rows"></div>
                <hr>
                <div class="row total">
                    <strong style="color:#6b7280;">Total Employer Cost</strong>
                    <strong id="ps-employer-total" style="color:#6b7280;">-</strong>
                </div>
                <div style="font-size:11px;color:#9ca3af;margin-top:6px;font-style:italic;">
                    Employer contributions are not deducted from employee pay.
                </div>
            </div>
        </div>

        <!-- SIGNATURE LINES -->
        <div class="payslip-signatures">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Prepared by</div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Approved by</div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Released by</div>
                <div class="sig-name" id="ps-released-by">—</div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="payslip-footer">
            <div class="payslip-release-info">
                <span>Status: <strong id="ps-status">—</strong></span>
                <span>Released: <span id="ps-released-at">—</span></span>
            </div>
            <p>This is a system-generated payslip. No signature required.</p>
            <small>Generated on: <span id="ps-generated-on">—</span></small>
        </div>

        <!-- ACTIONS -->
        <div class="modal-actions">
            <button class="btn-outline" onclick="closePayslip()">Close</button>
            <button class="btn-outline" onclick="printPayslip()">Print</button>
            <button class="btn-primary" onclick="downloadPayslipPDF()">Print / Save PDF</button>
        </div>

    </div>
</div>
