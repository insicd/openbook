<p align="center">
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.2%2B-brightgreen?logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.mysql.com/"><img src="https://img.shields.io/badge/MySQL-passed-brightgreen?logo=mysql&logoColor=white" alt="MySQL"></a>
  <a href="CHANGELOG.md"><img src="https://img.shields.io/badge/Openbook-26.38.rc1-0F766E" alt="Openbook 26.38.rc1"></a>
  <a href="https://www.w3.org/TR/activitypub/"><img src="https://img.shields.io/badge/ActivityPub-federated-5B21B6" alt="ActivityPub"></a>
</p>

<h1 align="center">Openbook</h1>

<p align="center">
  Italiano: <a href="README.it.md">README.it.md</a>
  ·
  <a href="https://about.openb.app">Website</a>
  ·
  <a href="https://openb.app">Instance</a>
  ·
  <a href="docs/README.md">Documentation</a>
  ·
  <a href="CHANGELOG.md">Changelog</a>
</p>

Openbook is a general-purpose **federated** social network, inspired by the
simplicity of Facebook's early years but built natively on **ActivityPub**.
It is not a microblog, not a Mastodon clone, and not a link aggregator: it
is meant for personal, local, association, and topic-based communities.

Current version: **26.38.rc1**. Release notes are in
[`CHANGELOG.md`](CHANGELOG.md). The first stable release was
**26.34 - Lovable Pancake**.

## Install

The simplest path needs neither Composer nor SSH. It uses the
`setup-openbook.php` bootstrap and the zip releases on
[about.openb.app](https://about.openb.app).

1. Download [`setup-openbook.php`](https://about.openb.app/dist/setup-openbook.php)
   and upload it to the folder where you want to install Openbook.
2. Open it in the browser (`https://your-domain.example.org/setup-openbook.php`).
3. Complete `/install` (database, instance name, administrator).
4. Configure cron: see [Configuration](docs/configuration.md#cron-and-periodic-tasks).

Requirements, git/Composer install, web server layouts, and updates:
**[Install guide](docs/install.md)**.

## Documentation

- [Install and updates](docs/install.md)
- [Configuration and cron](docs/configuration.md)
- [Architecture](docs/architecture.md)
- [Federation](docs/federation.md)
- [Development and tests](docs/development.md)
- [Security](docs/security.md)
- [Roadmap](docs/roadmap.md)
- Full index: [`docs/README.md`](docs/README.md)

## License

Openbook is distributed under the **GNU Affero General Public License v3.0 or
later** (AGPL-3.0-or-later). See [`LICENSE`](LICENSE) for the full text.
