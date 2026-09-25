> Documentation: [English](README.md) · [Italiano](README.it.md)

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
| `OPENBOOK_COMMENT_MAX_DEPTH` | Comment nesting levels treated as "normal" in configuration (the actual structure has no hard limit, see [Known limitations](roadmap.md#known-limitations)). |
| `OPENBOOK_EVENT_DEFAULT_DURATION_HOURS` | Visual duration assumed when a remote event has no `endTime` (default 12 hours; stored and federated dates are not changed). |
| `OPENBOOK_EVENT_CACHE_TTL_HOURS` | Minimum interval between opportunistic refreshes of the same remote event from its origin (default 4 hours). |
| `OPENBOOK_SEARCH_MIN_LENGTH` / `OPENBOOK_SEARCH_PER_SECTION` | Minimum query length and maximum results per section in local search. |
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

Openbook uses Laravel's **database** queue (`jobs`/`failed_jobs` tables, no
Redis/RabbitMQ and, except for the optional video worker described below, no
permanent process): inbox processing and outgoing
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
