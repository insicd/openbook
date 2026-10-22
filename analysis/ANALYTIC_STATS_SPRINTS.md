# Stats — piano di implementazione

Stato: sprint 1–3 approvati; grafico sprint 4 scartato; revisioni top 10 approvate; revisioni registrate in `117f4cb`; sprint 5 implementato per revisione; sprint 6–9 non iniziati.
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

### Implementazione in revisione — 22 ottobre 2026

Tre card e tre endpoint autenticati: `/stats/geografia`, `/stats/eventi`,
`/stats/community`. Nessuna cache. Rendering e caricamento alla visibilità
riusano la pagina esistente; dati e cambi di vista geografica non bloccano HTML.

Geografia: una sola SELECT con quattro rami UNION ALL (paesi dei post, paesi
degli eventi e i rispettivi totali), così numeratori e denominatori hanno lo
stesso snapshot. Ogni contenuto ha al massimo un luogo grazie alle PK delle
location. Il conteggio «con un luogo» non presume la presenza di coordinate.
Codici paese normalizzati in maiuscolo; codici non validi e paesi con meno di
cinque contenuti nella vista selezionata confluiscono in «Altri paesi» già
nel dataset, senza inviare il paese dei gruppi piccoli. Soglia applicata per
ciascuna vista insieme/post/eventi. Sono aggregati geografici di contenuti
pubblici, non posizioni degli autori né una garanzia contro ogni inferenza
tra aggregati. Grafico dei primi dieci paesi più eventuale gruppo «altri»;
quota riferita alla stessa selezione. Il cambio vista usa i dati già ricevuti.

Calendario eventi: una serie totale per mese di inizio, senza suddivisioni
per stato. Si includono solo eventi pubblici non cancellati/deleted, pubblicati
nei due anni della coorte, ma l’inizio può essere futuro. Barre per i soli
mesi con eventi: nessuna sequenza artificiale potenzialmente illimitata tra
un anno remoto e uno futuro. La nota distingue pubblicazione e inizio.

Community: UNION ALL di appartenenza a community locali pubbliche, pubblicazione
diretta da Group e Announce diretti dei Group locali/remoti. Actor del Group e
autore del post devono entrambi essere ammissibili; esclusi Group associati a
community locali private. COUNT DISTINCT Actor Group e post evita duplicazioni,
anche se lo stesso post è rilanciato da più community. Bucket sulla settimana
di pubblicazione originale del post. Nessuna divisione per origine o classifica.
Serie completa con zeri; riepilogo del massimo settimanale e post distinti totali.
La query aggiornata è documentata in Q18 con EXPLAIN dei tre rami; il campione
inizialmente vuoto è ora popolato: per questo aggregato il consenso
all’indicizzazione non è richiesto, né al Group né all’autore del post. Gli
altri filtri restano attivi. Verifica MySQL: 20 settimane non vuote, picco 17
community; query 94,38 ms, EXPLAIN ANALYZE 17,6 ms, indici dei tre rami
registrati in Q18. Il ramo di pubblicazione diretta Group resta vuoto.
Test positivi per pubblicazioni, rilanci, esclusioni e deduplicazione preparati
per la verifica finale; nessuna fixture nel database applicativo.

Anteprima con dati reali: 73 contenuti con luogo su 20.181 ammessi
(7 post e 66 eventi con luogo), 76 eventi su nove mesi di inizio e zero
post ammissibili nelle community. Provato cambio insieme/eventi con riepiloghi
coerenti (73/0,4% e 66/86,8%) e stato vuoto delle community. Nessun dato
simulato aggiunto al database. Controlli di sintassi PHP/JS e Pint passano.

**Punto di arresto:** mostrare i grafici per la revisione; nessun commit dello
sprint 5 prima dell’OK. PHPUnit ancora rinviato. EXPLAIN dei quattro rami
geografici e delle altre query da registrare prima del commit approvato.

### Revisione geografica: solo totale

