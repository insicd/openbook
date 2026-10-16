# Sondaggi remoti — issue #120

Stato al 15 ottobre 2026: sprint 1–4 implementati e committati; revisione finale
completata, con correzioni e verifiche descritte in fondo. Importazione e voto
sono collegati all'interfaccia; filtro selettivo e anteprima inbox sono stati
esclusi dall'utente. I paragrafi sugli esiti degli sprint documentano le verifiche
incrementali e vanno letti insieme alla revisione conclusiva.
Issue: https://github.com/insicd/openbook/issues/120

Evoluzione nella #121: lo schema descritto negli esiti storici sotto è stato
unificato in `polls`, `poll_options`, `poll_votes` e `poll_vote_choices`, con
modelli comuni e relazione `Post::poll`. Le migration della #120 restano
invariate; una nuova migration rinomina le quattro tabelle e le estende senza
perdere i dati. Vedi [analisi dei sondaggi locali](LOCAL_POLLS.md).

## Obiettivo e vincoli

Visualizzazione e voto sono un'unica funzionalità: domanda, opzioni, scelta
singola/multipla, scadenza, risultati disponibili e risposte già votate.
Il voto iniziale è definitivo, senza modifica o annullamento;
non promettere anonimato verso i server coinvolti. La creazione locale è fuori
perimetro.

Riutilizzare visibilità, autorizzazioni, nascondimento, blocchi e servizi di
federazione esistenti. Nessun nuovo worker permanente o servizio obbligatorio.
Non equiparare una risposta HTTP positiva alla conferma che il server remoto
abbia contato il voto; non inventare risultati mancanti.

## Decisione concordata: voto completato alla messa in coda

Come per Like e commenti, l'operazione dell'utente è completata quando le
scelte e le attività federate necessarie sono persistite e messe in coda.
La UI conferma il voto e mostra le scelte effettuate senza attendere la
consegna al server remoto. Da quel momento il voto è definitivo e ulteriori
invii della stessa operazione non devono creare nuovi voti.

Consegna, retry e fallimento restano nel flusso federato esistente e non
introducono un ciclo di conferma del voto dipendente dalla rete. Anche una
consegna parziale di scelte multiple non riapre il voto e non modifica le
scelte locali. Gli eventuali errori di accodamento impediscono invece di
confermare l'operazione: evitare di persistere un voto definitivo privo delle
attività necessarie alla consegna.

I risultati mostrati restano quelli comunicati dal server di origine:
la conferma locale del voto non incrementa artificialmente i conteggi.

### Vincolo tecnico verificato nello sprint 1

Il flusso attuale di `ActivityDelivery` usa `afterCommit()` e può saltare la
consegna quando manca un'inbox o la destinazione è bloccata. Riutilizzarlo da
solo non dimostra che voto e accodamento siano persistiti coerentemente:
un errore dopo il commit non annulla la scrittura già effettuata.

La soluzione scelta è scrivere voto, snapshot delle scelte e job Laravel
`DeliverActivityJob` nella stessa transazione sulla stessa connessione DB.
`DatabaseQueue` con job `beforeCommit()` inserisce subito nella tabella `jobs`:
il commit rende durevoli tutte le righe insieme e il rollback le rimuove tutte.
I test SQLite e MySQL verificano anche il fallimento del secondo accodamento
dopo che il primo job è già stato inserito. Non occorrono tabella outbox
aggiuntiva, processo di recupero dedicato o un secondo sistema di consegna.

Nello sprint 3 questo contratto verrà collegato al servizio di voto, con la
normale coda `delivery`, firma e retry esistenti. Risolvere destinatario,
endpoint e blocchi prima della transazione; dentro la transazione ripetere
permessi, stato e versione delle opzioni. Confermare all'utente dopo il commit.
Nessun fetch o invio HTTP nella transazione. Gli ID di attività e Note saranno
derivati dall'UUID persistito della singola scelta e restano stabili nei retry.

La coda deve usare la connessione del dominio: un `DB_QUEUE_CONNECTION` diverso
o un driver globale `sync`/esterno non garantisce questa atomicità. Per i voti
usare comunque il backend database locale esistente, senza modificare il driver
degli altri flussi. Se serve un alias interno di connessione Laravel, deve
puntare alle stesse righe `jobs` del dominio; lo sprint 3 adeguerà il comando
di consegna/cron esistente a consumarle anche quando il driver globale è
diverso. Il test dello sprint 1 prova la transazione; il consumo tramite cron
nelle configurazioni alternative è un criterio di accettazione dello sprint 3.

## Dati locali verificati

Ricognizione in sola lettura sul MySQL configurato dall'applicazione, senza
rielaborare attività né inviare richieste federate. Tra 36.530 righe inbox sono
presenti 143 oggetti `Question` incorporati, relativi a 8 URI distinti:

| Attività | Stato inbox | Numero |
| --- | --- | ---: |
| Create | ignored | 2 |
| Create | pending | 1 |
| Update | ignored | 70 |
| Update | pending | 70 |

