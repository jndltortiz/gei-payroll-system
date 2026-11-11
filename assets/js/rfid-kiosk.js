(function () {
    'use strict';

    const input = document.getElementById('rfid_uid');
    if (input) {
        input.focus();
        document.addEventListener('click', function (event) {
            if (!event.target.closest('button, a, input')) input.focus();
        });
        window.addEventListener('pageshow', function () { input.focus(); });

        // Card readers type the UID and press Enter. Submit without a button
        // value so the server decides: first tap = Time In, next tap = Time Out.
        // (Plain Enter is disabled in the HTML, so it can never default to Time In.)
        input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            if (input.readOnly || input.value.trim() === '') return;
            input.readOnly = true;
            input.form.submit();
        });
        window.addEventListener('pageshow', function () { input.readOnly = false; });
    }

    const table = document.getElementById('recordsTable');
    const copyButton = document.getElementById('copyRecords');
    const clearButton = document.getElementById('clearRecords');
    const clearedState = document.getElementById('recordsCleared');

    function csvValue(value) {
        return '"' + String(value).replace(/"/g, '""') + '"';
    }

    if (copyButton && table) {
        copyButton.addEventListener('click', async function () {
            const rows = Array.from(table.querySelectorAll('tr')).filter(row => row.querySelectorAll('th, td').length === 5);
            const csv = rows.map(row => Array.from(row.querySelectorAll('th, td')).map(cell => csvValue(cell.textContent.trim())).join(',')).join('\n');
            try {
                await navigator.clipboard.writeText(csv);
                window.showToast?.('Attendance records copied as CSV.', 'success');
            } catch (error) {
                window.showToast?.('Unable to copy records. Please try again.', 'error');
            }
        });
    }

    if (clearButton && table && clearedState) {
        clearButton.addEventListener('click', function () {
            table.hidden = true;
            clearedState.hidden = false;
            window.showToast?.('Records cleared from this view. Stored attendance data was not changed.', 'info');
        });
    }
}());
