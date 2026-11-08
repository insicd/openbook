> Documentation: [Italiano](docker.it.md) · [All guides](README.md)

# Install Openbook from Docker Hub

This guide is for administering an instance using the prebuilt Openbook image.
You do not need application sources, PHP or Composer on the host. Docker remains
optional; shared-hosting installation is covered in [Installation](install.md).

The image repository is `skeyby/openbook` on Docker Hub. Image creation and
publication are covered in the [developer guide](../development/docker.md).

## Requirements and files

You need Docker with Compose v2, an existing MySQL/MariaDB database and account,
a stable public domain, and an HTTPS reverse proxy. The database account must
be able to apply schema changes. The example does not create a database server.

Copy `compose.yaml` and `docker/openbook.env.example` from the same Openbook
release into an installation directory, preserving the `docker/` subdirectory.
Keep that directory and its Compose project name stable: they identify the
instance's containers and persistent volume. Use a different project and database
for each independent instance.

In `compose.yaml`, check the image reference near the top and select the
published version you want to use, for example:

```yaml
image: docker.io/skeyby/openbook:26.38.rc1
```

All services use that same image. Save the actual image reference in this file
so that later commands and new terminal sessions use the same version. Then:

```sh
cp docker/openbook.env.example docker/openbook.env
chmod 600 docker/openbook.env
docker compose -f compose.yaml --profile video pull
```

The commands below explicitly select `compose.yaml`; run them from the
installation directory.

## Instance configuration

Edit `docker/openbook.env` before starting any services:

| Variable | Meaning |
| --- | --- |
| `APP_URL` | Canonical public HTTPS URL, e.g. `https://social.example.org` |
| `OPENBOOK_DOMAIN` | Stable federation domain matching the URL host |
| `APP_KEY` | Application encryption key; generate once and preserve |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Existing database connection |
| `DB_PORT` | Optional; default `3306` |

Generate a key **once**, then copy the complete output into `APP_KEY`:

```sh
docker compose -f compose.yaml run --rm --no-deps --entrypoint php web -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Do not change this key on restart or upgrade: encrypted settings and OAuth
accesses depend on it. Back it up with the database. Keep the federation domain
stable. `DB_HOST=localhost` refers to the container itself, so use a database
hostname reachable from Docker. Single-quote passwords containing `$`, `#` or
spaces in the env file, for example `DB_PASSWORD='example$with#symbols'`.

Defaults include production mode, debug off, UTC, secure cookies, and logs sent
to the container logs. No additional `.env` inside the container is needed.
Instance name, policies, registration, media limits, video and object storage
are managed through their existing administration pages and saved in the database.
`APP_LOCALE=it` is optional; the default language is English.

Registrations start **closed**. The default `MAIL_MAILER=log` does **not** send
email. Before opening registrations, configure SMTP through `MAIL_MAILER=smtp`,
`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`
and `MAIL_FROM_NAME` as required by your provider. `MAIL_SCHEME=smtps` selects
implicit TLS. SMTP currently uses environment configuration, not a UI setting.
After editing the env file, recreate the services to apply the changes, including
video if enabled.

The image accepts individual uploads up to 128 MiB and POST bodies up to 136 MiB.
UI limits may be lower. To increase PHP limits, mount an additional PHP `.ini`
into `/usr/local/etc/php/conf.d/` and align the reverse proxy and UI limits.

### Optional file secrets

You may use `APP_KEY_FILE` and `DB_PASSWORD_FILE` instead of the corresponding
direct values. Remove `APP_KEY`/`DB_PASSWORD` from the env file when using files;
supplying both forms is rejected. Mount the same secrets into every service.
For example, merge the following into `compose.yaml`:

```yaml
services:
  web:
    environment:
      APP_KEY_FILE: /run/secrets/app_key
      DB_PASSWORD_FILE: /run/secrets/db_password
    secrets: [app_key, db_password]
  # Add the same environment and secrets entries to cron, inbox, delivery and video.
secrets:
  app_key:
    file: ./docker/secrets/app_key
  db_password:
    file: ./docker/secrets/db_password
```

