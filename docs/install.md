> Documentation: [English](README.md) · [Italiano](README.it.md)

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
5. Configure cron (see [Cron and periodic tasks](configuration.md#cron-and-periodic-tasks)).
6. Optionally import the GeoNames city catalog to enable post locations (see
   [Post locations](configuration.md#post-locations)). This step requires CLI access and is not
   needed for the rest of Openbook.

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

5. Configure cron (see [Cron and periodic tasks](configuration.md#cron-and-periodic-tasks)).
6. Optionally enable post locations by importing the GeoNames city catalog:

   ```bash
   php artisan openbook:update-cities
   ```

   See [Post locations](configuration.md#post-locations) for offline import and privacy details.

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

# Optional: import the GeoNames catalog to enable post locations:
php artisan openbook:update-cities

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
