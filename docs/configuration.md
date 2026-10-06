> Documentation: [English](README.md) · [Italiano](README.it.md)

## Configuration

All Openbook-specific settings are centralized in
`config/openbook.php` and configurable via environment variables (see
`.env.example` for the full list with comments). The main ones:

| Variable | Description |
|---|---|
| `OPENBOOK_DOMAIN` | Public domain of the instance, used in `user@domain` addresses. Must match the host of `APP_URL`. If you change domain, update both and then run `php artisan openbook:repair-federation-urls` (otherwise Lemmy rejects Follows when id and inbox are on different hosts). The command also updates the technical `/relay` Actor while keeping its dedicated paths; use `--dry-run` to review changes first. |
| `OPENBOOK_INSTALLED` | Set automatically by the installer; do not change by hand. |
| `OPENBOOK_WEB_CRON_ENABLED` / `OPENBOOK_WEB_CRON_TOKEN` | Enable running periodic jobs via HTTP request, for hosts without a real cron. |
| `OPENBOOK_REGISTRATION_OPEN` / `OPENBOOK_REGISTRATION_REQUIRES_APPROVAL` | Control whether registrations are open. |
| `OPENBOOK_MEDIA_MAX_SIZE_KB` / `OPENBOOK_MEDIA_MAX_ATTACHMENTS` | Maximum size (KB) and maximum number of images attachable to a post. |
| `OPENBOOK_POST_MAX_LENGTH` | Maximum length (characters) of a post's text. |
| `OPENBOOK_COMMENT_MAX_DEPTH` | Comment nesting levels treated as "normal" in configuration (the actual structure has no hard limit, see [Known limitations](roadmap.md#known-limitations)). |
| `OPENBOOK_EVENT_DEFAULT_DURATION_HOURS` | Visual duration assumed when a remote event has no `endTime` (default 12 hours; stored and federated dates are not changed). |
| `OPENBOOK_EVENT_CACHE_TTL_HOURS` | Minimum interval between opportunistic refreshes of the same remote event from its origin (default 4 hours). |
| `OPENBOOK_SEARCH_MIN_LENGTH` / `OPENBOOK_SEARCH_PER_SECTION` | Minimum query length and maximum results per section on the Search page, including known local and remote people. |
| `DB_PERSISTENT` | If `true`, reuse PDO MySQL/MariaDB connections across requests. Recommended on hosting with a limit on new connections per second (e.g. Hostinger: error `2002 Operation not permitted`). |
| `OPENBOOK_FEED_PER_PAGE` | Number of posts per page in the personal feed, the local feed, and profile/hashtag pages. |
| `OPENBOOK_PUBLICATION_QUEUE_RETENTION_DAYS` | Days to retain completed or failed video publication jobs before database rows and any residual private staging files are removed. |
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

### Post locations

Post locations are an optional feature and use a local
[GeoNames](https://www.geonames.org/) catalog. The location controls remain
hidden until an administrator successfully imports the catalog; all other
Openbook features continue to work normally. After installing or updating
Openbook, download the default `cities500` dataset with:

```bash
php artisan openbook:update-cities
```

The default download also imports the official country and first-level
administrative-area dictionaries used for readable labels. Importing the ZIP
requires the PHP `zip` extension. For offline installations, pass an already
downloaded GeoNames ZIP or extracted TSV instead:

```bash
php artisan openbook:update-cities /path/to/cities500.zip
php artisan openbook:update-cities /path/to/cities500.txt
```

The file mode performs no network requests. If `admin1CodesASCII.txt` and
`countryInfo.txt` are in the same directory, they are used to complete labels.
Without an imported catalog, Openbook continues to work but local users cannot
select a post location.

Location is always added explicitly. Browser coordinates requested by the
“Current location” button are used transiently to find the nearest catalog
city and are never stored, logged, displayed, or federated. Only the selected
GeoNames city and its city-centre coordinates are saved and published.
Geographical data is provided by GeoNames under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).

## Cron and periodic tasks

Configure `openbook:cron` every minute to process pending work and run periodic
maintenance. The command coordinates these tasks:

- `openbook:process-inbox` — processes the `inbox` queue;
- `openbook:deliver` — processes the `delivery` queue;
- `openbook:deliver-push` — delivers pending Web Push notifications;
- `openbook:confirm-outgoing-follows` — confirms remote Follows still pending
  if we already appear in the target's `followers` collection (missing Accept);
- `openbook:fetch-feeds` — imports RSS/Atom feeds;
- `openbook:auto-announce` — performs configured automatic shares;
- `openbook:purge-database` — cleans expired operational rows once every 24 hours;
- `openbook:database-sanity --scheduled` — reconciles orphans once every 24
  hours, deferred to the next invocation when operational Maintenance runs.

Each invocation processes pending work and finishes on its own. For these
tasks, configure the periodic cron; no permanent worker is needed.

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

The endpoint requires the correct token and rejects requests that are too close
together (`OPENBOOK_WEB_CRON_MIN_INTERVAL`,
default 55 seconds, 429 response), returning 404 if the feature is disabled or
403 if the token is missing or wrong.

### Remote post retention

Retention keeps remote posts for a configurable period. Open
**Administration → Database → Retention** and set the days for the two categories:

- **Relevant:** can appear in at least one local user's Home or have comments
  from local actors.
- **Non-relevant:** meet neither criterion; a typical example is a post that
  only appears in World.

Periods start at first import into Openbook. New comments or interactions do
not restart the clock. Both categories are initially **disabled (0)** and can
be enabled independently. When both are active, relevant posts must be kept
at least as long as non-relevant posts.

**Expiry permanently deletes the remote post and all its comments, including
local comments, together with associated reports.** Local posts and their
comments, direct conversations, and remote posts quoted by local content are
excluded. This cleanup does not remove media or files. Other instances receive
no deletion requests.

**Save retention periods** only saves settings. **Preview** uses saved periods
and shows up to 10 posts per category, with links opening in a new tab. An empty
list is expected when the category is disabled or no posts are old enough.
Posts already marked as deleted may still need cleanup even if their page
can no longer be viewed.

Cleanup runs from the terminal or a dedicated cron; **it is not included in
the ordinary cron**, including when that cron is triggered through the web.
To preview a sample of posts before deleting them:

```bash
php artisan openbook:prune-remote-posts --dry-run
```

To run cleanup with the ordinary parameters:

```bash
php artisan openbook:prune-remote-posts
```

The command processes batches until no candidates remain or approximately
30 minutes have elapsed. The current batch finishes even if it exceeds the
limit. Run the command again to continue; the summary counts deleted posts
per category, without adding comments. Deletion is permanent: keep a backup
if you need to recover local contributions from removed threads.

Optional parameters let you adjust cleanup:

| Parameter | Default | Meaning |
| --- | --- | --- |
| `--batch-size` | 100 | Posts per category in each batch, not the total run limit. |
| `--max-time` | 1800 | Available seconds, checked between batches. |
| `--sample` | 10 | Links shown per category in dry-run; 0 hides them. |

Dry-run counts refer to one batch per category, **not the total** posts awaiting
deletion. Links use `APP_URL`: open them while signed in to view content your
account can access. If a deleted post is imported again, it receives a new
import date and a new local link.

For daily cleanup, add a dedicated cron at a quiet time, adjusting the PHP
and installation paths:

```cron
0 3 * * * cd /path/to/openbook && /usr/bin/php artisan openbook:prune-remote-posts >> storage/logs/remote-post-retention.log 2>&1
```

The **Administration → Database** header shows the estimated size of the whole
database, including data and indexes. **Maintenance** contains operational-table
statistics and cleanup; **Database sanity** handles references left after a
deletion, as described below.

### Database sanity

Database sanity removes **orphaned likes, mentions and notifications**: references
to content or other objects no longer present in the database. It preserves
references to objects still present, even if marked as deleted, and does not
remove content, audit logs, media or files. It also removes push notifications
linked to deleted notifications and updates notification counts. It works
even with both retention categories disabled.

The ordinary cron, from either the terminal or the web, runs it automatically
**at most once every 24 hours**, with 100-row batches and 5 seconds available
between batches. When Maintenance runs in the same invocation, sanity is deferred
to the next cron invocation. A partial run leaves remaining work for the next
day; you can complete it immediately from the panel or terminal. No additional
cron is required.

Open **Administration → Database → Database sanity** to view the preview.
The table shows up to 100 orphans per table and object type: these are
**samples, not global totals**. **Refresh preview** reloads the numbers without
changing data. Unsupported types are listed separately and preserved; their
numbers are totals per type instead.

After confirmation, **Clean up orphans** runs cleanup immediately. The panel
uses 100-row batches and a 5-second limit checked between batches; the current
batch finishes. On return, it shows refreshed samples and rows deleted during
that invocation. Partial cleanup can be repeated. The action is recorded in
the audit log.

From the terminal, preview or run cleanup:

```bash
php artisan openbook:database-sanity --dry-run
php artisan openbook:database-sanity
```

The command uses **100 rows per batch and table/type** and a limit of
**1800 seconds (30 minutes)**, checked between batches. The preview shows
one batch per table/type; cleanup continues until exhausted or the time limit
is reached and reports rows actually deleted. To adjust the parameters:

```bash
php artisan openbook:database-sanity --batch-size=500 --max-time=300
```

The panel and dedicated command can be used at any time, independently of the
automatic schedule. When sanity cleanup is already running, a second invocation
is skipped. On failure, previously completed batches remain applied; run cleanup
again to continue. `--max-time` limits cleanup, not reading the preview or the
unsupported-type report.

The `--scheduled` option, used by the ordinary cron, applies the daily interval.
Manual runs without this option do not shift the automatic run; dry-run changes
neither data nor the schedule. If you prefer a full cleanup at a specific time,
you may add a dedicated cron, or run the command immediately after retention
in the same script:

```cron
30 3 * * * cd /path/to/openbook && /usr/bin/php artisan openbook:database-sanity >> storage/logs/database-sanity.log 2>&1
```

### Video worker (only when video support is enabled)

Local video uploads are **disabled by default**. An administrator must enable
them from the instance settings after configuring working `ffmpeg` and
`ffprobe` executables. Instances that keep the feature disabled need neither
tool and continue to accept images and audio as before.

Video transcoding is deliberately excluded from `openbook:cron` and from the
web cron endpoint. The recommended setup is a permanent CLI process:

```bash
php /path/to/openbook/artisan openbook:process-videos
```

The worker polls every five seconds, handles SIGTERM/SIGINT gracefully and
checks FFmpeg/ffprobe before claiming work. `--once` processes at most one
queued post and is useful for diagnostics. Multiple workers are safe: a short
claim lock and a per-item lease prevent duplicate publication.

As a simpler, higher-latency alternative, a system cron may run one queued
post per invocation:

```cron
* * * * * php /path/to/openbook/artisan openbook:process-videos --once >/dev/null 2>&1
```

This command is CLI-only and must not be exposed through a web endpoint. Since
each invocation handles at most one post, the permanent worker is preferable
for instances where video uploads may accumulate.

Example systemd `ExecStart`:

```ini
ExecStart=/usr/bin/php /path/to/openbook/artisan openbook:process-videos
Restart=always
RestartSec=5
TimeoutStopSec=960
```

Equivalent Supervisor program command:

```ini
command=/usr/bin/php /path/to/openbook/artisan openbook:process-videos
autostart=true
autorestart=true
stopwaitsecs=960
numprocs=1
```

Use an absolute project path and the same operating-system user that owns the
Openbook storage directories. Increase `numprocs` only when the host has enough
CPU, memory and I/O capacity for concurrent FFmpeg processes.
