# Building and publishing the Docker image

> [Italiano](docker.it.md) · [Developer documentation](README.md)

This guide is for Openbook developers and maintainers. Instance administrators
should use [Install Openbook from Docker Hub](../docs/docker.md). The image
includes production dependencies; the local Compose is for testing that deployment,
not a live-reloading development server.

## Files and build stages

- `Dockerfile`: official `php:8.5-apache-trixie`, named `base`, `build` and
  `production` stages. Extensions are compiled once in the shared base. The build
  stage installs production dependencies from `composer.lock` and optimizes the
  application classmap. Only its vendor directory is copied into production.
- `.dockerignore`: allowlist of application sources. Local `.env`, storage,
  database files, development vendor, tests and generated bootstrap caches are
  excluded. Do not broaden it without checking for instance data or secrets.
- `docker/`: Apache/PHP configuration, entrypoint, automatic database preparation
  and periodic runner. Composer/Git/unzip and GD development headers stay outside
  the final image; the official PHP base retains its own development tools.
- `compose.local.yaml`: standalone local build using `openbook:local`, with no
  pull of that application tag. `build --pull` still refreshes PHP/Composer bases.
- `compose.yaml`: standalone registry deployment; no source checkout or build
  fallback. `OPENBOOK_IMAGE` can override its reference for packaging tests.

Both Compose files have the same services, runtime configuration and volume.
Do not overlay them. Keep the same project/directory if switching one instance
between modes; use a separate project, database and storage for integration tests.

## Build and start locally

Use a clean release checkout and Docker with Compose v2. Read the runtime
configuration, HTTPS and backup instructions in the administrator guide. For
an isolated test instance:

```sh
cp docker/openbook.env.example docker/openbook.env
chmod 600 docker/openbook.env
docker compose -f compose.local.yaml build --pull web
```

Fill the env file with the URL/domain and a dedicated, disposable MySQL/MariaDB
database. Generate a key once and put its output in `APP_KEY`:

```sh
docker compose -f compose.local.yaml run --rm --no-deps --entrypoint php web -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
docker compose -f compose.local.yaml up -d --wait web
docker compose -f compose.local.yaml run --rm --no-deps web php artisan openbook:make-admin
docker compose -f compose.local.yaml run --rm --no-deps web php artisan tinker --execute='App\Infrastructure\Installation\InstallationLock::lock((string) config("openbook.version"));'
docker compose -f compose.local.yaml up -d cron inbox delivery
```

Only mark installation complete after successfully creating the administrator.
Enable video in the UI before `docker compose -f compose.local.yaml --profile video up -d video`.
The HTTP port remains local; production mode expects an HTTPS reverse proxy.
For an update, stop all services, rebuild `web`, start it with `--wait`, then
start the workers. Do not leave old workers running during migrations.

## Startup and queue behavior

Only the `apache2-foreground` entrypoint automatically runs migrations. It executes
`docker/prepare.php` as UID 33, holds an exclusive file lock in shared storage,
calls `migrate --force --no-interaction`, then `view:clear`. Failure prevents
Apache startup. The lock serializes web preparation; it is not a multi-host
migration coordinator. No APP_KEY, administrator or seed data are generated.
CLI commands do not trigger migrations implicitly.

The web health check only becomes available after preparation. Compose gates
cron, inbox, delivery and video on `service_healthy`. This controls startup only:
it does not stop existing workers when web restarts. Upgrades must stop every
service first. The image uses SIGTERM for CLI; the web service selects SIGWINCH
for graceful Apache shutdown. Startup forwards stop signals to preparation.

`openbook:cron` retains its existing queue-draining behavior. Permanent workers
use `queue:work --queue=inbox --sleep=1` and
`queue:work --queue=delivery --sleep=1`, one queue per service.
With the default database configuration, ordinary and transactional delivery
share the jobs table. If queue backends change, consume `local_delivery`
separately as well as the default backend. Do not assume the default delivery
worker sees a different connection's transactional jobs.

Do not bake config caches into the image. Keep runtime configuration and startup
scripts in the final stage so editing them does not invalidate Composer install.
Database-dependent preparation belongs at startup, not during the build.

## Validation before publication

Build and run the packaging regression checks (Python 3 standard library only):

```sh
docker compose -f compose.local.yaml build web
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/Docker -v
```

The checks create and remove temporary containers, with an isolated SQLite file
for migration/restart checks. They verify Compose parity and readiness ordering,
web startup failure when migrations fail, pending migrations and view cleanup on
restart, clean image contents, CLI UID, installer blocking including the front
controller path, public uploads and PHP execution blocking. They never use the
development database.

Also test a full instance against a disposable MySQL/MariaDB database: fresh web
startup/migrations, administrator creation, persistent installation state, env
and file secrets, inbox/delivery job consumption (including `local_delivery`),
video capability, clean stop signals, and replacement/restart with retained
storage. Confirm every container uses the same image. The full PHPUnit suite
remains required before proposing a PR; follow [Tests](testing.md), including
its protection against cached development database configuration.

A registry-mode packaging test may set `OPENBOOK_IMAGE=openbook:local` and run
`compose.yaml` from a temporary directory containing only that file and runtime
configuration. This checks that deployment does not require sources.

## Publication

The target repository is `docker.io/skeyby/openbook`. Use the version declared
in `config/openbook.php` as an immutable tag. Before publishing, review the full
diff, run checks, commit the intended sources, and ensure the working tree is
clean. Check whether the tag already exists; do not overwrite a published release
silently. Keep release candidates separate from any stable `latest` alias.

After local Docker Hub login, a single-platform publication can use:

```sh
docker build --pull --build-arg VCS_REF="$(git rev-parse HEAD)" -t docker.io/skeyby/openbook:YOUR_VERSION .
docker push docker.io/skeyby/openbook:YOUR_VERSION
```

The image includes OCI source, license and revision labels. Record the resulting
registry digest and the resolved base-image digest. A local build validates only
its target architecture; test each architecture before publishing a multi-platform
manifest. With buildx and an appropriate builder:

```sh
docker buildx build --platform linux/amd64,linux/arm64 \
  --build-arg VCS_REF="$(git rev-parse HEAD)" \
  -t docker.io/skeyby/openbook:YOUR_VERSION --push .
docker buildx imagetools inspect docker.io/skeyby/openbook:YOUR_VERSION
```

Rebuild for base security updates with a new documented image tag; preserve
instance APP_KEY, storage and DB across deployments. Other OCI registries work
with their own hostname. See [Docker multi-stage builds](https://docs.docker.com/build/building/multi-stage/).
