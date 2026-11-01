# OAuth client integration

> [Italiano](clients.it.md) · [API overview](README.md)

Use Authorization Code with PKCE S256, a public client, a callback URI and the
single scope `api`. The library generates verifier, challenge and state. No
login polling is needed. The user sees only OpenBook login and app consent.

| Platform | Library | Application setup |
| --- | --- | --- |
| iOS | [AppAuth-iOS](https://github.com/openid/AppAuth-iOS) | Configure endpoints and a callback handled by ASWebAuthenticationSession, then handle the resulting auth state. |
| Android | [AppAuth-Android](https://github.com/openid/AppAuth-Android) | Configure endpoints and callback handling through Custom Tabs/App Links or a private URI scheme. |
| React / browser JavaScript | [oidc-client-ts](https://github.com/authts/oidc-client-ts) | Register using fetch, configure OAuth endpoints, then call the library at login and at the callback route. |

AppAuth supports RFC 7591 registration. Set token_endpoint_auth_method to `none`
and use the registration endpoint from OAuth metadata. Alternatively supply a
client_id already registered for the instance. Persist and reuse registration
information; independent registrations produce independent client IDs. Native
token storage should use the platform's secure storage facilities.

## Browser example

Fetch `/.well-known/oauth-authorization-server`, register once through its
registration_endpoint and retain client_id. This minimal example uses OAuth
only; no OpenID Connect discovery, ID token or userinfo is required.

```js
import { OidcClient } from 'oidc-client-ts';

const client = new OidcClient({
  authority: instanceUrl,
  metadata,                 // RFC 8414 document fetched from OpenBook
  client_id: savedClientId,
  redirect_uri: callbackUrl,
  response_type: 'code',
  scope: 'api',
  loadUserInfo: false,
});

// Sign-in button:
const request = await client.createSigninRequest({});
window.location.assign(request.url);

// In the callback route, using the same configuration and browser origin:
const result = await client.processSigninResponse(window.location.href);
// result.access_token is the Bearer credential for subsequent API calls.
```

Persisting the public client ID is separate from storing access tokens. The
application chooses its token lifecycle/storage; do not place the access token
in a URL or log it. Clear the callback query from browser history after processing.
Native apps should likewise let AppAuth perform PKCE and token exchange rather
than reimplementing them.

## Reproducible SDK check

The repository includes a test with the real oidc-client-ts library, an isolated
SQLite database and a loopback PHP server. Browser login/consent is simulated
through HTTP with a cookie jar and real CSRF checks; the SDK generates PKCE/state,
validates the callback and performs token exchange itself. It then reads `/me`
with the authenticated Person and checks 200/304/401, then closes the access from settings with real CSRF
validation. It checks that the Bearer and refresh token are rejected while the
website session stays open. Signing keys are generated automatically during the
flow; the test verifies encrypted database storage without provisioning key files.
The test deletes temporary data and does not use the local
OpenBook database. It is not a React rendering test or an iOS/Android device test.

```sh
npm install --prefix /tmp/openbook-oauth-sdk oidc-client-ts@^3 --ignore-scripts --no-audit --no-fund
NODE_PATH=/tmp/openbook-oauth-sdk/node_modules node tests/Client/oauth.cjs
```

An installed-instance marker is required, as with the other application
tests. Run this check separately from the installer tests that temporarily remove
that marker. A loopback listening port and Node.js with fetch/Web Crypto are needed.

## Production-environment check

The same check can boot the application in production:

```sh
APP_ENV=production OPENBOOK_OAUTH_TEST_ENV=production NODE_PATH=/tmp/openbook-oauth-sdk/node_modules node tests/Client/oauth.cjs
```

SQLite, keys, sessions and the loopback server remain isolated. The fixture
verifies that production forces HTTPS, then overrides URL generation and Secure
cookies only for its HTTP loopback transport. CSRF, PKCE/state and revocation
are real; this is not a TLS deployment test. Deployments use HTTPS and Secure cookies.
