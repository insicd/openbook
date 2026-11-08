# Docker distribution

## Problem and constraints

Provide a clean OCI image of Openbook using PHP 8.5 + Apache, with MySQL/MariaDB
supplied externally. Docker remains optional; shared-hosting behavior and domain
services are unchanged. Never bake local environment, keys, database, uploads,
development dependencies or generated caches into the image.

## Decisions

- Three named Dockerfile stages: `base` compiles extensions once; `build` resolves
  production dependencies from the lock file and optimizes autoloading without
  application scripts; `production` assembles sources, vendor and runtime config.
  Check actual extensions and production Composer requirements. Keep Composer,
  Git, unzip and GD headers out of the final stage. Keep FFmpeg included as agreed.
- Use runtime environment with production defaults, no physical `.env` required.
  APP_KEY must remain stable. APP_KEY_FILE and DB_PASSWORD_FILE support mounted
  secrets with conflicting direct values rejected. Dynamic settings remain in DB.
- CLI creates the initial administrator and installation lock. The Docker Apache
  configuration blocks the web wizard, including front-controller paths, because
  the wizard's env-file writes conflict with externally managed configuration.
- Each web start runs `docker/prepare.php` as www-data: exclusive flock in shared
  storage, `migrate --force --no-interaction`, then `view:clear`. Apache starts only
  after success. Preparation forwards stop signals. Compiled views must be cleared
  because the volume survives image replacement; migration history remains in DB.
- Cron, inbox, delivery and optional video share image, configuration and storage.
  They depend on web's HTTP health check, which becomes available after migrations.
  This orders startup only; it does not stop existing workers. Stop every service
  before upgrading. The file lock is not a multi-host deployment coordinator.
- Keep existing cron orchestration, including its queue consumption. Permanent
  consumers use --queue=inbox / --queue=delivery and --sleep=1. With default DB
  queue settings, transactional local_delivery shares the jobs table. Alternative
  queue backends need their own local_delivery consumer.
- Persist all storage (uploads, sessions, cache, install lock, video staging).
  Root owns application code; UID 33 owns writable storage/bootstrap cache.
  Bootstrap cache is local to each container. Never recursively chown uploads
  during every restart. Existing bind mounts must already permit UID 33 access.
- Use SIGTERM for CLI and SIGWINCH for Apache. Video remains opt-in in UI and a
  Compose profile because its command exits when disabled.
- `compose.local.yaml` is standalone, builds openbook:local and never pulls that
  application tag. `compose.yaml` is standalone and only uses the published image
  docker.io/skeyby/openbook:26.38.rc1 (OPENBOOK_IMAGE can override it). Both have
  the same service behavior and volume identity; do not overlay them.
- General docs are for administrators using Docker Hub. Local builds, packaging
  internals, checks and publication live in development/docker.md and .it.md.
- Publish only after reviewed clean commit, using the app version as an immutable
  tag and OCI source/license/revision labels. Do not promote a candidate to latest.
  Docker archive updates from the UI are not the deployment mechanism.

## Review findings addressed

- The original Apache rule missed /index.php/install and its subroutes. Expanded
  it and added a regression check before any installation lock exists.
- Persistent compiled views could outlive changed image sources. Clear them before
  serving requests on each web start.
- The PHP Apache base uses SIGWINCH by default, unsuitable for CLI workers. Use
  explicit appropriate stop signals and verify clean termination.
- User-facing instructions mixed deployment and image building/publication. Split
  them and persist the selected registry version in Compose instead of relying on
  a shell variable that disappears in a new terminal.

## Validation

- Full PHP suite during review: 2324 passed, 133 skipped, 19273 assertions. Used a
  separate APP_CONFIG_CACHE path to preserve development configuration and avoid
  the suite's local-DB protection. Optional integration skips remain unchanged.
- Seven packaging regression tests pass on ARM64 and AMD64: Compose parity and
  readiness ordering, migration failure before Apache, pending migrations and
  view cleanup across repeated restarts, image cleanliness and CLI UID, blocked
  installer aliases, project files and uploaded PHP.
- On both ARM64 and AMD64, source-free registry Compose tested with a local
  prebuilt image and disposable
  MySQL 8.4: automatic fresh migrations, file secrets in all services, administrator,
  install lock, persistent data, gated workers and both ordinary/transactional
  queue consumption. Image replacement with a pending test migration clears views,
  preserves users, upgrades all services and remains idempotent on another restart.
- Original full smoke checks also covered FFmpeg/ffprobe, no development packages,
  conflicting/missing config rejection, logs and clean cron/video shutdown.
- Production image checks confirm no Composer/Git/unzip or GD headers; JPEG, WebP
  and FreeType work. Local Compose builds; no source is needed for registry mode.

## Limits

Single host and one instance per storage/database; no coordinated rolling upgrade
or multi-host file sessions/cache/staging. A separately configured HTTPS reverse
proxy is required; forwarded client-IP headers are not automatically trusted.
The /up check is startup/liveness, not continuous DB or worker health monitoring.
MySQL/MariaDB and backup management remain the operator's responsibility. Keep DB,
storage and APP_KEY consistent when restoring or rolling back migrations.
