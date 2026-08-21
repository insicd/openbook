# Openbook

> Italiano: vedi [`README.it.md`](README.it.md).

Openbook is a general-purpose **federated** social network, inspired by the
simplicity of Facebook's early years but built natively on the **ActivityPub**
protocol and integrated with the Fediverse. It is not a microblog, not a
Mastodon clone, and not a link aggregator: it is meant for personal, local,
association, and topic-based communities, with an interface that non-technical
users can understand.

Current version: **26.34 - Lovable Pancake**. Release notes are in Italian in
[`CHANGELOG.md`](CHANGELOG.md). This is the first stable release. Openbook is
beyond basic bidirectional federation: it includes **communities** (local and
remote `Group` Actors, membership, wall, Lemmy/Friendica interoperability) and
is working on **Phase 6** (broad interoperability with Mastodon, Misskey,
PeerTube, Pixelfed, WordPress, WriteFreely, etc., remote media, hardening).
Phases 1–4 remain the foundation: bootstrap and installer, local social domain,
ActivityPub identity, delivery/receipt of activities via a MySQL queue.

## Table of contents

- [Requirements](#requirements)
- [Guided installation (recommended)](#guided-installation-recommended-on-shared-hosting)
- [Manual installation / CLI](#manual-installation--cli)
- [Updating an existing instance](#updating-an-existing-instance)
- [Web server configuration](#web-server-configuration)
- [Configuration](#configuration)
- [Architecture](#architecture)
  - [Federation (Phase 3)](#federation-phase-3)
  - [Social federation (Phase 4)](#social-federation-phase-4)
  - [Community (Phase 5)](#community-phase-5)
  - [Interoperability and remote media (Phase 6)](#interoperability-and-remote-media-phase-6)
  - [Profile customization and account settings](#profile-customization-and-account-settings)
- [Tests](#tests)
- [Cron and periodic tasks](#cron-and-periodic-tasks)
- [Security and privacy](#security-and-privacy)
- [Roadmap and project status](#roadmap-and-project-status)
- [Changelog](CHANGELOG.md)
- [License](#license)

## Requirements

Openbook is designed to run even on ordinary **shared hosting**, without
continuous SSH access, without Docker, and without long-running processes:

- PHP **8.2** or later, with extensions: `curl`, `openssl`, `json`, `pdo`,
  `pdo_mysql`, `mbstring`, `fileinfo`;
- `gd` extension **recommended** (not blocking) for uploading images in posts:
  without `gd` the instance remains fully functional, but only for text posts;
- MySQL 8 or equivalent MariaDB;
- Composer (only during install/update, not in production);
- Apache with `mod_rewrite`, or Nginx;
- HTTPS (required in production: federation needs secure endpoints);
- the ability to schedule a cron job **or**, alternatively, the token-protected
  web cron endpoint (useful when the host does not allow real cron);
- a writable local filesystem for attachments, cache, and logs.

Not required: Redis, RabbitMQ, permanent queues or workers, WebSocket, Node.js
in production, Docker, Elasticsearch, object storage, or external cloud
services. These components may be supported later as advanced options, but the
base mode uses only MySQL, PHP cron, and the local filesystem.

## Guided installation (recommended on shared hosting)

The simplest path needs neither Composer nor SSH: it uses the
`setup-openbook.php` bootstrap and the zip releases published on
[about.openb.app](https://about.openb.app).

1. Download [`setup-openbook.php`](https://about.openb.app/dist/setup-openbook.php)
   and upload it (FTP / File Manager) to the folder where you want to install
   Openbook.
2. Open it in the browser (`https://your-domain.example.org/setup-openbook.php`).
3. The wizard checks PHP requirements, downloads the latest official release
   (archive with `vendor/` included, verified via SHA-256), prepares `.env`
   and, if needed, the root `.htaccess` for a flat `public_html` layout.
4. When it finishes you are redirected to `/install` for the database, instance
   name, and administrator account (the existing Laravel installer).
5. Configure cron (see [Cron and periodic tasks](#cron-and-periodic-tasks)).

> Releases and the `releases/latest.json` manifest must be published on
> about.openb.app (see `bin/build-release.sh` and `distribution/manifest.example.json`).

### Classic installation (git / Composer)

1. Put the code on the server (upload via SFTP/panel, or `git clone`) and
   install production dependencies:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. Copy the sample configuration file:

   ```bash
   cp .env.example .env
   ```

3. Make sure the following directories are writable by the web server user
   (typically `www-data`, or your hosting user):

   ```
   storage/
   storage/framework/{cache,sessions,views}
   storage/logs/
   storage/app/public/
   bootstrap/cache/
   ```

   > New subdirectories created under `storage/app/public/` on the first upload
   > of each type (e.g. `avatars/`, `covers/`, `media/`) are still made
   > explicitly readable/traversable (`chmod` 0755/0644) right after writing,
   > instead of relying on `mkdir()` alone: on some hosts with a restrictive
   > PHP-process `umask` (e.g. `0077`), `mkdir($path, 0755)` can otherwise
   > produce a `0700` directory in practice, unreadable for the user that serves
   > static files when it differs from the one PHP runs as (common with
   > suPHP/LSAPI). If an uploaded image still returns a 403 "Permission denied"
   > in the Apache log, also check the permissions of `storage/app/public/`
   > itself.

4. Open `https://your-domain.example.org/install` in the browser. The guided
   installer performs, in order:

   1. check of the PHP version and required extensions;
   2. check of write permissions on the needed directories;
   3. collection of MySQL/MariaDB connection parameters and a connection test;
   4. running of the database migrations;
   5. generation of the application key (`APP_KEY`), if missing;
   6. configuration of the instance name and domain;
   7. creation of the administrator account (with automatic generation of the
      RSA key pair for its ActivityPub Actor);
   8. generation of a secret token for web cron (optional, shown only once);
   9. writing of the configuration to `.env` and permanent lock of the
      installer (`storage/installed.lock`).

   **The installer never shows passwords or secrets after completion** and,
   once locked, every request to `/install/*` is redirected to the home page.

5. Configure cron (see [Cron and periodic tasks](#cron-and-periodic-tasks)).

## Manual installation / CLI

If you prefer not to use the web installer (for example in automated
environments), you can run the same steps from the command line:

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate

# Configura DB_* e OPENBOOK_* in .env, poi:
php artisan migrate --force

# Crea il primo amministratore (chiede i dati interattivamente se omessi):
php artisan openbook:make-admin --username=admin --email=admin@example.org

# (Opzionale) promuovi un account esistente a moderatore di istanza:
# php artisan openbook:make-moderator --promote=nome-utente

# Mark installation as complete:
php artisan tinker --execute="file_put_contents(storage_path('installed.lock'), 'cli - '.now());"
```

The `openbook:make-admin` command can also promote an already existing account:

```bash
php artisan openbook:make-admin --promote=nome-utente
```

For moderation powers only (without instance settings):

```bash
php artisan openbook:make-moderator --promote=nome-utente
```

## Updating an existing instance

### Via admin panel (recommended on shared hosting)

In **Control panel → Updates** an administrator can consult the manifest
`https://about.openb.app/releases/latest.json` and, if a newer version is
available, apply the official archive (SHA-256 verification, maintenance,
migrations, preservation of `.env` and `storage/`).

Before updating: back up the database.

### Via CLI / SSH

```bash
# 1. backup del database prima di qualunque migration
mysqldump -u UTENTE -p NOME_DB > backup_$(date +%Y%m%d_%H%M%S).sql

# 2. aggiorna il codice sorgente (git pull, upload, ecc.)

# 3. se sono cambiate le dipendenze PHP
composer install --no-dev --optimize-autoloader

# 4. applica le migration pendenti
php artisan migrate --force

# 5. se usi le cache di config/route/view, ricostruiscile
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Publishing a release (maintainer)

```bash
./bin/build-release.sh
```

Upload to about.openb.app the zip, the `*-changelog.md` file, `latest.json`
generated in `dist/`, and `setup-openbook.php` from the repository root.

## Web server configuration

Openbook is a Laravel application: the web server **document root must point
to the `public/` directory**, never to the project root (which contains
application code and sensitive configuration).

### Apache (with VirtualHost configuration access)

```apache
<VirtualHost *:443>
    ServerName social.example.org
    DocumentRoot /var/www/openbook/public

    <Directory /var/www/openbook/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The project's `public/.htaccess` file (provided by Laravel) already handles
routing via `mod_rewrite`.

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name social.example.org;
    root /var/www/openbook/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

### Hosting with a non-configurable document root (project inside `public_html`)

Many shared-hosting panels (cPanel, Plesk) require the domain to serve
`public_html/` directly, with no way to point at a subdirectory. Recommended
solution:

1. Upload the entire project **outside** `public_html`, for example in
   `~/openbook/` (a folder above the webroot);
2. Copy the **contents** of `~/openbook/public/` into `public_html/`;
3. Edit `public_html/index.php` so that it points at the real project
   directory:

   ```php
   require __DIR__.'/../openbook/vendor/autoload.php';
   $app = require_once __DIR__.'/../openbook/bootstrap/app.php';
   ```

   (adjust the `../openbook/...` paths to match the real location of the
   project relative to `public_html/`).

This avoids publicly exposing `app/`, `config/`, `.env`, and the other
sensitive project directories.

#### If you cannot even leave `public_html` (entire project in a single public folder)

When the hosting panel requires that *the entire project* live inside the
domain's public folder (no folders "above" `public_html/` available), the only
option is an `.htaccess` in the **project root** that routes every request
toward `public/` via `mod_rewrite`, explicitly denying direct access to
everything that must never be reachable:

```apache
# .htaccess nella root del progetto (accanto a .env, artisan, vendor/, ecc.)
RewriteEngine On

# Nega l'accesso diretto al codice e ai file sensibili del progetto, ma NON
# tocca in alcun modo le richieste dirette a /public/. "storage" e'
# volutamente ESCLUSO da questo elenco: il symlink public/storage espone
# solo storage/app/public/ (avatar, copertine, allegati dei post), mai le
# sottocartelle davvero sensibili (storage/framework, storage/logs,
# storage/app/private), quindi bloccarlo qui romperebbe la visualizzazione
# di ogni immagine caricata dagli utenti senza aggiungere protezione reale.
RewriteCond %{REQUEST_URI} !^/public/
RewriteCond %{REQUEST_URI} ^/(\.env.*|\.git|composer\.(json|lock)|artisan|app|bootstrap|config|database|resources|routes|tests|vendor)($|/)
RewriteRule ^ - [F,L]

# Instrada tutte le altre richieste (incluse quelle verso /storage/...,
# servite tramite il symlink public/storage) verso la cartella public/
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L]
```

This approach is more fragile than the previous two options (it depends on
`mod_rewrite` and on a path list maintained by hand) and should be used only
when the other two are not viable. If you later add new directories to the
project, remember to add them to this list **without ever including `storage`**.

## Configuration

All Openbook-specific settings are centralized in
`config/openbook.php` and configurable via environment variables (see
`.env.example` for the full list with comments). The main ones:

| Variable | Description |
|---|---|
| `OPENBOOK_DOMAIN` | Public domain of the instance, used in `user@domain` addresses. Must match the host of `APP_URL`. If you change domain, update both and then run `php artisan openbook:repair-federation-urls` (otherwise Lemmy rejects Follows when id and inbox are on different hosts). |
| `OPENBOOK_INSTALLED` | Set automatically by the installer; do not change by hand. |
| `OPENBOOK_WEB_CRON_ENABLED` / `OPENBOOK_WEB_CRON_TOKEN` | Enable running periodic jobs via HTTP request, for hosts without a real cron. |
| `OPENBOOK_REGISTRATION_OPEN` / `OPENBOOK_REGISTRATION_REQUIRES_APPROVAL` | Control whether registrations are open. |
| `OPENBOOK_MEDIA_MAX_SIZE_KB` / `OPENBOOK_MEDIA_MAX_ATTACHMENTS` | Maximum size (KB) and maximum number of images attachable to a post. |
| `OPENBOOK_POST_MAX_LENGTH` | Maximum length (characters) of a post's text. |
| `OPENBOOK_COMMENT_MAX_DEPTH` | Comment nesting levels treated as "normal" in configuration (the actual structure has no hard limit, see [Known limitations](#known-limitations)). |
| `OPENBOOK_SEARCH_MIN_LENGTH` / `OPENBOOK_SEARCH_PER_SECTION` | Minimum query length and maximum results per section in local search. |
| `DB_PERSISTENT` | If `true`, reuse PDO MySQL/MariaDB connections across requests. Recommended on hosting with a limit on new connections per second (e.g. Hostinger: error `2002 Operation not permitted`). |
| `OPENBOOK_FEED_PER_PAGE` | Number of posts per page in the personal feed, the local feed, and profile/hashtag pages. |
| `OPENBOOK_ACTOR_KEY_BITS` | Length (bits) of the RSA keys generated for new ActivityPub Actors (recommended minimum: 2048). |
| `OPENBOOK_SIGNATURE_MAX_SKEW` | Maximum skew (seconds) tolerated between the `Date` header of an incoming signed request and the local clock, before rejecting it. |
| `OPENBOOK_FETCH_MAX_REDIRECTS` / `OPENBOOK_FETCH_TIMEOUT` / `OPENBOOK_FETCH_CONNECT_TIMEOUT` / `OPENBOOK_FETCH_MAX_BYTES` | Limits applied by the SSRF-protected HTTP client (`SafeHttpClient`) used to fetch remote Actors and resources. |
| `OPENBOOK_FETCH_ALLOW_INSECURE` | Allows outgoing requests over plain HTTP (local development only; production always requires HTTPS). |
| `OPENBOOK_ACTOR_CACHE_TTL_HOURS` | How many hours an already-resolved remote Actor is considered "fresh" before being fetched again. |
| `OPENBOOK_INBOX_MAX_BODY_BYTES` / `OPENBOOK_INBOX_MAX_JSON_DEPTH` | Size and JSON depth limits applied to incoming activities, even before cryptographic verification. |
| `OPENBOOK_DELIVERY_MAX_ATTEMPTS` | Maximum number of attempts to deliver a single outgoing activity before it ends up in `failed_jobs`. Backoff intervals between attempts (1, 5, 15, 60, 360, 1440 minutes) are fixed. |

Image uploads require the PHP `gd` extension (checked by the installer as a
**recommended**, non-blocking requirement): without `gd` the instance works
normally, but only text posts can be published.

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
    Reactions/        # Likes and shares (Like/Announce at the local level)
    SocialGraph/      # Follows between Actors (local and remote)
    Notifications/    # Local notifications (not federated)
  Federation/        # Everything related to ActivityPub
    Actors/           # Actors (local and remote), RemoteActorResolver (fetch + WebFinger)
    Inbox/            # Raw InboxItem + InboxActivityProcessor (semantic processing)
    Resolution/       # ObjectResolver: ActivityPub URI -> local Actor/Post/Comment
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
(see [Federation](#federation-phase-3) below). The local social domain is
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
  [Social federation](#social-federation-phase-4)).
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
  cycle (see [Social federation](#social-federation-phase-4) below), so a
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
  meant to be scheduled (see [Cron and periodic tasks](#cron-and-periodic-tasks)).
- **Search**: the Search page (`/cerca?q=...`, GET form) has two paths.
  If the query is a federated address (`utente@dominio`, with or without a
  leading `@`, `acct:...`, or a profile URL), it is resolved locally if the
  domain matches this instance, otherwise via WebFinger + fetching the Actor
  document (`RemoteActorResolver::resolveByHandle()`), then redirected to the
  profile. If the query has a different shape (keyword, phrase, username
  without domain), it runs a *local-only* search (`LocalSearchQuery`) on people
  (username, display name, bio; respects `discoverable`), posts and comments of
  local Actors (visibility and FEP-5feb `indexable` respected), and hashtags.
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
  [known limitations](#known-limitations) below).
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

Not yet part of the mature product: a real recipient system for direct
messages (beyond mentions), and advanced federation-debug tools (beyond the
admin queue panel).

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
  `public/assets/js/infinite-scroll.js` downloads the next page in the
  background (the same `?page=N` URL as always) and appends only its posts to
  the current list, with no dedicated route/API and no external library.
  Classic pagination remains available inside a `<noscript>`, for people
  browsing without JavaScript. Setting `data-infinite-scroll` and
  `data-next-url` on a post container is enough for the script to activate: see
  `resources/views/posts/_feed.blade.php`, the partial shared by all these
  pages. This was also the chance to give `FeedQuery`/`HashtagController` a
  truly deterministic sort (`ORDER BY ... , id DESC`): without a tie-breaker,
  two posts published in the same second could end up duplicated or skipped
  when moving from one page to the next, a defect already present with classic
  pagination but much more visible with continuous scrolling.
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

## Tests

The project uses PHPUnit. The suite runs by default on in-memory SQLite (see
`phpunit.xml`), so it does not need a MySQL database to run:

```bash
php artisan test
```

The suite covers bootstrap/installer/authentication, the local social domain
(posts, media, comments, reactions, follows, feed, notifications), identity and
social federation (`tests/Feature/Federation`,
`tests/Unit/Infrastructure/Security`), communities
(`tests/Feature/Communities`), and interoperability cases (Article/Video/
attachments, Pixelfed Atom fallback, Wafrn blog API, Lemmy Accept, World/
discover, profile photo roll). In particular:

- generation and verification of HTTP Signatures, `SsrfGuard` (rejection of
  private/loopback/reserved IPs, DNS that resolves to non-public addresses,
  resolution failures), WebFinger, NodeInfo, content negotiation on
  profile/post/comment (including visibility rules for anonymous requesters and
  the `Tombstone` representation), outbox/followers/following collections, and
  the full inbox lifecycle at the transport level (correctly signed activity,
  missing signature, tampered body, signing Actor mismatch, unsupported
  content-type, body too large, deduplication, shared inbox);
- `InboxActivityProcessor`: every activity type (`Follow` toward open and
  protected accounts, `Accept`/`Reject` of an outgoing follow, `Undo`,
  `Like`/`Announce` and their `Undo`, `Create` of a relevant post or reply,
  `Delete`, `Update` with a `Note` object and with a `Person`/`Group` object
  including rejection of a document that declares an id different from the
  signing Actor) and the case of an unknown signing Actor;
- `ActivityDelivery` and `DeliverActivityJob`: deduplication of shared inboxes,
  exclusion of local/not-yet-accepted followers, delivery rules for direct
  messages, correct HTTP signature of the outgoing request, permanent failure
  without a private key, retry on a non-2xx response;
- `RemoteActorResolver::resolveByUri()`/`resolveByHandle()`: fetch and cache
  with TTL, rejection of a document that declares an id different from the one
  requested, refusal to treat a local URI as remote, WebFinger resolution;
- the full "local action → activity delivered to a remote Actor" cycle
  end-to-end for `Follow`/`Unfollow`, `Like`/`Unlike`, `Announce`/`Unannounce`,
  publishing and deleting posts and comments (including the HTTP controllers);
- remote search (`/cerca`) and the profile page of a cached remote Actor
  (`/attori/{id}`, including the redirect to the canonical profile when the id
  corresponds to a local Actor);
- `RemoteOutboxFetcher` (`RemoteOutboxFetcherTest`): on the first load of a
  remote Actor's profile page (or after cache expiry) the most recent public
  posts from their real outbox are fetched and shown, excluding replies,
  non-public posts, and any item that declares an author other than the outbox
  owner; if the outbox is a stub (only `totalItems`, typical of Pixelfed) the
  Atom feed is used; no new request before cache expiry; the attempt is still
  recorded when the remote server does not respond, so later loads are not
  slowed down; no mention notification is generated for content retrieved this
  way (it is not a "just happened" event);
- `RemoteRepliesFetcher` (`RemoteRepliesFetcherTest`, `SignedFetchTest`):
  opening a remote post (e.g. from the feed of someone you follow) queries the
  `replies` collection of the original Note (TTL
  `OPENBOOK_REPLIES_CACHE_TTL_HOURS`), also following Mastodon's typical `next`
  pagination (where the first page is often empty); GETs are signed
  (authorized fetch) with the visiting user's key or a fallback local Actor;
  public/unlisted comments from third parties are cached without generating
  notifications; replies to already-known comments under the same post are
  nested correctly;
- follower/following lists (`FollowListTest`): public visibility for a local
  profile, exclusion of still-pending requests, correct follow/unfollow button
  state per row, redirect of a remote Actor's list when it actually corresponds
  to a local account, authentication required for a remote Actor's list.
- the Settings page (`SettingsTest`): authentication required, update of
  name/biography/links with synchronization of the name on the federated Actor,
  upload and replacement of the avatar (with removal of the previous file),
  rejection of a non-image file, change of interface language actually applied
  by the middleware, propagation of default visibility to the composer,
  synchronization of "protected account" between `user_settings` and the Actor,
  exclusion from suggestions when the account is no longer "discoverable",
  sending of a federated `Update` to remote followers when the public profile,
  the "Protected account" option, or the `discoverable`/`indexable` flags
  change (and its absence when only purely local preferences change); the
  profile image upload service (`ProfileImageUploaderTest`): separate paths for
  avatar/cover, removal of the previous file, type and size validation,
  resizing of oversized images, permissions of the directory created on first
  upload correct even with a restrictive PHP-process `umask` (also verified for
  post attachments in `MediaUploaderTest`); construction of the avatar/cover
  URL (`Tests\Unit\Domain\Profiles\ProfileTest`), to avoid regressions on the
  choice of the "public" disk instead of the `asset()` helper.
- the "World" section (`WorldTest`): the timeline shows only public remote
  posts already in cache and excludes both local posts and non-public remote
  ones; authentication required; ranking of accounts to discover (priority to
  accepted local followers, then to the most recent activity), exclusion of
  anyone with neither a local follower nor a post in cache, exclusion of anyone
  already followed by the visitor; `/mondo/scopri` page with the full list and
  infinite scroll.
- share visibility (`AnnounceVisibilityTest`): a shared post (local, from
  another local Actor, or remote) appears on the profile and in the personal
  feed of the person who shared it with the "shared this post" label; ordering
  by share time even when the original post is much older; no redundant label
  when sharing your own post; disappearance from the profile after undoing the
  share; no label on the original author's profile.

A small subset of tests (`Tests\Feature\Installer\InstallerMysqlFlowTest`)
specifically checks installer step 2 (connection and migration) against a
**real MySQL/MariaDB server**, because this behavior cannot be exercised
reliably with SQLite. These tests skip themselves (`markTestSkipped`) if they
do not find a reachable server with the credentials given by the environment
variables `OPENBOOK_TEST_MYSQL_HOST`, `OPENBOOK_TEST_MYSQL_PORT`,
`OPENBOOK_TEST_MYSQL_DATABASE`, `OPENBOOK_TEST_MYSQL_USERNAME`,
`OPENBOOK_TEST_MYSQL_PASSWORD` (sample values already present in
`phpunit.xml`). To actually run them, start a throwaway MySQL/MariaDB instance
with those credentials before launching the suite.

## Cron and periodic tasks

Openbook uses Laravel's **database** queue (`jobs`/`failed_jobs` tables, no
Redis/RabbitMQ and no permanent process): inbox processing and outgoing
activity delivery happen only when someone periodically runs the
`openbook:cron` command, which in turn invokes in sequence:

- `openbook:process-inbox` — processes the `inbox` queue (`InboxActivityProcessor`);
- `openbook:deliver` — processes the `delivery` queue (`DeliverActivityJob`);
- `openbook:confirm-outgoing-follows` — confirms remote Follows still pending
  if we already appear in the target's `followers` collection (missing Accept).

The first two sub-commands run with `queue:work --stop-when-empty`, so they
exit on their own instead of listening indefinitely: suitable for a classic
cron, never for a permanent process supervisor.

**With access to a real system cron:**

```cron
* * * * * php /percorso/openbook/artisan openbook:cron >/dev/null 2>&1
```

**On hosting without a real cron or CLI access**, the installer generates a
secret token and enables an equivalent HTTP endpoint, to be called with any
"external cron" service (e.g. cron-job.org) pointed at regular intervals:

```
GET https://your-domain.example.org/cron/run?token=YOUR_TOKEN
```

The token is compared with `hash_equals()` (no timing attack) and the endpoint
rejects requests that are too close together (`OPENBOOK_WEB_CRON_MIN_INTERVAL`,
default 55 seconds, 429 response), returning 404 if the feature is disabled or
403 if the token is missing or wrong.

## Security and privacy

- Passwords hashed with modern algorithms via Laravel's native mechanisms
  (bcrypt), no custom algorithm.
- Authentication with rate limiting on login attempts and on resending
  verification emails.
- Actor private keys encrypted at rest, never exposed by APIs/logs/errors.
- Session cookies `HttpOnly` and `secure` in production, CSRF protection on
  all forms.
- No third-party analytics, trackers, advertising pixels, CDNs, or mandatory
  remote fonts: the interface uses only CSS and assets served locally.
- The installer never shows secrets (passwords, tokens) after the procedure
  completes and locks itself permanently at the end.

To report vulnerabilities see [`SECURITY.md`](SECURITY.md).

## Roadmap and project status

Current version: **26.34 - Lovable Pancake**. Release notes are in Italian in
[`CHANGELOG.md`](CHANGELOG.md).

### Versioning

From 26.34 onward, a stable release uses `YY.week` (this release is `26.34`,
codename Lovable Pancake). Follow-up patch candidates of the same week are
`26.34.rc1`, `26.34.rc2`, and so on. `26.34.rc1` comes *after* the `26.34`
stable (it is not a pre-release before it). The `0.x` versions in the changelog
are pre-stable history.

NodeInfo and the User-Agent use the technical version (`26.34`); the footer
shows `26.34 - Lovable Pancake`.

- ✅ **Phase 1 — Structure and installation**: project, configuration,
  installer, database, authentication, administrator account, local profiles.
- ✅ **Phase 2 — Local social domain**: posts, images, nested comments, likes,
  shares, local follows, feed, notifications.
- ✅ **Phase 3 — Federated identity**: `Person` Actor, WebFinger, NodeInfo,
  content negotiation, inbox/outbox, HTTP signatures.
- ✅ **Phase 4 — Social federation**: remote search,
  `Follow`/`Accept`/`Reject`, `Create`/`Update`/`Delete`, `Like`, `Announce`,
  `Undo`, MySQL queue, retry, cron; then (0.5.x–0.6.x) profile/settings, World,
  on-demand outbox and replies, admin panel, signed fetch, live notifications.
- ✅ **Phase 5 — Community** (0.7.x): local and remote `Group` Actors,
  membership, wall, FEP-1b12 Announce, Lemmy/Friendica, moderators, Local/Remote
  list. Further polish (dedicated member list, community avatar/cover) remains
  possible without blocking Phase 6.
- 🚧 **Phase 6 — Security and interoperability** (in progress on the first
  stable): types `Article`/`Video`/`Image`, remote media in the gallery,
  `/users/…` URIs, Lemmy Accept, Pixelfed Atom fallback, Wafrn blog API, Photos
  roll on the profile, World → `/mondo/scopri`. Still to strengthen: NodeBB and
  other edge cases; optional local download of remote media; dedicated
  recipients for direct messages.

The project does not move to the next phase until the previous phase's tests
are green.

### Known limitations

- Mentions on *write* resolve **local** Actors and remote ones **already in
  cache** (`@utente` / `@utente@dominio`); an unknown remote handle is not
  resolved on the fly via WebFinger at compose time. On *receive*, a mention of
  a local Actor correctly generates a notification.
- "Direct" messages (`direct` visibility) have no dedicated recipient list:
  they are visible to the author and to whoever is mentioned in the text. A
  conversation UI is deferred.
- Remote content is reduced to plain text (no arbitrary HTML): labeled links
  (`[text](url)` from `<a href>`) and images in `attachment` as remote URLs
  remain. This is an explicit security choice.
- Remote content in the inbox is cached only if relevant (followed author,
  reply to something known, local mention). In addition: remote profile
  (`RemoteOutboxFetcher`, with Atom fallback) and replies of the remote post
  (`RemoteRepliesFetcher`). It is not a complete index of the fediverse.
  Keyword search (`LocalSearchQuery`) covers *local* content; for remotes,
  `utente@dominio` resolution remains.
- Remote images use `media.remote_url` (hotlink): if the origin blocks or
  removes the file, the gallery can appear empty. Local attachments stay on
  `storage/app/public` → `public/storage`, with no CDN.
- The `OPENBOOK_COMMENT_MAX_DEPTH` limit is in configuration but not yet
  applied in the UI: a post's entire comment tree is loaded in one page.
- The control panel covers moderation, blocked domains, queue, and settings;
  IP bans and an HTTP-signature debug tool remain out of scope. Staff
  promotion: UI and CLI (`openbook:make-admin` / `openbook:make-moderator`).

## License

Openbook is distributed under the **GNU Affero General Public License v3.0 or
later** (AGPL-3.0-or-later). See [`LICENSE`](LICENSE) for the full text.