Su richiesta dell’utente rimosso il selettore post/eventi. Il grafico e il JSON
espongono solo la somma: le quattro branche SQL sono aggregate per paese,
compreso il denominatore totale, senza discriminator di tipo. Non si preparano
più tre viste nel servizio. La soglia per gruppi piccoli è applicata alla sola
somma. Questa decisione sostituisce la precedente scelta delle viste separate.
Test aggiornati, PHPUnit non eseguito, nessun commit.

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

## Sprint 7 — Delivery dei post per sorgente e relay

**Semantica approvata:** contare le delivery elaborate di post pubblici, anche
se già noti. Create, Update e Announce riusciti contribuiscono per il post
principale dell’attività; il fetch dipendente non genera un secondo incremento.
Le citazioni importate come effetto laterale non sono delivery aggiuntive.
I recuperi espliciti e le voci RSS elaborate contribuiscono alla propria sorgente.
Non misurare richieste HTTP, attività respinte o persone/post unici.

**Persistenza:** solo copertura, sorgenti e bucket giornalieri con PK composta
(giorno UTC, sorgente). Nessun hash/identificativo dei post, journal, baseline,
autore/community nei bucket o rettifiche dopo cancellazioni/blocchi/sospensioni.
Restano i confini di visibilità al conteggio e i controlli core d’importazione.
L’identità relay è indipendente dalla configurazione; niente FK al relay.

**Isolamento:** registrazione afterCommit del commit più esterno; incremento
su connessione dedicata con propria transazione. Catch Throwable sia intorno
alla registrazione sia dentro il callback, senza log, rilancio o retry.
Un errore metriche non può annullare il post. Rollback post scarta callback.
È best effort: incrementi persi e duplicazioni da retry interni sono ammessi.

**Frontend:** aree impilate settimanali per relay, inbox senza relay identificato,
recupero/discovery, RSS e sconosciuto. Esplicitare data iniziale e natura best
effort. Nessun dato antecedente inventato. Esempio dimostrativo separato dai dati
reali, senza lasciare dati di prova nel database applicativo.

**Verifiche:** post già noto, relay diversi, Announce che evita l’upsert,
rollback esterno, errore DB statistiche dopo commit, contenuti privati e query
senza join ad autori/post. Migration e indici MySQL/SQLite; PHPUnit alla fase
finale concordata. Mostrare la revisione senza commit.

## Sprint 8 — Provenienza degli eventi e consolidamento della raccolta

**Backend:** estendere il contesto e il conteggio agli eventi pubblici remoti,
coprendo delivery da inbox, relay e discovery, anche di eventi già noti. Riusare il servizio e la
struttura dei bucket dello sprint 7: post ed eventi sono contenuti e incrementano
lo stesso bucket giornaliero per sorgente, senza dimensione per tipo. Verificare la compatibilità di migration e
vincoli MySQL/MariaDB/SQLite; non supporre che la cancellazione del record
relay debba azzerare o scollegare l'identità statistica storica.

**Frontend nello stesso sprint:** un solo grafico delle consegne di contenuti,
senza selettore né serie per post/eventi. Gli eventi contribuiscono dal momento
dell’attivazione; i conteggi dei post già raccolti vengono conservati.
Non confrontare le delivery con le pubblicazioni come se avessero lo stesso
riferimento temporale.

**Verifica e uscita:** delivery ripetute tra relay e discovery contate separatamente,
rollback e guasti alle metriche senza impatto sugli eventi, visibilità privata,
cleanup e relay rimossi senza riscrittura dello storico. Nessun journal o
rettifica retroattiva: stesso modello best effort dello sprint 7. Verificare
piani delle letture e degli incrementi atomici. Documentare
la raccolta e la sua retention nelle guide inglesi/italiane. PHPUnit rimandato
alla verifica finale, come richiesto.

Risultato: nuova metrica di provenienza completa nel perimetro post/eventi,
con grafico aggiornato; ancora nessuna cache delle risposte statistiche.

Non conservare lo storico delle sorgenti per singolo oggetto: i contatori
aggregati di delivery per sorgente sono sommabili e non richiedono identificativi
dei contenuti.

