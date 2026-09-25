# Home progressiva: tendenze e feed asincroni

Documento di analisi. Nessuna delle modifiche descritte qui è ancora
implementata.

## Obiettivo

Mostrare rapidamente la cornice della Home autenticata, lasciando che i
contenuti più costosi arrivino poco dopo. L'obiettivo è migliorare il tempo
percepito prima che l'utente veda una pagina utilizzabile, senza promettere
che le query sottostanti diventino più veloci. Procedere in due macrofasi
misurabili, mantenendo l'interfaccia semplice e senza introdurre worker o
dipendenze obbligatorie.

## Situazione attuale

- `FeedController::index()` esegue `FeedQuery::forActor()` e annota i post
  prima di restituire `feed.index`: il primo blocco di card è nell'HTML
  iniziale. Composer, pubblicazioni video in attesa ed eventuale kit di
  benvenuto sono preparati nella stessa richiesta.
- `posts._feed` espone il cursore della pagina successiva. Lo scroll infinito
  scarica oggi **l'intera pagina HTML** all'URL `?cursor=…`, ne estrae
  `[data-infinite-scroll]` e aggiunge solo i suoi figli al feed corrente.
  Non esiste un endpoint JSON per i post né una risposta limitata alle card.
- Il view composer di `partials.sidebar-right` in `AppServiceProvider` calcola
  suggerimenti e tendenze durante il rendering del layout. Di conseguenza
  anche la pagina successiva scaricata per lo scroll infinito ripete queste
  query, pur scartando poi la sidebar ricevuta.
- La colonna destra è nascosta via CSS sotto i **1024 px**, ma questo oggi non
  impedisce al server di calcolare le tendenze. Il layout autenticato esegue
  inoltre altre query, per esempio notifiche e suggerimenti: spostare le
  tendenze non azzera da solo il costo della prima risposta.

## Macrofase 1 — Tendenze via JSON, solo quando visibili

Rimuovere `PopularHashtagsQuery` dal rendering sincrono della sidebar.
Lasciare nel box il titolo e uno stato leggero di caricamento; un endpoint
autenticato restituisce i soli dati necessari per costruire le righe
(hashtag, URL, conteggio già formattato e indicazione di ulteriori risultati).
Riutilizzare la query e le regole di moderazione esistenti, senza duplicarne
la logica in JavaScript.

Il browser richiede i dati una volta sola quando il box è visibile. Un
`IntersectionObserver` può rilevarlo; la richiesta non deve partire quando
la sidebar è nascosta dal breakpoint CSS, nemmeno se si visitano altre pagine
autenticate. Gestire il cambio di larghezza della finestra, lo stato vuoto e
un errore senza bloccare il resto della pagina. Senza JavaScript può restare
un collegamento alla pagina `/tendenze`, senza rimettere la query nel percorso
sincrono.

Questa fase alleggerisce sia la prima Home sia le pagine successive dello
scroll infinito attuale. L'HTML scaricato dallo scroll viene solo interpretato
per estrarne i post: non deve innescare una seconda richiesta per il box delle
tendenze scartato. Verificare che l'endpoint non venga chiamato su viewport
sotto i 1024 px e che il box si popoli correttamente su desktop.

Prima e dopo la modifica, misurare il tempo della prima risposta `/home` e di
una richiesta `/home?cursor=…`, oltre al tempo dell'endpoint JSON. Se il costo
residuo del layout è ancora significativo, esaminare separatamente i
suggerimenti della sidebar e le altre query condivise; non spostarle per
assunzione.

## Macrofase 2 — Prime card via HTML asincrono

Se la Home resta percettibilmente lenta dopo la prima fase, restituire subito
il layout, il composer e un contenitore del feed con stato di caricamento,
senza eseguire `FeedQuery::forActor()` nella richiesta iniziale. Il browser
chiede poi la prima pagina del feed come **frammento HTML** già renderizzato
dal server, non come JSON da trasformare in card nel client.

Usare lo stesso frammento per le pagine successive dello scroll infinito:
card e URL del cursore successivo devono essere sufficienti al JavaScript,
senza ricostruire e trasferire l'intero layout. Mantenere invariati
ordinamento, visibilità, annotazioni per il visualizzatore, deduplicazione e
semantica del cursore. Un caricamento fallito deve offrire un messaggio e la
possibilità di riprovare; prevedere un percorso utilizzabile anche senza
JavaScript. Conservare il comportamento della Home vuota/kit di benvenuto e
del composer, inclusa la citazione di un post da `?quote=`.

Misurare separatamente il tempo fino all'HTML iniziale e quello fino alle
prime card: la query del feed può continuare a impiegare tempo, ma non deve
trattenere la cornice della pagina. Verificare primo caricamento, scroll,
pagina vuota, nuovi post arrivati durante lo scroll, errore/retry, viewport
mobile e funzionamento senza JavaScript. L'estrazione del solo frammento deve
evitare anche le query di layout che oggi accompagnano le pagine successive.

## Confini

- Non cambiare la query o l'algoritmo del feed nell'ambito di questo lavoro:
  qui si modifica il percorso di rendering, non la selezione dei post.
- Non introdurre code, polling continuo o un framework frontend. Restare su
  richieste HTTP mirate e JavaScript leggero, coerente con l'interfaccia
  esistente.
- Non assumere che la macrofase 2 sia necessaria prima di aver misurato il
  risultato della macrofase 1.
