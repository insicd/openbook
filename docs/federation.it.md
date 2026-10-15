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

### Campi dei profili locali

L'editor del profilo distingue Link e Informazioni aggiuntive, con pulsanti
per aggiungere/rimuovere righe e un limite condiviso di otto campi. Le etichette
accettano fino a 50 caratteri, gli URL HTTP(S) fino a 255 e il testo semplice
fino a 1.000. Alla riapertura dell'editor, i valori HTTP(S) validi compaiono nei
Link; gli altri nelle Informazioni aggiuntive. Il salvataggio mantiene l'ordine
interno delle sezioni, con i link per primi.

I campi locali restano in `profiles.links`, come coppie `label`/`value`; i
vecchi record `label`/`url` restano leggibili senza migrazione dei dati. Il
profilo pubblico mostra etichette e valori sotto la bio. Il testo viene reso
con escaping, senza interpretare Markdown o HTML.

I campi sono pubblicati nell'array `attachment` dell'Actor come elementi
`PropertyValue`, con la mappatura del contesto schema.org compatibile con
Mastodon. I valori HTTP(S) diventano link HTML; gli altri diventano testo con
escaping, preservando gli a capo. La stessa rappresentazione è inclusa nelle
attività `Update` del profilo inviate ai follower remoti. La rimozione di tutti
i campi pubblica un array vuoto, così le altre istanze possono svuotare la
propria cache. I profili esistenti espongono i campi al successivo recupero
dell'Actor o aggiornamento del profilo; non viene avviato un invio massivo.
Pubblicare `rel="me"` non implica una verifica del collegamento in Openbook.

### Campi dei profili remoti

Openbook importa gli elementi `attachment` di tipo `PropertyValue` dell'Actor
in `actors.links`, come coppie ordinate `label`/`value`. Testo e link HTTP(S)
passano dalla pipeline esistente di sanificazione e rendering dei contenuti
remoti; HTML remoto, media inline ed effetti delle emoji personalizzate non
vengono riprodotti. La pagina del profilo remoto mostra i campi sotto la bio.
Una dichiarazione remota o un attributo `rel="me"` non implicano una verifica
del collegamento da parte di Openbook.

Il sistema conserva al massimo 16 campi validi, con etichette fino a 100
caratteri e valori normalizzati fino a 1.000. Campi vuoti o malformati e
attachment di altro tipo vengono ignorati; etichette ripetute mantengono
l'ordine originale. Ogni documento Actor valido sostituisce l'elenco in cache,
anche azzerandolo quando `attachment` è assente o non contiene campi validi.
Questo vale sia per i normali refresh sia per le attività `Update` del profilo
ricevute. Aprendo il profilo di un Actor remoto attivo viene verificata anche
la cache del documento Actor, con il TTL ordinario (24 ore per impostazione
predefinita); se il recupero fallisce, resta disponibile la copia in cache.
I profili già in cache acquisiscono i campi al successivo refresh o Update
ordinario, senza fetch massivi o richieste separate. I campi locali restano in
`profiles.links`, con editor e pubblicazione descritti sopra.

### Sospensione degli account remoti

Il flag `suspended` di un documento Actor remoto valido viene conservato in
`actors.remote_suspended`, separatamente dalla moderazione locale in
`actors.status`. Lo aggiornano sia i normali refresh sia gli Update del profilo
ricevuti. Un documento completo valido senza flag, o con `false`, rimuove la
sospensione remota; fetch falliti e documenti invalidi lasciano intatta la cache.

Il profilo remoto mostra un avviso di sospensione sul server di origine. Sono
impediti nuovi follow, messaggi, like, condivisioni/citazioni, risposte e
partecipazioni agli eventi, comprese le risposte a commenti di un autore sospeso
sotto post o eventi altrui. Il controllo è applicato nell'interfaccia e nei
servizi applicativi. Le condivisioni automatiche si fermano senza perdere la
preferenza. Post, commenti, eventi e relazioni di follow/reazione esistenti
restano conservati con la visibilità attuale; è possibile ritirare follow,
reazioni e partecipazioni precedenti. Consultazione, segnalazione e copia
dell'URL restano disponibili.

