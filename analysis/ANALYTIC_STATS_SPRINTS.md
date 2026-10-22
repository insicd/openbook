# Stats — piano di implementazione

Stato: sprint 1–3 approvati; grafico sprint 4 scartato; revisioni top 10 approvate; sprint 5 autorizzato; sprint 6–9 non iniziati.
Data: 22 ottobre 2026. Ramo richiesto: `stats`.
Analisi e SQL: [ANALYTIC_STATS.md](ANALYTIC_STATS.md).
Misure iniziali: [ANALYTIC_STATS_EXPLAIN.md](ANALYTIC_STATS_EXPLAIN.md).

## Sequenza e criteri comuni

1. **Sprint 1–6:** tutte le query già possibili, senza cache dei risultati,
   consegnate insieme ai rispettivi grafici nella pagina Stats.
2. **Sprint 7–8:** persistenza e raccolta dei dati mancanti di provenienza,
   con grafici funzionanti nello stesso sprint. I bucket sono la fonte
   durevole di una nuova metrica, non una cache del corpus corrente.
3. **Sprint 9:** cache dei dataset, con tempi coerenti con la granularità e
   la modificabilità delle metriche, mai inferiori a cinque minuti.

Aggiornamento richiesto dall’utente: eseguire PHPUnit soltanto nella verifica
finale, mantenendo intanto i test mirati scritti e i controlli di sintassi,
query e browser per ogni sprint. Eventuali problemi osservati vanno risolti
o riportati. Dopo lo sprint 1 attendere l’OK esplicito dell’utente prima di
iniziare lo sprint 2. Ogni sprint lascia qualcosa di consultabile: non
sono previsti sprint di solo backend seguiti da una fase frontend separata.

I primi sprint implementano davvero gli aggregati senza `Cache::remember`,
precalcoli persistenti, query memorizzate tra richieste o cache HTTP dei
dataset. La cache delle risorse statiche e i meccanismi già esistenti di
sessione/configurazione restano indipendenti dalle statistiche. Condividere
un risultato all'interno della stessa richiesta è possibile, senza anticipare
la cache dei dataset dell'ultima fase.

In tutte le fasi:

- pagina e JSON autenticati per utenti ordinari; nessun privilegio staff;
- dataset comune limitato ai contenuti pubblici ammissibili, non costruito
  sulla Home personale del visitatore; filtri coerenti tra dataset e grafico;
- controller sottili, query in `Application/Queries`, servizio di dataset
  responsabile di unità, periodo, copertura, denominatori e data di generazione;
- solo periodi e filtri ammessi esplicitamente, serie limitate e throttle
  ragionevole degli endpoint; niente SQL o intervalli arbitrari dal browser;
- nessuna aggregata Stats durante il rendering HTML; caricamento alla
  visibilità, massimo due richieste simultanee e nessun polling automatico;
- grafici coerenti col CSS Openbook, riepiloghi accessibili, stato vuoto/errore,
  retry locale e gestione della sessione scaduta;
- benchmark MySQL dell'SQL realmente generato e di tutti i rami UNION ALL,
  oltre ai test SQLite; niente indici nuovi basati soltanto sul loro nome;
- nessuna migrazione di tabelle metriche per le query del primo giro;
- changelog per le funzionalità introdotte, guide inglesi/italiane aggiornate
  quando cambia il comportamento. Questa pianificazione non è una release note.

La fase senza cache è una scelta di implementazione e misurazione, non una
promessa di sostenere qualsiasi numero di visitatori: mantenere filtri limitati,
accesso autenticato e caricamento progressivo fino alla fase finale.

## Sprint 1 — Pagina Stats, ritmo orario e lingue

**Backend:** route `stats.index` e route JSON autenticate, con contratto di
risposta comune. Implementare Q13 (360 ore complete UTC, estremo superiore
all'inizio dell'ora corrente) e Q05 (post/eventi distinguibili). Applicare
policy di moderazione effettive e filtro conservativo degli autori descritti
nell'analisi. Nessuna cache dei risultati.

**Frontend:** link «Stats» nei footer sinistro e generale per gli autenticati,
senza nuova voce del menu; layout esistente con entrambe le colonne laterali.
Chart.js locale con versione fissata e licenza conservata, codice caricato
solo qui, adattatore dei colori CSS, card, coda fetch, IntersectionObserver e
stati comuni. Linea/area per il ritmo e barre delle lingue senza tabelle o selettore post/eventi.
Sommare post ed eventi per lingua ed escludere i dati senza lingua dichiarata
anche dai totali mostrati. Usare i codici lingua completi normalizzati;
non sommare silenziosamente varianti regionali in una lingua base.

