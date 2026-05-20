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
            <h2>PAYSLIP</h2>
            <!-- was: hardcoded "March 1 – March 15, 2026" -->
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
        </div>

        <!-- EARNINGS -->
        <div class="payslip-section">
            <h4 class="earnings-title">EARNINGS</h4>

            <div class="card">
                <div class="row">
                    <span>Basic Salary</span>
                    <span id="ps-basic">-</span>
                </div>
                <div class="row">
                    <span>Additional Assignment Pay</span>
                    <span id="ps-assign">-</span>
                </div>
                <div class="row">
                    <span>Rice Subsidy</span>
                    <span id="ps-rice">-</span>
                </div>
                <div class="row">
                    <span>Laundry Allowance</span>
                    <span id="ps-laundry">-</span>
                </div>

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
                <div class="row"><span>PERAA Premium</span><span id="ps-peraa">-</span></div>
                <div class="row"><span>PERAA Loan</span><span id="ps-peraa-loan">-</span></div>
                <div class="row"><span>HDMF Premium</span><span id="ps-hdmf">-</span></div>
                <div class="row"><span>HDMF Loan</span><span id="ps-hdmf-loan">-</span></div>
                <div class="row"><span>PhilHealth</span><span id="ps-philhealth">-</span></div>
                <div class="row"><span>SSS Premium</span><span id="ps-sss">-</span></div>
                <div class="row"><span>SSS Loan</span><span id="ps-sss-loan">-</span></div>

                <hr>

                <div class="row total red">
                    <strong>Total Deductions</strong>
                    <strong id="ps-totalded">-</strong>
                </div>
            </div>
        </div>

        <!-- NET PAY -->
        <div class="net-box">
            <span>NET PAY</span>
            <strong id="ps-net">-</strong>
        </div>

        <!-- FOOTER -->
        <div class="payslip-footer">
            <p>This is a system-generated payslip. No signature required.</p>
            <!-- was: hardcoded "April 23, 2026" -->
            <small>Generated on: <span id="ps-generated-on">—</span></small>
        </div>

        <!-- ACTIONS -->
        <div class="modal-actions">
            <button class="btn-outline" onclick="closePayslip()">Close</button>
            <button class="btn-outline" onclick="printPayslip()">Print</button>
            <button class="btn-primary" onclick="downloadPayslipPDF()">Download PDF</button>
        </div>

    </div>
</div>