Quando il server di origine dichiara nuovamente attivo l'account, le interazioni
riprendono. I refresh remoti non annullano mai uno stato locale di blocco,
sospensione o cancellazione. Il flag non sostituisce la normale gestione di
Delete/Undo e non introduce un ban delle attività in ingresso. L'aggiornamento
dello schema richiede il consueto `php artisan migrate`; non servono nuovi
servizi o worker.

### Migrazioni degli account remoti

Un `Move` autentico da un account remoto genera una notifica per ciascun
follower locale con follow accettato e un avviso sul vecchio profilo.
Openbook verifica che actor e object corrispondano al mittente autenticato
e recupera il profilo aggiornato della destinazione, che deve dichiarare
il vecchio account in `alsoKnownAs`. Un fetch fallito o una verifica negativa
non produce notifiche. Le copie dello stesso annuncio vengono deduplicate.

La notifica apre il nuovo profilo dentro Openbook, dove l'utente puo'
scegliere Segui. Nessun follow viene creato o rimosso automaticamente;
preferenze, post precedenti e regole di retention e visibilita' restano
invariati. Un Update con il solo `movedTo` non genera notifiche di migrazione.
Questa funzione riguarda annunci fra account remoti, non la migrazione di
account da o verso Openbook, community o catene di spostamenti automatici.

Anche i normali aggiornamenti del vecchio profilo importano `movedTo`:
Openbook cerca il nuovo Actor nel database, lo recupera se manca e salva
il collegamento nel campo esistente `moved_to_actor_id`. Questo mostra
l'avviso e il link al nuovo profilo senza generare notifiche. Non vengono
inseguite catene di spostamenti. Un Move verificato successivo puo' comunque
notificare i follower; il collegamento informativo non lo sostituisce.
L'avviso di trasferimento compare nella card sotto nome e handle. Il globo
accanto al nome apre le informazioni sulla provenienza remota al passaggio
del mouse, al focus da tastiera o al click/tocco.

Lo schema richiede il consueto `php artisan migrate`; non servono nuovi worker.

### Profili commemorativi remoti

Il flag `memorial` dell'Actor remoto viene conservato in `actors.memorial`
(false per default), usando il medesimo flusso di fetch/refresh e Update
ricevuti. Un documento valido senza flag o con `false` lo rimuove; fetch
falliti e documenti invalidi preservano il valore in cache. I profili già
conservati acquisiscono il flag al successivo refresh o Update ordinario,
senza recuperi massivi.

Il profilo remoto mostra un badge discreto “Profilo commemorativo” vicino al
nome. Il flag è informativo: non modifica visibilità, approvazione dei follow,
messaggi, like, commenti o condivisioni. Sospensione remota e restrizioni di
moderazione locale continuano ad applicarsi indipendentemente. La gestione dei
profili commemorativi locali è fuori perimetro. La nuova colonna boolean
richiede il consueto `php artisan migrate`, senza nuovi servizi o worker.

### Post fissati remoti

Il campo Actor `featured` identifica la collection dei post fissati. Openbook
conserva l'endpoint e una piccola cache ordinata dei post importati; la pagina
profilo mostra «Post fissati» fra «Post» e «Foto e video» soltanto se almeno un
post è visibile al lettore. I post usano le card ordinarie e non vengono
anteposti alla timeline.

Il recupero avviene alla visita del profilo, con richieste HTTP sicure e firmate
quando disponibili, usando `OPENBOOK_POSTS_CACHE_TTL_HOURS` (default 6 ore).
Si leggono al massimo 3 documenti di collection e 20 elementi; collection inline,
pagine `first`/`next`, `items`/`orderedItems` e riferimenti URI sono supportati.
Si importano gli originali pubblici/non elencati nei tipi post già supportati,
con controllo dell'autore e senza notifiche. Risposte, oggetti privati e post
cancellati non vengono ricreati; valgono anche i controlli delle community private.

