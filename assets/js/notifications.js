/**
 * assets/js/notifications.js
 * In-system notification bell: dropdown toggle, AJAX preview, mark-read.
 * Loaded globally via footer.php — guards against missing bell elements.
 */
(function () {
    'use strict';

    var bellBtn   = document.getElementById('notifBtn');
    var dropdown  = document.getElementById('notifDropdown');
    var badge     = document.getElementById('notifBadge');
    var ddBody    = document.getElementById('notifDdBody');
    var markAllEl = document.getElementById('notifMarkAll');

    // Bell not present on this page — nothing to do
    if (!bellBtn || !dropdown) return;

    var isOpen   = false;
    var baseUrl  = window.BASE_URL || '/gei-payroll-system/';

    /* ── Helpers ────────────────────────────────────────────────────────── */

    function esc(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmtAgo(dtStr) {
        var diff = (Date.now() - new Date(dtStr).getTime()) / 1000;
        if (diff < 60)    return 'Just now';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    var typeIcons = {
        calendar:       'fa-calendar-days',
        payroll:        'fa-file-invoice-dollar',
        leave:          'fa-calendar-minus',
        loan:           'fa-hand-holding-dollar',
        service_credit: 'fa-medal',
        system:         'fa-circle-info'
    };

    var typeColors = {
        calendar:       '#d97706',
        payroll:        '#4f46e5',
        leave:          '#0d9488',
        loan:           '#059669',
        service_credit: '#7c3aed',
        system:         '#3b82f6'
    };

    function getIcon(type)  { return typeIcons[type]  || 'fa-bell'; }
    function getColor(type) { return typeColors[type] || '#64748b'; }

    /* ── Render notification items ──────────────────────────────────────── */

    function renderItems(items) {
        if (!items || items.length === 0) {
            return '<div class="notif-empty"><i class="fa fa-bell-slash"></i><p>No notifications yet</p></div>';
        }

        return items.map(function (n) {
            var unread  = parseInt(n.is_read) === 0;
            var icon    = getIcon(n.type);
            var color   = getColor(n.type);
            var href    = n.link_url || '#';
            var bgRgba  = color + '22';

            return '<a class="notif-item' + (unread ? ' unread' : '') + '"' +
                ' href="' + esc(href) + '"' +
                ' onclick="NOTIF.markRead(event,' + n.notification_id + ',\'' + esc(href) + '\')">' +
                '<div class="notif-icon" style="background:' + bgRgba + ';color:' + color + '">' +
                '<i class="fa ' + icon + '"></i></div>' +
                '<div class="notif-item-content">' +
                '<div class="notif-item-title">' + esc(n.title) + '</div>' +
                (n.message ? '<div class="notif-item-msg">' + esc(n.message) + '</div>' : '') +
                '<div class="notif-item-time">' + fmtAgo(n.created_at) + '</div>' +
                '</div>' +
                (unread ? '<div class="notif-unread-dot"></div>' : '') +
                '</a>';
        }).join('');
    }

    /* ── Update badge count ─────────────────────────────────────────────── */

    function setBadge(count) {
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }

    /* ── Load preview from server ───────────────────────────────────────── */

    function loadPreview() {
        if (ddBody) ddBody.innerHTML = '<div class="notif-empty"><i class="fa fa-circle-notch fa-spin"></i><p>Loading…</p></div>';

        fetch(baseUrl + 'actions/notification-action.php?action=get_preview')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    if (ddBody) ddBody.innerHTML = renderItems([]);
                    return;
                }
                setBadge(parseInt(data.unread_count) || 0);
                if (ddBody) ddBody.innerHTML = renderItems(data.notifications);
            })
            .catch(function () {
                if (ddBody) ddBody.innerHTML = '<div class="notif-empty"><i class="fa fa-circle-exclamation"></i><p>Could not load</p></div>';
            });
    }

    /* ── Toggle dropdown ────────────────────────────────────────────────── */

    bellBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        isOpen = !isOpen;
        dropdown.classList.toggle('open', isOpen);
        if (isOpen) loadPreview();
    });

    document.addEventListener('click', function (e) {
        if (isOpen && !dropdown.contains(e.target)) {
            isOpen = false;
            dropdown.classList.remove('open');
        }
    });

    /* ── Mark all read ──────────────────────────────────────────────────── */

    if (markAllEl) {
        markAllEl.addEventListener('click', function () {
            fetch(baseUrl + 'actions/notification-action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    setBadge(0);
                    loadPreview();
                }
            })
            .catch(function () {});
        });
    }

    /* ── Public API ─────────────────────────────────────────────────────── */

    window.NOTIF = {
        markRead: function (e, notifId, href) {
            e.preventDefault();
            fetch(baseUrl + 'actions/notification-action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&notification_id=' + notifId
            })
            .then(function () {
                if (href && href !== '#') window.location.href = href;
            })
            .catch(function () {
                if (href && href !== '#') window.location.href = href;
            });
        }
    };

    /* ── Silent background poll: load on start + every 60 s ─────────────── */

    function silentPoll() {
        fetch(baseUrl + 'actions/notification-action.php?action=get_preview')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var count = parseInt(data.unread_count) || 0;
                    setBadge(count);
                    // If dropdown is open, refresh its contents too
                    if (isOpen && ddBody) ddBody.innerHTML = renderItems(data.notifications);
                }
            })
            .catch(function () {});
    }

    silentPoll();                        // immediate on page load
    setInterval(silentPoll, 30000);      // re-check every 30 seconds

})();
