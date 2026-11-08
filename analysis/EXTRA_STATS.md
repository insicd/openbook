# Statistiche persistenti degli hashtag

Data: 8 novembre 2026 · ramo `extra_stats`.

Stato: base implementata e committata; estensione eventi/ticker implementata,
verificata e revisionata. Commit conclusivo autorizzato dall'utente.
Questo documento riguarda i bucket degli hashtag. I conteggi di ricezione per
relay già implementati sul ramo sono un intervento distinto.

## 1. Problema e obiettivi

### Estensione autorizzata: eventi e miniticker

L'utente ha autorizzato anche i contributi degli eventi e un miniticker a 7
giorni per riga in `/tendenze`, senza commit prima della sua review. I bucket
sommano post ed eventi pubblici, usando la pubblicazione originale, non la
data di svolgimento dell'evento. Gli eventi ammessi sono scheduled, tentative
e postponed, con gli stessi filtri autore/moderazione già usati da Stats.
Annullamento, modifica e Delete correggono il contributo entro la finestra
concordata; riconsegne e pulizia fisica non aggiungono/sottraggono contributi.
La stessa più breve retention configurata dei post limita entrambe le entità.

Il bootstrap in migrazione e il comando di reset sommano entrambe le entità;
su staging già migrato si userà il ricalcolo completo in manutenzione.
Non si aggiungono migrazioni di ricalcolo automatico alle istanze già attive.

I ticker leggono sette giorni UTC, oggi parziale incluso, con una query per
tutti i tag visualizzati e piccoli SVG senza istanze Chart.js per riga.
La classifica corrente conserva il contratto post/eventi pubblici e non
elencati; il trend statistico è pubblico, approssimativo e indipendente dalla
pulizia. La descrizione della pagina distingue i due significati. Servono
EXPLAIN MySQL per entrambe le sorgenti del bootstrap e per la lettura batch,
test dei flussi eventi e verifica responsive della lista.

La pagina di un hashtag potrebbe mostrare un piccolo grafico dell'andamento
negli ultimi 30 giorni, sotto il titolo e prima dei contenuti. Calcolare ogni
volta l'andamento dai post può diventare costoso per tag molto diffusi.

Anche il grafico degli hashtag in Stats, che oggi mostra una classifica e un
andamento mensile negli ultimi 24 mesi, esegue aggregazioni onerose su post e
associazioni ai tag. In produzione la query impiega decine di secondi.

Inoltre, le statistiche attuali descrivono il corpus ancora conservato:
la pulizia dei post remoti elimina le associazioni ai tag e modifica lo storico.
Vogliamo conservare un andamento statistico indipendente dalla retention dei
contenuti, senza conservare un archivio parallelo dei post.

Riferimenti nel codice corrente:

- `HashtagController::show`: pagina del tag, post ed eventi associati.
- `StatsQuery::hashtags` e `StatsDataset::hashtags`: classifica e serie mensili.
- `PostComposer`: creazione locale e aggiornamento dei tag dei post.
- `RemoteNoteUpserter`: inserimento/aggiornamento dei post remoti; gli hashtag
  della Note sono sincronizzati anche negli aggiornamenti accettati.
- `InboxActivityProcessor::handleDelete` e `LocalContentDeleter`: cancellazioni
  federate e locali.
- `RemotePostRetention`: pulizia fisica, distinta dalle cancellazioni reali.
- `DeliveryStats`: precedente per statistiche best effort dopo il commit,
  con connessione di scrittura separata.

## 2. Semantica concordata

Le statistiche sono approssimative e soft-fail. Un errore statistico non deve
impedire, annullare o rallentare con tentativi ripetuti un'operazione sul contenuto.
Sono accettati conteggi mancanti e disallineamenti occasionali rispetto al corpus.
Non si richiede una riconciliazione perfetta.

Il significato è: **contenuti osservati dall'istanza, correggibili per una
finestra limitata e poi consolidati**. Non rappresentano l'intero Fediverso,
né il numero esatto dei contenuti ancora esistenti presso le rispettive origini.

Il periodo del contributo si basa su `published_at`, cioè sulla pubblicazione
originale disponibile e normalizzata dal Core, non su `created_at`, che per i
remoti indica l'inserimento della copia locale. I periodi statistici sono UTC.

Il termine «readonly» riguarda esclusivamente le regole del conteggio:
nessun flag readonly sui record, nessun vincolo di sola lettura nel database.