Definire qui il contratto di copertura: gli zeri del corpus attualmente
conservato significano «nessun contenuto conservato che soddisfa i filtri»,
non «nessuna pubblicazione avvenuta». Se si mostra invece una serie di flusso
osservato, gli intervalli non coperti sono null, non zero. MIN/MAX delle date
dei contenuti non certificano copertura: non usarli come inizio/fine certa
della raccolta. Le 360 ore sono 360 bucket anche quando alcuni sono null.

**Verifica e uscita:** login e accesso degli utenti ordinari, dati privati
esclusi, ora legale e confini UTC, corpus vuoto, 360 bucket, dati mancanti,
nessun fetch lontano dalla viewport e nessun incremento delle query Stats
nel rendering HTML. Una seconda richiesta JSON dopo una modifica al dato
deve vedere il cambiamento senza attendere un TTL. Provare mobile e desktop
1.024–1.280 px, CSS personalizzato, movimento ridotto e sessione scaduta.
Il grafico e i riepiloghi usano lo stesso dataset e conteggi esatti.

Risultato: prima pagina usabile con due grafici reali, senza cache. Decidere
su queste prove se serve una variante della stessa shell senza colonna destra;
se sì, consegnarla qui senza duplicare l'intero layout.

### Esito della prima implementazione — 22 ottobre 2026

- Pagina `/stats` e JSON `/stats/ritmo`, `/stats/lingue`, solo autenticati;
  utenti ordinari ammessi, nessun privilegio staff.
- Chart.js 4.5.1 locale, CSS della pagina separato con variabili del tema,
  linea oraria, barre lingue, filtro post/eventi e tabelle accessibili.
- Caricamento alla visibilità, massimo due richieste, timeout e retry locali;
  header JSON `private, no-store`, nessuna cache dei risultati.
- Verificato il fuso: connessione MySQL dedicata UTC sullo stesso database,
  senza modificare la sessione principale; conversione dell’orologio
  applicativo SQLite prevista e coperta dai test da eseguire alla fine.
- 360 bucket orari UTC verificati; nel campione 819 post e 20.160 utilizzi
  lingua tra post ed eventi. Tabella oraria in browser: 360 righe e totale 819.
- Verifica del kernel HTTP: guest 302 verso accesso sulla pagina e 401 sui
  JSON; utente ordinario 200 su pagina e entrambi i JSON. Rendering HTML
  verificato senza aggregazioni Stats; query autonome dai dati della Home.
- EXPLAIN ANALYZE sul SQL effettivo, con entrambi i rami lingue: circa 4–5 ms
  per ritmo, 110–117 ms per lingue nelle ripetizioni. Anche policy CW/tag attiva
  e lista blocchi non vuota verificate in memoria, senza cambiare l’istanza:
  circa 5–6 ms e 135–147 ms. SQL e piani in fondo al documento EXPLAIN.
- Sintassi PHP/JS, Pint e compilazione Blade passano. Preparati i test
  `tests/Feature/StatsTest.php`, **non eseguiti**, come richiesto; neppure
  l’intera suite PHPUnit è stata avviata.
- Browser: markup renderizzato con utente ordinario e dataset reali, servito
  da una preview locale separata senza creare credenziali. Provati desktop
  1.024/1.280 px, mobile 390 px, tema personalizzato, filtro eventi e tabella;
  nessun overflow orizzontale. A viewport mobile alta 500 px il secondo grafico
  resta non caricato finché lontano dalla viewport. La preview non sostituisce
  le verifiche HTTP autenticate della pagina reale.
- Conservato il layout originale con colonna destra. L’icona informativa
  della frequenza di aggiornamento resta nello sprint 9, come concordato.

**Punto di arresto:** pronto per la revisione del primo grafico da parte
dell’utente. Revisione approvata e registrata nel commit `f413b8c`;
sprint 2 autorizzato.

## Sprint 2 — Domini, volume e autori attivi

**Backend:** Q01 per Actor ammessi per dominio/tipo/origine; Q04 e Q15
per volume settimanale e autori distinti. Q14 non serve più dopo la rimozione
del filtro per dominio. Stessi perimetri e
intervalli delle query misurate, salvo correzioni esplicite con nuovo piano.
Q00 resta una baseline diagnostica, non un conteggio da esporre come equivalente
di Q01. Non introdurre cache.

**Frontend:** barre dei primi otto domini senza categoria «altri», totale di tutti i domini e
distinzione tra persone/community/feed; grafici settimanali del volume e degli
autori attivi, ciascuno con una sola serie totale senza filtro per istanza
né distinzione locale/remoto.

**Verifica e uscita:** Actor non ammissibili e domini bloccati esclusi;
COUNT DISTINCT corretto per autore presente più volte; «altri» e percentuali
coerenti con lo stesso totale filtrato. UI leggibile con nomi lunghi e molti
domini. Q04 diventa anche denominatore riusabile nelle richieste successive.