Un elenco vuoto valido rimuove i fissati dalla scheda, senza cancellare i post.
Errori o paginazioni incomplete preservano l'ultimo elenco valido; il tentativo
viene registrato anche in caso di errore. Add/Remove ricevuti dal firmatario
verso la propria collection invalidano la cache per la visita successiva.
Cambio o rimozione dell'endpoint nell'Actor azzerano l'elenco precedente.
I profili già in cache scoprono l'endpoint al successivo refresh Actor ordinario.
La migrazione richiede `php artisan migrate`; non occorrono nuovi servizi.
La gestione dei fissati dei profili locali resta fuori perimetro.

### Lingua dei post remoti

Openbook conserva in `posts.language` la lingua dichiarata del testo importato
quando non è ambigua. Una `contentMap` con una sola chiave BCP 47 utilizzabile
fornisce la lingua se il valore coincide esattamente con `content`, oppure se è
il testo effettivamente scelto dal fallback in assenza di `content`. Mappe con
più lingue, testi discordanti, tag malformati o indeterminati restano `NULL`;
il sistema non analizza linguisticamente il testo. I tag sono normalizzati in
minuscolo senza perdere regioni o alfabeti, con limite applicativo di 255 caratteri.

In assenza di mappa, un `@language` esplicito nei contesti incorporati può
etichettare `content`. Il contesto dell'oggetto può sostituire il default ereditato
dall'attività; `null` lo azzera. Contesti remoti sconosciuti e ridefinizioni che
richiedono espansione JSON-LD non vengono interpretati né scaricati: il default
resta non utilizzabile fino a un reset esplicito. I documenti recuperati tramite
HTTP hanno un contesto indipendente da quello dell'attività che li riferisce.

La regola è condivisa da inbox, importazione outbox e refresh, inclusi i messaggi
privati memorizzati come post. Gli aggiornamenti accettati possono cambiare o
rimuovere la lingua; quelli obsoleti non la modificano. Non è previsto un backfill
dello storico, né cambiano autorizzazione e criteri di rilevanza dei post.
Le card nei feed, nei dettagli e nei post citati mostrano la lingua vicino alla
data, con descrizione accessibile «Lingua dichiarata dall’autore». Il nome segue
la lingua dell'interfaccia del lettore; regioni e alfabeti vengono mantenuti
quando il catalogo dispone del nome completo, altrimenti compare il codice.
La presentazione usa i dati di Symfony Intl senza richiedere l'estensione PHP
`intl`. Non compare alcuna etichetta per lingua assente o post eliminati.
La selezione della lingua nel composer e la preferenza di scrittura del profilo
restano previste in una fase successiva.

La migrazione amplia `posts.language` da 8 a 255 caratteri. Il rollback viene
rifiutato se esistono tag più lunghi di 8 caratteri, per evitare troncamenti.