Tutti i casi trovati sono pubblici, con `oneOf`, 2 o 4 opzioni, conteggi
`replies.totalItems`, `votersCount` e `endTime`. Nessuno presenta `closed`
valorizzato; tutte le scadenze sono trascorse alla data della ricognizione.
Non sono state trovate Note candidate al voto con `name`, `inReplyTo` e contenuto
vuoto. Il campione non copre dunque voti reali, `anyOf`, audience riservate o
chiusura esplicita. Non sono stati risolti oggetti disponibili solo come URI.

Casi utili, identificati tramite URI pubblico e ID inbox locale:

- `https://io.mwl.io/users/mwl/statuses/117407645606807961`:
  1 Create e 35 Update, 4 opzioni, votanti da 0 a 46.
  Create: `01a11da2-11ac-7131-9a31-6eacf6d1b597`;
  ultimo Update disponibile: `01a1219c-8441-72b7-9548-926b06839932`.
- `https://cosocial.ca/users/evan/statuses/117407677386346981`:
  1 Create e 54 Update, 4 opzioni, votanti da 0 a 88.
  Create: `01a11dad-97a5-734e-91ee-74f89b55eaa7`;
  ultimo Update disponibile: `01a1212d-9ed8-7165-b12f-f6f6715ad8bc`.
- `https://mastodon.social/users/dansup/statuses/117404823581184649`:
  27 Update, 2 opzioni, votanti da 105 a 137; utile anche per verificare
  l'arrivo di un Update prima di un Create disponibile localmente.
  Primo Update disponibile: `01a11baf-cd4d-711b-9a49-db4226a07241`.

Prima di usare i payload come fixture versionate, anonimizzare identificativi e
testi conservando struttura, audience, timestamp relativi e sequenza dei conteggi.
Usare copie in un database di test e un orologio controllato per i casi aperti.
I record originali non vanno riaccodati indiscriminatamente sul database locale.

## Punti di integrazione individuati nella ricognizione iniziale

Questa sezione descriveva la base iniziale; i collegamenti effettuati sono
riepilogati in «Esito sprint 2».

- `RemotePostObject::POSTABLE_TYPES` non include Question: Create e Update
  incorporati non entrano nel normale flusso di importazione dei post.
- `InboxActivityProcessor` gestisce rilevanza, origine, autore e audience:
  estendere questo percorso mantenendo i controlli esistenti.
- `RemoteNoteUpserter` è condiviso dai percorsi di importazione e aggiornamento:
  conservare qui, tramite un servizio dedicato, anche i dati del sondaggio.
- Verificare fetch documento, outbox, Announce, refresh del post e replies:
  alcuni percorsi hanno controlli specifici per Note e non basta ampliare la
  lista dei tipi supportati.
- `DeliverActivityJob` offre firma, retry e fallimento definitivo; riutilizzare
  questo flusso mantenendolo disaccoppiato dalla conferma locale del voto.

## Altre decisioni concordate

- Compatibilità iniziale con il formato ActivityPub usato da Mastodon,
  includendo scelta singola e multipla; verificare il protocollo sulle fonti
  primarie prima di implementare la serializzazione del voto.
- Voto consentito fino a `endTime` oppure fino alla chiusura esplicita.
  Senza scadenza dichiarata, mostrare «Sondaggio aperto» e consentire il voto
  finché non arriva la chiusura. Usare testi positivi rivolti all'utente.
- Conservare il testo delle scelte confermate: una modifica o rimozione remota
  delle opzioni non deve attribuire all'utente una risposta diversa.
- Evidenziare subito le scelte locali, per esempio con spunta e «La tua
  risposta». Non sommarle né sottrarle dai conteggi remoti, che potrebbero già
  includerle. Conservare separati scelta personale e risultati remoti.
- Nessun polling dedicato; aggiornamenti da inbox e percorsi di refresh
  esistenti. Conteggi mancanti restano sconosciuti, non diventano zero.

## Invarianti architetturali e confini

- La cache remota del sondaggio appartiene al post; la scelta personale
  appartiene all'Actor locale che vota. Mostrare quest'ultima soltanto al suo
  utente, evitando contaminazioni attraverso cache o partial condivisi.
- Il voto è diretto al destinatario previsto dal protocollo del sondaggio.
  Non riutilizzare il fan-out dei commenti verso follower, community o relay:
  la scelta non deve essere distribuita come un normale contenuto sociale.
- I dati delle scelte personali non entrano nella rappresentazione federata
  del post o nei risultati aggregati. Non creare notifiche/commenti ordinari
  a partire dalle Note di voto ricevute.
- Importazione e refresh devono aggiornare dati e audience con gli stessi
  controlli dei post esistenti. Nuovi conteggi non devono far riapparire post
  eliminati, nascosti o moderati, né rendere pubblico un contenuto riservato.
- Conservare distinti zero, dato assente e dato malformato. Calcolare eventuali
  percentuali soltanto con un denominatore noto e coerente con la modalità del
  sondaggio; il numero dei votanti non si ricava dalla somma delle risposte
  multiple. Escape dei testi e limiti su numero/dimensione delle opzioni
  seguono le difese adottate per gli altri input remoti.