## 3. Due tabelle aggregate, aggiornate direttamente

Le due tabelle statistiche implementate sono:

| Tabella | Chiave logica | Contenuto | Conservazione |
| --- | --- | --- | --- |
| `stats_hashtag_daily_buckets` | hashtag + giorno UTC | Conteggio di post ed eventi del tag pubblicati nel giorno | Ultimi 30 giorni di calendario, oggi incluso |
| `stats_hashtag_monthly_buckets` | hashtag + primo giorno del mese UTC | Conteggio di post ed eventi del tag pubblicati nel mese | Ultimi 24 mesi di calendario, mese corrente incluso |

Si creano soltanto le coppie tag/periodo effettivamente necessarie, non una
griglia preventiva di tutti gli hashtag e tutti i periodi. Un contenuto con più
occorrenze dello stesso tag contribuisce una sola volta a quel tag.

Ogni contributo aggiorna direttamente i bucket giornaliero e mensile ancora
conservabili. Non si prevede un processo di consolidamento giornaliero → mensile.
Un giorno può appartenere alla finestra mensile ma essere già fuori da quella
giornaliera: non si crea o ricrea il bucket giornaliero scaduto.

Il cron di pulizia esistente elimina soltanto i bucket fuori finestra:

- giornalieri con giorno precedente a oggi UTC meno 29 giorni;
- mensili con mese precedente al primo giorno del mese corrente meno 23 mesi.

I bucket non dipendono dalla presenza dei post o delle loro associazioni:
la cancellazione fisica di un post non deve eliminarli in cascata.
Anche l'identità del tag necessaria a leggere lo storico va conservata.

Gli indici devono supportare il dettaglio per tag/periodo e la scansione per
periodo di Stats e della pulizia. Verranno verificati con EXPLAIN sulle query
effettive, non soltanto sulla base dell'esistenza degli indici.

## 4. Finestra modificabile e assenza di ricevute

**Non si introducono ricevute statistiche per contenuto**, tabelle di contributi
post/tag o un altro registro persistente per deduplicare i contenuti già rimossi.

La finestra modificabile deriva dalla più breve delle due retention configurate
per post remoti pertinenti e non pertinenti. Le finestre disattivate vanno
escluse dal minimo: nel Core il valore zero significa «non cancellare».
Con entrambe disattivate, i contributi sono correggibili entro i 24 mesi conservati.

Il controllo dell'età statistica usa la pubblicazione originale. La retention
locale attuale usa invece `created_at`: un vecchio post recuperato oggi può
restare leggibile nella cache, ma non per questo torna conteggiabile.

| Operazione | Regola |
| --- | --- |
| Primo inserimento entro la finestra modificabile | Aggiunge una volta il contributo ai bucket pertinenti ancora conservabili. |
| Nuova ricezione di un post già presente | Non è una nuova creazione e non incrementa di nuovo il conteggio. |
| Modifica entro la finestra modificabile | Adegua il contributo noto ai cambiamenti di tag e di ammissibilità. |
| Cancellazione reale entro la finestra modificabile | Sottrae il contributo noto una sola volta, prima di perdere i dati necessari. |
| Inserimento, recupero, modifica o cancellazione oltre la finestra modificabile | Non cambia i conteggi ordinari. |
| Pulizia locale per retention | Non cambia mai i conteggi. |

Si consolidano i contributi dei post vecchi, non necessariamente un intero
bucket: lo stesso mese può contenere contributi consolidati e altri ancora
modificabili.

Per le correzioni entro finestra si usano le informazioni del post ancora
presente. Le cancellazioni ripetute non devono ripetere il decremento e i
contatori non devono diventare negativi. Se un incremento era fallito, una
successiva correzione può comunque produrre un conteggio mancante: è un limite
accettato del modello senza ricevute.

### Cambiamenti della retention: approssimazione accettata

**Non si introduce una soglia persistente di consolidamento, né una watermark
monotona o un meccanismo per impedire la riapertura della finestra.**

Si usano le impostazioni correnti. Se l'amministratore modifica la retention,
può rendere nuovamente conteggiabili contenuti già eliminati e poi recuperati,
oppure cambiare quali contributi sono correggibili. I conseguenti disallineamenti
statistici sono esplicitamente accettati dall'utente.

Questa scelta sostituisce la precedente proposta di una soglia globale
persistente: non va reintrodotta implicitamente durante l'implementazione.

## 5. Scritture e integrazione

