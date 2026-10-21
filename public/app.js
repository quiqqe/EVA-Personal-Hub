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

    const update = () => {
        const type = form.querySelector('[name=type]:checked').value;
        form.querySelectorAll('[data-show]').forEach(el => show(el, el.dataset.show === type));
        show($('[data-category]'), type !== 'transfer');

        // only this type's categories; drop a selection of the other kind
        const select = $('[name=category]');
        select.querySelectorAll('optgroup').forEach(g => { g.hidden = g.dataset.kind !== type; });
        if (select.selectedOptions[0]?.parentElement.hidden) select.value = '';

        const split = $('[data-split]');
        const splitting = split && type === 'expense' && split.checked;
        if (split) show($('[data-split-box]'), splitting);
        const otherPaid = splitting && $('[data-payer]').value !== $('[data-payer]').dataset.me;
        show($('[data-account]'), !otherPaid);
        if (split) show($('[data-other-currency]'), otherPaid);

        // second amount only when the currencies differ
        show($('[data-to-amount]'), type === 'transfer' && !!cur('[data-currency-target]') && cur('[data-currency-target]') !== cur('[data-currency-source]'));
    };
    form.addEventListener('change', update);
    update();

    // exchange: amount × rate = received, editing either side updates the other
    const num = v => parseFloat(String(v).replace(/\s/g, '').replace(',', '.'));
    const amount = $('[name=amount]'), to = $('[data-fx-to]'), rate = $('[data-fx-rate]');
    const sum = () => { try { return String(amount.value).replace(/\s/g, '').replace(/,/g, '.').split(/(?=[+-])/).reduce((a, t) => a + (parseFloat(t) || 0), 0); } catch { return NaN; } };
    const round = x => +x.toFixed(8);
    const labels = () => {
        const from = cur('[data-currency-source]'), dest = cur('[data-currency-target]');
        $('[data-to-cur]').textContent = dest ? `(${dest})` : '';
        $('[data-rate-label]').textContent = from && dest ? `${dest} за 1 ${from}` : '';
    };
    if (to && rate) {
        rate.addEventListener('input', () => { const r = num(rate.value), a = sum(); if (r > 0 && a > 0) to.value = round(a * r); });
        to.addEventListener('input', () => { const t = num(to.value), a = sum(); if (t > 0 && a > 0) rate.value = round(t / a); });
        amount.addEventListener('input', () => { const r = num(rate.value), a = sum(); if (r > 0 && a > 0) to.value = round(a * r); });
        if (num(to.value) > 0 && sum() > 0) rate.value = round(num(to.value) / sum());
        form.addEventListener('change', labels);
        labels();
    }
});
