# Provenienza di acquisizione dei post

Stato: persistenza implementata e verificata, 3 novembre 2026.
Nessuna modifica alla UI o al contratto pubblico REST/ActivityPub.
Ramo: `posts_source`, creato da `main` allineato all'upstream (`1b2a178`).
Implementati salvataggio e migrazione; interfaccia ed esposizione API escluse.

## 1. Obiettivo

Conservare sul singolo post come OpenBook lo ha acquisito per la prima volta,
quando la provenienza è significativa: relay, comunità o feed RSS/Atom.
Il dato descrive il canale che ha fatto scoprire il post all'istanza, senza
cambiare autore, identità ActivityPub, appartenenza o visibilità del contenuto.

L'obiettivo successivo è una piccola icona in basso a destra nella card, con
una descrizione della provenienza disponibile tramite hover e modalità
accessibili equivalenti per tastiera e tocco. Con provenienza nulla l'icona
non viene mostrata. L'interfaccia resta fuori dal perimetro implementato.

L'analogia con Via HTTP riguarda la provenienza osservata da OpenBook:
non si ricostruisce una catena di tutti i server attraversati e non si
introduce una nuova estensione federata da propagare agli altri server.

## 2. Decisioni concordate

| Campo opzionale nel JSON | Quando usarlo | Identità della sorgente |
| --- | --- | --- |
| `null` | Post locale, consegna ordinaria, acquisizione senza una delle sorgenti previste o provenienza storica non registrata | Nessuna |
| `relay` | Il post viene acquisito grazie a un relay riconosciuto | URI del relay |
| `community` | Il post viene acquisito grazie all'annuncio/inoltro di un actor Group | URI dell'actor della comunità |
| `rss` | Il post è importato da un feed RSS o Atom | URL del feed, non del singolo articolo |

Regole ferme:

1. Registrare la **prima acquisizione riuscita**, nella stessa persistenza
   che crea il post. Un fallimento seguito da rollback non stabilisce una
   provenienza definitiva.
2. Aggiornamenti, retry, boost e consegne successive non sovrascrivono il dato.
   Vale anche se la prima acquisizione ha salvato `null`: un successivo
   Announce non deve attribuire retroattivamente il post al suo mittente.
3. Se un relay annuncia soltanto l'URI e OpenBook scarica poi il documento
   originale dall'autore, registrare quel relay. Il fetch è il meccanismo
   tecnico per ottenere i byte, non una nuova provenienza.
4. Lo stesso principio vale per un Announce di comunità con oggetto espresso
   come URI, anziché incorporato nel messaggio.
5. L'appartenenza del post a una comunità non prova che il post sia arrivato
   attraverso quella comunità. Non derivare la provenienza da `community_id`.
6. Non aggiungere campi `fetch`, `inbox` o `unknown` nel JSON. L'assenza
   di una provenienza speciale è rappresentata da `null`, senza deduzioni.
7. Nessun backfill dei post esistenti. Un relay assente dalle attività inbox
   non dimostra che il post sia arrivato direttamente dall'autore.
8. Il dato resta metadato locale. Nessuna esposizione automatica nei documenti
   ActivityPub o nel contratto REST con questa modifica.
9. Comunità e relay possono descrivere lo **stesso arrivo**: conservare entrambi
   quando verificati. I loro ruoli sono distinti, quindi non serve scegliere
   una precedenza o memorizzare una lista ordinata di passaggi. Non aggiungere
   informazioni ricavate da consegne successive alla prima acquisizione.

Esempi:

- Relay R annuncia P; OpenBook esegue GET di P presso l'autore: P conserva R.
- Group G annuncia P sconosciuto: P conserva G, anche se serve un GET di P.
- L'annuncio di G arriva attraverso il relay R: P conserva sia G sia R.
  La futura UI può descriverlo come «Post ricevuto nella comunità G tramite
  il relay R». G deve essere parte del percorso osservato, non soltanto una
  comunità associata al contenuto.
