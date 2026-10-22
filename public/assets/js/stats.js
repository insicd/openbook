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
    var datasets = new Map();

    function text(tag, value, className) {
        var element = document.createElement(tag);
        element.textContent = value;
        if (className) element.className = className;
        return element;
    }

    function languageName(code) {
        try { return names ? names.of(code) : code; } catch (error) { return code; }
    }

    function theme() {
        var style = getComputedStyle(root);
        function color(name) { return style.getPropertyValue(name).trim(); }
        return {
            primary: color('--ob-color-primary'), soft: color('--ob-color-primary-soft'),
            surface: color('--ob-color-surface'), border: color('--ob-color-border'),
            text: color('--ob-color-text'), muted: color('--ob-color-text-muted'),
            secondary: color('--ob-color-success'), third: color('--ob-color-text-muted'), font: style.fontFamily
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

    function horizontal(options) {
        options.indexAxis = 'y';
        options.scales.x.ticks.precision = 0;
        options.scales.x.grid = { color: theme().border, drawTicks: false };
        options.scales.y.grid.display = false;
        options.scales.y.ticks.autoSkip = false;
        options.scales.y.ticks.callback = function (value) { var label = this.getLabelForValue(value); return label.length > 25 ? label.slice(0, 24) + '…' : label; };
    }

    function rankedDomains(rows) {
        var counts = new Map();
        rows.forEach(function (row) { counts.set(row.domain, (counts.get(row.domain) || 0) + row.count); });
        return Array.from(counts, function (entry) { return { domain: entry[0], count: entry[1] }; })
            .sort(function (a, b) { return b.count - a.count || a.domain.localeCompare(b.domain); });
    }

    function domainChart(card, options, colors) {
        var rows = card._statsData.rows;
        var ranked = rankedDomains(rows);
        var total = rows.reduce(function (sum, row) { return sum + row.count; }, 0);
        var local = rows.reduce(function (sum, row) { return sum + (row.local ? row.count : 0); }, 0);
        summary(card, [[total, labels.known_actors], [ranked.length, labels.domains], [local, labels.local_actors]]);
        var top = ranked.slice(0, 8).map(function (row) { return row.domain; });
        var buckets = top.map(function (domain) { return { domain: domain, person: 0, group: 0, feed: 0 }; });
        rows.forEach(function (row) {
            var index = top.indexOf(row.domain);
            if (index < 0) return;
            var bucket = buckets[index];
            bucket[row.type] = (bucket[row.type] || 0) + row.count;
        });
        horizontal(options);
        options.scales.x.stacked = true;
        options.scales.y.stacked = true;
        options.plugins.legend.display = true;
        options.plugins.tooltip.callbacks = { label: function (item) { return item.dataset.label + ': ' + number.format(item.parsed.x) + ' (' + number.format(total ? item.parsed.x / total * 100 : 0) + '%)'; } };
        if (!total) options.scales.x.suggestedMax = 1;
        return { total: total, type: 'bar', data: {
            labels: buckets.map(function (row) { return row.domain; }),
            datasets: ['person', 'group', 'feed'].map(function (type, index) { return { label: labels[type], data: buckets.map(function (row) { return row[type]; }), backgroundColor: [colors.primary, colors.secondary, colors.third][index], borderRadius: 6, borderSkipped: false, maxBarThickness: 24 }; })
        } };
    }

    function weeklyChart(card, options, colors) {
        var data = card._statsData;
        var authors = card.dataset.statsCard === 'authors';
        var weeks = new Map(data.weeks.map(function (week) { return [week, 0]; }));
        data.rows.forEach(function (row) { weeks.set(row.week, weeks.get(row.week) + (authors ? row.authors : row.count)); });
        var totals = Array.from(weeks.values());
        var peak = Math.max.apply(null, totals.concat([0]));
        var total = authors ? peak : totals.reduce(function (sum, value) { return sum + value; }, 0);
        summary(card, authors ? [[peak, labels.peak_authors]] : [[total, labels.public_posts], [peak, labels.peak_week_posts]]);
        options.plugins.legend.display = false;
        options.interaction = { intersect: false, mode: 'index' };
        options.plugins.tooltip.callbacks = {
            title: function (items) { return labels.week_of + ' ' + date.format(new Date(data.weeks[items[0].dataIndex] + 'T00:00:00Z')); },
            label: function (item) { return item.dataset.label + ': ' + number.format(item.parsed.y); }
        };
        if (!peak) options.scales.y.suggestedMax = 1;
        return { total: total, type: 'line', data: {
            labels: data.weeks.map(function (week) { return date.format(new Date(week + 'T00:00:00Z')); }),
            datasets: [{ label: labels.total, data: totals, borderColor: colors.primary, backgroundColor: colors.primary, borderWidth: 2.5, tension: 0.3, cubicInterpolationMode: 'monotone', pointRadius: 0, pointHoverRadius: 4, pointHitRadius: 10 }]
        } };
    }

    function render(card) {
        var data = card._statsData;
        var colors = theme();
        var metric = card.dataset.statsCard;
        var rhythm = metric === 'rhythm';
        var chartType = rhythm ? 'line' : 'bar';
        var accessibleDetails = '';
        var canvas = card.querySelector('canvas');
        var options = {
            responsive: true, maintainAspectRatio: false,
            animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 450, easing: 'easeOutQuart' },
            color: colors.muted, font: { family: colors.font },
            plugins: { legend: { display: false, position: 'bottom', labels: { color: colors.text, usePointStyle: true, padding: 18 }, onClick: function () {} },
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
            var gradient = canvas.getContext('2d').createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, colors.soft);
            gradient.addColorStop(1, colors.surface);
            chartData = { labels: data.rows.map(function (row) { return hour.format(new Date(row.at)); }), datasets: [{ label: labels.posts, data: data.rows.map(function (row) { return row.total; }), borderColor: colors.primary, backgroundColor: gradient, fill: true, borderWidth: 2.5, tension: 0.3, cubicInterpolationMode: 'monotone', pointRadius: 0, pointHoverRadius: 4, pointHitRadius: 12 }] };
            options.interaction = { intersect: false, mode: 'index' };
            options.plugins.tooltip.callbacks = { title: function (items) { return hour.format(new Date(data.rows[items[0].dataIndex].at)) + ' UTC'; }, label: function (item) { return labels.posts + ': ' + number.format(item.parsed.y); } };
            if (!peak) options.scales.y.suggestedMax = 1;
        } else if (metric === 'languages') {
            var rows = data.rows;
            total = rows.reduce(function (sum, row) { return sum + row.count; }, 0);
            summary(card, [[total, labels.contents], [rows.length, labels.languages]]);
            var displayed = rows.slice(0, 8);
            if (rows.length > 8) {
                displayed.push(rows.slice(8).reduce(function (rest, row) { rest.count += row.count; return rest; }, { other: true, count: 0 }));
            }
            chartData = { labels: displayed.map(function (row) { return row.other ? labels.other : languageName(row.language); }), datasets: [{ label: labels.total, data: displayed.map(function (row) { return row.count; }), backgroundColor: colors.primary, borderRadius: 6, borderSkipped: false, maxBarThickness: 24 }] };
            options.indexAxis = 'y';
            options.scales.x.ticks.precision = 0;
            options.scales.x.grid = { color: colors.border, drawTicks: false };
            options.scales.y.grid.display = false;
            options.scales.y.ticks.autoSkip = false;
            options.scales.y.ticks.callback = function (value) { var label = this.getLabelForValue(value); return label.length > 25 ? label.slice(0, 24) + '…' : label; };
            options.plugins.tooltip.callbacks = { label: function (item) { return item.dataset.label + ': ' + number.format(item.parsed.x); } };
            if (!total) options.scales.x.suggestedMax = 1;
            accessibleDetails = rows.map(function (row) { return languageName(row.language) + ': ' + number.format(row.count); }).join('; ');
        } else {
            var result = metric === 'domains' ? domainChart(card, options, colors) : weeklyChart(card, options, colors);
            chartData = result.data;
            chartType = result.type;
            total = result.total;
        }
        card.querySelector('[data-stats-results]').hidden = false;
        var status = card.querySelector('[data-stats-status]');
        status.hidden = total > 0;
        status.style.minHeight = total > 0 ? '' : '0';
        status.textContent = labels.empty;
        var period = data.period;
        card.querySelector('[data-stats-period]').textContent = metric === 'domains' ? labels.snapshot + ' ' + date.format(new Date(period.to)) : date.format(new Date(period.from)) + ' – ' + (rhythm ? hour.format(new Date(period.to)) : date.format(new Date(period.to))) + ' UTC';
        card.querySelector('[data-stats-updated]').textContent = labels.generated + ' ' + hour.format(new Date(data.generated_at)) + ' UTC';
        canvas.setAttribute('aria-label', card.querySelector('h2').textContent + '. ' + Array.from(card.querySelector('[data-stats-summary]').children, function (item) { return Array.from(item.children, function (part) { return part.textContent; }).join(' '); }).join('. ') + (accessibleDetails ? '. ' + accessibleDetails : ''));
        if (card._statsChart) card._statsChart.destroy();
        card._statsChart = new Chart(canvas, { type: chartType, data: chartData, options: options });
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
            var url = card.dataset.url;
            if (!datasets.has(url)) {
                datasets.set(url, (async function () {
                    var response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
                    if (response.status === 401 || response.status === 419 || response.redirected) throw new Error('auth');
                    if (!response.ok) throw new Error('http');
                    var result = await response.json();
                    if (result.version !== 1 || result.metric !== (card.dataset.metric || card.dataset.statsCard) || !Array.isArray(result.rows) || !result.period) throw new Error('dataset');
                    return result;
                }()).catch(function (error) { datasets.delete(url); throw error; }));
            }
            var data = await datasets.get(url);
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
        card.querySelector('[data-stats-retry]').addEventListener('click', function () { datasets.delete(card.dataset.url); enqueue(card); });
        if (observer) observer.observe(card); else enqueue(card);
    });
}());
