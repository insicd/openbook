# Anteprime dei link esterni — analisi iniziale

Documento di analisi e memoria degli sprint implementati nel ramo
`post_preview`, poi confluito in `main`.

## Obiettivo

Valutare anteprime dei link esterni che siano utili e gradevoli, mantenendo
l'implementazione semplice e il costo di rete, elaborazione e archiviazione
sotto controllo.

## Casi d'uso

### Post pubblico con link a un articolo

Il post locale `01a0d3c9-48d0-72c9-a2ab-fb3050f39559` contiene nel body
un solo URL esterno:
`https://robinbannks.com/2026/09/24/love-over-gold/`. L'obiettivo è mostrare
una card con i metadati Open Graph disponibili (per esempio immagine, titolo e
breve descrizione), mantenendo il link cliccabile verso la pagina originale.
La verifica successiva ha confermato almeno il titolo Open Graph dell'esempio
(`Love Over Gold`); non assumiamo che tutti i tag `og:*` siano presenti.

OpenBook renderizza già i link del body e dispone di un embed specifico per
YouTube/PeerTube. Una card generica non deve duplicare quell'embed.

### Decisioni iniziali

- La card generica è un'ultima risorsa: compare solo se il body del post
  originale contiene **una sola destinazione web distinta candidata** (due
  occorrenze dello stesso URL contano come una; i link Markdown etichettati
  come menzioni `@…` o hashtag `#…` non contano), il post **non ha
  un'immagine allegata**
  e quel link **non è YouTube o PeerTube**. In quest'ultimo caso resta l'embed
  video già esistente.
- La card compare nella timeline e nel dettaglio del post, nella stessa
  posizione dell'embed video attuale: dopo il body e prima degli allegati.
- Il rendering della pagina non attende la rete esterna: il browser richiede
  la card tramite un'API separata. **Nessuna coda**: se i metadati non sono in
  cache, è la chiamata API ad attendere il recupero, con un timeout definito.
- Prima della risposta valida la card resta completamente nascosta; timeout
  ed errori non producono una card visibile.
- Le richieste client partono per i post caricati nella pagina, senza
  aspettare che entrino nel viewport. Una piccola coda **solo nel browser**
  limita a tre le chiamate API contemporanee, anche quando lo scroll infinito
  aggiunge altri post.
- Tabella di cache dedicata con `fetched_at`, **senza `expires_at`**:
  i risultati positivi sono validi inizialmente per una settimana e gli
  errori per un'ora. La scadenza si calcola al momento della lettura, così
  cambiare le durate configurate vale anche per le righe già presenti.
- L'API richiede un utente autenticato: non deve diventare un servizio
  pubblico di scraping. Il client passa **l'ID del post**, non un URL.
  L'API verifica che il post sia pubblicato e **public**, ricava dal body
  il suo unico link idoneo e recupera i metadati. La cache è per URL e non
  mantiene riferimenti ai post: due post che contengono lo stesso URL
  condividono la stessa riga.
- La tabella salva solo i campi normalizzati utili alla card, **mai l'HTML
  originale** della pagina. Il parsing avviene durante il fetch.
- Le immagini OG sono referenziate direttamente dal browser come gli altri
  media remoti già presenti; non è previsto un proxy delle immagini.
- Le anteprime si applicano soltanto ai post **public**, non a unlisted,
  followers-only o direct.
- Il fetch usa l'URL completo, inclusa la query string. Non si eliminano
  parametri: molti link non identificano correttamente la pagina senza di
  essi. Il fragment può essere rimosso, perché non viene inviato in HTTP.
- La card appare solo se è disponibile almeno `og:title`; descrizione e
  immagine sono facoltative. In assenza di metadati sufficienti si conserva
  un esito negativo per un'ora e non si mostra alcuna card.
- Il cache miss sincrono ha un limite di **5 secondi complessivi**, inclusi
  eventuali redirect.

Schema indicativo, da affinare: URL completo usato per il fetch, impronta univoca
dell'URL per l'indice, titolo, descrizione, nome del sito, URL
dell'immagine, esito del recupero e `fetched_at`. I campi
testuali vanno troncati a lunghezze ragionevoli; i campi OG non necessari
alla card non vengono archiviati.

