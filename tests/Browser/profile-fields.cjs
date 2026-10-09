/** Browser checks against the production Blade partial, JS and CSS. */
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const playwright = require('playwright');
const root = path.resolve(__dirname, '../..');
const fixture = execFileSync('php', [path.join(__dirname, 'fixtures/profile-fields.php')], {
    cwd: root,
    encoding: 'utf8',
});
const server = http.createServer((request, response) => {
    if (request.url === '/fields.js' || request.url === '/app.css') {
        const file = request.url === '/fields.js' ? 'js/profile-fields.js' : 'css/app.css';
        response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'text/css');
        response.end(fs.readFileSync(path.join(root, 'public/assets', file)));
        return;
    }
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(`<!DOCTYPE html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/app.css"></head><body><main class="ob-shell"><div class="ob-card">
        <form>${fixture}</form></div></main><script src="/fields.js" defer></script></body></html>`);
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
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        const rows = page.locator('[data-profile-field]');
        const addLink = page.locator('[data-add-profile-field="link"]');
        const addText = page.locator('[data-add-profile-field="text"]');
        assert.equal(await rows.count(), 2);
        assert.equal(await page.locator('[data-profile-field-list="link"] [data-profile-field-value]').inputValue(), 'https://example.test');
        assert.equal(await page.locator('[data-profile-field-list="text"] [data-profile-field-value]').inputValue(), 'Teacher');

        for (const viewport of [{ width: 1280, height: 800 }, { width: 375, height: 812 }]) {
            await page.setViewportSize(viewport);
            await addText.click();
            const last = page.locator('[data-profile-field-list="text"] [data-profile-field]').last();
            await last.locator('[data-profile-field-label]').fill('City');
            await last.locator('[data-profile-field-value]').fill('Palermo');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            if (process.env.PROFILE_FIELDS_SCREENSHOTS) {
                await page.screenshot({ path: path.join(process.env.PROFILE_FIELDS_SCREENSHOTS, `profile-fields-${viewport.width}.png`), fullPage: true });
            }
            await last.locator('[data-remove-profile-field]').click();
        }

        while (await rows.count() < 8) await addLink.click();
        assert.equal(await addLink.isDisabled(), true);
        assert.equal(await addText.isDisabled(), true);
        await rows.last().locator('[data-remove-profile-field]').click();
        assert.equal(await addLink.isEnabled(), true);
        assert.equal(await addText.isEnabled(), true);
        assert.equal(await addText.evaluate(button => button === document.activeElement), true);
        await addText.click();
        assert.equal(await rows.count(), 8);
        const names = await page.locator('[data-profile-field] [name]').evaluateAll(inputs => inputs.map(input => input.name));
        assert.equal(new Set(names).size, names.length);
        assert.equal(names.some(name => name.includes('__INDEX__')), false);
        assert.equal(await page.locator('[data-profile-field-count]').textContent(), '8 of 8 total fields');
        assert.deepEqual(errors, []);
        console.log('Profile fields: legacy values, add/remove, shared limit, unique indices and desktop/mobile layout passed.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