## Sprint 9 — Cache dei dataset e verifica finale

Introdurre solo ora la cache condivisa dei risultati, tramite backend
database/file supportati da Openbook. La selezione dei TTL considera sia i
bucket visualizzati sia la possibilità di arrivi tardivi e rettifiche: un
bucket settimanale non giustifica sette giorni di risultato congelato.
TTL approvati dall’utente per i grafici effettivamente implementati:

| Grafico | Granularità di aggregazione | TTL |
| --- | --- | ---: |
| Quindici giorni, ora per ora | Oraria, ultimi 15 giorni | 5 minuti |
| Le lingue che si incontrano qui | Per lingua, totale degli ultimi 2 anni | 1 ora |
| Un mondo di istanze | Per dominio, fotografia attuale degli account | 15 minuti |
| Due anni di pubblicazioni | Settimanale | 1 ora |
| Quante voci, ogni settimana | Autori distinti per settimana | 1 ora |
| Di cosa si parla | Mensile, top 10 hashtag di ciascun mese | 1 ora |
| Da dove arrivano le storie | Per paese, totale degli ultimi 2 anni | 15 minuti |
| Il calendario degli eventi | Mensile, per data di inizio dell’evento | 1 ora |
| I luoghi dove si conversa | Community attive per settimana | 1 ora |
| Le storie prendono forma | Totali immagini/video degli ultimi 2 anni | 1 ora |
| Immagini e video, settimana per settimana | Settimanale, per tipo di allegato | 1 ora |
| La voglia di chiedere | Percentuale di post con sondaggio per settimana | 1 ora |
| Come arrivano i contenuti | Settimanale per sorgente/relay, da bucket giornalieri condivisi | 15 minuti |

Volume e autori condividono un dataset; totali e trend degli allegati ne
condividono un altro. Nessun dataset per i grafici rimossi (diffusione e
conversazioni tra istanze).

**Minimo assoluto di configurazione: 300 secondi** per i risultati riusabili.
Calcolare scadenza dall’inserimento effettivo `cached_at`. I risultati si
aggiornano esclusivamente dopo scadenza del TTL o svuotamento esplicito admin.
Nessun listener su scritture, modifiche di visibilità, moderazione, cancellazioni,
blocchi di dominio o impostazioni. Anche il cambio d’ora/giorno non interrompe
il TTL. Filtri e finestre UTC vengono valutati soltanto durante il ricalcolo.

Chiavi con metrica/versione e una generazione condivisa, modificata soltanto
dal comando admin. Nessun user ID, timestamp, policy o finestra variabile
nella chiave. HTTP sempre autenticato, senza cache pubblica dei JSON. Errori,
401 e risposte parziali non sono dataset riusciti e non vengono memorizzati.
Numeratori, denominatori, top tag e serie di una card restano un unico risultato.
Le finestre UTC normalizzate sono nei metadati, non nella chiave di cache.

Gestire i ricalcoli concorrenti con lock compatibili con lo store scelto e
lettura diretta quando lo store è assente o guasto. La modifica dei filtri
applicati alle query non è parte di questa revisione: eventuali variazioni del
corpus diventano visibili al successivo ricalcolo per TTL o richiesta admin.

**Frontend nello stesso sprint:** sotto ogni grafico una piccola icona «i»
grigia raccoglie nello stesso hover frequenza di aggiornamento e una sola data:
«Dati aggiornati il …», usando `cached_at`. Nel flusso attuale calcolo e
inserimento sono consecutivi, quindi non mostrare due timestamp ridondanti.
La data resta invariata sulle cache hit. Se non viene usata la cache,
mostrare `generated_at` come data di aggiornamento, senza dichiarare un
inserimento mai avvenuto. Spostare qui la precedente riga esterna.
Date nel fuso dell’utente; gli intervalli del dataset restano in UTC.

Il TTL mostrato deve riflettere quello effettivo del dataset, non un valore
fisso duplicato nel frontend. Rendere la stessa informazione disponibile anche
al focus da tastiera e al tocco, con nome accessibile e contrasto leggibile.
Mantenere gli stati di caricamento già collaudati.