- P è già presente prima dell'Announce di G: la provenienza di P non cambia.
- P appartiene a G ma arriva dall'autore: `null`, non G.
- Feed F importa l'articolo A: origine `rss`, URI di F; l'URI di A rimane il
  riferimento dell'articolo, distinto dalla provenienza di acquisizione.
- Un recupero di P fa scaricare anche una citazione Q: Q non eredita
  automaticamente la provenienza di P. Il contesto va associato al suo oggetto.

## 3. Persistenza

Una colonna JSON nullable su `posts`, nome confermato `received_via`, default
SQL `NULL`. Forma confermata: un oggetto con chiavi opzionali che identificano il
ruolo della sorgente: `relay`, `community`, `rss`, ciascuna con il proprio URI.
Non un discriminatore esclusivo `kind`/`uri`, perché relay e comunità possono
coesistere nello stesso arrivo.

```json
{
  "relay": "https://relay.example/actor"
}
```

```json
{
  "community": "https://forum.example/c/cucina",
  "relay": "https://relay.example/actor"
}
```

```json
{
  "rss": "https://blog.example/feed.xml"
}
```

È valido anche il solo campo `community`. Le chiavi non applicabili vengono
omesse; se non c'è nessuna informazione prevista si salva SQL NULL, non `{}`.
L'oggetto è estendibile senza trasformarlo in uno storico o in una catena di
hop arbitrari. Ogni chiave descrive un ruolo verificato nella prima acquisizione.
La combinazione osservata comunità+relay non è una fusione di arrivi distinti;
RSS descrive un'importazione effettiva del feed, non l'eventuale origine RSS
presunta di un autore federato.

Identità persistente come URI, senza dipendere soltanto da una FK nullable:
eliminare la configurazione del relay/feed o un actor dalla cache non deve
cancellare la traccia storica. Evitare URI di inbox, nomi o domini come unica
identità quando è disponibile l'URI canonico dell'actor.

Per ora non servono timestamp duplicati, cronologie di consegna o indici sul
JSON: l'uso previsto è leggere il metadato insieme al post per la futura card.
`created_at` resta il momento di persistenza locale, distinto dal `published`
dichiarato dall'autore e dall'ora di ricezione dell'attività inbox.

Se in futuro serviranno filtri o statistiche per origine, valutarne le query
e gli indici effettivi su MySQL/MariaDB; non trattare i contatori delle consegne
come numero di post acquisiti unici. Nessuna nuova query aggregata ora.

## 4. Ricognizione del codice attuale

### Ingresso ActivityPub

`InboxController` verifica la firma HTTP e individua il relay tramite
`RelayIngressResolver`; conserva `inbox_items.relay_id`. In caso di forwarding
può autenticare e normalizzare l'attività, conservando in `actor_uri` l'actor
dell'attività. Questo non coincide necessariamente con il trasportatore HTTP.
`signature_key_id` è conservato, ma non va reinterpretato come prova autonoma
dell'identità o del tipo del trasportatore.

`InboxActivityProcessor` gestisce Create/Update/Announce. Per un relay, Announce
è un contenitore di trasporto e non una condivisione sociale del relay.
Per i Group, Announce può invece essere il percorso con cui viene acquisito
il post di un altro autore. Il post può essere incorporato o recuperato per URI.

`resolveAnnouncedRemotePost()` passa attraverso fetch, risoluzione dell'autore
e `RemoteNoteUpserter`. Per i documenti recuperati azzera il parametro `$source`,
che ha anche un significato per la validazione dei sondaggi e dei loro
aggiornamenti. **Non cambiare questa regola di autenticazione per conservare
la provenienza:** serve un contesto di acquisizione indipendente che sopravviva
al fetch e arrivi alla persistenza del post interessato.

`RemoteNoteUpserter` distingue già creazione e aggiornamento con `$wasNew` e
lavora in transazione. La provenienza va applicata solo durante la creazione,
non con un successivo aggiornamento condizionato al fatto che il campo sia nullo.
I sondaggi Question persistiti come post seguono lo stesso principio, mantenendo
intatte le verifiche del loro flusso.

