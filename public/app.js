// Progressive enhancement only: every form works without JS.
document.addEventListener('DOMContentLoaded', () => {
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
        if (select.selectedOptions[0]?.parentElement.hidden) select.value = '';

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
