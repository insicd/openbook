# REST API draft

> [Italiano](README.it.md) · [OpenAPI specification](openapi.json) · [Client integration](clients.md)

All REST content endpoints require an active user and an OAuth Bearer token,
including content publicly readable on the website. A website session cookie
alone does not authorize REST calls. This prototype is limited to local/testing.

- `GET /rest/v1/instance` returns general instance metadata.
- `POST /rest/v1/posts` creates a text post from NewPost and returns Post.
- `GET /rest/v1/posts` returns an array of Post objects from World or the home timeline.
- `GET /rest/v1/posts/{postId}` returns a single Post.
- `PATCH /rest/v1/posts/{postId}` edits an owned simple post using PatchPost.
- `DELETE /rest/v1/posts/{postId}` deletes an owned local post.
- `PUT /rest/v1/posts/{postId}/like` adds your like.
- `DELETE /rest/v1/posts/{postId}/like` removes your like.
- `PUT /rest/v1/posts/{postId}/announce` adds your manual announce.
- `DELETE /rest/v1/posts/{postId}/announce` removes your manual announce.
- `GET /rest/v1/me` returns Me with myPerson, the authenticated account as a full Person.
- `GET /rest/v1/me/posts` lists your published posts, excluding announces.
- `GET /rest/v1/me/follow-requests` lists pending requesters.
- `PATCH /rest/v1/me/follow-requests/{personId}` accepts a request.
- `DELETE /rest/v1/me/follow-requests/{personId}` rejects a request.
- `GET /rest/v1/persons/{personId}` returns a Person object.
- `GET /rest/v1/persons/{personId}/follow` reads your outgoing follow state.
- `PUT /rest/v1/persons/{personId}/follow` follows or requests approval.
- `DELETE /rest/v1/persons/{personId}/follow` unfollows or withdraws the request.
- `GET /rest/v1/persons/{personId}/posts` returns the profile's posts and announces.
- `GET /rest/v1/persons/{personId}/followers` returns an array of PersonReference.
- `GET /rest/v1/persons/{personId}/following` returns an array of PersonReference.

PersonReference contains personId, personUsername, personDomain, personName and
an optional personAvatar. Person lists use after, limit (default 20, maximum 100),
sort=personId and direction=asc (default) or desc. Link with rel="next" supplies
the next URL. Remote relationship lists may be incomplete; these lists do not
trigger remote fetches.
Locally suspended profiles and unavailable resources return 404.

Person supports the case-sensitive types `person`, `application`, `feed` and
`group`. PersonReference uses the same compact fields for every type. Local
private community profiles remain readable; their relationship collections
return 403 and private community identities are omitted from relationship lists.

## Instance metadata

GET /instance returns a direct Instance object containing instanceName,
instanceDomain, instanceUrl and instanceDescription. The description is an
empty string if not configured. instanceIcon is optional and omitted when
there is no custom icon; otherwise it is an absolute URL for the 512-pixel PNG.
The base URL and domain identify the configured instance, independently of
the request Host. Description content is returned without additional rendering.

The endpoint requires the same active Bearer identity as other REST endpoints.
It includes no statistics, contacts, software details, limits or administrative
settings. Responses use private caching, Vary: Authorization and ETag with
If-None-Match; metadata changes invalidate the representation. Access checks
precede 304. There is no creation or modification operation for Instance.

## Creation schemas

Creation inputs use `New<Entity>` schemas, separate from response entities:
for example, NewPost is the creation counterpart of Post. Field names and
grouping are preserved where applicable; required and nullable constraints are
defined independently. Server-generated and read-only fields are not inputs.

NewPost currently accepts only postContent and postVisibility, both required
and non-null. postContent must contain non-whitespace text and respect the
instance's post length limit. postVisibility accepts public, unlisted or
followers, with no default. Additional fields introduced later will be optional.
POST /posts accepts NewPost as a JSON object and returns 201 with the full
Post and a Location header pointing to its REST detail. Creation responses use
no-store. Unknown properties are rejected with 422, including fields reserved
for future features. Missing or invalid fields also return 422; malformed JSON
returns 400 and a non-JSON content type returns 415. Authentication is required
before validation.

Hashtags, known mentions, notifications and federation follow website publishing.
201 confirms local publication; it does not confirm remote delivery. Repeating
a POST may create another post, and an error does not guarantee that nothing
was created. The text limit follows the instance configuration, just like the
website. Media, polls, quotes, communities, location and direct posts are not
yet supported by this operation.