### RSS/Atom

`FeedImporter::upsertEntry()` crea i nuovi post oppure riconosce quelli già
presenti tramite `source_uri`/`uri`. `FeedSource.feed_url` identifica il feed;
`posts.source_uri` identifica l'articolo e non deve cambiare significato.
Al momento della creazione è disponibile l'actor feed, collegato alla sorgente.
Un'importazione che riconosce un post esistente non modifica la sua provenienza.

### Statistiche già esistenti

`DeliverySource` e `DeliveryStats` distinguono oggi relay, inbox, fetch, feed e
origine sconosciuta per le metriche di consegna. Non conservano la provenienza
sul post e usano per i relay anche chiavi hash/etichette, insufficienti da sole
per la nuova identità URI.

Riutilizzare i punti dove il contesto è già noto, senza cambiare la semantica
delle statistiche: il loro tipo `feed` non deve essere rinominato in `rss` solo
per allinearlo al nuovo metadato. Valutare un piccolo contesto tipizzato o una
forma condivisa minimale; evitare un secondo framework di tracking.

## 5. Decisioni finali e agganci di implementazione

L'utente conferma:

- includere sia l'Announce della comunità con oggetto incorporato o URI, sia
  l'inoltro dell'attività originale quando la comunità è identificata dal
  trasporto verificato;
- `posts.received_via` JSON nullable con chiavi opzionali `community`, `relay`,
  `rss`; nessun aggiornamento dopo l'inserimento, neppure quando il valore è NULL;
- implementare prima persistenza e test. Indicatore nella card, nomi leggibili,
  link ed eventuale esposizione REST restano attività successive. Nessuna
  esposizione del metadato nei messaggi ActivityPub.

Gli agganci esistenti permettono un intervento circoscritto:

1. **Ricezione → coda.** In InboxController l'identità del firmatario HTTP è
   disponibile dopo la verifica. Il relay è già identificato, ma il Group che
   inoltra un'attività di un altro autore può andare perso quando `actor_uri`
   viene normalizzato sull'actor dell'attività. Conservare il minimo contesto
   verificato prima di accodare in `inbox_items.received_via` JSON
   nullable nello stesso formato. È contesto di trasporto della coda, non una
   seconda cronologia del post e non un campo aggiunto al payload firmato.
2. **Processor → risoluzione dell'Announce.** `handleAnnounce()` conosce chi
   annuncia e l'eventuale relay. Costruire/completare il contesto di acquisizione
   per il post interessato e passarlo esplicitamente a
   `resolveAnnouncedRemotePost()`, sia per l'oggetto incorporato sia per URI+GET.
   Nei Create inoltrati riusare il contesto verificato conservato nella coda.
   Anche `RemoteOutboxFetcher::ingestGroupAnnounce()` passa il Group noto
   all'upsert: un Announce di comunità resta tale anche quando recuperato
   dall'outbox; il resto dei fetch ordinari continua a inserire NULL.
3. **Fetch → upsert.** Aggiungere un parametro opzionale dedicato al contesto,
   che raggiunga `RemoteNoteUpserter::upsertPost()` e la sua transazione.
   Il fetch può continuare ad azzerare `$source` per le verifiche esistenti dei
   sondaggi, senza perdere `received_via`. Non introdurre contesti globali o
   modificare `NoteDocumentFetcher`: la provenienza viaggia nel chiamante.
4. **Inserimento.** L'upserter dispone già di `$wasNew`: assegnare la provenienza
   lì prima del primo salvataggio. I chiamanti ordinari non passano il nuovo
   parametro e continuano a inserire NULL. Nei recuperi collaterali di genitori
   o citazioni non propagare il parametro automaticamente.
5. **RSS.** Impostare il JSON nella `Post::create()` di FeedImporter usando il
   feed collegato all'actor, senza modificare il caso di articolo già presente.

