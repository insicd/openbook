# Stats — piano di implementazione

Stato: sprint 1 implementato per revisione; sprint 2–9 non iniziati.
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
  sulla Home personale del visitatore; stessi filtri in grafico e tabella;
- controller sottili, query in `Application/Queries`, servizio di dataset
  responsabile di unità, periodo, copertura, denominatori e data di generazione;
- solo periodi e filtri ammessi esplicitamente, serie limitate e throttle
  ragionevole degli endpoint; niente SQL o intervalli arbitrari dal browser;
- nessuna aggregata Stats durante il rendering HTML; caricamento alla
  visibilità, massimo due richieste simultanee e nessun polling automatico;
- grafici coerenti col CSS Openbook, tabella accessibile, stato vuoto/errore,
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
stati comuni. Linea/area per il ritmo e barre delle lingue, con «Mostra dati».
Usare i codici lingua completi normalizzati, mantenendo «non indicata» distinta;
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
Il confronto grafico/tabella usa lo stesso dataset e conteggi esatti.

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
dell’utente; nessun avvio dello sprint 2 senza il suo OK.

## Sprint 2 — Domini, volume e autori attivi

**Backend:** Q01 per Actor ammessi per dominio/tipo/origine; Q04, Q14 e Q15
per volume settimanale, domini autori e autori distinti. Stessi perimetri e
intervalli delle query misurate, salvo correzioni esplicite con nuovo piano.
Q00 resta una baseline diagnostica, non un conteggio da esporre come equivalente
di Q01. Non introdurre cache.

**Frontend:** barre dei domini con categoria «altri», totale dei domini e
distinzione tra persone/community/feed; grafici settimanali del volume e degli
autori attivi, con serie locali/remote e selezione limitata dei domini autori.
La selezione delle categorie resta stabile durante l'intero periodo.

**Verifica e uscita:** Actor non ammissibili e domini bloccati esclusi;
COUNT DISTINCT corretto per autore presente più volte; «altri» e percentuali
coerenti con lo stesso totale filtrato. UI leggibile con nomi lunghi e molti
domini. Q04 diventa anche denominatore riusabile nelle richieste successive.

Risultato: lettura della composizione dell'istanza e della sua attività.

## Sprint 3 — Hashtag e diffusione degli argomenti

**Backend:** Q02B come prima candidata per il top dei tag, Q03 per le serie,
Q04 per la normalizzazione e Q16 per autori/domìni distinti. Confermare la
riscrittura con EXPLAIN ANALYZE dell'implementazione. Non rendere obbligatori
i FORCE INDEX delle varianti diagnostiche. Top tag, serie e denominatori della
stessa risposta devono appartenere allo stesso snapshot logico.

**Frontend:** confronto di pochi tag con linee di colore stabile, conteggi e
opzione per 1.000 post; barre o riepilogo di diffusione tra autori/domìni.
Esporre la finestra di due anni come corpus conservato, senza promettere
copertura storica completa. Evitare che assenza di dati significhi zero attività
storica o che il top cambi per ogni settimana.

**Verifica e uscita:** post multi-tag senza duplicati nella singola serie,
denominatore zero, tag vuoto, cambi di filtro con risposte fuori ordine,
edit/cancellazioni, periodi con pochi dati e policy CW/tag effettivamente attive.
Ancora nessuna cache: misurare il costo complessivo della card, non soltanto
la SELECT finale delle serie.

Risultato: grafici hashtag e diffusione utilizzabili con il corpus disponibile.

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
| Conversazioni (Q06/Q07) | Settimanale e quota sulla stessa coorte | 1 ora |
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