Preferire un servizio statistico condiviso, invocato dai flussi applicativi
appropriati, evitando observer generici che confondano pulizia e cancellazione.
Nessun Redis, servizio esterno, worker permanente o coda obbligatoria.

La scrittura statistica avviene dopo il commit del contenuto e fallisce senza
compromettere l'operazione principale. Gli aggiornamenti dei due bucket
applicabili allo stesso contributo vanno trattati insieme in una transazione
statistica, con incrementi/decrementi atomici per non perdere aggiornamenti
concorrenti. Non è richiesta atomicità distribuita con la transazione del post.

Occorre coprire creazioni locali e remote e gli altri percorsi effettivi di
pubblicazione, compresi importazioni e finalizzazione dei video, riusando i
servizi esistenti. Una riconsegna, un Announce o un Update di un post già noto
non equivale a una nuova creazione.

Il perimetro iniziale riguardava i post con hashtag; l'estensione autorizzata
include anche gli eventi. I bucket e Stats sommano entrambe le entità ammesse.

## 6. Ammissibilità e cambiamento di significato rispetto a Stats

Il conteggio deve escludere conversazioni e contenuti privati. Per il grafico
condiviso si assume come base il contenuto pubblico, coerentemente con Stats.

La query Stats attuale applica anche lo stato degli autori, la sospensione
remota, i domini bloccati e, se configurati, i filtri sui content warning e tag
forzati. Un aggregato storico senza ricevute non può reinterpretare tutti
questi filtri retroattivamente.

Scelta implementativa: gli stessi filtri attuali di Stats (pubblico, autore
attivo/non sospeso, domini, community e content warning) si applicano quando
si acquisisce una fotografia del contributo prima/dopo una mutazione del post.
Non si applicano ricalcoli retroattivi generali al cambio di moderazione,
sospensione o privacy della community. La cancellazione dell'account rimuove i
contributi ancora modificabili prima di perdere tag e identità dell'autore.
Le correzioni dopo un filtro amministrativo cambiato possono essere incomplete:
è accettato, come gli altri disallineamenti best effort.

## 7. Letture previste

- Pagina del tag: circa 30 bucket giornalieri, con zeri aggiunti alla serie in
  presentazione per i giorni mancanti. Grafico leggero sotto il titolo.
- Stats: aggregazione e classifiche sugli ultimi 24 mesi dei bucket mensili,
  senza join ai post e a `post_hashtags`. Conservare le convenzioni della
  rappresentazione del grafico oppure documentarne esplicitamente le variazioni.
- Cache: può restare utile per il ranking globale di tutti i tag; la lettura
  di un singolo tag è già limitata a poche righe. Nessuna promessa di tempi
  assoluti senza verifiche sul piano e su dati rappresentativi.

I due interventi fanno entrambi parte del perimetro: aggiungere il grafico al
dettaglio dell'hashtag e sostituire la query del grafico hashtag in Stats con
la lettura dei bucket mensili.

### Libreria e presentazione del grafico

Scelta concordata: riusare **Chart.js**, già distribuita localmente in
`public/assets/vendor/chartjs/chart.umd.min.js` e usata dalla pagina Stats.
Non introdurre una seconda libreria o dipendenze da CDN.

Il dettaglio del tag carica la libreria quando presenta il grafico e usa una
configurazione dedicata alla serie giornaliera. La presentazione concordata
è una fascia tenue arrotondata nell'intestazione: «Andamento / Ultimi 30 giorni»
nella metà sinistra e trend nella metà destra, alto 56 px. La linea non usa
spline, ha una leggera sfumatura sottostante e un punto finale. Niente assi,
tooltip o tabella; senza JavaScript la fascia resta nascosta. Sono riusati
i colori del tema e la libreria locale, senza caricare l'intero comportamento
della pagina Stats.

La serie del singolo tag viene letta durante il rendering HTML con una query
limitata ai 30 giorni e alla chiave del tag. Non occorre un endpoint aggiuntivo.

## 8. Misure comunicate dalla produzione

Valori forniti dall'utente l'8 novembre 2026. Le query misurano i dati ancora
presenti e applicano i filtri pubblici principali, non tutti quelli configurabili
di moderazione. Il giorno e il mese correnti sono parziali.

