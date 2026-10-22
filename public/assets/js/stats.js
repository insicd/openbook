(function () {
    'use strict';
    var root = document.querySelector('[data-stats]');
    if (!root) return;

    var labels = JSON.parse(root.dataset.labels);
    var locale = root.dataset.locale;
    var number = new Intl.NumberFormat(locale);
    var date = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
    var hour = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'UTC', hourCycle: 'h23' });
    var names = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames([locale], { type: 'language' }) : null;
    var queue = [];
    var active = 0;

    function text(tag, value, className) {
        var element = document.createElement(tag);
        element.textContent = value;
        if (className) element.className = className;
        return element;
    }

    function languageName(code) {
        if (code === 'und') return labels.unknown;
        try { return names ? names.of(code) : code; } catch (error) { return code; }
    }

    function theme() {
        var style = getComputedStyle(root);
        function color(name) { return style.getPropertyValue(name).trim(); }
        return {
            primary: color('--ob-color-primary'), soft: color('--ob-color-primary-soft'),
            surface: color('--ob-color-surface'), border: color('--ob-color-border'),
            text: color('--ob-color-text'), muted: color('--ob-color-text-muted'),
            secondary: color('--ob-stats-secondary'), font: style.fontFamily
        };
    }

    function summary(card, items) {
        var container = card.querySelector('[data-stats-summary]');
        container.replaceChildren();
        items.forEach(function (item) {
            var cell = document.createElement('div');
            cell.append(text('strong', number.format(item[0]), 'ob-stats-summary__value'), text('span', item[1], 'ob-stats-summary__label'));
            container.append(cell);
        });
    }

    function table(card, headings, rows) {
        var target = card.querySelector('[data-stats-table]');
        var heading = document.createElement('tr');
        headings.forEach(function (label) { var th = text('th', label); th.scope = 'col'; heading.append(th); });
        target.tHead.replaceChildren(heading);
        var fragment = document.createDocumentFragment();
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            row.forEach(function (value, index) {
                var cell = text(index === 0 ? 'th' : 'td', typeof value === 'number' ? number.format(value) : value);
                if (index === 0) cell.scope = 'row';
                tr.append(cell);
            });
            fragment.append(tr);
        });
        target.tBodies[0].replaceChildren(fragment);
    }

    function render(card) {
        var data = card._statsData;
        var colors = theme();
        var rhythm = card.dataset.statsCard === 'rhythm';
        var canvas = card.querySelector('canvas');
        var options = {
            responsive: true, maintainAspectRatio: false,
            animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 450, easing: 'easeOutQuart' },
            color: colors.muted, font: { family: colors.font },
            plugins: { legend: { display: !rhythm, position: 'bottom', labels: { color: colors.text, usePointStyle: true, padding: 18 }, onClick: function () {} },
                tooltip: { backgroundColor: colors.text, titleColor: colors.surface, bodyColor: colors.surface, padding: 12, cornerRadius: 10 } },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { color: colors.muted, maxRotation: 0, autoSkip: true, maxTicksLimit: 5, font: { size: 11 } } },
                y: { beginAtZero: true, border: { display: false }, grid: { color: colors.border, drawTicks: false }, ticks: { color: colors.muted, precision: 0, maxTicksLimit: 5, padding: 8, font: { size: 11 } } }
            }
        };
        var chartData;
        var total = 0;
        if (rhythm) {
            total = data.rows.reduce(function (sum, row) { return sum + row.total; }, 0);
            var peak = data.rows.reduce(function (max, row) { return Math.max(max, row.total); }, 0);
            summary(card, [[total, labels.public_posts], [peak, labels.peak]]);
            table(card, [labels.hour, labels.local, labels.remote, labels.total], data.rows.map(function (row) { return [hour.format(new Date(row.at)) + ' UTC', row.local, row.remote, row.total]; }));
            var gradient = canvas.getContext('2d').createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, colors.soft);
            gradient.addColorStop(1, colors.surface);
            chartData = { labels: data.rows.map(function (row) { return hour.format(new Date(row.at)); }), datasets: [{ label: labels.posts, data: data.rows.map(function (row) { return row.total; }), borderColor: colors.primary, backgroundColor: gradient, fill: true, borderWidth: 2.5, tension: 0.3, cubicInterpolationMode: 'monotone', pointRadius: 0, pointHoverRadius: 4, pointHitRadius: 12 }] };
            options.interaction = { intersect: false, mode: 'index' };
            options.plugins.tooltip.callbacks = { title: function (items) { return hour.format(new Date(data.rows[items[0].dataIndex].at)) + ' UTC'; }, label: function (item) { return labels.posts + ': ' + number.format(item.parsed.y); } };
            if (!peak) options.scales.y.suggestedMax = 1;
        } else {
            var kind = card.querySelector('[data-stats-kind]').value;
            var grouped = new Map();
            data.rows.forEach(function (row) {
                if (kind !== 'all' && row.kind !== kind) return;
                if (!grouped.has(row.language)) grouped.set(row.language, { code: row.language, post: 0, event: 0, total: 0 });
                var language = grouped.get(row.language);
                language[row.kind] += row.count;
                language.total += row.count;
                total += row.count;
            });
            var rows = Array.from(grouped.values()).sort(function (a, b) { return b.total - a.total || a.code.localeCompare(b.code); });
            summary(card, [[total, labels.contents], [rows.filter(function (row) { return row.code !== 'und'; }).length, labels.languages]]);
            table(card, [labels.language, labels.posts, labels.events, labels.total], rows.map(function (row) { return [languageName(row.code) + ' (' + row.code + ')', row.post, row.event, row.total]; }));
            var displayed = rows.slice(0, 8);
            if (rows.length > 8) {
                displayed.push(rows.slice(8).reduce(function (rest, row) { rest.post += row.post; rest.event += row.event; rest.total += row.total; return rest; }, { other: true, post: 0, event: 0, total: 0 }));
            }
            chartData = { labels: displayed.map(function (row) { return row.other ? labels.other : languageName(row.code); }), datasets: [] };
            ['post', 'event'].forEach(function (type) {
                if (kind !== 'all' && kind !== type) return;
                chartData.datasets.push({ label: type === 'post' ? labels.posts : labels.events, data: displayed.map(function (row) { return row[type]; }), backgroundColor: type === 'post' ? colors.primary : colors.secondary, borderRadius: 6, borderSkipped: false, maxBarThickness: 24 });
            });
            options.indexAxis = 'y';
            options.scales.x.stacked = true;
            options.scales.x.ticks.precision = 0;
            options.scales.x.grid = { color: colors.border, drawTicks: false };
            options.scales.y.stacked = true;
            options.scales.y.grid.display = false;
            options.scales.y.ticks.autoSkip = false;
            options.scales.y.ticks.callback = function (value) { var label = this.getLabelForValue(value); return label.length > 25 ? label.slice(0, 24) + '…' : label; };
            options.plugins.tooltip.callbacks = { label: function (item) { return item.dataset.label + ': ' + number.format(item.parsed.x); } };
            if (!total) options.scales.x.suggestedMax = 1;
        }
        card.querySelector('[data-stats-results]').hidden = false;
        var status = card.querySelector('[data-stats-status]');
        status.hidden = total > 0;
        status.style.minHeight = total > 0 ? '' : '0';
        status.textContent = labels.empty;
        var period = data.period;
        card.querySelector('[data-stats-period]').textContent = date.format(new Date(period.from)) + ' – ' + (rhythm ? hour.format(new Date(period.to)) : date.format(new Date(period.to))) + ' UTC · ' + labels.end_exclusive;
        card.querySelector('[data-stats-updated]').textContent = labels.generated + ' ' + hour.format(new Date(data.generated_at)) + ' UTC';
        canvas.setAttribute('aria-label', (rhythm ? labels.rhythm_title : labels.language_title) + '. ' + labels.total + ': ' + number.format(total) + '. ' + labels.table_help);
        if (card._statsChart) card._statsChart.destroy();
        card._statsChart = new Chart(canvas, { type: rhythm ? 'line' : 'bar', data: chartData, options: options });
    }

    function enqueue(card) {
        if (card._statsState === 'loading' || card._statsState === 'queued' || card._statsState === 'loaded') return;
        card._statsState = 'queued';
        queue.push(card);
        drain();
    }

    function drain() {
        while (active < 2 && queue.length) load(queue.shift());
    }

    async function load(card) {
        active++;
        card._statsState = 'loading';
        card.setAttribute('aria-busy', 'true');
        var status = card.querySelector('[data-stats-status]');
        status.hidden = false;
        status.style.minHeight = '';
        status.textContent = labels.loading;
        var retry = card.querySelector('[data-stats-retry]');
        var login = card.querySelector('[data-stats-login]');
        retry.hidden = true;
        login.hidden = true;
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 20000);
        try {
            if (typeof Chart === 'undefined') throw new Error('chart-library');
            var response = await fetch(card.dataset.url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
            if (response.status === 401 || response.status === 419 || response.redirected) throw new Error('auth');
            if (!response.ok) throw new Error('http');
            var data = await response.json();
            if (data.version !== 1 || data.metric !== card.dataset.statsCard || !Array.isArray(data.rows) || !data.period) throw new Error('dataset');
            card._statsData = data;
            render(card);
            card._statsState = 'loaded';
        } catch (error) {
            card._statsState = 'error';
            card.querySelector('[data-stats-results]').hidden = true;
            status.textContent = error.message === 'auth' ? labels.session_expired : labels.error;
            login.hidden = error.message !== 'auth';
            retry.hidden = error.message === 'auth';
        } finally {
            clearTimeout(timeout);
            card.removeAttribute('aria-busy');
            active--;
            drain();
        }
    }

    var observer = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) { if (entry.isIntersecting) { observer.unobserve(entry.target); enqueue(entry.target); } });
    }, { rootMargin: '200px 0px' }) : null;
    root.querySelectorAll('[data-stats-card]').forEach(function (card) {
        card.querySelector('[data-stats-retry]').addEventListener('click', function () { enqueue(card); });
        var filter = card.querySelector('[data-stats-kind]');
        if (filter) filter.addEventListener('change', function () { if (card._statsData) render(card); });
        if (observer) observer.observe(card); else enqueue(card);
    });
}());
