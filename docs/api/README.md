# Experimental REST API — first sprint

> [Italiano](README.it.md) · [OpenAPI JSON](openapi.json) · [Client integration](clients.md)

This sprint opens OpenBook to independent applications: native clients,
specialized frontends and third-party integrations. The native interface
continues to manage the product and all administrative operations.

**Support is experimental in coverage and contract, and is available in
production too.** REST/OAuth and connected accesses do not depend on APP_ENV.
A completed installation, OAuth migrations and secure instance configuration
remain required. Signing keys are initialized automatically. This is neither a
Mastodon API nor an ActivityPub representation. Deployment preparation is described below.

## Supported operations

Entities cover Instance, Me, Person, PersonReference, Follow and Post.
NewPost and PatchPost define post inputs; PatchFollowRequest accepts incoming
requests. Media and Location are already embedded in Post, without standalone
upload or management endpoints.

| Entity | Endpoint | Purpose |
| --- | --- | --- |
| Instance | `GET /rest/v1/instance` | Get instance metadata |
| Me | `GET /rest/v1/me` | Get the authenticated user |
| Me | `GET /rest/v1/me/posts` | List posts published by the authenticated user |
| Person | `GET /rest/v1/persons/{personId}` | Get a person |
| Person | `GET /rest/v1/persons/{personId}/followers` | List a person's followers |
| Person | `GET /rest/v1/persons/{personId}/following` | List a person's following |
| Person | `GET /rest/v1/persons/{personId}/posts` | List a person's posts and announces |
| Follow | `GET /rest/v1/me/follow-requests` | List incoming pending follow requests |
| Follow | `PATCH /rest/v1/me/follow-requests/{personId}` | Accept an incoming pending follow request |
| Follow | `DELETE /rest/v1/me/follow-requests/{personId}` | Reject an incoming pending follow request |
| Follow | `GET /rest/v1/persons/{personId}/follow` | Get your follow state towards a person |
| Follow | `PUT /rest/v1/persons/{personId}/follow` | Follow a person or request approval |
| Follow | `DELETE /rest/v1/persons/{personId}/follow` | Stop following a person or withdraw a pending request |
| Post | `GET /rest/v1/posts` | List posts |
| Post | `POST /rest/v1/posts` | Create a text post |
| Post | `GET /rest/v1/posts/{postId}` | Get a post |
| Post | `PATCH /rest/v1/posts/{postId}` | Edit a simple post |
| Post | `DELETE /rest/v1/posts/{postId}` | Delete an owned local post |
| Post | `PUT /rest/v1/posts/{postId}/announce` | Announce a post |
| Post | `DELETE /rest/v1/posts/{postId}/announce` | Remove a post announce |
| Post | `PUT /rest/v1/posts/{postId}/like` | Like a post |
| Post | `DELETE /rest/v1/posts/{postId}/like` | Remove a like |

Every REST call requires OAuth Bearer credentials, an active user and actor,
and scope `api`. Website cookies alone do not authorize even public-content
requests. Admin/moderator roles grant no additional REST privileges.

## Representations and content

- Direct objects or arrays, without a `data` wrapper. OpenAPI defines fields,
  required and nullable independently, and examples. Optional is distinct from null.
- Property names and case-sensitive enum values use lowerCamelCase; OAuth
  protocol names retain their standard wire format.
- Person types are `person`, `application`, `feed` and `group`, with the same
  representation for available local/remote identities. A group Person is the
  community identity, not the future Community resource. PersonReference is
  the compact ID, username, domain, name and optional avatar representation.
- `myPerson` is the complete authenticated Person. `/me/posts` selects ownership;
  `/persons/{personId}/posts` also includes announces.
- `personSummary` and `postContent` use the text available in OpenBook without new
  HTML rendering: Markdown or plain text depending on the source. The original
  remote document is not promised. Clients handle rendering and sanitization.
  `personFields` are label/value pairs; values need not be URLs.
- Instance exposes name, domain, canonical URL, description and an optional icon,
  without statistics. An unconfigured description is an empty string.
- Federated data reflects this instance's knowledge. Remote lists and histories
  may be partial rather than complete global inventories.

## Reading and pagination

