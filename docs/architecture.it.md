> Documentazione: [Italiano](README.it.md) · [English](README.md)

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
[Federazione](federation.it.md#federazione-fase-3) piu' sotto). Il dominio sociale locale e' modellato
pensando alla federazione: `follows` e `likes` collegano **Actor** (non utenti), cosi'
da poter accogliere attori remoti senza modifiche allo schema.

Le chiavi private degli Actor sono cifrate a riposo (cast `encrypted` di Eloquent,
basato su `APP_KEY`) e non vengono mai esposte da API, log o messaggi di errore.

### Post, commenti e reazioni

- Il corpo di post e commenti e' testo con Markdown ampio (GFM), reso in HTML
  sicuro da `App\Domain\Posts\PostBodyRenderer` (HTML grezzo rimosso, link
  esterni con `rel` restrittivi, immagini Markdown ignorate). Hashtag e
  menzioni vengono linkificati dopo la conversione; il contenuto HTML dei
  post remoti (Fase 4) viene ridotto a testo semplice prima di entrare nella
  stessa pipeline (vedi [Federazione sociale](federation.it.md#federazione-sociale-fase-4)).
- I commenti vivono in una tabella dedicata (`comments`), separata da `posts`, con
  `parent_comment_id` per le risposte annidate: l'intero albero di un post viene
  caricato con un'unica query e ricostruito in memoria. Possono avere allegati
  immagine come i post (`comment_attachments`, stessi limiti MIME/dimensione).
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
  e nelle ricerche" (`user_settings.discoverable` e `actors.discoverable`), l'account
  smette di comparire nel riquadro "Persone da seguire" della sidebar e il documento
  Actor federato dichiara `discoverable: false` (directory Mastodon e simili). Resta
  raggiungibile in modo diretto, ad esempio tramite ricerca federata dell'indirizzo
  esatto.
- **Indicizzazione ricerca (FEP-5feb)**: la casella "Consenti l'indicizzazione dei
  miei post pubblici" (`user_settings.indexable` / `actors.indexable`, disattivata
  di default) e' il consenso `indexable` del profilo ActivityPub. I post e i commenti
  pubblici altrui comparono nella ricerca locale solo se l'autore ha attivato
  l'opzione; l'autore trova comunque i propri contenuti.
- **Riquadro "Questa istanza"**: non mostra piu' il numero di iscritti (un dato che
  espone inutilmente le dimensioni reali dell'istanza), ma i tag piu' usati di
  recente dalla community locale (`App\Application\Queries\PopularHashtagsQuery`):
  solo hashtag su post pubblicati da Actor *locali*, con visibilita' pubblica o non
  elencata, mai da contenuto remoto semplicemente in cache o da post riservati a
  follower/destinatari diretti.
- **Hashtag seguiti**: gli utenti autenticati possono seguire o smettere di
  seguire un tag dalla sua pagina o dall'elenco completo delle tendenze. I post
  pubblici gia' conosciuti dall'istanza entrano quindi nella Home personale,
  riusando deduplicazione e paginazione a cursore della timeline. Si tratta di
  una preferenza locale, non di un Follow ActivityPub federato: non scopre
  contenuti mai ricevuti dall'istanza. I tag seguiti restano privati e sono
  mostrati soltanto al proprietario, mescolati cronologicamente agli Actor nel
  proprio elenco "Seguiti".
- **Lightbox sulle immagini**: cliccando su un'immagine allegata a un post o commento
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
  della pagina, `public/assets/js/infinite-scroll.js` richiede l'URL successivo
  `?cursor=…` e ne innesta i post in coda all'elenco corrente, senza route/API
  dedicata ne' libreria esterna. Una richiesta normale a `/home` renderizza
  layout, composer, eventuale post citato e pubblicazioni video in attesa,
  senza interrogare il feed ne' preparare il kit di benvenuto. La richiesta
  AJAX allo stesso endpoint `/home` restituisce un frammento HTML con le card,
  oppure il kit di benvenuto se il primo blocco e' vuoto; le richieste AJAX
  con cursore restituiscono le card successive. Lo stesso caricatore gestisce
  entrambi e offre un link per riprovare dopo un errore.
  Senza JavaScript, la Home spiega che il feed lo richiede. Gli altri elenchi
  continuano a scaricare la pagina completa e offrono la paginazione classica
  dentro un `<noscript>`. Impostare `data-infinite-scroll`
  e "data-next-url" su un contenitore di post e' sufficiente perche' lo script si
  attivi: vedi `resources/views/posts/_feed.blade.php`, il parziale condiviso da
  tutte queste pagine. Approfittata anche l'occasione per dare a
  `FeedQuery`/`HashtagController` un ordinamento davvero deterministico
  (`ORDER BY ... , id DESC`): senza un criterio di spareggio, due post pubblicati
  nello stesso secondo potevano finire duplicati o saltati passando da una pagina
  all'altra, difetto gia' presente con la paginazione classica ma molto piu'
  evidente con lo scorrimento continuo.
- **Sidebar delle tendenze**: il layout autenticato mostra uno stato di
  caricamento senza eseguire `PopularHashtagsQuery`. Su viewport larghi almeno
  1024 px, `public/assets/js/trending-sidebar.js` richiede `/tendenze/sidebar`
  quando il box entra nell'area visibile. Il JSON contiene le cinque righe gia'
  formattate e indica se la pagina completa mostra altri risultati; usa la
  stessa query e le stesse regole di moderazione di quella pagina. La sidebar
  nascosta su mobile non fa richieste. Il risultato del box resta nella cache
  Laravel configurata per cinque minuti. L'accesso a `/tendenze` lo invalida
  subito; anche le modifiche alla finestra temporale o alla moderazione
  scartano il valore precedente.
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