**Admin nello stesso sprint:** in **Database → Manutenzione**, aggiungere una
sezione «Cache statistiche» con pulsante «Svuota cache delle statistiche».
Azione riservata agli amministratori, POST protetta da CSRF, con riscontro
esplicito dell’esito. Invalida tutti e soli i dataset Stats condivisi: non
svuota la cache generale dell’applicazione, non cancella bucket, sorgenti o
metadati storici della raccolta. I dataset vengono rigenerati alle successive
richieste, senza eseguire tutte le query nel POST amministrativo. Usare una
generazione/namespace della sola cache Stats, così un ricalcolo già in corso
non può ripubblicare risultati nella generazione corrente dopo lo svuotamento.

Verificare tempi delle card con hit, miss e richieste concorrenti. Nessun
polling e nessun pulsante che consenta di aggirare sempre la cache per utente.

**Verifica e uscita:** test di hit/miss, avanzamento del tempo, TTL minimo,
stabilità delle chiavi dopo modifiche e cambi di ora/giorno, coerenza
dei denominatori, concorrenza e fallback. Test del comando admin: autorizzazione,
invalidazione di tutti i dataset Stats, conservazione delle altre cache e dei
bucket di delivery, ricalcolo successivo e race con richieste già in corso.
Controllare nel tooltip TTL e timestamp reali, invariati sulle cache hit.
Un nuovo piano MySQL per le query
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

### Revisione comune: statistiche aggregate senza indexable

Rimosso il filtro `indexable` da tutti i dataset, non solo dalle community,
con un unico filtro condiviso senza eccezioni o parametri speciali. Restano
scoperta e tutte le esclusioni di visibilità/moderazione. Aggiornati metodologia
UI EN/IT/FI, documentazione e fixture positive con `indexable=false` per tutti
i grafici; il test di esclusione distingue ora il consenso alla scoperta.
PHPUnit rinviato alla verifica finale come concordato. Anteprima rigenerata.

Verifica MySQL dopo l’ampliamento della popolazione: ritmo 36,69 ms, lingue
132,49 ms, domini 22,77 ms, serie settimanali 138,14 ms, hashtag 637,50 ms,
geografia 182,76 ms, calendario 0,98 ms, community 56,43 ms. SQL, binding e
EXPLAIN ANALYZE completi degli otto dataset registrati in ANALYTIC_STATS_EXPLAIN,
inclusi tutti i rami UNION. I post mantengono accessi indicizzati; la scansione
degli Actor per i domini e l’aggregazione hashtag restano da coprire con il
caching nello sprint previsto. Queste misure locali non garantiscono il costo
su un corpus più grande.

### Revisione comune: nessun filtro discoverable

Anche `discoverable` è rimosso da tutti i dataset aggregati. Restano visibilità,
stato attivo, assenza di sospensione/cancellazione, esclusione domini bloccati
(compreso host canonico), Actor tecnici e filtri CW già previsti. Nessuna modifica
alle regole di scoperta o ricerca dell’applicazione. Fixture positive impostate
con entrambi i consensi false; regressioni di privacy e sospensione mantenute.
Metodologia UI e documentazione EN/IT aggiornate; PHPUnit rinviato.

Verifica MySQL senza entrambi i filtri: ritmo 32,60 ms, lingue 171,04 ms,
domini 31,91 ms, settimane 145,29 ms, hashtag 1.237,41 ms, geografia 207,37 ms,
calendario 0,60 ms e community 54,26 ms. EXPLAIN ANALYZE completo registrato
per gli otto dataset, inclusi i rami UNION. Accessi ai post ancora indicizzati;
aggregazione hashtag più costosa con il corpus ampliato, da considerare nella
fase caching prevista. Anteprima community: 1.230 post distinti, picco 17
community attive in una settimana. Misure locali, non garanzia su grandi corpus.

### Sprint 6 — Prima implementazione da rivedere

