/* Commerce Event Inspector: reads GA4 e-commerce events from window.dataLayer (gtag() writes there too).
 * Order confirmation: sends what it found to this site once, for the comparison with the order.
 * Inspector: shows every event with its checks in a small panel, for the logged-in shop manager only. */
(function () {
    'use strict';
    const C = window.CEVI;
    if (!C) {
        return;
    }
    const T = C.text;
    const KNOWN = ['view_item_list', 'select_item', 'view_item', 'add_to_wishlist', 'add_to_cart', 'remove_from_cart',
        'view_cart', 'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase', 'refund',
        'view_promotion', 'select_promotion'];
    const NEEDS_ITEMS = ['view_item_list', 'select_item', 'view_item', 'add_to_wishlist', 'add_to_cart', 'remove_from_cart',
        'view_cart', 'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase'];
    const found = [];
    let panel = null;

    function num(v) {
        if (typeof v === 'number' && isFinite(v)) {
            return v;
        }
        if (typeof v === 'string' && v.trim() !== '' && isFinite(Number(v))) {
            return Number(v);
        }
        return null;
    }

    function str(v) {
        return (typeof v === 'string' || typeof v === 'number') ? String(v).slice(0, 100) : '';
    }

    function items(raw, legacy) {
        if (!Array.isArray(raw)) {
            return null;
        }
        return raw.slice(0, 100).map(function (i) {
            i = i && typeof i === 'object' ? i : {};
            return {
                item_id: str(legacy ? i.id : i.item_id),
                item_name: str(legacy ? i.name : i.item_name),
                price: num(i.price),
                quantity: i.quantity === undefined ? null : num(i.quantity)
            };
        });
    }

    function make(name, p, source, legacy) {
        p = p && typeof p === 'object' ? p : {};
        return {
            name: name,
            source: source,
            legacy: !!legacy,
            currency: str(p.currency).toUpperCase(),
            has_value: p.value !== undefined,
            value: num(p.value),
            value_raw: p.value !== undefined && num(p.value) === null ? str(p.value).slice(0, 20) : '',
            transaction_id: str(p.transaction_id),
            tax: num(p.tax),
            shipping: num(p.shipping),
            items: items(p.items, legacy),
            items_count: Array.isArray(p.items) ? p.items.length : 0
        };
    }

    // gtag('event', name, params) pushes an Arguments object; GTM-style code pushes {event, ecommerce}.
    function normalize(e) {
        if (!e || typeof e !== 'object') {
            return null;
        }
        const argsLike = Array.isArray(e) || Object.prototype.toString.call(e) === '[object Arguments]';
        if (argsLike) {
            return e[0] === 'event' && KNOWN.indexOf(e[1]) !== -1 ? make(e[1], e[2], 'gtag') : null;
        }
        const ec = e.ecommerce;
        if (typeof e.event === 'string' && KNOWN.indexOf(e.event) !== -1 && ec && typeof ec === 'object') {
            if (ec.purchase && ec.purchase.actionField) {
                const af = ec.purchase.actionField;
                return make('purchase', {currency: ec.currencyCode, value: af.revenue, transaction_id: af.id, tax: af.tax,
                    shipping: af.shipping, items: ec.purchase.products}, 'dataLayer', true);
            }
            return make(e.event, ec, 'dataLayer');
        }
        if (ec && typeof ec === 'object' && ec.purchase && ec.purchase.actionField) {
            const af = ec.purchase.actionField;
            return make('purchase', {currency: ec.currencyCode, value: af.revenue, transaction_id: af.id, tax: af.tax,
                shipping: af.shipping, items: ec.purchase.products}, 'dataLayer', true);
        }
        return null;
    }

    // Tag Manager and gtag.js accept another array name with l=..., and plugins such as PixelYourSite use their own
    // (dataLayerPYS). Read dataLayer, every array whose name starts with dataLayer, and names set by a loader.
    const lists = {};
    let names = ['dataLayer'];
    let lastNames = 0;

    function findNames() {
        const set = {dataLayer: true};
        Object.keys(window).forEach(function (k) {
            if (/^dataLayer/.test(k)) {
                set[k] = true;
            }
        });
        Array.prototype.forEach.call(document.querySelectorAll('script[src*="gtm.js"], script[src*="gtag/js"]'), function (s) {
            const m = /[?&]l=([A-Za-z_$][\w$]{0,60})/.exec(s.getAttribute('src') || '');
            if (m) {
                set[m[1]] = true;
            }
        });
        names = Object.keys(set);
    }

    function scan() {
        if (Date.now() - lastNames > 2000) {
            lastNames = Date.now();
            findNames();
        }
        names.forEach(function (name) {
            const dl = window[name];
            if (!Array.isArray(dl)) {
                return;
            }
            const state = lists[name] || (lists[name] = {arr: null, next: 0});
            if (dl !== state.arr) {
                state.arr = dl;
                state.next = 0;
            }
            for (; state.next < dl.length; state.next++) {
                let n = null;
                try {
                    n = normalize(dl[state.next]);
                } catch (err) {
                    n = null;
                }
                if (n && found.length < 50) {
                    n.list = name;
                    found.push(n);
                    if (panel) {
                        show(n);
                    }
                }
            }
        });
    }

    // Order confirmation: send once, after the page had time to push its events, or when it is left.
    let sent = false;
    function send() {
        if (sent || !C.order) {
            return;
        }
        sent = true;
        scan();
        fetch(C.rest, {
            method: 'POST',
            keepalive: true,
            credentials: 'omit',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({order: C.order.id, key: C.order.key, staff: !!C.staff, events: found.slice(0, 20)})
        }).catch(function () {});
    }

    // ---- Inspector panel ----

    function close(a, b) {
        return a !== null && b !== null && b !== undefined && Math.abs(a - b) < 0.011;
    }

    function fmt(v) {
        return v === null || v === undefined ? '–' : Number(v).toFixed(2);
    }

    function sprintf(s) {
        const args = Array.prototype.slice.call(arguments, 1);
        let i = 0;
        return s.replace(/%(\d+\$)?s/g, function (m, pos) {
            return String(args[pos ? parseInt(pos, 10) - 1 : i++]);
        });
    }

    function itemChecks(n, ctx, out) {
        const used = [];
        (n.items || []).forEach(function (it) {
            let hit = null;
            ctx.items.forEach(function (c, k) {
                if (hit === null && ((it.item_id !== '' && c.ids.indexOf(it.item_id) !== -1) || (it.item_id === '' && it.item_name !== '' && it.item_name === c.name))) {
                    hit = k;
                }
            });
            const label = it.item_id || it.item_name || '?';
            if (hit === null) {
                out.push(['warn', sprintf(T.itemUnknown, label)]);
                return;
            }
            used.push(hit);
            const c = ctx.items[hit];
            if (c.qty !== undefined && it.quantity !== null && it.quantity !== c.qty) {
                out.push(['warn', sprintf(T.itemQty, label, it.quantity, c.qty)]);
            }
            if (it.price === null) {
                out.push(['warn', sprintf(T.itemNoPrice, label)]);
            } else if (close(it.price, c.gross)) {
                out.push(['ok', sprintf(T.itemGross, label, fmt(it.price))]);
            } else if (close(it.price, c.net)) {
                out.push(['ok', sprintf(T.itemNet, label, fmt(it.price))]);
            } else if (close(it.price, c.gross_full) || close(it.price, c.net_full)) {
                out.push(['ok', sprintf(T.itemFull, label, fmt(it.price))]);
            } else {
                out.push(['warn', sprintf(T.itemPrice, label, fmt(it.price), fmt(c.gross), fmt(c.net))]);
            }
        });
        if (ctx.type !== 'product' && !(n.items_count > (n.items || []).length)) {
            ctx.items.forEach(function (c, k) {
                if (used.indexOf(k) === -1) {
                    out.push(['warn', sprintf(T.itemMissing, c.name)]);
                }
            });
        }
    }

    function checks(n) {
        const out = [];
        const ctx = C.context;
        if (n.legacy) {
            out.push(['warn', T.legacy]);
        }
        if ((n.name === 'purchase' || n.name === 'refund') && n.transaction_id === '') {
            out.push(['fail', T.noTransaction]);
        }
        if (n.has_value && n.value === null) {
            out.push(['fail', n.value_raw ? sprintf(T.valueText, n.value_raw) : T.valueNotNumber]);
        }
        if (n.has_value && n.currency === '') {
            out.push(['fail', T.noCurrency]);
        } else if (n.currency !== '' && n.currency !== C.currency) {
            out.push(['warn', sprintf(T.otherCurrency, n.currency, C.currency)]);
        }
        if (NEEDS_ITEMS.indexOf(n.name) !== -1) {
            if (!n.items || !n.items.length) {
                out.push(['fail', T.noItems]);
            } else {
                n.items.forEach(function (it) {
                    if (it.item_id === '' && it.item_name === '') {
                        out.push(['fail', T.itemNoId]);
                    }
                    if (it.quantity !== null && (it.quantity < 1 || Math.floor(it.quantity) !== it.quantity)) {
                        out.push(['warn', sprintf(T.badQty, it.item_id || it.item_name, it.quantity)]);
                    }
                });
            }
        }
        if (ctx && n.items && n.items.length && (ctx.type !== 'product' || ['view_item', 'add_to_cart', 'add_to_wishlist'].indexOf(n.name) !== -1)) {
            itemChecks(n, ctx, out);
        }
        if (ctx && ctx.values && n.value !== null && ['view_cart', 'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase'].indexOf(n.name) !== -1) {
            let match = '';
            Object.keys(ctx.values).forEach(function (k) {
                if (!match && close(n.value, ctx.values[k])) {
                    match = k;
                }
            });
            out.push(match ? ['ok', sprintf(T.valueMatch, fmt(n.value), T.values[match])] : ['fail', sprintf(T.valueNoMatch, fmt(n.value), fmt(ctx.values.total))]);
        }
        if (ctx && ctx.number && n.name === 'purchase' && n.transaction_id !== '') {
            out.push(n.transaction_id === ctx.number || n.transaction_id === String(ctx.id)
                ? ['ok', sprintf(T.transactionOk, n.transaction_id)]
                : ['fail', sprintf(T.transactionOther, n.transaction_id, ctx.number)]);
        }
        if (!out.some(function (o) { return o[0] !== 'ok'; })) {
            out.push(['ok', T.allOk]);
        }
        return out;
    }

    function el(tag, cls, text) {
        const e = document.createElement(tag);
        if (cls) {
            e.className = cls;
        }
        if (text !== undefined) {
            e.textContent = text;
        }
        return e;
    }

    let listEl = null;
    let countEl = null;
    // Shown entries of this page; kept for the next page in this tab, because an add_to_cart pushed right before a
    // form submit would otherwise vanish with the reload.
    const shown = [];
    const KEY = 'ceviPrevious';

    function entry(rec, target) {
        const li = el('li');
        const head = el('div', 'cevi-name', rec.name);
        head.appendChild(el('span', 'cevi-source', rec.meta));
        li.appendChild(head);
        const ul = el('ul');
        rec.lines.forEach(function (r) {
            ul.appendChild(el('li', 'cevi-' + r[0], r[1]));
        });
        li.appendChild(ul);
        target.insertBefore(li, target.firstChild);
    }

    function show(n) {
        const purchases = found.filter(function (f) { return f.name === 'purchase'; }).length;
        const res = checks(n);
        if (n.name === 'purchase' && purchases > 1) {
            res.unshift(['fail', sprintf(T.duplicate, purchases)]);
            // The closing "no problem found" no longer holds once the page pushed purchase twice.
            if (res[res.length - 1][1] === T.allOk) {
                res.pop();
            }
        }
        const rec = {name: n.name, meta: n.source + (n.list && n.list !== 'dataLayer' ? ' · ' + n.list : '') + (n.value !== null ? ' · ' + fmt(n.value) + ' ' + n.currency : ''), lines: res};
        shown.push(rec);
        entry(rec, listEl);
        countEl.textContent = String(found.length);
        panel.classList.remove('cevi-empty');
    }

    function previous(body) {
        let prev = null;
        try {
            prev = JSON.parse(window.sessionStorage.getItem(KEY) || 'null');
            window.sessionStorage.removeItem(KEY);
        } catch (err) {
            prev = null;
        }
        if (!prev || !Array.isArray(prev.entries) || !prev.entries.length) {
            return;
        }
        body.appendChild(el('p', 'cevi-prev', sprintf(T.previous, String(prev.path).slice(0, 200))));
        const ol = el('ol', 'cevi-list cevi-prev-list');
        prev.entries.slice(-20).forEach(function (rec) {
            if (rec && typeof rec.name === 'string' && Array.isArray(rec.lines)) {
                entry({name: rec.name, meta: String(rec.meta || ''), lines: rec.lines.map(function (l) { return [String(l[0]).replace(/[^a-z]/g, ''), String(l[1])]; })}, ol);
            }
        });
        body.appendChild(ol);
    }

    function build() {
        panel = el('div', 'cevi-panel cevi-empty');
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-label', T.title);
        const bar = el('button', 'cevi-bar');
        bar.type = 'button';
        bar.setAttribute('aria-expanded', 'true');
        bar.appendChild(el('span', '', T.title + ' '));
        countEl = el('span', 'cevi-count', '0');
        bar.appendChild(countEl);
        bar.addEventListener('click', function () {
            const open = panel.classList.toggle('cevi-closed');
            bar.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
        panel.appendChild(bar);
        const body = el('div', 'cevi-body');
        body.appendChild(el('p', 'cevi-hint', C.context ? T.context[C.context.type] : T.noContext));
        listEl = el('ol', 'cevi-list');
        body.appendChild(listEl);
        body.appendChild(el('p', 'cevi-none', T.none));
        previous(body);
        panel.appendChild(body);
        document.body.appendChild(panel);
        found.forEach(show);
        window.addEventListener('pagehide', function () {
            scan();
            try {
                window.sessionStorage.setItem(KEY, JSON.stringify({path: window.location.pathname, entries: shown.slice(-20)}));
            } catch (err) {
                // Storage blocked: the panel still works for the current page.
            }
        });
    }

    if (C.inspector) {
        if (document.body) {
            build();
        } else {
            document.addEventListener('DOMContentLoaded', build);
        }
    }
    scan();
    setInterval(scan, 400);
    if (C.order) {
        window.addEventListener('load', function () {
            setTimeout(send, 4000);
        });
        window.addEventListener('pagehide', send);
    }
})();