| Periodo | Coppie tag/periodo | Post con tag | Associazioni post/tag | Media tag per post con tag |
| --- | ---: | ---: | ---: | ---: |
| 10 ottobre–8 novembre 2026, giornaliero | 250.574 | 153.666 | 649.879 | 4,23 |
| Dicembre 2024–novembre 2026, mensile | 289.510 | 442.284 | 1.906.248 | 4,31 |

Tempi delle query di dimensionamento: **33 secondi** per i 30 giorni,
**101 secondi** per i 24 mesi. Comprendono diversi COUNT DISTINCT; non sono
misure della futura lettura dei bucket o della query di un singolo tag.

Dimensione iniziale osservabile: circa **540.084 bucket** complessivi.
La tabella hashtag contiene circa **191.000 righe**, ma questo numero non va
moltiplicato automaticamente per tutti i periodi: si registrano solo gli usi.

Lo storico recente è molto più denso: quasi il 95% dei post conteggiati nei
24 mesi è concentrato da agosto a novembre 2026. Ottobre contiene 112.323
coppie tag/mese; ipotizzando una densità simile per 24 mesi si arriverebbe a
circa **2,7 milioni di bucket mensili**, oltre ai giornalieri.

Nel periodo di 30 giorni: circa 5.122 post con tag/giorno e 21.663 associazioni
post/tag/giorno. Aggiornare entrambe le granularità comporterebbe circa
43.300 aggiornamenti di bucket/giorno, mediamente 0,5 al secondo. La media
non descrive i picchi di ricezione/importazione e non include tutte le altre
operazioni statistiche.

Le proiezioni non recuperano i post già rimossi dalla retention e non sono una
previsione garantita della crescita futura. L'assenza delle ricevute elimina
invece la componente di spazio proporzionale ai singoli post conteggiati.

## 9. Popolamento iniziale e ricalcolo opzionale

Scelta successiva esplicita dell'utente: **la migrazione crea e popola le due
tabelle**, leggendo i post ancora presenti nei 24 mesi. Sostituisce la proposta
precedente di non eseguire il bootstrap in migrazione. Non si può ricostruire
lo storico dei post già rimossi.

Il popolamento lavora per mese, con rimpiazzo atomico del periodo e un marker
in `stats_collections` per i mesi completati. I marker sono solo progressi del
bootstrap, non ricevute per contenuto né soglie di consolidamento. Un tentativo
interrotto può riprendere senza incrementare due volte i mesi completati.
La connessione primaria della migrazione vede anche il DDL transazionale SQLite.

Il comando `openbook:backfill-hashtag-stats` riprende i periodi non completati;
`--month=YYYY-MM` limita il periodo. L'opzione esplicita `--reset` svuota entrambe
le tabelle e i marker e ricostruisce dai contenuti rimasti. Il reset perde lo
storico dei post già eliminati dalla retention e non è una riconciliazione
perfetta con i vecchi bucket.

Per migrazione e ricalcolo usare la normale finestra di deploy/manutenzione,
con ricezione/pubblicazione e cron sospesi. Il comando richiede maintenance mode;
la sospensione dei processi esterni resta operativa. Non introdurre un protocollo
di fusione tra backfill e scritture concorrenti. In produzione il tempo può
riflettere le query storiche misurate (decine di secondi/minuti).

Le due tabelle sono comprese nella manutenzione ordinaria del database: conteggi,
dimensioni e righe scadute nella pagina admin, pulizia per singola tabella o
globale dal Web, e pulizia dal cron esistente. Le eliminazioni procedono a
piccoli blocchi e con budget temporale; eventuali righe residue, comunque escluse
dalle letture per periodo, vengono eliminate nelle esecuzioni successive.

## 10. Perimetro, verifiche e stima

Intervento di complessità media e circoscritta, semplificato rispetto alla
versione con ricevute. Non richiede un archivio parallelo dei contenuti né
infrastruttura aggiuntiva. Il tempo macchina del bootstrap in produzione resta
distinto dal lavoro di sviluppo e dipende dai dati conservati.

Stadi implementati dopo autorizzazione:

1. Scelte concordate, schema e migrazioni.
2. Servizio statistico e agganci ai flussi di inserimento/correzione/cancellazione.
3. Pulizia dei periodi scaduti e popolamento iniziale.
4. Sostituzione della lettura Stats e grafico nella pagina del tag.
5. Test mirati, EXPLAIN MySQL/MariaDB, documentazione e review completa.

