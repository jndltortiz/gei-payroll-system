(function () {
    'use strict';

    const form = document.getElementById('rfidRegisterForm');
    const select = document.getElementById('employeeSelect');
    const employeeFilter = document.getElementById('employeeFilter');
    const hint = document.getElementById('employeeHint');
    const uidInput = document.getElementById('rfidUid');
    const submitLabel = document.querySelector('#registerSubmit span');
    if (!form || !select || !uidInput) return;

    const defaultHint = hint ? hint.textContent : '';

    function updateSelection() {
        const option = select.selectedOptions[0];
        if (!option) {
            if (hint) { hint.textContent = defaultHint; hint.classList.remove('is-warn'); }
            if (submitLabel) submitLabel.textContent = 'Register card';
            return;
        }
        const card = option.dataset.card;
        if (hint) {
            hint.textContent = card
                ? option.dataset.name + ' already has card ' + card + '. Tapping a new card will replace it.'
                : 'Registering a card for ' + option.dataset.name + '.';
            hint.classList.toggle('is-warn', Boolean(card));
        }
        if (submitLabel) submitLabel.textContent = card ? 'Replace card' : 'Register card';
    }

    function pickEmployee(id) {
        if (employeeFilter) { employeeFilter.value = ''; filterOptions(); }
        select.value = String(id);
        const option = select.selectedOptions[0];
        if (option) option.scrollIntoView({ block: 'nearest' });
        updateSelection();
        uidInput.value = '';
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        uidInput.focus({ preventScroll: true });
    }

    function filterOptions() {
        const q = (employeeFilter.value || '').trim().toLowerCase();
        Array.from(select.options).forEach(function (option) {
            option.hidden = q !== '' && !option.dataset.search.includes(q);
        });
    }

    select.addEventListener('change', function () {
        updateSelection();
        uidInput.focus();
    });

    if (employeeFilter) {
        employeeFilter.addEventListener('input', filterOptions);
        employeeFilter.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            const visible = Array.from(select.options).filter(o => !o.hidden);
            if (visible.length === 1) pickEmployee(visible[0].value);
        });
    }

    // Card readers act as a keyboard and usually press Enter after the UID.
    // Without an employee selected, keep the scan instead of failing validation.
    form.addEventListener('submit', function (event) {
        if (!select.value) {
            event.preventDefault();
            window.showToast?.('Choose the employee first, then tap the card.', 'warning');
            select.focus();
        }
    });

    document.querySelectorAll('.js-pick').forEach(function (button) {
        button.addEventListener('click', function () { pickEmployee(button.dataset.employeeId); });
    });

    document.querySelectorAll('.js-unlink').forEach(function (unlinkForm) {
        unlinkForm.addEventListener('submit', function (event) {
            if (!window.confirm('Unlink the RFID card from ' + unlinkForm.dataset.name + '? They will not be able to use the RFID kiosk until a card is registered again.')) {
                event.preventDefault();
            }
        });
    });

    // Table filters
    const tableFilter = document.getElementById('tableFilter');
    const cardFilter = document.getElementById('cardFilter');
    const rows = Array.from(document.querySelectorAll('#cardTable tbody tr'));
    const empty = document.getElementById('tableEmpty');

    function filterTable() {
        const q = (tableFilter?.value || '').trim().toLowerCase();
        const card = cardFilter?.value || 'all';
        let shown = 0;
        rows.forEach(function (row) {
            const match = (q === '' || row.dataset.search.includes(q)) && (card === 'all' || row.dataset.card === card);
            row.hidden = !match;
            if (match) shown++;
        });
        if (empty) empty.hidden = shown > 0;
    }
    tableFilter?.addEventListener('input', filterTable);
    cardFilter?.addEventListener('change', filterTable);

    updateSelection();
    if (select.value) uidInput.focus(); else (employeeFilter || select).focus();
}());
