# Changelog

Tutte le modifiche rilevanti a Openbook sono documentate in questo file.
Il formato e' ispirato a [Keep a Changelog](https://keepachangelog.com/),
le versioni seguono [Semantic Versioning](https://semver.org/).

La versione corrente del software e' `config('openbook.version')`
(vedi `config/openbook.php`); e' la stessa esposta in footer, NodeInfo e
User-Agent in uscita.

Per lo stato complessivo della roadmap (fasi completate / in corso) vedi
il [`README`](README.md#roadmap-e-stato-del-progetto).

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