Verifiche essenziali: confini UTC dei giorni/mesi, riconsegne e Update senza
nuovi conteggi, tag ripetuti, cancellazioni ripetute, mancato incremento seguito
da Delete, importazioni tardive, pulizia senza decrementi, cambi di retention
con approssimazione accettata, scadenza indipendente dei due bucket, rollback e
soft-fail, concorrenza sui contatori, filtri di visibilità e bootstrap ripetuto.

Sono escluse per decisione esplicita: ricevute per contenuto, watermark
persistente, riconciliazione perfetta e consolidamento periodico giornaliero →
mensile. Implementazione e, dopo la verifica della UI, commit autorizzati dall'utente.

### Esito delle verifiche dell'implementazione

- Suite completa dopo la review finale: 2.451 test, 19.225 asserzioni,
  133 skip; nessun errore.
- Test MySQL opt-in su schema temporaneo: 1 test, 21 asserzioni. Esercitati
  192.000 bucket sintetici, 5.000 post, popolamento e ripetizione, contatori,
  eliminazioni e ciclo down/up della migrazione con dati già presenti.
- EXPLAIN delle query effettive: dettaglio giornaliero sulla PRIMARY; ranking
  sull'indice mensile per periodo; pulizia DELETE ordinata e limitata in range
  sugli indici per data; bootstrap con indice visibilità/stato/pubblicazione
  dei post e PRIMARY dell'associazione post/tag.
- Browser con Chart.js reale: desktop, tablet e mobile, 30 punti, cambio tema,
  assenza di overflow. Dopo la revisione UI il trend è compatto e integrato
  nell’intestazione, senza assi, tooltip, legenda o tabella; senza JavaScript
  rimane nascosto.
- Pint e controllo del diff superati. Review corretti i confini inclusivi
  dell'istante corrente, la normalizzazione UTC del bootstrap e la duplicazione
  della pulizia nel cron; i filtri statistici sono condivisi. La review finale
  ha corretto anche l'inclusione del mese corrente esattamente alla mezzanotte
  UTC del primo giorno, mantenendo sempre 24 mesi nella serie.

Non sono state applicate migrazioni ai dati dell'istanza locale o di produzione.
Non è stata eseguita una prova dedicata su MariaDB né un benchmark sulla
produzione: i piani MySQL e i dati sintetici non garantiscono tempi assoluti.

## 11. Verifiche dell'estensione eventi e ticker

- Suite mirata eventi/federazione/statistiche/cache: 132 test, 1.169 asserzioni.
- Suite completa: 2.457 test, 19.273 asserzioni, 133 skip; nessun errore.
- MySQL su schema isolato: 192.000 bucket, 5.000 post e 5.000 eventi sintetici;
  controllati lettura batch di 100 tag, entrambe le sorgenti UNION ALL del
  bootstrap, reset, ripresa e migrazione down/up con somma post/eventi.
  EXPLAIN usa gli indici per pubblicazione dei post e `events_trending_idx`,
  con PRIMARY sui pivot; la query dei ticker usa gli indici già esistenti.
- Un test di pagina conferma una sola query ai bucket per tutte le righe;
  il lettore batch restituisce sette giorni per 100 tag, con zeri per i mancanti.
- Browser: visitatore e utente autenticato, Segui/Smetti di seguire alternati,
  hashtag lunghi, larghezze 320/375/768/1280 px, posizioni orizzontali uguali,
  assenza di sovrapposizioni/overflow, colori CSS personalizzati e SVG senza JS.
  Verificato anche il dettaglio hashtag dopo il riuso del colore di sfondo.
- Pint e controllo del diff superati. Nessuna migrazione o ricalcolo eseguito
  sull'istanza locale o sullo staging.

Il TODO per queste due attività è stato assorbito dall'implementazione.
Per lo staging già popolato con soli post, il reset completo documentato
in `docs/configuration.it.md` ricostruirà i bucket da post ed eventi conservati;
la semplice ripresa dei mesi già completati non aggiungerebbe gli eventi.

Review conclusiva: corretta la sfumatura Chart.js per colori CSS RGB, HSL,
nominali ed esadecimali brevi, usando il parser già disponibile in Chart.js.
Verifica browser dedicata superata per tutti i formati, oltre ai layout del
dettaglio e dei ticker. Sostituito «utilizzi» con «contenuti», con etichette
naturali inglesi e finlandesi. Verifica rapida finale: 54 test mirati,
698 asserzioni, Pint e controllo del diff superati; la suite completa e la
prova MySQL dell'estensione erano già passate come riportato sopra.