Risultato: lettura della composizione dell'istanza e della sua attività.

### Implementazione in revisione — 22 ottobre 2026

Aggiunte tre card: account per dominio/tipo, volume settimanale e autori
attivi settimanali. Le ultime due condividono `/stats/settimane`: una query
raggruppata solo per settimana produce COUNT(*) e COUNT(DISTINCT actor_id).
Non si trasmettono più suddivisioni per dominio o origine. Il conteggio
degli autori è distinto direttamente nell’intera settimana, non una somma
di conteggi giornalieri. Anche il riepilogo del numero di istanze è rimosso;
il volume mostra totale e picco settimanale, gli autori il picco settimanale.
La finestra di due anni e i filtri dei post coincidono con quelli dello sprint 1.
Le settimane mancanti sono riempite con zero; prima e ultima possono essere parziali.

Volume e autori attivi mostrano ciascuno una sola linea totale, senza filtro
per istanza né distinzione locale/remoto, su richiesta dell’utente.
Il grafico domini mostra una fotografia corrente degli Actor ammessi, non
una serie storica: solo i primi otto, distinti per persona/community/feed.
La barra «altre istanze» è omessa su richiesta dell’utente per evitare
una scala dominata dalla coda lunga; totali e percentuali includono tutti i domini.
Nessuna tabella dati e nessuna cache server dei risultati. Il browser riusa
il dataset settimanale nella stessa pagina senza una seconda richiesta per
la card autori; un nuovo caricamento della pagina legge nuovamente i dati.

Anche le query del primo sprint sono allineate alla UI semplificata:
ritmo raggruppato solo per ora, lingue solo per codice normalizzato con
somma SQL dei contributi post/eventi tramite UNION ALL. Lingue nulle,
vuote e `und` escluse già nel database. Il JSON delle lingue contiene
solo lingua/conteggio, quello del ritmo solo ora/totale. Nessuna divisione
per tipo o origine viene calcolata per questi grafici. Le varianti regionali
restano distinte. Aggiornati i test, ancora da eseguire.
Questa modifica supera il precedente contratto del primo sprint; i piani
EXPLAIN precedenti non sono misure delle query ora semplificate.

**Esito:** sprint 2 approvato dall’utente. Eseguito un EXPLAIN ANALYZE per
query corrente: ritmo 8 ms, lingue 83 ms, domini 13 ms, volume/autori 131 ms
sul campione locale; piani completi nel documento EXPLAIN. Nessun nuovo
indice giustificato da queste misure. PHPUnit resta rinviato alla verifica
finale concordata. Autorizzato l’avvio dello sprint 3.

## Sprint 3 — Hashtag e diffusione degli argomenti

**Backend:** Q02B come prima candidata per il top dei tag, Q03 per le serie,
Q16 per autori/domìni distinti. Nessuna normalizzazione richiesta dalla UI. Confermare la
riscrittura con EXPLAIN ANALYZE dell'implementazione. Non rendere obbligatori
i FORCE INDEX delle varianti diagnostiche. Top tag, serie e denominatori della
stessa risposta devono appartenere allo stesso snapshot logico.

**Frontend:** confronto di pochi tag con linee di colore stabile, conteggi e
senza normalizzazione; barre o riepilogo di diffusione tra autori/domìni.
Esporre la finestra di due anni come corpus conservato, senza promettere
copertura storica completa. Evitare che assenza di dati significhi zero attività
storica o che il top cambi per ogni settimana.

**Verifica e uscita:** post multi-tag senza duplicati nella singola serie,
denominatore zero, tag vuoto, cambi di filtro con risposte fuori ordine,
edit/cancellazioni, periodi con pochi dati e policy CW/tag effettivamente attive.
Ancora nessuna cache: misurare il costo complessivo della card, non soltanto
la SELECT finale delle serie.

Risultato: grafici hashtag e diffusione utilizzabili con il corpus disponibile.

### Implementazione in revisione — 22 ottobre 2026

Sprint 2 registrato nel commit `93f9e4b`. Sprint 3 aggiunge «Gli argomenti»
e «La diffusione», entrambi caricati progressivamente dal JSON autenticato
`/stats/hashtag`, riusato soltanto nella pagina corrente e senza cache server.
Nessuna divisione per origine o tipo di contenuto e nessuna tabella dati.

