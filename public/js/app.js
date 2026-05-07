/**
 * Precision Ink Insights — Front-end JS
 * Small vanilla helpers; no framework required.
 */
(function () {
    'use strict';

    // CSRF token used for AJAX POSTs
    window.PII = {
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    };

    // Dismissable alerts
    document.addEventListener('click', (e) => {
        if (e.target.matches('.alert-close')) {
            const alert = e.target.closest('.alert');
            if (alert) alert.remove();
        }
    });

    // ── Margin Watchdog ────────────────────────────────────────
    // Bill To row expand/collapse → AJAX-load items
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.row-expand-toggle');
        if (!btn) return;
        e.preventDefault();

        const tr      = btn.closest('tr');
        const target  = btn.dataset.target;
        const row     = document.getElementById(target);
        if (!row) return;

        const isOpen = btn.classList.toggle('is-open');
        btn.textContent = isOpen ? '▼' : '▶';
        tr.classList.toggle('expanded', isOpen);
        row.style.display = isOpen ? '' : 'none';

        if (!isOpen) return;

        // Lazy-load items the first time we open
        if (row.dataset.loaded === '1') return;

        const wrap = row.querySelector('.children-wrap');
        const billTo = btn.dataset.billto;

        try {
            const params = new URLSearchParams({
                bill_to: billTo,
                baseline_start: btn.dataset.baselineStart,
                baseline_end:   btn.dataset.baselineEnd,
                comparison_start: btn.dataset.comparisonStart,
                comparison_end:   btn.dataset.comparisonEnd,
                view_mode: getActiveItemViewMode(row)
            });
            const url = '/margin-watchdog/items?' + params.toString();
            const resp = await fetch(url, { headers: { 'Accept': 'text/html' } });
            wrap.innerHTML = await resp.text();
            row.dataset.loaded = '1';
        } catch (err) {
            wrap.innerHTML = '<div class="alert alert-danger">Failed to load items: ' + err.message + '</div>';
        }
    });

    // Item view-mode toggle inside a Bill To drill-down
    document.addEventListener('change', async (e) => {
        if (!e.target.matches('.item-view-toggle')) return;
        const row = e.target.closest('.row-children');
        const btn = document.querySelector('.row-expand-toggle[data-target="' + row.id + '"]');
        if (!row || !btn) return;
        const wrap = row.querySelector('.children-wrap');
        wrap.innerHTML = '<div class="children-loading"><span class="spinner"></span> Loading items…</div>';
        try {
            const params = new URLSearchParams({
                bill_to: btn.dataset.billto,
                baseline_start: btn.dataset.baselineStart,
                baseline_end:   btn.dataset.baselineEnd,
                comparison_start: btn.dataset.comparisonStart,
                comparison_end:   btn.dataset.comparisonEnd,
                view_mode: getActiveItemViewMode(row)
            });
            const resp = await fetch('/margin-watchdog/items?' + params.toString(), { headers: { 'Accept': 'text/html' } });
            wrap.innerHTML = await resp.text();
        } catch (err) {
            wrap.innerHTML = '<div class="alert alert-danger">Failed to load items: ' + err.message + '</div>';
        }
    });

    function getActiveItemViewMode(row) {
        const tog = row.querySelector('input.item-view-toggle:checked');
        return tog ? tog.value : 'both';
    }

    // Bill To list sort buttons
    document.addEventListener('click', (e) => {
        const sortBtn = e.target.closest('.bill-to-sort');
        if (!sortBtn) return;
        e.preventDefault();
        const sort = sortBtn.dataset.sort;
        const url = new URL(window.location.href);
        url.searchParams.set('sort', sort);
        window.location.href = url.toString();
    });

})();
