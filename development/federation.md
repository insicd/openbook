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

### Local profile fields

The profile editor has separate Link and Additional information sections with
add/remove buttons and a shared limit of eight fields. Labels accept up to 50
characters, HTTP(S) URLs up to 255, and plain text up to 1,000. When reopening
the editor, valid HTTP(S) values appear under Link; other values appear under
Additional information. Saving preserves each section's order, with links first.

Local fields remain in `profiles.links`, as `label`/`value` pairs; existing
`label`/`url` records remain readable without a data migration. The public
profile shows labels and values below the biography. Plain text is escaped,
without interpreting Markdown or HTML.

Fields are published in the Actor's `attachment` array as `PropertyValue`
entries, using the schema.org context mapping compatible with Mastodon.
HTTP(S) values become HTML links; other values become escaped text, preserving
line breaks. The same representation is included in profile `Update`
activities sent to remote followers. Removing all fields publishes an empty
array so peers can clear their cached fields. Existing profiles expose their
fields on the next Actor fetch or profile update; no bulk delivery is triggered.
Publishing `rel="me"` does not mean Openbook has verified the link.

### Remote profile fields

Openbook imports Actor `attachment` entries of type `PropertyValue` into
`actors.links`, as ordered `label`/`value` pairs. Text and HTTP(S) links pass
through the existing remote-content sanitization and rendering pipeline;
remote HTML, inline media and custom emoji effects are not reproduced.
The remote profile page shows these fields below the biography. Link
verification is not implied by a remote claim or by a `rel="me"` attribute.

The application keeps up to 16 valid fields, with labels up to 100 characters
and normalized values up to 1,000 characters. Empty or malformed fields and
other attachment types are ignored; duplicate labels retain their order.
Each valid Actor document replaces the cached list, including clearing it
when `attachment` is absent or has no usable fields. This applies both to
ordinary Actor refreshes and incoming profile `Update` activities. Visiting
an active remote profile also checks the Actor document cache, using its
normal TTL (24 hours by default); a failed fetch keeps the cached profile
available. Existing cached profiles acquire fields on their next normal
refresh or Update; there is no bulk fetch or separate field request. Local fields remain in
`profiles.links`, with the editor and publication behavior described above.

### Remote account suspension

A valid remote Actor document's `suspended` flag is stored in
`actors.remote_suspended`, independently of local moderation in `actors.status`.
Both normal refreshes and incoming profile Updates use this field. A valid
complete document without the flag, or with `false`, clears remote suspension;
failed fetches and invalid documents leave the cached state intact.

The remote profile displays an originating-server suspension notice. New local
follows, messages, likes, shares/quotes, replies and event participation are
blocked, including replies to a suspended author's comment under someone else's
post or event. UI controls and application services enforce this restriction.
Automatic sharing pauses without clearing its setting. Existing posts, comments,
events and follow/reaction records are retained with their existing visibility;
users can still withdraw earlier follows, reactions and event participation.
Viewing content, reporting it and copying its URL remain available.

When the origin declares the account active again, interactions resume. Remote
refreshes never clear a locally blocked, suspended or deleted status. This flag
does not replace ordinary Delete/Undo processing or introduce an inbound ban.
The schema update requires running `php artisan migrate` as usual; no new service
or worker is required.

### Remote account migrations

An authenticated remote `Move` generates a notification for each local
follower with an accepted follow and a notice on the old profile.
Openbook checks that actor and object match the authenticated sender. For a
remote destination it fetches the current profile; for a local Person it reads
the canonical identity, active User and aliases from the database. Both must
list the old account in `alsoKnownAs`. Failed fetches or invalid announcements
produce no notifications. Repeated copies of the same announcement are deduplicated.

The notification opens the new profile inside Openbook, where the user can
choose Follow. No follow is created or removed automatically; preferences,
previous posts, retention and visibility rules remain unchanged. An Update
containing only `movedTo` does not generate migration notifications.
Remote announcements may also target a local account prepared with an alias.
`AccountMoveTargetResolver` handles this check separately from informational
profile discovery; local aliases and state are read again under the Actor lock
before notifications are created. Communities and automatic migration chains
remain excluded.