| Collection | Boundary and size | Order |
| --- | --- | --- |
| Followers, following and incoming requests | `after` = last personId; `limit` defaults to 20, maximum 100 | `sort=personId`; `direction=asc` default, or `desc` |
| `/me/posts` and `/posts?engine=world` | `after` = last postId; `limit` 20/100 | `sort=postPublishedAt`; `direction=desc` |
| `/posts?engine=timeline` | `after` + `afterAt`; `limit` 20/100 | `sort=postTimelineAt`; `direction=desc` |
| `/persons/{personId}/posts` | `after` + `afterAt`; configured website page size; `limit` is not accepted | `sort=postTimelineAt`; `direction=desc` |

Follow the standard `Link: <URL>; rel="next"` header when present. No server-side
cursor state is stored. These lists do not support `cursor` or `page`.
For timeline/profile lists, `afterAt` is the returned ISO postTimelineAt; the
link supplies both parameters even if the reference post is later removed.
Group profile order uses publication time. World and /me/posts require the
reference to remain available in the collection; otherwise 422 means restart
from the first page. Pagination is not an immutable snapshot.

World is the default engine and follows the website's World selection; timeline
follows the personal home feed. Optional `postAnnounce` is the context selected
by the feed, not an arbitrary announce. Details, World and /me/posts do not
invent it. `postMyInteractions.postDirectAnnounced` is the viewer's manual
announce state; `postAnnounced` also includes implicit quote shares.

Person detail refreshes remote metadata/counts using existing cache policy;
the person's post collection imports content when its TTL expires. Post detail
uses the website's remote refresh behavior. Remote failures retain cached data
when still accessible. Authorization is checked again after refresh. Person
lists and incoming requests do not fetch remote documents.

## Writes and relationships

POST /posts accepts only NewPost with required `postContent` and `postVisibility`.
Creation/editing allow public, unlisted and followers. PATCH accepts PatchPost
with both fields optional but at least one supplied. Text must be nonempty and
within the instance's configured limit. Unknown fields, nulls and invalid values
return 422; malformed JSON returns 400 and non-JSON Content-Type returns 415.

Creation returns 201 with Post and Location. Editing returns 200 with Post,
only for owned, simple local posts. Media, location, polls, communities, quotes
and direct posts are outside the initial edit scope and return 409. Omitted
title/CW/language are preserved. DELETE removes only owned local posts: 204;
already deleted posts return 404. Admins cannot mutate other users' posts.

| Relationship | PUT | DELETE |
| --- | --- | --- |
| Like | 201 on creation, 204 if already present | 204 even when already absent |
| Manual announce | 201 on activation, 204 if already active | 204 even when already absent on a visible post |
| Follow | 201 with Follow on creation, 200 with Follow if existing | 204 even when absent on an available Person |

These operations require no body. Follow contains only `followStatus`:
none, pending or accepted. GET returns 200 even for none. Direction is always
authenticated user → Person in the path. Rejection/withdrawal returns to none.
Open local identities and feeds are accepted immediately; protected locals and
remote non-feed identities may stay pending until acceptance. PUT may retry
pending remote delivery without duplicating the relation. Self-follow and
inactive targets refused by the operation return 422.

New announces require public/unlisted posts outside private communities.
Visible but non-announceable content returns 403. An existing announce can be
removed after visibility is lost; once removed, further DELETE requests for an
invisible post return 404. Deleted posts and private messages are always excluded.
Removing a manual announce preserves published quotes and implicit shares.
Suspended remote identities refuse new follows/likes/announces with 403, but
removal remains possible while the identity/resource is available. Community
groups retain membership permissions; owners cannot leave their community.

### Incoming requests

GET /me/follow-requests returns PersonReference objects for pending requesters
to the authenticated account, excluding other accounts' and community requests.
PATCH requires `{"followStatus":"accepted"}` as PatchFollowRequest and returns
204. DELETE rejects and returns 204. Missing, handled, withdrawn, foreign or
unavailable requests return 404. DELETE does not remove an accepted follower.
Pending requests remain manageable if the receiving profile has since become
open; pending state determines collection membership.

## Authentication and connected accesses