Top dieci hashtag fissi sull’intero periodo di due anni, ordinati per conteggio,
nome e ID per risolvere le parità. Q02B conta prima la pivot e poi unisce i nomi;
nessun FORCE INDEX. Seguono Q03 e Q16 senza origine. Dopo la rimozione
della normalizzazione Q04 non viene più eseguita per gli hashtag, né
si trasmettono denominatori inutilizzati. Le tre query condividono
una transazione di sola lettura REPEATABLE READ sulla connessione MySQL UTC;
SQLite usa il proprio snapshot transazionale. Stesse policy dei post già usate.

Serie settimanali complete con zero nel corpus conservato e soli conteggi
assoluti, senza controllo per 1.000 post. Colori e ordine dei tag stabili; nessuna nuova scelta
per singola settimana. Il grafico di diffusione (utilità da rivedere con l’utente) mostra autori/istanze distinti
nell’intero periodo per ciascun tag, con numero di post nei tooltip. I picchi
di autori e istanze nei riepiloghi sono massimi per singolo tag, non totali
sommati tra tag che potrebbero sovrapporsi.

**Punto di arresto:** mostrare il risultato, raccogliere le correzioni e attendere
l’OK prima del commit e della verifica completa dei nuovi piani. PHPUnit
preparato ma rinviato alla verifica finale concordata.

### Revisione successiva: rimozione diffusione e selezione dei topic da definire

Su richiesta dell’utente rimossa «La diffusione» da pagina, JavaScript e JSON,
insieme alla query Q16 e alle relative COUNT DISTINCT per hashtag. Le query
rimaste sono ranking e serie, nello stesso snapshot. Le descrizioni precedenti
della diffusione sono storiche e superate da questa decisione.

Il criterio top 10 cumulati in due anni resta provvisorio: può nascondere topic
occasionali. Occorre un solo grafico lungo due anni in cui emergano anche picchi
limitati a un mese, senza tendina dei mesi. Selezione dei candidati e resa grafica
sono da discutere prima di modificare ulteriormente l’implementazione.
Nessun commit e nessuna nuova verifica PHPUnit.

### Selezione mensile e legenda breve — decisione successiva

Approvato il concetto: unico grafico con unione dei top 10 mensili e legenda
limitata ai top 10 dell’intero periodo, evidenziazione su hover/focus e tooltip
sulla singola serie. I top 10 complessivi sono inclusi anche se non fossero
mai entrati nei top mensili. Quindi il limite teorico è 240 candidati mensili
più, al massimo, 10 campioni del periodo non già selezionati.

La finestra comprende 24 mesi di calendario, dal primo giorno del mese di
23 mesi fa all’inizio dell’ora corrente UTC: mese corrente parziale. Ranking
mensile tramite ROW_NUMBER; conteggi sull’intero periodo tramite SUM OVER
e DENSE_RANK, con parità risolte per nome/ID. Prima aggregazione della pivot,
poi join dei nomi. Le funzioni finestra sono disponibili su MySQL 8+, MariaDB
10.2+ e SQLite 3.25+; restano da verificare i piani effettivi prima del commit.

Selezionati i candidati, la seconda query ricostruisce tutti i conteggi settimanali
nel periodo: uscire dalla top mensile non azzera né interrompe artificialmente
la serie. La query e la serie condividono lo snapshot di sola lettura.
La legenda HTML mostra solo i dieci campioni complessivi e supporta hover,
focus da tastiera e tap. Evidenziando una linea le altre diventano attenuate;
nel grafico il tooltip usa la serie più vicina e mostra il nome completo.
Nessuna cache, selezione di mesi, normalizzazione o grafico di diffusione.
Questa decisione supera il precedente criterio provvisorio dei top cumulati.
Anteprima locale con dati reali: 124 serie, 10 voci di legenda e 104 bucket
settimanali. Provati evidenziazione dalla legenda e tooltip della singola serie.
Preparato un test per un topic mensile fuori dalla top 10 complessiva,
con conteggi conservati anche nei mesi fuori classifica; PHPUnit non eseguito.

### Correzione definitiva del perimetro mensile e del fuso di visualizzazione

L’utente chiarisce che un hashtag deve essere disegnato SOLO nei mesi in cui
rientra nella top 10. Superata l’interpretazione precedente dei conteggi
completi fuori classifica. Si usa ora un bucket mensile con al massimo dieci
valori presenti per mese; gli altri sono null, non zero. I null interrompono
le linee (spanGaps false); brevi tratti orizzontali permettono di vedere anche un trend
presente in un solo mese. Lo stesso ID hashtag identifica una sola serie,
quindi ricomparse in anni diversi mantengono identità e colore.

Ranking, totali per la legenda e conteggi mensili provengono da una sola
SELECT con funzioni finestra: eliminata la seconda query di serie settimanali
e la transazione esplicita, non più necessaria per la coerenza dello snapshot.
La legenda resta limitata ai dieci campioni complessivi; un eventuale tag
in legenda ma mai nella top mensile non viene forzato nel grafico.