`AccountAliasManager` manages up to ten aliases on the authenticated local
Person through the settings POST/DELETE routes. Handle/URI resolution uses the
existing protected remote resolver; removal does not fetch the old server.
Alias changes lock the Actor to avoid lost updates and publish a normal Actor
Update. `ActorSerializer` emits `alsoKnownAs`, including an empty list after
removal, using its existing ActivityStreams JSON-LD mapping. These are public
preparatory declarations, not proof of ownership or instructions to follow.
The previous server starts the Move; incoming Follow/Accept processing is
unchanged, including manual approval for protected accounts.

Normal updates to the old profile also import remote `movedTo` destinations:
Openbook looks up the destination Actor, fetches it if missing and stores the link in the
existing `moved_to_actor_id` field. This displays the notice and link without
generating notifications. Migration chains are not followed. A subsequent
verified Move can still notify followers; the informational link does not
replace the announcement.
The migration notice appears inside the card below the name and handle.
The globe beside the name displays the remote profile information on hover,
keyboard focus or click/tap.

The #116 schema requires running `php artisan migrate` as usual. The #117 arrival
stage reuses those columns and adds no migration or worker.

### Outgoing account migrations (#117)

`AccountMigrationService` verifies the destination with a fresh protected fetch,
saves it in the existing `actors.moved_to_actor_id` column and calls the ordinary
`ActivityDelivery::deliverToFollowers()` for the Actor Update and Move.
`ActivitySerializer::move()` generates an activity id when building the payload;
the standard delivery jobs retain that payload, including its id, across retries.

Source locking serializes confirmation and removal. The existing `movedTo`
relation supplies the destination for the Actor document and profile link.
`AccountMoveNotifier` shares the #116 local notification behavior with the
incoming handler, preserving follows. Repeat confirmation of the current
destination does not send another announcement.

Removing the indication clears `moved_to_actor_id` and sends an Actor Update
with explicit `movedTo: null`. Remote profile imports recognize that explicit
null; omission still preserves the previous link. The old account remains active
and can publish, receive follows, manage aliases and move again.

Rebuild or clear the route cache after updating. This stage adds no schema migration.

### Remote memorial profiles

The remote Actor's `memorial` flag is cached in `actors.memorial` (false by
default), using the same Actor fetch/refresh and incoming Update flow. A valid
document without the flag or with `false` clears it; failed fetches and invalid
documents preserve the cached value. Existing cached profiles acquire the flag
on their next ordinary refresh or Update, without a bulk fetch.

The remote profile displays a discreet “Memorial profile” badge near its name.
The flag is informational: it does not change visibility, follow approval,
messages, likes, comments or shares. Any remote suspension or local moderation
restrictions still apply independently. Creating memorial profiles for local
accounts is outside this feature. The added boolean column requires the usual
`php artisan migrate` and adds no service or worker requirement.

### Remote pinned posts

The Actor's `featured` field identifies its pinned-post collection. Openbook
stores the endpoint and a small ordered snapshot of imported posts. A “Pinned
posts” tab appears between “Posts” and “Photos and videos” only when at least one
post is visible to the viewer. It uses ordinary post cards and does not prepend
pins to the timeline.

The collection is fetched on profile visits using safe HTTP and signed requests
when available, with `OPENBOOK_POSTS_CACHE_TTL_HOURS` (default 6 hours).
At most 3 collection documents and 20 entries are read. Inline collections,
`first`/`next` pages, `items`/`orderedItems` and URI references are supported.
Only original public/unlisted posts of already-supported types are imported,
checking authorship and without notifications. Replies, private objects and
deleted posts are not recreated; private-community visibility still applies.