Le firme, l'autenticazione degli oggetti recuperati, le regole di visibilità
ed i contatori DeliveryStats rimangono separati da questo dato. Non dedurre
un Group dall'appartenenza del post o dal solo key ID. Un relay può essere a
sua volta un actor di tipo Group: non registrarlo anche come comunità soltanto
per quel tipo; i due ruoli devono essere effettivamente osservati.

Il dettaglio del nome leggibile e la gestione degli URL feed con credenziali
vanno definiti insieme alla UI, prima di mostrarli ai visitatori. Per ora
non costituiscono un blocco per il salvataggio del metadato locale.

## 6. Processo di implementazione proposto

### Passo 1 — Persistenza e cattura della provenienza (implementato)

- Seguire le decisioni e gli agganci confermati nella sezione 5.
- Migrazioni nullable per post e contesto inbox, cast e scrittura della
  provenienza solo sui nuovi post.
- Collegare i percorsi relay, Announce dei Group ed RSS ai punti di creazione,
  preservando il contesto attraverso fetch e processing asincrono.
- Conservare `null` per post locali/ordinari e post già presenti; nessuna
  migrazione dei dati storici, modifica delle statistiche o delle firme AP.
- Test mirati sui flussi, compatibilità della migrazione SQLite/MySQL,
  documentazione e changelog secondo le regole del progetto.

### Passo 2 — Indicatore nella card, successivamente

- Piccola icona coerente con la provenienza, in basso a destra.
- Descrizione combinata quando sono presenti comunità e relay: «Post ricevuto
  nella comunità X tramite il relay Y», senza invertirne i ruoli.
- Nessuna icona per `null`; descrizione accessibile con hover, focus e tocco.
- Risoluzione/etichetta/link della sorgente senza chiamate federate per card.
- Valutare separatamente un'eventuale esposizione REST: se richiesta,
  aggiornare insieme contratto pubblico, implementazione e test.

L'utente ha autorizzato l'implementazione del salvataggio e dei relativi test,
con esplicita esclusione di icone e altre modifiche alla UI.

## 7. Verifiche di uscita

- Relay con oggetto incorporato e con URI+fetch: stessa provenienza corretta.
- Group Announce incorporato e URI+fetch: provenienza della comunità corretta.
- Create inoltrato da un Group verificato: identità conservata attraverso
  normalizzazione e processamento asincrono, distinta dall'autore del post.
- Relay di tipo Group: nessuna comunità fittizia aggiunta per il solo tipo.
- Annuncio di comunità trasportato dal relay: entrambi gli URI conservati,
  anche dopo fetch del documento originale; nessuna precedenza esclusiva.
- Relay con post soltanto appartenente a una comunità: non aggiungere
  `community` se non è parte dell'arrivo osservato.
- Arrivo via comunità seguito da consegna del relay, o viceversa: non fondere
  i due arrivi nel JSON della prima acquisizione.
- RSS/Atom: URL del feed, non dell'articolo, soltanto alla prima creazione.
- Post locale o consegna ordinaria: SQL NULL, senza oggetti JSON vuoti.
- Acquisizione ordinaria seguita da relay/Group: rimane NULL.
- Acquisizione da una sorgente seguita da un'altra o da Update: resta la prima.
- Nessuna provenienza registrata per un contenuto rifiutato o annullato dal DB.
- Retry, duplicati e concorrenza non cambiano una provenienza già stabilita;
  sfruttare idempotenza e vincoli esistenti, verificando i punti di creazione.
- Fetch di genitori/citazioni non eredita accidentalmente il contesto del post.
- Nessuna modifica delle verifiche di firma, audience, visibilità, sondaggi,
  community private, notifiche o contatori statistici.
- Migrazione up/down conserva i dati esistenti, default NULL, MySQL/MariaDB
  e SQLite compatibili; eventuali query nontriviali verificate con EXPLAIN.
- Per la UI futura, assenza di richieste esterne e nessuna esposizione di URL
  con informazioni riservate. Prove desktop, mobile e tastiera.

## 8. Riferimenti

