/** End-to-end protocol test using oidc-client-ts and the real Laravel HTTP stack. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const crypto = require('node:crypto');
const { spawn, execFileSync } = require('node:child_process');
const { once } = require('node:events');
const { OidcClient, WebStorageStateStore, InMemoryWebStorage } = require('oidc-client-ts');
const root = path.resolve(__dirname, '../..');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'openbook-oauth-'));
let server;
(async () => {
    try {
        const listener = net.createServer();
        listener.listen(0, '127.0.0.1');
        await once(listener, 'listening');
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const base = `http://127.0.0.1:${port}`;
        const keys = crypto.generateKeyPairSync('rsa', {
            modulusLength: 2048, privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
            publicKeyEncoding: { type: 'spki', format: 'pem' },
        });
        fs.writeFileSync(path.join(directory, 'private.key'), keys.privateKey, { mode: 0o600 });
        fs.writeFileSync(path.join(directory, 'public.key'), keys.publicKey);
        fs.writeFileSync(path.join(directory, 'database.sqlite'), '');
        fs.mkdirSync(path.join(directory, 'sessions'));
        const environment = { ...process.env, OPENBOOK_OAUTH_TEST_DIR: directory, OPENBOOK_OAUTH_TEST_URL: base };
        const fixture = path.join(__dirname, 'oauth-fixture.php');
        const setup = execFileSync('php', [fixture], { cwd: root, env: environment, encoding: 'utf8' });
        let data;
        try { data = JSON.parse(setup); } catch { throw new Error('Fixture setup failed: '+setup); }
        server = spawn('php', ['-S', `127.0.0.1:${port}`, fixture], { cwd: root, env: environment, stdio: 'ignore' });
        let ready = false;
        for (let attempt = 0; attempt < 50; attempt++) {
            try { await fetch(`${base}/.well-known/oauth-authorization-server`); ready = true; break; }
            catch { await new Promise(resolve => setTimeout(resolve, 50)); }
        }
        assert(ready, 'PHP server did not start');
        const metadata = await (await fetch(`${base}/.well-known/oauth-authorization-server`)).json();
        const registrationResponse = await fetch(metadata.registration_endpoint, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
                client_name: 'oidc-client-ts integration test', redirect_uris: ['https://client.example/callback'],
                token_endpoint_auth_method: 'none', grant_types: ['authorization_code', 'refresh_token'], response_types: ['code'],
            }),
        });
        assert.equal(registrationResponse.status, 201);
        const registration = await registrationResponse.json();
        assert(!registration.client_secret);
        const client = new OidcClient({
            authority: base, metadataUrl: `${base}/.well-known/oauth-authorization-server`,
            client_id: registration.client_id, redirect_uri: 'https://client.example/callback',
            response_type: 'code', scope: 'api', loadUserInfo: false,
            stateStore: new WebStorageStateStore({ store: new InMemoryWebStorage() }),
        });
        const signin = await client.createSigninRequest({});
        const authorization = new URL(signin.url);
        assert.equal(authorization.searchParams.get('code_challenge_method'), 'S256');
        assert(authorization.searchParams.get('state'));
        // Only the browser login/consent is simulated; PKCE, state and exchange are SDK code.
        const cookies = new Map();
        async function browser(url, body) {
            const headers = { Cookie: [...cookies].map(([key, value]) => `${key}=${value}`).join('; ') };
            if (body) headers['Content-Type'] = 'application/x-www-form-urlencoded';
            const response = await fetch(url, { method: body ? 'POST' : 'GET', headers, body: body && new URLSearchParams(body), redirect: 'manual' });
            for (const cookie of response.headers.getSetCookie()) {
                const pair = cookie.split(';', 1)[0]; const split = pair.indexOf('=');
                cookies.set(pair.slice(0, split), pair.slice(split + 1));
            }
            return response;
        }
        const input = (html, name) => {
            const value = html.match(new RegExp(`name="${name}"[^>]*value="([^"]*)"`));
            assert(value, `Missing input ${name}`); return value[1];
        };
        const first = await browser(signin.url); assert.equal(first.status, 302);
        const loginPage = await browser(first.headers.get('Location')); assert.equal(loginPage.status, 200);
        const loggedIn = await browser(`${base}/accedi`, {
            _token: input(await loginPage.text(), '_token'), login: 'oauthclient', password: 'OAuthClientTest123',
        });
        assert.equal(loggedIn.status, 302);
        const consent = await browser(loggedIn.headers.get('Location')); assert.equal(consent.status, 200);
        const consentHtml = await consent.text();
        const approved = await browser(`${base}/oauth/authorize`, {
            _token: input(consentHtml, '_token'), auth_token: input(consentHtml, 'auth_token'),
            state: input(consentHtml, 'state'), client_id: registration.client_id,
        });
        assert.equal(approved.status, 302);
        const callback = approved.headers.get('Location');
        assert(callback.startsWith('https://client.example/callback?'));
        assert(!new URL(callback).searchParams.has('access_token'));
        const result = await client.processSigninResponse(callback);
        assert(result.access_token); assert(result.refresh_token);
        const url = `${base}/rest/v1/persons/${data.personId}`;
        const person = await fetch(url, { headers: { Authorization: `Bearer ${result.access_token}` } });
        assert.equal(person.status, 200); assert.equal((await person.json()).personId, data.personId);
        const etag = person.headers.get('ETag');
        assert.equal((await fetch(url, { headers: { Authorization: `Bearer ${result.access_token}`, 'If-None-Match': etag } })).status, 304);
        assert.equal((await fetch(url, { headers: { 'If-None-Match': etag } })).status, 401);
        const appsPage = await browser(`${base}/impostazioni?tab=applications`);
        assert.equal(appsPage.status, 200);
        const appsHtml = await appsPage.text();
        assert(appsHtml.includes('Connected apps'));
        const closeAction = appsHtml.match(/<form method="POST" action="([^"]*impostazioni\/applicazioni\/[^"]*)">/);
        assert(closeAction, 'Missing connected-app close form');
        const closed = await browser(closeAction[1], { _token: input(appsHtml, '_token'), _method: 'DELETE' });
        assert.equal(closed.status, 302);
        assert.equal((await fetch(url, { headers: { Authorization: `Bearer ${result.access_token}` } })).status, 401);
        const refresh = await fetch(metadata.token_endpoint, { method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ grant_type: 'refresh_token', client_id: registration.client_id, refresh_token: result.refresh_token }),
        });
        assert.equal(refresh.status, 400);
        const afterClose = await browser(`${base}/impostazioni?tab=applications`);
        assert.equal(afterClose.status, 200);
        assert((await afterClose.text()).includes('There are no apps connected to your account.'));
        console.log('PASS: dynamic registration, SDK PKCE/state, existing login, callback, SDK exchange, REST 200/304/401, settings close with CSRF, revoked Bearer/refresh, website session preserved');
    } finally {
        if (server && server.exitCode === null) { server.kill(); await once(server, 'exit'); }
        fs.rmSync(directory, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
