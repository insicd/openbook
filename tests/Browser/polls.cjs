/** Browser checks against production Blade, JS and CSS; no application DB. */
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const playwright = require('playwright');
const root = path.resolve(__dirname, '../..');
const fixture = JSON.parse(execFileSync('php', [path.join(__dirname, 'fixtures/polls.php')], {cwd: root, encoding: 'utf8'}));
let votes = 0;
const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname.startsWith('/assets/')) {
        const file = {'/assets/app.css': 'css/app.css', '/assets/polls.js': 'js/polls.js', '/assets/composer.js': 'js/poll-composer.js', '/assets/shortcut.js': 'js/compose-shortcut.js'}[url.pathname];
        if (!file) { response.writeHead(404).end(); return; }
        response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
        response.end(fs.readFileSync(path.join(root, 'public/assets', file)));
        return;
    }
    if (request.method === 'POST') {
        request.resume();
        votes++;
        setTimeout(() => {
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({html: fixture.confirmed, message: 'Vote recorded.'}));
        }, 80);
        return;
    }
    const origin = `http://127.0.0.1:${server.address().port}`;
    const poll = url.searchParams.has('poll_results') ? fixture.results : fixture.poll;
    const modal = url.pathname === '/modal' ? `<a href="/home" data-compose-trigger data-compose-home="0">New post</a>
        <a href="/posts/plain/modifica" data-edit-post data-edit-action="/posts/plain" data-edit-body="Plain text" data-edit-visibility="followers">Edit plain</a>
        <div id="ob-compose-modal" hidden><div class="ob-compose-modal__dialog"><button data-compose-modal-close>Close</button>
        <div data-composer><form data-composer-create-action="/pubblica"><textarea data-composer-body name="body"></textarea>
        <div id="modal_composer-panel-visibility" data-composer-panel><select name="visibility" data-composer-fill="visibility" data-composer-default="public"><option value="public">Public</option><option value="followers">Followers</option></select></div>
        ${fixture.composer.replaceAll('browser', 'modal_composer')}<button class="ob-composer__submit" type="submit">Publish</button>
        </form></div></div></div>` : '';

    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(`<!DOCTYPE html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/assets/app.css"></head><body><main class="ob-shell"><article class="ob-card">
        <h1>Meet up</h1>${modal || `<form id="composer">${fixture.composer}<button type="submit">Publish</button></form>`}
        ${poll}</article></main><script src="/assets/composer.js" defer></script><script src="/assets/polls.js" defer></script><script src="/assets/shortcut.js" defer></script>
        </body></html>`.replaceAll('http://openbook.test', origin));
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        const options = {headless: true};
        if (process.env.BROWSER_EXECUTABLE_PATH) options.executablePath = process.env.BROWSER_EXECUTABLE_PATH;
        browser = await playwright[process.env.BROWSER_TYPE || 'chromium'].launch(options);
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const origin = `http://127.0.0.1:${server.address().port}`;
        for (const viewport of [{width: 1280, height: 900}, {width: 375, height: 812}]) {
            await page.setViewportSize(viewport);
            await page.goto(origin);
            const composer = page.locator('[data-poll-composer]');
            await composer.locator('summary').click();
            const fields = composer.locator('[data-poll-option-input]');
            assert.equal(await fields.count(), 2);
            await fields.first().fill('💪'.repeat(50));
            assert.equal(await fields.first().evaluate(field => field.validity.valid), true);
            await fields.first().fill('a'.repeat(51));
            assert.equal(await fields.first().evaluate(field => field.validity.valid), false);
            await fields.first().fill('Saturday');
            await fields.nth(1).fill('Sunday');
            while (await fields.count() < 6) await composer.locator('[data-poll-option-add]').click();
            assert.equal(await composer.locator('[data-poll-option-add]').isDisabled(), true);
            await composer.locator('[data-poll-option-remove]').last().click();
            assert.equal(await fields.count(), 5);
            assert.equal(await composer.locator('[data-poll-option-add]').isEnabled(), true);
            const ids = await fields.evaluateAll(nodes => nodes.map(node => node.id));
            assert.equal(new Set(ids).size, ids.length);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            if (process.env.POLL_SCREENSHOTS) {
                await page.screenshot({path: path.join(process.env.POLL_SCREENSHOTS, `polls-${viewport.width}.png`), fullPage: true});
            }
            await composer.locator('[data-poll-composer-clear]').click();
            assert.equal(await fields.count(), 2);
            assert.equal(await composer.evaluate(node => node.open), false);
            const poll = page.locator('[data-poll-id]');
            await poll.locator('input[name="choices[]"]').first().check();
            const toggle = poll.locator('[data-poll-results-toggle]');
            await toggle.click();
            assert.equal(await poll.getAttribute('data-poll-results'), '1');
            assert.equal(await poll.locator('input[name="choices[]"]').first().isDisabled(), true);
            await toggle.click();
            assert.equal(await poll.locator('input[name="choices[]"]').first().isChecked(), true);
            assert.equal(await poll.locator('input[name="choices[]"]').first().isEnabled(), true);
        }
        await page.evaluate(() => {
            const form = document.querySelector('[data-poll-form]');
            form.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true}));
            form.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true}));
        });
        await page.getByText('Vote recorded.', {exact: false}).first().waitFor();
        assert.equal(votes, 1);
        assert.equal(await page.locator('[data-poll-form]').count(), 0);
        assert.equal(await page.locator('.ob-poll__choice').count(), 1);
        const passiveVotes = votes;
        await page.waitForTimeout(1200);
        assert.equal(votes, passiveVotes);
        await page.goto(origin + '/modal');
        await page.locator('[data-compose-trigger]').click();
        const modal = page.locator('#ob-compose-modal');
        await modal.locator('[data-poll-composer] summary').click();
        await modal.locator('[data-poll-option-add]').click();
        assert.equal(await modal.locator('[data-poll-option-input]').count(), 3);
        await page.keyboard.press('Escape');
        await page.locator('[data-edit-post]').click();
        assert.equal(await modal.locator('[data-poll-composer]').isHidden(), true);
        const editNames = await modal.locator('form').evaluate(form => Array.from(new FormData(form).keys()));
        assert.equal(editNames.some(name => name.startsWith('poll')), false);
        assert.equal(await modal.locator('textarea').inputValue(), 'Plain text');
        await page.keyboard.press('Escape');
        await page.locator('[data-compose-trigger]').click();
        assert.equal(await modal.locator('[data-poll-composer]').isVisible(), true);
        assert.equal(await modal.locator('[data-poll-option-input]').count(), 2);
        assert.equal(await modal.locator('[data-poll-composer-fields]').isDisabled(), false);
        const plain = await browser.newPage({javaScriptEnabled: false});
        await plain.goto(origin);
        await plain.locator('[data-poll-results-toggle]').click();
        assert.equal(await plain.locator('[data-poll-id]').getAttribute('data-poll-results'), '1');
        await plain.locator('[data-poll-results-toggle]').click();
        assert.equal(await plain.locator('[data-poll-id]').getAttribute('data-poll-results'), '0');
        assert.deepEqual(errors, []);
        console.log('Polls: composer limits/Unicode, desktop/mobile layout, results toggle, vote confirmation and native links passed.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