A valid empty snapshot removes pins from the tab without deleting the posts.
Errors or incomplete pagination preserve the last valid snapshot; failed
attempts are cached too. Incoming Add/Remove activities by the signer targeting
its own collection invalidate the cache for the next visit. Changing or removing
the Actor endpoint clears the previous snapshot. Existing cached profiles
discover the endpoint on their next ordinary Actor refresh.
Run `php artisan migrate` for the schema change; no new service is needed.
Managing pins for local profiles remains outside this feature.

### Remote post language

Openbook stores the declared language of the imported text in `posts.language`
when it is unambiguous. A `contentMap` with one usable BCP 47 key supplies the
language if its value exactly matches `content`, or if that value is actually
selected as the fallback when `content` is unavailable. Multiple languages,
conflicting texts, malformed or undetermined tags leave `NULL`; Openbook does
not detect the language of the text. Tags are lowercased without dropping
regions or scripts, with an application limit of 255 characters.

Without a map, an explicit `@language` in embedded contexts can label `content`.
The object's context can override the default inherited from the activity;
`null` clears it. Unknown remote contexts and redefinitions requiring JSON-LD
expansion are neither interpreted nor fetched: the default remains unusable
until an explicit context reset. Documents fetched over HTTP have a context
independent of the referring activity.

The rule is shared by inbox processing, outbox import and refresh, including
private messages stored as posts. Accepted updates can change or remove the
language; stale updates cannot. There is no historical backfill, and post
authorization and relevance rules remain unchanged.

Cards in feeds, post details and quoted posts display the language beside the
date, with an accessible “Language declared by the author” description. Names
follow the reader's interface language; regions and scripts are preserved when
the catalogue provides the full name, otherwise the code is shown. Presentation
uses Symfony Intl data without requiring the PHP `intl` extension. No label is
shown for missing languages or deleted posts. Composer language selection and
the profile's writing preference are planned for a subsequent phase.

The migration widens `posts.language` from 8 to 255 characters. Rollback is
refused while tags longer than 8 characters exist, preventing truncation.

The same extraction rules store the language in `comments.language`
(nullable, 255 characters), through both inbox processing and reply fetching.
Each upsert may change or clear it. Existing comment update ordering remains
unchanged: it does not apply the stale-version protection used for posts.
The language appears next to the comment date, using the same presentation
rules as posts. No backfill or language selection is added for comments; local
comments continue without a declaration. Event comments use a separate path.

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
    `Video`, `Image`, `Question`) cache the remote post or comment locally (tables
    `posts`/`comments`, identified by the `uri` column), but **only if
    relevant** to this instance (the author is followed by a local Actor, the
    Note replies to content we already know, or it explicitly mentions a local
    Actor; updates to cached polls are also relevant): no remote content is
    stored "at random". HTML content is reduced to
    plain text (`RemoteContentSanitizer`), preserving `<a href>` as
    `[label](url)`; images in `attachment` remain as remote URLs in the
    gallery. Then it goes through the same safe rendering pipeline as local
    posts. When saving a remote post, its publication timestamp is converted to
    the application timezone and capped at the current time. Updates with a
    future timestamp retain the cached publication date if it is already valid,
    so repeated imports do not push the post back to the top of the timeline.
    Existing future dates are corrected when the post is next saved;
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
  to all followers. Chat messages can also target active remote `Application`
  Actors with an inbox, as well as people; communities and the local technical
  `/relay` Actor are not chat recipients.
