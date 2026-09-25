> Documentation: [English](README.md) · [Italiano](README.it.md)

## Architecture

The code is organized to **explicitly separate** the local application domain
from the ActivityPub representation and federation mechanics, as required by
the project design:

```
app/
  Domain/            # Local application domain
    Accounts/
    Profiles/
    Posts/           # Posts, attachments, hashtags, mentions, text rendering
    Comments/        # Comments (top-level and nested replies)
    Events/          # Federated events, locations, RSVP and comment threads
    Reactions/        # Likes and shares (Like/Announce at the local level)
    SocialGraph/      # Follows between Actors (local and remote)
    Notifications/    # Local notifications (not federated)
  Federation/        # Everything related to ActivityPub
    Actors/           # Actors (local and remote), RemoteActorResolver (fetch + WebFinger)
    Inbox/            # Raw InboxItem + InboxActivityProcessor (semantic processing)
    Resolution/       # ObjectResolver: ActivityPub URI -> local Actor/content
    Delivery/         # ActivityDelivery: fan-out of outgoing activities to remote inboxes
    Serialization/    # ActorSerializer, NoteSerializer, CollectionSerializer, ActivitySerializer
  Jobs/
    Federation/       # ProcessInboxActivityJob, DeliverActivityJob ("inbox"/"delivery" queues)
  Infrastructure/    # Cross-cutting technical details (DB, security, installation, media)
    Database/
    Installation/
    Security/
      Http/           # SsrfGuard, SafeHttpClient, DnsResolver: remote fetch protected from SSRF
    Media/            # Upload, validation, thumbnails (Media, MediaVariant, MediaUploader)
  Application/       # Application services that orchestrate the domain
    Services/
    Queries/          # Complex read queries (e.g. FeedQuery)
  Http/              # Controllers, HTTP requests, middleware
  Policies/          # Centralized authorization (PostPolicy, CommentPolicy)
```

Controllers **contain no domain logic**: they validate the request, check
authorization/authentication, call an application service (in
`app/Application/Services`), and return the response. For example, complete
account creation (user + profile + settings + ActivityPub Actor + RSA key pair
+ endpoints) is wrapped in a single transaction by
`App\Application\Services\AccountRegistrar`, used by the registration
controller, the installer, and the `openbook:make-admin` CLI command. In the
same way, `PostComposer`, `CommentComposer`, `FollowManager`, `ReactionManager`,
and `AnnounceManager` each wrap a single domain operation in a transaction,
updating denormalized counters and creating the relevant notifications via
`NotificationCreator`. From Phase 4 onward, each of these services also calls
`ActivityDelivery` **after** the transaction commit, when the actor performing
the action is local and the recipient (or the followers) involve at least one
remote Actor: that is the only point where domain logic "knows" about
federation, and it remains an after-the-fact addition, never a condition for
the local action to succeed.

