/** Device timezone detection against the production layout, script and event views. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const requests = new Map();
const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname.startsWith('/assets/')) {
        const file = path.resolve(root, 'public', '.' + url.pathname);
        if (!file.startsWith(path.join(root, 'public/assets/')) || !fs.existsSync(file)) {
            response.writeHead(404).end();
            return;
        }
        response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'text/css');
        response.end(fs.readFileSync(file));
        return;
    }
    if (url.pathname !== '/timezone') {
        response.writeHead(404).end();
        return;
    }
    requests.set(url.search, (requests.get(url.search) || 0) + 1);
    const cookies = Object.fromEntries((request.headers.cookie || '').split(';').filter(Boolean).map(pair => {
        const [key, ...value] = pair.trim().split('=');
        return [key, decodeURIComponent(value.join('='))];
    }));
    try {
        const html = execFileSync('php', [path.join(root, 'tests/Browser/fixtures/timezones.php')], {
            cwd: root, encoding: 'utf8', env: {...process.env,
                OPENBOOK_BROWSER_COOKIES: JSON.stringify(cookies), OPENBOOK_BROWSER_METHOD: request.method,
                OPENBOOK_BROWSER_TIMEZONE: url.searchParams.get('preferred') || '',
                OPENBOOK_BROWSER_GUEST: url.searchParams.get('guest') || '',
                OPENBOOK_BROWSER_FORM: url.searchParams.get('form') || ''},
        });
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(html.replaceAll('http://openbook.test', `http://127.0.0.1:${server.address().port}`)
            .replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, script => script.includes('/assets/js/timezone.js') ? script : ''));
    } catch (error) {
        response.writeHead(500).end(String(error));
    }
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({headless: true, executablePath: process.env.BROWSER_EXECUTABLE_PATH});
    try {
        for (const guest of [false, true]) {
            const context = await browser.newContext({timezoneId: 'America/New_York', viewport: {width: 375, height: 900}});
            const page = await context.newPage();
            const query = `?guest=${guest ? 1 : 0}`;
            await page.goto(origin + '/timezone' + query);
            await page.waitForFunction(() => document.querySelector('#local-date')?.textContent.trim() === '2027-01-15 17:30');
            assert.equal(requests.get(query), 2, 'first automatic visit reloads once');
            const cookie = (await context.cookies()).find(cookie => cookie.name === 'openbook_timezone');
            assert.equal(decodeURIComponent(cookie.value), 'America/New_York');
            assert.equal(cookie.sameSite, 'Lax');
            assert.equal(cookie.httpOnly, false);
            await page.reload();
            assert.equal(requests.get(query), 3, 'unchanged device does not reload twice');
            assert.match(await page.locator('.ob-event-local-time').textContent(), /15 January 2027, 17:30 in your time zone/);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            if (!guest) await page.screenshot({path: path.join(os.tmpdir(), 'openbook-timezone-mobile.png'), fullPage: true});
            await context.close();
        }

        const context = await browser.newContext({timezoneId: 'America/New_York'});
        const page = await context.newPage();
        await page.goto(origin + '/timezone?preferred=Asia/Tokyo');
        assert.equal((await page.locator('#local-date').textContent()).trim(), '2027-01-16 07:30');
        assert.equal(requests.get('?preferred=Asia/Tokyo'), 1, 'explicit preference needs no reload');
        await page.goto(origin + '/timezone?preferred=Europe/Helsinki&form=1');
        assert.equal(await page.locator('#event-timezone').inputValue(), 'Europe/Helsinki');
        await context.addCookies([{name: 'openbook_timezone', value: 'Europe%2FRome', url: origin}]);
        await page.goto(origin + '/timezone?changed=1');
        await page.waitForFunction(() => document.querySelector('#local-date')?.textContent.trim() === '2027-01-15 17:30');
        assert.equal(requests.get('?changed=1'), 2, 'a changed device updates automatic mode');
        await page.screenshot({path: path.join(os.tmpdir(), 'openbook-timezone-desktop.png'), fullPage: true});
        await context.close();

        for (const mode of ['blocked', 'nojs', 'nointl']) {
            const context = await browser.newContext({timezoneId: 'America/New_York', javaScriptEnabled: mode !== 'nojs'});
            if (mode === 'blocked') await context.addInitScript(() => Object.defineProperty(Document.prototype, 'cookie', {get: () => '', set: () => {}}));
            if (mode === 'nointl') await context.addInitScript(() => { window.Intl = undefined; });
            const page = await context.newPage();
            await page.goto(origin + '/timezone?mode=' + mode);
            assert.equal((await page.locator('#local-date').textContent()).trim(), '2027-01-15 22:30');
            assert.equal(requests.get('?mode=' + mode), 1, 'fallback must not loop');
            await context.close();
        }
        const postContext = await browser.newContext({timezoneId: 'America/New_York'});
        const postPage = await postContext.newPage();
        await postPage.route('**/timezone?post=1', route => route.continue({method: 'POST'}));
        await postPage.goto(origin + '/timezone?post=1');
        assert.equal(requests.get('?post=1'), 1, 'a POST response must never reload');
        await postContext.close();
        process.stdout.write('PASS: automatic/explicit timezones, device changes, guest, event form, mobile, fallbacks and POST safety.\n');
    } finally {
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; server.close(); });
