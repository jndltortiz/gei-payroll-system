/**
 * gei-ui.js — GEI HR System Global UI Utilities
 * Loaded via includes/footer.php on every page.
 *
 * Provides:
 *   GEI.confirm(options) → Promise  — replaces browser confirm()
 *   data-confirm-title form interceptor — declarative confirms on any <form>
 *   Sortable table headers — th.sortable[data-col][data-sort-type]
 */
(function () {
    'use strict';

    window.GEI = window.GEI || {};

    // ── Confirm Modal ─────────────────────────────────────────────────────────

    let _resolve = null;
    let _reject  = null;

    function _modal() { return document.getElementById('gei-confirm-modal'); }

    const ICONS  = { danger: 'fa-triangle-exclamation', warning: 'fa-circle-exclamation', info: 'fa-circle-info' };
    const COLORS = { danger: '#ef4444', warning: '#d97706', info: '#3b82f6' };
    const BGS    = { danger: '#fee2e2', warning: '#fef3c7', info: '#dbeafe' };
    const BTNCLS = { danger: 'gei-confirm-btn--danger', warning: 'gei-confirm-btn--warning', info: 'gei-confirm-btn--info' };

    /**
     * Show a styled confirmation modal.
     * @param {object} opts
     *   title       string  Modal heading
     *   message     string  Body text
     *   note        string  Optional amber-box warning (e.g. "This cannot be undone.")
     *   type        string  'danger' | 'warning' | 'info'  (default: danger)
     *   confirmText string  Confirm button label            (default: Confirm)
     *   cancelText  string  Cancel button label             (default: Cancel)
     * @returns Promise  resolves on confirm, rejects on cancel/backdrop
     */
    GEI.confirm = function (opts) {
        opts = opts || {};
        return new Promise(function (resolve, reject) {
            _resolve = resolve;
            _reject  = reject;

            var modal = _modal();
            if (!modal) { resolve(); return; }   // modal missing — fall through silently

            var type = opts.type || 'danger';

            // Icon
            var iconEl = modal.querySelector('.gei-confirm-icon');
            if (iconEl) {
                iconEl.innerHTML = '<i class="fa ' + (ICONS[type] || ICONS.danger) + '"></i>';
                iconEl.style.background = BGS[type]    || BGS.danger;
                iconEl.style.color      = COLORS[type] || COLORS.danger;
            }

            // Title / message
            var titleEl = modal.querySelector('.gei-confirm-title');
            var msgEl   = modal.querySelector('.gei-confirm-msg');
            if (titleEl) titleEl.textContent = opts.title   || 'Confirm Action';
            if (msgEl)   msgEl.textContent   = opts.message || 'Are you sure you want to proceed?';

            // Optional note
            var noteEl   = modal.querySelector('.gei-confirm-note');
            var noteText = modal.querySelector('.gei-confirm-note-text');
            if (noteEl) {
                if (opts.note) {
                    if (noteText) noteText.textContent = opts.note;
                    noteEl.style.display = 'flex';
                } else {
                    noteEl.style.display = 'none';
                }
            }

            // Confirm button
            var confirmBtn = modal.querySelector('.gei-confirm-btn-ok');
            if (confirmBtn) {
                confirmBtn.textContent = opts.confirmText || 'Confirm';
                confirmBtn.className   = 'gei-confirm-btn-ok ' + (BTNCLS[type] || BTNCLS.danger);
            }

            // Cancel button text
            var cancelBtn = modal.querySelector('.gei-confirm-btn-cancel');
            if (cancelBtn) cancelBtn.textContent = opts.cancelText || 'Cancel';

            modal.classList.add('gei-confirm--visible');
        });
    };

    // Confirm / cancel handlers (called from HTML buttons)
    window._geiConfirmOk = function () {
        var modal = _modal();
        if (modal) modal.classList.remove('gei-confirm--visible');
        if (_resolve) { _resolve(); _resolve = null; _reject = null; }
    };

    window._geiConfirmCancel = function () {
        var modal = _modal();
        if (modal) modal.classList.remove('gei-confirm--visible');
        if (_reject)  { _reject();  _resolve = null; _reject = null; }
    };

    // Close on backdrop click
    document.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'gei-confirm-modal') {
            window._geiConfirmCancel();
        }
    });

    // ── data-confirm Form Interceptor ─────────────────────────────────────────
    //
    // Any <form> with data-confirm-title="..." is intercepted on submit.
    // Supported attributes:
    //   data-confirm-title   (required)  Modal heading
    //   data-confirm-message (optional)  Body text
    //   data-confirm-note    (optional)  Amber warning text
    //   data-confirm-type    (optional)  danger|warning|info
    //   data-confirm-btn     (optional)  Confirm button label
    //
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute('data-confirm-title')) return;
        if (form._geiConfirmed) return;   // form.submit() was called after confirm — allow through

        e.preventDefault();

        GEI.confirm({
            title:       form.getAttribute('data-confirm-title'),
            message:     form.getAttribute('data-confirm-message') || 'Are you sure you want to proceed?',
            note:        form.getAttribute('data-confirm-note')    || '',
            type:        form.getAttribute('data-confirm-type')    || 'danger',
            confirmText: form.getAttribute('data-confirm-btn')     || 'Confirm',
        }).then(function () {
            form._geiConfirmed = true;
            form.submit();              // does NOT re-trigger the submit event
        }).catch(function () { /* cancelled */ });
    }, true); // capture phase — fires before any inline onsubmit

    // ── Sortable Table Headers ────────────────────────────────────────────────
    //
    // Usage: add class="sortable" data-col="N" to <th> elements.
    //   data-col       0-indexed column number
    //   data-sort-type text (default) | number | date
    //
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('th.sortable').forEach(function (th) {
            th.style.cursor     = 'pointer';
            th.style.userSelect = 'none';

            // Append sort indicator if not already present
            if (!th.querySelector('.sort-ind')) {
                var ind = document.createElement('span');
                ind.className   = 'sort-ind';
                ind.innerHTML   = '&nbsp;<i class="fa fa-sort" style="font-size:9px;opacity:0.3;"></i>';
                th.appendChild(ind);
            }

            th.addEventListener('click', function () {
                var table = th.closest('table');
                if (!table) return;
                var tbody = table.querySelector('tbody');
                if (!tbody) return;

                var col   = parseInt(th.getAttribute('data-col') || '0', 10);
                var type  = th.getAttribute('data-sort-type') || 'text';
                var asc   = th.getAttribute('data-sort-dir') !== 'asc';

                // Reset all other sortable headers in this table
                table.querySelectorAll('th.sortable').forEach(function (h) {
                    h.removeAttribute('data-sort-dir');
                    var icon = h.querySelector('.sort-ind i');
                    if (icon) { icon.className = 'fa fa-sort'; icon.style.opacity = '0.3'; }
                });

                th.setAttribute('data-sort-dir', asc ? 'asc' : 'desc');
                var activeIcon = th.querySelector('.sort-ind i');
                if (activeIcon) {
                    activeIcon.className   = asc ? 'fa fa-sort-up' : 'fa fa-sort-down';
                    activeIcon.style.opacity = '0.85';
                }

                var rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort(function (a, b) {
                    var aText = (a.cells[col] ? a.cells[col].innerText : '').trim();
                    var bText = (b.cells[col] ? b.cells[col].innerText : '').trim();

                    if (type === 'number') {
                        var aNum = parseFloat(aText.replace(/[^\d.\-]/g, '')) || 0;
                        var bNum = parseFloat(bText.replace(/[^\d.\-]/g, '')) || 0;
                        return asc ? aNum - bNum : bNum - aNum;
                    }
                    if (type === 'date') {
                        var aDate = new Date(aText);
                        var bDate = new Date(bText);
                        var aVal  = isNaN(aDate) ? 0 : aDate.getTime();
                        var bVal  = isNaN(bDate) ? 0 : bDate.getTime();
                        return asc ? aVal - bVal : bVal - aVal;
                    }
                    return asc
                        ? aText.localeCompare(bText, 'en', { sensitivity: 'base' })
                        : bText.localeCompare(aText, 'en', { sensitivity: 'base' });
                });

                rows.forEach(function (r) { tbody.appendChild(r); });
            });
        });
    });

})();