Gli intervalli e i bucket restano UTC. Solo la data «Dati elaborati il» viene
formattata in JavaScript usando il fuso scelto tramite UserDate (preferenza
account, browser o fallback applicativo), con indicazione locale del fuso.
Aggiornati i test per massimo dieci valori al mese, ricomparsa dello stesso
tag, assenza fuori classifica e preferenza di fuso. PHPUnit ancora non eseguito.

Ritocco grafico successivo: nessun pallino fisso sui valori. I mesi isolati
restano visibili come brevi tratti, usando pointStyle line solo quando non
ci sono valori adiacenti. Nota di lettura abbreviata nella UI; le regole
dettagliate restano in questa analisi. Nessun commit.

**Esito sprint 3:** approvato dall’utente. EXPLAIN ANALYZE del SQL definitivo
registrato: circa 409 ms sul campione locale, con indice
`posts_popular_remote_actor_index`, PK della pivot e lookup dei nomi sulla PK.
Il costo include aggregazione mensile e ordinamenti delle funzioni finestra;
nessun nuovo indice giustificato. Cache ancora rinviata allo sprint 9.
PHPUnit non eseguito, come concordato. Autorizzato lo sprint 4.

## Sprint 4 — Conversazioni tra istanze

**Backend:** Q06 per gli archi autore → destinatario effettivo delle risposte,
Q07 per quota dei post radice con risposta da altro dominio. Mantenere la
coorte dei post radice del periodo e dichiararla; non trasformare il grafico
in «tutte le risposte del periodo». Commenti consultabili secondo il modello
esistente, senza inventare un filtro sull'audience originaria non persistita.
Nessuna cache.

**Frontend:** barre delle principali coppie di domini, volume e thread
distinti; indicatore dei post con interlocutori esterni. Distinguere direzione
della risposta e dominio dell'autore radice, con metodologia comprensibile.
Niente rete interattiva o matrice complessa nella prima versione.

**Verifica e uscita:** risposte dirette/annidate, padre mancante, padre non
ammissibile, post e commenti eliminati, community private, radice fuori dal
periodo e corpus vuoto. Nessun fallback dell'interlocutore al post quando
il padre manca. Rivedere i piani con una distribuzione realistica delle risposte.

Risultato: grafico degli scambi e quota dei thread che attraversano domini.

### Implementazione in revisione — 22 ottobre 2026

Sprint 3 registrato in `ddb283e`. Una sola card «Le istanze si parlano»,
con le otto coppie direzionali principali per numero di risposte (senza
«altri»), totale degli scambi tra istanze e quota dei post con almeno un
commentatore di un’altra istanza. Tooltip con risposte e post distinti.
Nessuna serie locali/remoti, filtro di istanza, tabella dati o cache.

JSON autenticato `/stats/conversazioni`, caricato alla visibilità. Q06 viene
aggregata direttamente su tutto il periodo, senza bucket settimanali o somme
scorrette di COUNT DISTINCT. Due rami UNION ALL disgiunti: risposta diretta
al post e risposta annidata al commento padre dello stesso post. Padre non
pubblicato o autore non ammissibile: nessun arco e nessun fallback alla radice.
Padri antecedenti al periodo restano ammessi; quelli futuri rispetto al taglio
sono esclusi. Gli autori di radice, risposte e padri usano la stessa policy,
inclusi host canonici bloccati. CW/tag/community sono filtrati sulla radice.

Q07 usa EXISTS per contare ogni post una sola volta, confrontando il dominio
del commentatore con quello dell’autore radice. Un commentatore pubblicato
resta partecipante anche se il suo padre è stato rimosso: Q07 non afferma
che abbia risposto direttamente alla radice, mentre Q06 non inventa quel
collegamento. Tutti i post ammessi del periodo, anche senza commenti, entrano
nel denominatore. Corpus vuoto: conteggi zero, quota mostrata come trattino.

Coorte: post radice pubblicati negli ultimi due anni, risposte create nello
stesso intervallo fino all’inizio dell’ora corrente UTC. Non sono «tutte le
risposte degli ultimi due anni» su radici più antiche. I commenti non hanno
visibilità persistita autonoma: si applica il modello di consultazione già
esistente, come esplicitato nell’analisi. Nessun dato individuale viene esposto.
Le due query condividono una transazione REPEATABLE READ di sola lettura su
MySQL, la transazione SQLite nei test. I test sono scritti ma PHPUnit resta
rinviato alla verifica finale concordata.

**Punto di arresto:** mostrare la card e raccogliere feedback prima di committare
lo sprint 4. EXPLAIN ANALYZE, inclusi entrambi i rami della UNION ALL, da
registrare nella verifica successiva all’OK.

