> Documentazione: [Italiano](README.it.md) · [English](README.md)

## Requisiti

Openbook e' progettato per funzionare anche su un normale **shared hosting**, senza
accesso SSH continuo, senza Docker e senza processi permanenti:

- PHP **8.2** o superiore, con estensioni: `curl`, `openssl`, `json`, `pdo`,
  `pdo_mysql`, `mbstring`, `fileinfo`;
- estensione `gd` **consigliata** (non bloccante) per il caricamento di immagini nei
  post: senza `gd` l'istanza resta pienamente funzionante, ma solo per i post
  testuali;
- MySQL 8 o MariaDB equivalente;
- Composer (solo in fase di installazione/aggiornamento, non in produzione);
- Apache con `mod_rewrite`, oppure Nginx;
- HTTPS (obbligatorio in produzione: la federazione richiede endpoint sicuri);
- possibilita' di schedulare un cron job **oppure**, in alternativa, l'endpoint web
  di cron protetto da token (utile quando l'hosting non consente cron reali);
- filesystem locale scrivibile per allegati, cache e log.

Non sono richiesti: Redis, RabbitMQ, code o worker permanenti, WebSocket, Node.js in
produzione, Docker, Elasticsearch, object storage o servizi cloud esterni. Questi
componenti potranno essere supportati in futuro come opzioni avanzate, ma la modalita'
base usa esclusivamente MySQL, cron PHP e filesystem locale.

## Installazione guidata (consigliata su shared hosting)

Il percorso piu' semplice non richiede Composer ne' SSH: usa il bootstrap
`setup-openbook.php` e le release zip pubblicate su
[about.openb.app](https://about.openb.app).

1. Scarica [`setup-openbook.php`](https://about.openb.app/dist/setup-openbook.php)
   e caricalo (FTP / File Manager) nella cartella in cui vuoi installare Openbook.
2. Aprilo nel browser (`https://tuo-dominio.example.org/setup-openbook.php`).
3. Il wizard verifica i requisiti PHP, scarica l'ultima release ufficiale
   (archivio con `vendor/` incluso, verificato via SHA-256), prepara `.env`
   e, se serve, il `.htaccess` di root per layout `public_html` piatto.
4. Al termine vieni reindirizzato a `/install` per database, nome istanza e
   account amministratore (installer Laravel gia' esistente).
5. Configura il cron (vedi [Cron e attivita periodiche](configuration.it.md#cron-e-attivita-periodiche)).
6. Facoltativamente importa il catalogo delle citta' GeoNames per abilitare le
   posizioni nei post (vedi [Posizioni nei post](configuration.it.md#posizione-dei-post)). Questo
   passaggio richiede accesso CLI e non serve alle altre funzioni di Openbook.

> Le release e il manifesto `releases/latest.json` devono essere pubblicati su
> about.openb.app (vedi `bin/build-release.sh` e `distribution/manifest.example.json`).

### Installazione classica (git / Composer)

1. Scarica il codice sul server (upload via SFTP/pannello, oppure `git clone`) e
   installa le dipendenze di produzione:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. Copia il file di configurazione di esempio:

   ```bash
   cp .env.example .env
   ```

3. Assicurati che le seguenti cartelle siano scrivibili dall'utente del server web
   (tipicamente `www-data`, o l'utente del tuo hosting):

   ```
   storage/
   storage/framework/{cache,sessions,views}
   storage/logs/
   storage/app/public/
   bootstrap/cache/
   ```

   > Le nuove sottocartelle create sotto `storage/app/public/` al primo upload di ogni
   > tipo (es. `avatars/`, `covers/`, `media/`) vengono comunque rese esplicitamente
   > leggibili/attraversabili (`chmod` 0755/0644) subito dopo la scrittura, invece di
   > affidarsi al solo `mkdir()`: su alcuni hosting con una `umask` del processo PHP
   > restrittiva (es. `0077`), `mkdir($path, 0755)` puo' altrimenti produrre in pratica
   > una cartella `0700`, illeggibile per l'utente con cui il web server serve i file
   > statici quando e' diverso da quello con cui gira PHP (comune con suPHP/LSAPI). Se
   > un'immagine caricata restituisse comunque un 403 "Permission denied" nel log di
   > Apache, verifica anche i permessi della cartella `storage/app/public/` stessa.

4. Apri `https://tuo-dominio.example.org/install` nel browser. L'installer guidato
   effettua, in ordine:

   1. verifica della versione PHP e delle estensioni richieste;
   2. verifica dei permessi di scrittura sulle cartelle necessarie;
   3. raccolta dei parametri di connessione MySQL/MariaDB e test della connessione;
   4. esecuzione delle migration del database;
   5. generazione della chiave applicativa (`APP_KEY`), se assente;
   6. configurazione del nome e del dominio dell'istanza;
   7. creazione dell'account amministratore (con generazione automatica della
      coppia di chiavi RSA per il suo Actor ActivityPub);
   8. generazione di un token segreto per il cron via web (opzionale, mostrato una
      sola volta);
   9. scrittura della configurazione nel file `.env` e blocco definitivo
      dell'installer (`storage/installed.lock`).

   **L'installer non mostra mai password o segreti dopo il completamento** e, una
   volta bloccato, ogni richiesta a `/install/*` viene reindirizzata alla home.

5. Configura il cron (vedi [Cron e attivita periodiche](configuration.it.md#cron-e-attivita-periodiche)).
6. Facoltativamente abilita le posizioni nei post importando il catalogo delle
   citta' GeoNames:

   ```bash
   php artisan openbook:update-cities
   ```

   Vedi [Posizioni nei post](configuration.it.md#posizione-dei-post) per l'importazione offline e
   i dettagli sulla privacy.

## Installazione manuale / CLI

Se preferisci non usare l'installer web (ad esempio in ambienti automatizzati), puoi
eseguire gli stessi passi da riga di comando:

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate

# Configura DB_* e OPENBOOK_* in .env, poi:
php artisan migrate --force

# Crea il primo amministratore (chiede i dati interattivamente se omessi):
php artisan openbook:make-admin --username=admin --email=admin@example.org

# Facoltativo: importa il catalogo GeoNames per abilitare le posizioni nei post:
php artisan openbook:update-cities

# (Opzionale) promuovi un account esistente a moderatore di istanza:
# php artisan openbook:make-moderator --promote=nome-utente

# Segnala all'applicazione che l'installazione e' completata:
php artisan tinker --execute="file_put_contents(storage_path('installed.lock'), 'cli - '.now());"
```

Il comando `openbook:make-admin` puo' anche promuovere un account gia' esistente:

```bash
php artisan openbook:make-admin --promote=nome-utente
```

Per i soli poteri di moderazione (senza impostazioni istanza):

```bash
php artisan openbook:make-moderator --promote=nome-utente
```

## Aggiornamento di un'istanza esistente

### Via pannello admin (consigliato su shared hosting)

In **Pannello → Aggiornamenti** un amministratore puo' consultare il manifesto
`https://about.openb.app/releases/latest.json` e, se e' disponibile una versione
piu' recente, applicare l'archivio ufficiale (verifica SHA-256, manutenzione,
migrazioni, conservazione di `.env` e `storage/`).

Prima di aggiornare: backup del database.

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

### Pubblicare una release (maintainer)

```bash
./bin/build-release.sh
```

Carica su about.openb.app lo zip, il file `*-changelog.md`, `latest.json`
generati in `dist/` e `setup-openbook.php` dalla root del repository.

## Configurazione del server web

Openbook e' un'applicazione Laravel: il **document root del web server deve puntare
alla cartella `public/`**, mai alla radice del progetto (che contiene codice
applicativo e configurazione sensibile).

### Apache (con accesso alla configurazione del VirtualHost)

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

Il file `public/.htaccess` incluso nel progetto (fornito da Laravel) gestisce gia' il
routing tramite `mod_rewrite`.

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

### Hosting con document root non configurabile (progetto dentro `public_html`)

Molti pannelli di hosting condiviso (cPanel, Plesk) impongono che il dominio serva
direttamente `public_html/` senza possibilita' di puntare a una sottocartella.
Soluzione consigliata:

1. Carica l'intero progetto **fuori** da `public_html`, ad esempio in
   `~/openbook/` (una cartella sopra la webroot);
2. Copia il **contenuto** di `~/openbook/public/` dentro `public_html/`;
3. Modifica `public_html/index.php` affinche' punti alla cartella reale del
   progetto:

   ```php
   require __DIR__.'/../openbook/vendor/autoload.php';
   $app = require_once __DIR__.'/../openbook/bootstrap/app.php';
   ```

   (adatta i percorsi `../openbook/...` in base alla posizione reale del progetto
   rispetto a `public_html/`).

Questo evita di esporre pubblicamente `app/`, `config/`, `.env` e le altre cartelle
sensibili del progetto.

#### Se non puoi nemmeno uscire da `public_html` (tutto il progetto in un'unica cartella pubblica)

Quando il pannello di hosting impone che *l'intero progetto* stia dentro la cartella
pubblica del dominio (niente cartelle "sopra" `public_html/` accessibili), l'unica
strada e' un `.htaccess` nella **radice del progetto** che instrada ogni richiesta
verso `public/` tramite `mod_rewrite`, negando esplicitamente l'accesso diretto a
tutto cio' che non deve mai essere raggiungibile:

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

Questo approccio e' piu' fragile delle due opzioni precedenti (dipende da
`mod_rewrite` e da un elenco di percorsi mantenuto a mano) e va preferito solo
quando le altre due non sono percorribili. Se in futuro aggiungi nuove cartelle al
progetto, ricorda di aggiungerle a questo elenco **senza mai includere `storage`**.