### Ipotesi tecniche da verificare

- L'API ricalcola sempre l'idoneità del post e del link lato server: non si
  fida di un URL fornito dal browser. Autenticazione e rate limit restano
  necessari per limitare i cache miss provocabili da un account.
- Il download è limitato a pagine HTTP(S) pubbliche, con controlli su DNS,
  redirect, dimensione della risposta e timeout: i link nei post non sono
  affidabili e non devono permettere richieste alla rete interna del server.
- Riutilizzare, se adatto, il `SafeHttpClient` già presente per i fetch
  federati, verificando i limiti applicati a risposte HTML e tempi di attesa.
- Rate limit dell'API e protezione contro recuperi concorrenti dello stesso
  URL: l'autenticazione da sola non limita il costo dei cache miss.
- Leggere i meta tag richiede una richiesta `GET` limitata; un `HEAD` HTTP
  restituisce solo gli header della risposta, non l'elemento HTML `<head>`.
- Prima della visualizzazione considerare contenuti sensibili e l'effetto
  privacy delle immagini caricate direttamente da un host esterno.

La view condivisa dei post mostra oggi sia gli embed YouTube/PeerTube sia gli
allegati; la nuova condizione dovrà distinguere le immagini dagli altri media
senza aggiungere query per ogni card della timeline.
Lo scroll infinito aggiunge dinamicamente i post successivi alla pagina: la
componente JS deve riconoscere anche queste nuove card, evitando doppie
richieste per i nodi già gestiti.

## Piano di implementazione

### Sprint 1 — Recupero sicuro, cache e API

**Stato: implementato e verificato.**

- Migrazione per la cache per URL, con campi OG normalizzati, esito e
  `fetched_at`; configurazione delle due durate (successo/errore).
- Selezione del link idoneo dal body del post public, con controllo di
  immagini allegate e precedenza agli embed YouTube/PeerTube.
- Fetch HTTP sicuro e limitato a 5 secondi complessivi, parsing dei soli
  metadati necessari, cache dei successi e dei fallimenti, protezione dalle
  richieste concorrenti per lo stesso URL.
- Endpoint autenticato e rate-limited che riceve l'ID del post; test su
  idoneità, deduplicazione della cache, errori, redirect e SSRF.
- Pulizia della cache già integrata nella manutenzione del database: compare
  nel pannello admin ed è eseguita anche da `openbook:purge-database` tramite
  `openbook:cron`. Elimina solo righe il cui `fetched_at` risale ad almeno
  30 giorni fa, o più se i TTL configurati lo richiedono.
- Verifica manuale: chiamare l'API per il post di esempio e controllare
  risposta e riga in cache.

L'endpoint è `GET /posts/{post}/link-preview` (richiede login). Sul database
locale la prova con il post d'esempio ha prodotto una riga positiva in
`external_link_previews`, con titolo `Love Over Gold`.
La descrizione OG viene ripulita dagli hashtag autonomi e dagli spazi
ridondanti prima di essere salvata; la stessa pulizia si applica in lettura
alle righe già in cache.

### Sprint 2 — Card asincrona nelle viste

**Stato: implementato e verificato visivamente.**

- Card inserita nella view condivisa, dopo il body, nascosta fino al
  risultato valido; titolo obbligatorio, descrizione/immagine facoltative,
  link esterno sempre esplicito.
- JavaScript senza dipendenze: massimo tre richieste API simultanee per
  pagina, inizializzazione dei post già presenti e di quelli aggiunti dallo
  scroll infinito, senza doppie richieste ai nodi già gestiti.
- Stili responsive e verifiche su timeline, dettaglio, post con CW,
  video embed, allegati, errori e cache hit/miss. Nessuna modifica alla
  serializzazione ActivityPub.
- I link Markdown di menzioni e hashtag non contano nella scelta dell'unico
  URL della card: un link all'articolo scritto in Markdown continua invece
  a contare. Questo evita che le attribuzioni nei post importati, come
  quelle di Flipboard, blocchino l'anteprima.
