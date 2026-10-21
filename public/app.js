// Progressive enhancement only: every form works without JS.

// Long <select>s (10+ options) become a searchable picker. The native select stays in the form and keeps the value;
// the picker only reads its options (respecting hidden optgroups/options) and sets select.value + fires "change".
const norm = s => s.toLowerCase().replace(/ё/g, 'е').trim();
function searchableSelect(sel) {
    const wrap = document.createElement('div');
    wrap.className = 'ss' + (sel.closest('.in-switch') ? ' ss-pill' : '');
    sel.before(wrap);
    wrap.append(sel);
    sel.classList.add('ss-native');
    sel.tabIndex = -1;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ss-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-label', sel.getAttribute('aria-label') || sel.closest('label')?.firstChild?.textContent?.trim() || 'Выбор');
    const pop = document.createElement('div');
    pop.className = 'ss-pop';
    pop.hidden = true;
    pop.innerHTML = '<div class="ss-head"><input type="search" class="ss-search" placeholder="Поиск…" autocomplete="off" aria-label="Поиск"><button type="button" class="ss-close" aria-label="Закрыть">✕</button></div><ul class="ss-list" role="listbox"></ul>';
    const search = pop.querySelector('.ss-search'), list = pop.querySelector('.ss-list');
    // the visible button takes the select's place for <label>s: label text opens the picker
    if (sel.id) { btn.id = sel.id; sel.removeAttribute('id'); }
    wrap.prepend(btn);
    wrap.append(pop);

    const sync = () => {
        btn.textContent = sel.selectedOptions[0]?.textContent.trim() || '—';
        btn.disabled = sel.disabled;
    };
    let items = [], active = -1;

    const render = () => {
        const q = norm(search.value);
        list.innerHTML = '';
        items = [];
        const add = (opt) => {
            if (opt.hidden || opt.disabled) return false;
            const text = opt.dataset.search || opt.textContent;
            if (q && !norm(text + ' ' + opt.value).includes(q)) return false;
            const li = document.createElement('li');
            li.className = 'ss-opt' + (opt.selected ? ' selected' : '');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', String(opt.selected));
            li.textContent = opt.dataset.search && opt.dataset.search !== opt.textContent ? opt.dataset.search : opt.textContent;
            li.dataset.value = opt.value;
            li.addEventListener('mousedown', e => e.preventDefault());
            li.addEventListener('click', () => choose(opt.value));
            items.push(li);
            return li;
        };
        for (const node of sel.children) {
            if (node.tagName === 'OPTGROUP') {
                if (node.hidden) continue;
                const lis = [...node.children].map(add).filter(Boolean);
                if (lis.length) {
                    const h = document.createElement('li');
                    h.className = 'ss-group';
                    h.textContent = node.label;
                    list.append(h, ...lis);
                }
            } else {
                const li = add(node);
                if (li) list.append(li);
            }
        }
        if (!items.length) list.innerHTML = '<li class="ss-empty">Ничего не найдено</li>';
        active = Math.max(0, items.findIndex(li => li.classList.contains('selected')));
        if (q) active = 0;
        highlight();
    };
    const highlight = () => items.forEach((li, i) => {
        li.classList.toggle('active', i === active);
        if (i === active) li.scrollIntoView({block: 'nearest'});
    });
    const open = () => {
        if (sel.disabled) return;
        document.querySelectorAll('.ss.open').forEach(o => o !== wrap && o.ssClose());
        pop.hidden = false;
        wrap.classList.add('open');
        document.body.classList.toggle('ss-sheet-open', matchMedia('(max-width: 640px)').matches);
        search.value = '';
        render();
        search.focus({preventScroll: true});
    };
    const close = () => {
        pop.hidden = true;
        wrap.classList.remove('open');
        document.body.classList.remove('ss-sheet-open');
    };
    const choose = (value) => {
        if (sel.value !== value) {
            sel.value = value;
            sel.dispatchEvent(new Event('change', {bubbles: true}));
        }
        sync();
        close();
        btn.focus();
    };
    wrap.ssClose = close;

    btn.addEventListener('click', () => (pop.hidden ? open() : close()));
    pop.querySelector('.ss-close').addEventListener('click', () => { close(); btn.focus(); });
    search.addEventListener('input', render);
    search.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown') { active = Math.min(items.length - 1, active + 1); highlight(); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { active = Math.max(0, active - 1); highlight(); e.preventDefault(); }
        else if (e.key === 'Enter') { if (items[active]) choose(items[active].dataset.value); e.preventDefault(); }
        else if (e.key === 'Escape') { close(); btn.focus(); }
    });
    btn.addEventListener('keydown', e => { if (['ArrowDown', 'Enter', ' '].includes(e.key) && pop.hidden) { open(); e.preventDefault(); } });
    document.addEventListener('mousedown', e => { if (!pop.hidden && !wrap.contains(e.target)) close(); });
    sel.addEventListener('change', sync);
    // the operation form enables/disables and re-filters selects as you switch type
    new MutationObserver(sync).observe(sel, {attributes: true, attributeFilter: ['disabled']});
    sync();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select:not([data-nav])').forEach(sel => { if (sel.options.length >= 10) searchableSelect(sel); });

    // theme: system → light → dark, remembered in this browser
    const THEMES = {'': ['◐', 'системная'], light: ['☀', 'светлая'], dark: ['☾', 'тёмная']};
    const setTheme = t => {
        t ? document.documentElement.dataset.theme = t : delete document.documentElement.dataset.theme;
        try { t ? localStorage.setItem('theme', t) : localStorage.removeItem('theme'); } catch (e) {}
        document.querySelectorAll('[data-theme-toggle]').forEach(b => { b.textContent = THEMES[t][0]; b.title = 'Тема: ' + THEMES[t][1]; });
        document.querySelectorAll('[name=theme_choice]').forEach(r => { r.checked = r.value === t; });
    };
    const current = () => document.documentElement.dataset.theme || '';
    setTheme(current());
    document.querySelectorAll('[data-theme-toggle]').forEach(b => b.addEventListener('click', () => {
        const order = Object.keys(THEMES);
        setTheme(order[(order.indexOf(current()) + 1) % order.length]);
    }));
    document.querySelectorAll('[name=theme_choice]').forEach(r => r.addEventListener('change', () => setTheme(r.value)));

    // show / hide password
    document.querySelectorAll('[data-pw-toggle]').forEach(b => b.addEventListener('click', () => {
        const input = document.getElementById(b.getAttribute('aria-controls'));
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        b.setAttribute('aria-pressed', String(show));
        b.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
        input.focus();
    }));

    // Telegram sign-in: open the app (desktop: tg:// straight into Telegram Desktop, phones: t.me opens the app if installed),
    // then poll until the person confirms in the bot
    const wait = document.querySelector('[data-tg-wait]');
    if (wait) {
        const mobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        const open = wait.querySelector('[data-tg-open]');
        open.href = mobile ? wait.dataset.web : wait.dataset.app;
        if (mobile) { open.target = '_blank'; open.rel = 'noopener'; }
        const status = wait.querySelector('[data-tg-status]');
        let done = false;
        const check = async () => {
            if (done) return;
            try {
                const r = await (await fetch(wait.dataset.check, {headers: {Accept: 'application/json'}, credentials: 'same-origin'})).json();
                if (r.status === 'ok') { done = true; status.textContent = '✅ Готово, входим…'; location.href = r.redirect; }
                if (r.status === 'expired') { done = true; status.textContent = 'Время ожидания вышло — начните заново.'; }
            } catch (e) { /* network hiccup: try again on the next tick */ }
        };
        setInterval(check, 2000);
        // coming back from the Telegram app: check right away
        document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    }

    // space switcher and other <select data-nav>
    document.querySelectorAll('select[data-nav]').forEach(s => s.addEventListener('change', () => { location.href = s.value; }));

    // confirmation on destructive forms / buttons
    document.addEventListener('submit', e => {
        const btn = e.submitter, msg = btn?.dataset.confirm || e.target.dataset.confirm;
        if (msg && !confirm(msg)) e.preventDefault();
    });

    // filters and import mapping apply on change
    document.querySelectorAll('form[data-autosubmit]').forEach(f => f.addEventListener('change', () => f.submit()));
    document.querySelectorAll('[data-autosubmit-field]').forEach(el => el.addEventListener('change', () => el.form.requestSubmit(el.form.querySelector('[name=preview]'))));

    document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
        const input = document.querySelector(b.dataset.copy);
        navigator.clipboard?.writeText(input.value).then(() => { b.textContent = 'Скопировано ✓'; }, () => input.select());
    }));

    const form = document.querySelector('[data-op-form]');
    if (!form) return;
    const $ = sel => form.querySelector(sel);
    const show = (el, on) => { if (!el) return; el.hidden = !on; el.querySelectorAll('input, select').forEach(i => { i.disabled = !on; }); };
    const cur = sel => $(sel)?.selectedOptions[0]?.dataset.cur;
    const num = v => parseFloat(String(v).replace(/[\s ]/g, '').replace(',', '.'));
    // "250+120-30" like the server accepts
    const sum = v => String(v).replace(/[\s ]/g, '').replace(/,/g, '.').split(/(?=[+-])/).reduce((a, t) => a + (parseFloat(t) || 0), 0);
    const round = x => +x.toFixed(8);
    // hints: kopecks for normal sums, more digits only for small (crypto) amounts
    const fmt = x => x.toLocaleString('ru-RU', {maximumFractionDigits: Math.abs(x) >= 1 ? 2 : 8});
    const amount = $('[name=amount]');

    const update = () => {
        const type = form.querySelector('[name=type]:checked').value;
        form.querySelectorAll('[data-show]').forEach(el => show(el, el.dataset.show === type));
        show($('[data-category]'), type !== 'transfer');

        // only this type's categories; drop a selection of the other kind
        const select = $('[name=category]');
        select.querySelectorAll('optgroup').forEach(g => { g.hidden = g.dataset.kind !== type; });
        if (select.selectedOptions[0]?.parentElement.hidden) { select.value = ''; select.dispatchEvent(new Event('change', {bubbles: true})); }

        const split = $('[data-split]');
        const splitting = !!split && type === 'expense' && split.checked;
        let iPay = true;
        if (split) {
            show($('[data-split-box]'), splitting);
            if (splitting) {
                const payer = $('[data-payer]'), many = payer.value === 'many';
                const mode = form.querySelector('[name=split_mode]:checked').value;
                form.querySelectorAll('[data-equal-only]').forEach(c => { c.hidden = mode !== 'equal'; c.disabled = mode !== 'equal'; });
                form.querySelectorAll('[data-part]').forEach(i => { i.hidden = mode === 'equal'; i.disabled = mode === 'equal'; i.placeholder = {amounts: '0', percent: '%', shares: '1'}[mode] || ''; });
                form.querySelectorAll('[data-paid]').forEach(i => { i.hidden = !many; i.disabled = !many; });
                $('[data-col-part]').hidden = mode === 'equal';
                $('[data-col-paid]').hidden = !many;
                $('[data-people]').classList.toggle('with-part', mode !== 'equal');
                $('[data-people]').classList.toggle('with-paid', many);
                const mine = form.querySelector(`[data-paid][data-id="${payer.dataset.me}"]`);
                iPay = many ? sum(mine.value) > 0 : payer.value === payer.dataset.me;
                hint(mode, many);
            }
        }
        show($('[data-account]'), iPay);
        if (split) show($('[data-other-currency]'), splitting && !iPay);

        // second amount only when the currencies differ
        show($('[data-to-amount]'), type === 'transfer' && !!cur('[data-currency-target]') && cur('[data-currency-target]') !== cur('[data-currency-source]'));
    };

    // live "how much is left to distribute"
    const hint = (mode, many) => {
        const total = sum(amount.value), lines = [];
        const parts = [...form.querySelectorAll('[data-part]')].map(i => num(i.value) || 0);
        const people = mode === 'equal' ? form.querySelectorAll('[data-equal-only]:checked').length : parts.filter(v => v > 0).length;
        if (mode === 'equal' && people) lines.push(`по ${fmt(total / people)} на человека`);
        if (mode === 'amounts') { const left = total - parts.reduce((a, b) => a + b, 0); lines.push(Math.abs(left) < 1e-9 ? 'доли сходятся ✓' : `осталось распределить ${fmt(left)}`); }
        if (mode === 'percent') { const p = parts.reduce((a, b) => a + b, 0); lines.push(Math.abs(p - 100) < 1e-6 ? '100% ✓' : `в сумме ${fmt(p)}% — нужно 100%`); }
        if (mode === 'shares') { const sh = parts.reduce((a, b) => a + b, 0); if (sh > 0) lines.push(`1 доля = ${fmt(total / sh)}`); }
        if (many) { const paid = [...form.querySelectorAll('[data-paid]')].reduce((a, i) => a + sum(i.value), 0); const left = total - paid; lines.push(Math.abs(left) < 1e-9 ? 'оплата сходится ✓' : `оплачено ${fmt(paid)} из ${fmt(total)}`); }
        $('[data-split-hint]').textContent = lines.join(' · ');
    };

    form.addEventListener('change', update);
    form.addEventListener('input', e => { if (e.target.matches('[data-part], [data-paid], [name=amount]')) update(); });
    update();

    // exchange: amount × rate = received, editing either side updates the other
    const to = $('[data-fx-to]'), rate = $('[data-fx-rate]');
    const labels = () => {
        const from = cur('[data-currency-source]'), dest = cur('[data-currency-target]');
        $('[data-to-cur]').textContent = dest ? `(${dest})` : '';
        $('[data-rate-label]').textContent = from && dest ? `${dest} за 1 ${from}` : '';
    };
    if (to && rate) {
        rate.addEventListener('input', () => { const r = num(rate.value), a = sum(amount.value); if (r > 0 && a > 0) to.value = round(a * r); });
        to.addEventListener('input', () => { const t = num(to.value), a = sum(amount.value); if (t > 0 && a > 0) rate.value = round(t / a); });
        amount.addEventListener('input', () => { const r = num(rate.value), a = sum(amount.value); if (r > 0 && a > 0) to.value = round(a * r); });
        if (num(to.value) > 0 && sum(amount.value) > 0) rate.value = round(num(to.value) / sum(amount.value));
        form.addEventListener('change', labels);
        labels();
    }
});
