# Changelog

Tutte le modifiche rilevanti a Openbook sono documentate in questo file.
Il formato e' ispirato a [Keep a Changelog](https://keepachangelog.com/).

Da **26.34** in poi le versioni usano il calendario `YY.settimana` e un nome
in codice (aggettivo emozionale + cibo, in inglese). Esempio: `26.34` e'
la prima stable (**Lovable Pancake**); le patch candidate successive della
stessa settimana sono `26.34.rc1`, `26.34.rc2`, … Le sezioni `0.x` restano
lo storico pre-stable.

La versione tecnica del software e' `config('openbook.version')`
(vedi `config/openbook.php`); e' la stessa esposta in NodeInfo e User-Agent.
Il footer mostra anche il nome in codice (`config('openbook.release_label')`).

Per lo stato complessivo della roadmap (fasi completate / in corso) vedi
il [`README`](README.md#roadmap-and-project-status).

## [26.36.rc1]

### Added
- Menu condividi del post: voce **Condividi a utente**
  (`GET /posts/{post}/condividi-a-utente`). Apre i messaggi privati con il
  post gia' citato: scegli il destinatario e invia (commento opzionale).
  La citazione riusa `posts.quoted_post_id` come le quote pubbliche, senza
  incrementare il contatore di condivisione ne' notificare l'autore originale.
- **Condividi a utente** anche sui profili (icona accanto al messaggio
  privato): `GET /@{user}/condividi-a-utente` e
  `GET /attori/{actor}/condividi-a-utente`. Il messaggio cita il profilo
  (`posts.quoted_actor_id`) con link alla pagina Openbook locale
  (`/@username` o `/attori/{id}`), non all'URI ActivityPub originale, cosi'
  il destinatario puo' seguire da questa istanza.
- Pannello di controllo, impostazioni istanza: upload della **favicon**
  del sito. Da un'unica immagine (consigliato quadrato 512×512) vengono
  generate la favicon 32×32, l'Apple Touch Icon 180×180 (iOS) e le icone
  Android 192/512 (anche maskable) per «Aggiungi alla schermata Home»,
  piu' un web app manifest in `GET /manifest.webmanifest`.
- Profili remoti (`GET /attori/{actor}`): follower e seguiti usano i
  conteggi `totalItems` delle collection ActivityPub del server di origine,
  con cache di 24 ore (`OPENBOOK_COLLECTIONS_CACHE_TTL_HOURS`). La data di
  iscrizione arriva dal campo `published` del documento Person (o equivalenti
  JSON-LD / `createdAt`). Visitando il profilo si rilegge l'Actor se la data
  non e' ancora in cache. Si conserva anche un campione della prima pagina
  della lista (non l'intero grafo, e non nel grafo locale `follows`).
- Pannello di controllo, sezione **Aspetto** (`GET /admin/aspetto`): CSS
  personalizzato che sovrascrive il template sul sito pubblico (non sul
  pannello). Anteprima live in iframe su una pagina di esempio
  (`GET /admin/aspetto/anteprima`); il sito reale cambia solo al salvataggio.
- Impostazioni istanza: **finestra in giorni** per gli hashtag in tendenza
  (sidebar e `GET /tendenze`, default 7). I conteggi oltre 999 si mostrano
  in forma compatta (`19,6k` in italiano, `19.6k` in inglese).
- Cliccando il numero di **Mi piace** o **Condivisioni** di un post si apre
  un dropdown con chi ha reagito (`GET /posts/{post}/piace-a` e
  `GET /posts/{post}/condiviso-da`). L'icona continua a mettere mi piace o
  ad aprire il menu condividi. Senza JavaScript le stesse URL mostrano una
  pagina con l'elenco. Massimo 40 nomi, poi "e altri N". La visibilita'
  e' la stessa del post.

### Changed
- Box «In tendenza» della sidebar: il titolo include la finestra in
  giorni impostata dall'istanza, es. `In tendenza (7d)`.
- Tab Foto del profilo (`GET /@{username}/foto` e
  `GET /attori/{actor}/foto`): scorrimento infinito come gli altri feed,
  al posto della paginazione a numeri di pagina. Senza JavaScript resta
  un link "Foto successive".
- Titoli dei post (campo titolo, `.ob-post__title`): stessa dimensione del
  testo del corpo, solo in grassetto. Prima ereditavano l'h2 del browser
  e risultavano sproporzionati.

### Fixed
- Profili remoti: la data di iscrizione non restava vuota sugli Actor gia'
  in cache. Visitando il profilo si rilegge il documento Person e si
  accettano anche `published` in JSON-LD e `createdAt`.
- Bio nel documento Actor ActivityPub (`summary`): gli a-capo non vengono
  piu' appiattiti in un unico paragrafo. Si usa lo stesso HTML dei post
  (`<br>` per i singoli a-capo, `<p>` distinti per i paragrafi), cosi'
  Mastodon e gli altri client remoti mostrano la formattazione.
- Iscrizione a una community locale: la notifica al proprietario non dice
  piu' che l'utente «ha iniziato a seguirti». Distingue iscrizione
  (`:name si e' iscritto a :community`) e richiesta su community privata.
- HTML federato dei post (e della bio): i link agli hashtag hanno
  `rel="tag"` e `class="mention hashtag"`, come Mastodon. Senza, Mastodon
  trattava il primo hashtag come URL normale e mostrava la preview della
  pagina tag di questa istanza (`/tag/…`).
- Titolo dei post federati: la Note include `name` (oltre al fallback
  `<p><b>…</b></p>` nel content per Mastodon). Un'altra istanza Openbook
  che apre il profilo remoto mostra il titolo come titolo, senza
  duplicarlo nel corpo. Le Note vecchie, con solo il `<b>` iniziale,
  vengono riconosciute allo stesso modo.
- Post in una community locale (e verso un Group remoto): l'HTML federato
  aggiunge in coda una menzione visibile `in @community@dominio` (stesso
  href del tag Mention, `class="u-url mention"`). Mastodon mostra cosi'
  l'appartenenza nel testo; audience e tag da soli non bastano. In locale
  il corpo del post resta pulito: la community resta il badge della card.

## [26.34] — Lovable Pancake

### Added
- Documento Actor ActivityPub: proprieta' Mastodon **`discoverable`** e
  **`indexable`** (FEP-5feb, namespace `http://joinmastodon.org/ns#`).
  Nelle Impostazioni, accanto alla presenza nei suggerimenti, c'e' il
  consenso all'indicizzazione dei post pubblici. Un `Update` federato
  parte quando cambiano questi flag. In ricerca locale i post/commenti
  pubblici altrui compaiono solo se l'autore ha dato il consenso
  (`indexable`); l'autore trova comunque i propri. In ingresso i flag
  remoti vengono messi in cache (`actors.discoverable` / `actors.indexable`).

### Changed
- Ricerca di un **URL**: si prova prima il Fediverso (documento Actor,
  WebFinger da path tipo `/@utente`, link HTML `application/activity+json`)
  e solo dopo un eventuale feed RSS/Atom. I profili Mastodon non vengono
  piu' importati come feed solo perche' espongono anche un RSS.

### Fixed
- Iscrizione a una **community pubblica** locale: un utente Openbook non
  riceve piu' l'errore «Non puoi seguire questo account». Il Group della
  community non ha un User collegato; il controllo sull'account attivo
  vale solo per gli Actor Person (`FollowManager`, `POST` join community).

## [0.9.1] — Allegati remoti

### Added
- **Feed RSS/Atom come contatti** (stile Friendica): dalla ricerca puoi
  incollare l'URL di un feed o di un sito con feed rintracciabile; Openbook
  crea un Actor di tipo `feed`, importa le voci come post pubblici e ti
  permette di seguirlo nella home (relazione one-way, senza ActivityPub).
  Aggiornamento periodico via `openbook:fetch-feeds` incluso in
  `openbook:cron`.
- Menu laterale: voce **Tendenze** sotto Community, con la stessa
  destinazione della pagina hashtag popolari (`/tag`).
- **Notifiche** di like e condivisione: nome e avatar di chi ha agito
  portano al suo profilo Openbook; il resto della riga (e «Vedi») resta
  sul contenuto coinvolto.

### Changed
- **Ricerca**: mentre si digita nella navbar o nella pagina `/cerca`
  compaiono suggerimenti (persone locali discoverable, account remoti gia'
  in cache, hashtag), sullo stesso schema delle menzioni. Frecce per
  selezionare, Invio sul suggerimento apre profilo/tag; Invio senza
  selezione avvia la ricerca completa. Nessuna chiamata WebFinger in
  autocomplete (`GET /cerca/suggerimenti`).
- **Condividi / repost** sui post: condivisione diretta e annullamento
  avvengono via AJAX (come i Mi piace), senza ricaricare la pagina.
- Menu condividi: **Annulla condivisione** compare solo dopo un repost
  diretto; la sola citazione con commento lascia **Condivisione diretta**
  (il contatore resta aggiornato anche per le citazioni).
- **Persone da seguire** (sidebar): su pagine hashtag e ricerca per keyword
  propone profili (locali discoverable e remoti in cache) la cui bio
  menziona il tag o la parola cercata; altrove resta il suggerimento
  predefinito. Se non ci sono corrispondenze, si ripiega sul box
  standard.
- Box **Persone da seguire**: handle lunghi vanno a capo senza spingere
  fuori dal riquadro il pulsante Segui.
- Tab **Community remote**: sezione **Community popolari su questa istanza**
  con Group remoti gia' seguiti da altri utenti locali (classificati per
  iscritti sull'istanza), con pulsante per richiedere l'iscrizione; visibile
  anche agli ospiti. Le community gia' seguite o in attesa non compaiono
  tra i suggerimenti.
- **Allegati audio** sui post: in ingresso federato (Mastodon e simili,
  `Document`/`Audio` con `audio/*`) e in uscita dal composer locale
  (MP3, OGG, WAV, M4A, FLAC, AAC); riproduzione inline con `<audio controls>`.
- **Rifiuto richiesta di follow**: notifica locale a chi aveva richiesto
  di seguire (accettazione o rifiuto da profilo protetto o da remoto via
  `Reject`).

### Fixed
- **Avviso sul contenuto**: il soffietto copre anche immagini, audio, video
  e citazioni allegate, non solo il testo del post.
- Menu condivisi: «Annulla condivisione» non compare piu' insieme a
  «Condivisione diretta» sui post non ancora ripostati (il CSS del menu
  annullava l'attributo `hidden` sui form).
- Post federati con **una sola immagine** non mostrano piu' duplicati in
  galleria quando il server remoto invia piu' varianti dello stesso file
  (es. WordPress ActivityPub con thumbnail `-150x150` e media `-1024x572`,
  o Mastodon con URL `original` e `small`): si conserva la variante a
  risoluzione piu' alta. I post gia' in cache si correggono al refresh
  remoto del documento.
- Actor remoti con **preferredUsername lungo** (tipico WordPress ActivityPub,
  che usa il dominio del blog come username, es.
  `@blog.example.com@blog.example.com`) non falliscono piu' in inserimento
  per limite MySQL su `actors.preferred_username` (esteso a 255 caratteri;
  resta 32 per gli account locali).
- Elenchi **follower / seguiti** (e box suggerimenti): gli avatar non vengono
  piu' allungati orizzontalmente dal layout flex del testo accanto.
- **Scorrimento infinito** nei feed: la paginazione usa un cursore ancorato
  all'ultimo post mostrato invece del numero di pagina, evitando duplicati
  quando arrivano nuovi post mentre si scrolla; deduplica lato client come
  rete di sicurezza. Il cursore del feed home/profilo ripete la subquery di
  `shared_at` nel WHERE (l'alias SELECT non e' usabile su MySQL/MariaDB).
  La pagina hashtag accetta correttamente la relation `BelongsToMany` dei
  post (non solo un `Builder`).
- **Create** consegnati all'inbox personale di un utente (es. messaggio
  opzionale dopo un rifiuto da bridge ActivityPub) non vengono piu' ignorati:
  vengono trattati come messaggio diretto in `/messaggi` quando non sono
  post pubblici.
- Pagine che interrogano server remoti (profili/community federate, post
  remoti, ricerca `@utente@dominio`) non mostrano piu' l'error page di
  Laravel quando l'host esterno e' irraggiungibile o va in timeout cURL:
  la richiesta fallisce in silenzio e resta visibile il contenuto gia'
  in cache locale.

## [0.9.0] — Messaggistica privata federata

### Added
- **Messaggi** (`/messaggi`): chat 1:1 con UX dedicata (lista conversazioni,
  thread, composer). I messaggi restano Note ActivityPub `direct` verso
  destinatari menzionati, compatibili con Mastodon e il resto del fediverso.
- Conversazioni stabili per coppia di Actor (locale/remoto); badge non letti
  in sidebar; notifica `direct_message` con link al thread.
- Pulsante **Messaggio** sui profili locali e remoti; i post DM aprono il
  thread anziche' la pagina post.
- Impostazione **Chi puo scriverti in privato**: chiunque, solo follower,
  nessuno (`user_settings.direct_message_policy`).
- Comando `openbook:repair-dm-comments` per spostare in `/messaggi` i reply
  DM gia' salvati per errore come commenti (`--dry-run` per anteprima).

### Changed
- Ricezione DM federati: visibilita' corretta quando `to`/`cc` contengono
  solo destinatari specifici; inbox accetta Note indirizzate a un actor
  locale anche senza tag `Mention`.
- Profili: azioni **Segui/Smetti di seguire** e messaggio (icona) sulla
  stessa riga dei contatori follower/seguiti.
- Thread messaggi: invio Ajax e aggiornamento automatico (polling ogni 5s)
  senza ricaricare la pagina.
- **Nuova conversazione** in `/messaggi`: campo destinatario con
  autocompletamento (persone locali e remoti in cache) senza passare dal profilo.
- Feed, profili, ricerca locale e media: i post collegati a conversazioni
  private (`conversation_id`) non compaiono piu' nelle timeline pubbliche,
  solo in `/messaggi`.
- Pagina **Notifiche** (`/notifiche`): scorrimento infinito al posto della
  paginazione a numeri (fallback `<noscript>` invariato).

### Fixed
- Invio messaggio: errore SQL su `conversation_reads` (chiave composta senza
  colonna `id`); aggiornamento lettura via query diretta.
- Ricezione DM federati: testo mancante in conversazione quando la Note
  arriva come stub senza `content` (typical Mastodon). OpenBook ora recupera
  il documento completo con fetch firmato come destinatario locale e unisce
  l'audience `to`/`cc` dell'attivita' Create.
- Risposte DM federate (`inReplyTo` su un messaggio privato): restano nel
  thread chat anziche' essere salvate come commenti fuori conversazione;
  riconosciuto anche il flag Mastodon `directMessage: true`, il campo
  `inReplyToAtomUri` e il `conversation_id` del messaggio padre (rete di
  sicurezza se il fetch HTTP del Note remoto perde i metadati Mastodon).
- Routing inbox riscritto: qualsiasi Note con `directMessage: true` o
  audience `direct` va sempre in conversazione, senza passare dal percorso
  commenti (elimina doppia notifica menzione + commento).

## [0.8.12] — Composer rapido dalla navbar

### Added
- Pulsante + in header/FAB: fuori dalla home apre un dialog con lo stesso
  composer della home (layout e opzioni identici); alla pubblicazione si
  va al dettaglio del post. In home resta lo scroll/focus sul composer
  inline.
- Voce **Copia link** nel menu di ogni post: copia l'URL locale del post
  negli appunti.
- Impostazione admin **Mostra amministrazione sulla home**: rende
  visibile o nasconde il blocco guest con amministratori e moderatori
  dell'istanza (default: visibile).
- Pannello admin **Database** (`/admin/database`): dimensioni e righe
  eliminabili per tabelle operative (inbox grezzo, job falliti, cache,
  sessioni, token reset); pulizia singola o totale con retention di 24 ore
  (le voci inbox `pending` non vengono mai eliminate).
- Pulizia database automatica in `openbook:cron` (`openbook:purge-database`):
  al massimo una volta ogni 24 ore, stessa retention del pannello admin.

### Fixed
- Post con GIF animate (locali o federati): le immagini inline nel content
  HTML remoto vengono estratte come allegati in galleria; gli upload locali
  conservano l'animazione (niente ricodifica GD) e nel feed si usa il file
  originale anziche' una miniatura statica. Supporto anche alle GIF Mastodon
  convertite in MP4 loop (`video/mp4` in attachment o tag `<video>` inline).
- Impostazioni admin: salvataggio solo in database; il file `.env` non viene
  piu' riscritto dal pannello (modifica manuale o installer al primo setup).
- Pagine `/regole` e `/privacy`: layout a tutta larghezza della colonna
  centrale, allineato al resto del sito (niente colonna stretta da 420px).
- Sidebar sinistra: firma in fondo con nome istanza (link alla home locale)
  e link al progetto Openbook, come nel footer globale.
- Moderazione utenti: sospensione/disabilitazione sincronizzano lo stato
  ActivityPub (`actors.status`); esclusi da "Persone da seguire" e ricerca;
  profilo sospeso oscurato, profilo disabilitato 404. Migrazione allinea i
  dati gia\' presenti.
- Hashtag con nome vuoto (es. tag ActivityPub malformati): esclusi da
  "In tendenza", dal feed e dalla creazione futura; migrazione rimuove i
  record invalidi gia\' presenti (niente piu' errore URL dopo il login).
- Like/unlike su post e commenti via Ajax: niente piu' ricarica della
  timeline che riporta in cima. Senza JS resta il redirect con anchor
  (`#post-…` / `#commento-…`).
- Nelle Note ActivityPub in uscita, i link HTML delle menzioni remote
  usano l'URI ActivityPub dell'attore (es. openb.app) e non la pagina
  locale `/attori/…`: su Mastodon e simili la menzione apre l'istanza
  di destinazione. Dentro Openbook restano i link alla cache locale.
- Firme HTTP in ingresso da tags.pub / activitypub-bot / Mastodon 4.5+:
  - `keyId` su documento CryptographicKey (`…/publickey`): si usa la PEM
    gia' in cache dall'Actor (dopo il Follow) oppure si recupera il
    documento chiave *senza* authorized fetch (i GET firmati verso
    tags.pub spesso tornano 400);
  - verifica RFC 9421 (`Signature-Input` / `Content-Digest`), anche senza
    `alg` e con spazi dopo `;` come Mastodon; fallback a draft-cavage;
    Digest `sha-256` case-insensitive.
  - Log `federation.delivery_queued` / `delivery_ok` / `delivery_rejected` /
    `delivery_skipped` per diagnosticare Follow in uscita; se l'Actor remoto
    non ha inbox in cache si ritenta un refresh prima di abbandonare.
  - Follow remoti bloccati in pending senza Accept (tags.pub / activitypub-bot
    aggiunge ai followers ma non consegna l'Accept): dopo un Follow 2xx si
    verifica la collection `followers` remota e si conferma in locale
    (`openbook:confirm-outgoing-follows` nel cron).
  Sblocca Accept/follow-back da bot come `@_followback@tags.pub`.
- Announce (boost) da Person/bot di Note remote non ancora in cache: si
  recupera/incorpora il post come gia' per i Group, cosi' le ricondivisioni
  di bot seguiti (es. tags.pub) compaiono nel feed. Prima venivano ignorate
  se l'oggetto non era un post locale gia' presente in DB. Se l'Announce
  porta solo l'URI (tipico tags.pub), si recupera anche l'Actor autore
  remoto via fetch — senza di cio' restavano `ignored` in `inbox_items`.
- Citazioni remote (`quoteUrl` / `quote` FEP-044f / `_misskey_quote`): il
  post citato viene recuperato in cache e mostrato nella card annidata
  (`ob-post__quote`), togliendo dal testo il fallback "RE: …" / link nudo.
- "Recupera aggiornamenti" su un post remoto con un proprio commento locale
  nella collection replies: non fallisce piu' con UniqueConstraint (non si
  tenta di re-ingerire il commento locale ne' di creare un Actor remoto
  sul dominio dell'istanza).
- Inbox forwarding ActivityPub: se la firma HTTP e' di un Actor diverso da
  `activity.actor` (tipico delle risposte inoltrate), si autentica l'attivita'
  in ordine con (1) Linked Data Signature `RsaSignature2017` (stile Mastodon)
  e (2) GET same-origin sull'id dichiarato. Le attivita' in uscita vengono
  firmate anche con LD Signature, cosi' i peer possono inoltrarle. Sblocca i
  commenti remoti ai thread che prima restavano `actor_mismatch`.

## [0.8.11] — Deploy e aggiornamenti guidati su shared hosting

### Added
- Bootstrap `setup-openbook.php`: wizard pre-Laravel che scarica la release
  ufficiale da about.openb.app (zip + SHA-256), prepara `.env` / `.htaccess`
  e avvia `/install`.
- Pannello admin **Aggiornamenti**: confronta la versione locale con
  `releases/latest.json`, applica l'archivio preservando `.env` e `storage/`,
  esegue le migration e registra l'azione in audit.
- Script `bin/build-release.sh` e template in `distribution/` per pubblicare
  pacchetti shared hosting (con `vendor/`) e il manifesto JSON.
- Elenco membri delle community pubbliche (`/c/{slug}/membri`) con infinite
  scroll, come follower/seguiti.
- Impostazioni community per il creatore: avatar e copertina (come i profili),
  piu' nome e descrizione, con `Update` ActivityPub ai follower del Group.

### Changed
- Getting Started del README centrato sul flusso setup + about.openb.app;
  resta documentata anche l'installazione classica con Composer.

### Fixed
- Follow da istanze remote verso community pubbliche Openbook: Accept con
  `to` (compatibile Lemmy), risoluzione dell'URI profilo `/c/{slug}`,
  consegna sulla `sharedInbox` del richiedente e auto-accettazione dei
  Follow pending verso Group locali aperti (cosi' la join non resta
  "in attesa" e `members_count` si aggiorna).
- Timestamp `published` remoti con offset (es. `+02:00` di ziobudda.org /
  Friendica): convertiti al timezone dell'app prima del salvataggio, cosi'
  nel feed non compare piu' "tra xx min/ore" al posto del tempo trascorso.

## [0.8.10] — Onboarding, menzioni e recupero post remoti

### Added
- Welcome kit sulla home vuota: suggerimenti di staff, persone locali e
  account remoti noti, con link a Mondo e alle community.
- Notifica ai membri locali quando qualcuno pubblica in una community.
- Autocomplete `@` nel composer di post e commenti (utenti locali e remoti
  gia' in cache).
- Voce di menu "Recupera aggiornamenti" sui post remoti: ri-scarica la Note
  e forza il fetch delle replies, ignorando il TTL.
- Dopo la pubblicazione di un post normale, redirect alla pagina del post
  (non piu' solo alla home).
- Privacy policy gestibile dal pannello admin (Markdown), pagina pubblica
  `/privacy` e link in footer e sidebar.
- Markdown ampio (GFM) in post e commenti: grassetto, corsivo, titoli,
  elenchi, citazioni, codice, tabelle, barrato e link; hashtag/menzioni
  restano linkificati. Le immagini Markdown sono ignorate (usare gli
  allegati).
- Allegati immagine su commenti e risposte (stessi limiti dei post), con
  galleria/lightbox in thread e `attachment` ActivityPub in uscita e in
  ingresso.

### Changed
- Gli elenchi follower/seguiti usano lo scorrimento infinito come gli altri
  feed (la paginazione classica resta solo in `<noscript>`).
- Composer unificato per post, commenti e risposte: tip Markdown dietro
  icona info, Pubblica a tutta larghezza sotto le opzioni, pannelli a
  fisarmonica e layout piu' usabile su mobile (senza community nei
  commenti).

- Le community private compaiono nell'elenco locali per il creatore e per
  lo staff dell'istanza (badge "Privata"); gli altri utenti vedono solo le
  pubbliche.
- La pagina di attesa MySQL (connessioni transienti) e' uno schermo di
  caricamento con retry automatico e backoff, non piu' un messaggio di
  "servizio non disponibile".
- Ricerca con `#tag`: un solo hashtag trovato apre direttamente la pagina
  del tag; con piu' risultati resta l'elenco di ricerca.
- Hashtag e menzioni nei post remoti puntano alle pagine locali di Openbook
  (o alla ricerca federata `user@dominio` se l'attore non e' ancora in cache).
- La registrazione atterra sulla home con il welcome kit, non sul profilo.

### Fixed
- La mail di verifica veniva inviata due volte alla registrazione (listener
  `Registered` duplicato rispetto a quello di Laravel).
- A capo nei post/commenti federati verso Mastodon: niente piu' newline
  residui dopo `<br>` che raddoppiavano le interruzioni di riga.

## [0.8.9] — Profili Threads senza cronologia outbox

### Changed
- I profili `@utente@threads.net` spiegano perche' l'elenco post e' vuoto:
  Threads non espone l'outbox ActivityPub (solo stub/`404`). I post arrivano
  solo in push dopo un Follow, se l'account ha abilitato la condivisione sul
  Fediverso. Non e' un difetto di Openbook: stesso limite noto su Mastodon,
  Ghost ActivityPub, ecc.

## [0.8.8] — Avatar Threads: URL lunghi

### Fixed
- La ricerca di profili Threads/Instagram (es. `@barackobama@threads.net`)
  falliva perche' `actors.icon_url` / `image_url` erano VARCHAR(255) e gli
  URL CDN firmati superano facilmente quel limite. Ora accettano fino a
  2048 caratteri.

## [0.8.7] — Commenti in community private

### Fixed
- I commenti ai post di community private non vengono piu' consegnati ai
  follower dell'autore del commento ne' marcati `as:Public`: audience e
  fan-out restano sui membri remoti del Group. In locale solo chi puo'
  vedere il post puo' commentarlo.

## [0.8.6] — Community private: post non pubblici

### Fixed
- I post nelle community private non sono piu' trattati come pubblici sul
  Fediverso ne' sui feed/profili di chi non e' membro: `visibleTo` richiede
  l'iscrizione al Group, niente Create ai follower dell'autore, audience e
  Announce senza `as:Public`, outbox e feed Locale/Mondo senza quei contenuti.
  Il wall della community resta leggibile ai membri accettati e all'autore.

## [0.8.5] — Community private: richiesta di iscrizione

### Fixed
- Visitare il link di una community privata non restituisce piu' 403 ai
  non-membri: si vede la pagina (nome, descrizione) con "Richiedi iscrizione"
  (o login per gli ospiti). Il wall resta nascosto fino all'accettazione della
  richiesta da parte del proprietario/moderatore.

## [0.8.4] — Profili Wafrn: post da API blog

### Fixed
- Le istanze Wafrn espongono un outbox ActivityPub vuoto (`200 OK` senza
  collection). Visitando un profilo `@utente@istanza.wafrn`, Openbook ricade
  sull'API pubblica `/api/v2/blog?id=…`, recupera i post pubblici/non elencati
  e preferisce il documento Note in `/fediverse/post/{id}` (con fallback
  sintetico se il GET AP risponde 401).

## [0.8.3] — Elenco completo "Da scoprire" su Mondo

### Added
- Link "Vedi altro" sotto i 5 suggerimenti remoti della pagina Mondo; apre
  `/mondo/scopri` con l'elenco completo degli account suggeriti, con
  scorrimento infinito come gli altri feed (paginazione classica in
  `<noscript>`).

## [0.8.2] — Fallback Atom per outbox Pixelfed

### Fixed
- Molte istanze Pixelfed espongono un outbox ActivityPub "stub" (solo
  `totalItems`, senza `first`/`orderedItems`): visitando un profilo remoto
  Openbook ricade sul feed Atom `{actor}.atom`, recupera le Note AP e le
  immagini in allegato. Ritenta anche dentro il TTL se la cache e' ancora
  vuota.

## [0.8.1] — Rullino fotografico sul profilo

### Added
- Tab Post / Foto sui profili locali (`/@utente/foto`) e remoti
  (`/attori/{id}/foto`): griglia di tutte le immagini allegate ai post
  visibili, con lightbox e link al post di origine.

## [0.8.0] — Interoperabilita' Fediverso (Article, Video, media remoti)

### Added
- Ingestione di oggetti ActivityStreams `Article` (WordPress, WriteFreely),
  `Video` (PeerTube) e `Image`, oltre a `Note`/`Page` gia' supportati.
- Allegati immagine remoti (`attachment` / anteprime): salvati come URL
  https in `media.remote_url` e mostrati nella galleria del post, senza
  scaricare i file (Pixelfed, WordPress, thumb PeerTube).
- Create con `object` solo-id: fetch del documento remoto prima dell'upsert.

### Changed
- `attributedTo` accetta stringa, oggetto o lista Person+Group (PeerTube):
  il firmatario deve essere tra gli autori dichiarati.
- Corpo remoto: `content` → `contentMap` → `source` → `summary` → URL
  `text/html` → `name`.

## [0.7.9] — Elenco community locali / remote

### Added
- Nella pagina Community uno switch Locali / Remote: le locali restano le
  pubbliche dell'istanza; le remote sono i Group di altre istanze a cui
  l'utente e' iscritto (Follow accettato).

## [0.7.8] — Link etichettati nei post remoti

### Fixed
- I post federati (community Lemmy/Group e Note remote) non perdono piu'
  l'`href` degli `<a>` HTML: in ingest l'ancora diventa `[etichetta](url)`
  e `PostBodyRenderer` la mostra come link cliccabile sul testo. Restano
  esclusi schemi non `http`/`https`.

## [0.7.7] — URI Actor `/users/{username}` (compat. Lemmy)

### Changed
- Identificatore ActivityPub locale canonico: da `/@{username}` a
  `/users/{username}` (come Mastodon). Lemmy percent-encoda `@` (`%40`) e
  rifiuta il documento se l'URL richiesto non coincide con `id`; con il
  nuovo schema l'`Accept` dei Follow verso community Lemmy puo' completarsi.
- WebFinger `self` punta a `/users/...`; `/@...` e `/c/...` restano le pagine
  HTML (le richieste AP su quei path fanno redirect 301 all'id canonico).

### Fixed
- `ObjectResolver` riconosce ancora gli alias locali `/@nome` dopo la
  migrazione dell'uri.
- Follow, firme HTTP (`keyId`) e Note usano sempre l'id `/users/...` anche
  se la colonna `actors.uri` e' ancora legacy: altrimenti Lemmy vede un
  mismatch tra documento Actor e attivita' firmata.

## [0.7.6] — Fix inbox Actor su host errato (Lemmy)

### Fixed
- Il documento ActivityPub degli Actor locali espone inbox/outbox/sharedInbox
  sempre da `APP_URL`, non da valori obsoleti in `actor_endpoints`. Un
  mismatch di host (es. id su `openb.app` e inbox su un dominio vecchio)
  faceva rifiutare i Follow da Lemmy, mentre Friendica restava tollerante.

### Added
- Comando `php artisan openbook:repair-federation-urls` per allineare uri ed
  endpoint locali all'`APP_URL` corrente.

## [0.7.5] — Fix iscrizione a community Lemmy (Accept Follow)

### Fixed
- L'`Accept` di un Follow verso Group remoti (Lemmy) viene riconosciuto anche
  con URI percent-encodati (`%40`), object solo-id, o actor annidato; prima
  l'iscrizione poteva restare "in attesa" per sempre.
- Un nuovo click su Iscriviti, se la richiesta e' ancora pending, ritenta la
  consegna del `Follow` (utile se il primo invio era fallito).

### Changed
- Le attivita' `Follow` in uscita includono `to` (allineamento Lemmy).

## [0.7.4] — UI community: ordine wall e gestisci moderatori

### Fixed
- Wall delle community remote (profilo Group): i post sono ordinati dal piu'
  recente al piu' vecchio (per `published_at`), allineati al resto dei feed.

### Changed
- Pannello moderatori sulle community locali: nascosto di default, si apre
  da **Gestisci moderatori**.

## [0.7.3] — Fase 5: Ingestione post Lemmy (`Page`)

### Added
- I post delle community Lemmy (`Announce` → `Create` → `Page`) vengono
  ingeriti come post locali: `name` → titolo, `content`/`url` → corpo.
  Vale per inbox (ritrasmissioni ai follower) e per il fetch dell'outbox
  sul profilo del Group remoto.

## [0.7.2] — Fase 5: Composer verso community remote

### Added
- Composer sul profilo di una community remota (Group) se sei iscritto:
  il post viene indirizzato al Group (Mention + `to` + consegna Create
  all'inbox remota), come nel modello Friendica / FEP-1b12.
- Le menzioni `@nome@dominio` verso Actor remoti gia' in cache (anche Group)
  vengono risolte in scrittura, non solo quelle locali.

### Changed
- `ActivityDelivery::deliverContent` consegna anche ai Group remoti
  menzionati (oltre ai follower dell'autore).

## [0.7.1] — Fase 5 (slice 2): Community remote e moderatori

### Added
- Ingestione FEP-1b12: un `Announce` da Actor `Group` remoto mette in cache
  la Note (anche se remota, da oggetto embedded o fetch HTTP) e la mostra
  nel feed di chi e' iscritto al Group.
- Profilo community remota (`/attori/{id}`): badge, testi "Iscriviti/Lascia",
  wall alimentato anche dall'outbox (Announce del Group).
- Ricerca `nome@dominio` risolve anche le community locali (Group), non solo
  gli utenti.
- Moderatori delegati sulle community locali (oltre al proprietario):
  gestiscono le richieste di iscrizione alle community private.
- I Follow verso un Group locale (anche remoti in ingresso) aggiornano
  `members_count` e notificano il proprietario.

### Changed
- Le Note pubblicate verso una community locale includono l'URI del Group
  in `to` e un tag Mention (allineamento Friendica / FEP-1b12 in uscita).

## [0.7.0] — Fase 5 (slice 1): Community locali

Prima slice delle community in stile Friendica / FEP-1b12: Actor `Group`,
iscrizione via Follow, wall e ritrasmissione locale con Announce.

### Added
- Community locali: creazione, elenco `/community`, pagina `/c/{slug}`,
  Actor ActivityPub di tipo `Group` con WebFinger `nome@dominio`.
- Iscrizione / uscita (Follow verso il Group); community private con
  approvazione del proprietario.
- Pubblicazione sul wall della community (`posts.community_id`); il Group
  ritrasmette con `Announce` agli iscritti; i post compaiono anche nel
  feed di chi e' membro.
- Composer: scelta della community (home e wall); badge community sulla
  card del post; voce di menu **Community**; contatore community sul profilo.

### Changed
- WebFinger, inbox/outbox/followers/following per-username risolvono qualsiasi
  Actor locale (Person o Group), non solo gli utenti.

## [0.6.9]

### Added
- Nella home pubblica, nel riquadro **Questa istanza**, elenco di
  amministratori e moderatori locali attivi (avatar, nome, ruolo, link
  al profilo).
- Nei feed (home, mondo, profilo, hashtag) i post piu' lunghi di
  150 caratteri mostrano un'anteprima con link **Altro...** che espande
  subito il testo completo; la pagina del singolo post resta integrale.
  Soglia configurabile con `OPENBOOK_FEED_BODY_EXCERPT`.

### Changed
- Il dettaglio delle release e' stato spostato dal README a questo
  file; il README mantiene solo la roadmap a grandi linee con link qui.

## [0.6.8]

### Fixed
- Eliminare un commento aggiorna i contatori denormalizzati
  (`posts.comments_count` e, se e' una risposta, `replies_count` del padre),
  anche per soft-delete da UI, pannello admin e `Delete` federato.
- L'installer scrive in `storage/installed.lock` la stessa versione di
  `config('openbook.version')` (prima usava `config('app.version')`,
  chiave inesistente, con fallback a valori di milestone obsoleti).

### Added
- Mini-footer prodotto in fondo alla sidebar sinistra
  (`Openbook · Regole · Openbook v…`), utile su feed lunghi.
- Sezione hashtag **In tendenza** in sidebar destra: classifica dai post
  pubblici/unlisted in cache (locali e remoti), massimo 5 voci, con link
  **Mostra tutti** verso `/tendenze` quando ce ne sono di piu'.

## [0.6.7]

### Fixed
- `&#039;` (apostrofo HTML-escaped) non viene piu' interpretato come
  hashtag `#039` in post/commenti, ne' nel `content` ActivityPub in uscita
  (`PostBodyRenderer`).

## [0.6.6]

### Changed
- Polling notifiche a basso costo: `If-None-Match` / ETag sulla revisione
  utente; se nulla e' cambiato risponde 304 con una sola lettura leggera,
  senza rieseguire count/elenco.

## [0.6.5]

### Added
- Notifiche live: polling leggero su `/notifiche/feed` aggiorna badge
  (header + sidebar) e dropdown senza ricaricare la pagina.

## [0.6.4]

### Fixed
- Audience ActivityStreams (`to`/`cc`) come stringa singola (GoToSocial)
  non fa piu' scartare Note pubbliche.
- Collection `replies` senza `first` vengono dereferenziate via `id`.

## [0.6.3]

### Added
- Signed fetch / authorized fetch: i GET ActivityPub (Actor, outbox,
  replies) sono firmati con la chiave di un Actor locale
  (`OPENBOOK_FETCH_SIGNED`), cosi' le istanze che richiedono Signature
  non rispondono piu' 401.

## [0.6.1] – [0.6.2]

### Added
- Aprendo un post remoto si interroga la collection `replies` della Note
  originale (`RemoteRepliesFetcher`), seguendo la paginazione `next`
  tipica di Mastodon.
- L'orario del post apre il dettaglio Openbook; l'originale remoto e'
  nel menu «Apri post originale».

## [0.6.0]

### Added
- Pannello di controllo completo: limiti post/commenti/media, regole
  istanza in Markdown (`/regole`), blocchi dominio federato, ispezione
  coda (`jobs` / `failed_jobs` / `inbox_items`), promozione admin da UI,
  segnalazioni commenti, registro azioni staff.

## [0.5.0]

### Added
- Pannello di controllo (slice 1): ruoli admin/moderatore, coda
  segnalazioni, gestione utenti locali, impostazioni base (`site_name`,
  registrazioni aperte/chiuse).

### Out of scope in questa slice
- Limiti media/post, regole testuali, ban federation, coda inbox/outbox,
  audit log (arrivati in 0.6.0).

## Dopo la Fase 4 (pre-0.5)

Funzionalita' successive al Milestone 4 e precedenti alla numerazione
0.5.x, documentate qui per continuita' storica.

### Added
- **Personalizzazione del profilo e impostazioni account**: avatar,
  copertina, biografia, link, lingua dell'interfaccia, visibilita'
  predefinita dei post, account protetto, presenza nei suggerimenti,
  federazione via `Update` (in entrambe le direzioni) di ogni cambio al
  profilo pubblico e all'account protetto.
- **Sezione "Mondo"**: timeline dei post remoti pubblici gia' in cache
  locale e account remoti da scoprire, classificati sui soli segnali
  visibili da questa istanza (nessun indice globale del fediverso).
- **Post recenti su un profilo remoto**: la pagina profilo di un Actor
  remoto recupera al bisogno la prima pagina del suo outbox reale, cosi'
  da mostrare i suoi post pubblici piu' recenti anche prima che un
  qualunque Actor locale inizi a seguirlo.
- **Condivisioni visibili su profilo e feed** di chi condivide: prima
  comparivano solo nel feed di chi segue chi condivide.

## Fasi della roadmap (riepilogo)

| Fase | Tema | Stato |
|------|------|--------|
| 1 | Struttura e installazione | Completata |
| 2 | Dominio sociale locale | Completata |
| 3 | Identita' federata | Completata |
| 4 | Federazione sociale | Completata |
| 5 | Community (`Group`) | In corso / pianificata |
| 6 | Sicurezza e interoperabilita' | Pianificata |