- Un documento incompleto o malformato non deve cancellare il voto personale
  né offrire risposte inventate. La disponibilità dell'azione dipende anche
  dall'esistenza di opzioni valide e di una destinazione utilizzabile.
- I controlli di autorizzazione e scadenza vengono ripetuti sul server alla
  conferma: la pagina aperta prima della scadenza non costituisce un permesso
  a votare successivamente.

## Sprint di implementazione e verifiche

Gli sprint sono incrementi verificabili dello stesso lavoro. Visualizzazione e
voto verranno resi disponibili insieme nella funzionalità completa.

### Sprint 1 — Modello dati, protocollo e fixture

Definire persistenza di sondaggio, opzioni e scelte locali, vincoli univoci,
identità delle opzioni e accodamento coerente con il flusso esistente.
Verificare il protocollo Mastodon su fonti primarie. Creare fixture realistiche
anonimizzate ispirate alle sequenze Create/Update dell'inbox; completarle con
scelta multipla, chiusura esplicita, scadenza assente e conteggi mancanti.

Verifica: test PHPUnit di normalizzazione e persistenza; migration su SQLite e
MySQL/MariaDB, inclusi vincoli. Orologio di test controllato, senza modificare
le scadenze dei record originali. Nessuna dipendenza della suite dal DB locale.

### Sprint 2 — Importazione e aggiornamenti

Integrare Question nei percorsi condivisi di inbox, fetch, outbox, Announce e
refresh. Conservare i controlli di autore, audience e rilevanza. Gestire Update
senza Create disponibile, duplicati e aggiornamenti fuori ordine; evitare di
importare le Note di voto come commenti ordinari.

Verifica: test attraverso i servizi reali, con HTTP simulato quando necessario;
replay delle sequenze fixture, ripetuto per verificare idempotenza e conteggi.
Controllare subito autore contraffatto, audience riservate e blocchi. Esaminare
con EXPLAIN i piani delle nuove query MySQL pertinenti.

### Sprint 3 — Voto e accodamento

Implementare voto singolo/multiplo autenticato, definitivo alla messa in coda,
con snapshot delle scelte e attività stabili per i retry. Riutilizzare consegna
e gestione errori esistenti. Verificare permessi, scadenza e opzioni al momento
della conferma, oltre ai vincoli contro richieste duplicate e concorrenti.

Verifica: test di autorizzazione e validazione, doppio invio, concorrenza su
MySQL, errori di accodamento e recupero dopo interruzione al commit secondo il
contratto definito nello sprint 1. Ispezionare le attività nella
coda database reale di test; simulare successo, retry e consegna parziale senza
cambiare voto locale o conteggi. Nessuna consegna verso utenti remoti reali.

### Sprint 4 — Interfaccia completa

Integrare domanda, selezione, conferma, risultati e stato in home e dettaglio,
con scelta personale subito evidenziata. Usare «Sondaggio aperto», scadenza
quando presente e «Sondaggio concluso». Gestire opzioni modificate o rimosse,
conteggi assenti, contenuti nascosti e visualizzazione di altri utenti.

Verifica: test HTTP/Blade e verifica browser desktop/mobile per scelta singola
e multipla, tastiera, conferma, ricaricamento e scadenza. Accertare che il voto
di un utente non appaia come scelta personale di un altro. Dimostrazione locale
con fixture aperte e risultati aggiornati tramite inbox, senza polling.

### Sprint 5 — Revisione complessiva e chiusura

L'utente ha escluso il filtro selettivo e l'anteprima del riprocessamento inbox:
non sono necessari per completare la funzionalità. Il comando esistente resta
invariato e ha già consentito la verifica del campione locale.

Chiudere con revisione critica di tutto il ramo rispetto a `main`: coerenza con
servizi e flussi del progetto, duplicazioni, nomi, privacy e regressioni.
Correggere i rilievi, eseguire test mirati e suite completa, verificare le query
su MySQL e allineare documentazione EN/IT e changelog prima del commit finale.

## Progettazione tecnica da completare negli sprint

Le decisioni di prodotto sono fissate. Le voci seguenti non sono approvazioni
pendenti: fanno parte del lavoro tecnico e vanno risolte prima di implementare
il comportamento interessato, documentando qui la soluzione e i relativi test.

### Sprint 1 — prima di persistenza e accodamento

Completato: le decisioni e le verifiche sono riportate in «Esito sprint 1» e
nel contratto di accodamento sopra. L'applicazione dei lock e delle verifiche
al servizio di voto sarà implementata nello sprint 3.

### Sprint 2 — prima di importazione e aggiornamenti

Le scelte su ordinamento, chiusura, Note di voto e documenti parziali o
malformati sono state risolte nello sprint 2 e sono descritte nel suo esito.

Coinvolgere nuovamente l'utente soltanto se un limite verificato del protocollo
richiede di modificare una decisione di prodotto concordata.

## Esito della revisione architetturale

Revisione completa del documento effettuata prima del commit iniziale.
Precisati il confine tra decisioni di prodotto e progettazione tecnica, il
rischio dell'accodamento dopo commit, la destinazione privata delle attività
di voto e la separazione tra cache del sondaggio e scelte personali. Questi
punti rientrano negli sprint previsti e non richiedono nuove decisioni di
prodotto. Questa revisione iniziale aveva riguardato soltanto il documento;
il successivo lavoro dello sprint 1 è descritto sotto.