### Revisione delle card già esistenti durante lo sprint 4

Su richiesta dell’utente «Le connessioni» mostra le dieci istanze principali
con un solo totale di account, senza distinzione persone/community/feed.
Anche SQL e JSON sono semplificati: GROUP BY domain con COUNT(*) e il solo
SUM degli account locali necessario al riepilogo esistente, senza righe per tipo
od origine. Le lingue mostrano le prime dieci; il resto resta in «Altre».
Questa decisione supera i precedenti limiti di otto e la suddivisione per tipo.
Test aggiornati ma non eseguiti; nessun commit prima della revisione.

### Rimozione su richiesta dell’utente

Il grafico delle conversazioni è stato giudicato non utile e rimosso del tutto:
card, route JSON, controller, query Q06/Q07, dataset, JavaScript, CSS,
traduzioni e test dedicati. Le specifiche precedenti di questo sprint restano
come storico di una proposta scartata; Q06/Q07 sono fuori dal perimetro di
implementazione e caching. Restano le revisioni approvate a dieci istanze e
lingue, con account aggregati senza tipo. Nessun commit e nessun avvio dello
sprint successivo senza autorizzazione dell’utente.

## Sprint 5 — Geografia, eventi e community

**Backend:** Q08–Q11 per paesi e copertura dei geotag; Q17 per eventi per mese
di inizio/stato; Q18 per community pubbliche attive e relativo volume. Dataset
geografico con numeratori e denominatori della stessa generazione. Q18 richiede
fixture realmente popolate: il benchmark iniziale non esercita il ramo post.
Nessuna cache.

**Frontend:** barre per paese e selettore post/eventi/insieme, quota dei contenuti
geotaggati, serie mensile degli eventi e serie delle community attive.
Primo perimetro geografico per paese; nessuna mappa o geolocalizzazione degli
utenti. Gestire codici mancanti e gruppi piccoli senza inventare paesi.

**Verifica e uscita:** uno stesso luogo associato a contenuti distinti conta
ciascun contenuto una volta; somma post/eventi coerente; eventi cancellati e
community private escluse; inizio evento futuro distinto dalla sua pubblicazione.
Fixture MySQL non pubbliche o database di prova separato per verificare Q18
senza contaminare il database applicativo; piani di ciascun ramo UNION ALL.

Risultato: grafici geografia, calendario eventi e attività delle community.

## Sprint 6 — Allegati e sondaggi; completamento del giro senza cache

**Backend:** Q12 per allegati MIME immagine/video e post distinti, Q19 per
quota di post con sondaggio. Riutilizzare Q04 come definizione del denominatore,
senza riusare una risposta di un'altra generazione. Includere solo allegati
dei post, senza contare embed esterni come file video e senza dati sui votanti.
Nessuna cache.

**Frontend:** barre immagini/video con unità esplicita (allegati oppure post),
andamento settimanale, quota dei post con sondaggio. Mantenere separate le
categorie sovrapposte, evitando torte che sommino impropriamente al 100%.

**Verifica e uscita:** più allegati nello stesso post, post misti immagine/video,
MIME diversi, post senza allegati e sondaggi su contenuti riservati. Revisione
integrata dei grafici già consegnati, ripetizione dei piani sullo SQL finale
e controlli di sintassi/browser, riportando eventuali errori estranei.
La suite PHPUnit resta rinviata alla verifica finale richiesta dall’utente.

Risultato: tutte le query disponibili sono visualizzate senza cache dei
risultati. Copertura: Q01–Q19, con Q02B al posto di Q02; Q00 e i hint restano
diagnostica. Nessuna fase successiva serve per rendere visibili questi grafici.

## Sprint 7 — Prima acquisizione dei post remoti per sorgente e relay

**Definizione prima della migration:** contare la prima acquisizione locale
riuscita dei nuovi post remoti pubblici ammissibili, non il traffico HTTP,
gli Announce tecnici o gli Update. Usare l'istante locale di acquisizione,
non `published_at` remoto. Definire e registrare copertura dall'attivazione,
identità stabile dei relay, rettifiche e durata della deduplicazione.

**Backend e persistenza:** introdurre i metadati di prima acquisizione minimi
e i bucket giornalieri necessari al grafico, mantenendo distinta la funzione
di deduplicazione da quella di conteggio. Scelta iniziale: conservare la chiave
di deduplicazione delle acquisizioni pubbliche almeno per tutta la retention
delle relative metriche, anche quando il post è rimosso dalla cache locale.
Un contenuto reimportato durante quel periodo non deve contare di nuovo.
I dati precedenti all'attivazione non diventano uno storico ricostruito: quando
non è dimostrabile una prima acquisizione, segnalarla come non attribuibile,
senza inventare la sorgente o incrementare il nuovo flusso storico.

