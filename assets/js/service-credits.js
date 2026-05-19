// ── Open / close modal helpers ─────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'grid'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
 
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay){
  overlay.addEventListener('click', function(e){
    if(e.target === overlay) overlay.style.display = 'none';
  });
});
 
// ── Add Credit ─────────────────────────────────────────────────────────────
document.getElementById('btnAddCredit').addEventListener('click', function(){
  resetCreditModal();
  document.getElementById('modalTitle').textContent  = 'Add Service Credit';
  document.getElementById('submitBtn').textContent   = 'Add Credit';
  document.getElementById('formAction').value        = 'add';
  openModal('creditModal');
});
var btnEmpty = document.getElementById('btnAddCreditEmpty');
if(btnEmpty) btnEmpty.addEventListener('click', function(){
  document.getElementById('btnAddCredit').click();
});
 
function resetCreditModal(){
  document.getElementById('creditForm').reset();
  document.getElementById('formCreditId').value = '';
  document.getElementById('formWorkDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('formDays').value = '1.0';
  document.getElementById('charCount').textContent = '0/255';
}
 
// ── Edit Credit ────────────────────────────────────────────────────────────
function openEditModal(cr){
  document.getElementById('modalTitle').textContent  = 'Edit Service Credit';
  document.getElementById('submitBtn').textContent   = 'Update Credit';
  document.getElementById('formAction').value        = 'edit';
  document.getElementById('formCreditId').value      = cr.service_credit_id;
  document.getElementById('formEmployee').value      = cr.employee_id;
  document.getElementById('formDays').value          = cr.days;
  document.getElementById('formWorkDate').value      = cr.work_date;
  document.getElementById('formRemarks').value       = cr.remarks || '';
  updateCharCount(document.getElementById('formRemarks'));
  openModal('creditModal');
}
 
// ── Delete ─────────────────────────────────────────────────────────────────
function openDeleteModal(id, name, days){
  document.getElementById('deleteCreditId').value    = id;
  document.getElementById('deleteDesc').textContent  =
    'Remove ' + days + ' credit' + (days !== 1 ? 's' : '') + ' from ' + name +
    '? This will reduce their leave balance.';
  openModal('deleteModal');
}
 
// ── Char counter ───────────────────────────────────────────────────────────
var remarksEl = document.getElementById('formRemarks');
remarksEl.addEventListener('input', function(){ updateCharCount(this); });
function updateCharCount(el){
  document.getElementById('charCount').textContent = el.value.length + '/255';
}
 
// ── Live search with debounce ──────────────────────────────────────────────
var searchInput = document.getElementById('searchInput');
var debounceTimer;
searchInput.addEventListener('input', function(){
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(function(){
    document.getElementById('filterForm').submit();
  }, 500);
});