Due dataset autenticati senza cache: `/stats/allegati` e `/stats/sondaggi`.
Il primo alimenta due grafici con una richiesta condivisa nella pagina: barre
dei totali immagine/video e linee settimanali dei file allegati. Query unica con
COUNT DISTINCT dei post e conteggi condizionali per MIME; un post misto conta
in entrambe le categorie, una volta sola nel totale dei post con media.
Nessuna divisione locale/remoto. Esclusi audio, documenti ed embed esterni.

Il secondo aggrega post totali e post con sondaggio nella stessa query tramite
LEFT JOIN alla relazione univoca polls.post_id. Nessun accesso a voti/opzioni.
Percentuale globale calcolata sui totali, senza mediare percentuali settimanali.
Il grafico mostra la quota settimanale; nessuna percentuale nelle settimane
senza post (null e interruzione della linea), zero quando i post esistono ma
nessuno contiene un sondaggio. Inclusi sondaggi aperti e chiusi.

I bucket seguono la pubblicazione originale UTC, due anni fino all’ultima ora
completa. Filtri condivisi senza discoverable/indexable; privacy e moderazione
invariate. Test preparati per MIME multipli/misti, conteggio distinto, contenuti
privati, file non pertinenti e denominatore sondaggi; PHPUnit non eseguito.
Nessun commit dello sprint 6 prima della revisione dell’utente.

Verifica MySQL sprint 6: query media 520,16 ms (EXPLAIN ANALYZE 620 ms),
polls 133,93 ms (EXPLAIN ANALYZE 124 ms). Piani completi e SQL finale in Q12/Q19
e nel registro EXPLAIN. Media: post tramite posts_popular_remote_actor_index,
att tramite indice univoco post/media, media e Actor via PK; polls: stesso
accesso post, polls_post_id_unique covering. Non sono giustificati nuovi indici
MIME per l’accesso dalla pivot. Entrambe restano candidate al caching previsto.

Anteprima reale: 31304 immagini, 1347 video, 27262 post con media;
12 post con sondaggio su 48.860 ammessi (0,02%). Sintassi PHP/JS, Pint e
diff --check passano. Verifica browser dei tre grafici e dei relativi riepiloghi.
PHPUnit non eseguito; sprint 6 pronto per revisione, senza commit.

### Sprint 7 — Revisione delivery richiesta dall’utente

La precedente implementazione di prima acquisizione è sostituita, non affiancata:
il journal degli hash e le rettifiche sono rimossi. Anche le tabelle locali del
prototipo non committato sono eliminate e sostituite dalla migration
`2026_10_22_000000_create_post_delivery_metrics`. Nessuna scansione/baseline post.
Tre tabelle: stats_collections, stats_delivery_sources, stats_post_delivery_buckets.
Un solo contatore per giorno/sorgente; nessuna dipendenza da autori o post.

Conteggio inbox centralizzato dopo il successo del processore, così anche gli
Announce su post già in cache contribuiscono senza doppio conteggio per l’upsert.
Il source dei poll resta separato per non alterarne le verifiche federate.
Le statistiche non modificano i controlli di sicurezza/accettazione dell’inbox.

Prove manuali su SQLite file isolato con connessione metriche distinta: upsert
reale conta solo dopo il commit esterno; seconda consegna dello stesso post
incrementa; due Announce relay su post noto incrementano due volte; rollback
scarta callback; cancellazione/sospensione successive non cambiano lo storico.
Tabella metriche eliminata di proposito: nuovo post salvato e commit concluso,
nessuna eccezione propagata. File di prova eliminato, nessun post dimostrativo
nel database applicativo. Test PHPUnit aggiornati ma non eseguiti.

Verifica MySQL: afterCommit eseguito solo al commit esterno, connessione server
metriche distinta da quella applicativa, due delivery dello stesso post = due
incrementi. Nessun post/account modificato; metriche di prova eliminate.
Query sui 24 bucket dimostrativi: 0,73 ms, EXPLAIN ANALYZE 0,199 ms, range
sulla PRIMARY(day, source_id), lookup sorgente sulla PRIMARY. UPDATE atomico
sulla stessa PRIMARY, una riga. Nessun join ad Actor/post/community.
Anteprima reale rigenerata; demo separata generata su SQLite temporaneo poi
eliminato. Nessun commit prima della revisione dell’utente.

