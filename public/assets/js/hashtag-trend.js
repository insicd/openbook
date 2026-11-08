(function () {
    'use strict';
    var root = document.querySelector('[data-hashtag-trend]');
    if (!root || typeof Chart === 'undefined') return;
    var rows = JSON.parse(root.dataset.rows);
    var container = root.querySelector('[data-hashtag-trend-canvas]');
    var canvas = container.querySelector('canvas');
    function colors() {
        var style = getComputedStyle(root);
        return {primary: style.getPropertyValue('--ob-color-primary').trim()};
    }
    var palette = colors();
    function areaFill(context) {
        var area = context.chart.chartArea;
        if (!area) return 'transparent';
        var gradient = context.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
        gradient.addColorStop(0, palette.primary + '30');
        gradient.addColorStop(1, palette.primary + '00');
        return gradient;
    }
    root.hidden = false;
    var chart;
    try {
        chart = new Chart(canvas, {
            type: 'line',
            data: {labels: rows.map(function (row) {return row.day;}),
                datasets: [{data: rows.map(function (row) {return row.count;}),
                    borderColor: palette.primary, backgroundColor: areaFill, borderWidth: 2, fill: true,
                    pointBackgroundColor: palette.primary, pointBorderWidth: 0,
                    pointRadius: function (context) {return context.dataIndex === rows.length - 1 ? 3 : 0;}, tension: 0}]},
            options: {responsive: true, maintainAspectRatio: false, animation: false, events: [],
                layout: {padding: {top: 6, right: 5, bottom: 4, left: 2}},
                plugins: {legend: {display: false}, tooltip: {enabled: false}},
                scales: {x: {display: false}, y: {display: false, beginAtZero: true}}}
        });
    } catch (error) {
        root.hidden = true;
        return;
    }
    new MutationObserver(function () {
        palette = colors();
        chart.data.datasets[0].borderColor = palette.primary;
        chart.data.datasets[0].pointBackgroundColor = palette.primary;
        chart.update('none');
    }).observe(document.documentElement, {attributes: true, attributeFilter: ['data-theme', 'class']});
})();
