> Documentazione: [Italiano](README.it.md) · [English](README.md)

# Federazione

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
  [Federazione sociale](federation.it.md#federazione-sociale-fase-4) qui sotto), cosi' da non bloccare
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
- **Relay compatibili con Mastodon**: gli amministratori possono configurare le
  inbox dei relay da **Pannello di controllo → Relay**, abilitare separatamente
  ricezione e pubblicazione e avviare o interrompere la sottoscrizione. Dopo
  l'accettazione, Openbook importa le attivita' pubbliche trasportate dal relay e
  aggiunge la sua inbox al fan-out di post, commenti, eventi e commenti agli
  eventi pubblici locali. Il traffico riusa code, firme, retry, blocchi di
  dominio e deduplicazione della federazione ordinaria. Con relay trafficati
  sono consigliati due worker residenti separati, cosi' l'elaborazione in
  ingresso e le consegne remote lente non si bloccano a vicenda:

  ```bash
  php /percorso/openbook/artisan queue:work --queue=inbox --sleep=1
  php /percorso/openbook/artisan queue:work --queue=delivery --sleep=1
  ```

  I worker possono convivere con `openbook:cron`, ma vanno riavviati dopo ogni
  deploy affinche' carichino il codice aggiornato.

- **Sorgenti d'istanza basate su Actor**: lo stesso pannello puo' seguire un
  Actor remoto `Application`/`Service`, come quelli esposti da Mobilizon o
  Gancio, partendo dall'identita' federata (per esempio
  `@relay@istanza.example`) o dall'URI ActivityPub completo. Openbook scopre e
  valida l'Actor e importa gli oggetti pubblici trasportati tramite `Announce`.
  Le applicazioni remote possono a loro volta seguire l'Actor tecnico `/relay`
  di Openbook per ricevere gli `Announce` di post, commenti ed eventi pubblici
  locali. Le collection `/relay/outbox`, `/relay/followers` e
  `/relay/following` sono esposte per discovery e backfill.

- **Relay compatibili con LitePub**: gli hub usati da Pleroma/Akkoma e software
  compatibili si configurano tramite identita' federata dell'Actor o URI
  ActivityPub completo. Openbook esegue il Follow dell'Actor, registra il Follow
  reciproco necessario alla pubblicazione e inoltra i contenuti pubblici locali
  tramite `Announce` tecnici. Il pannello distingue una sottoscrizione accettata
  che attende ancora il Follow reciproco. Disabilitare la pubblicazione o
  annullare la sottoscrizione interrompe subito il fan-out, anche per consegne
  gia' in coda; restano applicate le normali regole di visibilita', blocco
  domini, deduplicazione e prevenzione dei loop. Contenuti non elencati,
  followers-only, diretti, remoti o di community private non vengono mai
  pubblicati verso un relay LitePub.

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
  essere schedulato (vedi [Cron e attivita periodiche](configuration.it.md#cron-e-attivita-periodiche)).
- **Ricerca**: la pagina "Cerca" (`/cerca?q=...`, form in GET) ha due percorsi.
  Se la query e' un indirizzo federato (`utente@dominio`, con o senza `@`
  iniziale, `acct:...`, o l'URL di un profilo), lo risolve localmente se il
  dominio corrisponde a questa istanza, altrimenti tramite WebFinger + recupero
  del documento Actor (`RemoteActorResolver::resolveByHandle()`), poi
  reindirizza al profilo. Se invece la query ha una forma diversa (parola
  chiave, frase, username senza dominio), esegue una ricerca *solo locale*
  (`LocalSearchQuery`) su persone (username, nome visualizzato, bio; rispetta
  `discoverable`), post e commenti di Actor locali (visibilita' e `indexable`
  FEP-5feb rispettati), e
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

### Eventi federati

Openbook riconosce gli oggetti ActivityStreams `Event` ricevuti tramite
attivita' firmate `Create` e `Announce` e li tiene separati dai post della
timeline. La sezione pubblica **Eventi** (`/eventi`) offre elenchi di eventi
in arrivo e passati, pagine di dettaglio locali, informazioni su luogo e fuso
orario, media, hashtag, risultati di ricerca, contatori remoti e thread di
commenti federati. La visibilita' segue il pubblico dell'evento: gli eventi
pubblici e non elencati si aprono tramite link, mentre quelli solo per i
follower e quelli diretti richiedono un destinatario locale registrato. Gli
eventi eliminati restano come tombstone invece di diventare un 404 ambiguo
per chi era autorizzato a vederli.

Gli utenti autenticati possono creare eventi pubblici o non elencati dalla
sezione Eventi o dal proprio profilo. Il composer supporta copertina,
descrizione in Markdown, fuso orario esplicito, luogo fisico/online/ibrido,
hashtag, menzioni e partecipazione aperta, moderata o esterna. Gli eventi
locali si possono modificare, annullare o eliminare; Openbook pubblica le
attivita' `Create(Event)`, `Update(Event)` e `Delete(Event)` e le espone
sia nell'outbox dell'Actor sia nella collection dedicata `events`.

Gli utenti possono inviare `Like`/`Undo(Like)` come manifestazione di
interesse e, se l'origine lo supporta, `Join`, `Undo(Join)` o `Leave` per
un RSVP. Gli organizzatori locali ricevono notifiche per i nuovi
partecipanti e possono accettare o rifiutare le richieste moderate. I
commenti agli eventi e le risposte annidate sono federati come oggetti
`Note` il cui `inReplyTo` punta all'Event o al commento padre; i commenti
supportano anche allegati, mi piace, notifiche e cancellazione a tombstone.
Un evento si puo' condividere in una conversazione privata Openbook solo se
il destinatario appartiene gia' al suo pubblico; la condivisione non concede
mai l'accesso a contenuti riservati. Gli eventi contrassegnati `sensitive`
tengono descrizioni e media dietro un controllo di rivelazione esplicito.
Gli hashtag usati da eventi pubblici/non elencati recenti contribuiscono
anche al calcolo delle tendenze, insieme a quelli dei post.

Non serve un worker aggiuntivo: le attivita' degli eventi usano le code di
inbox e delivery gia' elaborate da `openbook:cron`. Dopo aver distribuito
il supporto a un nuovo tipo di oggetto in inbox, un amministratore puo'
ritentare le righe conservate classificate come `ignored` con:

```bash
php artisan openbook:reprocess-inbox
```

Il comando rimette in coda ogni elemento ignored conservato ed e' sicuro
da eseguire piu' di una volta; le attivita' non supportate tornano semplicemente
nello stato ignored.

Non fanno ancora parte del prodotto maturo: un vero sistema di destinatari per i
messaggi diretti (oltre menzioni), e tool avanzati di debug federazione (oltre al
pannello code in admin).