OAuth 2 Authorization Code with PKCE S256, single scope `api` and consent
through the existing website login. This is not OpenID Connect: no ID token,
userinfo, password grant, sharing account credentials with clients or polling.
Protocol endpoints are at the root, outside /rest/v1:

- `GET /.well-known/oauth-authorization-server`: OAuth metadata.
- `POST /oauth/register`: RFC 7591 public registration without a client secret.
- `GET/POST/DELETE /oauth/authorize`: start, consent/denial with session and CSRF.
- `POST /oauth/token`: code exchange and refresh-token renewal.

Register once per instance and retain client_id; registrations are not
deduplicated by name. Registration is distinct from a user access. Supported
callbacks are HTTPS, HTTP loopback for tests/native apps, or reverse-domain
private schemes; no fragments or credentials in the URI. Redirects must match
registration. Token requests use form-urlencoded with standard grant_type,
client_id, code, redirect_uri and code_verifier names. Client libraries handle
PKCE and state: see [integration](clients.md).

Access tokens are JWTs with server-side validation and revocation. Both access
and refresh tokens default to 365 days. `OPENBOOK_OAUTH_TOKEN_DURATION` controls
new lifetimes in days, without changing already issued tokens. Refresh does
not require login again. Account availability is checked during authorization,
issuance, refresh and REST calls.

Settings → Connected apps lists individual accesses and closes them with their
refresh tokens. Other accesses and the website session remain open. Tokens
are never displayed. If the client has already rotated an access, the list
reports the change: reload and close the current access. Website logout does
not revoke OAuth tokens. Password/email changes, account deletion, staff and
administration remain in the native interface.

## HTTP, caching and security

Reads use `Cache-Control: private, no-cache`, ETag and Vary Authorization.
Send If-None-Match for 304 only after authentication and current accessibility
checks. Key client caches by user and URL; invalidate/revalidate affected
entries after writes. Writes and errors use no-store.
Last-Modified/If-Modified-Since are not implemented; use ETag. HTTP status codes
are authoritative. REST errors use Problem Details, with errors for validation;
OAuth retains protocol error format. Missing/invalid credentials return 401;
inactive accounts or insufficient permissions return 403. Operation-specific
404 responses protect unavailable resources.

CORS exposes ETag, Link, Location, Retry-After and WWW-Authenticate to Bearer
clients without website cookies. Per-address minute limits: 60 REST calls,
30 token exchanges and 10 registrations. Federation services and URL protection
remain those of OpenBook; local success does not guarantee remote delivery.
Clients must treat content, names and URLs as untrusted data.

## Deployment preparation

Install Composer dependencies and apply OAuth migrations using
`php artisan migrate --force`. Signing keys are generated automatically on first
OAuth use and stored encrypted in the database with APP_KEY. No manual key
command or persistent OAuth key files are needed. Back up the database together
with APP_KEY and retain both across deployments: changing or losing the key pair
invalidates existing access tokens, and losing APP_KEY prevents decryption.
Unreadable existing keys cause an error rather than being replaced automatically.
For production, use APP_ENV=production, APP_DEBUG=false, an HTTPS APP_URL and
secure session cookies (SESSION_SECURE_COOKIE=true). Refresh configuration and
route caches after deployment: an old route cache may still omit the REST routes.
No new mandatory Redis, permanent worker or external service is introduced.

```sh
php artisan passport:client --public --name="Example application" --redirect_uri="https://client.example/callback" --no-interaction
```

Paw: OAuth 2 / Authorization Code, registered client_id, empty secret,
scope api and PKCE S256. Callback must match registration. A client without
PKCE support cannot complete this flow automatically.

## Next sprint

A second PR is planned to extend Comment, Community, Event, Notification and
Hashtag resources and their relationships. Media upload and complex post writes
need dedicated contracts; they are not implemented by this sprint.
Date-based caching, search, polls, messaging and further settings remain to be
defined.

## Email verification

Unverified accounts can authorize applications, obtain and refresh tokens, read
resources, follow people, like and reshare posts. Creating a post requires a
verified email address. Otherwise `POST /rest/v1/posts` returns HTTP 403 with
`application/problem+json` and `code: "emailVerificationRequired"`. After confirming
the email through the website, the same Bearer can create posts without another
authorization. Existing post editing and deletion permissions are unchanged.
