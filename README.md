# Openbook

Openbook e' un social network generalista **federato**, ispirato alla semplicita' dei
primi anni di Facebook ma costruito nativamente sul protocollo **ActivityPub** e
integrato con il Fediverso. Non e' un microblog, non e' un clone di Mastodon e non e'
un aggregatore di link: e' pensato per comunita' personali, territoriali, associative e
tematiche, con un'interfaccia comprensibile anche a utenti non tecnici.

Questo repository si trova al **Milestone 4** della roadmap tecnica: oltre a bootstrap,
installer, autenticazione e profili (Milestone 1), al dominio sociale locale completo
(Milestone 2: post, immagini, commenti annidati, Mi piace, condivisioni, follow locali,
feed, notifiche) e all'identita' federata (Milestone 3: profilo/post/commenti
negoziabili in ActivityPub, inbox/outbox firmati), Openbook e' ora **effettivamente
federato in entrambe le direzioni**: le attivita' ricevute (`Follow`, `Like`,
`Announce`, `Create`/`Update`/`Delete`, `Undo`) producono effetti reali sul dominio
locale, ogni azione locale rilevante viene **consegnata** ai server remoti coinvolti
tramite una coda MySQL con retry e backoff, ed e' possibile **cercare e seguire una
persona su qualunque altro server del Fediverso** direttamente dall'interfaccia.

## Indice

