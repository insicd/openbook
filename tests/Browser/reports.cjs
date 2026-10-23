/** Visual checks against the production report views and CSS, with isolated SQLite data. */
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const playwright = require('playwright');
const root = path.resolve(__dirname, '../..');
const pages = Object.fromEntries(['list', 'detail', 'missing', 'local'].map(mode => [mode,
    execFileSync('php', [path.join(__dirname, 'fixtures/reports.php'), mode], { cwd: root, encoding: 'utf8' }),
]));
const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname === '/assets/css/app.css') {
        response.setHeader('Content-Type', 'text/css');
        response.end(fs.readFileSync(path.join(root, 'public/assets/css/app.css')));
        return;
    }
    const html = pages[pathname.slice(1)];
    if (!html) { response.writeHead(204).end(); return; }
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(html.replaceAll('href="http://openbook.test', 'href="').replaceAll('href="https://openbook.test', 'href="'));
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
        for (const width of [1280, 375]) {
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
                }
                if (mode === 'missing') assert.match(text, /Already handled positively/);
                if (mode === 'list') { assert.match(text, /Federated/); assert.match(text, /Local/); }
                if (process.env.OPENBOOK_REPORT_SCREENSHOTS) {
                    fs.mkdirSync(process.env.OPENBOOK_REPORT_SCREENSHOTS, { recursive: true });
                    await page.screenshot({ path: path.join(process.env.OPENBOOK_REPORT_SCREENSHOTS, `${mode}-${width}.png`), fullPage: true });
                }
            }
        }
        assert.deepEqual(errors, []);
        console.log('Reports: local/federated list and details, automatic closure, restricted targets and desktop/mobile layout passed.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
