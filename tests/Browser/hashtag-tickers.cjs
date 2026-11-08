/** Responsive trend rows with followed/unfollowed hashtags and no per-row scripts. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const documents = {};
for (const mode of ['auth', 'guest']) documents[mode] = execFileSync('php', ['tests/Browser/fixtures/hashtag-trend.php'], {cwd: root, encoding: 'utf8', env: {...process.env, OPENBOOK_HASHTAG_TICKERS: mode}});
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
    res.end(documents[url.pathname.includes('guest') ? 'guest' : 'auth'].replaceAll('http://openbook.test', `http://127.0.0.1:${server.address().port}`));
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({headless: true});
        for (const mode of ['auth', 'guest']) for (const width of [1280, 768, 375, 320]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(`http://127.0.0.1:${server.address().port}/${mode}`);
            assert.equal(await page.locator('.ob-hashtag-sparkline').count(), 8);
            const positions = await page.locator('.ob-hashtag-sparkline').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().x));
            assert(Math.max(...positions) - Math.min(...positions) < 1, `Ticker alignment ${mode}/${width}`);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow ${mode}/${width}`);
            if (mode === 'auth') {
                assert.equal(await page.locator('.ob-hashtag-follow-form .ob-btn--primary').count(), 4);
                assert.equal(await page.locator('.ob-hashtag-follow-form .ob-btn--ghost').count(), 4);
                const rows = await page.locator('.ob-hashtag-list--tickers li').evaluateAll(nodes => nodes.map(node => {
                    const boxes = [...node.children].map(child => child.getBoundingClientRect());
                    return boxes[1].left >= boxes[0].right && (innerWidth <= 600 || boxes[2].left >= boxes[1].right);
                }));
                assert(rows.every(Boolean), `Overlapping row ${width}`);
            }
            if (process.env.OPENBOOK_HASHTAG_SCREENSHOTS) await page.screenshot({path: `/tmp/openbook-hashtag-tickers-${mode}-${width}.png`, fullPage: true});
            await page.evaluate(() => document.documentElement.style.setProperty('--ob-color-primary', '#9955ee'));
            assert.equal(await page.locator('.ob-hashtag-sparkline').first().evaluate(node => getComputedStyle(node).color), 'rgb(153, 85, 238)');
            assert.deepEqual(errors, []);
            await page.close();
        }
        const noJs = await browser.newPage({javaScriptEnabled: false});
        await noJs.goto(`http://127.0.0.1:${server.address().port}/guest`);
        assert.equal(await noJs.locator('.ob-hashtag-sparkline:visible').count(), 8);
        console.log('PASS: SVG tickers, stable follow/unfollow columns, long names, four widths, guest/auth and no JavaScript.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