Protect those files and keep them out of version control. Compose file secrets
are mounted files, not an encrypted secret store. See [Docker Compose secrets](https://docs.docker.com/compose/how-tos/use-secrets/).

## First start

Start the web service and wait for it to be ready:

```sh
docker compose -f compose.yaml up -d --wait web
```

**Each web container start applies pending database migrations automatically**,
then clears compiled views and starts Apache. Existing migrations are not
reapplied. If either operation fails, Apache stays stopped; the restart policy
retries startup, and errors are available in:

```sh
docker compose -f compose.yaml logs -f web
```

On a new instance, create the first administrator interactively, then mark
installation complete **only after the administrator command succeeds**:

```sh
docker compose -f compose.yaml run --rm --no-deps web php artisan openbook:make-admin
docker compose -f compose.yaml run --rm --no-deps web php artisan tinker --execute='App\Infrastructure\Installation\InstallationLock::lock((string) config("openbook.version"));'
docker compose -f compose.yaml up -d cron inbox delivery
```

The administrator password is requested privately; do not put it on the command
line. No demo users are created. The web installer is disabled in this image;
complete setup with the commands above before using the site. The image never
creates an administrator or changes the application key automatically.

## HTTPS and storage

The web service binds HTTP only to `127.0.0.1:8080` on the host. Configure your
HTTPS reverse proxy to forward the public domain to that address and preserve
the public Host. A proxy running in another container needs a shared network
and can connect to `web:80`. Configure certificates and upload/time limits in
that proxy before opening the instance to users. The image does not automatically
trust forwarded client-IP headers; configure trusted proxy addresses explicitly
if you need the original client IP for rate limits and logs.

The named `openbook-storage` volume preserves uploads, private files, sessions,
cache, installation state and video staging across image replacements. Back up
the entire volume, database, `docker/openbook.env`, Compose file and any secrets
as a consistent instance. **Do not use `docker compose down -v` during upgrades**:
it deletes the persistent volume.

The default named volume is initialized automatically. Existing bind mounts
must permit read/write access by UID 33 (`www-data`). This setup is for one
instance on one host; multi-host storage and rolling upgrades are not provided.

## Background services and video

| Service | Purpose |
| --- | --- |
| `web` | Applies migrations, then serves the website through Apache |
| `cron` | Runs periodic federation, polling, push, feed and maintenance tasks |
| `inbox` | Continuously processes incoming federation work |
| `delivery` | Continuously processes outgoing federation work |
| `video` | Optional permanent video worker |

Background services wait for the web health check before starting, so they do
not start against a database that is still being migrated. This is a startup
ordering rule: it does not stop already running workers if the web later fails.
Run one cron service per instance; do not duplicate it with host cron or web cron.
Keep the queue defaults supplied by the image. Inbox/delivery are processed by
permanent workers; the existing periodic command also processes those queues.
See [periodic tasks](configuration.md#cron-and-periodic-tasks) for additional
schedules such as remote-post retention.

For video, enable it in the administration UI first. FFmpeg and ffprobe are
already installed; their paths can stay at the defaults. Then:

```sh
docker compose -f compose.yaml --profile video up -d video
docker compose -f compose.yaml logs -f cron inbox delivery video
```

Services restart automatically unless explicitly stopped. Inbox/delivery and
cron have two minutes to stop; the video worker has twenty minutes. If you disable
video in the UI, also run `docker compose -f compose.yaml stop video` to prevent
repeated restarts of an inactive worker.

## Updates

Back up the instance first. **Stop all services**, including video, before changing
the image reference in `compose.yaml` to the new published version:

```sh
docker compose -f compose.yaml --profile video stop
```

After saving the new image reference:

```sh
docker compose -f compose.yaml --profile video pull
docker compose -f compose.yaml up -d --wait web
docker compose -f compose.yaml up -d cron inbox delivery
# Only if video was enabled:
# docker compose -f compose.yaml --profile video up -d video
```

No separate migration command is needed: web startup applies migrations and
clears persistent compiled views before background services are started. Check
web logs if startup fails. Some migrations on a large database can take time.
Do not upgrade by replacing/restarting only the web container while old workers
are still running. Keep every service on the same image version.

Use image replacement for Docker updates; do not use the administration panel's
archive updater. Reverting an image does not undo database migrations: rollback
may require restoring the matching database and storage backup.

The `/up` health check confirms that the web application responds after startup;
it does not continuously verify database availability, cron or video processing.