Le stesse regole di estrazione salvano la lingua in `comments.language`
(nullable, 255 caratteri), sia dall'inbox sia dal recupero delle risposte.
Ogni upsert può cambiarla o rimuoverla. La gestione preesistente dell'ordine
degli aggiornamenti dei commenti rimane invariata: non applica la protezione
contro versioni obsolete prevista per i post. La lingua viene mostrata accanto
alla data dei commenti, con le stesse regole di presentazione dei post. Non sono
introdotti backfill o selezione della lingua dei commenti; quelli locali
restano senza dichiarazione. I commenti degli eventi seguono un percorso distinto.

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
    `Image`, `Question`) mettono in cache localmente il post o commento remoto (tabelle
    `posts`/`comments`, identificati dalla colonna `uri`), ma **solo se rilevanti**
    per questa istanza (l'autore e' seguito da un Actor locale, la Note risponde a
    un contenuto che gia' conosciamo, oppure menziona esplicitamente un Actor
    locale; sono rilevanti anche gli aggiornamenti dei sondaggi già in cache):
    nessun contenuto remoto viene conservato "a caso". Il contenuto HTML
    viene ridotto a testo semplice (`RemoteContentSanitizer`), preservando gli
    `<a href>` come `[etichetta](url)`; le immagini in `attachment` restano come
    URL remoti in galleria. Poi passa dalla stessa pipeline di rendering sicura
    dei post locali. Al salvataggio di un post remoto, la data di pubblicazione
    viene convertita al fuso orario dell'applicazione e limitata all'ora corrente.
    Gli aggiornamenti con data futura conservano la data di pubblicazione in
    cache se gia' valida, evitando che importazioni ripetute riportino il post
    in cima alla timeline. Le date future gia' presenti vengono corrette al
    successivo salvataggio del post;
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
  esplicitamente menzionati, mai a tutti i follower. La chat puo' indirizzare
  messaggi anche ad Actor `Application` remoti attivi con una inbox, oltre che
  alle persone; community e Actor tecnico locale `/relay` non sono destinatari
  della chat.
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
  reindirizza al profilo. Per una parola chiave, frase o username senza
  dominio, `PeopleSearchQuery` cerca le persone locali e remote gia' note
  all'istanza per username o nome visualizzato; cerca anche nelle bio locali.
  I risultati persona rispettano `discoverable`. Separatamente,
  `LocalSearchQuery` cerca post e commenti di Actor locali (visibilita' e
  `indexable` FEP-5feb rispettati), eventi visibili locali o remoti gia'
  memorizzati e hashtag. L'autocompletamento della lente suggerisce persone
  discoverable e hashtag, senza recuperare Actor sconosciuti ne' proporre
  eventi. La `@` iniziale e' facoltativa per le query persone; le query sui
  contenuti conservano il testo originale. Nessun Elasticsearch: LIKE
  case-insensitive con jolly escapati, limiti configurabili (`OPENBOOK_SEARCH_MIN_LENGTH`,
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
  (Follow/Accept), wall dei post dei membri, Announce del Group in uscita,
  FEP-1b12 in ingresso (`Create` Note/Page che menziona o indirizza il Group:
  le community pubbliche accettano la citazione; per le private l'autore deve
  essere membro), community
  private con approvazione, moderatori delegati. I `Follow` in ingresso possono
  includere il flag `notify`: viene salvato sul follow. Mastodon tiene la
  campanella solo in locale e non la invia; l'`Announce` FEP-1b12 e' un boost
  e non la fa suonare. I nuovi post sul wall consegnano quindi anche un
  `Create` originale del Group (Note non elencata,
  `/users/{group}/avvisi/{post}`) a tutti i membri remoti, cosi' Mastodon
  puo' notificare chi ha acceso la campanella. L'elenco
  `/community` distingue
  **Le tue community**, **Community locali** e **Community remote**; le ultime
  due includono anche i Group già seguiti. Le tre liste sono ordinate per
  handle e caricano venti righe alla volta durante lo scorrimento. Le azioni
  di iscrizione aggiornano la riga senza ricaricare la pagina; anche una
  richiesta in attesa per una community locale privata può essere annullata.
- **Remote** (Lemmy, Friendica, …): ricerca `nome@dominio`, iscrizione federata,
  profilo `/attori/{id}` con composer per i membri, ingestione di Announce/Page
  (FEP-1b12). Dal profilo si puo' accendere la campanella per ricevere una
  notifica in-app a ogni post che il Group ritrasmette; la preferenza viene
  inviata come `Follow.notify`. Gli URI Actor locali usano lo schema Mastodon
  `/users/{username}` per compatibilita' (Lemmy rifiuta gli id con `@`
  percent-encodato).

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

### Sondaggi locali (#121, primo incremento)

Il primo incremento introduce persistenza e pubblicazione come `Question`
tramite i percorsi Create, Update, GET canonico e outbox dei post.
`PostComposer` accetta `poll` con `options` (lista di 2–6 testi distinti di
massimo 50 caratteri Unicode), `multiple` (booleano) e `duration_seconds`
(intero fra 300 e 604.800). La durata parte dalla pubblicazione effettiva,
anche con video in staging; i conteggi iniziali sono pari a zero.

Definizione e visibilità rimangono fissate alla pubblicazione. Le correzioni
del testo non possono aggiungere destinatari. Sono incluse le community locali
anche private; conversazioni private e pubblicazione verso Group remoti sono
intenzionalmente escluse. Applicare `php artisan migrate`. UI di creazione/voto e comando di distribuzione dei risultati arriveranno
nei successivi incrementi. Il normale flusso invia già Create(Question); il
lavoro federato pendente è registrato insieme al sondaggio per il successivo
collegamento al cron generale, senza un nuovo cron da configurare.