Passare un contesto di provenienza esplicito attraverso inbox, dereference,
outbox/discovery e feed, riusando gli ingester esistenti. Un fetch causato da
un Announce relay conserva la causa relay; non diventa automaticamente discovery.
`relay_id` nullo significa «inbox senza relay identificato» finché non abbiamo
un dato affidabile per una distinzione ulteriore. L'assenza di contesto diventa
«sconosciuto», mai «consegna diretta» per deduzione.

Registrazione e incremento devono essere atomici e idempotenti rispetto
all'inserimento riuscito. Se l'ingester attuale non ha una transazione adeguata,
progettare una transazione locale breve, senza fetch HTTP sotto lock. Esplicitare
il comportamento di retry, importazioni concorrenti e rimozione della
configurazione relay. Nessuna retention aggiuntiva dei payload inbox.
Prima di finalizzare lo schema annotare la politica dei contributi rettificabili
per cambi di visibilità e moderazione, distinguendoli dalla normale retention
del corpus. Misurare indici di scrittura e query di lettura anche su MySQL.

**Frontend nello stesso sprint:** barre impilate settimanali dei nuovi post
per relay/sorgente, con data iniziale di copertura, legenda per relay e
«sconosciuto»/«senza relay identificato» ben distinti. Niente dati simulati
presentati come osservati. Per i relay rimossi conservare una chiave storica e
un'etichetta neutra, senza esporre URL tecnici o errori di configurazione.

**Verifica e uscita:** stessa attività da più consegne, più Announce sullo
stesso post, retry del job, rollback, acquisizione concorrente, post privato,
Update senza nuovo post, contenuto già noto e cleanup dell'inbox. Testare
anche la reimportazione dopo retention. Verificare dal browser che un nuovo
post pubblico ricevuto popoli il grafico, senza cache dei dataset.

Risultato: prima statistica relay fondata su nuovi dati durevoli, già visibile.

## Sprint 8 — Provenienza degli eventi e consolidamento della raccolta

**Backend:** estendere il contesto e il conteggio agli eventi pubblici remoti,
coprendo acquisizioni da inbox, relay e discovery. Riusare il servizio e la
struttura dei bucket dello sprint 7, distinguendo il tipo di oggetto e le
relative finestre di copertura. Verificare la compatibilità di migration e
vincoli MySQL/MariaDB/SQLite; non supporre che la cancellazione del record
relay debba azzerare o scollegare l'identità statistica storica.

**Frontend nello stesso sprint:** selettore post/eventi/insieme sul grafico
di provenienza, con coperture separate. La somma è valida sugli intervalli
comuni; il periodo in cui raccoglievamo solo post non diventa zero eventi.
Non confrontare le nuove acquisizioni con le pubblicazioni dei post come
se avessero lo stesso riferimento temporale.

**Verifica e uscita:** eventi duplicati tra relay e discovery, annullamento
e cambio di visibilità, autore non risolto, cleanup, relay disabilitati/rimossi,
deduplicazione e rettifiche idempotenti. Verificare consistenza tra contributi
e bucket, piani delle letture e dei percorsi usati nelle rettifiche. Documentare
la raccolta e la sua retention nelle guide inglesi/italiane. PHPUnit rimandato
alla verifica finale, come richiesto.

Risultato: nuova metrica di provenienza completa nel perimetro post/eventi,
con grafico aggiornato; ancora nessuna cache delle risposte statistiche.

Non includere ora lo storico di tutte le sorgenti che hanno consegnato un
oggetto: richiederebbe una metrica diversa con conteggi non sommabili.

## Sprint 9 — Cache dei dataset e verifica finale

Introdurre solo ora la cache condivisa dei risultati, tramite backend
database/file supportati da Openbook. La selezione dei TTL considera sia i
bucket visualizzati sia la possibilità di arrivi tardivi e rettifiche: un
bucket settimanale non giustifica sette giorni di risultato congelato.
Valori iniziali da verificare con le misure finali:

| Dataset | Bucket o natura | TTL iniziale |
| --- | --- | ---: |
| Ritmo delle ultime 360 ore (Q13) | Orario, finestra mobile | 5 minuti |
| Actor per dominio (Q01) | Fotografia corrente | 15 minuti |
| Volume/domìni/autori attivi (Q04/Q14/Q15) | Settimanale, corpus modificabile | 1 ora |
| Top e trend hashtag, diffusione e denominatori (Q02B/Q03/Q16/Q04) | Settimanale, contributi retroattivi possibili | 1 ora |
| Lingue (Q05) | Settimanale, corpus modificabile | 1 ora |
| Geografia e copertura (Q08–Q11) | Fotografia sul periodo scelto | 15 minuti |
| Eventi per mese di inizio (Q17) | Mensile, stato/date modificabili | 1 ora |
| Community attive (Q18) | Settimanale, corpus modificabile | 1 ora |
| Allegati e quota sondaggi (Q12/Q19), con denominatori | Settimanale, corpus modificabile | 1 ora |
| Provenienza post/eventi | Bucket durevoli giornalieri, vista settimanale | 15 minuti |

