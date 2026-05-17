<!-- Modal: Assign -->
<div class="modal fade" id="modalAssign" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ps-modal">
            <div class="ps-modal-header">
                <h5 class="ps-modal-title" id="assignModalTitle">Assign "Item" to...</h5>
                <button type="button" class="ps-modal-close" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button>
            </div>
            <form id="formAssign" onsubmit="submitAssignment(event)">
                <input type="hidden" name="item_type" id="assignItemType">
                <input type="hidden" name="item_id"   id="assignItemId">
                <div class="ps-modal-body">

                    <div class="ps-assign-info">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>Select which employees, departments, or positions should receive this item. This determines who will see it in their payroll computation.</span>
                    </div>

                    <div class="ps-form-label mt-3 mb-2">Assignment Scope</div>

                    <div class="ps-radio-cards" id="assignRadioCards">
                        <label class="ps-radio-card selected" data-scope="ALL">
                            <input type="radio" name="applies_to" value="ALL" checked>
                            <div class="ps-radio-card-body">
                                <div class="fw-semibold">All Employees</div>
                                <div class="ps-radio-card-sub">Apply to everyone in the organization</div>
                            </div>
                        </label>

                        <label class="ps-radio-card" data-scope="DEPARTMENT">
                            <input type="radio" name="applies_to" value="DEPARTMENT">
                            <div class="ps-radio-card-body">
                                <div class="fw-semibold">By Department</div>
                                <div class="ps-radio-card-sub">Select specific departments</div>
                                <select class="ps-form-select mt-2 ps-scope-select d-none" name="target_department" id="assignDeptSelect">
                                    <option value="">— Select Department —</option>
                                    <?php foreach ($departments ?? [] as $dept): ?>
                                    <option value="<?= $dept['department_id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </label>

                        <label class="ps-radio-card" data-scope="POSITION">
                            <input type="radio" name="applies_to" value="POSITION">
                            <div class="ps-radio-card-body">
                                <div class="fw-semibold">By Position</div>
                                <div class="ps-radio-card-sub">Select specific positions</div>
                                <select class="ps-form-select mt-2 ps-scope-select d-none" name="target_position" id="assignPosSelect">
                                    <option value="">— Select Position —</option>
                                    <?php foreach ($positions ?? [] as $pos): ?>
                                    <option value="<?= $pos['position_id'] ?>"><?= htmlspecialchars($pos['position_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </label>

                        <label class="ps-radio-card" data-scope="EMPLOYEE">
                            <input type="radio" name="applies_to" value="EMPLOYEE">
                            <div class="ps-radio-card-body">
                                <div class="fw-semibold">Selected Employees</div>
                                <div class="ps-radio-card-sub">Pick individual employees</div>
                                <select class="ps-form-select mt-2 ps-scope-select d-none" name="target_employee" id="assignEmpSelect">
                                    <option value="">— Select Employee —</option>
                                    <?php foreach ($employees ?? [] as $emp): ?>
                                    <option value="<?= $emp['employee_id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </label>
                    </div>
                </div>
                <div class="ps-modal-footer">
                    <button type="button" class="ps-btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="ps-btn-primary">
                        <i class="bi bi-person-check"></i> Save Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>