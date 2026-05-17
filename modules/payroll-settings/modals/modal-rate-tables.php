<!-- Modal: Government Rate Tables -->
<div class="modal fade" id="modalRateTables" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content ps-modal">

            <div class="ps-modal-header" style="flex-shrink:0;">
                <div>
                    <h5 class="ps-modal-title">2024 Official Government Contribution Rates</h5>
                    <div class="ps-modal-subtitle">Bracket-based rates used for automatic calculation</div>
                </div>
                <button type="button" class="ps-modal-close" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <!-- Scrollable body -->
            <div class="modal-body ps-rate-modal-body">

                <!-- SSS -->
                <div class="ps-rate-section">
                    <div class="ps-rate-section-header blue">
                        <span class="ps-rate-num">1</span>
                        <span>SSS Contribution Table (Employee Share: 4.5%)</span>
                    </div>
                    <div class="ps-rate-table-wrap">
                        <table class="ps-rate-table">
                            <thead>
                                <tr>
                                    <th>SALARY RANGE</th>
                                    <th>MONTHLY SALARY CREDIT</th>
                                    <th class="text-blue">EMPLOYEE SHARE</th>
                                    <th>EMPLOYER SHARE</th>
                                </tr>
                            </thead>
                            <tbody id="sssRateRows">
                                <tr><td colspan="4" class="text-center text-muted py-3">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="ps-rate-note blue mt-2" id="sssTotalNote"></div>
                </div>

                <!-- PhilHealth -->
                <div class="ps-rate-section mt-4">
                    <div class="ps-rate-section-header green">
                        <span class="ps-rate-num green">2</span>
                        <span>PhilHealth Contribution (Premium Rate: 5%)</span>
                    </div>
                    <div class="ps-rate-table-wrap">
                        <table class="ps-rate-table">
                            <thead>
                                <tr>
                                    <th>SALARY RANGE</th>
                                    <th class="text-green">EMPLOYEE SHARE (2.5%)</th>
                                    <th>EMPLOYER SHARE (2.5%)</th>
                                </tr>
                            </thead>
                            <tbody id="philRateRows">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="ps-rate-example green mt-2" id="philExample"></div>
                </div>

                <!-- Pag-IBIG -->
                <div class="ps-rate-section mt-4">
                    <div class="ps-rate-section-header amber">
                        <span class="ps-rate-num amber">3</span>
                        <span>Pag-IBIG (HDMF) Contribution</span>
                    </div>
                    <div class="ps-rate-table-wrap">
                        <table class="ps-rate-table">
                            <thead>
                                <tr>
                                    <th>SALARY RANGE</th>
                                    <th class="text-amber">EMPLOYEE RATE</th>
                                    <th>EMPLOYER RATE</th>
                                    <th>EMPLOYEE MAX</th>
                                </tr>
                            </thead>
                            <tbody id="pagibigRateRows">
                                <tr><td colspan="4" class="text-center text-muted py-3">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="ps-rate-example amber mt-2" id="pagibigExample"></div>
                </div>

            </div><!-- /.modal-body -->

            <div class="ps-modal-footer" style="flex-shrink:0;">
                <button type="button" class="ps-btn-primary" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>