# REST API draft

> [Italiano](README.it.md) · [OpenAPI specification](openapi.json) · [Client integration](clients.md)

All REST content endpoints require an active user and an OAuth Bearer token,
including content publicly readable on the website. A website session cookie
alone does not authorize REST calls. This prototype is limited to local/testing.

- `GET /rest/v1/posts` returns an array of Post objects from the World feed.
- `GET /rest/v1/me` returns Me with myPerson, the authenticated account as a full Person.
- `GET /rest/v1/persons/{personId}` returns a Person object.
- `GET /rest/v1/persons/{personId}/followers` returns an array of PersonReference.
- `GET /rest/v1/persons/{personId}/following` returns an array of PersonReference.

PersonReference contains personId, personUsername, personDomain, personName and
an optional personAvatar. Lists use after, limit (default 20, maximum 100),
sort=personId and direction=asc (default) or desc. Link with rel="next" supplies
the next URL. Remote information may be incomplete; no remote fetch is triggered.
Locally suspended profiles and unavailable resources return 404.

Person supports the case-sensitive types `person`, `application`, `feed` and
`group`. PersonReference uses the same compact fields for every type. Local
private community profiles remain readable; their relationship collections
return 403 and private community identities are omitted from relationship lists.

## Post list

`GET /rest/v1/posts` returns a direct array of Post objects. `engine=world` is
the default, using the World section's selection, exclusions and ordering.
`timeline` is not supported yet and returns 422. The initial representation
includes text, author PersonReference, content warning, media, location,
counters and user interactions; polls, quotes and other related objects are
not yet represented.

Parameters: limit (20, maximum 100), after (the last postId),
sort=postPublishedAt, direction=desc. Link next supplies the next page.
Unknown or unreadable reference posts return 422; restart from the first page.
cursor and page are not supported. Text is not rendered to HTML by this API.
Optional fields without values are omitted, including title, source and emojis.

## OAuth flow

Use OAuth 2 Authorization Code with PKCE S256 and a public client. The client
library opens OpenBook's existing login page, receives a code at its callback
and exchanges it for a token. There is no login polling or token in the callback
URL. The single scope is `api`; the user sees an ordinary application consent
page, with no granular permissions to select. OpenBook is an OAuth server, not
an OpenID Connect provider: do not request `openid` or an ID token.

| Operation | Endpoint |
| --- | --- |
| OAuth server metadata (RFC 8414) | `GET /.well-known/oauth-authorization-server` |
| Public client registration (RFC 7591) | `POST /oauth/register` |
| Browser authorization | `GET /oauth/authorize` |
| Code exchange and refresh | `POST /oauth/token` |

Registration takes a JSON body. It supports HTTPS redirects, HTTP loopback
redirects for local applications and reverse-domain private schemes for native
apps. URL fragments and user information are rejected.

```json
{
  "client_name": "Example application",
  "redirect_uris": ["https://client.example/callback"],
  "token_endpoint_auth_method": "none",
  "grant_types": ["authorization_code", "refresh_token"],
  "response_types": ["code"],
  "scope": "api"
}
```

Store the returned client_id and reuse it on this instance. Registration is not
part of every login. Each registration call creates a client; the server does
not deduplicate arbitrary applications by display name or redirect URI. Public
clients do not receive a secret. The server registers Authorization Code and
refresh support and returns the effective metadata. Other metadata is ignored;
confidential client registration and registration management are not exposed.

Token requests use application/x-www-form-urlencoded with standard OAuth names
such as grant_type, client_id, code, redirect_uri and code_verifier. The response
uses access_token, token_type, expires_in and refresh_token. Access tokens are
JWTs with server-side revocation checks. Access and refresh tokens default to
365 days. Operators can configure new token lifetimes in days using
`OPENBOOK_OAUTH_TOKEN_DURATION`; existing tokens keep their expiry. Refresh does
not require another login.
Account availability is checked at authorization, issuance, refresh and REST
access. Personal settings include “Connected apps”, listing each separate
access. “Close this access” revokes both the Bearer and its refresh token while
keeping other accesses and the website session open. Invalid accesses disappear
from the list; if an app has just renewed an access, reload the list and close
the current one. Tokens are never displayed. Website logout does not revoke
OAuth tokens. Administration and staff operations remain outside the REST API.

## Local setup and testing

Install Composer dependencies and apply the pending OAuth migrations. Generate
keys once with `php artisan passport:keys`; preserve storage/oauth-*.key and do
not overwrite existing keys on routine updates. Refresh configuration and route
caches. Production integration with the web installer/updater is still deferred.

Clients can register automatically through the endpoint above. A developer can
also create a public client with the standard Passport command:

```sh
php artisan passport:client --public --name="Example application" --redirect_uri="https://client.example/callback" --no-interaction
```

```sh
curl -i -H "Authorization: Bearer $OPENBOOK_TOKEN" \
  'http://127.0.0.1:8000/rest/v1/persons/PERSON_UUID/following?limit=20'
```

For Paw or another API client, choose OAuth 2 / Authorization Code, supply the
registered client_id, leave client_secret empty, send client_id in the token
request body and configure scope `api` plus PKCE S256. The callback must match
the registered URI exactly. A tool without PKCE support cannot complete this
flow automatically.

CORS allows browser clients to call the Bearer API, metadata, registration and
token endpoints without website cookies. ETag, Link, Retry-After and
WWW-Authenticate are exposed to JavaScript. REST errors use Problem Details;
OAuth errors retain the standard OAuth format. Missing/invalid credentials return
401 and inactive accounts return 403. ETag/If-None-Match supports 304 after all
access checks. Last-Modified remains unimplemented. Limits per client address:
60 REST calls, 30 token exchanges and 10 registrations per minute, independently.