**Minimo assoluto di configurazione: 300 secondi** per i risultati riusabili.
Calcolare scadenza da `generated_at`; non usare «tempo fino a fine bucket»
se può produrre TTL inferiori al minimo. Una nuova finestra richiesta forma
una chiave diversa; non è una cache con TTL più breve. Revoche di accesso,
privacy e moderazione possono invalidare subito: il minimo non obbliga a
conservare per cinque minuti risultati diventati non ammissibili.

Chiavi con metrica/versione, periodo UTC, filtri normalizzati e versione della
policy; niente user ID finché il dataset è realmente comune. HTTP sempre
autenticato, senza cache pubblica dei JSON. Non memorizzare errori, 401 o
risposte parziali come dataset riusciti. Denominatori, top tag e serie della
stessa card sono un solo risultato coerente: non assemblare cache con TTL e
generazioni indipendenti. Riutilizzare Q04 come logica, non come vecchio
denominatore preso da un'altra card.

Normalizzare gli estremi delle finestre mobili: per il ritmo usare l'ora
completa già definita; per le altre serie temporali adottare inizialmente
lo stesso estremo superiore orario. Le fotografie senza periodo hanno una
chiave stabile per filtri e policy. Non includere nella chiave `generated_at`
o un `now()` al secondo diverso per ogni richiesta: annullerebbe ogni hit
della cache. Gli estremi normalizzati fanno parte dei metadati del dataset.

Gestire il ricalcolo concorrente con gli strumenti compatibili col backend
cache scelto e verificare il comportamento se la cache manca o fallisce:
lettura diretta con gli stessi controlli. Per privacy/moderazione invalidare o
versionare i dataset interessati prima di poterli servire di nuovo; nessun
vecchio dato viene riproposto come fallback se non è più ammissibile.

**Frontend nello stesso sprint:** mostrare la data effettiva di generazione,
non l'ora della fetch; mantenere gli stati di caricamento già collaudati.
Sotto ogni grafico aggiungere una piccola icona «i» grigia informativa:
passandoci sopra deve comparire la frequenza di aggiornamento del dataset,
per esempio «I dati vengono aggiornati ogni 15 minuti», così la durata della
cache è comprensibile. Il testo deve riflettere il TTL effettivo del dataset,
non un valore fisso duplicato nel frontend. Rendere la stessa informazione
disponibile anche al focus da tastiera e al tocco, con nome accessibile e
contrasto leggibile. Questo requisito si implementa nella fase di caching.
Verificare tempi delle card con hit, miss e richieste concorrenti. Nessun
polling e nessun pulsante che consenta di aggirare sempre la cache per utente.

**Verifica e uscita:** test di hit/miss, avanzamento del tempo, TTL minimo,
chiavi per filtri/finestre, invalidazione dopo privacy/moderazione, coerenza
dei denominatori, concorrenza e fallback. Un nuovo piano MySQL per le query
dei bucket e le aggregate finali, prova browser completa, revisione del diff,
documentazione inglese/italiana e changelog aggiornati, suite PHPUnit completa
prima di proporre la PR. Riportare separatamente limiti o fallimenti estranei.

Risultato: gli stessi grafici, con carico di lettura ridotto e tempi di
aggiornamento dichiarati; nessun cambiamento silenzioso delle definizioni.

## Estensioni fuori da questo piano

Accesso anonimo, mappe dettagliate, software dei server remoti via NodeInfo,
link esterni indicizzati, accessibilità degli allegati, audience originaria dei
commenti, tutte le consegne/sorgenti per oggetto e ricostruzione storica completa.
Gli snapshot storici di Actor e gli aggregati di hashtag indipendenti dalla
retention si rivalutano dopo questa sequenza: non sono necessari per
visualizzare correttamente il corpus conservato nel primo giro.

### Revisione frontend richiesta — 22 ottobre 2026

Rimosse le tabelle da entrambi i grafici. Le lingue sommano post ed eventi
in una sola serie senza selettore e senza legenda per tipo di contenuto.
Le lingue mancanti (`und`) sono escluse dal grafico e dai riepiloghi;
il contratto JSON e le query restano invariati. Questa revisione sostituisce
le precedenti scelte di presentazione descritte nell’esito iniziale.
Revisione approvata dall’utente e registrata nel commit `f413b8c`.