`LocalPollVoter` raccoglie schede locali definitive e risposte federate
Create/Note. `LocalPollVoteIngester` verifica firmatario, autore, origine della
Note e destinazione canonica della Question prima dei flussi commenti e messaggi.
Visibilità corrente, membership della community, stato degli account e blocchi
di dominio valgono per entrambi gli ingressi. Le scelte multiple locali vengono
confermate insieme; quelle federate arrivano in Note distinte e possono aggiungere
opzioni diverse fino alla scadenza. Note o scelte duplicate lasciano invariati i
conteggi; nella scelta singola resta la prima risposta valida elaborata.
Conteggi, revisione e lavoro federato pendente vengono salvati insieme, senza
richieste HTTP durante il voto.

Per le risposte incorporate decide il `received_at` originale dell’inbox
autenticata, anche nei retry e nell’elaborazione dopo la chiusura; `published`
remoto non lo sostituisce. Le risposte tramite URI richiedono anche il recupero
entro la scadenza, perché il documento corrente non prova il contenuto alla
ricezione dell’envelope. Una risposta tempestiva in coda può aggiornare i
risultati chiusi mantenendo la chiusura. L’elaborazione ricontrolla i permessi
correnti: la ricezione precedente non conserva accessi revocati. I risultati
sono subito disponibili nella Question canonica; la distribuzione federata
sarà collegata nel prossimo incremento.

Le tabelle `polls`, `poll_options`, `poll_votes` e `poll_vote_choices` sono
condivise dai sondaggi locali e remoti. La nuova migration rinomina prima le
quattro tabelle `remote_poll*` della #120 e poi aggiunge i campi locali,
preservando UUID, conteggi nullable, opzioni, snapshot, date e voti accodati.
Le migration precedenti restano invariate. Il rollback verso lo schema remoto
è consentito soltanto senza sondaggi locali o votanti scollegati, evitando di
perdere dati che il vecchio schema non rappresenta. Gli account
cancellati conservano i contributi con identità scollegata; hash con chiave
preservano la deduplicazione senza URI personali del voto in chiaro. I payload
grezzi inbox seguono la conservazione esistente. La cancellazione logica del
post elimina scelte/opzioni e ferma gli aggiornamenti, mantenendo i metadati
per la tombstone Question; la cancellazione fisica elimina anche questi.
Le Question accodate vengono ricontrollate prima dell'invio per evitare di
ripubblicare post eliminati.

### Sondaggi remoti (#120)

Gli oggetti `Question` nel formato Mastodon vengono importati tramite i flussi
esistenti di inbox, outbox, collection dei post fissati, Announce e refresh di
post/risposte. I metadati vivono in `polls` e `poll_options`; il
post conserva le normali regole di autore, audience e moderazione. Le Question
di risposta sono escluse. Il sondaggio compare nella card del feed e nella
pagina del post (`/posts/{post}`), identificato da `polls.post_id`.
Restano i percorsi di aggiornamento esistenti, senza polling o worker dedicati. L'apertura
della pagina rilegge testo, avviso e visibilità dopo il refresh, applicando
il pubblico corrente prima di mostrare il contenuto.

`oneOf` e `anyOf` conservano scelta singola/multipla, nomi esatti delle opzioni,
conteggi nullable di risposte/votanti, scadenza e chiusura esplicita. I conteggi
assenti restano sconosciuti; le scelte locali non alterano i risultati remoti.
Il normalizzatore accetta attualmente 2–50 opzioni distinte di massimo 1.000
caratteri Unicode. I metadati malformati vengono ignorati preservando lo stato
valido precedente. Il riordino conserva l'identità delle opzioni; la rinomina
la sostituisce preservando eventuali snapshot del voto personale. Una chiusura
già acquisita resta tale.

Le versioni remote esplicite `updated` hanno precedenza; altrimenti data e ID
originali dell'inbox determinano l'ordine del replay. I fetch usano il momento
di osservazione locale. Senza una revisione remota, l'ordine originale del
server non si può ricavare dall'ordine di consegna: snapshot più recenti
accettati possono legittimamente ridurre i conteggi. I sondaggi incorporati da
terzi vengono verificati all'endpoint canonico. I Create/Note con forma di voto
vengono scartati prima di diventare post, commenti o messaggi, anche quando il
destinatario del voto è sconosciuto.

