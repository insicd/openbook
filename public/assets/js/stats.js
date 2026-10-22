(function () {
    'use strict';
    var root = document.querySelector('[data-stats]');
    if (!root) return;

    var labels = JSON.parse(root.dataset.labels);
    var locale = root.dataset.locale;
    var number = new Intl.NumberFormat(locale);
    var date = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
    var hour = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'UTC', hourCycle: 'h23' });
    var monthDate = new Intl.DateTimeFormat(locale, { month: 'short', year: 'numeric', timeZone: 'UTC' });
    var generatedDate = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: root.dataset.timezone, timeZoneName: 'short', hourCycle: 'h23' });
    var names = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames([locale], { type: 'language' }) : null;
    var countryNames = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames([locale], { type: 'region' }) : null;
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
            font: style.fontFamily
        };
    }

    function summary(card, items) {
        var container = card.querySelector('[data-stats-summary]');
        container.replaceChildren();
        items.forEach(function (item) {
            var cell = document.createElement('div');
            cell.append(text('strong', typeof item[0] === 'string' ? item[0] : number.format(item[0]), 'ob-stats-summary__value'), text('span', item[1], 'ob-stats-summary__label'));
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

    function domainChart(card, options, colors) {
        var rows = card._statsData.rows;
        var total = rows.reduce(function (sum, row) { return sum + row.count; }, 0);
        var local = rows.reduce(function (sum, row) { return sum + row.local_count; }, 0);
        summary(card, [[total, labels.known_actors], [rows.length, labels.domains], [local, labels.local_actors]]);
        var displayed = rows.slice(0, 10);
        horizontal(options);
        options.plugins.tooltip.callbacks = { label: function (item) { return labels.known_actors + ': ' + number.format(item.parsed.x) + ' (' + number.format(total ? item.parsed.x / total * 100 : 0) + '%)'; } };
        if (!total) options.scales.x.suggestedMax = 1;
        return { total: total, type: 'bar', data: {
            labels: displayed.map(function (row) { return row.domain; }),
            datasets: [{ label: labels.known_actors, data: displayed.map(function (row) { return row.count; }), backgroundColor: colors.primary, borderRadius: 6, borderSkipped: false, maxBarThickness: 24 }]
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

    function geographyChart(card, options, colors) {
        var data = card._statsData;
        var totals = data.totals;
        var rows = data.rows;
        var known = rows.filter(function (row) { return row.country !== 'OTHER'; })
            .sort(function (a, b) { return b.count - a.count || a.country.localeCompare(b.country); });
        var other = rows.filter(function (row) { return row.country === 'OTHER'; }).reduce(function (sum, row) { return sum + row.count; }, 0)
            + known.slice(10).reduce(function (sum, row) { return sum + row.count; }, 0);
        var displayed = known.slice(0, 10);
        if (other) displayed.push({ country: 'OTHER', count: other });
        var percent = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 1 });
        summary(card, [[totals.located, labels.located_contents], [totals.contents ? percent.format(totals.located / totals.contents) : '—', labels.location_coverage]]);
        horizontal(options);
        options.plugins.tooltip.callbacks = { label: function (item) { return labels.contents_count + ': ' + number.format(item.parsed.x); } };
        if (!totals.located) options.scales.x.suggestedMax = 1;
        function countryName(code) {
            if (code === 'OTHER') return labels.other_countries;
            try { return countryNames ? countryNames.of(code) : code; } catch (error) { return code; }
        }
        return { total: totals.located, type: 'bar', data: {
            labels: displayed.map(function (row) { return countryName(row.country); }),
            datasets: [{ label: labels.contents_count, data: displayed.map(function (row) { return row.count; }), backgroundColor: colors.primary, borderRadius: 6, borderSkipped: false, maxBarThickness: 24 }]
        } };
    }

    function eventCalendarChart(card, options, colors) {
        var rows = card._statsData.rows;
        var total = rows.reduce(function (sum, row) { return sum + row.count; }, 0);
        summary(card, [[total, labels.public_events]]);
        options.plugins.tooltip.callbacks = { label: function (item) { return labels.events + ': ' + number.format(item.parsed.y); } };
        if (!total) options.scales.y.suggestedMax = 1;
        return { total: total, type: 'bar', data: {
            labels: rows.map(function (row) { return monthDate.format(new Date(row.month + '-01T00:00:00Z')); }),
            datasets: [{ label: labels.events, data: rows.map(function (row) { return row.count; }), backgroundColor: colors.primary, borderRadius: 6, borderSkipped: false, maxBarThickness: 32 }]
        } };
    }

    function communityActivityChart(card, options, colors) {
        var rows = card._statsData.rows;
        var peak = Math.max.apply(null, rows.map(function (row) { return row.active; }).concat([0]));
        summary(card, [[peak, labels.peak_communities], [rows.reduce(function (sum, row) { return sum + row.count; }, 0), labels.community_posts]]);
        options.interaction = { intersect: false, mode: 'index' };
        options.plugins.tooltip.callbacks = {
            title: function (items) { return labels.week_of + ' ' + date.format(new Date(rows[items[0].dataIndex].week + 'T00:00:00Z')); },
            label: function (item) { return labels.active_communities + ': ' + number.format(item.parsed.y); },
            afterLabel: function (item) { return labels.posts + ': ' + number.format(rows[item.dataIndex].count); }
        };
        if (!peak) options.scales.y.suggestedMax = 1;
        return { total: peak, type: 'line', data: {
            labels: rows.map(function (row) { return date.format(new Date(row.week + 'T00:00:00Z')); }),
            datasets: [{ label: labels.active_communities, data: rows.map(function (row) { return row.active; }), borderColor: colors.primary, backgroundColor: colors.primary, borderWidth: 2.5, tension: 0.3, cubicInterpolationMode: 'monotone', pointRadius: 0, pointHoverRadius: 4, pointHitRadius: 10 }]
        } };
    }

    function focusHashtag(chart, index) {
        if (chart._statsFocus === index) return;
        chart._statsFocus = index;
        chart.data.datasets.forEach(function (dataset, i) {
            dataset.borderColor = index === null || index === i ? dataset.statsColor : Chart.helpers.color(dataset.statsColor).alpha(0.12).rgbString();
            dataset.backgroundColor = dataset.borderColor;
            dataset.pointBorderWidth = index === i ? 3 : 1;
            dataset.borderWidth = index === i ? 4 : (index === null ? 2 : 1);
            dataset.order = index === i ? -1 : 0;
        });
        chart.update('none');
    }

    function hashtagLegend(card) {
        var chart = card._statsChart;
        var legend = card.querySelector('[data-stats-legend]');
        legend.replaceChildren();
        card._statsData.rows.forEach(function (tag, index) {
            if (!tag.legend) return;
            var button = text('button', '#' + tag.name);
            button.type = 'button';
            button.style.setProperty('--ob-stats-series-color', chart.data.datasets[index].statsColor);
            button.addEventListener('mouseenter', function () { focusHashtag(chart, index); });
            button.addEventListener('mouseleave', function () { if (document.activeElement !== button) focusHashtag(chart, null); });
            button.addEventListener('focus', function () { focusHashtag(chart, index); });
            button.addEventListener('blur', function () { focusHashtag(chart, null); });
            button.addEventListener('click', function () { focusHashtag(chart, index); });
            legend.append(button);
        });
    }

    function hashtagChart(card, options, colors) {
        var data = card._statsData;
        summary(card, [[data.rows.length, labels.compared_hashtags]]);
        options.plugins.legend.display = false;
        options.interaction = { intersect: false, mode: 'nearest', axis: 'xy' };
        options.scales.y.ticks.precision = 0;
        options.onHover = function (event, elements, chart) { focusHashtag(chart, elements.length ? elements[0].datasetIndex : null); };
        options.plugins.tooltip.callbacks = {
            title: function (items) { return monthDate.format(new Date(data.months[items[0].dataIndex].month + '-01T00:00:00Z')); },
            label: function (item) { return item.dataset.label + ': ' + number.format(item.parsed.y); }
        };
        return { total: data.rows.length, type: 'line', data: {
            labels: data.months.map(function (month) { return monthDate.format(new Date(month.month + '-01T00:00:00Z')); }),
            datasets: data.rows.map(function (tag, index) {
                var color = Chart.helpers.color(colors.primary).rotate(index * 137.508).rgbString();
                return {
                    label: '#' + tag.name, statsColor: color,
                    data: data.months.map(function (month) { return month.counts[index]; }),
                    borderColor: color, backgroundColor: color, borderWidth: 2,
                    tension: 0.3, cubicInterpolationMode: 'monotone',
                    spanGaps: false, pointStyle: 'line',
                    pointRadius: function (context) {
                        var values = context.dataset.data;
                        var i = context.dataIndex;
                        return values[i] !== null && (i === 0 || values[i - 1] === null) && (i === values.length - 1 || values[i + 1] === null) ? 5 : 0;
                    },
                    pointHoverRadius: 5, pointHitRadius: 8
                };
            })
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
            var displayed = rows.slice(0, 10);
            if (rows.length > 10) {
                displayed.push(rows.slice(10).reduce(function (rest, row) { rest.count += row.count; return rest; }, { other: true, count: 0 }));
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
            var renderer = { domains: domainChart, volume: weeklyChart, authors: weeklyChart, hashtags: hashtagChart, geography: geographyChart, eventCalendar: eventCalendarChart, communityActivity: communityActivityChart }[metric];
            var result = renderer(card, options, colors);
            chartData = result.data;
            chartType = result.type;
            total = result.total;
        }
        card.querySelector('[data-stats-results]').hidden = false;
        var status = card.querySelector('[data-stats-status]');
        status.hidden = total > 0;
        status.style.minHeight = total > 0 ? '' : '0';
        status.textContent = { hashtags: labels.empty_hashtags, geography: labels.empty_geography, eventCalendar: labels.empty_events, communityActivity: labels.empty_communities }[metric] || labels.empty;
        var period = data.period;
        card.querySelector('[data-stats-period]').textContent = metric === 'domains' ? labels.snapshot + ' ' + date.format(new Date(period.to)) : date.format(new Date(period.from)) + ' – ' + (rhythm ? hour.format(new Date(period.to)) : date.format(new Date(period.to))) + ' UTC';
        card.querySelector('[data-stats-updated]').textContent = labels.generated + ' ' + generatedDate.format(new Date(data.generated_at));
        canvas.setAttribute('aria-label', card.querySelector('h2').textContent + '. ' + Array.from(card.querySelector('[data-stats-summary]').children, function (item) { return Array.from(item.children, function (part) { return part.textContent; }).join(' '); }).join('. ') + (accessibleDetails ? '. ' + accessibleDetails : ''));
        if (card._statsChart) card._statsChart.destroy();
        card._statsChart = new Chart(canvas, { type: chartType, data: chartData, options: options });
        if (metric === 'hashtags') {
            hashtagLegend(card);
            canvas.onmouseleave = function () { focusHashtag(card._statsChart, null); };
        }
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
