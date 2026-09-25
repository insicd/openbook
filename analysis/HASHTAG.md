# Hashtag follow — analisi

Documento di analisi e memoria delle decisioni implementative. La sezione
"Stato iniziale del codice" fotografa la situazione prima dello sviluppo;
gli esiti degli sprint sono riportati in fondo.

## Obiettivo

Permettere a un utente locale di seguire e smettere di seguire hashtag, con
un'interazione coerente con i pulsanti gia' usati per il follow degli Actor.

Prima proposta UI:

- pagina del singolo hashtag (`GET /tag/{name}`): pulsante nell'intestazione
  accanto al titolo `#tag`;
- pagina completa delle tendenze (`GET /tendenze`): pulsante `Segui` oppure
  `Smetti di seguire` su ogni riga, nello stile degli elenchi follower;
- nessun dropdown o interazione JavaScript necessaria: normali form `POST` e
  `DELETE`, redirect alla pagina precedente e protezione CSRF;
- i pulsanti compaiono soltanto agli utenti autenticati. La navigazione degli
  hashtag resta pubblica.

Per ora non e' richiesto di aggiungere i pulsanti anche al piccolo widget
"In tendenza" della sidebar: lo spazio e' piu' limitato e la pagina completa
copre gia' il caso d'uso.

Gli hashtag seguiti non avranno una pagina elenco separata: compariranno nella
normale pagina "Seguiti", mescolati cronologicamente agli Actor. Una riga
hashtag usa l'icona `#` gia' presente nella navigazione, mostra `#nome` e offre
l'azione `Smetti di seguire`.

## Stato iniziale del codice

- Gli hashtag sono entita' persistenti nella tabella `hashtags`, con nome
  canonico minuscolo, univoco e senza `#`.
- `post_hashtags` collega post e hashtag con chiave primaria composta e foreign
  key in cascade.
- `HashtagController::show()` usa gia' `FeedQuery`, visibilita' e paginazione a
  cursore per mostrare i post noti all'istanza.
- `PopularHashtagsQuery` alimenta sia la pagina delle tendenze sia la sidebar.
- Non esiste ancora una relazione tra utenti/Actor e hashtag.
- La Home viene costruita da `FeedQuery::forActor()` come unione di stream:
  post propri, post degli Actor seguiti, post delle community seguite e
  condivisioni rilevanti. L'unione elimina poi i duplicati per post.
- Il pattern UI del follow Actor usa form server-side e pulsanti
  `ob-btn--small`; puo' essere riutilizzato senza introdurre un nuovo flusso
  frontend.

## Confine della funzionalita'

Il follow di un hashtag e' una preferenza locale. Deve portare nella Home i
post con quel tag che OpenBook possiede gia' nel proprio database e che sono
visibili all'utente.

Non e' un meccanismo per chiedere agli altri server ActivityPub di consegnare
tutti i post del Fediverso contenenti quel tag: ActivityPub non definisce un
follow federato standard degli hashtag. Anche Mastodon tratta i tag seguiti
come sorgente aggiuntiva della timeline Home locale.

Conseguenza: la copertura dipende dai contenuti che l'istanza riceve o scopre
gia' tramite follow di Actor/community, inbox, outbox e altre normali vie di
federazione. Non sono previsti crawler, relay o polling remoto per hashtag.

## Impostazione tecnica proposta

### Persistenza

Nuova tabella pivot dedicata, indicativamente `hashtag_follows`, con:

- `actor_id`: Actor locale dell'utente;
- `hashtag_id`;
- timestamp;
- vincolo univoco/chiave primaria composta (`actor_id`, `hashtag_id`);
- foreign key in cascade verso `actors` e `hashtags`.

Usare l'Actor invece di `users` rende naturale il join dentro
`FeedQuery::forActor()`, che riceve gia' l'Actor viewer. Il servizio/controller
deve comunque consentire la scrittura soltanto all'Actor dell'utente locale
autenticato: non e' una relazione federata e non va serializzata in
ActivityPub.

