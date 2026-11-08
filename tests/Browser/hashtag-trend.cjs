/** Production Chart.js and Blade layout, with a compact header sparkline. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const html = execFileSync('php', [path.join(__dirname, 'fixtures/hashtag-trend.php')], {cwd: root, encoding: 'utf8'});
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.startsWith('/assets/')) {
        const file = path.join(root, 'public', url.pathname);
        if (!fs.existsSync(file)) return res.writeHead(404).end();
        res.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'image/svg+xml');
        return res.end(fs.readFileSync(file));
    }
    if (url.pathname.includes('sidebar') || url.pathname.includes('notifiche')) return res.writeHead(200, {'Content-Type': 'application/json'}).end('{}');
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(html.replaceAll('http://openbook.test', `http://127.0.0.1:${server.address().port}`));
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({headless: true, ...(process.env.BROWSER_EXECUTABLE_PATH ? {executablePath: process.env.BROWSER_EXECUTABLE_PATH} : {})});
        for (const width of [1280, 768, 375]) {
            const page = await browser.newPage({viewport: {width, height: 900}});
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(`http://127.0.0.1:${server.address().port}`);
            await page.waitForFunction(() => window.Chart && Chart.getChart(document.querySelector('[data-hashtag-trend] canvas')));
            assert(await page.evaluate(() => Chart.getChart(document.querySelector('[data-hashtag-trend] canvas')).data.datasets[0].data.length === 30));
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow at ${width}px`);
            const box = await page.locator('[data-hashtag-trend] canvas').boundingBox();
            assert(box.height >= 50 && box.height <= 60);
            const trendBox = await page.locator('[data-hashtag-trend]').boundingBox();
            assert(box.width < trendBox.width / 2);
            const headerBox = await page.locator('.ob-hashtag-header').boundingBox();
            const feedBox = await page.locator('#ob-post-list').boundingBox();
            assert(feedBox.y - (headerBox.y + headerBox.height) >= 15);
            assert.equal(await page.locator('.ob-hashtag-header [data-hashtag-trend]').count(), 1);
            assert.equal(await page.locator('[data-hashtag-trend] table, [data-hashtag-trend] details').count(), 0);
            assert(await page.evaluate(() => {const chart = Chart.getChart(document.querySelector('[data-hashtag-trend] canvas')); return !chart.options.scales.x.display && !chart.options.scales.y.display && !chart.options.plugins.tooltip.enabled;}));
            await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
            if (process.env.OPENBOOK_HASHTAG_SCREENSHOTS) await page.screenshot({path: `/tmp/openbook-hashtag-trend-${width}.png`, fullPage: true});
            assert.deepEqual(errors, []);
            await page.close();
        }
        const noJs = await browser.newPage({javaScriptEnabled: false, viewport: {width: 375, height: 900}});
        await noJs.goto(`http://127.0.0.1:${server.address().port}`);
        assert.equal(await noJs.locator('[data-hashtag-trend]:visible').count(), 0);
        await noJs.close();
        console.log('PASS: compact header trend, no axes/tooltips/table, three widths, theme update and hidden without JavaScript.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
