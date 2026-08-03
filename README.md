# Openbook

Openbook e' un social network generalista **federato**, ispirato alla semplicita' dei
primi anni di Facebook ma costruito nativamente sul protocollo **ActivityPub** e
integrato con il Fediverso. Non e' un microblog, non e' un clone di Mastodon e non e'
un aggregatore di link: e' pensato per comunita' personali, territoriali, associative e
tematiche, con un'interfaccia comprensibile anche a utenti non tecnici.

Versione corrente: **0.8.10** (vedi [`CHANGELOG.md`](CHANGELOG.md)). Openbook e'
oltre la federazione bidirezionale di base: include **community** (Actor `Group`
locali e remoti, iscrizione, wall, interoperabilita' Lemmy/Friendica) e sta
lavorando alla **Fase 6** (interoperabilita' ampia con Mastodon, Misskey, PeerTube,
Pixelfed, WordPress, WriteFreely, ecc., media remoti, hardening). Le fasi 1–4 restano
la base: bootstrap e installer, dominio sociale locale, identita' ActivityPub,
consegna/ricezione delle attivita' via coda MySQL.

## Indice

- [Requisiti](#requisiti)
- [Installazione guidata (consigliata)](#installazione-guidata-consigliata)
- [Installazione manuale / CLI](#installazione-manuale--cli)
- [Aggiornamento di un'istanza esistente](#aggiornamento-di-unistanza-esistente)
- [Configurazione del server web](#configurazione-del-server-web)
- [Configurazione](#configurazione)
- [Architettura](#architettura)
  - [Federazione (Fase 3)](#federazione-fase-3)
  - [Federazione sociale (Fase 4)](#federazione-sociale-fase-4)
  - [Community (Fase 5)](#community-fase-5)
  - [Interoperabilita' e media remoti (Fase 6)](#interoperabilita-e-media-remoti-fase-6)
  - [Personalizzazione del profilo e impostazioni account](#personalizzazione-del-profilo-e-impostazioni-account)
- [Test](#test)
- [Cron e attivita periodiche](#cron-e-attivita-periodiche)
- [Sicurezza e privacy](#sicurezza-e-privacy)
- [Roadmap e stato del progetto](#roadmap-e-stato-del-progetto)
- [Changelog](CHANGELOG.md)
- [Licenza](#licenza)

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

## Installazione guidata (consigliata)

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

5. Configura il cron (vedi [Cron e attivita periodiche](#cron-e-attivita-periodiche)).

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

Una volta che l'installer si e' bloccato (`storage/installed.lock`), l'unico modo per
applicare le migration di una versione successiva e' da riga di comando (richiede
quindi accesso SSH/CLI all'hosting; su shared hosting privi di CLI non esiste oggi
un percorso equivalente via web):

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

## Configurazione

Tutte le impostazioni specifiche di Openbook sono centralizzate in
`config/openbook.php` e configurabili tramite variabili d'ambiente (vedi
`.env.example` per l'elenco completo con commenti). Le principali:

| Variabile | Descrizione |
|---|---|
| `OPENBOOK_DOMAIN` | Dominio pubblico dell'istanza, usato negli indirizzi `utente@dominio`. Deve coincidere con l'host di `APP_URL`. Se cambi dominio, aggiorna entrambi e poi `php artisan openbook:repair-federation-urls` (altrimenti Lemmy rifiuta i Follow se id e inbox sono su host diversi). |
| `OPENBOOK_INSTALLED` | Impostata automaticamente dall'installer; non modificare a mano. |
| `OPENBOOK_WEB_CRON_ENABLED` / `OPENBOOK_WEB_CRON_TOKEN` | Abilitano l'esecuzione dei processi periodici via richiesta HTTP, per hosting privi di cron reale. |
| `OPENBOOK_REGISTRATION_OPEN` / `OPENBOOK_REGISTRATION_REQUIRES_APPROVAL` | Controllano l'apertura delle registrazioni. |
| `OPENBOOK_MEDIA_MAX_SIZE_KB` / `OPENBOOK_MEDIA_MAX_ATTACHMENTS` | Dimensione massima (KB) e numero massimo di immagini allegabili a un post. |
| `OPENBOOK_POST_MAX_LENGTH` | Lunghezza massima (caratteri) del testo di un post. |
| `OPENBOOK_COMMENT_MAX_DEPTH` | Livelli di annidamento dei commenti considerati "normali" in configurazione (la struttura reale non ha un limite rigido, vedi [Limitazioni](#limitazioni-note-stato-08x)). |
| `OPENBOOK_SEARCH_MIN_LENGTH` / `OPENBOOK_SEARCH_PER_SECTION` | Lunghezza minima della query e risultati massimi per sezione nella ricerca locale. |
| `DB_PERSISTENT` | Se `true`, riusa le connessioni PDO MySQL/MariaDB fra richieste. Consigliato su hosting con limite di nuove connessioni/secondo (es. Hostinger: errore `2002 Operation not permitted`). |
| `OPENBOOK_FEED_PER_PAGE` | Numero di post per pagina nel feed personale, nel feed locale e nelle pagine profilo/hashtag. |
| `OPENBOOK_ACTOR_KEY_BITS` | Lunghezza (bit) delle chiavi RSA generate per i nuovi Actor ActivityPub (minimo consigliato: 2048). |
| `OPENBOOK_SIGNATURE_MAX_SKEW` | Scarto massimo (secondi) tollerato tra l'header `Date` di una richiesta firmata in ingresso e l'orologio locale, prima di rifiutarla. |
| `OPENBOOK_FETCH_MAX_REDIRECTS` / `OPENBOOK_FETCH_TIMEOUT` / `OPENBOOK_FETCH_CONNECT_TIMEOUT` / `OPENBOOK_FETCH_MAX_BYTES` | Limiti applicati dal client HTTP protetto da SSRF (`SafeHttpClient`) usato per recuperare Actor e risorse remote. |
| `OPENBOOK_FETCH_ALLOW_INSECURE` | Consente richieste in uscita su HTTP semplice (solo per sviluppo locale; in produzione resta sempre richiesto HTTPS). |
| `OPENBOOK_ACTOR_CACHE_TTL_HOURS` | Per quante ore un Actor remoto gia' risolto viene considerato "fresco" prima di essere ri-scaricato. |
| `OPENBOOK_INBOX_MAX_BODY_BYTES` / `OPENBOOK_INBOX_MAX_JSON_DEPTH` | Limiti di dimensione e profondita' JSON applicati alle attivita' in ingresso, prima ancora della verifica crittografica. |
| `OPENBOOK_DELIVERY_MAX_ATTEMPTS` | Numero massimo di tentativi per la consegna di una singola attivita' in uscita, prima che finisca in `failed_jobs`. Gli intervalli di backoff tra un tentativo e l'altro (1, 5, 15, 60, 360, 1440 minuti) sono fissi. |

Il caricamento di immagini richiede l'estensione PHP `gd` (verificata dall'installer come
requisito **consigliato**, non bloccante): senza `gd` l'istanza funziona regolarmente,
ma sara' possibile pubblicare solo post testuali.

## Architettura

Il codice e' organizzato per **separare esplicitamente** il dominio applicativo
locale dalla rappresentazione e dalla meccanica di federazione ActivityPub, cosi'
come richiesto dal design del progetto:

```
app/
  Domain/            # Dominio applicativo locale
    Accounts/
    Profiles/
    Posts/           # Post, allegati, hashtag, menzioni, rendering del testo
    Comments/        # Commenti (di primo livello e risposte annidate)
    Reactions/        # Mi piace e condivisioni (Like/Announce a livello locale)
    SocialGraph/      # Follow tra Actor (locali e remoti)
    Notifications/    # Notifiche locali (non federate)
  Federation/        # Tutto cio' che riguarda ActivityPub
    Actors/           # Actor (locali e remoti), RemoteActorResolver (fetch + WebFinger)
    Inbox/            # InboxItem grezzo + InboxActivityProcessor (elaborazione semantica)
    Resolution/       # ObjectResolver: URI ActivityPub -> Actor/Post/Comment locali
    Delivery/         # ActivityDelivery: fan-out delle attivita' in uscita verso le inbox remote
    Serialization/    # ActorSerializer, NoteSerializer, CollectionSerializer, ActivitySerializer
  Jobs/
    Federation/       # ProcessInboxActivityJob, DeliverActivityJob (code "inbox"/"delivery")
  Infrastructure/    # Dettagli tecnici trasversali (DB, sicurezza, installazione, media)
    Database/
    Installation/
    Security/
      Http/           # SsrfGuard, SafeHttpClient, DnsResolver: fetch remoto protetto da SSRF
    Media/            # Upload, validazione, miniature (Media, MediaVariant, MediaUploader)
  Application/       # Servizi applicativi che orchestrano il dominio
    Services/
    Queries/          # Query di lettura complesse (es. FeedQuery)
  Http/              # Controller, richieste HTTP, middleware
  Policies/          # Autorizzazioni centralizzate (PostPolicy, CommentPolicy)
```

I controller **non contengono logica di dominio**: validano la richiesta, verificano
autorizzazioni/autenticazione, invocano un servizio applicativo (in
`app/Application/Services`) e restituiscono la risposta. Ad esempio, la creazione
completa di un account (utente + profilo + impostazioni + Actor ActivityPub + coppia
di chiavi RSA + endpoint) e' incapsulata in un'unica transazione dal servizio
`App\Application\Services\AccountRegistrar`, usato sia dal controller di
registrazione, sia dall'installer, sia dal comando CLI `openbook:make-admin`. Allo
stesso modo, `PostComposer`, `CommentComposer`, `FollowManager`, `ReactionManager` e
`AnnounceManager` incapsulano ciascuno una singola operazione di dominio in una
transazione, aggiornando contatori denormalizzati e generando le notifiche pertinenti
tramite `NotificationCreator`. Dalla Fase 4 in poi, ciascuno di questi servizi invoca
anche `ActivityDelivery` **dopo** il commit della transazione, quando l'attore che
compie l'azione e' locale e il destinatario (o i follower) coinvolgono almeno un
Actor remoto: e' l'unico punto in cui la logica di dominio "sa" della federazione, e
resta comunque un'aggiunta a valle, mai una condizione per il successo dell'azione
locale.

Ogni account locale possiede fin da subito un Actor ActivityPub di tipo `Person`
(tabelle `actors`, `actor_keys`, `actor_endpoints`), esposto al Fediverso (vedi
[Federazione](#federazione-fase-3) piu' sotto). Il dominio sociale locale e' modellato
pensando alla federazione: `follows` e `likes` collegano **Actor** (non utenti), cosi'
da poter accogliere attori remoti senza modifiche allo schema.

Le chiavi private degli Actor sono cifrate a riposo (cast `encrypted` di Eloquent,
basato su `APP_KEY`) e non vengono mai esposte da API, log o messaggi di errore.

### Post, commenti e reazioni

- Un post e' sempre testo semplice: il corpo digitato dall'utente viene mostrato con
  escaping automatico di Blade (mai HTML grezzo), con hashtag e menzioni
  "linkificati" in un secondo momento sul testo gia' sfuggito
  (`App\Domain\Posts\PostBodyRenderer`). Questo elimina di fatto il rischio di XSS
  lato contenuti locali senza bisogno di una libreria di sanitizzazione HTML; il
  contenuto HTML dei post remoti (Fase 4) viene invece ridotto a testo semplice prima
  di entrare nella stessa pipeline (vedi [Federazione sociale](#federazione-sociale-fase-4)),
  evitando cosi' di introdurre un sanitizzatore HTML completo.
- I commenti vivono in una tabella dedicata (`comments`), separata da `posts`, con
  `parent_comment_id` per le risposte annidate: l'intero albero di un post viene
  caricato con un'unica query e ricostruito in memoria.
- "Mi piace" (`likes`) e menzioni (`mentions`) sono relazioni polimorfiche, gia'
  pronte per applicarsi sia a post sia a commenti.
- Le condivisioni (`announces`) non duplicano mai il post originale: sono un
  semplice riferimento "attore ha condiviso questo post", che il feed usa per
  mostrare il contenuto anche a chi segue chi ha condiviso (non l'autore originale).
  Compaiono anche nel feed personale e nel profilo di chi condivide (`FeedQuery`),
  con l'indicazione "ha condiviso questo post" sopra la card — ordinate per il
  momento della condivisione, non per la data di pubblicazione originale del post,
  cosi' una condivisione recente di un post vecchio compare comunque in cima.
  Condividere un post proprio non aggiunge l'indicazione (sarebbe ridondante:
  compare gia' come post proprio).
- I contatori (`likes_count`, `comments_count`, `announces_count`) sono
  denormalizzati sulle righe di post/commento e aggiornati transazionalmente, per
  evitare conteggi pesanti a ogni richiesta del feed.
- Il feed (`App\Application\Queries\FeedQuery`) unisce post propri, post di chi si
  segue e condivisioni fatte da chi si segue, rispettando la visibilita' (pubblica,
  non elencata, solo-follower, diretta) e senza alcun algoritmo di raccomandazione:
  ordinamento sempre cronologico inverso.

### Federazione (Fase 3)

Ogni Actor locale e' ora raggiungibile dal Fediverso tramite gli endpoint standard di
ActivityPub, tutti serviti **senza sessione ne' CSRF** (`routes/activitypub.php`,
caricate fuori dal gruppo middleware `web`), come richiesto da protocolli pensati per
essere consumati da altri server e non da browser:

- **Scoperta**: `/.well-known/webfinger?resource=` (risolve `acct:utente@dominio` o
  l'URL canonico dell'Actor) e `/.well-known/nodeinfo` + `/nodeinfo/2.1`
  (metadati dell'istanza e statistiche d'uso aggregate, senza dati personali). Il
  documento NodeInfo dichiara sempre `software.name: "openbook"`, la versione
  reale (`config('openbook.version')`, la stessa riportata nel footer) e un link a
  `software.homepage`, cosi' gli strumenti del Fediverso che leggono NodeInfo
  riconoscono correttamente il software dietro l'istanza (non un fork/derivato di
  altre piattaforme). Per lo stesso motivo lo User-Agent delle richieste in uscita
  (`config('openbook.federation.user_agent')`) riporta la versione reale del
  software, non un valore fisso scollegato da essa. Questi endpoint pubblici (piu'
  il profilo/post/commento canonico sotto, vedi content negotiation) espongono
  anche l'intestazione CORS `Access-Control-Allow-Origin: *` (`config/cors.php`):
  sono documenti pubblici per definizione, e senza quell'intestazione un browser
  bloccherebbe la lettura cross-origin da parte di strumenti di verifica del
  software federato eseguiti lato client.
- **Content negotiation**: le pagine profilo (`/@utente`), post (`/posts/{uuid}`) e
  commento (`/comments/{uuid}`) restituiscono HTML a un browser e un documento
  ActivityPub (`Person`/`Note`/`Tombstone`) quando l'header `Accept` richiede
  `application/activity+json` o `application/ld+json`. Un contenuto eliminato viene
  rappresentato come `Tombstone` anziche' sparire silenziosamente.
- **Collezioni**: `outbox`, `followers` e `following` di ogni utente sono esposte come
  `OrderedCollection`/`OrderedCollectionPage` paginate; l'outbox avvolge i post
  pubblici e non elencati in attivita' `Create`.
- **Inbox**: ogni utente ha un inbox dedicato (`/users/{utente}/inbox`) e l'istanza ha
  un inbox condiviso (`/inbox`). Le richieste in ingresso vengono autenticate con
  **HTTP Signatures** (bozza Cavage, `rsa-sha256`: verifica di `Signature`, digest del
  corpo, scarto massimo dell'header `Date`, corrispondenza tra l'Actor firmatario e il
  campo `actor` dell'attivita', con un tentativo di aggiornamento della chiave in caso
  di rotazione), validate nella forma minima (content-type, dimensione, profondita'
  JSON) e **deduplicate** tramite vincolo univoco su `remote_activity_uri`. Le attivita'
  valide vengono memorizzate grezze in `inbox_items` con stato `pending`: la loro
  **elaborazione semantica** avviene fuori dal ciclo HTTP (vedi
  [Federazione sociale](#federazione-sociale-fase-4) qui sotto), cosi' da non bloccare
  mai chi consegna un'attivita' in attesa di elaborazioni pesanti.
- **Recupero di Actor remoti**: `RemoteActorResolver` scarica, valida e mette in cache
  localmente il documento `Person` di un Actor remoto (necessario per verificare le
  firme in ingresso, per la ricerca remota e per risolvere gli attori citati dalle
  attivita'); ogni fetch in uscita passa da `SafeHttpClient`, che applica `SsrfGuard`
  per rifiutare URL non pubblici (IP privati, loopback, riservati), impone HTTPS in
  produzione, limita redirect/timeout/dimensione della risposta e blocca il *DNS
  rebinding* fissando la connessione all'IP gia' validato (`CURLOPT_RESOLVE`). La
  stessa protezione si applica anche alle richieste **in uscita** di consegna
  (`SafeHttpClient::post()`), che inoltre non seguono mai un redirect (la firma HTTP
  e' calcolata sull'URL esatto di destinazione).

### Federazione sociale (Fase 4)

Le attivita' accettate nell'inbox (Fase 3) vengono ora **elaborate**, e le azioni
locali rilevanti vengono **consegnate** ai server remoti coinvolti: la federazione e'
finalmente bidirezionale.

- **Elaborazione dell'inbox**: ogni `InboxItem` con stato `pending` viene accodato su
  `ProcessInboxActivityJob` (coda `inbox`) subito dopo la ricezione
  (`InboxController::receive()`, dopo il commit). `InboxActivityProcessor` interpreta
  l'attivita' e produce l'effetto di dominio corrispondente **riusando sempre gli
  stessi servizi applicativi del percorso locale** (`FollowManager`, `ReactionManager`,
  `AnnounceManager`), cosi' che i due percorsi restino sempre coerenti:
  - `Follow` verso un Actor locale crea la riga in `follows` (`pending` o `accepted`
    a seconda di `manuallyApprovesFollowers`) e, se accettato subito, risponde con un
    `Accept`;
  - `Accept`/`Reject` completano un `Follow` originato da questa istanza verso un
    Actor remoto;
  - `Undo` (di `Follow`, `Like` o `Announce`) annulla la relazione o la reazione
    corrispondente;
  - `Like`/`Announce` su un post o commento locale aggiornano i contatori e generano
    una notifica, esattamente come un Mi piace/condivisione locale;
  - `Create`/`Update` con oggetto postabile (`Note`, `Page`, `Article`, `Video`,
    `Image`) mettono in cache localmente il post o commento remoto (tabelle
    `posts`/`comments`, identificati dalla colonna `uri`), ma **solo se rilevanti**
    per questa istanza (l'autore e' seguito da un Actor locale, la Note risponde a
    un contenuto che gia' conosciamo, oppure menziona esplicitamente un Actor
    locale): nessun contenuto remoto viene conservato "a caso". Il contenuto HTML
    viene ridotto a testo semplice (`RemoteContentSanitizer`), preservando gli
    `<a href>` come `[etichetta](url)`; le immagini in `attachment` restano come
    URL remoti in galleria. Poi passa dalla stessa pipeline di rendering sicura
    dei post locali;
  - `Update` con oggetto `Person`/`Group` (un altro server che notifica un cambio al
    profilo di un proprio utente) aggiorna direttamente la cache locale dell'Actor
    remoto (`actors`/`actor_keys`/`actor_endpoints`) applicando il documento
    incorporato, senza bisogno di un ulteriore fetch HTTP; accettato solo se l'id
    dichiarato nel documento coincide con l'Actor firmatario;
  - `Delete` marca come eliminato un post/commento locale o la sua copia remota in
    cache, esattamente come un'eliminazione locale (mai una cancellazione fisica
    della riga, per preservare l'id).
- **Consegna delle attivita' in uscita**: `ActivityDelivery` calcola l'insieme di
  inbox remote di destinazione (deduplicate sulla `sharedInbox` quando piu' follower
  vivono sullo stesso server) e accoda una `DeliverActivityJob` per ciascuna (coda
  `delivery`, `afterCommit()`). Ogni job firma l'attivita' con la chiave privata
  dell'Actor locale mittente e la invia con `SafeHttpClient::post()`; un fallimento
  temporaneo (errore di rete, risposta 5xx) viene ritentato con backoff crescente (1,
  5, 15, 60, 360, 1440 minuti, configurabile), mentre un errore permanente (violazione
  SSRF, chiave privata assente) fallisce subito senza ritentare. E' cablata in ogni
  punto in cui un Actor locale compie un'azione federabile: `FollowManager` (`Follow`
  /`Accept`/`Reject`/`Undo`), `ReactionManager` (`Like`/`Undo`), `AnnounceManager`
  (`Announce`/`Undo`, consegnato sia ai follower remoti di chi condivide sia
  all'autore originale se distinto), `PostComposer`/`PostController` (`Create`
  /`Delete`) e `CommentComposer`/`CommentController` (`Create`/`Delete`, sempre
  recapitato anche all'autore del contenuto padre come destinatario diretto). I
  messaggi con visibilita' "diretta" vengono consegnati solo agli Actor
  esplicitamente menzionati, mai a tutti i follower.
- **Coda e cron**: la coda usa il driver database di Laravel (tabelle `jobs` e
  `failed_jobs`, gia' presenti dall'installer), coerente con i vincoli di shared
  hosting (nessun processo permanente, nessun Redis/RabbitMQ). I comandi
  `openbook:process-inbox` e `openbook:deliver` processano rispettivamente le code
  `inbox` e `delivery` con `--stop-when-empty`, cosi' da terminare da soli invece di
  restare in ascolto indefinitamente; `openbook:cron` li invoca entrambi in sequenza
  dividendo un budget di tempo massimo configurabile, ed e' il comando pensato per
  essere schedulato (vedi [Cron e attivita periodiche](#cron-e-attivita-periodiche)).
- **Ricerca**: la pagina "Cerca" (`/cerca?q=...`, form in GET) ha due percorsi.
  Se la query e' un indirizzo federato (`utente@dominio`, con o senza `@`
  iniziale, `acct:...`, o l'URL di un profilo), lo risolve localmente se il
  dominio corrisponde a questa istanza, altrimenti tramite WebFinger + recupero
  del documento Actor (`RemoteActorResolver::resolveByHandle()`), poi
  reindirizza al profilo. Se invece la query ha una forma diversa (parola
  chiave, frase, username senza dominio), esegue una ricerca *solo locale*
  (`LocalSearchQuery`) su persone (username, nome visualizzato, bio; rispetta
  `discoverable`), post e commenti di Actor locali (visibilita' rispettata), e
  hashtag. Nessun Elasticsearch: LIKE case-insensitive con jolly escapati,
  limiti configurabili (`OPENBOOK_SEARCH_MIN_LENGTH`,
  `OPENBOOK_SEARCH_PER_SECTION`). Un Actor remoto risolto ha una pagina profilo
  di comodo (`/attori/{id}`, mai un identificatore ActivityPub canonico) con
  statistiche, eventuale biografia e un pulsante di follow che avvia il flusso
  `Follow`/`Accept` reale. In tutta l'interfaccia (card dei post, commenti,
  notifiche) gli autori sono ora mostrati tramite
  `Actor::displayName()`/`Actor::avatarUrl()`/`Actor::profileUrl()`, che
  funzionano in modo identico per attori locali e remoti. La pagina profilo
  recupera anche (`RemoteOutboxFetcher`, cache con TTL separato in
  `actors.posts_fetched_at`) i post pubblici recenti dall'outbox reale
  dell'Actor (con fallback al feed Atom se l'outbox e' stub, tipico Pixelfed),
  cosi' da mostrarne i contenuti anche se nessun Actor locale lo segue ancora:
  senza questo passaggio un profilo appena scoperto risulterebbe spesso senza
  post, dato che l'inbox mette in cache solo contenuto gia' ritenuto rilevante
  (vedi limitazioni note piu' sotto).
- **Elenchi follower/seguiti**: i contatori "Follower" e "Seguiti" di ogni profilo
  (locale o remoto) sono link verso una pagina paginata con l'elenco reale
  (`FollowListQuery`), condivisa fra profili locali (`/@utente/follower`,
  `/@utente/seguiti`) e Actor remoti (`/attori/{id}/follower`, `/attori/{id}/seguiti`
  con redirect al profilo locale se l'Actor risulta essere di questa istanza). Ogni
  riga mostra un pulsante segui/smetti di seguire coerente con lo stato reale del
  visitatore (`FollowManager::statusMapFor()`, una sola query per l'intera pagina). Il
  contatore "Community" sul profilo conta i Group (locali o remoti) a cui l'utente e'
  iscritto. I profili espongono inoltre i tab **Post** / **Foto** (rullino delle
  immagini allegate ai post visibili).

### Community (Fase 5)

Le community sono Actor ActivityPub di tipo `Group`:

- **Locali**: creazione da UI, slug `/c/{slug}`, WebFinger `nome@dominio`, iscrizione
  (Follow/Accept), wall dei post dei membri, Announce del Group in uscita, community
  private con approvazione, moderatori delegati. Elenco in `/community` con switch
  **Locali** / **Remote**.
- **Remote** (Lemmy, Friendica, …): ricerca `nome@dominio`, iscrizione federata,
  profilo `/attori/{id}` con composer per i membri, ingestione di Announce/Page
  (FEP-1b12). Gli URI Actor locali usano lo schema Mastodon `/users/{username}` per
  compatibilita' (Lemmy rifiuta gli id con `@` percent-encodato).

### Interoperabilita' e media remoti (Fase 6)

Oltre a `Note` e `Page`, l'inbox/outbox accettano `Article` (WordPress ActivityPub,
WriteFreely), `Video` (PeerTube, anche con `attributedTo` Person+Group) e `Image`.
Gli allegati immagine remoti restano URL https in `media.remote_url` (galleria e
rullino profilo) senza download sull'istanza. Se l'outbox e' uno stub (tipico
Pixelfed: solo `totalItems`), il profilo remoto ricade sul feed Atom `{actor}.atom`.
Per Wafrn (outbox vuoto) si usa l'API pubblica `/api/v2/blog`. **Threads**
(Meta) non espone i post nell'outbox ActivityPub: sul profilo remoto si
possono vedere solo i contenuti gia' ricevuti in inbox dopo un Follow (e
solo se l'account ha abilitato la condivisione sul Fediverso). La sezione Mondo
propone account remoti da scoprire e, oltre i primi 5, l'elenco completo in
`/mondo/scopri` con scorrimento infinito. SSRF, blocco domini e firme HTTP
restano i vincoli di sicurezza di base.

Non fanno ancora parte del prodotto maturo: un vero sistema di destinatari per i
messaggi diretti (oltre menzioni), e tool avanzati di debug federazione (oltre al
pannello code in admin).

### Personalizzazione del profilo e impostazioni account

Il database predisponeva gia' dalle prime fasi le colonne per la personalizzazione
dell'account (`profiles.avatar_path`/`cover_path`/`bio`/`links`,
`user_settings.locale`/`default_post_visibility`/`manually_approves_followers`
/`discoverable`); la pagina **Impostazioni** (`/impostazioni`, link nel menu utente e
pulsante "Modifica profilo" sul proprio profilo) le rende modificabili:

- **Profilo pubblico**: nome visualizzato, biografia (max 500 caratteri), fino a 4 link
  con etichetta, avatar e immagine di copertina. Il caricamento delle immagini
  (`ProfileImageUploader`) valida il tipo effettivo del file (mai la sola estensione),
  rimuove i metadati EXIF e ridimensiona con GD quando disponibile (avatar max 512px,
  copertina max 1600px sul lato piu' lungo), riusando la stessa logica di base gia'
  impiegata per gli allegati dei post (`ManipulatesImagesWithGd`, trait condiviso con
  `MediaUploader`). Il file precedente viene sempre rimosso quando se ne carica uno
  nuovo, per non accumulare copie orfane su hosting con quota limitata. Modificare il
  nome visualizzato aggiorna anche il campo `name` dell'Actor ActivityPub locale, che e'
  il valore effettivamente esposto ai server remoti (`ActorSerializer`). Ogni modifica
  al profilo pubblico (nome, biografia, link, avatar, copertina) o all'opzione "Account
  protetto" invia inoltre un `Update` ActivityPub a tutti i follower remoti
  (`ProfileUpdater`/`AccountPreferencesUpdater` + `ActivityDelivery::deliverToFollowers()`),
  con l'intero documento Actor aggiornato come oggetto incorporato: senza questo
  passaggio i server remoti avrebbero continuato a mostrare una copia obsoleta del
  profilo fino alla scadenza della loro cache locale (fino a
  `openbook.federation.actor_cache_ttl_hours`, 24 ore di default). Simmetricamente, un
  `Update` con oggetto `Person`/`Group` ricevuto da un'altra istanza (perche' un utente
  remoto ha modificato il proprio profilo) aggiorna subito la copia in cache del suo
  Actor (`InboxActivityProcessor::handleUpdateActor()`), applicando direttamente il
  documento incorporato invece di aspettare un nuovo fetch; viene accettato solo se
  l'id dichiarato nel documento coincide con l'Actor che ha firmato la richiesta, cosi'
  nessuno puo' aggiornare il profilo di un altro. Prima di salvare, il file scelto viene
  mostrato subito in anteprima (lato client,
  via `FileReader`, senza upload). `Profile::avatarUrl()`/`coverUrl()` costruiscono
  l'URL pubblico tramite il disco "public" configurato (`Storage::disk('public')->url()`),
  come gia' avviene per gli allegati dei post (`Media::url()`), invece di affidarsi
  all'helper `asset()`: quest'ultimo dipende dallo schema/host rilevati sulla singola
  richiesta e puo' produrre URL incoerenti (es. `http://` invece di `https://`) dietro
  proxy o load balancer che non riportano correttamente lo schema originale.
- **Lingua dell'interfaccia**: ogni utente puo' scegliere tra le lingue elencate in
  `config('openbook.locales')` (italiano e inglese al momento). Il middleware
  `SetUserLocale`, applicato a tutte le richieste web, imposta la lingua dell'app in
  base a `user_settings.locale` per gli utenti autenticati; chi non ha ancora
  effettuato   l'accesso vede invece la lingua dedotta dall'header `Accept-Language`
  del browser (italiano se preferito, inglese in ogni altro caso), cosi' anche
  la homepage pubblica si presenta gia' nella lingua giusta prima della
  registrazione. Una richiesta priva di quell'intestazione (mai un browser
  reale, tipico di crawler/monitoraggi) non viene forzata e resta sulla lingua
  di default dell'istanza (`app.locale`).
- **Visibilita' predefinita dei nuovi post**: il selettore di visibilita' nel composer
  usa ora `user_settings.default_post_visibility` come valore iniziale (il pannello si
  apre automaticamente se il default non e' "pubblica"), restando comunque modificabile
  post per post.
- **Account protetto**: la casella "Account protetto" aggiorna sia
  `user_settings.manually_approves_followers` sia (la colonna effettivamente letta da
  `FollowManager`) `actors.manually_approves_followers`, cosi' che le due restino
  sempre coerenti fra loro.
- **Presenza nei suggerimenti**: disattivando "Includi il mio account nei suggerimenti
  e nelle ricerche" (`user_settings.discoverable`), l'account smette di comparire nel
  riquadro "Persone da seguire" della sidebar (resta comunque raggiungibile in modo
  diretto, ad esempio tramite ricerca federata dell'indirizzo esatto).
- **Riquadro "Questa istanza"**: non mostra piu' il numero di iscritti (un dato che
  espone inutilmente le dimensioni reali dell'istanza), ma i tag piu' usati di
  recente dalla community locale (`App\Application\Queries\PopularHashtagsQuery`):
  solo hashtag su post pubblicati da Actor *locali*, con visibilita' pubblica o non
  elencata, mai da contenuto remoto semplicemente in cache o da post riservati a
  follower/destinatari diretti.
- **Lightbox sulle immagini dei post**: cliccando su un'immagine allegata a un post
  si apre un overlay a schermo intero con l'originale a piena risoluzione (frecce
  precedente/successiva se il post ne ha piu' di una, chiusura con Esc, click fuori
  dall'immagine o pulsante dedicato). Nessuna libreria esterna: markup condiviso in
  `layouts.app` e un solo script (`public/assets/js/lightbox.js`) che delega gli
  eventi su tutta la pagina, cosi' funziona identico su feed, profilo, pagina del
  singolo post e sezione "Mondo". Colto anche l'occasione per usare finalmente in
  feed la miniatura gia' generata al caricamento (`MediaUploader`, mai sfruttata
  finora): l'anteprima nel post mostra la miniatura per intero (mai ritagliata:
  `object-fit: contain` con sfondo neutro a riempire eventuali bande laterali/
  superiori quando le proporzioni non coincidono con il riquadro), il lightbox
  recupera invece l'originale a piena risoluzione tramite l'attributo
  `data-full-src` e lo mostra il piu' grande possibile senza mai ingrandirlo oltre
  la sua dimensione naturale.
- **Versionamento degli asset statici (`App\Support\Assets`)**: `app.css` e
  `lightbox.js` sono serviti da `public/` senza alcuna pipeline di build (niente
  Vite/webpack, per restare compatibili con l'hosting condiviso): senza una query
  string che cambi ad ogni modifica, il browser puo' continuare a servire dalla
  cache una copia vecchia del file anche dopo un aggiornamento del software (causa
  tipica di "l'ho aggiornato ma non cambia nulla", o peggio di markup nuovo abbinato
  a CSS/JS vecchi che si comporta in modo incoerente). Le viste ora referenziano
  questi due file tramite `App\Support\Assets::url()`, che aggiunge automaticamente
  `?v=<ultima modifica del file>` alla URL.
- **Scorrimento infinito al posto della paginazione a numeri**: feed, "Mondo",
  profilo (locale o remoto) e pagina di un hashtag non mostrano piu' frecce/numeri
  di pagina in fondo all'elenco dei post. Quando l'utente si avvicina alla fine
  della pagina, `public/assets/js/infinite-scroll.js` scarica in background la
  pagina successiva (lo stesso URL "?page=N" di sempre) e ne innesta i soli post
  in coda all'elenco corrente, senza alcuna route/API dedicata ne' libreria
  esterna. La paginazione classica resta comunque disponibile dentro un
  `<noscript>`, per chi naviga senza JavaScript. Impostare `data-infinite-scroll`
  e "data-next-url" su un contenitore di post e' sufficiente perche' lo script si
  attivi: vedi `resources/views/posts/_feed.blade.php`, il parziale condiviso da
  tutte queste pagine. Approfittata anche l'occasione per dare a
  `FeedQuery`/`HashtagController` un ordinamento davvero deterministico
  (`ORDER BY ... , id DESC`): senza un criterio di spareggio, due post pubblicati
  nello stesso secondo potevano finire duplicati o saltati passando da una pagina
  all'altra, difetto gia' presente con la paginazione classica ma molto piu'
  evidente con lo scorrimento continuo.
- **Card del post**: like / commento / condivisione sono solo icone (con il
  contatore numerico accanto; i testi restano come `aria-label` per
  l'accessibilita'). L'eliminazione non e' piu' in linea con le altre azioni:
  compare solo per i *propri post locali* (mai per Note remote in cache, ne'
  per un admin: `PostPolicy::delete` rifiuta i post con `uri` valorizzato) e
  vive in un menu a tre puntini verticali in alto a destra della card
  (`<details class="ob-post__menu">`, con `post-menu.js` per chiudere al click
  fuori). Sui post remoti, il click sull'orario apre l'`uri` ActivityPub
  originale in una nuova scheda (`target="_blank" rel="noopener noreferrer"`);
  sui post locali continua a portare alla pagina Openbook del post. Lo stesso
  schema (icone + menu a tre puntini per Elimina, mai sui remoti) e' applicato
  anche ai commenti (`comments/_comment.blade.php`, `CommentPolicy`).
- **Navbar**: l'icona campanella apre un dropdown con le notifiche recenti
  (la pagina completa resta nella sidebar sinistra); l'icona search apre un
  campo di input inline invece di andare subito a `/cerca` (l'invio del form
  usa comunque la stessa ricerca locale/federata). Script dedicato:
  `public/assets/js/header-panels.js`. Su desktop, dopo lo scroll oltre il
  composer, appare al centro della header un pulsante **+** che riporta
  il focus sul composer (o alla Home se si e' altrove); su mobile lo stesso
  controllo e' un FAB discreto in basso a destra (`compose-shortcut.js`).
- **Emoji**: nei composer di post e commenti (anche risposte) un'icona
  sorriso apre un picker locale stile Mastodon (categorie, ricerca,
  recenti in `localStorage`). Solo Unicode nativo del sistema, nessuna
  CDN / Twemoji (`emoji-data.js` + `emoji-picker.js`).
- **Segnalazioni**: dal menu a tre puntini di ogni post altrui (locale o
  remoto) si puo' aprire una segnalazione locale (motivo + dettagli
  opzionali), archiviata in `reports` e gestita dal pannello di
  controllo (`/admin/segnalazioni`). Non e' federata; non si puo'
  segnalare un proprio post. Throttle su `POST /posts/{post}/segnala`.
- **Pannello di controllo** (`/admin`, v0.5.0–0.6.0): accessibile ad
  amministratori e moderatori (`is_admin` / `is_moderator`). Include
  dashboard, coda segnalazioni su post e commenti (revisiona / archivia /
  azione, con soft-delete opzionale dei soli contenuti locali), gestione
  utenti locali (sospensione / disabilitazione; promozione moderatori e
  admin), impostazioni istanza (`site_name`, `registration_open`, regole
  e privacy policy Markdown, limiti post/commenti/media), blocchi dominio federato,
  ispezione coda federazione e registro azioni. CLI ancora disponibile:
  `openbook:make-admin` / `openbook:make-moderator`.
- **Embed video**: se il body di un post contiene un link YouTube
  (`youtube.com`, `youtu.be`, Shorts, ...) o PeerTube (`/w/...`,
  `/videos/watch/...`), sotto il testo viene mostrato un player iframe
  (solo il *primo* link video del post). YouTube usa
  `youtube-nocookie.com`; PeerTube e' riconosciuto dalla forma del path
  tipica delle istanze (`VideoEmbedFinder`).
- **Hashtag nelle bio**: hashtag (e URL/menzioni) nelle biografie dei
  profili locali e remoti sono linkificati con lo stesso
  `PostBodyRenderer` usato per post e commenti; vale anche per lo
  snippet bio nei risultati di ricerca. Sui remoti il `summary` HTML
  viene prima ridotto a testo piano (`RemoteContentSanitizer::toPlainText`).
- **Condivisione con citazione**: l'icona share sulla card apre un menu
  con *Condivisione diretta* (Announce / boost, come prima) oppure
  *Condivisione con citazione*. La citazione porta al composer della
  Home con il post originale annidato sotto il testo; alla pubblicazione
  nasce un nuovo post (`quoted_post_id`) che nel feed mostra la card
  originale dentro la propria. La citazione alimenta anche il contatore
  di condivisione dell'originale (stessa riga `announces` della share
  diretta; se l'utente aveva gia' condiviso, non si doppia). Federazione
  in uscita: `quoteUrl` sulla Note piu' link di fallback nel `content`,
  e Announce ai follower. I commenti non hanno share (solo like/risposta).

### Sezione "Mondo"

Una nuova voce "Mondo" nella sidebar sinistra (`/mondo`) da' una finestra su cio' che
arriva dal resto del fediverso verso questa istanza. **Non e' ne' puo' essere un indice
completo del fediverso**: Openbook non lo esplora ne' lo indicizza attivamente, quindi
questa pagina mostra solo cio' che e' gia' stato messo in cache localmente perche'
rilevante (`InboxActivityProcessor::isRelevant()` — autore seguito da un Actor locale,
risposta a un contenuto gia' noto, o menzione di un Actor locale). E' un limite dichiarato
in interfaccia, non un dettaglio implementativo nascosto.

- **Timeline**: tutti i post pubblici di Actor *remoti* gia' in cache
  (`FeedQuery::world()`), ordinati per data di pubblicazione decrescente, a prescindere
  da chi li segue (a differenza del feed personale). I post locali non compaiono: vivono
  gia' nella Home.
- **Account da scoprire**: un piccolo elenco di Actor remoti proposti
  (`PopularRemoteActorsQuery`), con lo stesso pulsante "Segui" usato altrove
  (`actors.follow`). Se ce ne sono piu' di cinque, un link "Vedi altro" apre
  `/mondo/scopri` con l'elenco paginato e scorrimento infinito. Non esistendo un
  conteggio follower autoritativo per un Actor remoto (ne' un indice di
  "popolarita' reale" nel fediverso), la classifica usa solo segnali visibili da
  questa istanza, in ordine: quanti Actor locali lo seguono gia'
  (`follows.status = accepted`), poi la data del suo post pubblico piu' recente in
  cache. Un Actor senza nessuno dei due segnali (mai seguito localmente, mai un post
  pubblico in cache) non viene proposto, e chi e' gia' seguito dal visitatore viene
  escluso.

## Test

Il progetto usa PHPUnit. La suite gira di default su SQLite in memoria (vedi
`phpunit.xml`), quindi non richiede un database MySQL per essere eseguita:

```bash
php artisan test
```

La suite copre bootstrap/installer/autenticazione, il dominio sociale locale
(post, media, commenti, reazioni, follow, feed, notifiche), l'identita' e la
federazione sociale (`tests/Feature/Federation`,
`tests/Unit/Infrastructure/Security`), le community (`tests/Feature/Communities`)
e i casi di interoperabilita' (Article/Video/allegati, fallback Atom Pixelfed,
API blog Wafrn, Accept Lemmy, Mondo/scopri, rullino profilo). In particolare:

- generazione e verifica delle HTTP Signatures, `SsrfGuard` (rifiuto di IP
  privati/loopback/riservati, DNS che risolve a indirizzi non pubblici, fallimenti di
  risoluzione), WebFinger, NodeInfo, content negotiation su profilo/post/commento
  (incluse le regole di visibilita' per i richiedenti anonimi e la rappresentazione
  `Tombstone`), collezioni outbox/followers/following, e l'intero ciclo di vita
  dell'inbox a livello di trasporto (attivita' firmata correttamente, firma mancante,
  corpo manomesso, Actor firmatario non corrispondente, content-type non supportato,
  corpo troppo grande, deduplicazione, inbox condiviso);
- `InboxActivityProcessor`: ogni tipo di attivita' (`Follow` verso account aperti e
  protetti, `Accept`/`Reject` di un follow in uscita, `Undo`, `Like`/`Announce` e i
  relativi `Undo`, `Create` di un post o di una risposta rilevante, `Delete`, `Update`
  con oggetto `Note` e con oggetto `Person`/`Group` incluso il rifiuto di un documento
  che dichiara un id diverso dall'Actor firmatario) e il caso di un Actor firmatario
  sconosciuto;
- `ActivityDelivery` e `DeliverActivityJob`: deduplicazione delle inbox condivise,
  esclusione di follower locali/non ancora accettati, regole di consegna per i
  messaggi diretti, firma HTTP corretta della richiesta in uscita, fallimento
  permanente senza chiave privata, ritentativo su risposta non 2xx;
- `RemoteActorResolver::resolveByUri()`/`resolveByHandle()`: fetch e cache con TTL,
  rifiuto di un documento che dichiara un id diverso da quello richiesto, rifiuto di
  trattare un URI locale come remoto, risoluzione WebFinger;
- l'intero ciclo "azione locale → attivita' consegnata a un Actor remoto" end-to-end
  per `Follow`/`Unfollow`, `Like`/`Unlike`, `Announce`/`Unannounce`, pubblicazione ed
  eliminazione di post e commenti (inclusi i controller HTTP);
- la ricerca remota (`/cerca`) e la pagina profilo di un Actor remoto in cache
  (`/attori/{id}`, incluso il redirect al profilo canonico quando l'id corrisponde a
  un Actor locale);
- `RemoteOutboxFetcher` (`RemoteOutboxFetcherTest`): al primo caricamento della pagina
  profilo di un Actor remoto (o dopo la scadenza della cache) i post pubblici piu'
  recenti del suo outbox reale vengono recuperati e mostrati, esclusi risposte, post
  non pubblici e qualunque item che dichiari un autore diverso dal titolare
  dell'outbox; se l'outbox e' uno stub (solo `totalItems`, tipico Pixelfed) si usa
  il feed Atom; nessuna nuova richiesta prima della scadenza della cache; il tentativo
  viene comunque registrato quando il server remoto non risponde, per non rallentare i
  caricamenti successivi; nessuna notifica di menzione viene generata per contenuto
  recuperato in questo modo (non e' un evento "appena successo");
- `RemoteRepliesFetcher` (`RemoteRepliesFetcherTest`, `SignedFetchTest`): aprendo un
  post remoto (es. dal feed di chi si segue) viene interrogata la collection `replies`
  della Note originale (TTL `OPENBOOK_REPLIES_CACHE_TTL_HOURS`), seguendo anche la
  paginazione `next` tipica di Mastodon (dove la prima pagina e' spesso vuota); i GET
  sono firmati (authorized fetch) con la chiave dell'utente che visita o di un Actor
  locale di fallback; i commenti pubblici/non elencati di terzi vengono messi in cache
  senza generare notifiche; le risposte a commenti gia' noti sotto lo stesso post
  vengono annidate correttamente;
- gli elenchi follower/seguiti (`FollowListTest`): visibilita' pubblica per un profilo
  locale, esclusione delle richieste ancora in attesa, stato corretto del pulsante
  segui/smetti di seguire per riga, redirect dell'elenco di un Actor remoto quando
  corrisponde in realta' a un account locale, obbligo di autenticazione per l'elenco
  di un Actor remoto.
- la pagina Impostazioni (`SettingsTest`): obbligo di autenticazione, aggiornamento di
  nome/biografia/link con sincronizzazione del nome sull'Actor federato, caricamento e
  sostituzione dell'avatar (con rimozione del file precedente), rifiuto di un file non
  immagine, cambio della lingua dell'interfaccia effettivamente applicato dal
  middleware, propagazione della visibilita' predefinita al composer, sincronizzazione
  di "account protetto" fra `user_settings` e l'Actor, esclusione dai suggerimenti
  quando l'account non e' piu' "discoverable", invio di un `Update` federato ai
  follower remoti quando cambia il profilo pubblico o l'opzione "Account protetto" (e
  la sua assenza quando cambiano solo preferenze puramente locali); il servizio di
  caricamento immagini di
  profilo (`ProfileImageUploaderTest`): percorsi separati per avatar/copertina,
  rimozione del file precedente, validazione di tipo e dimensione, ridimensionamento
  delle immagini sovradimensionate, permessi della cartella creata al primo upload
  corretti anche con una `umask` restrittiva del processo PHP (verificata anche per
  gli allegati dei post in `MediaUploaderTest`); la costruzione dell'URL di
  avatar/copertina (`Tests\Unit\Domain\Profiles\ProfileTest`), per evitare regressioni
  sulla scelta del disco "public" al posto dell'helper `asset()`.
- la sezione "Mondo" (`WorldTest`): la timeline mostra solo post remoti pubblici gia' in
  cache ed esclude sia i post locali sia quelli remoti non pubblici; obbligo di
  autenticazione; classifica degli account da scoprire (priorita' ai follower locali
  accettati, poi all'attivita' piu' recente), esclusione di chi non ha ne' un follower
  locale ne' un post in cache, esclusione di chi e' gia' seguito dal visitatore;
  pagina `/mondo/scopri` con elenco completo e scorrimento infinito.
- visibilita' delle condivisioni (`AnnounceVisibilityTest`): un post condiviso (locale,
  di un altro Actor locale, o remoto) compare sul profilo e nel feed personale di chi
  lo ha condiviso con l'indicazione "ha condiviso questo post"; ordinamento per momento
  della condivisione anche quando il post originale e' molto piu' vecchio; nessuna
  indicazione ridondante quando si condivide un proprio post; scomparsa dal profilo
  dopo aver ritirato la condivisione; nessuna indicazione sul profilo dell'autore
  originale.

Un piccolo sottoinsieme di test (`Tests\Feature\Installer\InstallerMysqlFlowTest`)
verifica specificamente il passo 2 dell'installer (connessione e migration) contro un
**vero server MySQL/MariaDB**, perche' questo comportamento non e' esercitabile in modo
affidabile con SQLite. Questi test si auto-saltano (`markTestSkipped`) se non trovano
un server raggiungibile con le credenziali indicate dalle variabili d'ambiente
`OPENBOOK_TEST_MYSQL_HOST`, `OPENBOOK_TEST_MYSQL_PORT`, `OPENBOOK_TEST_MYSQL_DATABASE`,
`OPENBOOK_TEST_MYSQL_USERNAME`, `OPENBOOK_TEST_MYSQL_PASSWORD` (valori di esempio gia'
presenti in `phpunit.xml`). Per eseguirli davvero, avvia un'istanza MySQL/MariaDB
usa-e-getta con quelle credenziali prima di lanciare la suite.

## Cron e attivita periodiche

Openbook usa la coda **database** di Laravel (tabelle `jobs`/`failed_jobs`, nessun
Redis/RabbitMQ ne' processo permanente): l'elaborazione dell'inbox e la consegna delle
attivita' in uscita avvengono solo quando qualcuno esegue periodicamente il comando
`openbook:cron`, che a sua volta invoca in sequenza:

- `openbook:process-inbox` — processa la coda `inbox` (`InboxActivityProcessor`);
- `openbook:deliver` — processa la coda `delivery` (`DeliverActivityJob`).

Entrambi i sotto-comandi girano con `queue:work --stop-when-empty`, cosi' terminano da
soli invece di restare in ascolto indefinitamente: adatto a un cron classico, mai a un
supervisore di processi permanenti.

**Con accesso a un vero cron di sistema:**

```cron
* * * * * php /percorso/openbook/artisan openbook:cron >/dev/null 2>&1
```

**Su hosting privi di cron reale o di accesso CLI**, l'installer genera un token
segreto e abilita un endpoint HTTP equivalente, da richiamare con un qualunque
servizio di "cron esterno" (es. cron-job.org) puntato a intervalli regolari:

```
GET https://tuo-dominio.example.org/cron/run?token=IL_TUO_TOKEN
```

Il token viene confrontato con `hash_equals()` (nessun timing attack) e l'endpoint
rifiuta richieste troppo ravvicinate (`OPENBOOK_WEB_CRON_MIN_INTERVAL`, default 55
secondi, risposta 429) restituendo 404 se la funzione e' disabilitata o 403 se il
token e' mancante o errato.

## Sicurezza e privacy

- Password con hashing moderno tramite i meccanismi nativi di Laravel (bcrypt),
  nessun algoritmo custom.
- Autenticazione con rate limiting sui tentativi di login e sul reinvio delle email
  di verifica.
- Chiavi private degli Actor cifrate a riposo, mai esposte da API/log/errori.
- Cookie di sessione `HttpOnly` e `secure` in produzione, protezione CSRF su tutte le
  form.
- Nessun analytics di terze parti, tracker, pixel pubblicitari, CDN o font remoti
  obbligatori: l'interfaccia usa solo CSS e asset serviti localmente.
- L'installer non mostra mai segreti (password, token) dopo il completamento della
  procedura e si blocca permanentemente al termine.

Per segnalare vulnerabilita' vedi [`SECURITY.md`](SECURITY.md).

## Roadmap e stato del progetto

Versione corrente: **0.8.10**. Il dettaglio delle modifiche per versione e' in
[`CHANGELOG.md`](CHANGELOG.md).

- ✅ **Fase 1 — Struttura e installazione**: progetto, configurazione, installer,
  database, autenticazione, account amministratore, profili locali.
- ✅ **Fase 2 — Dominio sociale locale**: post, immagini, commenti annidati, Mi
  piace, condivisioni, follow locali, feed, notifiche.
- ✅ **Fase 3 — Identita' federata**: Actor `Person`, WebFinger, NodeInfo, content
  negotiation, inbox/outbox, firme HTTP.
- ✅ **Fase 4 — Federazione sociale**: ricerca remota,
  `Follow`/`Accept`/`Reject`, `Create`/`Update`/`Delete`, `Like`, `Announce`, `Undo`,
  coda MySQL, retry, cron; poi (0.5.x–0.6.x) profilo/impostazioni, Mondo, outbox e
  replies on-demand, pannello admin, signed fetch, notifiche live.
- ✅ **Fase 5 — Community** (0.7.x): Actor `Group` locali e remoti, iscrizione,
  wall, Announce FEP-1b12, Lemmy/Friendica, moderatori, elenco Locali/Remote.
  Eventuali rifiniture (elenco membri dedicato, avatar/copertina community) restano
  possibili senza bloccare la Fase 6.
- 🚧 **Fase 6 — Sicurezza e interoperabilita'** (0.8.x, in corso): tipi
  `Article`/`Video`/`Image`, media remoti in galleria, URI `/users/…`, Accept Lemmy,
  fallback Atom Pixelfed, API blog Wafrn, rullino Foto sul profilo,
  Mondo → `/mondo/scopri`. Ancora da rafforzare: NodeBB e altri edge-case;
  eventuale download locale dei media remoti; destinatari dedicati per i
  messaggi diretti.

Non si passa a una fase successiva finche' i test della fase precedente non sono
verdi.

### Limitazioni note (stato 0.8.x)

- Le menzioni in *scrittura* risolvono Actor **locali** e remoti **gia' in cache**
  (`@utente` / `@utente@dominio`); un handle remoto sconosciuto non viene risolto
  al volo via WebFinger in fase di compose. In *ricezione*, una menzione a un Actor
  locale genera correttamente una notifica.
- I messaggi "diretti" (visibilita' `direct`) non hanno un elenco destinatari
  dedicato: sono visibili all'autore e a chi e' menzionato nel testo. Una UI di
  conversazione e' rimandata.
- Il contenuto remoto viene ridotto a testo semplice (niente HTML arbitrario):
  restano pero' i link etichettati (`[testo](url)` da `<a href>`) e le immagini in
  `attachment` come URL remoti. E' una scelta di sicurezza esplicita.
- Un contenuto remoto in inbox e' in cache solo se rilevante (autore seguito,
  risposta a qualcosa di noto, menzione locale). In piu': profilo remoto
  (`RemoteOutboxFetcher`, con fallback Atom) e replies del post remoto
  (`RemoteRepliesFetcher`). Non e' un indice completo del fediverso.
  La ricerca per parole chiave (`LocalSearchQuery`) copre i contenuti *locali*;
  per i remoti resta la risoluzione `utente@dominio`.
- Le immagini remote usano `media.remote_url` (hotlink): se l'origine blocca o
  rimuove il file, la galleria puo' risultare vuota. Gli allegati locali restano
  su `storage/app/public` → `public/storage`, senza CDN.
- Il limite `OPENBOOK_COMMENT_MAX_DEPTH` e' in configurazione ma non ancora
  applicato in UI: l'intero albero commenti di un post viene caricato in una
  pagina.
- Il pannello di controllo copre moderazione, domini bloccati, coda e
  impostazioni; restano fuori ban IP e un tool di debug firme HTTP. Promozione
  staff: UI e CLI (`openbook:make-admin` / `openbook:make-moderator`).

## Licenza

Openbook e' distribuito sotto licenza **GNU Affero General Public License v3.0 o
successiva** (AGPL-3.0-or-later). Vedi [`LICENSE`](LICENSE) per il testo completo.
