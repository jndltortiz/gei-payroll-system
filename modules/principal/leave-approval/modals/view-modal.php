<!-- PRINCIPAL: Full Leave Details View Modal -->
<div class="pr-modal-overlay" id="principalViewModal" style="display:none;"
     onclick="if(event.target===this) closeModal('principalViewModal')">
    <div class="pr-modal-box pr-modal-box--xl" onclick="event.stopPropagation()">

        <div class="pr-modal-header">
            <h3 class="pr-modal-title">
                <i class="fa fa-calendar-days" style="color:#0f766e;margin-right:6px;"></i>
                Leave Request Details
            </h3>
            <button class="pr-modal-close" onclick="closeModal('principalViewModal')">×</button>
        </div>

        <div class="pr-modal-body" id="principalViewBody" style="padding:0;">
            <div style="text-align:center;padding:50px;color:#94a3b8;">
                <i class="fa fa-spinner fa-spin fa-lg"></i><br>Loading…
            </div>
        </div>

        <div class="pr-modal-footer" id="principalViewFooter">
            <button class="pr-btn pr-btn--ghost" onclick="closeModal('principalViewModal')">Close</button>
        </div>

    </div>
</div>