Possibile alternativa: `user_id`. E' semanticamente esplicita come preferenza
locale, ma richiede di portare l'utente dentro una query oggi interamente
actor-based. Al momento `actor_id` sembra il compromesso piu' coerente col
codice esistente.

### Backend HTTP

Rotte autenticate, indicativamente:

- `POST /tag/{name}/segui`;
- `DELETE /tag/{name}/segui`.

Un controller dedicato normalizza e valida il nome con `Hashtag::normalize()`
e `Hashtag::isValidName()`. Il follow e' idempotente (`firstOrCreate` /
`syncWithoutDetaching`) e l'unfollow di una relazione assente e' una no-op.

Se la pagina rappresenta un nome valido ma ancora assente da `hashtags`, il
follow crea la riga del tag: non serve aspettare che un post locale lo usi. La
riga senza utilizzi non entra nelle tendenze, ma permette ai futuri post con
quel tag di comparire subito nella Home. Un nome non valido deve produrre 404 o
validation error senza creare dati.

### Stato delle view

- La pagina del tag necessita di un solo booleano `isFollowing`.
- La pagina tendenze deve recuperare in una query gli ID dei tag seguiti
  presenti nelle 100 righe, evitando una query per riga.
- I form fanno redirect back e non richiedono JavaScript.
- Il layout dell'intestazione del tag necessita di un contenitore flex per
  titolo e azione; la lista completa puo' riusare la geometria di
  `.ob-suggestion` o introdurre una variante minima della lista hashtag.

### Elenco "Seguiti"

L'elenco attuale pagina direttamente righe `follows`, ordinate per
`accepted_at`, e alla fine le trasforma in Actor. Per mescolare correttamente
gli hashtag non basta concatenare due collection dopo la paginazione: si
otterrebbero ordine e numero di pagine errati.

Serve una sorgente paginabile comune, indicativamente una `UNION ALL` tra:

- follow Actor accettati: tipo `actor`, ID del destinatario e `accepted_at`;
- follow hashtag: tipo `hashtag`, ID del tag e `created_at` come data di follow.

Il risultato viene ordinato prima della paginazione per data discendente, con
un tie-breaker deterministico; gli oggetti Actor e Hashtag vengono poi caricati
in blocco e ricomposti nello stesso ordine. La view deve trattarli come due tipi
di riga distinti: un hashtag non diventa un Actor nel modello e non acquisisce
URI, handle o comportamento ActivityPub fittizi.

Questa unione riguarda soltanto l'elenco `following` di un Actor locale. Gli
elenchi remoti sono campioni delle collection ActivityPub del server di origine
e non espongono eventuali preferenze hashtag, che non fanno parte di quelle
collection.

Gli hashtag seguiti sono inoltre un'informazione privata, per evitare di
rendere facilmente profilabili gli interessi dell'utente:

- il proprietario autenticato vede Actor e hashtag mescolati nella propria
  pagina "Seguiti";
- altri utenti e guest vedono soltanto gli Actor seguiti, esattamente come
  prima;
- conteggi pubblici e collection ActivityPub `following` continuano a contare
  ed esporre soltanto gli Actor.

### Integrazione nella Home

`FeedQuery::forActor()` puo' aggiungere un ulteriore stream di candidati:

1. parte dai post pubblicati, con visibilita' strettamente `public`, che
   risultano visibili al viewer secondo le regole gia' esistenti;
2. unisce `post_hashtags` a `hashtag_follows` per l'Actor viewer;
3. ordina per `published_at` e applica lo stesso cursore/limite;
4. entra nella `UNION ALL` esistente;
5. la `row_number()` finale continua a mostrare una sola volta un post che
   arriva anche da un Actor o da una community gia' seguiti.

Percorso previsto della query: `hashtag_follows`, filtrata per `actor_id`,
produce pochi `hashtag_id`; questi raggiungono `post_hashtags` tramite l'indice
su `hashtag_id`, quindi `posts` tramite la sua PK. La nuova pivot deve avere:

- chiave primaria composta (`actor_id`, `hashtag_id`), utile sia al filtro per
  utente sia all'idempotenza;
