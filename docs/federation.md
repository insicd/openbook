> Documentation: [English](README.md) · [Italiano](README.it.md)

# Federation

### Federation (Phase 3)

Every local Actor is now reachable from the Fediverse through the standard
ActivityPub endpoints, all served **without session or CSRF**
(`routes/activitypub.php`, loaded outside the `web` middleware group), as
required by protocols meant to be consumed by other servers rather than
browsers:

- **Discovery**: `/.well-known/webfinger?resource=` (resolves `acct:utente@dominio`
  or the Actor's canonical URL) and `/.well-known/nodeinfo` + `/nodeinfo/2.1`
  (instance metadata and aggregate usage statistics, with no personal data). The
  NodeInfo document always declares `software.name: "openbook"`, the technical
  version (`config('openbook.version')`) and a link to `software.homepage`, so
  Fediverse tools that read NodeInfo correctly recognize the software behind
  the instance (not a fork/derivative of other platforms). For the same reason
  the User-Agent of outgoing requests
  (`config('openbook.federation.user_agent')`) reports the real software
  version, not a fixed value disconnected from it. These public endpoints (plus
  the canonical profile/post/comment below, see content negotiation) also
  expose the CORS header `Access-Control-Allow-Origin: *` (`config/cors.php`):
  they are public documents by definition, and without that header a browser
  would block cross-origin reads by client-side federated-software verification
  tools.
- **Content negotiation**: profile (`/@utente`), post (`/posts/{uuid}`), and
  comment (`/comments/{uuid}`) pages return HTML to a browser and an
  ActivityPub document (`Person`/`Note`/`Tombstone`) when the `Accept` header
  requests `application/activity+json` or `application/ld+json`. Deleted
  content is represented as a `Tombstone` rather than disappearing silently.
- **Collections**: each user's `outbox`, `followers`, and `following` are
  exposed as paginated `OrderedCollection`/`OrderedCollectionPage`; the outbox
  wraps public and unlisted posts in `Create` activities.
- **Inbox**: each user has a dedicated inbox (`/users/{utente}/inbox`) and the
  instance has a shared inbox (`/inbox`). Incoming requests are authenticated
  with **HTTP Signatures** (Cavage draft, `rsa-sha256`: verification of
  `Signature`, body digest, maximum skew of the `Date` header, match between
  the signing Actor and the activity's `actor` field, with a key-refresh
  attempt in case of rotation), validated in minimal form (content-type, size,
  JSON depth) and **deduplicated** via a unique constraint on
  `remote_activity_uri`. Valid activities are stored raw in `inbox_items` with
  status `pending`: their **semantic processing** happens outside the HTTP
  cycle (see [Social federation](federation.md#social-federation-phase-4) below), so a
  delivering peer is never blocked waiting for heavy processing.
- **Fetching remote Actors**: `RemoteActorResolver` downloads, validates, and
  caches locally the `Person` document of a remote Actor (needed to verify
  incoming signatures, for remote search, and to resolve actors cited by
  activities); every outgoing fetch goes through `SafeHttpClient`, which
  applies `SsrfGuard` to reject non-public URLs (private, loopback, reserved
  IPs), requires HTTPS in production, limits redirects/timeout/response size,
  and blocks *DNS rebinding* by pinning the connection to the already-validated
  IP (`CURLOPT_RESOLVE`). The same protection also applies to **outgoing**
  delivery requests (`SafeHttpClient::post()`), which never follow a redirect
  (the HTTP signature is computed on the exact destination URL).
- **Mastodon-compatible relays**: administrators can configure relay inboxes
  from **Control panel → Relays**, explicitly enable receiving and/or
  publishing, and start or stop the subscription. Once accepted, Openbook
  accepts public activities transported by that relay and adds its inbox to
  the delivery fan-out for local public posts, comments, events, and event
  comments. Relay traffic uses the normal ActivityPub queues, signatures,
  retry/backoff policy, domain blocks, and duplicate protection; no additional
  worker or cron entry is required. The administration page reports the last
  successful exchange and the latest terminal delivery error. Relays can
  generate a high volume of public content, so enabling one is always an
  explicit instance-administrator decision. The normal periodic cron remains
  sufficient for small installations; with a busy relay, separate permanent
  workers are recommended so that incoming processing and slow remote
  deliveries cannot block each other:

  ```bash
  php /path/to/openbook/artisan queue:work --queue=inbox --sleep=1
  php /path/to/openbook/artisan queue:work --queue=delivery --sleep=1
  ```

  The workers can safely run alongside `openbook:cron`, but must be restarted
  after each deployment so that they load the updated application code.

- **Actor-based instance sources**: the same Relay panel can subscribe to a
  remote ActivityPub `Application`/`Service` Actor, as exposed by software such
  as Mobilizon or Gancio. In this mode administrators can enter its federated
  identity (for example `@relay@instance.example`) or its full Actor URI, not
  the inbox URL: Openbook discovers, resolves and validates the Actor, follows
  it and imports public objects transported through its `Announce` activities.
  Remote applications may likewise follow Openbook's technical `/relay` Actor to
  receive `Announce` activities for locally produced public posts, comments,
  events, and event comments. Unlisted, followers-only, direct, remote, and
  private-community content is excluded. Technical subscriptions and
  announcements do not create user notifications, social boosts, or reaction
  counters. The Actor exposes paginated `/relay/outbox`, `/relay/followers`,
  and `/relay/following` collections for discovery and backfill.

- **LitePub-compatible relays**: relay hubs used by Pleroma/Akkoma and
  compatible software can be configured by federated Actor identity or full
  ActivityPub URI. Openbook performs the Actor Follow handshake, records the
  reciprocal Follow required for outgoing fan-out, and transports local public
  content through technical `Announce` activities. The administration page
  distinguishes an accepted subscription that is still waiting for the
  reciprocal Follow. Disabling publishing or unsubscribing stops new fan-out
  immediately, including deliveries that were already queued; the normal
  visibility, domain-block, deduplication and loop-prevention rules continue to
  apply. Unlisted, followers-only, direct, remote and private-community content
  is never published to a LitePub relay.

### Social federation (Phase 4)

Activities accepted in the inbox (Phase 3) are now **processed**, and relevant
local actions are **delivered** to the remote servers involved: federation is
finally bidirectional.

- **Inbox processing**: each `InboxItem` with status `pending` is queued on
  `ProcessInboxActivityJob` (`inbox` queue) immediately after receipt
  (`InboxController::receive()`, after commit). `InboxActivityProcessor`
  interprets the activity and produces the corresponding domain effect
  **always reusing the same application services as the local path**
  (`FollowManager`, `ReactionManager`, `AnnounceManager`), so the two paths
  stay consistent:
  - `Follow` toward a local Actor creates the row in `follows` (`pending` or
    `accepted` depending on `manuallyApprovesFollowers`) and, if accepted
    immediately, replies with an `Accept`;
  - `Accept`/`Reject` complete a `Follow` originated by this instance toward a
    remote Actor;
  - `Undo` (of `Follow`, `Like`, or `Announce`) cancels the corresponding
    relation or reaction;
  - `Like`/`Announce` on a local post or comment update counters and generate a
    notification, exactly like a local like/share;
  - `Create`/`Update` with a postable object (`Note`, `Page`, `Article`,
    `Video`, `Image`) cache the remote post or comment locally (tables
    `posts`/`comments`, identified by the `uri` column), but **only if
    relevant** to this instance (the author is followed by a local Actor, the
    Note replies to content we already know, or it explicitly mentions a local
    Actor): no remote content is stored "at random". HTML content is reduced to
    plain text (`RemoteContentSanitizer`), preserving `<a href>` as
    `[label](url)`; images in `attachment` remain as remote URLs in the
    gallery. Then it goes through the same safe rendering pipeline as local
    posts;
  - `Update` with a `Person`/`Group` object (another server notifying a change
    to one of its users' profiles) updates the local cache of the remote Actor
    directly (`actors`/`actor_keys`/`actor_endpoints`) by applying the embedded
    document, with no extra HTTP fetch; accepted only if the id declared in the
    document matches the signing Actor;
  - `Delete` marks a local post/comment or its remote cached copy as deleted,
    exactly like a local deletion (never a physical row delete, to preserve the
    id).
- **Outgoing activity delivery**: `ActivityDelivery` computes the set of
  destination remote inboxes (deduplicated on `sharedInbox` when several
  followers live on the same server) and queues a `DeliverActivityJob` for each
  (`delivery` queue, `afterCommit()`). Each job signs the activity with the
  sending local Actor's private key and sends it with `SafeHttpClient::post()`;
  a temporary failure (network error, 5xx response) is retried with increasing
  backoff (1, 5, 15, 60, 360, 1440 minutes, configurable), while a permanent
  error (SSRF violation, missing private key) fails immediately without retry.
  It is wired into every point where a local Actor performs a federatable
  action: `FollowManager` (`Follow`/`Accept`/`Reject`/`Undo`),
  `ReactionManager` (`Like`/`Undo`), `AnnounceManager` (`Announce`/`Undo`,
  delivered both to the sharer's remote followers and to the original author if
  distinct), `PostComposer`/`PostController` (`Create`/`Delete`), and
  `CommentComposer`/`CommentController` (`Create`/`Delete`, always delivered
  also to the parent content's author as a direct recipient). Messages with
  "direct" visibility are delivered only to explicitly mentioned Actors, never
  to all followers.
- **Queue and cron**: the queue uses Laravel's database driver (`jobs` and
  `failed_jobs` tables, already present from the installer), consistent with
  shared-hosting constraints (no permanent process, no Redis/RabbitMQ). The
  `openbook:process-inbox` and `openbook:deliver` commands process the `inbox`
  and `delivery` queues respectively with `--stop-when-empty`, so they exit on
  their own instead of listening indefinitely; `openbook:cron` invokes both in
  sequence, splitting a configurable maximum time budget, and is the command
  meant to be scheduled (see [Cron and periodic tasks](configuration.md#cron-and-periodic-tasks)).
- **Search**: the Search page (`/cerca?q=...`, GET form) has two paths.
  If the query is a federated address (`utente@dominio`, with or without a
  leading `@`, `acct:...`, or a profile URL), it is resolved locally if the
  domain matches this instance, otherwise via WebFinger + fetching the Actor
  document (`RemoteActorResolver::resolveByHandle()`), then redirected to the
  profile. For a keyword, phrase, or username without domain,
  `PeopleSearchQuery` finds known local and cached remote people by username
  or display name; local biographies are also searchable. People results
  respect each account's `discoverable` setting. `LocalSearchQuery` separately
  finds posts and comments of local Actors (visibility and FEP-5feb
  `indexable` respected), visible local or cached remote events, and hashtags.
  The header autocomplete suggests discoverable people and hashtags, but does
  not fetch unknown Actors or suggest events. A leading `@` is optional for
  people queries; content queries retain the original text.
  No Elasticsearch: case-insensitive LIKE with escaped wildcards, configurable
  limits (`OPENBOOK_SEARCH_MIN_LENGTH`, `OPENBOOK_SEARCH_PER_SECTION`). A
  resolved remote Actor has a convenience profile page (`/attori/{id}`, never a
  canonical ActivityPub identifier) with statistics, optional biography, and a
  follow button that starts the real `Follow`/`Accept` flow. Across the
  interface (post cards, comments, notifications) authors are now shown via
  `Actor::displayName()`/`Actor::avatarUrl()`/`Actor::profileUrl()`, which work
  identically for local and remote actors. The profile page also fetches
  (`RemoteOutboxFetcher`, cache with a separate TTL in
  `actors.posts_fetched_at`) recent public posts from the Actor's real outbox
  (with fallback to the Atom feed if the outbox is a stub, typical of Pixelfed),
  so their content can be shown even if no local Actor follows them yet:
  without this step a newly discovered profile would often have no posts,
  because the inbox only caches content already considered relevant (see
  [known limitations](roadmap.md#known-limitations) below).
- **Follower/following lists**: the "Followers" and "Following" counters on
  every profile (local or remote) are links to a paginated page with the real
  list (`FollowListQuery`), shared between local profiles (`/@utente/follower`,
  `/@utente/seguiti`) and remote Actors (`/attori/{id}/follower`,
  `/attori/{id}/seguiti` with a redirect to the local profile if the Actor
  turns out to belong to this instance). Each row shows a follow/unfollow
  button consistent with the visitor's real status
  (`FollowManager::statusMapFor()`, a single query for the whole page). The
  "Communities" counter on the profile counts the Groups (local or remote) the
  user has joined. Profiles also expose **Posts** / **Photos** tabs (roll of
  images attached to visible posts).

### Community (Phase 5)

Communities are ActivityPub Actors of type `Group`:

- **Local**: creation from the UI, slug `/c/{slug}`, WebFinger `nome@dominio`,
  membership (Follow/Accept), members' post wall, outgoing Group Announce,
  private communities with approval, delegated moderators. List at `/community`
  with a **Local** / **Remote** switch.
- **Remote** (Lemmy, Friendica, …): search `nome@dominio`, federated join,
  profile `/attori/{id}` with a composer for members, ingestion of
  Announce/Page (FEP-1b12). Local Actor URIs use the Mastodon scheme
  `/users/{username}` for compatibility (Lemmy rejects ids with
  percent-encoded `@`).

### Interoperability and remote media (Phase 6)

Besides `Note` and `Page`, the inbox/outbox accept `Article` (WordPress
ActivityPub, WriteFreely), `Video` (PeerTube, including with `attributedTo`
Person+Group), and `Image`. Remote image attachments stay as https URLs in
`media.remote_url` (gallery and profile photo roll) without download onto the
instance. If the outbox is a stub (typical of Pixelfed: only `totalItems`), the
remote profile falls back to the Atom feed `{actor}.atom`. For Wafrn (empty
outbox) the public `/api/v2/blog` API is used. **Threads** (Meta) does not
expose posts in the ActivityPub outbox: on the remote profile you can only see
content already received in the inbox after a Follow (and only if the account
has enabled sharing to the Fediverse). The World section suggests remote
accounts to discover and, beyond the first 5, the full list at `/mondo/scopri`
with infinite scroll. SSRF, domain blocks, and HTTP signatures remain the
baseline security constraints.

### Federated events

Openbook recognizes ActivityStreams `Event` objects received through signed
`Create` and `Announce` activities and keeps them separate from timeline
posts. The public **Events** section (`/eventi`) provides upcoming and past
event lists, local detail pages, location and time-zone information, media,
hashtags, search results, remote counters, and federated comment
threads. Visibility follows the event audience: public and unlisted events can
be opened by link, while followers-only and direct events require a recorded
local recipient. Deleted events remain as tombstones instead of becoming an
ambiguous 404 for users who were allowed to see them.

Authenticated users can create public or unlisted events from the Events
section or from their profile. The composer supports a cover image, Markdown
description, explicit time zone, physical/online/hybrid location, hashtags,
mentions, and open, moderated, or external participation. Local events can be
edited, cancelled, or deleted; Openbook publishes the corresponding
`Create(Event)`, `Update(Event)`, and `Delete(Event)` activities and exposes
them in both the Actor outbox and its dedicated `events` collection.

Users can send `Like`/`Undo(Like)` as an expression of interest and, where
supported by the origin, `Join`, `Undo(Join)`, or `Leave` for an RSVP. Local
organizers receive notifications for new participants and can accept or reject
moderated requests. Event comments and nested replies are federated as `Note`
objects whose `inReplyTo` points to the Event or parent comment; comments also
support attachments, likes, notifications, and tombstone deletion. An event
can be shared in a private Openbook conversation only when the recipient
already belongs to its audience; sharing never grants access to restricted
content. Events marked `sensitive` keep descriptions and media behind an
explicit reveal control. Hashtags used by recent public/unlisted events also
contribute to the trending ranking alongside post hashtags.

No additional worker is required: event activities use the existing inbox and
delivery queues processed by `openbook:cron`. After deploying support for a
new inbox object type, an administrator may retry retained rows previously
classified as `ignored` with:

```bash
php artisan openbook:reprocess-inbox
```

The command requeues every retained ignored inbox item and remains safe to run
more than once; unsupported activities simply return to the ignored state.

Not yet part of the mature product: a real recipient system for direct
messages (beyond mentions), and advanced federation-debug tools (beyond the
admin queue panel).