- **Queue and cron**: the queue uses Laravel's database driver (`jobs` and
  `failed_jobs` tables, already present from the installer), consistent with
  shared-hosting constraints (no permanent process, no Redis/RabbitMQ). The
  `openbook:process-inbox` and `openbook:deliver` commands process the `inbox`
  and `delivery` queues respectively with `--stop-when-empty`, so they exit on
  their own instead of listening indefinitely; `openbook:cron` invokes both in
  sequence, splitting a configurable maximum time budget, and is the command
  meant to be scheduled (see [Cron and periodic tasks](../docs/configuration.md#cron-and-periodic-tasks)).
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
  [known limitations](../docs/roadmap.md#known-limitations) below).
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
  inbound FEP-1b12 (`Create` Note/Page that mentions or addresses the Group:
  public communities accept the cite; private ones require membership),
  private communities with approval, delegated moderators.   Incoming `Follow` activities may include a `notify` flag: it is stored
  on the follow row. Mastodon keeps the Group bell locally and does not
  send it; a FEP-1b12 `Announce` is a reblog and does not ring that bell.
  New wall posts therefore also fan out a Group-signed original `Create`
  (unlisted Note at `/users/{group}/avvisi/{post}`) to every remote
  member, so Mastodon can notify whoever enabled the bell. The `/community`
  directory has **Your communities**, **Local communities**, and **Remote
  communities** tabs. The latter two also include joined Groups; all three
  lists are ordered by handle and load twenty rows at a time as you scroll.
  Membership actions update the row without reloading the page; pending
  requests to private local communities can also be cancelled.
- **Remote** (Lemmy, Friendica, …): search `nome@dominio`, federated join,
  profile `/attori/{id}` with a composer for members, ingestion of
  Announce/Page (FEP-1b12). Members can ring the bell on that profile to
  receive an in-app notification for every post the Group announces, and
  the preference is sent back as `Follow.notify`. Local Actor URIs use the
  Mastodon scheme `/users/{username}` for compatibility (Lemmy rejects ids
  with percent-encoded `@`).

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

### Local polls (#121)

Openbook persists polls and publishes them as `Question` through
existing post Create, Update, canonical GET and outbox paths. `PostComposer`
accepts `poll` containing `options` (a list of 2–6 distinct strings of at most
50 Unicode characters), `multiple` (boolean) and `duration_seconds` (integer
from 300 to 604,800). Duration starts at actual publication, including staged
videos; initial counts are zero.

Definition and visibility stay fixed after publication. Text corrections cannot
add recipients. Local communities, including private ones, are supported;
private conversations and publication to remote Groups are intentionally
excluded. Run `php artisan migrate` and refresh configuration/routes caches
after updating. `PostComposer` records durable publication or update work together
with the Question; `openbook:cron` now calls `openbook:process-polls` between
inbox processing and ordinary delivery. No additional cron configuration or
permanent worker is required. The canonical GET and outbox expose the current
Question independently of delivery progress.

The poll icon in the composer toolbar opens the answer panel, following the
same accordion behavior as the other advanced controls. It starts with two
answer fields; add options one at a time up to six, select multiple answers if desired and choose a duration (one day by
default). Options support 50 Unicode characters. The post body is the question.
Creation works with attachments, quotes and local communities; remote Group and
private-message composers stay outside this feature. Published polls expose
fixed definition/visibility in the editor while allowing text corrections.

Local cards and detail pages use the shared poll renderer and vote endpoint.
Results appear after voting or closure; “Preview results” also reveals aggregates
before voting and “Back to options” restores selection. This is a presentation
preference, not aggregate secrecy. Personal choices and vote date stay scoped
to the viewer; green confirmation and participant count share a row below
the options;
quotes stay read-only and content warnings wrap the poll. Before voting, the UI
explains that the author can inspect individual answers. Native HTML links and
forms remain functional without JavaScript. The optional enhancement confirms
votes in place and prevents double submission, with no result polling.
“Vote”, “Preview results” and “Answer details” (author only) share the action
bar. A small information icon on the right explains vote finality and author
access to individual answers: hover or open with click, keyboard and touch.
A known deadline appears in italics beside “Poll”; “Poll open” remains for
an unknown deadline.

The author can open “Answer details” on their own local poll. The dedicated page
has one option link per answer and 25 identifiable participants per page,
including remote accounts. Authorization checks exact ownership; administrator,
moderator and community owner roles grant no extra access. Deleted accounts
contribute only to an aggregate archived-answer count. The page uses private,
no-store responses; shared cards and ActivityPub never include voter lists.
Option links and previous/next page links also work without JavaScript.

`LocalPollVoter` collects definitive local ballots and federated Create/Note
answers. `LocalPollVoteIngester` checks signer, author, Note origin and canonical
Question target before comment or message handling. Current visibility, community
membership, account status and domain blocks apply to both entry points. Local
multiple selections are submitted together; federated multiple selections arrive
as individual Notes and can add distinct options until expiry. Duplicate Notes
or choices leave counts unchanged; a single-choice ballot keeps the first valid
processed choice. Counts, revision and pending federation work commit together,
with no HTTP requests during voting.

For embedded answers, the original authenticated inbox `received_at` decides
expiry, including retries and processing after closure; remote `published` does
not override it. URI-only answers must also be fetched before expiry because the
current document cannot prove its contents at envelope receipt. A timely queued
answer can update closed results while retaining closure. Processing rechecks
current permissions; an old receipt does not retain revoked access. Results are
available in the canonical Question immediately; federated distribution runs
through the cron described below.

The poll processor first records scheduled closures, then groups pending
revisions into Create/Update snapshots. `poll_distributions` holds at most one
active snapshot per poll, its revision and batch cursor. A batch commits delivery
jobs and cursor together through the `local_delivery` database connection; a
crash rolls back both, while already committed batches remain queued. Completing
a snapshot acknowledges only that revision; votes or edits accepted during the
fan-out retain pending work for a subsequent Update. Update IDs derive from the
revision and queued objects remain stable during retries. Active snapshots are
removed after fan-out, including snapshots for deleted posts. Rollback of this
table requires active distributions to finish first. An initial Create with
coalesced revisions is accompanied by an Update, ensuring peers that already
received a pre-cron Create also receive the latest results.

Destinations include authorized remote followers, community members, mentions
and voters, even voters who do not follow the author. Shared inboxes are
deduplicated before batching. Public relays retain ordinary Create/Update or
technical Announce behavior; restricted Questions never go to relays. Current
access and audience are rechecked by `DeliverActivityJob` before HTTP delivery.
Missing inbox metadata is resolved by delivery, with normal retries, outside
poll locks. HTTP and Linked Data signatures also happen at delivery time.

For a manual preparation pass, run `php artisan openbook:process-polls`; its
`--max-time`, `--limit` and `--batch-size` options bound elapsed time, batches
and destinations respectively. Ordinary `openbook:deliver` sends the jobs even
when the global queue uses `sync` or another database. The general cron combines
both steps. A closed Question can receive another results Update for timely
answers that were still in the authenticated inbox. Delivery timing follows the
cron and retry schedule, without remote polling.

The `polls`, `poll_options`, `poll_votes` and `poll_vote_choices` tables are
shared by local and remote polls. The new migration first renames the four
`remote_poll*` tables from #120, then adds local fields, preserving UUIDs,
nullable counts, options, snapshots, dates and queued votes. Previous migrations
remain unchanged. Rollback to the remote schema requires no local polls or
detached voters, preventing loss of data the old schema cannot represent. Deleted
accounts retain contributions with identity detached; keyed hashes preserve
deduplication without personal vote URIs in plain text. Raw inbox payloads
follow existing retention. Logical post deletion removes choices/options and
stops updates, keeping Question tombstone metadata; physical deletion removes
that metadata too. Queued Questions are rechecked before sending to prevent
deleted posts from being republished.

### Remote polls (#120)

Mastodon-shaped `Question` objects are imported through the existing inbox,
outbox, featured collection, Announce and post/replies refresh paths. Their
metadata lives in `polls` and `poll_options`; the post retains
its ordinary author, audience and moderation rules. Question replies are
excluded. Polls appear in feed cards and on the post page (`/posts/{post}`),
identified by `polls.post_id`. No dedicated polling or worker is introduced. Opening
the page reloads text, content warning and visibility after refresh, applying
the current audience before displaying content.

`oneOf` and `anyOf` preserve single/multiple choice, exact option names,
nullable option/voter counts, expiry and explicit closure. Missing counts
remain unknown; local choices do not alter remote results. The current
normalizer accepts 2–50 distinct options of up to 1,000 Unicode characters.
Malformed metadata is ignored without replacing a previously valid snapshot.
Option reordering preserves identity; renaming replaces it and leaves any
personal vote snapshot intact. An acquired closure remains closed.

Explicit remote `updated` versions take precedence; otherwise original inbox
receipt time and item ID determine replay order. Fetches use their local
observation time. Without a remote revision, original server ordering cannot
be inferred from delivery order: newer accepted snapshots may legitimately
reduce counts. Third-party embedded polls are verified at the canonical
endpoint. Vote-shaped Create/Note objects are skipped before becoming posts,
comments or messages, even when their target is unknown.

Apply migrations with `php artisan migrate`. Ordering metadata uses
`results_received_at`, without depending on retained inbox rows. Poll and remote
option rows omit automatic `created_at`/`updated_at`; publication lives in the
associated post's `published_at`, while personal votes retain their timestamps.
The corrective migration renames the earlier `observed_at` column and removes
the redundant timestamps, preserving cached polls, results and personal votes.
Historical inbox replay remains available through `openbook:reprocess-inbox`;
it requeues all ignored/pending activities, including ordinary content. Use
fixtures or a database copy for verification limited to polls.

Answers use native radio buttons or checkboxes; “Vote” immediately confirms
“Vote recorded” after commit and shows “Your answer” only to its owner.
The authenticated `POST /posts/{post}/vota` endpoint reuses the voting service and
checks visibility, expiry and option version. JavaScript updates the card after
the POST; ordinary HTML forms also work without JavaScript. Quoted polls are
read-only. Content warnings enclose the poll as well. Remote text and choice
snapshots are escaped.

Missing counts show “Results updating”. Percentages use the origin's voter total:
it must be positive and no option may exceed it; single-choice polls also require
all option counts and their sum to equal that total. Inconsistent counts remain
visible as received, without invented percentages. Removed or renamed personal
answers stay in a separate block with their original text.

The voting service commits personal choices and one `Create/Note` per choice
atomically. Each Note uses the exact option name, targets the Question URI and
is addressed only to its author. Persisted choice UUIDs keep activity IDs stable
across retries. Duplicate requests return the original immutable vote, even after
closure. The option version detects renames, reordering and mode changes; remote
counter updates do not invalidate the selection. Expiry and access are rechecked
under locks, and delivery rechecks visibility and moderation. Remote results are
never adjusted using local votes.

The internal `local_delivery` database queue uses the domain connection and the
existing `jobs` table, regardless of the global queue driver or
`DB_QUEUE_CONNECTION`. `openbook:deliver` consumes it and the configured delivery
backend within the existing time budget, avoiding duplicate workers when both
use the same table. The queue uses the existing jobs table; local poll processing
is included in the general cron. Regenerate configuration with
`php artisan config:cache` after updating
if configuration caching is enabled. Operators using only a custom worker for a
separate backend should also consume `local_delivery` or use `openbook:deliver`.

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
classified as `ignored`, and enqueue `pending` rows missing their jobs
(for example after a database import), with:

```bash
php artisan openbook:reprocess-inbox
```

The command enqueues `ignored` and `pending` items, excluding `processed` and
`failed` rows. With the database queue, then run
`php artisan openbook:process-inbox`; with `QUEUE_CONNECTION=sync`, processing
is immediate. This is not a dry-run and does not deduplicate existing queued
jobs: it is a manual recovery command. Jobs check for `pending` status before
processing; unsupported activities return to `ignored`.

Not yet part of the mature product: a real recipient system for direct
messages (beyond mentions), and advanced federation-debug tools (beyond the
admin queue panel).
