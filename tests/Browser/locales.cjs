/** Native language menu against the production layout and CSS; isolated SQLite. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const locale = process.env.OPENBOOK_BROWSER_LOCALE || 'en';
const fixtures = JSON.parse(execFileSync('php', [path.join(__dirname, 'fixtures/locales.php')], {cwd: root, encoding: 'utf8'}));
let submission;
const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname.startsWith('/assets/')) {
        response.setHeader('Content-Type', 'text/css');
        response.end(fs.readFileSync(path.join(root, 'public/assets/css/app.css')));
        return;
    }
    if (request.method === 'POST') {
        let body = '';
        request.on('data', chunk => { body += chunk; });
        request.on('end', () => {
            submission = new URLSearchParams(body);
            response.writeHead(303, {Location: '/guest'}).end();
        });
        return;
    }
    const origin = `http://127.0.0.1:${server.address().port}`;
    // Other page scripts are outside this test; keep the production inline
    // navigation handler so the authenticated mobile menu works normally.
    const html = fixtures[url.pathname === '/account' ? 'account' : 'guest']
        .replaceAll('http://openbook.test', origin)
        .replace(/<script\b[^>]*\bsrc=[^>]*>[\s\S]*?<\/script>/gi, '');
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(html);
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({headless: true, ...(process.env.BROWSER_EXECUTABLE_PATH ? {executablePath: process.env.BROWSER_EXECUTABLE_PATH} : {})});
        const origin = `http://127.0.0.1:${server.address().port}`;
        for (const width of [1280, 375]) {
            const context = await browser.newContext({viewport: {width, height: 812}});
            const page = await context.newPage();
            for (const kind of ['guest', 'account']) {
                await page.goto(`${origin}/${kind}`);
                assert.equal(await page.locator('html').getAttribute('lang'), locale);
                if (kind === 'account' && width === 375) await page.locator('#ob-nav-toggle').click();
                const summary = page.locator('.ob-language-selector > summary');
                await summary.focus();
                await page.keyboard.press('Enter');
                await page.locator('.ob-language-selector__menu button[value="it"]').waitFor({state: 'visible'});
                const box = await page.locator('.ob-language-selector__menu').boundingBox();
                assert(box && box.x >= 0 && box.x + box.width <= width, `${kind} menu must fit at ${width}px`);
                assert.equal(await page.locator('.ob-language-selector__menu button[aria-current="true"]').getAttribute('value'), locale);
                await page.keyboard.press('Tab');
                assert.equal(await page.locator(':focus').getAttribute('value'), 'it');
                submission = undefined;
                await Promise.all([page.waitForNavigation({waitUntil: 'load'}), page.keyboard.press('Enter')]);
                assert.equal(submission.get('locale'), 'it');
                assert(submission.has('_token'));
                assert(submission.get('return_to').startsWith('/'));
            }
            await context.close();
        }
        const noJs = await browser.newContext({javaScriptEnabled: false, viewport: {width: 375, height: 812}});
        const page = await noJs.newPage();
        await page.goto(`${origin}/guest`);
        await page.locator('.ob-language-selector > summary').click();
        await page.locator('.ob-language-selector__menu button[value="it"]').waitFor({state: 'visible'});
        await noJs.close();
        console.log('PASS: language menu on desktop/mobile, keyboard, submission and guest without JavaScript.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
