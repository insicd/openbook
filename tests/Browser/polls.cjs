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
        const file = {'/assets/app.css': 'css/app.css', '/assets/polls.js': 'js/polls.js', '/assets/composer.js': 'js/poll-composer.js', '/assets/shared-composer.js': 'js/composer.js', '/assets/shortcut.js': 'js/compose-shortcut.js'}[url.pathname];
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
    if (url.pathname.includes('/sondaggio/votanti')) {
        const option = url.pathname.split('/').at(-1);
        const content = fixture.voters[option] || Object.values(fixture.voters)[0];
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(`<!DOCTYPE html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"></head><body><main class="ob-shell">${content}</main></body></html>`.replaceAll('http://openbook.test', origin));
        return;
    }
    const poll = url.pathname === '/poll-voted' ? fixture.confirmed : (url.searchParams.has('poll_results') ? fixture.results : fixture.poll);
    const modal = url.pathname === '/modal' ? `<a href="/home" data-compose-trigger data-compose-home="0">New post</a>
        <a href="/posts/plain/modifica" data-edit-post data-edit-action="/posts/plain" data-edit-body="Plain text" data-edit-visibility="followers">Edit plain</a>
        <div id="ob-compose-modal" hidden><div class="ob-compose-modal__dialog"><button data-compose-modal-close>Close</button>
        ${fixture.composer.replaceAll('browser', 'modal_composer')}
        </div></div>` : '';

    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(`<!DOCTYPE html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/assets/app.css"></head><body><main class="ob-shell"><article class="ob-card">
        <h1>Meet up</h1>${url.pathname.startsWith('/poll') ? '' : (modal || fixture.composer)}
        ${poll}<div class="ob-post__actions">♡ 0　♧ 0　♧ 0</div></article></main><script src="/assets/shared-composer.js" defer></script><script src="/assets/composer.js" defer></script><script src="/assets/polls.js" defer></script><script src="/assets/shortcut.js" defer></script>
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
            const togglePoll = page.locator('[data-composer-toggle="browser-panel-poll"]');
            assert.equal(await composer.isHidden(), true);
            await togglePoll.click();
            assert.equal(await togglePoll.getAttribute('aria-expanded'), 'true');
            const fields = composer.locator('[data-poll-option-input]');
            await fields.first().fill('Preserved');
            await page.locator('[data-composer-toggle="browser-panel-cw"]').click();
            assert.equal(await composer.isHidden(), true);
            assert.equal(await togglePoll.getAttribute('aria-expanded'), 'false');
            await togglePoll.click();
            assert.equal(await fields.first().inputValue(), 'Preserved');
            assert.equal(await togglePoll.evaluate(node => node.classList.contains('is-filled')), true);
            assert.equal(await fields.count(), 2);
            await page.locator('[data-composer-body]').fill('When do we meet?');
            await fields.first().fill('Saturday');
            await togglePoll.click();
            await page.locator('.ob-composer__submit').click();
            assert.equal(await composer.isVisible(), true);
            assert.equal(await togglePoll.getAttribute('aria-expanded'), 'true');
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
            assert.equal(await composer.isHidden(), true);
            assert.equal(await togglePoll.getAttribute('aria-expanded'), 'false');
            assert.equal(await togglePoll.evaluate(node => node.classList.contains('is-filled')), false);
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
            const button = document.querySelector('[data-poll-submit]');
            button.click();
            button.click();
        });
        await page.getByText('Vote recorded.', {exact: false}).first().waitFor();
        assert.equal(votes, 1);
        assert.equal(await page.locator('[data-poll-form]').count(), 0);
        assert.equal(await page.locator('.ob-poll__choice').count(), 1);
        assert.equal(await page.locator('[data-poll-confirmed]').evaluate(node => document.activeElement === node), true);
        assert.equal(await page.locator('[data-poll-confirmed]').evaluate(node => getComputedStyle(node).outlineStyle), 'none');
        assert.equal(await page.locator('[data-poll-id]').evaluate(node => getComputedStyle(node).outlineStyle), 'none');
        for (const width of [1280, 375]) {
            await page.setViewportSize({width, height: 900});
            await page.goto(origin + '/poll-voted');
            const summary = page.locator('.ob-poll__summary');
            assert.match(await summary.locator('time').textContent(), /You voted on/);
            assert.equal(await summary.locator('.ob-poll__confirmed').evaluate(node => getComputedStyle(node).fontSize),
                await summary.locator('.ob-poll__total').evaluate(node => getComputedStyle(node).fontSize));
            if (width === 1280) {
                const left = await summary.locator('.ob-poll__confirmed').boundingBox();
                const right = await summary.locator('.ob-poll__total').boundingBox();
                assert.ok(Math.abs(left.y - right.y) < 2);
                assert.ok(right.x > left.x + left.width);
            }
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            if (process.env.POLL_SCREENSHOTS) await page.screenshot({path: path.join(process.env.POLL_SCREENSHOTS, `poll-voted-${width}.png`), fullPage: true});
        }
        const passiveVotes = votes;
        await page.waitForTimeout(1200);
        assert.equal(votes, passiveVotes);
        await page.goto(origin + '/modal');
        await page.locator('[data-compose-trigger]').click();
        const modal = page.locator('#ob-compose-modal');
        await modal.locator('[data-composer-toggle$="panel-poll"]').click();
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
        assert.equal(await modal.locator('[data-composer-toggle$="panel-poll"]').isVisible(), true);
        assert.equal(await modal.locator('[data-poll-composer]').isHidden(), true);
        assert.equal(await modal.locator('[data-poll-option-input]').count(), 2);
        assert.equal(await modal.locator('[data-poll-composer-fields]').isDisabled(), false);
        for (const width of [1280, 375]) {
            await page.setViewportSize({width, height: 900});
            await page.goto(origin + '/poll');
            const actions = page.locator('.ob-poll__actions');
            assert.equal(await actions.getByRole('button', {name: 'Vote', exact: true}).count(), 1);
            assert.equal(await actions.getByRole('link', {name: 'Preview results'}).count(), 1);
            assert.equal(await actions.getByRole('link', {name: 'Answer details'}).count(), 1);
            assert.equal(await page.locator('.ob-poll').evaluate(node => getComputedStyle(node).borderBottomWidth), '0px');
            const info = page.locator('.ob-poll__info');
            await page.locator('h1').click();
            assert.equal(await info.locator('[role="note"]').isVisible(), false);
            if (process.env.POLL_SCREENSHOTS) await page.screenshot({path: path.join(process.env.POLL_SCREENSHOTS, `poll-actions-${width}.png`), fullPage: true});
            await info.locator('summary').focus();
            assert.match(await info.locator('summary').getAttribute('title'), /Your vote cannot be changed/);
            await page.keyboard.press('Enter');
            assert.equal(await info.getAttribute('open'), '');
            assert.equal(await info.locator('[role="note"]').isVisible(), true);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        }
        const touch = await browser.newPage({viewport: {width: 375, height: 812}, hasTouch: true, isMobile: true});
        await touch.goto(origin + '/poll');
        await touch.locator('.ob-poll__info summary').tap();
        assert.equal(await touch.locator('.ob-poll__info-panel').isVisible(), true);
        await touch.locator('.ob-poll__info summary').tap();
        assert.equal(await touch.locator('.ob-poll__info-panel').isVisible(), false);
        await touch.close();
        const plain = await browser.newPage({javaScriptEnabled: false});
        await plain.goto(origin);
        assert.equal(await plain.locator('[data-poll-composer]').isVisible(), true);
        await plain.locator('[data-poll-results-toggle]').click();
        assert.equal(await plain.locator('[data-poll-id]').getAttribute('data-poll-results'), '1');
        await plain.locator('[data-poll-results-toggle]').click();
        assert.equal(await plain.locator('[data-poll-id]').getAttribute('data-poll-results'), '0');
        for (const width of [1280, 375]) {
            await plain.setViewportSize({width, height: 900});
            await plain.goto(origin + '/posts/question/sondaggio/votanti');
            const tabs = plain.locator('.ob-poll-voters__tabs a');
            assert.equal(await tabs.count(), 2);
            await tabs.nth(1).focus();
            await plain.keyboard.press('Enter');
            assert.equal(await plain.locator('.ob-poll-voters__tabs [aria-current="page"] bdi').textContent(), 'Sunday');
            assert.equal(await plain.locator('[data-poll-voters] .ob-suggestion').count(), 25);
            await plain.locator('a[rel="next"]').click();
            assert.equal(new URL(plain.url()).searchParams.get('page'), '2');
            assert.equal(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            if (process.env.POLL_SCREENSHOTS) await plain.screenshot({path: path.join(process.env.POLL_SCREENSHOTS, `voters-${width}.png`), fullPage: true});
        }
        assert.deepEqual(errors, []);
        console.log('Polls: composer limits/Unicode, desktop/mobile layout, results toggle, vote confirmation and native links and author tabs/pagination passed.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