- [Requisiti](#requisiti)
- [Installazione guidata (consigliata)](#installazione-guidata-consigliata)
- [Installazione manuale / CLI](#installazione-manuale--cli)
- [Configurazione del server web](#configurazione-del-server-web)
- [Configurazione](#configurazione)
- [Architettura](#architettura)
  - [Federazione (Fase 3)](#federazione-fase-3)
  - [Federazione sociale (Fase 4)](#federazione-sociale-fase-4)
- [Test](#test)
- [Cron e attivita periodiche](#cron-e-attivita-periodiche)
- [Sicurezza e privacy](#sicurezza-e-privacy)
- [Roadmap e stato del progetto](#roadmap-e-stato-del-progetto)
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

# Segnala all'applicazione che l'installazione e' completata:
php artisan tinker --execute="file_put_contents(storage_path('installed.lock'), 'cli - '.now());"
```

Il comando `openbook:make-admin` puo' anche promuovere un account gia' esistente:

```bash
php artisan openbook:make-admin --promote=nome-utente
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

## Configurazione

Tutte le impostazioni specifiche di Openbook sono centralizzate in
`config/openbook.php` e configurabili tramite variabili d'ambiente (vedi
`.env.example` per l'elenco completo con commenti). Le principali:

| Variabile | Descrizione |
|---|---|
| `OPENBOOK_DOMAIN` | Dominio pubblico dell'istanza, usato negli indirizzi `utente@dominio`. **Non va cambiato dopo l'avvio**: comprometterebbe la federazione. |
| `OPENBOOK_INSTALLED` | Impostata automaticamente dall'installer; non modificare a mano. |
| `OPENBOOK_WEB_CRON_ENABLED` / `OPENBOOK_WEB_CRON_TOKEN` | Abilitano l'esecuzione dei processi periodici via richiesta HTTP, per hosting privi di cron reale. |
| `OPENBOOK_REGISTRATION_OPEN` / `OPENBOOK_REGISTRATION_REQUIRES_APPROVAL` | Controllano l'apertura delle registrazioni. |
| `OPENBOOK_MEDIA_MAX_SIZE_KB` / `OPENBOOK_MEDIA_MAX_ATTACHMENTS` | Dimensione massima (KB) e numero massimo di immagini allegabili a un post. |
| `OPENBOOK_POST_MAX_LENGTH` | Lunghezza massima (caratteri) del testo di un post. |
| `OPENBOOK_COMMENT_MAX_DEPTH` | Livelli di annidamento dei commenti considerati "normali" in configurazione (la struttura reale non ha un limite rigido, vedi [Limitazioni](#limitazioni-note-di-questo-milestone)). |
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
    SocialGraph/      # Follow tra Actor (locale per ora, gia' pronto per la federazione)
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
tramite `NotificationCreator`. Da questo milestone, ciascuno di questi servizi invoca
anche `ActivityDelivery` **dopo** il commit della transazione, quando l'attore che
compie l'azione e' locale e il destinatario (o i follower) coinvolgono almeno un
Actor remoto: e' l'unico punto in cui la logica di dominio "sa" della federazione, e
resta comunque un'aggiunta a valle, mai una condizione per il successo dell'azione
locale.

Ogni account locale possiede fin da subito un Actor ActivityPub di tipo `Person`
(tabelle `actors`, `actor_keys`, `actor_endpoints`), e a partire da questo milestone
tale Actor e' effettivamente esposto al Fediverso (vedi
[Federazione](#federazione-fase-3) piu' sotto). Per lo stesso motivo il dominio
sociale locale introdotto nel Milestone 2 e' gia' modellato pensando alla
federazione: `follows` e `likes` collegano **Actor** (non utenti), cosi' da poter
accogliere attori remoti senza modifiche allo schema.

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
  (metadati dell'istanza e statistiche d'uso aggregate, senza dati personali).
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
  - `Create`/`Update` con oggetto `Note` mettono in cache localmente il post o
    commento remoto (tabelle `posts`/`comments`, identificati dalla colonna `uri`),
    ma **solo se rilevanti** per questa istanza (l'autore e' seguito da un Actor
    locale, la Note risponde a un contenuto che gia' conosciamo, oppure menziona
    esplicitamente un Actor locale): nessun contenuto remoto viene conservato "a
    caso". Il contenuto HTML viene ridotto a testo semplice (`RemoteContentSanitizer`)
    prima di passare dalla stessa pipeline di rendering sicura dei post locali;
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
  `failed_jobs`, gia' presenti dal Milestone 1), coerente con i vincoli di shared
  hosting (nessun processo permanente, nessun Redis/RabbitMQ). I comandi
  `openbook:process-inbox` e `openbook:deliver` processano rispettivamente le code
  `inbox` e `delivery` con `--stop-when-empty`, cosi' da terminare da soli invece di
  restare in ascolto indefinitamente; `openbook:cron` li invoca entrambi in sequenza
  dividendo un budget di tempo massimo configurabile, ed e' il comando pensato per
  essere schedulato (vedi [Cron e attivita periodiche](#cron-e-attivita-periodiche)).
- **Ricerca remota e profili federati**: la pagina "Cerca" (`/cerca`) accetta un
  indirizzo `utente@dominio` (con o senza `@` iniziale, o l'URL di un profilo), lo
  risolve localmente se il dominio corrisponde a questa istanza, altrimenti tramite
  WebFinger + recupero del documento Actor (`RemoteActorResolver::resolveByHandle()`).
  Un Actor remoto risolto ha una pagina profilo di comodo (`/attori/{id}`, mai un
  identificatore ActivityPub canonico) con statistiche, eventuale biografia e un
  pulsante di follow che avvia il flusso `Follow`/`Accept` reale. In tutta
  l'interfaccia (card dei post, commenti, notifiche) gli autori sono ora mostrati
  tramite `Actor::displayName()`/`Actor::avatarUrl()`/`Actor::profileUrl()`, che
  funzionano in modo identico per attori locali e remoti.

Non fanno ancora parte di questo milestone: gli Actor di tipo `Group` (community, Fase
5), un vero sistema di destinatari per i messaggi diretti, e un pannello di
amministrazione per ispezionare code e fallimenti di consegna (per ora consultabili
solo direttamente nelle tabelle `jobs`/`failed_jobs`).

## Test

Il progetto usa PHPUnit. La suite gira di default su SQLite in memoria (vedi
`phpunit.xml`), quindi non richiede un database MySQL per essere eseguita:

```bash
php artisan test
```

La suite copre bootstrap/installer/autenticazione (Milestone 1), l'intero dominio
sociale locale (Milestone 2: creazione post con testo/hashtag/menzioni/allegati e
rollback transazionale in caso di upload non valido, upload e validazione media,
commenti e risposte annidate, Mi piace e condivisioni con prevenzione dei duplicati,
follow/unfollow e approvazione manuale degli account protetti, composizione del feed e
regole di visibilita', notifiche), l'identita' federata (Milestone 3) e la federazione
sociale bidirezionale (Milestone 4), tutte in `tests/Feature/Federation` e
`tests/Unit/Infrastructure/Security`:

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
  relativi `Undo`, `Create` di un post o di una risposta rilevante, `Delete`) e il
  caso di un Actor firmatario sconosciuto;
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
  un Actor locale).

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

- ✅ **Fase 1 — Struttura e installazione**: progetto, configurazione, installer,
  database, autenticazione, account amministratore, profili locali.
- ✅ **Fase 2 — Dominio sociale locale**: post, immagini, commenti annidati, Mi
  piace, condivisioni, follow locali, feed, notifiche.
- ✅ **Fase 3 — Identita' federata**: Actor `Person`, WebFinger, NodeInfo, content
  negotiation, inbox/outbox, firme HTTP.
- ✅ **Fase 4 — Federazione sociale** (questo repository): ricerca remota,
  `Follow`/`Accept`/`Reject`, `Create`/`Update`/`Delete`, `Like`, `Announce`, `Undo`,
  coda MySQL, retry, cron.
- ⏳ **Fase 5 — Community**: Actor `Group`, iscrizione, moderatori, pubblicazione,
  community remote.
- ⏳ **Fase 6 — Sicurezza e interoperabilita'**: protezione SSRF, hardening, blocco
  istanze, test di interoperabilita' con altri software del Fediverso.

Non si passa a una fase successiva finche' i test della fase precedente non sono
verdi.

### Limitazioni note di questo milestone

- Le menzioni (`@utente`) vengono ancora risolte solo verso attori **locali** in fase
  di *scrittura*: la sintassi `@utente@dominio-remoto` in un post o commento composto
  su questa istanza viene riconosciuta ma ignorata in modo sicuro (nessuna menzione
  remota, nessuna consegna diretta basata su di essa). La direzione opposta funziona
  gia': una menzione a un Actor locale dentro una Note **ricevuta** da un altro server
  genera correttamente una notifica.
- I messaggi "diretti" (visibilita' `direct`) non hanno un vero elenco di
  destinatari: sono visibili all'autore e a chi viene esplicitamente menzionato nel
  testo (solo menzioni locali, vedi punto precedente). Un sistema di destinatari
  dedicato e la relativa UI di conversazione sono rimandati a una fase successiva.
- Il contenuto remoto (post e commenti ricevuti via `Create`/`Update`) viene sempre
  ridotto a testo semplice: nessuna formattazione ricca (grassetto, liste, link gia'
  pronti) viene preservata, per evitare di introdurre un sanitizzatore HTML completo
  solo per contenuto proveniente da server non fidati. E' una scelta esplicita, non
  un difetto temporaneo.
- Un contenuto remoto (post o risposta) viene messo in cache solo se rilevante per
  questa istanza (autore seguito, risposta a qualcosa gia' noto, o menzione esplicita
  di un Actor locale): Openbook non replica mai un intero timeline remoto ne' offre
  ricerca full-text sui contenuti, locali o remoti che siano.
- Le immagini/allegati di un post remoto non vengono ancora recuperati ne' mostrati:
  solo il testo della Note viene messo in cache. Gli allegati locali restano serviti
  direttamente dal disco pubblico (`storage/app/public`, collegato a
  `public/storage`), senza CDN o object storage.
- Il limite di profondita' dei commenti (`OPENBOOK_COMMENT_MAX_DEPTH`) e'
  predisposto in configurazione ma non ancora applicato all'interfaccia: l'intero
  albero di un post viene sempre caricato e mostrato in una sola pagina. Il
  caricamento progressivo per thread molto lunghi arrivera' in una fase successiva.
- Non esiste ancora un sistema di community (Fase 5): i post non hanno un
  destinatario "community" e la colonna corrispondente non e' stata introdotta.
- Il pannello di amministrazione avanzato (gestione utenti, segnalazioni, ispezione
  della coda federativa) non e' ancora presente: la coda si ispeziona direttamente
  nelle tabelle `jobs`/`failed_jobs` e l'unico strumento amministrativo e' il
  fallback CLI `openbook:make-admin`.

## Licenza

Openbook e' distribuito sotto licenza **GNU Affero General Public License v3.0 o
successiva** (AGPL-3.0-or-later). Vedi [`LICENSE`](LICENSE) per il testo completo.