- indice separato su `hashtag_id`, necessario anche per una foreign key
  efficiente verso `hashtags` dato che nella PK e' la seconda colonna.

`post_hashtags` possiede gia' la PK (`post_id`, `hashtag_id`) e l'indice
dedicato `hashtag_id`. Durante lo sprint della Home vanno comunque controllati
SQL generato e piano `EXPLAIN` su MySQL con dati realistici: gli indici non
vanno considerati corretti soltanto perche' esistono sulla carta.

## Sicurezza e comportamento

- Riutilizzare sempre lo scope `visibleTo($viewer)`: il follow di un hashtag
  non deve aggirare blocchi o visibilita'.
- Non generare notifiche per i nuovi post del tag: il risultato e' soltanto una
  sorgente della Home.
- Nella Home entrano esclusivamente post con `visibility = public`: gli
  `unlisted` restano raggiungibili dalla pagina del tag ma non vengono spinti
  nella timeline personale tramite il follow.
- Il follow riguarda esclusivamente gli hashtag associati ai post. Commenti e
  risposte non vengono indicizzati per hashtag e non entrano nella Home tramite
  questa funzionalita'.
- Non produrre attivita' ActivityPub `Follow`/`Undo`.
- Nessun effetto retroattivo sui dati: appena si segue il tag, i post gia'
  conosciuti e visibili possono comparire nella Home; smettendo di seguirlo
  spariscono dalla sorgente hashtag.
- Inserimento e cancellazione devono essere idempotenti.

## Test minimi previsti

- migration e vincolo che impedisce duplicati;
- follow/unfollow HTTP, CSRF/auth e normalizzazione del nome;
- stato corretto del pulsante nella pagina hashtag;
- stato dei pulsanti nella pagina tendenze senza N+1;
- Home include un post visibile di un Actor non seguito con tag seguito;
- Home non include un post con tag non seguito;
- nessun duplicato se il post e' gia' incluso tramite follow dell'autore o
  della community;
- rispetto della visibilita' e della paginazione a cursore;
- guest: pagine leggibili ma nessuna azione mostrata.

## Questioni da decidere

Le decisioni strutturali emerse finora sono state definite.

### Indicazione della sorgente sulla card

Valutata e rinviata. L'attuale `UNION ALL` conserva soltanto post, istante,
eventuale Actor che ha condiviso ed event ID. Per mostrare "Perche' segui
#tag" bisognerebbe alternativamente:

- aggiungere tipo/ID della sorgente a tutti gli stream della union e definire
  una priorita' quando lo stesso post arriva da follow Actor, community e tag;
- oppure eseguire dopo la paginazione una query aggiuntiva che ricostruisca il
  tag seguito e verifichi che il post non sia gia' spiegato da un'altra
  sorgente.

L'informazione quindi non arriva gratis e complicherebbe una query gia'
delicata. La prima versione mostra normalmente la card, senza etichetta sulla
sorgente. Si potra' rivalutare separatamente se emergera' un problema UX reale.

## Suddivisione proposta in sprint

### Sprint 1 — Persistenza e operazioni di follow

- migration `hashtag_follows`, foreign key, PK composta e indice inverso;
- eventuali relazioni Eloquent minime su `Actor` e `Hashtag`;
- controller e rotte autenticate `POST`/`DELETE`;
- normalizzazione, validazione e idempotenza;
- test HTTP e test dei vincoli della migration.

Risultato verificabile: da test o richiesta HTTP un utente puo' seguire e
smettere di seguire un tag, ma la UI e la Home non cambiano ancora.

Stato: implementato sul branch `hashtag_follow`. La migration crea
`hashtag_follows`; sono disponibili le relazioni Eloquent e le rotte
autenticate `hashtags.follow` / `hashtags.unfollow`. I test coprono creazione
del tag sconosciuto, normalizzazione, idempotenza, isolamento tra utenti,
autenticazione e cascade delle foreign key.

### Sprint 2 — Interfaccia hashtag e tendenze

