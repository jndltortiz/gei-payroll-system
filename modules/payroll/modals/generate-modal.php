<!-- GENERATE PAYROLL MODAL -->
<div id="generateModal" class="modal">
    <div class="modal-box">

        <div class="modal-header">
            <h3>Generate Payroll</h3>
            <span class="close" onclick="closeGenerateModal()">&times;</span>
        </div>

        <p style="margin-top:10px;">
            This will generate payroll records for all employees for the selected pay period.
        </p>

        <form method="POST" action="<?= BASE_URL ?>actions/generate-payroll.php">

            <div style="margin-top:15px;">
                <label>Pay Period</label>
                <select name="period_id" required>
                    <?php
                   // $pdo already available via auth.php included by the parent page
                    $periods = $pdo->query("SELECT * FROM payroll_periods WHERE status='OPEN'");
                    while ($p = $periods->fetch()):
                    ?>
                        <option value="<?= $p['period_id'] ?>">
                            <?= $p['period_name'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-outline" onclick="closeGenerateModal()">Cancel</button>
                <button type="submit" class="btn-primary">Generate</button>
            </div>

        </form>
    </div>
</div>