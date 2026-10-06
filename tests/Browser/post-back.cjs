/**
 * Browser checks using the production JS/CSS and a local POST/redirect fixture.
 * Run: node tests/Browser/post-back.cjs (requires Playwright and its browser).
 * Optional: BROWSER_TYPE=webkit, BROWSER_EXECUTABLE_PATH=/path/to/browser.
 */
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const playwright = require('playwright');
const root = path.resolve(__dirname, '../..');
let comments = 0;

const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/post-back.js' || url.pathname === '/app.css') {
        const file = url.pathname === '/app.css' ? 'css/app.css' : 'js/post-back.js';
        response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
        response.end(fs.readFileSync(path.join(root, 'public/assets', file)));
        return;
    }
    if (request.method === 'POST') {
        // Laravel uses a 302 redirect for both successful comments and validation errors.
        request.resume();
        response.writeHead(302, { Location: url.searchParams.has('error')
            ? '/posts/one?error=1' : '/posts/one#commento-' + (++comments) });
        response.end();
        return;
    }
    const post = url.pathname === '/posts/one';
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(`<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="/app.css"></head><body>
        <header class="ob-header"><div class="ob-header__inner">
        <div class="ob-header__start"><button class="ob-icon-btn" aria-label="Menu">☰</button>
        <a href="/home" class="ob-brand">Punk Kitchen</a></div>
        <div class="ob-header__end"><button class="ob-icon-btn" aria-label="Notifiche">♧</button>
        <button class="ob-icon-btn" aria-label="Opzioni">•••</button></div></div>
        ${post ? `<div class="ob-post-back" data-post-back hidden><button class="ob-post-back__button" data-post-back-button>
        <svg class="ob-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 12H4m6-6-6 6 6 6"/></svg><span>Indietro</span></button></div>` : ''}</header>
        <main class="ob-shell">${post ? `<article class="ob-card"><h1>Post</h1>
        <a id="anchor" href="#commenti">Commenti</a></article>
        <section class="ob-card" id="commenti"><form method="POST" action="/posts/one/commenti">
        <textarea name="body" required></textarea><button id="submit">Pubblica commento</button></form>
        <form method="POST" action="/posts/one/commenti?error=1"><button id="error">Errore server</button></form>
        <form id="ajax" method="POST" action="/posts/one/mi-piace"><button id="like">Like AJAX</button></form>
        <p>${url.searchParams.has('error') ? 'Errore di validazione' : 'Commenti'}</p></section>`
        : `<h1>Lista</h1>
        <article class="ob-card ob-post"><div class="ob-post__header ob-post__header--linked">
        <a id="header-link" class="ob-post__header-link" href="/posts/one" aria-label="Apri post"></a>
        <div class="ob-avatar">A</div><div class="ob-post__meta">
        <a id="author" class="ob-post__author" href="/author">Autore</a>
        <div class="ob-post__handle">@autore@example.test</div>
        <div class="ob-post__time"><a id="time" href="/timestamp">Data</a></div></div>
        <details class="ob-post__menu"><summary id="menu" class="ob-icon-btn">•••</summary>
        <div class="ob-post__menu-panel"><a id="original" class="ob-post__menu-item" href="/original">Apri originale</a></div></details>
        </div><p>Corpo del post</p></article><div style="height:1200px"></div><a id="post" href="/posts/one#commenta">Apri post</a>
        <a id="new-tab" href="/posts/one" target="_blank">Nuova scheda</a>`}</main>
        <script>document.addEventListener('submit', function(e) { if(e.target.id === 'ajax') e.preventDefault(); });</script>
        <script src="/post-back.js" defer></script></body></html>`);
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await playwright[process.env.BROWSER_TYPE || 'chromium'].launch({
            headless: true, timeout: 10000, executablePath: process.env.BROWSER_EXECUTABLE_PATH,
        });
        const base = `http://127.0.0.1:${server.address().port}`;
        const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const page = await context.newPage();
        const depth = () => page.evaluate(() => history.state?.obPostBack?.depth || 0);
        const openPost = async () => {
            await page.goto(base + '/home');
            await page.click('#post');
            await page.locator('[data-post-back-button]').waitFor({ state: 'visible' });
            assert.equal(await depth(), 1);
        };
        const submit = async (selector) => {
            await Promise.all([page.waitForNavigation(), page.click(selector)]);
        };
        const backToList = async () => {
            await page.click('[data-post-back-button]');
            await page.waitForURL(base + '/home');
            assert.equal(await page.evaluate(() => sessionStorage.getItem('ob.postBack')), null);
        };

        await page.goto(base + '/home');
        const header = page.locator('.ob-post__header');
        const bounds = await header.boundingBox();
        await page.mouse.click(bounds.x + bounds.width - 80, bounds.y + 5);
        await page.waitForURL(base + '/posts/one');
        await page.goBack();
        await page.click('#author');
        await page.waitForURL(base + '/author');
        await page.goBack();
        await page.click('#time');
        await page.waitForURL(base + '/timestamp');
        await page.goBack();
        await page.click('#menu');
        assert.equal(await page.locator('.ob-post__menu').evaluate(el => el.open), true);
        await page.click('#original');
        await page.waitForURL(base + '/original');
        await page.goto(base + '/home');
        await page.locator('#header-link').focus();
        await page.keyboard.press('Enter');
        await page.waitForURL(base + '/posts/one');
        assert.equal(await depth(), 1, 'header link also starts back context');

        await page.goto(base + '/posts/one');
        assert.equal(await page.locator('[data-post-back]').isVisible(), false, 'direct access');
        await openPost();
        await page.setViewportSize({ width: 767, height: 844 });
        assert.equal(await page.locator('[data-post-back]').isVisible(), true);
        await page.setViewportSize({ width: 768, height: 844 });
        assert.equal(await page.locator('[data-post-back]').isVisible(), false);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok(await page.locator('[data-post-back-button]').evaluate(el => el.getBoundingClientRect().height >= 44));
        await page.click('#submit'); // Native required validation does not submit.
        assert.equal(await depth(), 1);
        await page.click('#like'); // Existing AJAX interception must not become a pending navigation.
        assert.equal(await page.evaluate(() => JSON.parse(sessionStorage.getItem('ob.postBack')).pending), false);
        await page.fill('textarea', 'Primo commento');
        await submit('#submit');
        assert.equal(await depth(), 2);
        await page.fill('textarea', 'Secondo commento');
        await submit('#submit');
        assert.equal(await depth(), 3);
        await page.reload();
        assert.equal(await depth(), 3, 'refresh does not increment');
        await backToList();

        await openPost();
        await submit('#error');
        assert.equal(await depth(), 2, 'validation redirect still adds an entry');
        await backToList();

        await openPost();
        await page.fill('textarea', 'Commento');
        await submit('#submit');
        await page.goBack();
        assert.equal(await depth(), 1, 'native back restores entry depth');
        await page.fill('textarea', 'Sostituisce il ramo avanti');
        await submit('#submit');
        assert.equal(await depth(), 2, 'forward history truncation');
        await backToList();

        await openPost();
        await page.click('.ob-brand');
        await page.waitForURL(base + '/home');
        assert.equal(await page.evaluate(() => sessionStorage.getItem('ob.postBack')), null);
        await page.click('#post');
        assert.equal(await depth(), 1, 'reopening the same post');
        await page.evaluate(() => {
            document.querySelector('form').addEventListener('submit', e => e.preventDefault());
        });
        await page.fill('textarea', 'Submit annullato');
        await page.click('#submit');
        await page.reload();
        assert.equal(await depth(), 1, 'cancelled submit and refresh');
        await backToList();

        await openPost();
        await page.click('#anchor');
        await page.waitForURL(/#commenti$/);
        assert.equal(await depth(), 2, 'anchor adds a step');
        await page.goBack();
        assert.equal(await depth(), 1, 'native back across an anchor');
        await page.click('#anchor');
        await page.waitForURL(/#commenti$/);
        await backToList();

        const external = await context.newPage();
        await external.goto(base.replace('127.0.0.1', 'localhost') + '/home');
        await external.evaluate(target => location.href = target, base + '/posts/one');
        await external.waitForURL(base + '/posts/one');
        assert.equal(await external.locator('[data-post-back]').isVisible(), false, 'external referrer');
        await external.close();

        const popupPromise = page.waitForEvent('popup');
        await page.click('#new-tab');
        const popup = await popupPromise;
        await popup.waitForLoadState();
        assert.equal(await popup.locator('[data-post-back]').isVisible(), false, 'new tab has no back entry');
        await popup.close();

        const noStorage = await context.newPage();
        await noStorage.addInitScript(() => {
            Object.defineProperty(window, 'sessionStorage', { get() { throw new Error('storage denied'); } });
        });
        await noStorage.goto(base + '/home');
        await noStorage.click('#post');
        await noStorage.locator('[data-post-back-button]').waitFor();
        await noStorage.click('[data-post-back-button]');
        await noStorage.waitForURL(base + '/home');
        await noStorage.close();

        await openPost();
        if (process.env.SCREENSHOT_PATH) await page.screenshot({ path: process.env.SCREENSHOT_PATH });
        console.log('PASS: header background, author/time/menu links, keyboard, comments, validation, refresh, native back, anchors, reset, breakpoint, direct/external access, new tab and denied storage');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