Revisione grafico delivery: aree impilate al posto delle barre, con segmenti
rettilinei (tension 0), nessun pallino e colori dal tema. Tooltip per settimana
con valori di tutte le sorgenti e totale. Nessuna modifica a query o dataset.
Anteprima reale e demo aggiornate; nessun commit.
Con un solo bucket settimanale si mantiene una singola colonna impilata:
un’area senza due punti non avrebbe estensione visibile. Da due settimane
in poi il grafico è ad aree. Anteprima demo verificata nel browser, incluso
il tooltip con tutte le sorgenti e il totale della settimana (12).

Revisione testi provenienza: titolo «Come arrivano i post» per esplicitare che
gli eventi sono ancora allo sprint 8. Rimossi nota tecnica sotto la card e
riepilogo grande «Raccolta dal»; resta il range sotto al grafico. Il consenso
a duplicazioni/perdita incrementi resta documentato nell’analisi, non nella
UI. Rimossa la menzione best effort anche dalla metodologia visibile.

### Sprint 8 — Implementazione approvata

Un’unica tabella `stats_content_delivery_buckets` con PK (day, source_id),
nessuna dimensione per tipo e nessuna tendina. Post ed eventi incrementano
lo stesso contatore per giorno e sorgente. La migrazione rinomina la tabella
post conservando storico e data iniziale. Gli eventi contribuiscono solo
dall’attivazione della loro raccolta, senza ricostruzioni retroattive.

DeliveryStats/DeliverySource condividono afterCommit esterno, connessione
separata, transazione autonoma e catch silenzioso. Inbox conteggiato una sola
volta dopo esito positivo, compresi Announce su eventi noti. Gli ingester
non duplicano il conteggio inbox; outbox, risoluzione URL e refresh usano fetch.
Endpoint e query unici senza parametro type e senza UNION; aggregazione
settimanale del totale dei contenuti. Grafico ad aree impilate per sorgente.

Test mirati aggiornati per verificare che post ed eventi della stessa sorgente
finiscano nella stessa riga; PHPUnit rimandato alla verifica finale concordata.
Sprint 8 approvato dall’utente; pronto per il commit.

Verifiche manuali aggiornate: SQLite con ingester reale, Announce ripetuti,
post ed eventi nello stesso bucket, rollback, privacy e guasto della tabella
senza perdita dell’evento. Demo: 70 consegne complessive su quattro settimane.
MySQL: connessione separata e commit esterno verificati; EXPLAIN ANALYZE della
query unica su 24 bucket, 0,54 ms; incremento via PRIMARY con una riga attesa.
Nessun dato applicativo di prova conservato. Migration locale applicata.

### Sprint 9 — Implementazione da rivedere

CachedStatsDataset avvolge i dataset esistenti senza cambiare le query aggregate.
TTL approvati in config/stats.php, minimo 300 secondi. Chiavi v3 per metrica
e unica generazione condivisa, cambiata solo dall’admin. Lock con lease 120 secondi e attesa massima 3 secondi,
poi lettura diretta. Un’invalidazione durante il calcolo provoca un nuovo giro;
le generazioni obsolete non vengono servite. Gli errori delle query propagano
senza essere memorizzati, quelli della cache degradano alla lettura diretta.
I timestamp generated_at/cached_at/expires_at sono nel dataset; la lettura della
cache non li riscrive. In transazione si legge direttamente senza memorizzare.

Invalidatore automatico e listener SQL rimossi su richiesta dell’utente.
Modifiche ai post, privacy, sospensioni, cancellazioni, blocchi e impostazioni
non svuotano la cache Stats. Nessuna invalidazione implicita tramite cambio
di policy o finestra nella chiave; anche dopo il cambio d’ora resta il TTL.
I filtri esistenti delle query si applicano al successivo ricalcolo.

