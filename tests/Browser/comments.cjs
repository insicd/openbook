/** Comment editing with production views, styles and scripts; isolated SQLite. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const fixtures = JSON.parse(execFileSync('php', [path.join(__dirname, 'fixtures/comments.php')], {cwd: root, encoding: 'utf8'}));
let saved;
const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname.startsWith('/assets/')) {
        const file = path.join(root, 'public', url.pathname);
        if (!fs.existsSync(file)) return response.writeHead(404).end();
        response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'image/svg+xml');
        return response.end(fs.readFileSync(file));
    }
    if (request.method === 'POST') {
        let body = '';
        request.on('data', chunk => {body += chunk;});
        request.on('end', () => {
            saved = body;
            response.writeHead(303, {Location: '/thread'}).end();
        });
        return;
    }
    if (url.pathname.startsWith('/notifiche') || url.pathname.includes('/notifications')) {
        return response.writeHead(200, {'Content-Type': 'application/json'}).end('{}');
    }
    const origin = `http://127.0.0.1:${server.address().port}`;
    const mode = url.pathname === '/edit' || url.pathname.endsWith('/modifica') ? 'edit' : 'thread';
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(fixtures[mode].replaceAll('http://openbook.test', origin));
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({headless: true, ...(process.env.BROWSER_EXECUTABLE_PATH ? {executablePath: process.env.BROWSER_EXECUTABLE_PATH} : {})});
        const origin = `http://127.0.0.1:${server.address().port}`;
        for (const width of [1280, 375]) {
            for (const javaScriptEnabled of [true, false]) {
                const context = await browser.newContext({viewport: {width, height: 900}, javaScriptEnabled});
                const page = await context.newPage();
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.goto(`${origin}/thread`);
                const menu = page.locator('.ob-comment .ob-post__menu');
                await menu.locator('summary').click();
                const edit = menu.getByRole('menuitem', {name: 'Modifica', exact: true});
                await edit.waitFor({state: 'visible'});
                const labels = await menu.getByRole('menuitem').allTextContents();
                assert(labels.findIndex(x => x.includes('Modifica')) < labels.findIndex(x => x.includes('Elimina')));
                await edit.click();
                await page.getByRole('heading', {name: 'Modifica commento'}).waitFor();
                const text = page.locator('[data-composer-mode="comment"] textarea[name="body"]');
                assert.equal(await text.inputValue(), 'Il testo del mio commento da modificare.');
                assert.equal(await page.locator('[data-composer-mode="comment"] input[type="file"]').count(), 0);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow at ${width}px`);
                if (process.env.OPENBOOK_COMMENT_SCREENSHOTS && javaScriptEnabled) {
                    await page.screenshot({path: `/tmp/openbook-comment-edit-${width}.png`, fullPage: true});
                }
                await page.getByRole('link', {name: 'Annulla', exact: true}).click();
                await page.locator('.ob-comment').waitFor();
                await page.goto(`${origin}/edit`);
                await text.fill('Testo corretto dal browser.');
                saved = undefined;
                await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Salva', exact: true}).click()]);
                assert(saved.includes('Testo corretto dal browser.'));
                assert(saved.includes('PUT'));
                assert(saved.includes('_token'));
                assert.deepEqual(errors, []);
                await context.close();
            }
        }
        console.log('PASS: comment menu, editing, cancel, save, desktop/mobile and no-JavaScript fallback.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