```json
{
  "postContent": "Hello from my application! #OpenBook",
  "postVisibility": "public"
}
```

## Your posts

GET /me/posts returns a direct array of Post objects authored by the Bearer
user. Deleted posts and private conversation messages are excluded; your
restricted posts, community posts and quotes are included. Announces of other
authors' posts are excluded. An authored post that is also announced appears
once in publication order, without postAnnounce or postTimelineAt.

Supports limit (default 20, maximum 100), sort=postPublishedAt and direction=desc.
The next Link includes after, the last received postId, and all other navigation
parameters. The reference must still belong to this collection: deleted or
excluded posts and other users' posts return 422; restart from the first page.
engine, afterAt, cursor and page are not supported. No remote content is fetched.
Private caching with ETag and Vary Authorization applies, with authentication
before any 304 response, as with other REST lists.

## Post list

`GET /rest/v1/posts` returns a direct array of Post objects. `engine=world` is
the default, using the World section's selection, exclusions and ordering.
`engine=timeline` uses the authenticated user's home feed, including announces.
The representation includes text, author PersonReference, content warning, media,
location, counters and user interactions; polls, quotes and other related objects
are not yet represented.

Both engines accept limit (default 20, maximum 100) and direction=desc. World
uses sort=postPublishedAt and after (the last postId). Unknown or unreadable
World reference posts return 422; restart from the first page.
Timeline uses sort=postTimelineAt and the pair after + afterAt, containing the
last postId and its postTimelineAt. Follow the next Link, which supplies all
parameters. No server-side pagination state is retained. cursor and page are
not supported.

Timeline entries preserve the original postAuthor and postPublishedAt.
postTimelineAt is the date used for ordering; postAnnounce, when present,
contains announceAuthor (PersonReference) and announcePublishedAt. Each post
appears once with its latest relevant announce; self-announces and group
distribution are included. postAnnounce is optional for either engine and is
omitted when no announce context is selected. World currently lists original
posts without selecting an announce context and omits postTimelineAt. Pagination is not a snapshot: feed changes can affect
subsequent pages.

Text is not rendered to HTML by this API. Optional fields without values are
omitted, including title, source and emojis.

## Editing and deleting posts

PATCH /posts/{postId} accepts a PatchPost JSON object. postContent and
postVisibility are both optional, but at least one must be present. Supplied
values are non-null and follow NewPost constraints. Omitted properties retain
their values, including any existing title, content warning and language.
An empty object or invalid/unknown properties return 422; malformed JSON returns
400 and a non-JSON content type returns 415.

This initial editing scope includes owned, published local posts without
attachments, location, polls, communities or quotes, with a public, unlisted or
followers audience. Unsupported posts return 409 without changes. Success
returns 200 with the updated Post; hashtags, mentions, notifications and
federation use the website editing flow.

```json
{
  "postContent": "An updated message. #OpenBook"
}
```

DELETE /posts/{postId} deletes an owned local post, including posts outside
this simple editing scope. It returns 204 with no body on success. An already
deleted post returns 404 at lookup, before deletion authorization or processing.
Subsequent GET returns 404. Private messages,
unknown or unavailable posts, and other users' posts return 404. Admin roles
do not permit acting on other users' posts through these operations.
Both mutations require an active Bearer identity and return no-store responses.

## Post details and likes

GET /posts/{postId} returns the same Post representation used by the lists.
Only published posts visible to the authenticated user are available; private
messages are excluded. Remote replies and reaction totals are refreshed using
the website's existing cache policy. Remote failures fall back to cached data.
Visibility is checked again after refresh, before returning data or 304.
Details omit postAnnounce and postTimelineAt because no feed context is selected.

PUT /posts/{postId}/like and DELETE /posts/{postId}/like require no body.
PUT returns 201 with Location on creation, or 204 if already liked. DELETE
returns 204 even if already absent. Both return an empty body and no-store;
counts, notifications and federation follow the website behavior. Unavailable
posts return 404; liking a remotely suspended author returns 403.

## Announces

PUT /posts/{postId}/announce requires a published, visible public or unlisted
post outside private communities. It returns 201 with Location when a manual
announce is activated, including promotion of an implicit quote share, or 204
if already active. Visible restricted posts and remotely suspended authors
return 403; unavailable posts and private messages return 404.

