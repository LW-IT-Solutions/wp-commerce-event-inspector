/**
 * Commerce Event Inspector report: the result tiles filter the order list in the page.
 * No request is made; without JavaScript the tiles are counts and the list shows all orders.
 */
(function () {
    'use strict';
    const T = window.CEVI_ADMIN || {};
    const buttons = Array.prototype.slice.call(document.querySelectorAll('[data-cevi-filter]'));
    const rows = Array.prototype.slice.call(document.querySelectorAll('#cevi-orders tr[data-status]'));
    const note = document.querySelector('.cevi-filter-note');
    const text = note ? note.querySelector('.cevi-filter-text') : null;
    if (!buttons.length || !rows.length) {
        return;
    }

    function apply(status, label) {
        let shown = 0;
        rows.forEach(function (row) {
            const on = !status || row.getAttribute('data-status') === status;
            row.hidden = !on;
            shown += on ? 1 : 0;
        });
        buttons.forEach(function (b) {
            if (b.classList.contains('cevi-tile')) {
                b.setAttribute('aria-pressed', status && b.getAttribute('data-cevi-filter') === status ? 'true' : 'false');
            }
        });
        if (note && text) {
            note.hidden = !status;
            text.textContent = status && T.shown ? T.shown.replace('%1$s', label).replace('%2$d', String(shown)) : '';
        }
    }

    buttons.forEach(function (b) {
        b.addEventListener('click', function () {
            const status = b.getAttribute('data-cevi-filter') || '';
            const active = b.getAttribute('aria-pressed') === 'true';
            const label = b.querySelector('span:last-child');
            apply(active ? '' : status, label ? label.textContent : '');
            if (!status || active) {
                const first = buttons.find(function (x) { return x.classList.contains('cevi-tile') && !x.disabled; });
                if (!status && first) {
                    first.focus();
                }
            }
        });
    });
}());