Every local account has from the start an ActivityPub Actor of type `Person`
(tables `actors`, `actor_keys`, `actor_endpoints`), exposed to the Fediverse
(see [Federation](federation.md#federation-phase-3) below). The local social domain is
modeled with federation in mind: `follows` and `likes` connect **Actors** (not
users), so remote actors can be accepted without schema changes.

Actor private keys are encrypted at rest (Eloquent `encrypted` cast, based on
`APP_KEY`) and are never exposed by APIs, logs, or error messages.

### Posts, comments, and reactions

- The body of posts and comments is text with broad Markdown (GFM), rendered to
  safe HTML by `App\Domain\Posts\PostBodyRenderer` (raw HTML stripped, external
  links with restrictive `rel`, Markdown images ignored). Hashtags and mentions
  are linkified after conversion; HTML content of remote posts (Phase 4) is
  reduced to plain text before entering the same pipeline (see
  [Social federation](federation.md#social-federation-phase-4)).
- Comments live in a dedicated table (`comments`), separate from `posts`, with
  `parent_comment_id` for nested replies: a post's entire tree is loaded with a
  single query and rebuilt in memory. They can have image attachments like
  posts (`comment_attachments`, same MIME/size limits).
- Likes (`likes`) and mentions (`mentions`) are polymorphic relations, already
  ready to apply to both posts and comments.
- Shares (`announces`) never duplicate the original post: they are a simple
  "actor shared this post" reference, which the feed uses to show the content
  also to people who follow the sharer (not the original author). They also
  appear in the personal feed and on the sharer's profile (`FeedQuery`), with
  the "shared this post" label above the card — ordered by the share time, not
  the original post's publication date, so a recent share of an old post still
  appears at the top. Sharing your own post does not add the label (it would be
  redundant: it already appears as your own post).
- Counters (`likes_count`, `comments_count`, `announces_count`) are
  denormalized on post/comment rows and updated transactionally, to avoid heavy
  counts on every feed request.
- The feed (`App\Application\Queries\FeedQuery`) unions your own posts, posts
  from people you follow, and shares made by people you follow, respecting
  visibility (public, unlisted, followers-only, direct) and with no
  recommendation algorithm: always reverse-chronological order.

### Profile customization and account settings

From the earliest phases the database already had columns for account
customization (`profiles.avatar_path`/`cover_path`/`bio`/`links`,
`user_settings.locale`/`default_post_visibility`/`manually_approves_followers`
/`discoverable`); the **Settings** page (`/impostazioni`, link in the user menu
and "Edit profile" button on your own profile) makes them editable:

- **Public profile**: display name, biography (max 500 characters), up to 4
  labeled links, avatar and cover image. Image upload (`ProfileImageUploader`)
  validates the file's actual type (never the extension alone), strips EXIF
  metadata, and resizes with GD when available (avatar max 512px, cover max
  1600px on the longest side), reusing the same base logic already used for
  post attachments (`ManipulatesImagesWithGd`, trait shared with
  `MediaUploader`). The previous file is always removed when a new one is
  uploaded, so orphan copies do not accumulate on quota-limited hosting.
  Changing the display name also updates the local ActivityPub Actor's `name`
  field, which is the value actually exposed to remote servers
  (`ActorSerializer`). Every change to the public profile (name, biography,
  links, avatar, cover) or to the "Protected account" option also sends an
  ActivityPub `Update` to all remote followers
  (`ProfileUpdater`/`AccountPreferencesUpdater` +
  `ActivityDelivery::deliverToFollowers()`), with the full updated Actor
  document as the embedded object: without this step remote servers would keep
  showing a stale copy of the profile until their local cache expired (up to
  `openbook.federation.actor_cache_ttl_hours`, 24 hours by default).
  Symmetrically, an `Update` with a `Person`/`Group` object received from
  another instance (because a remote user changed their profile) immediately
  updates the cached copy of their Actor
  (`InboxActivityProcessor::handleUpdateActor()`), applying the embedded
  document directly instead of waiting for a new fetch; it is accepted only if
  the id declared in the document matches the Actor that signed the request, so
  nobody can update someone else's profile. Before saving, the chosen file is
  shown immediately as a preview (client-side, via `FileReader`, with no
  upload). `Profile::avatarUrl()`/`coverUrl()` build the public URL through the
  configured "public" disk (`Storage::disk('public')->url()`), as already
  happens for post attachments (`Media::url()`), instead of relying on the
  `asset()` helper: the latter depends on the scheme/host detected on the
  individual request and can produce inconsistent URLs (e.g. `http://` instead
  of `https://`) behind a proxy or load balancer that does not correctly report
  the original scheme.
- **Interface language**: each user can choose among the languages listed in
  `config('openbook.locales')` (Italian and English at the moment). The
  `SetUserLocale` middleware, applied to all web requests, sets the app
  language from `user_settings.locale` for authenticated users; visitors who
  have not signed in yet see the language deduced from the browser's
  `Accept-Language` header (Italian if preferred, English in every other case),
  so the public homepage already appears in the right language before
  registration. A request without that header (never a real browser, typical of
  crawlers/monitoring) is not forced and stays on the instance default language
  (`app.locale`).
- **Default visibility of new posts**: the visibility selector in the composer
  now uses `user_settings.default_post_visibility` as the initial value (the
  panel opens automatically if the default is not "public"), remaining
  changeable post by post.
- **Protected account**: the "Protected account" checkbox updates both
  `user_settings.manually_approves_followers` and (the column actually read by
  `FollowManager`) `actors.manually_approves_followers`, so the two stay
  consistent.
- **Presence in suggestions**: turning off "Include my account in suggestions
  and search results" (`user_settings.discoverable` and `actors.discoverable`)
  stops the account from appearing in the sidebar "People to follow" box, and
  the federated Actor document declares `discoverable: false` (Mastodon
  directories and similar). It remains reachable directly, for example via
  federated search of the exact address.
- **Search indexing (FEP-5feb)**: the "Allow my public posts to be indexed for
  search" checkbox (`user_settings.indexable` / `actors.indexable`, off by
  default) is the ActivityPub profile `indexable` consent. Other people's
  public posts and comments appear in local search only if the author enabled
  the option; the author still finds their own content.
- **"This instance" box**: it no longer shows the subscriber count (a figure
  that needlessly exposes the instance's real size), but the most used tags
  recently by the local community (`App\Application\Queries\PopularHashtagsQuery`):
  only hashtags on posts published by *local* Actors, with public or unlisted
  visibility, never from remote content merely in cache or from posts reserved
  to followers/direct recipients.
- **Followed hashtags**: authenticated users can follow or unfollow tags from
  a hashtag page or the full trends page. Public posts already known to the
  instance then join the personal Home feed, with the existing timeline
  deduplication and cursor pagination. This is a local preference rather than
  a federated ActivityPub follow: it does not discover content the instance
  has not received. Followed tags stay private and are shown only to their
  owner, mixed chronologically with Actors in the owner's "Following" list.
- **Image lightbox**: clicking an image attached to a post or comment opens a
  full-screen overlay with the original at full resolution (previous/next
  arrows if the post has more than one, close with Esc, click outside the image,
  or a dedicated button). No external library: shared markup in `layouts.app`
  and a single script (`public/assets/js/lightbox.js`) that delegates events
  across the page, so it works the same on the feed, profile, single-post page,
  and World section. This was also the chance to finally use in the feed the
  thumbnail already generated at upload (`MediaUploader`, unused until now):
  the in-post preview shows the thumbnail in full (never cropped: `object-fit:
  contain` with a neutral background filling any side/top bands when the
  aspect ratio does not match the box); the lightbox instead loads the original
  at full resolution via the `data-full-src` attribute and shows it as large as
  possible without ever enlarging it beyond its natural size.
- **Static asset versioning (`App\Support\Assets`)**: `app.css` and
  `lightbox.js` are served from `public/` with no build pipeline (no
  Vite/webpack, to stay compatible with shared hosting): without a query string
  that changes on every modification, the browser can keep serving a stale copy
  of the file even after a software update (typical cause of "I updated but
  nothing changes", or worse of new markup paired with old CSS/JS behaving
  inconsistently). Views now reference these two files through
  `App\Support\Assets::url()`, which automatically appends
  `?v=<last modification of the file>` to the URL.
- **Infinite scroll instead of numbered pagination**: feed, World, profile
  (local or remote), and hashtag pages no longer show page arrows/numbers at
  the bottom of the post list. When the user nears the end of the page,
  `public/assets/js/infinite-scroll.js` requests the next `?cursor=…` URL and
  appends its posts to the current list, with no dedicated route/API or
  external library. A normal request to `/home` renders the layout, composer,
  any quoted post, and pending video publications without querying the feed or
  building the welcome kit. An AJAX request to the same `/home` endpoint
  returns an HTML fragment with post cards, or the welcome kit when the first
  block is empty; AJAX cursor requests return later cards. The same loader
  handles both and offers a retry link after an error. Without JavaScript,
  Home explains that the feed requires it. Other post lists fetch their full
  pages and offer classic pagination inside `<noscript>`. Setting
  `data-infinite-scroll` and `data-next-url` on a post container is enough for
  the script to activate: see `resources/views/posts/_feed.blade.php`, the
  partial shared by all these pages. This was also the chance to give
  `FeedQuery`/`HashtagController` a
  truly deterministic sort (`ORDER BY ... , id DESC`): without a tie-breaker,
  two posts published in the same second could end up duplicated or skipped
  when moving from one page to the next, a defect already present with classic
  pagination but much more visible with continuous scrolling.
- **Event list scrolling**: the initial `/eventi` or `/eventi/passati` request
  renders the complete page. For later pages, an XMLHttpRequest to the same
  `?page=N` URL returns only the event grid and its next-page URL. The browser
  appends its cards using the shared infinite-scroll script. On `/eventi`, the
  "Your events" section is rendered only with a full page request; opening a
  later page directly still returns the complete page.
- **Trending sidebar**: the authenticated layout renders a loading placeholder
  without running `PopularHashtagsQuery`. On viewports at least 1024 px wide,
  `public/assets/js/trending-sidebar.js` requests `/tendenze/sidebar` when the
  widget enters view. The JSON response contains the five formatted rows and
  whether the full Trending page has more; it uses the same query and moderation
  rules as that page. Hidden mobile sidebars make no request. The sidebar
  result is cached for five minutes using the configured Laravel cache store.
  Visiting `/tendenze` invalidates it immediately; changes to the ranking
  window or moderation settings also bypass the previous result.
- **Post card**: like / comment / share are icons only (with the numeric
  counter beside them; the texts remain as `aria-label` for accessibility).
  Deletion is no longer in line with the other actions: it appears only for
  *your own local posts* (never for cached remote Notes, nor for an admin:
  `PostPolicy::delete` rejects posts with `uri` set) and lives in a
  three-vertical-dots menu at the top right of the card
  (`<details class="ob-post__menu">`, with `post-menu.js` to close on outside
  click). On remote posts, clicking the timestamp opens the original
  ActivityPub `uri` in a new tab (`target="_blank" rel="noopener noreferrer"`);
  on local posts it still goes to the Openbook post page. The same pattern
  (icons + three-dot menu for Delete, never on remotes) is applied to comments
  too (`comments/_comment.blade.php`, `CommentPolicy`).
- **Navbar**: the bell icon opens a dropdown with recent notifications (the
  full page remains in the left sidebar); the search icon opens an inline input
  instead of going straight to `/cerca` (submitting the form still uses the
  same local/federated search). Dedicated script:
  `public/assets/js/header-panels.js`. On desktop, after scrolling past the
  composer, a **+** button appears in the center of the header that returns
  focus to the composer (or to Home if you are elsewhere); on mobile the same
  control is a discreet FAB at the bottom right (`compose-shortcut.js`).
- **Emoji**: in post and comment composers (including replies) a smile icon
  opens a local Mastodon-style picker (categories, search, recents in
  `localStorage`). System-native Unicode only, no CDN / Twemoji
  (`emoji-data.js` + `emoji-picker.js`).
- **Reports**: from the three-dot menu of every post that is not yours (local
  or remote) you can open a local report (reason + optional details), stored in
  `reports` and handled by the control panel (`/admin/segnalazioni`). It is not
  federated; you cannot report your own post. Throttle on
  `POST /posts/{post}/segnala`.
- **Control panel** (`/admin`, v0.5.0–0.6.0): accessible to administrators and
  moderators (`is_admin` / `is_moderator`). Includes dashboard, report queue
  for posts and comments (review / archive / action, with optional soft-delete
  of local content only), local user management (suspension / disable;
  promoting moderators and admins), instance settings (`site_name`,
  `registration_open`, Markdown rules and privacy policy, post/comment/media
  limits), federated domain blocks, federation queue inspection, and an action
  log. CLI still available: `openbook:make-admin` / `openbook:make-moderator`.
- **Video embeds**: if a post body contains a YouTube link (`youtube.com`,
  `youtu.be`, Shorts, ...) or PeerTube (`/w/...`, `/videos/watch/...`), an
  iframe player is shown under the text (only the *first* video link in the
  post). YouTube uses `youtube-nocookie.com`; PeerTube is recognized from the
  path shape typical of instances (`VideoEmbedFinder`).
- **Hashtags in bios**: hashtags (and URLs/mentions) in local and remote
  profile biographies are linkified with the same `PostBodyRenderer` used for
  posts and comments; this also applies to the bio snippet in search results.
  On remotes the HTML `summary` is first reduced to plain text
  (`RemoteContentSanitizer::toPlainText`).
- **Quote sharing**: the share icon on the card opens a menu with *Share
  directly* (Announce / boost, as before) or *Share with quote*. The quote
  takes you to the Home composer with the original post nested under the text;
  on publish a new post is created (`quoted_post_id`) that in the feed shows
  the original card inside its own. The quote also increments the original's
  share counter (the same `announces` row as a direct share; if the user had
  already shared, it is not doubled). Outgoing federation: `quoteUrl` on the
  Note plus a fallback link in `content`, and Announce to followers. Comments
  have no share (only like/reply).

### "World" section

A new "World" item in the left sidebar (`/mondo`) gives a window onto what
arrives from the rest of the fediverse toward this instance. **It is not and
cannot be a complete index of the fediverse**: Openbook neither crawls nor
actively indexes it, so this page shows only what has already been cached
locally because it is relevant (`InboxActivityProcessor::isRelevant()` —
author followed by a local Actor, reply to already-known content, or mention
of a local Actor). That limit is stated in the interface, not a hidden
implementation detail.

- **Timeline**: all public posts of *remote* Actors already in cache
  (`FeedQuery::world()`), ordered by descending publication date, regardless of
  who follows them (unlike the personal feed). Local posts do not appear: they
  already live on Home.
- **Accounts to discover**: a small list of proposed remote Actors
  (`PopularRemoteActorsQuery`), with the same "Follow" button used elsewhere
  (`actors.follow`). If there are more than five, a "See more" link opens
  `/mondo/scopri` with the paginated list and infinite scroll. There being no
  authoritative follower count for a remote Actor (nor an index of "real
  popularity" in the fediverse), the ranking uses only signals visible from
  this instance, in order: how many local Actors already follow them
  (`follows.status = accepted`), then the date of their most recent public post
  in cache. An Actor with neither signal (never followed locally, never a
  public post in cache) is not proposed, and anyone already followed by the
  visitor is excluded.