Applicare le migration con `php artisan migrate`. L'ordinamento usa
`results_received_at`, senza dipendere dalla permanenza delle righe inbox.
Sondaggi e opzioni remote omettono i timestamp automatici `created_at`/`updated_at`:
la pubblicazione è nel `published_at` del post associato, mentre i voti personali
conservano le proprie date. La migration correttiva rinomina il precedente
`observed_at` e rimuove i timestamp ridondanti, preservando sondaggi in cache,
risultati e voti personali. Il replay storico resta disponibile con `openbook:reprocess-inbox`, che
riaccoda tutte le attività ignored/pending, comprese quelle ordinarie. Per una
verifica limitata ai sondaggi usare le fixture o una copia del DB.

Le risposte si selezionano tramite radio o checkbox; «Vota» conferma subito
«Voto registrato» dopo il commit e mostra «La tua risposta» solo al proprietario.
L'endpoint autenticato `POST /posts/{post}/vota` riusa il servizio di voto e
controlla visibilità, scadenza e versione delle opzioni. JavaScript aggiorna la
sola card dopo il POST; il normale form HTML funziona anche senza JavaScript.
Nelle citazioni il sondaggio è in sola lettura. Gli avvisi sui contenuti
racchiudono anche il sondaggio. Testi e snapshot remoti vengono escapati.

I conteggi assenti mostrano «Risultati in aggiornamento». Le percentuali usano i
votanti dichiarati dall'origine: richiedono un totale positivo e nessuna opzione
sopra quel totale; nella scelta singola richiedono anche tutti i conteggi e la
loro somma uguale al totale. I dati discordanti rimangono visibili come ricevuti,
senza percentuali inventate. Le risposte personali eliminate o rinominate restano
in un riquadro separato con il loro testo originale.

Il servizio di voto salva atomicamente le scelte personali e un `Create/Note`
per ciascuna scelta. La Note usa il nome esatto dell'opzione, risponde all'URI
della Question ed è indirizzata soltanto al suo autore. Gli UUID persistiti delle
scelte mantengono stabili gli ID nei retry. Invii duplicati restituiscono il voto
originale definitivo, anche dopo la chiusura. La versione delle opzioni rileva
rinomina, riordino e cambio di modalità; gli aggiornamenti dei conteggi remoti
preservano la selezione. Scadenza e accesso sono ricontrollati sotto lock;
la consegna ricontrolla visibilità e moderazione. I voti locali lasciano invariati
i risultati dichiarati dal server remoto.

La coda database interna `local_delivery` usa la connessione del dominio e la
tabella `jobs` esistente, indipendentemente dal driver globale o da
`DB_QUEUE_CONNECTION`. `openbook:deliver` consuma questa coda e il backend di
consegna configurato entro il budget esistente, evitando worker duplicati quando
condividono la stessa tabella. Questo incremento usa migration e cron già
esistenti. Se la configurazione è in cache, rigenerarla con
`php artisan config:cache` dopo l'aggiornamento. Chi usa soltanto un worker
personalizzato per un backend separato deve consumare anche `local_delivery`
oppure usare `openbook:deliver`.

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
ritentare le righe conservate classificate come `ignored` e riaccodare quelle
`pending` prive di job, per esempio dopo un'importazione del database, con:

```bash
php artisan openbook:reprocess-inbox
```

Il comando accoda gli elementi `ignored` e `pending`, senza includere quelli
`processed` o `failed`. Con la coda database, eseguire poi
`php artisan openbook:process-inbox`; con `QUEUE_CONNECTION=sync` l'elaborazione
avviene subito. Non è un dry-run e non deduplica job già presenti in coda: è
un comando di recupero manuale. I job controllano lo stato `pending` prima
dell'elaborazione; le attività non supportate tornano `ignored`.

Non fanno ancora parte del prodotto maturo: un vero sistema di destinatari per i
messaggi diretti (oltre menzioni), e tool avanzati di debug federazione (oltre al
pannello code in admin).
