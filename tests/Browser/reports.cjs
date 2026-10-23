/** Visual checks against the production report views and CSS, with isolated SQLite data. */
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const playwright = require('playwright');
const root = path.resolve(__dirname, '../..');
const pages = Object.fromEntries(['list', 'detail', 'missing', 'local', 'profile', 'profile-it'].map(mode => [mode,
    execFileSync('php', [path.join(__dirname, 'fixtures/reports.php'), mode === 'profile-it' ? 'profile' : mode, mode === 'profile-it' ? 'it' : 'en'], { cwd: root, encoding: 'utf8' }),
]));
const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (['/assets/css/app.css', '/assets/js/report-moderation.js'].includes(pathname)) {
        response.setHeader('Content-Type', pathname.endsWith('.css') ? 'text/css' : 'text/javascript');
        response.end(fs.readFileSync(path.join(root, 'public', pathname)));
        return;
    }
    const html = pages[pathname.slice(1)];
    if (!html) { response.writeHead(204).end(); return; }
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(html.replaceAll('href="http://openbook.test', 'href="').replaceAll('href="https://openbook.test', 'href="').replaceAll('src="http://openbook.test', 'src="').replaceAll('src="https://openbook.test', 'src="'));
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        const options = { headless: true };
        if (process.env.BROWSER_EXECUTABLE_PATH) options.executablePath = process.env.BROWSER_EXECUTABLE_PATH;
        browser = await playwright[process.env.BROWSER_TYPE || 'chromium'].launch(options);
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const width of [1280, 1024, 768, 375]) {
            await page.setViewportSize({ width, height: 900 });
            for (const mode of Object.keys(pages)) {
                await page.goto(`http://127.0.0.1:${server.address().port}/${mode}`);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${mode}: horizontal overflow at ${width}`);
                const text = await page.locator('main').innerText();
                assert.equal(text.includes('openbook.admin.reports.'), false, `${mode}: unresolved translation at ${width}`);
                if (mode === 'detail') {
                    assert.equal(await page.locator('[data-report-target]').count(), 5);
                    assert.match(text, /Sending Actor/);
                    assert.match(text, /Content access is restricted/);
                    assert.equal(await page.locator('input[name="targets[]"]').count(), 2);
                    const submit = page.locator('[data-report-submit]');
                    assert.equal(await submit.innerText(), 'Close as handled');
                    await page.locator('[data-report-target="post"] input').check();
                    assert.equal(await submit.innerText(), 'Delete selected and close');
                    assert.match(await page.locator('[data-report-selection]').innerText(), /Selected items: 1/);
                    assert.equal(await submit.evaluate(node => node.classList.contains('ob-report-destructive')), true);
                    const selected = await page.locator('#report-action').evaluate(form => new FormData(form).getAll('targets[]'));
                    assert.equal(selected.length, 1, 'Selection outside the form must be submitted through its form attribute');
                    assert.match(text, /User management/);
                    await page.locator('[data-report-target="post"] input').uncheck();
                    assert.equal(await submit.innerText(), 'Close as handled');
                    const review = page.locator('button[aria-describedby="report-review-help"]');
                    await review.focus();
                    assert.match(await page.locator('#report-review-help').innerText(), /case remains open/);
                    assert.match(await review.getAttribute('title'), /case remains open/);
                    await page.locator('[data-report-target="post"] input').check();
                }
                if (mode === 'profile' || mode === 'profile-it') {
                    assert.equal(await page.locator('[data-report-delete]').count(), 0);
                    assert.equal(await page.locator('[data-report-submit]').innerText(), mode === 'profile-it' ? 'Chiudi come gestita' : 'Close as handled');
                    const links = page.locator('.ob-report-target-actions a');
                    assert.equal(await links.count(), 2);
                    if (width === 1280) {
                        const boxes = await links.evaluateAll(nodes => nodes.map(n => n.getBoundingClientRect().top));
                        assert.equal(boxes[0], boxes[1], 'Profile navigation links should share a row');
                    }
                }
                if (mode === 'missing') assert.match(text, /Already handled positively/);
                if (mode === 'list') { assert.match(text, /Federated/); assert.match(text, /Local/); }
                if (process.env.OPENBOOK_REPORT_SCREENSHOTS) {
                    fs.mkdirSync(process.env.OPENBOOK_REPORT_SCREENSHOTS, { recursive: true });
                    await page.screenshot({ path: path.join(process.env.OPENBOOK_REPORT_SCREENSHOTS, `${mode}-${width}.png`), fullPage: true });
                }
            }
        }
        const noJs = await browser.newContext({ javaScriptEnabled: false });
        const fallback = await noJs.newPage();
        await fallback.goto(`http://127.0.0.1:${server.address().port}/profile`);
        assert.equal(await fallback.locator('[data-report-submit]').innerText(), 'Close as handled');
        await fallback.goto(`http://127.0.0.1:${server.address().port}/local`);
        assert.equal(await fallback.locator('[data-report-submit]').innerText(), 'Apply selections and close');
        assert.equal(await fallback.locator('input[name="delete_post"]').count(), 1);
        await noJs.close();
        assert.deepEqual(errors, []);
        console.log('Reports: local/federated list and details, automatic closure, restricted targets and desktop/mobile layout passed.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