- booleano di stato nella pagina del singolo hashtag;
- mappa degli hashtag seguiti caricata in blocco nella pagina tendenze;
- pulsanti `Segui` / `Smetti di seguire` coerenti con il design esistente;
- adattamento CSS desktop/mobile minimo;
- test delle view per utente autenticato e guest.
- integrazione tipizzata e cronologica degli hashtag nella pagina "Seguiti",
  senza falsi Actor e senza rompere paginazione/infinite scroll;

Risultato verificabile: il flusso completo di attivazione/disattivazione e'
utilizzabile dal browser, senza ancora influenzare la Home.

Stato: implementato sul branch `hashtag_follow`. La pagina del tag e le
tendenze mostrano lo stato del follow; la pagina "Seguiti" del proprietario
mescola Actor e hashtag mediante una union ordinata prima della paginazione.
Guest e altri utenti continuano a ricevere la lista dei soli Actor. Gli oggetti
vengono idratati in blocco dopo la paginazione, senza query per singola riga.

### Sprint 3 — Integrazione nella Home

- nuovo stream hashtag dentro `FeedQuery::forActor()`;
- filtro esplicito `Post::VISIBILITY_PUBLIC` oltre a `visibleTo()`;
- integrazione nella deduplicazione e nella paginazione a cursore esistenti;
- test di inclusione, esclusione, visibilita', duplicati e pagine successive;
- ispezione dell'SQL e `EXPLAIN` MySQL del nuovo percorso di join; eventuali
  indici ulteriori vengono aggiunti soltanto sulla base del piano osservato.

Risultato verificabile: i post pubblici gia' conosciuti con tag seguito entrano
nella Home una sola volta, anche se arrivano contemporaneamente da altre
sorgenti del feed.

Stato: implementato sul branch `hashtag_follow`. Lo stream usa un `EXISTS`
correlato, che evita duplicati a monte quando un post contiene piu' tag
seguiti, ed esclude gli eventi post quando esiste gia' un Announce rilevante,
preservando l'ordinamento temporale del feed.

Verifica MySQL effettuata sul dump locale:

- il planner trasforma il ramo hashtag in un semijoin con weedout;
- `hashtag_follows` usa la PK composta con lookup su `actor_id`;
- `post_hashtags` usa `post_hashtags_hashtag_id_index`;
- `posts` viene raggiunta con lookup singolo sulla PK;
- su un tag seguito con 705 associazioni, il ramo isolato filtra 689 post e
  restituisce i primi 21 in circa 17 ms (`EXPLAIN ANALYZE`);
- non emerge la necessita' di ulteriori indici.

Controllata anche la union della pagina privata "Seguiti": il ramo Actor usa
`follows_follower_id_following_id_unique`, quello hashtag usa la PK di
`hashtag_follows`. La materializzazione e il sort finale riguardano circa 406
righe nel caso locale osservato e sono necessari per ordinare insieme le due
sorgenti; aggiungere indici duplicati non eliminerebbe quel sort.

### Sprint 4 — Revisione e consegna

- esecuzione dei test mirati e della suite pertinente;
- verifica manuale desktop/mobile e controllo regressioni della Home;
- revisione finale di query, eager loading e compatibilita' MySQL/SQLite;
- aggiornamento di README/changelog soltanto dove serve;
- commit finale o commit separati coerenti con gli sprint.

Stato: revisione conclusiva completata. Durante la suite completa e' stata
individuata una compatibilita' della view `follows.index`, condivisa anche
dall'elenco membri delle community: la view accetta ora sia il nuovo paginator
`items` sia la variabile storica `actors`. I test delle community passano
nuovamente senza modificare i relativi controller.

Verifiche finali:

- Pint passa su tutti i file PHP coinvolti;
- 97 test mirati passano con 377 asserzioni, includendo hashtag, feed, elenchi
  follow e l'intera suite delle community;
- la suite completa chiude con 831 test passati, 2 saltati e 4 errori gia'
  presenti su aree non modificate (manifest aggiornamenti, rendering bio Actor
  remoto e due aspettative obsolete di `InfiniteScrollTest`);
- README inglese/italiano e changelog descrivono il carattere locale e privato
  del follow e il limite ai post pubblici gia' noti all'istanza.
