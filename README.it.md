<p align="center">
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.2%2B-brightgreen?logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.mysql.com/"><img src="https://img.shields.io/badge/MySQL-passed-brightgreen?logo=mysql&logoColor=white" alt="MySQL"></a>
  <a href="CHANGELOG.md"><img src="https://img.shields.io/badge/Openbook-26.38.rc1-0F766E" alt="Openbook 26.38.rc1"></a>
  <a href="https://www.w3.org/TR/activitypub/"><img src="https://img.shields.io/badge/ActivityPub-federated-5B21B6" alt="ActivityPub"></a>
</p>

<h1 align="center">Openbook</h1>

<p align="center">
  English: <a href="README.md">README.md</a>
  ·
  <a href="https://about.openb.app">Sito</a>
  ·
  <a href="https://openb.app">Istanza</a>
  ·
  <a href="docs/README.it.md">Documentazione</a>
  ·
  <a href="CHANGELOG.md">Changelog</a>
</p>

Openbook e' un social network generalista **federato**, ispirato alla
semplicita' dei primi anni di Facebook ma costruito nativamente su
**ActivityPub**. Non e' un microblog, non e' un clone di Mastodon e non e'
un aggregatore di link: e' pensato per comunita' personali, territoriali,
associative e tematiche.

Versione corrente: **26.38.rc1**. Il dettaglio delle modifiche e' in
[`CHANGELOG.md`](CHANGELOG.md). La prima stable e' stata
**26.34 - Lovable Pancake**.

## Installazione

Il percorso piu' semplice non richiede Composer ne' SSH. Usa il bootstrap
`setup-openbook.php` e le release zip su
[about.openb.app](https://about.openb.app).

1. Scarica [`setup-openbook.php`](https://about.openb.app/dist/setup-openbook.php)
   e caricalo nella cartella in cui vuoi installare Openbook.
2. Aprilo nel browser (`https://tuo-dominio.example.org/setup-openbook.php`).
3. Completa `/install` (database, nome istanza, amministratore).
4. Configura il cron: vedi [Configurazione](docs/configuration.it.md#cron-e-attivita-periodiche).

Requisiti, installazione git/Composer, server web e aggiornamenti:
**[Guida all'installazione](docs/install.it.md)**.

## Documentazione

- [Installazione e aggiornamenti](docs/install.it.md)
- [Configurazione e cron](docs/configuration.it.md)
- [Architettura](docs/architecture.it.md)
- [Federazione](docs/federation.it.md)
- [Sviluppo e test](docs/development.it.md)
- [Sicurezza](docs/security.it.md)
- [Roadmap](docs/roadmap.it.md)
- Indice completo: [`docs/README.it.md`](docs/README.it.md)

## Licenza

Openbook e' distribuito sotto licenza **GNU Affero General Public License v3.0 o
successiva** (AGPL-3.0-or-later). Vedi [`LICENSE`](LICENSE) per il testo completo.