UI: «i» grigia accessibile da mouse/tastiera/tocco, chiusura con Escape, data
spostata nel tooltip e frequenza derivata dal backend. Comando POST admin in
Database → Manutenzione, separato dalla pulizia dati; riscontro di successo o
errore. Invalida logicamente le chiavi Stats; le vecchie chiavi scadono col TTL,
senza flush generale o cancellazione dei bucket.

Suite PHPUnit completa NON eseguita: fermarsi prima, come richiesto dall’utente;
sarà eseguita prima e dopo la review finale. Sprint senza commit.

Verifica sprint 9: 32 test mirati StatsCacheTest/StatsTest/DeliveryStatsTest
passati (579 assertion), più 10 test AdminDatabaseMaintenanceTest e
AdminDomainBlockTest (60 assertion). Nessuna suite completa. La cache di
configurazione locale è stata ricaricata per includere stats.php. I test usano
un APP_CONFIG_CACHE separato, mantenendo la protezione SQLite del progetto.
Il primo tentativo con artisan era stato bloccato dalla protezione (nessuna
assertion e nessun database applicativo modificato). I test della cache creano
SQLite in memoria con migrate:fresh; evitato il rollback dell’intero schema,
che incontra un problema preesistente nell’indice conversation_id.

MySQL con store database e prefisso temporaneo isolato: tutti gli 11 dataset
con hit senza query al corpus, 0,54–1,80 ms nel campione locale; cold miss fino
a 1,31 s per gli hashtag. EXPLAIN lettura cache via PK. Quattro processi PHP
concorrenti: una sola query aggregata e identico dataset/timestamp restituito.
Chiavi e lock di prova rimossi; nessun contenuto di prova nel DB applicativo.
SQL e misure nel registro EXPLAIN. Browser: informazioni aperte da pulsante e
Enter, chiuse con Escape; date EEST corrette, riga esterna rimossa. Anteprima
admin statica con il solo pulsante Stats disabilitato, azione reale coperta dai
test HTTP. Screenshot salvati per revisione. Nessun commit dello sprint 9.

Revisione UI: sorgente inbox mostrata come «Consegna diretta», senza cambiare
la classificazione: nessun relay configurato riconosciuto, non necessariamente
invio dell’autore originale. Etichette inglese e finlandese allineate.

Revisione tooltip: mostrare solo frequenza e «Dati aggiornati il …» (cached_at,
oppure generated_at per risposte senza cache). Backend e TTL invariati.

Revisione finale delle invalidazioni: rimossi listener, namespace corpus e
variazioni implicite della chiave. Rimangono esclusivamente TTL e comando admin.
Test aggiornati: edit, visibilità, sospensioni, blocco server, impostazioni e
cambio d’ora mantengono lo stesso risultato fino a scadenza. Dopo scadenza si
applicano gli attuali filtri; lo svuotamento admin continua a proteggere dai
ricalcoli già in corso. Verificati 11 test mirati (48 assertion), senza suite
completa e senza commit.

Revisione footer: ritmo espresso come 15 giorni senza UTC né spiegazione degli
zeri; istanze senza distinzione tecnica per tipo; calendario senza nota sugli
annullati; community senza riferimento ad account non ammissibili. Aggiunta
nota generica ai Percorsi: tutti i contenuti pubblici ricevuti dall’istanza
attraverso le diverse sorgenti. Traduzioni EN/FI e anteprima allineate.

### Chiusura sprint 9 — prima della review generale

Suite globale eseguita dopo tutte le revisioni: 1.986 test, 14.166 assertion,
111 skip opzionali, nessun errore o failure. Corretti due problemi di preparazione
già presenti su main: schema SQLite mancante nel test VisibilityFromAudienceTest
e soglia excerpt del FeedTest dipendente dal .env locale. Nessuna modifica al
comportamento applicativo per queste due correzioni. Pint, sintassi JS e diff
check passati. Si procede con il commit di fine sprint e poi con una review
separata dell’intero ramo rispetto a main, seguita da nuova suite globale.