## Esito sprint 1

### Protocollo e limiti

Fonti primarie verificate il 15 ottobre 2026:

- [Mastodon: Polls](https://docs.joinmastodon.org/spec/activitypub/#polls)
- [VoteSerializer](https://github.com/mastodon/mastodon/blob/main/app/serializers/activitypub/vote_serializer.rb)
- [NoteSerializer](https://github.com/mastodon/mastodon/blob/main/app/serializers/activitypub/note_serializer.rb)

Question è incorporata in Create/Update. `oneOf` indica scelta singola, `anyOf`
scelta multipla; le opzioni sono Note senza ID, con il testo in `name` e i
conteggi in `replies.totalItems`. Il voto è un Create/Note indirizzato all'autore
del sondaggio, con `name` identico all'opzione e `inReplyTo` uguale all'URI della
Question. Ogni scelta multipla genera un'attività distinta. Il serializer
Mastodon rappresenta la chiusura con un timestamp. Nessuna promessa che i
conteggi aggiornati includano già il voto locale.

`RemotePollDocument` normalizza soltanto metadati: non autorizza l'importazione
e non interpreta audience o identità dell'autore. Accetta 2–50 opzioni, fino a
1.000 caratteri Unicode per nome, distinti tramite confronto esatto. Sono limiti
di interoperabilità Openbook. Non tronca o rinomina risposte. Modalità ambigue,
nomi duplicati e dati malformati generano un errore esplicito che l'importatore
dovrà gestire nello sprint 2, preservando lo stato valido precedente. Valori
assenti/null restano sconosciuti, zero intero rimane zero. Timestamp normalizzati
nel fuso applicativo; nessun fallback a «ora» per date invalide.

### Persistenza e concorrenza

- `remote_polls`: UUID, un solo sondaggio per post; modalità, scadenza,
  chiusura booleana/temporale e votanti remoti nullable. Nessuna duplicazione
  del testo, autore o audience del post.
- `remote_poll_options`: UUID, posizione e testo esatto con conteggio nullable;
  unicità per sondaggio/posizione e sondaggio/hash SHA-256 del nome. L'hash
  evita che la collation MySQL confonda maiuscole, accenti o spazi finali.
- `remote_poll_votes`: UUID, sondaggio e Actor; vincolo univoco sulla coppia.
  Nessun legame tra completamento del voto e successo della consegna remota.
- `remote_poll_vote_choices`: UUID, voto, posizione e testo al momento della
  conferma; unicità per voto/posizione e voto/hash del nome. Nessuna FK verso
  l'opzione remota mutabile, per preservare lo snapshot se viene rimossa.

Cancellazioni fisiche di post/Actor rimuovono le relative righe per FK cascade.
La cancellazione logica seguirà i servizi esistenti nello sprint 2. Le relazioni
personali sono escluse dalla serializzazione automatica del sondaggio, e Actor
e scelte sono esclusi da quella del voto; questo integra i controlli di accesso
che saranno richiesti nei servizi e nella UI, non li sostituisce.

Nel servizio di voto e nella sincronizzazione delle opzioni usare lo stesso
ordine di lock: post, sondaggio, opzioni. Alla conferma confrontare la versione
delle opzioni presentate con quella corrente, evitando di reinterpretare una
vecchia selezione per posizione. Il vincolo sondaggio/Actor è l'ultima difesa
contro doppio invio; nel caso concorrente restituire il voto già confermato.
Il codice di questi servizi appartiene agli sprint 2 e 3.
Per gli aggiornamenti riusare l'UUID di un'opzione soltanto quando il nome
esatto coincide; il riordino cambia la posizione, la rinomina crea una nuova
identità. Il contratto della richiesta di voto dovrà includere la versione
presentata all'utente, senza affidarsi soltanto alla posizione numerica.

### Verifiche e stato dei percorsi

Fixture anonimizzate in `tests/Fixtures/Federation/Polls`, con provenienza e
varianti spiegate nel README. Test di normalizzazione, persistenza, unicità,
snapshot, cascade, scadenza con orologio controllato e transazioni della coda.
La suite MySQL opzionale crea e rimuove soltanto propri schemi casuali, esegue
tutte le migration e verifica rollback/reapplicazione della nuova migration.
I piani EXPLAIN delle query reali dei modelli usano gli indici univoci
post_id, sondaggio/posizione, sondaggio/Actor e voto/posizione, senza filesort,
su un campione di 100 sondaggi, 400 opzioni, 100 voti e 200 scelte.

Risultati della verifica dello sprint:

- Test mirati: 30 passati su SQLite (84 asserzioni) e 9 su MySQL isolato
  (57 asserzioni), inclusi rollback della migration, date oltre il 2038,
  vincoli e piani di esecuzione. MariaDB non eseguito in questa sessione.
- Pint e controllo whitespace del diff superati.
- Esecuzione completa durante lo sprint: 1.511 passati, 10 saltati e 2
  fallimenti preesistenti: `VisibilityFromAudienceTest` interroga `actors`
  senza predisporre il DB; `FeedTest::test_long_post_bodies_are_truncated_in_the_feed_with_a_read_more_control`
  attende il controllo di espansione che il rendering corrente non produce.
  Entrambi riprodotti anche in una copia del commit iniziale `6fa6cfb`, con
  lo stesso ambiente di test. Nessuna correzione estranea a questo sprint.

Il changelog rimane invariato: questo incremento interno non rende ancora
disponibile un comportamento nuovo agli utenti o agli operatori.

Il normalizzatore e i modelli sono pronti ma non ancora collegati all'inbox:
`Question` resta escluso dai tipi postabili fino allo sprint 2. Nessuna route,
azione di voto o nuova UI esposta; nessun dato dell'istanza locale modificato.


## Esito sprint 2

### Percorso condiviso e controlli

`Question` è un tipo postabile. `RemoteNoteUpserter` usa
`RemotePollSynchronizer` all'interno della propria transazione, mantenendo
l'ordine di lock post → sondaggio → opzioni. Questo collega inbox, fetch per
URI, outbox Person/Group, citazioni, post fissati, Announce e refresh esistenti.
Il fetch delle risposte riconosce anche la Question radice e sincronizza i
metadati senza introdurre timer o polling dedicati.

Il metodo condiviso restituisce null per Question malformate, bloccate,
con autore incompatibile, con URI diverso da quello richiesto, di risposta,
o che tentano di modificare post eliminati/locali. Le attività scartate non
mutano lo stato precedente e non attivano l'aggancio a community a valle.
Gli aggiornamenti validi di un sondaggio già in cache restano rilevanti anche
quando il suo autore non è più seguito. Audience e moderazione continuano a
usare le regole del post; nessuna nuova route o rappresentazione federata del
voto personale viene esposta.

Le Question incorporate da terzi in Announce, outbox Group e citazioni vengono
verificate tramite fetch del documento canonico prima dell'importazione; ID,
autore, audience e blocchi vengono controllati anche sul documento recuperato.
Le Note con nome e inReplyTo, prive di contenuto e allegati, sono trattate come
voti e scartate prima di diventare post, messaggi o commenti, incluso il caso
di un sondaggio sconosciuto. Note con contenuto/allegati seguono il flusso
ordinario delle risposte.

### Ordinamento e chiusura

Una migration aggiuntiva conserva `remote_updated_at`, `results_received_at` e
`source_item_id` sul sondaggio. L'ID inbox è un riferimento di ordinamento,
senza FK: la retention dell'inbox non deve cancellare o invalidare il sondaggio.

- Quando entrambi i documenti hanno una revisione esplicita `updated`, quella
  maggiore prevale; revisioni uguali usano l'ordine di osservazione.
- Per i documenti senza revisione, usare `received_at` originale dell'InboxItem,
  con ID originale come spareggio deterministico. Ripetere la lavorazione di
  una riga vecchia non le attribuisce un nuovo momento di osservazione.
- I fetch usano il momento locale di osservazione, anche quando vengono
  eseguiti per risolvere un URI contenuto in una vecchia attività inbox.
  A parità di secondo tra
  fetch e inbox, conservare il fetch quando manca un ID inbox confrontabile.
- Gli snapshot vecchi/duplicati vengono ignorati e non generano effetti a valle.
  Senza revisione remota non è possibile ricostruire l'ordine originale del
  server: una consegna nuova può contenere un documento remoto obsoleto non
  riconoscibile. Non mascherare questa limitazione inventando massimi dei voti.
- Un nuovo snapshot valido può ridurre i conteggi. Conteggi assenti/null
  diventano sconosciuti; opzioni complete valide sostituiscono quelle precedenti.
  Una scadenza omessa conserva quella nota; null esplicito può rimuoverla
  soltanto mentre il sondaggio è ancora aperto.
- Una chiusura già acquisita, esplicita o per scadenza, resta chiusa: documenti
  vecchi o successive estensioni non riaprono il voto. I risultati possono
  continuare ad aggiornarsi.

Le versioni di testo del post mantengono anche la protezione esistente basata
su `updated`/`published`: un documento privo di revisione non sostituisce un
post già aggiornato con una revisione remota successiva alla pubblicazione.

### Identità delle opzioni e query

La sincronizzazione conserva l'UUID quando il nome esatto coincide, anche se
cambia la posizione; una rinomina crea una nuova opzione e rimuove quella
precedente. Gli snapshot personali restano indipendenti. Per evitare collisioni
nei vincoli univoci durante gli scambi, le posizioni esistenti vengono spostate
in un intervallo temporaneo dentro la transazione, poi applicate le nuove.

Le letture ordinate usano l'indice sondaggio/posizione. La DELETE di rimozione
opera soltanto sulle opzioni del sondaggio e senza ORDER BY superfluo; il piano
MySQL usa uno degli indici composti con prefisso poll_id, senza scansione globale
né filesort. Il test MySQL esamina le query effettivamente emesse dal servizio,
oltre ai piani delle relazioni verificati nello sprint 1.

### Verifiche e disponibilità

`RemotePollImportTest` copre sequenze fixture, Update senza Create, replay,
versioni/tempi discordanti, risultati in calo o assenti, chiusura, riordino e
rinomina, conservazione del voto, autore contraffatto, post eliminati, blocchi,
audience follower, fetch per URI, outbox, Announce canonico, post fissati e
refresh del post/risposte. I test condivisi SQLite/MySQL verificano anche gli
scambi di posizione con i vincoli reali e il rollback della nuova migration.

Applicare le migration aggiuntive `2026_10_15_000001_add_observation_to_remote_polls`
e `2026_10_15_000002_simplify_remote_poll_timestamps` con `php artisan migrate`. I dati possono ora essere importati; l'interfaccia specifica e il
voto restano da implementare insieme negli sprint 3 e 4. Il comando selettivo
per recuperare lo storico era stato considerato per lo sprint 5 e poi escluso
dall’utente. Nessun replay del DB locale era stato eseguito nello sprint 2.


Verifiche finali dello sprint 2:

- 171 test mirati di normalizzazione, persistenza e percorsi federati passati
  (641 asserzioni), più i due casi aggiunti di outbox Group e citazione canonica.
- 11 test MySQL isolati passati (69 asserzioni), comprese le due migration,
  scambi delle opzioni e EXPLAIN delle query effettive; MariaDB non eseguito.
- Suite completa: 1.544 passati, 13 saltati, i soli due fallimenti preesistenti
  di `VisibilityFromAudienceTest` e del controllo di espansione in `FeedTest`.
  Dopo questa esecuzione, la precisazione sul tempo dei fetch è stata verificata
  nuovamente con i 171 test mirati; nessun ulteriore comportamento aggiunto.
- Pint e controllo whitespace superati. Documentazione federazione EN/IT e
  changelog aggiornati, con distinzione esplicita tra importazione e UI/voto.

La cache di configurazione locale è stata preservata: le esecuzioni dei test
hanno usato un percorso `APP_CONFIG_CACHE` temporaneo privo di cache, così la
suite usa SQLite e non la connessione configurata per l'istanza locale.


### Semplificazione concordata delle date

I modelli `RemotePoll` e `RemotePollOption` disabilitano i timestamp automatici:
`created_at`/`updated_at` non servono al dominio del sondaggio. La data originale
rimane nel `published_at` del post associato; voti personali e scelte conservano
le proprie date. `results_received_at` indica soltanto la ricezione dello stato
dei risultati, usata per l'ordinamento descritto sopra.

Una migration progressiva rinomina il precedente `observed_at` e rimuove i
quattro timestamp ridondanti da sondaggi e opzioni, anche su DB locali già
migrati negli sprint precedenti. Le migration già esistenti restano applicabili
in sequenza; il rollback ricrea le colonne locali nullable senza inventarne
il contenuto storico. Test condivisi SQLite/MySQL verificano la conservazione
della ricezione dei risultati, delle opzioni e delle date dei voti, più il
rollback/reapplicazione delle migration coinvolte.

La differenza preesistente fra `posts.published_at`/`created_at` e
`comments.created_at` è annotata in `analysis/TODO.md` per un lavoro separato;
la semantica attuale di post e commenti resta invariata.

Verifica della semplificazione: 57 test mirati SQLite passati (180 asserzioni)
e 12 test MySQL isolati passati (80 asserzioni), incluso l'aggiornamento da uno
schema locale precedente senza perdita dei risultati o delle date dei voti.
Pint e controllo whitespace superati. Nessuna migration eseguita sul DB locale.


## Sprint 3 — backend del voto implementato

`RemotePollVoter` autentica l'Actor locale attivo e verifica accesso al post,
origine, blocchi e disponibilità della firma. Risoluzione endpoint e controllo
DNS avvengono prima dei lock. La transazione segue l'ordine post → sondaggio →
opzioni, condiviso con l'importatore; ricontrolla accesso, chiusura, versione e
appartenenza delle scelte. Un voto già confermato restituisce lo snapshot
originale senza altri accodamenti. I vincoli univoci restano l'ultima difesa.

`optionsVersion()` comprende ID del sondaggio, modalità e ID/posizione/nome delle
opzioni, escludendo i conteggi remoti. La UI dello sprint 4 invierà questa versione
insieme agli ID selezionati. La scadenza viene comunque verificata sullo stato
corrente. Senza scadenza, un sondaggio aperto accetta il voto.

`ActivityDelivery::enqueueDirectInTransaction()` richiede `DatabaseQueue` sulla
stessa connessione e una transazione attiva. L'alias `local_delivery` punta alla
tabella `jobs` esistente del dominio, anche con driver globale sync o database
esterno. Job `beforeCommit()` rendono atomici voto, snapshot e accodamenti.
`openbook:deliver` consuma anche questo backend, senza duplicarlo quando coincide
con quello globale e dividendo il budget di esecuzione quando sono distinti.
Rigenerare la cache di configurazione dopo l'aggiornamento, se utilizzata.

`PollVoteSerializer` genera un Create/Note privato per scelta, con `name` esatto,
`inReplyTo` della Question e audience limitata all'autore. L'ID deriva dall'UUID
della scelta e resta identico nei retry. `DeliverActivityJob` riusa firma HTTP,
client SSRF e retry esistenti; prima dell'invio `PollVoteDeliveryGuard` controlla
esistenza dello snapshot, accesso, stato degli Actor e blocchi correnti.
Chiusura successiva al commit e consegna parziale lasciano il voto definitivo;
la conferma locale non dipende dall'esito remoto. Nessuna modifica ai conteggi.

Nessuna nuova migration, route o interfaccia in questo incremento. La retention
resta quella del post remoto: la cancellazione fisica rimuove anche gli snapshot
tramite le FK già approvate. Non viene introdotto un archivio permanente dei voti.

Le verifiche usano le fixture anonimizzate, coda database reale e HTTP simulato:
idempotenza, singolo/multiplo, opzioni cambiate, accesso, scadenza, blocchi,
rollback del secondo job, retry e consegna parziale. MySQL esegue gli stessi casi,
più due processi concorrenti e verifica degli indici sulle query di accesso al
post, opzioni, voto personale e scelte. Nessuna consegna verso server reali.


Verifiche finali dello sprint 3:

- 21 test mirati SQLite su voto e comando passati, confermati nella suite
  completa con il worker eseguito in un processo separato.
- 31 test MySQL passati (161 asserzioni), inclusi due processi concorrenti,
  rollback e piani EXPLAIN. Server temporaneo arrestato a fine verifica.
- Suite completa: 1.569 passati, 33 saltati, 2 fallimenti già presenti
  (`VisibilityFromAudienceTest`, tabella actors assente nel test unitario;
  `FeedTest`, aspettativa sul controllo Altro...). Nessuna nuova regressione.
- Il test che esegue davvero il worker usa un processo separato: la suite intera
  supera i 128 MB predefiniti del worker Laravel. Il limite operativo rimane
  invariato; il processo fresco riproduce l'esecuzione da cron.
- Pint e controllo whitespace passati; documentazione EN/IT, architettura e
  changelog aggiornati. Nessuna migration o modifica del DB applicativo locale.


## Sprint 4 — interfaccia del sondaggio

La partial `posts._poll` è inclusa nel corpo rivelato del post: feed, dettaglio,
profili e citazioni usano la stessa rappresentazione. Gli avvisi sui contenuti
racchiudono anche opzioni e risultati; post eliminati e sondaggi invisibili al
lettore vengono esclusi. Le citazioni mostrano i risultati in sola lettura.

La scelta singola usa radio, quella multipla checkbox, con fieldset/legend e
label native. Il testo prima del bottone chiarisce che la conferma è definitiva.
Il form autenticato `POST /posts/{post}/vota` invia gli UUID e la versione delle
opzioni al servizio dello sprint 3. Il controller resta sottile. Gli errori HTML
sono associati al singolo post tramite un error bag dedicato; JSON restituisce
la partial personale con `Cache-Control: private, no-store`. Il JS intercetta
solo l'invio, sostituisce la card del sondaggio confermata e annuncia l'esito con
una regione live. Il fallback HTML e il retry del POST riusano l'idempotenza.
Nessun timer o polling dei risultati.

`Post::CARD_RELATIONS` precarica sondaggi/opzioni e endpoint per la disponibilità
del voto. `annotateViewerState` carica in batch visibilità e solo i voti dell'Actor
corrente con le relative scelte, comprese le citazioni. Le relazioni transitorie
`viewerVote` e `viewerCanSee` sono nascoste dalla serializzazione del modello e
vengono azzerate cambiando lettore. Il fallback della partial copre i percorsi
che rendono una card singola senza annotazione preventiva.

«La tua risposta» usa un confronto esatto del nome con lo snapshot personale;
le risposte rinominate o rimosse compaiono separatamente con il testo originale.
L'accodamento confermato mostra «Voto registrato», anche prima della consegna.
Il render preserva conteggi e totali remoti. Le percentuali richiedono votanti
noti positivi e conteggi non superiori al totale; nei sondaggi singoli richiedono
anche tutti i conteggi e somma coerente. Per il caso locale 113+23 contro 135
si mostrano i numeri ricevuti senza barre/percentuali. Dati assenti usano
«Risultati in aggiornamento»; chiusura senza data usa «Sondaggio concluso».

Disponibilità locale: nessuna migration aggiuntiva. Rigenerare le route in cache
con `php artisan route:cache`, poi aprire `/posts/{remote_polls.post_id}`. Il
campione importato consente la verifica dei sondaggi conclusi. Il voto aperto
è stato provato su fixture in SQLite isolato, HTTP simulato e coda database reale,
tramite browser: selezione da tastiera, conferma AJAX, persistenza al reload,
scelta multipla e conteggi invariati. Layout verificato a 390 px senza overflow
orizzontale, oltre alla card desktop con il primo sondaggio reale importato.
L'apertura del post reale ha attivato il normale refresh: sono arrivati risultati
e commenti più recenti, senza modifiche manuali a sondaggi o scadenze.


Verifiche finali dello sprint 4:

- 11 test HTTP/Blade nuovi passati (89 asserzioni, ricontrollati prima del commit); 87 test mirati su sondaggi,
  importazione, citazioni e azioni delle card passati (462 asserzioni).
- EXPLAIN MySQL sulle query effettive in batch: accesso ai post, voto personale
  filtrato per Actor e scelte usano indici, senza scansioni complete delle tabelle
  principali nel campione; test dei piani passato (22 asserzioni). MariaDB non
  eseguito in questa sessione.
- Suite completa: 1.580 passati, 33 saltati e i due fallimenti preesistenti
  `VisibilityFromAudienceTest` e `FeedTest` (7.006 asserzioni).
- Pint, sintassi JavaScript e whitespace passati. Prova browser su sondaggio
  reale concluso e fixture aperte single/multiple con HTTP simulato completata.
- Lo sprint 3 è congelato nel commit `e39b5fe`; lo sprint 4 è stato verificato
  localmente anche dall’utente e approvato per il commit. Nessun voto inviato
  verso utenti remoti reali.


## Revisione conclusiva del ramo

Perimetro confermato: niente filtro o anteprima inbox. L'importazione storica
è già disponibile col comando esistente. La review comprende tutti gli sprint,
non soltanto l'interfaccia; mantiene fuori refactor generali estranei all'issue.

Rilievi corretti e riprodotti prima delle modifiche:

- `RemoteRepliesFetcher` aveva spostato il controllo del tipo prima del refresh
  dei contatori, interrompendo l'aggiornamento delle reazioni dei normali Article.
  Ripristinato il comportamento preesistente, mantenendo prima la validazione dei
  Question. Eliminato il riconoscitore di tipi duplicato e riusato
  `RemotePostObject::hasType`, coerente con inbox e tipi JSON-LD completi.
- L'apertura del post validava la visibilità prima del fetch, ma la Question
  poteva aggiornare insieme testo, avviso e audience. `PostController` ora rilegge
  e autorizza il post corrente dopo il refresh, prima di renderizzare contenuto
  e commenti. Test distinti per ospite e utente autenticato escluso dal pubblico,
  oltre al render immediato del nuovo testo e del nuovo avviso.
- Citazioni, outbox Group e Announce dell'inbox recuperavano una seconda volta
  Question già ottenute dal documento canonico. Il flusso distingue oggetti
  incorporati da documenti recuperati; conserva verifica dell'ID, autore,
  audience e blocchi, e usa il tempo del fetch per l'ordinamento. I test contano
  le richieste reali simulate e respingono ID sostituiti.

Pulizia e coerenza:

- `RemotePollInteractionPolicy` riunisce le condizioni di account, origine e
  moderazione condivise da disponibilità UI, conferma e consegna. Non ingloba
  scadenza o avanzamento della consegna: un voto confermato resta tale anche
  quando il sondaggio si chiude dopo il commit. Visibilità e stato corrente
  vengono ricontrollati nelle fasi che lo richiedono.
- `Actor::deliveryInboxUrl()` centralizza la scelta dell'endpoint già in cache.
  La risoluzione di rete resta in `ActivityDelivery`, fuori dalla transazione
  del voto. Il servizio di importazione mantiene il nome storico
  `RemoteNoteUpserter`, già condiviso dagli altri tipi postabili: rinominarlo
  amplierebbe inutilmente il diff dell'issue.
- Corretto il nome della variabile dello snapshot (`choice`), riallineata la
  documentazione del fetcher e unificate le note di rilascio in una sola voce
  che descrive la funzionalità finale.
- Le tre migration restano separate: sono già state applicate durante le prove
  locali; preservarle consente aggiornamento incrementale e installazione nuova
  senza interventi manuali sul DB. I rollback e la preservazione dei dati sono
  coperti sia su SQLite sia su MySQL.

Verifica preliminare della review: sette nuovi casi riproducevano i rilievi prima
delle correzioni. Dopo le correzioni, 113 test mirati passati (432 asserzioni),
più 31 test MySQL isolati passati (168 asserzioni), inclusi concorrenza e EXPLAIN.
Due ulteriori casi coprono ID sostituito e tipo JSON-LD completo nel refresh.


Esito finale della review:

- 56 test mirati di importazione, refresh e UI passati (236 asserzioni), inclusi
  tutti i nuovi casi di regressione; 31 test MySQL passati (168 asserzioni).
- Suite completa: 1.589 passati, 33 saltati e i due fallimenti già presenti
  `VisibilityFromAudienceTest` e `FeedTest` (7.031 asserzioni). I fallimenti di
  base sono separati dall'issue; la review non introduce altre regressioni.
- Pint, sintassi JavaScript e controllo whitespace passati. Server MySQL
  temporaneo arrestato; nessuna migration o modifica manuale del DB locale.
- Review del diff complessivo e di quello correttivo completata; nessun rilievo
  bloccante resta aperto nel perimetro dei sondaggi. La consegna verso un server
  remoto reale resta una verifica di interoperabilità ancora da effettuare:
  invio, retry e failure sono stati esercitati con HTTP simulato.
- Il ramo conserva i commit incrementali già approvati e si chiude con un commit
  di correzione e pulizia; nessuna riscrittura della storia applicata in locale.