DELETE on the same URL returns 204 when your manual announce is removed or
already absent on a visible post. An existing manual announce can also be
removed after the post becomes invisible or restricted, or its remote author
is suspended. Once removed, repeating DELETE for an invisible post returns
404. Deleted posts and private messages always return 404. Published quotes
and their implicit shares are preserved: postMyInteractions.postAnnounced may
remain true while postDirectAnnounced becomes false. Use the latter for the
announce button; postAnnounce represents a feed entry's context instead.

Both operations require no body, return an empty body and no-store, and act
only on the Bearer identity. Counts, notifications and federation follow the
website behavior without duplicates on repeated requests. No new entity is
required.

## Following people

GET /persons/{personId}/follow returns Follow for the direction
Bearer user → Person identified in the path:

```json
{"followStatus": "pending"}
```

followStatus is required, non-null and one of none, pending or accepted.
none means no outgoing follow or request; pending means awaiting acceptance;
accepted means currently followed. Rejection or withdrawal returns to none.
The reverse relationship and extra preferences are not included. GET returns
200 even for none, with private caching, ETag and Vary Authorization. It does
not fetch remote profiles or retry follow requests.

PUT on the same path requires no body and returns Follow: 201 with Location
on creation, 200 when already present. Open local accounts and feeds are
accepted immediately; protected local accounts require approval. Remote
non-feed identities remain pending until acceptance arrives. Repeating PUT
does not duplicate the relation, but may retry delivery of a pending remote
request or accept a pending local request if the profile has become open.
The response body always describes the resulting state.

DELETE withdraws a pending request or removes your outgoing follow: 204 with
no body even when already absent on an available Person. Both writes use
no-store. Self-follow and inactive targets refused by the follow operation
return 422. A suspended remote identity can be read and unfollowed, but PUT
returns 403. Unavailable identities return 404 for all methods, even if an old
follow remains. Community identities retain membership permissions: owners
cannot remove their own membership. Your own relation to a private community
can be read without disclosing its member list.

## Person posts

GET /persons/{personId}/posts returns an array of Post objects matching the
profile's Posts tab, including authored posts and announces visible to the
current user. This is where recent remote content is imported when due under
the existing cache policy; GET /persons/{personId} remains limited to profile
metadata and relationship totals. RSS/Atom profiles follow the configured feed
refresh interval. Remote failures preserve available cached results. Imported
content is a partial local view, not the complete remote history.

Entries include postTimelineAt and, where applicable, postAnnounce identifying
the profile's announce. Ordinary profiles order by announce/publication time;
groups retain original publication order. Use sort=postTimelineAt (default),
direction=desc and the next Link with after + afterAt. Page size matches the
website feed configuration; limit is not selectable. limit, cursor, page and
engine parameters return 422. The client should follow Link without adding them.

Local private community walls require membership or ownership. Account access,
profile availability and visibility are checked before returning data or 304,
including after import. Responses use private caching and Vary: Authorization.

## Person detail refresh

GET /persons/{personId} refreshes remote metadata and follower/following totals
when their caches expire, using the website's refresh policy. Available cached
data is returned on remote failures. This operation does not import post lists.
Availability is checked again after refresh, before an eventual 304. Local
profiles and feeds do not trigger these remote profile fetches. Person-reference
lists and the world/home feeds remain based on locally available data.
Conditional reads use ETag/If-None-Match;
Last-Modified is not emitted by the current REST endpoints.

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
token endpoints without website cookies. ETag, Link, Location, Retry-After and
WWW-Authenticate are exposed to JavaScript. REST errors use Problem Details;
OAuth errors retain the standard OAuth format. Missing/invalid credentials return
401 and inactive accounts return 403. ETag/If-None-Match supports 304 after all
access checks. Last-Modified remains unimplemented. Limits per client address:
60 REST calls, 30 token exchanges and 10 registrations per minute, independently.

## Incoming requests

GET /me/follow-requests returns PersonReference objects for pending requesters
to the authenticated user. Supports after, limit (20/100), sort=personId and
direction=asc/desc; next Link, ETag and private caching match person lists.
No remote fetch occurs. PATCH on the detail requires {"followStatus":"accepted"}
as a PatchFollowRequest JSON object and returns 204. DELETE rejects the request
and returns 204. Missing, already handled or other users' requests return 404;
DELETE does not remove an accepted follower. Community requests remain in the
native interface. Writes use no-store and staff roles grant no delegation.
