/**
 * Commerce Event Inspector report.
 * Tiles, ring arcs and day columns filter the order list, the search field narrows it further and marks the hits,
 * "Copy problems as text" puts the problems of the listed orders on the clipboard. Numbers count up once on load.
 * No request is made; without JavaScript all values stay in the HTML and the list shows all orders.
 */
(function () {
    'use strict';
    const T = window.CEVI_ADMIN || {};
    const wrap = document.querySelector('.cevi-wrap');
    if (!wrap) {
        return;
    }
    const still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const all = function (sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    };
    const rows = all('#cevi-orders tbody tr[data-status]');
    const note = wrap.querySelector('.cevi-filter-note');
    const noteText = note ? note.querySelector('.cevi-filter-text') : null;
    const noMatch = wrap.querySelector('.cevi-no-match');
    const search = document.getElementById('cevi-search');
    const state = { status: '', statusLabel: '', day: '', dayLabel: '', q: '' };

    /* Count up: tiles and the share in the ring, once, from 0 to the value in the HTML. */
    function countUp(el) {
        const end = parseInt(el.getAttribute('data-cevi-count'), 10);
        const suffix = el.getAttribute('data-cevi-suffix') || '';
        const fmt = window.Intl ? new Intl.NumberFormat(document.documentElement.lang || undefined) : null;
        if (still || !end || !window.requestAnimationFrame) {
            return;
        }
        const t0 = performance.now();
        const dur = 700;
        function step(t) {
            const p = Math.min(1, Math.max(0, (t - t0) / dur));
            const v = Math.round(end * (1 - Math.pow(1 - p, 3)));
            el.textContent = (fmt ? fmt.format(v) : String(v)) + suffix;
            if (p < 1) {
                window.requestAnimationFrame(step);
            }
        }
        el.textContent = '0' + suffix;
        window.requestAnimationFrame(step);
    }
    all('[data-cevi-count]', wrap).forEach(countUp);

    /* One tooltip for ring arcs and day columns; shown on hover and keyboard focus, closed with Escape. */
    const tip = document.createElement('div');
    tip.className = 'cevi-tip';
    tip.setAttribute('role', 'tooltip');
    tip.id = 'cevi-tip';
    tip.hidden = true;
    document.body.appendChild(tip);
    function showTip(el, point) {
        const text = el.getAttribute('data-cevi-tip');
        if (!text) {
            return;
        }
        tip.textContent = text;
        tip.hidden = false;
        // Arcs of the ring: above the pointer; otherwise above the element (keyboard focus, day columns).
        const r = point ? { left: point[0], top: point[1] - 6, width: 0, bottom: point[1] + 16 } : el.getBoundingClientRect();
        const w = tip.offsetWidth;
        const h = tip.offsetHeight;
        let x = r.left + r.width / 2 - w / 2;
        x = Math.max(8, Math.min(x, document.documentElement.clientWidth - w - 8));
        let y = r.top - h - 8;
        if (y < 40) {
            y = r.bottom + 8;
        }
        tip.style.left = (x + window.scrollX) + 'px';
        tip.style.top = (y + window.scrollY) + 'px';
    }
    function hideTip() {
        tip.hidden = true;
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            hideTip();
        }
    });

    /* Ring arcs become filter buttons. */
    const ring = wrap.querySelector('.cevi-ring-svg');
    const arcs = all('.cevi-ring-seg', wrap);
    if (ring && arcs.length && rows.length) {
        ring.setAttribute('role', 'group');
        arcs.forEach(function (a) {
            const t = a.querySelector('title');
            if (t) {
                t.parentNode.removeChild(t);
            }
            a.setAttribute('tabindex', '0');
            a.setAttribute('role', 'button');
            a.setAttribute('aria-label', a.getAttribute('data-cevi-tip'));
            a.setAttribute('aria-pressed', 'false');
            a.setAttribute('aria-controls', 'cevi-orders');
        });
    }

    /* Day columns and arcs share the tooltip. */
    const days = all('.cevi-day[data-cevi-day]', wrap);
    [].concat(arcs, days).forEach(function (el) {
        el.addEventListener('mousemove', function (e) { showTip(el, el.tagName === 'circle' ? [e.clientX, e.clientY] : null); });
        el.addEventListener('mouseleave', hideTip);
        el.addEventListener('focus', function () { showTip(el); });
        el.addEventListener('blur', hideTip);
    });

    /* Search hits are marked in each cell; the original markup is restored before every new search. */
    const cells = [];
    rows.forEach(function (row) {
        all('td', row).forEach(function (td) {
            cells.push({ row: row, td: td, html: td.innerHTML, marked: false });
        });
    });
    function mark(node, q) {
        const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, null);
        const hits = [];
        let n;
        while ((n = walker.nextNode())) {
            if (n.parentNode.closest('.screen-reader-text, summary')) {
                continue;
            }
            if (n.nodeValue.toLowerCase().indexOf(q) !== -1) {
                hits.push(n);
            }
        }
        hits.forEach(function (t) {
            const frag = document.createDocumentFragment();
            const low = t.nodeValue.toLowerCase();
            let i = 0;
            let at;
            while ((at = low.indexOf(q, i)) !== -1) {
                frag.appendChild(document.createTextNode(t.nodeValue.slice(i, at)));
                const m = document.createElement('mark');
                m.className = 'cevi-hit';
                m.textContent = t.nodeValue.slice(at, at + q.length);
                frag.appendChild(m);
                i = at + q.length;
            }
            frag.appendChild(document.createTextNode(t.nodeValue.slice(i)));
            t.parentNode.replaceChild(frag, t);
        });
        return hits.length;
    }

    function apply() {
        const q = state.q.trim().toLowerCase();
        cells.forEach(function (c) {
            if (c.marked) {
                // Keep the comparison open if it was open before the markup is restored.
                const box = c.td.querySelector('.cevi-compare');
                const open = box && box.open;
                c.td.innerHTML = c.html;
                c.marked = false;
                if (open) {
                    c.td.querySelector('.cevi-compare').open = true;
                }
            }
        });
        let shown = 0;
        rows.forEach(function (row) {
            const on = (!state.status || row.getAttribute('data-status') === state.status) &&
                (!state.day || row.getAttribute('data-day') === state.day) &&
                (!q || row.textContent.toLowerCase().indexOf(q) !== -1);
            row.hidden = !on;
            shown += on ? 1 : 0;
        });
        if (q) {
            cells.forEach(function (c) {
                if (!c.row.hidden && c.td.textContent.toLowerCase().indexOf(q) !== -1) {
                    c.marked = true;
                    mark(c.td, q);
                    const box = c.td.querySelector('.cevi-compare');
                    if (box && box.querySelector('.cevi-hit')) {
                        box.open = true;
                    }
                }
            });
        }
        all('.cevi-tile, .cevi-ring-seg', wrap).forEach(function (b) {
            const on = !!state.status && b.getAttribute('data-cevi-filter') === state.status;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.classList.toggle('is-active', on);
        });
        wrap.classList.toggle('cevi-has-status', !!state.status);
        days.forEach(function (b) {
            b.setAttribute('aria-pressed', state.day && b.getAttribute('data-cevi-day') === state.day ? 'true' : 'false');
        });
        wrap.classList.toggle('cevi-has-day', !!state.day);
        const parts = [];
        if (state.statusLabel) {
            parts.push(state.statusLabel);
        }
        if (state.dayLabel) {
            parts.push(state.dayLabel);
        }
        if (q && T.search) {
            parts.push(T.search.replace('%s', state.q.trim()));
        }
        if (note && noteText) {
            note.hidden = !parts.length;
            noteText.textContent = parts.length && T.shown ?
                T.shown.replace('%1$d', String(shown)).replace('%2$d', String(rows.length)) + ' ' + (T.filter ? T.filter.replace('%s', parts.join(', ')) : '') : '';
        }
        if (noMatch) {
            noMatch.hidden = shown > 0;
        }
    }

    all('[data-cevi-filter]', wrap).forEach(function (b) {
        function act() {
            const status = b.getAttribute('data-cevi-filter') || '';
            if (!status) {
                // "Show all orders" resets every filter and the search.
                state.status = state.statusLabel = state.day = state.dayLabel = state.q = '';
                if (search) {
                    search.value = '';
                }
                apply();
                const first = wrap.querySelector('.cevi-tile:not(:disabled)');
                if (first) {
                    first.focus();
                }
                return;
            }
            const same = state.status === status;
            const tile = wrap.querySelector('.cevi-tile[data-cevi-filter="' + status + '"] span:last-child');
            state.status = same ? '' : status;
            state.statusLabel = same || !tile ? '' : tile.textContent;
            apply();
        }
        b.addEventListener('click', act);
        if (b.classList.contains('cevi-ring-seg')) {
            b.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    act();
                }
            });
        }
    });

    days.forEach(function (b) {
        b.addEventListener('click', function () {
            const day = b.getAttribute('data-cevi-day');
            const same = state.day === day;
            state.day = same ? '' : day;
            state.dayLabel = same ? '' : b.getAttribute('data-cevi-day-label');
            apply();
        });
    });

    /* Toolbar: search with the "/" shortcut and the copy button; shown only with JavaScript. */
    const bar = wrap.querySelector('.cevi-toolbar');
    if (bar && rows.length) {
        bar.hidden = false;
    }
    if (search) {
        let timer = 0;
        search.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                state.q = search.value;
                apply();
            }, 120);
        });
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && search.value) {
                e.preventDefault();
                search.value = '';
                state.q = '';
                apply();
            }
        });
        document.addEventListener('keydown', function (e) {
            const t = e.target;
            const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
            if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                search.focus();
                search.select();
            }
        });
    }

    /* Copy the problems and notes of the listed orders, for a ticket or a message to the agency. */
    const copy = wrap.querySelector('.cevi-copy');
    const status = wrap.querySelector('.cevi-copy-status');
    const area = wrap.querySelector('.cevi-copy-area');
    function copyText() {
        const lines = [copy.getAttribute('data-cevi-copy-head') || '', ''];
        let n = 0;
        rows.forEach(function (row) {
            if (row.hidden || ['fail', 'warn', 'missing'].indexOf(row.getAttribute('data-status')) === -1) {
                return;
            }
            const num = row.querySelector('.cevi-order');
            const badge = row.querySelector('.cevi-badge');
            lines.push((num ? num.textContent.trim() : '') + ' – ' + (badge ? badge.textContent.trim() : ''));
            all('.cevi-checks li.cevi-fail, .cevi-checks li.cevi-warn', row).forEach(function (li) {
                const c = li.cloneNode(true);
                all('.screen-reader-text', c).forEach(function (s) { s.parentNode.removeChild(s); });
                lines.push((li.classList.contains('cevi-fail') ? '  ✗ ' : '  ! ') + c.textContent.trim());
            });
            lines.push('');
            n++;
        });
        return n ? lines.join('\n').trim() + '\n' : '';
    }
    function done() {
        copy.classList.remove('is-done');
        void copy.offsetWidth;
        copy.classList.add('is-done');
        window.setTimeout(function () { copy.classList.remove('is-done'); }, 2200);
    }
    if (copy && status) {
        copy.addEventListener('click', function () {
            const text = copyText();
            if (area) {
                area.hidden = true;
            }
            if (!text) {
                status.textContent = T.copyNone || '';
                return;
            }
            function fallback() {
                status.textContent = T.copyFail || '';
                if (area) {
                    area.value = text;
                    area.hidden = false;
                    area.focus();
                    area.select();
                }
            }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    status.textContent = T.copied || '';
                    done();
                }, fallback);
            } else {
                fallback();
            }
        });
    }
}());