- [ActivityPub — Forwarding from Inbox](https://www.w3.org/TR/activitypub/#inbox-forwarding).
- [ActivityPub — Announce](https://www.w3.org/TR/activitypub/#announce-activity-inbox).
- `app/Http/Controllers/Federation/InboxController.php`.
- `app/Federation/Inbox/InboxActivityProcessor.php` e `RemoteNoteUpserter.php`.
- `app/Domain/Feeds/FeedImporter.php` e `FeedSource.php`.
- `app/Application/Services/DeliverySource.php` e `DeliveryStats.php`.

## 9. Implementazione e verifiche

- Migrazione `2026_11_03_000000_add_received_via_to_posts_and_inbox_items.php`:
  JSON nullable in posts e inbox_items, nessun backfill o nuovo indice.
- `PostReceivedVia` raccoglie il contesto verificato di trasporto e lo completa
  con il Group che annuncia, evitando di etichettare un relay Group anche come
  comunità soltanto per il tipo. Nessun contesto globale o nuova tabella.
- InboxController salva il contesto fuori dal payload firmato, dopo l'esito
  positivo dell'autenticazione. Il processor lo passa esplicitamente fino
  all'upserter, senza alterare le verifiche di sondaggi o audience.
- RemoteNoteUpserter assegna il JSON soltanto nel ramo `$wasNew`, nella stessa
  transazione del post. Recuperi collaterali di citazioni non lo ereditano.
- FeedImporter scrive l'URL del feed soltanto nella creazione della voce.
  Reimportare articoli esistenti conserva sia valori precedenti sia NULL.
- Announce del Group letto dall'outbox conserva la provenienza della comunità;
  un Group configurato come relay resta invece un relay, anche nella lettura
  dell’outbox e nella consegna a inbox personali. Nessun valore generico
  fetch/inbox aggiunto. Metriche DeliveryStats e autorizzazioni invariate.
- Migrazione up/down verificata su MySQL locale con sole tabelle temporanee
  della connessione: dati precedenti preservati, default SQL NULL e round-trip
  JSON corretti. SQLite verificato nella suite. Nessuna prova diretta MariaDB.
  Il riconoscimento del relay riusa RelayIngressResolver, una volta per outbox
  Group e anche per i firmatari Group delle inbox personali. EXPLAIN su MySQL
  locale conferma accesso ref tramite relays_state_receive_enabled_index
  (3 righe stimate, 3 relay configurati); nessun nuovo indice necessario.
  La provenienza non estende alle inbox personali i permessi dei relay
  riservati alla shared inbox.
- Test mirati: relay/community separati e insieme, oggetti incorporati e
  URI+fetch, firme HTTP e normalizzazione asincrona, fonte non sovrascritta
  dopo Update/retry, NULL invariato, rollback, contenuti rifiutati, citazioni,
  RSS/Atom, migrazione e assenza del metadato da REST e ActivityPub.

Verifica finale del 3 novembre 2026, PHP 8.5.12:

- Suite completa: **2.277 test, 17.293 asserzioni**, nessun errore o fallimento;
  130 saltati della baseline, senza nuovi test saltati per questa attività.
  Comando: `APP_CONFIG_CACHE=/tmp/openbook-posts-source-test-config.php php vendor/bin/phpunit`.
- Pint e `git diff --check` passati. Review dell'intero diff conclusa: modifiche
  limitate a persistenza, contesto di acquisizione, test e documentazione.
- Migrazione applicata anche al database locale; nessun dato storico
  ricostruito. I nuovi campi precedentemente assenti sono nullable.
- Dopo deploy riavviare i processi inbox già in esecuzione affinché utilizzino
  il nuovo codice. Nessuna nuova coda, configurazione o servizio obbligatorio.
- Non eseguito un test concorrente su più connessioni MySQL: l'immutabilità
  riusa il lock e i vincoli URI esistenti; testati retry, duplicati HTTP,
  rollback e aggiornamenti di post già presenti, inclusa provenienza NULL.
