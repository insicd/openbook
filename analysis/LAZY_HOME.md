# Home progressiva: tendenze e feed asincroni

Documento di analisi e piano di lavoro. Gli sprint 1 e 2 della macrofase 1
sono implementati e la prova dei tempi tra le macrofasi ha dato esito
positivo. Nella macrofase 2, gli sprint 3 e 4 caricano tramite lo stesso
frammento HTML sia il primo blocco sia le pagine successive della Home.

## Obiettivo

Mostrare rapidamente la cornice della Home autenticata, lasciando che i
contenuti più costosi arrivino poco dopo. L'obiettivo è migliorare il tempo
percepito prima che l'utente veda una pagina utilizzabile, senza promettere
che le query sottostanti diventino più veloci. Procedere in due macrofasi
misurabili, mantenendo l'interfaccia semplice e senza introdurre worker o
dipendenze obbligatorie. Entrambe le macrofasi sono previste: tra una e
l'altra ci si ferma per provare il risultato e confrontare le misure, non per
decidere se realizzare la seconda.

## Situazione di partenza (prima dello sprint 1)

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

Solo dopo avere completato e provato la UI asincrona, aggiungere in uno sprint
distinto una cache condivisa delle tendenze con durata di **5 minuti**. Non
richiedere Redis o altri servizi esterni: usare il sistema di cache già
disponibile nell'installazione ordinaria. Misurare separatamente richieste con
cache fredda e calda, così da distinguere il beneficio dell'asincronia da
quello della cache.

Decisione successiva: l'accesso a `/tendenze` invalida subito la cache del
box laterale. Un cambio della finestra temporale o delle impostazioni di
moderazione rende comunque inutilizzabile il valore precedente, anche prima
della scadenza dei cinque minuti.

## Macrofase 2 — Prime card via HTML asincrono

**Stato implementato:** `/home` usa lo stesso endpoint per due risposte. La
navigazione normale restituisce la cornice della Home con layout, composer,
eventuale citazione e pubblicazioni video in attesa, senza interrogare il feed
né preparare il kit di benvenuto. La richiesta AJAX a `/home` interroga il feed
e restituisce un frammento HTML: le card, oppure il kit di benvenuto se il
primo blocco è vuoto. Le richieste AJAX a `/home?cursor=…` restituiscono le
card successive. Non esiste una route separata né un algoritmo diverso per il
primo blocco.

Restituire subito il layout, il composer e un contenitore del feed con stato
di caricamento, senza eseguire `FeedQuery::forActor()` nella richiesta
iniziale. Il browser chiede poi la prima pagina del feed come **frammento
HTML** già renderizzato dal server, non come JSON da trasformare in card nel
client.

Usare lo stesso frammento per le pagine successive dello scroll infinito:
card e URL del cursore successivo devono essere sufficienti al JavaScript,
senza ricostruire e trasferire l'intero layout. Mantenere invariati
ordinamento, visibilità, annotazioni per il visualizzatore, deduplicazione e
semantica del cursore. Il primo blocco e i successivi devono usare la stessa
logica JavaScript di caricamento e gestione degli errori. Se una richiesta
fallisce, mostrare un messaggio comprensibile e un **link per riprovare** la
stessa richiesta, senza perdere le card già presenti. Conservare il
comportamento della Home vuota/kit di benvenuto e del composer, inclusa la
citazione di un post da `?quote=`.

Il feed asincrono richiede JavaScript. Senza JavaScript, mostrare al massimo
un messaggio in `<noscript>` che lo spieghi; non costruire un secondo percorso
di rendering completo della Home solo per questo caso.

Misurare separatamente il tempo fino all'HTML iniziale e quello fino alle
prime card: la query del feed può continuare a impiegare tempo, ma non deve
trattenere la cornice della pagina. Verificare primo caricamento, scroll,
pagina vuota, nuovi post arrivati durante lo scroll, errore/retry, viewport
mobile e messaggio `<noscript>`. L'estrazione del solo frammento deve
evitare anche le query di layout che oggi accompagnano le pagine successive.

## Sprint e punti di prova

Ogni sprint è una modifica separata, verificabile prima di iniziare il
successivo. Le misure vanno prese su dati rappresentativi e confrontate nelle
stesse condizioni; il tempo della risposta iniziale e quello fino ai contenuti
visibili sono due risultati distinti.

1. **Tendenze asincrone.** Preparare l'endpoint JSON autenticato e il box con
   caricamento su visibilità, stato vuoto ed errore. Rimuovere la query delle
   tendenze dal view composer. Verificare desktop, viewport sotto i 1024 px e
   scroll attuale; rilevare i tempi senza cache.
2. **Cache delle tendenze.** Aggiungere la cache di 5 minuti senza cambiare
   endpoint o UI. Verificare che scadenza e configurazione di moderazione non
   facciano apparire risultati non consentiti; confrontare cache fredda e
   calda.

**Pausa di prova tra le macrofasi:** usare la Home e le altre pagine
autenticate, controllare i tempi e annotare l'eventuale costo residuo del
layout. La seconda macrofase resta prevista anche se la prima dà un buon
risultato.

3. **Frammenti HTML per lo scroll.** Esporre un frammento che contenga card e
   cursore successivo, riusando query, annotazioni e vista delle card attuali.
   Far usare il frammento alle pagine successive dello scroll, mentre la prima
   pagina della Home resta ancora renderizzata nella risposta iniziale.
   Preparare la logica di caricamento condivisa e il link di retry. Implementato
   usando lo stesso URL `/home?cursor=…`: la richiesta AJAX riceve il frammento,
   la navigazione normale la cornice della Home. Gli altri elenchi non cambiano.
4. **Primo blocco asincrono.** Togliere la query del feed dalla risposta
   iniziale `/home` e richiedere il primo frammento con la stessa logica usata
   dallo scroll. Integrare kit di benvenuto, stato vuoto, composer e messaggio
   `<noscript>`. Provare il percorso completo e confrontare il tempo fino alla
   cornice con quello fino alle prime card. Implementato: `/home` senza header
   AJAX restituisce la cornice; la richiesta AJAX allo stesso URL restituisce
   il primo frammento. Il kit di benvenuto arriva nel contenitore del feed
   quando il primo blocco è vuoto, e il composer resta nella cornice iniziale.

## Confini

- Non cambiare la query o l'algoritmo del feed nell'ambito di questo lavoro:
  qui si modifica il percorso di rendering, non la selezione dei post.
- Non introdurre code, polling continuo o un framework frontend. Restare su
  richieste HTTP mirate e JavaScript leggero, coerente con l'interfaccia
  esistente.
- Non fondere lo spostamento asincrono delle tendenze con l'aggiunta della
  cache: sono due ottimizzazioni da provare separatamente.
