<!-- Le misure storiche conservano i filtri effettivamente usati; vedere la revisione corrente. -->
# Stats — idee e analisi iniziale

Stato: sprint 1 implementato per revisione; sprint successivi in attesa.
Verifica dello schema, dei flussi e delle protoquery con EXPLAIN ANALYZE e
revisione critica: 22 ottobre 2026. Piano in [ANALYTIC_STATS_SPRINTS.md](ANALYTIC_STATS_SPRINTS.md).

## Obiettivo e limiti

Offrire una pagina «Stats», accessibile a tutti gli utenti autenticati senza
privilegi particolari, per esplorare la porzione di Fediverso conosciuta
dall'istanza Openbook: domini, argomenti, volumi e provenienza dei contenuti.
Le statistiche descrivono il database locale, non l'intero Fediverso e neppure
la popolazione completa dei server remoti.

Decisione di accesso aggiornata: la pagina non sarà consultabile dai guest.
Il riferimento al perimetro «pubblico» nel resto dell'analisi riguarda i
contenuti inclusi nelle statistiche, non l'accessibilità anonima della pagina.
Richiedere il login non estende il dataset a messaggi, community private o
altri contenuti riservati.

Ogni grafico dovrebbe indicare periodo, unità di misura, filtri, ultima
elaborazione e copertura effettiva. Distinguere sempre:

- **fotografia corrente**: oggetti ancora presenti e ammessi dai filtri;
- **flusso osservato**: arrivi o pubblicazioni registrati in un intervallo;
- **storico conservato**: aggregati raccolti da quando la rilevazione è attiva.

Retention, cancellazioni, importazioni tardive e modifiche ai contenuti possono
cambiare le serie ricostruite dal database corrente. L'assenza di dati storici
non deve essere rappresentata automaticamente come assenza di attività.

## 1. Actor conosciuti per dominio

Prima idea: classifica dei domini per numero di Actor presenti nel database.

```sql
SELECT COUNT(*) AS C, domain
FROM actors
GROUP BY domain
ORDER BY C DESC, domain ASC;
```

La query è una base esplorativa, non ancora il contratto di una pagina pubblica.
Conta tutte le righe: persone, community (`group`), feed e Actor tecnici
(`application`), locali e remoti, inclusi gli stati non attivi.
Non misura utenti registrati sul dominio, utenti attivi o server collegati
tramite follow.

Possibile presentazione:

- barre orizzontali dei primi 20 domini e tabella paginata;
- conteggio e percentuale sul totale della stessa popolazione filtrata;
- filtro locali/remoti e suddivisione per tipo di Actor;
- categoria «altri» per evitare grafici illeggibili;
- totale dei domini distinti nel campione.

Per la prima implementazione: Actor attivi e ammissibili alla scoperta,
escludendo domini bloccati e identità tecniche, con persone, community e feed
distinguibili per tipo. Il benchmark adotta il filtro conservativo
`discoverable = 1 AND indexable = 1`, mantenuto nel prototipo e dichiarato
nella metodologia: il numero risultante non va etichettato come totale di
tutti gli Actor conosciuti. `indexable` remoto assente diventa false nel
resolver, quindi il filtro può escludere molti Actor anche se i loro post
sono leggibili. Cambiare questa scelta richiede anche aggiornare query,
metodologia e misure, senza mescolare popolazioni diverse tra grafici.

Disponibilità: **ricavabile dai dati attuali**. Uno storico della crescita per
dominio richiederebbe snapshot: `actors.created_at` indica la creazione della
riga locale, non la registrazione dell'account remoto.

## 2. Trend settimanale degli hashtag negli ultimi due anni

Grafico a linee: asse X con settimane, asse Y con numero di post pubblici che
usano ciascun hashtag. Fonti: `posts`, `post_hashtags`, `hashtags`; la pivot ha
chiave primaria (`post_id`, `hashtag_id`), quindi lo stesso tag conta una sola
volta per post. Un post con più tag contribuisce a più serie.

Proposta iniziale:

- finestra mobile di due anni di calendario, con bucket settimanali;
- settimane da lunedì, fuso esplicito e comune per tutti, inizialmente UTC;
- prima e ultima settimana eventualmente parziali, segnalate nel grafico;
- raggruppamento per `posts.published_at`, con estremi temporali espliciti;
- selezione di pochi hashtag, per esempio i primi 5–10 nel periodo, con
  possibilità di confrontare tag scelti dall'utente;
- serie stabile nel periodo: evitare una selezione diversa dei top tag per
  ogni settimana, che renderebbe difficile seguire una linea;
- filtro origine locale/remota e modalità opzionale normalizzata: post con
  quel tag ogni 1.000 post pubblici ammissibili della stessa settimana;
- settimane senza utilizzi a zero soltanto dove la copertura è disponibile;
  periodi non coperti come dati mancanti.

Contare i post originali una volta; non moltiplicarli per consegne o boost.
Per una prima versione escludere commenti ed eventi. Gli eventi hanno già
`event_hashtags`: potranno avere una serie separata, con stato e visibilità
ammissibili definiti prima di un eventuale totale combinato.

Disponibilità: **ricostruibile soltanto per i contenuti ancora conservati**.
La retention dei post remoti può rendere impossibile coprire due anni; anche
hashtag aggiunti o rimossi con un edit alterano retroattivamente la serie.
Prima della UI verificare la copertura effettiva, senza promettere due anni
completi. Se interessa uno storico delle osservazioni, serviranno aggregati
periodici e una data di inizio rilevazione, senza inventare il passato.

`PopularHashtagsQuery` è un riferimento per join e normalizzazione, ma non è
riutilizzabile senza adattamenti: oggi somma post ed eventi e ammette anche
contenuti `unlisted`. Per queste statistiche pubbliche si propone il solo
perimetro strettamente `public`, con i controlli descritti sotto.

## 3. Provenienza: consegna diretta e relay, distinti per relay

**Abbiamo una parte dei dati, con limiti importanti.**

`inbox_items` conserva `relay_id`, `remote_activity_uri`, `activity_type`,
`received_at`, stato di elaborazione e payload. `RelayIngressResolver`
riconosce il trasportatore HTTP come relay configurato, accettato e abilitato
alla ricezione; `InboxController` salva il suo ID per la shared inbox.

Sono quindi possibili conteggi interni delle **attività inbox registrate** per
settimana e relay, eventualmente separati per tipo e stato. Non sono ancora
conteggi attendibili dei **contenuti acquisiti** per sorgente:

- `relay_id IS NULL` significa «nessun relay identificato», non prova una
  consegna diretta dall'autore; può comprendere altri inoltri;
- `remote_activity_uri` è univoco: una riconsegna della stessa attività viene
  scartata, conservando la provenienza della prima registrazione;
- relay differenti possono inviare Announce con URI differenti per lo stesso
  oggetto: attività distinte non equivalgono a contenuti distinti;
- un'attività elaborata può essere un Follow, Like, Update, Delete o una
  no-op, non necessariamente l'importazione di un nuovo post;
- fetch da outbox, discovery, risoluzione di URI o feed possono introdurre
  contenuti fuori dall'inbox e non hanno qui una provenienza equivalente;
- non esiste una relazione permanente contenuto-relay; gli Announce tecnici
  dei relay non diventano boost sociali;
- la manutenzione può eliminare gli inbox item conclusi; eliminando un relay,
  la foreign key imposta `relay_id` a null e si perde l'attribuzione storica.

### Due possibili metriche da tenere separate

1. **Attività inbox per trasportatore riconosciuto**: barre impilate settimanali
   con un colore per relay e una categoria «senza relay identificato».
   Fattibile sullo storico inbox rimasto, con deduplicazione per attività.
   I totali grezzi includono attività private o amministrative: mantenerli
   diagnostici finché non è definito un sottoinsieme pubblicabile verificabile.
2. **Nuovi contenuti pubblici acquisiti per sorgente**: barre impilate o quote
   per relay, consegna senza relay, fetch/discovery, feed e sconosciuto.
   Richiede nuova strumentazione nei flussi di acquisizione, con collegamento
   all'oggetto e controllo della sua effettiva ammissibilità pubblica.

Per la seconda metrica distinguere due domande:

- **prima acquisizione**: ogni contenuto conta una volta, attribuito alla
  sorgente che lo ha introdotto; non misura il contributo esclusivo del relay;
- **tutte le sorgenti osservate**: un contenuto può essere associato a più relay
  e i totali delle sorgenti non sono sommabili come contenuti unici.

Per la prima versione si sceglie la **prima acquisizione di nuovi post ed
eventi remoti pubblici**, distinta dalle attività inbox e da tutti i tentativi
di consegna. Le sorgenti non riconosciute restano esplicitamente sconosciute.
La registrazione riguarda il primo inserimento locale riuscito; una successiva
transizione privato → pubblico non riscrive la prima acquisizione come pubblica.
La metrica storica non rappresenta il corpus tuttora presente.

Servirebbero tipo/ID dell'oggetto, istante di acquisizione, categoria e identità
stabile della sorgente, salvati in modo idempotente insieme all'importazione
riuscita. Prevedere «sconosciuto/storico precedente»: non attribuire a posteriori
tutti i vecchi post con relay assente alla consegna diretta. Conservare solo i
metadati necessari, senza prolungare la retention dei payload grezzi.

## Aggregazione persistente a bucket

Per le statistiche dei relay considerare una tabella di contatori per bucket
temporale, riutilizzando lo stesso approccio per altre metriche quando serve.
Questo permette di conservare lo storico oltre la pulizia dell'inbox e riduce
il costo delle consultazioni pubbliche, senza conservare i payload originali.

Il caso più semplice è una metrica basata su un istante assegnato localmente,
come la ricezione: un'attività ricevuta oggi incrementa il bucket di oggi anche
se dichiara una pubblicazione di due anni fa. Il mittente non può scegliere
un bucket passato tramite la data nel payload. Per ogni metrica va quindi
definito **quale evento si conta e quale orologio determina il bucket**.

| Metrica | Riferimento temporale | Comportamento dei bucket passati |
| --- | --- | --- |
| Attività registrate per relay | Ricezione locale | Nessuna retrodatazione dal payload; distinguere riconsegne da nuove registrazioni. |
| Contenuti acquisiti per la prima volta | Prima acquisizione locale riuscita | Un oggetto antico scoperto oggi conta oggi; richiede deduplicazione per oggetto. |
| Hashtag per data di pubblicazione | `posts.published_at` | Importazioni tardive ed edit possono aggiornare settimane passate. |
| Actor conosciuti per dominio | Istante dello snapshot locale | Ogni bucket fotografa il corpus di quel momento; i conteggi non sono sommabili nel tempo. |

### Forma iniziale da valutare

Una tabella indicativa `statistic_buckets`, oppure una tabella dedicata ai
relay se le esigenze lo giustificano, con:

- metrica e versione della sua definizione;
- inizio bucket e granularità, inizialmente giornaliera in UTC;
- dimensioni esplicite e limitate, per esempio categoria della sorgente e
  chiave stabile del relay;
- contatore intero e timestamp di aggiornamento;
- vincolo univoco sulla combinazione metrica/versione, bucket e dimensioni.

Usare chiavi di dimensione non nulle anche per «senza relay» e «sconosciuto»,
così il vincolo univoco resta efficace anche su MySQL e SQLite. La chiave
storica del relay non deve sparire con il record di configurazione; la sua
etichetta pubblica può essere gestita separatamente senza salvare URL privati.
Evitare dimensioni arbitrarie o per singolo utente, che farebbero crescere la
tabella e renderebbero meno utili gli aggregati.

I bucket giornalieri consentono di derivare settimane e mesi con somme dei
contatori additivi. Non sommare conteggi di autori distinti giornalieri per
ottenere autori distinti settimanali: la stessa persona potrebbe contare più
volte. Anche gli snapshot del numero di Actor richiedono una lettura per
istante, non una somma. La tabella comune non deve nascondere queste differenze.

### Scrittura, deduplicazione e chiusura

- Incremento atomico insieme alla registrazione dell'evento nella stessa
  transazione, quando possibile: per esempio inserimento del nuovo inbox item
  e incremento del bucket di ricezione. Un retry non deve incrementare di nuovo.
- Definire se si contano le attività uniche o tutti i tentativi di consegna:
  sono metriche diverse. Per le prime, il vincolo inbox protegge soltanto finché
  la riga esiste; dopo la pulizia una vecchia attività riconsegnata può apparire
  nuova. Decidere se mantenere una chiave di deduplicazione più durevole o
  dichiarare esplicitamente la finestra di deduplicazione.
- Per contenuti acquisiti, contare soltanto l'effettivo primo inserimento
  riuscito, nella sua transazione. Retry del processor, Update, più Announce
  dello stesso oggetto e acquisizioni concorrenti non devono duplicarlo.
  Anche qui chiarire la semantica dopo cancellazione e successiva reimportazione.
- L'assenza di retrodatazione in ingresso non garantisce da sola che un bucket
  sia subito definitivo: elaborazioni asincrone attribuite alla ricezione,
  importazioni storiche amministrative e rettifiche possono aggiornarlo.
  Dichiarare quando il bucket è consolidato e quali correzioni restano ammesse.
- Se si aggrega tramite cron, rendere atomici avanzamento del checkpoint e
  aggiornamento dei contatori, gestire gli elementi ancora in elaborazione e
  impedire che la manutenzione cancelli dati non ancora aggregati. Preferire
  l'incremento transazionale nel flusso per semplici contatori di ricezione.
- Registrare inizio e interruzioni della raccolta: un bucket assente vale zero
  solo durante una finestra certamente coperta. Un eventuale backfill deve
  indicare i limiti dei dati rimasti ed evitare sovrapposizioni con la raccolta
  attiva.

Per flussi senza contributi retroattivi, i bucket consolidati diventano così
uno storico economico da leggere e conservare. Per metriche come gli hashtag
per data di pubblicazione servono invece aggiornamenti dei bucket passati o
ricalcoli mirati; una diversa metrica «hashtag dei contenuti acquisiti oggi»
sarebbe più semplice, ma risponderebbe a una domanda diversa.

Resta da distinguere la fotografia storica di un flusso pubblico osservato dal
conteggio dei contenuti tuttora pubblicabili. Un aggregato anonimo di trasporto
può avere una semantica storica propria; aggregati per hashtag o altri dettagli
dei contenuti richiedono una decisione esplicita su cancellazioni e moderazione.
Se sono necessarie sottrazioni puntuali, i soli contatori non bastano: occorre
conservare il contributo rettificabile o una possibilità di ricalcolo, con una
retention coerente. I bucket non risolvono da soli l'ammissibilità pubblica dei
dati inbox descritta sopra.

## Altre idee selezionate

Le seguenti cinque proposte sono incluse nel piano, oltre a domini, hashtag
e provenienza. L’ordine e il perimetro iniziale sono definiti nel documento
degli sprint; le estensioni opzionali restano esplicitamente separate.
Tutte riguardano esclusivamente contenuti ammessi alla consultazione pubblica.

### Lingue dei contenuti

Distribuzione delle lingue e loro andamento nel tempo, con eventuale distinzione
tra contenuti locali e remoti. I campi `language` sono già presenti in post,
commenti ed eventi: definire quali includere e mantenerli distinguibili nei
conteggi. Prevedere «non indicata» e normalizzazione dei codici, senza presentare
la lingua dichiarata come risultato verificato di un riconoscimento automatico.

### Conversazioni tra istanze

Aggiornamento: grafico scartato dall’utente durante la revisione dello sprint 4;
Q06/Q07 conservate come analisi storica, fuori dall’implementazione corrente.

Mostrare quanto i domini dialogano tra loro: per esempio quota di post con
almeno una risposta da un altro dominio, numero di domini coinvolti e volume
degli scambi tra coppie di istanze. I dati sono ricavabili da post, commenti e
Actor. Distinguere risposte al post e risposte a un commento usando
`parent_comment_id`: il dominio dell'interlocutore dipende dal destinatario
della risposta, non sempre dall'autore del post iniziale.

Possibili rappresentazioni: classifica delle coppie di domini, matrice degli
scambi o andamento temporale. Contare soltanto risposte pubblicamente visibili
su discussioni pubbliche. La copertura è quella delle conversazioni conosciute
dall'istanza, che possono essere incomplete; i contatori remoti da soli non
permettono di ricostruire gli interlocutori.

### Geografia dei contenuti geotaggati

Distribuzione geografica di **post ed eventi**, consultabili **insieme oppure
separatamente**. Fonti già presenti: `post_locations`, `event_locations` e
relativi contenuti. Possibili viste: mappa aggregata e classifica per paese,
regione o città, secondo la completezza dei dati disponibili.

Si misura il luogo associato al contenuto, non la residenza o la posizione
attuale dell'autore. Il denominatore è il sottoinsieme geotaggato; mostrare anche
la sua incidenza sui contenuti pubblici. Per la vista combinata contare una
volta ciascun post/evento, mantenendo distinguibili i due tipi. Normalizzare i
luoghi quando possibile, senza attribuire coordinate ai record che non le
possiedono e senza esporre dettagli geografici troppo precisi per gruppi piccoli.

### Allegati: immagini e video

Distribuzione e andamento di immagini e video nei contenuti pubblici. Le
relazioni degli allegati e `media.mime_type` offrono già una base; decidere
successivamente se includere anche commenti ed eventi e se distinguere gli
embed video dagli allegati, perché non seguono necessariamente la stessa
rappresentazione.

Tenere separate due misure utili: numero di singoli allegati e numero/quota di
contenuti con almeno un'immagine o video. Un post con quattro immagini conta
quattro allegati ma un solo post con immagini; un contenuto misto può comparire
in entrambe le categorie. Distinguere eventualmente origine locale/remota.

### Ritmo temporale: ultimi 15 giorni, bucket di un'ora

**Grafico a linea degli ultimi 15 giorni con bucket di 1 ora**: sequenza
cronologica continua, senza raggruppare insieme le stesse ore o gli stessi
giorni della settimana. Proposta iniziale: numero di post pubblicati per ora,
con possibili serie separate locali/remoti; l'inclusione di altri contenuti
resta da valutare.

Usare bucket di un'ora in UTC e dichiarare il fuso delle etichette. La prima
versione usa esattamente 360 ore complete: estremo superiore all'inizio
dell'ora UTC corrente, escluso, ed estremo inferiore 360 ore prima. Non include
l'ora corrente parziale. I cambi di ora legale riguardano solo le etichette,
non il numero o la durata dei bucket. Riempire a zero solo gli intervalli con
copertura nota. Il riferimento iniziale è `published_at`: recuperi tardivi
possono modificare ore passate. Se si scegliesse invece il ritmo degli arrivi,
usare un istante locale di ricezione/acquisizione e indicarlo chiaramente.
I bucket persistenti descritti sopra possono essere applicati anche a questa
serie, con la granularità oraria richiesta.

## Frontend: prima proposta

### Accesso e collocazione

- Pagina «Stats», indicativamente `GET /stats`, con middleware di autenticazione
  e gli stessi eventuali controlli di stato account delle normali pagine interne.
  Nessun requisito di ruolo amministratore o moderatore.
- Link nel footer sotto il menu, accanto a Regole e Privacy:
  `resources/views/partials/sidebar-left.blade.php`, corrispondente al punto
  mostrato nello screenshot. Non aggiungere una voce al menu principale.
- Per mantenerla raggiungibile anche su mobile senza aprire il menu, riportare
  il link anche nel footer generale di `resources/views/layouts/app.blade.php`,
  visibile agli autenticati.
- Anche tutti gli endpoint JSON devono richiedere autenticazione, senza
  permettere il recupero anonimo dei dataset conoscendone l'URL. Un'eventuale
  sessione scaduta deve produrre uno stato comprensibile con link all'accesso,
  non un errore di parsing quando il fetch riceve HTML.

### Layout: partire da quello esistente

Riutilizzare inizialmente `layouts.app`, header, menu sinistro, colonna destra,
card, tipografia, spaziature e breakpoint attuali. Il CSS usa una shell fino a
1.600 px con colonne `260px minmax(0, 1fr) 300px`, gap di 24 px e padding
orizzontale complessivo di 40 px. A 1.280 px restano circa 632 px centrali,
prima del padding delle card: sufficienti per un grafico per riga, poco adatti
a due grafici affiancati. Al breakpoint desktop più stretto lo spazio cala
ulteriormente; sotto 1.024 px la colonna destra è già nascosta.

Prima prova con card a larghezza piena e altezza del grafico adattata al mobile.
Se legende, serie hashtag o futuri confronti tra domini risultano troppo
compressi, introdurre una variante della stessa shell per Stats: menu sinistro
conservato, colonna destra non renderizzata e centro allargato. Non duplicare
l'intero template e non lasciare una colonna CSS vuota. Decidere dopo aver
provato viewport desktop stretti e mobile, non soltanto il monitor largo.

Intestazione semplice, breve descrizione del campione e poi grafici in sequenza.
Ogni card contiene titolo, periodo, eventuale valore riassuntivo coerente con
il dataset, grafico, legenda e data di aggiornamento. Iniziare con pochi
controlli locali alla card; evitare un filtro globale che sembri modificare
anche statistiche con periodi fissi, come le 360 ore del ritmo temporale.

### Libreria e stile dei grafici

Prima scelta proposta: **Chart.js**, distribuzione browser UMD servita dal
progetto con versione fissata e licenza conservata. Si integra tramite script
con JavaScript semplice, senza introdurre React, una SPA o un processo di build
obbligatorio nell'installazione. Caricare libreria e codice Stats solo su questa
pagina; nessuna dipendenza runtime da CDN o servizi esterni.

Aspetto desiderato: linee morbide, tratto arrotondato, riempimento leggero,
barre con angoli arrotondati, griglia discreta e tooltip leggibili. Animazione
breve solo alla prima comparsa e rispetto di `prefers-reduced-motion`.
Usare interpolazione monotona ove appropriato per evitare picchi inventati
tra i punti; mantenere i conteggi orari originali, buchi per dati mancanti e
tooltip esatti. Nessuna media mobile implicita, grafico 3D o asse troncato
che faccia apparire più grandi le differenze nei conteggi.

Il canvas non eredita automaticamente i colori CSS: un piccolo adattatore
legge con `getComputedStyle()` le variabili effettive `--ob-color-primary`,
`--ob-color-primary-soft`, `--ob-color-text`, `--ob-color-text-muted`,
`--ob-color-border`, `--ob-color-surface` e `--ob-font`, e configura Chart.js.
Così il CSS personalizzato dell'istanza si riflette anche sui grafici.
Per più serie definire eventuali variabili semantiche Stats con fallback
coerenti, mantenendo contrasto, ordine stabile e differenze leggibili anche
senza affidarsi soltanto al colore. Un aggiornamento del tema nella pagina
richiederà di rileggere i valori e aggiornare il canvas.

Prime forme proposte:

- ritmo temporale: linea/area delle ultime 360 ore, punti non marcati tutti
  insieme, poche etichette sull'asse X e dettagli esatti nel tooltip;
- lingue e domini: barre orizzontali ordinate, top categorie e «altre»;
- allegati: barre immagini/video con unità chiaramente scelta tra allegati e
  contenuti; categorie sovrapposte non diventano fette di una torta;
- hashtag: poche linee confrontabili con colori stabili;
- conversazioni: prime barre delle coppie di domini; matrice o rete soltanto
  se la leggibilità ne giustifica la complessità;
- geografia: prima classifica per paese, con post/eventi/insieme. Un'eventuale
  mappa richiederà un asset geografico locale e una valutazione dedicata;
  Chart.js base non è una libreria cartografica e non serve introdurre tile
  remoti per la prima prova.

### Pagina pronta subito, dati caricati in seguito

La risposta HTML deve contenere struttura, titoli e segnaposto delle card con
altezza già riservata, senza eseguire le aggregate statistiche. Caricare i
dataset via fetch da endpoint JSON per singola statistica, con servizio di
query separato dai controller. Nei primi sprint tutte le statistiche già
ricavabili vengono eseguite **senza cache dei risultati**: caricamento alla
visibilità e limite di concorrenza contengono il lavoro, senza renderlo
gratuito per il database. La cache condivisa viene introdotta solo dopo
i nuovi dati relay, nell’ultima fase del piano. Il caricamento asincrono
protegge il primo byte della pagina in tutte le fasi.

Usare `IntersectionObserver` per accodare una card quando si avvicina alla
viewport (per esempio 200 px prima), inizializzandola una sola volta. Limite
iniziale di due richieste contemporanee, nessun polling e nessun recupero dei
grafici ancora lontani in fondo alla pagina. Senza supporto all'observer,
caricare tramite la stessa coda limitata. Un grafico lento o fallito non deve
impedire il funzionamento degli altri.

Prevedere stati distinti: in attesa, caricamento, dati disponibili, nessun dato,
copertura incompleta ed errore con «Riprova». Cambiando filtro, annullare la
richiesta precedente o scartare risposte obsolete. Le risposte includono unità,
periodo e fuso, copertura e data di generazione; il browser non inventa zeri o
normalizzazioni. Cache backend comune agli utenti soltanto finché il perimetro
resta quello condiviso dei contenuti pubblici; l'HTTP deve rispettare l'accesso
autenticato senza rendere i dataset pubblicamente cacheabili.

Ogni grafico ha un riepilogo testuale e un controllo «Mostra dati» per una
tabella accessibile alimentata dallo stesso JSON. Il canvas richiede nome e
descrizione accessibili; tooltip e colore non possono essere l'unico modo per
leggere i valori. Senza JavaScript mostrare un messaggio esplicito, senza
recuperare tutte le aggregate durante il rendering HTML come fallback.

### Prima implementazione di prova proposta

1. Pagina autenticata, link nei footer e card nel layout corrente.
2. Due dataset reali: ritmo temporale e lingue, senza cache dei risultati,
   con caricamento alla visibilità e gestione degli stati. Sono utili per provare sia una serie
   temporale sia categorie con etichette potenzialmente lunghe.
3. Verifica visiva su desktop largo, 1.024–1.280 px e mobile, anche con colore
   primario personalizzato e movimento ridotto; solo allora scelta definitiva
   sulla colonna destra.
4. Test mirati: guest esclusi da pagina e JSON, utenti ordinari ammessi,
   nessun contenuto riservato, assenza iniziale di cache e denominatori coerenti.
   Verificare in browser che l'HTML non aspetti le query, le card lontane non chiamino gli
   endpoint e un errore resti locale alla sua card.

Fonti tecniche consultate il 22 ottobre 2026:
[integrazione browser
Chart.js](https://www.chartjs.org/docs/latest/getting-started/integration.html),
[colori](https://www.chartjs.org/docs/latest/general/colors.html),
[linee e interpolazione](https://www.chartjs.org/docs/latest/charts/line.html),
[accessibilità canvas](https://www.chartjs.org/docs/latest/general/accessibility.html),
[licenza MIT](https://github.com/chartjs/Chart.js/blob/master/LICENSE.md).

## Altre query già possibili incluse nel primo giro

Queste statistiche, inizialmente tenute in riserva, sono incluse nel giro
senza cache richiesto: si riusano dataset e componenti dove possibile invece
di creare necessariamente una card per ogni query. Tutte si riferiscono al
perimetro pubblico ammesso. Le idee emerse nel menù ma non riportate qui
restano fuori dal piano corrente.

| Idea | Visualizzazione | Dati e limiti |
| --- | --- | --- |
| Volume di pubblicazione locale/remoto | Barre settimanali | `posts.published_at`, `actors.is_local`; storico limitato dalla retention. |
| Domini autori dei post | Classifica o barre impilate nel tempo | `posts.actor_id` → `actors.domain`; origine dell'autore, distinta dal relay trasportatore. |
| Autori attivi per settimana | Linee locali/remoti | Actor distinti con almeno un post pubblico nel periodo; non accessi o presenza online. |
| Diffusione degli hashtag | Barre con post e autori distinti | Pivot hashtag e autore del post; aiuta a distinguere volume da varietà degli autori. |
| Eventi pubblici | Numero per mese di inizio e stato | `events`; distinguere appuntamenti da pubblicazioni e definire inclusione dei cancellati. |
| Community pubbliche attive | Conteggi e post per settimana | `communities` e `posts.community_id`; escludere sempre le community private. |
| Presenza di sondaggi | Quota dei post con sondaggio | `polls` e `posts`; nessun elenco di votanti o preferenze individuali. |

Rinviare classifiche di engagement: i contatori di Like, commenti e Announce
remoti possono riflettere osservazioni parziali o snapshot, non metriche
omogenee dell'intero Fediverso. Escludere dalle statistiche pubbliche messaggi,
login, notifiche, segnalazioni e preferenze di follow degli hashtag.

## Perimetro pubblico e privacy

- Per i post richiedere `status = published`, `visibility = public`, esclusione
  delle conversazioni private e controlli di accesso per guest, incluse le
  community private. `visibleTo(null)` da solo ammette anche gli `unlisted`.
- Applicare le policy di moderazione, inclusi domini bloccati, sospensioni e
  cancellazioni degli Actor. Per decisione corrente, `discoverable` e `indexable`
  non filtrano queste statistiche aggregate; le regole della ricerca e della
  scoperta dell’applicazione restano separate.
- Definire il trattamento dei CW e dei tag esclusi secondo le policy pubbliche
  dell'istanza; non aggirarle attraverso un grafico o un export.
- Pubblicare aggregati, senza payload inbox, destinatari o identificativi
  privati. Stabilire soglie minime e granularità per gruppi piccoli, anche nei
  filtri combinati, per evitare inferenze su singoli utenti.
- Decidere se rendere pubblica l'identità dei relay configurati: una relazione
  amministrativa non è automaticamente un'informazione destinata al pubblico.
- Cache e aggregati del corpus corrente devono seguire cancellazioni, cambi
  di visibilità e moderazione. Per lo storico dei flussi osservati definire
  esplicitamente anonimizzazione e politica di rettifica, come discusso nella
  sezione sui bucket, prima di eliminare i dati necessari a correggerlo.

## Sostenibilità tecnica

Partire da query di lettura in `Application/Queries` e controller sottili,
senza cache dei risultati nel primo giro. Limitare periodo, numero di serie
e combinazioni dei filtri; tutte le richieste sono autenticate. Dopo aver
implementato e visualizzato le query disponibili e la provenienza relay,
introdurre cache database/file con TTL minimo di cinque minuti, come nel
piano degli sprint. Eventuali ulteriori precalcoli tramite il cron esistente
vanno giustificati da nuove misure; nessun Redis, worker permanente o servizio
esterno obbligatorio.

La query esplorativa iniziale e le protoquery riportate sotto sono state
eseguite sul database locale; piani e limiti della verifica sono documentati
nella sezione seguente. Per ulteriori varianti non ancora misurate,
prima dell'implementazione misurare `EXPLAIN` su MySQL/MariaDB con cardinalità
realistiche, inclusi tutti i rami di eventuali `UNION ALL`. Esiste già
`actors_is_local_domain_index` su (`is_local`, `domain`), ma il suo uso dipende
dai filtri effettivi; non prova l'efficienza della query senza filtro. Per gli
hashtag verificare ordine dei join e uso degli indici di `posts` e delle pivot.
Per l'inbox valutare gli indici temporali sulla query scelta, senza aggiungerli
preventivamente. Mantenere test SQLite per filtri e bucket temporali: funzioni
settimanali SQL specifiche di MySQL richiedono un adattamento esplicito.

## Query e verifica EXPLAIN ANALYZE

Verifica eseguita il **22 ottobre 2026** sul MySQL locale configurato
nell'applicazione, in transazioni di sola lettura e snapshot coerente per ogni
sessione di misura, senza modificare schema, indici, dati o statistiche del
planner. Sessione in UTC; timeout di 15 secondi per singola query. Il server
riporta `VERSION() = 26.7.0` e `version_comment = Homebrew`: non assumere che il
piano sia identico su altre versioni MySQL o su MariaDB.

Sono state misurate 26 forme di query, comprese baseline e varianti diagnostiche,
con tre `EXPLAIN ANALYZE` consecutivi per forma. Sono stati letti anche gli
`EXPLAIN` stimati e verificata l'esecuzione delle SELECT. I piani integrali
della terza ripetizione e tutti i tempi sono in
[ANALYTIC_STATS_EXPLAIN.md](ANALYTIC_STATS_EXPLAIN.md).

### Campione e interpretazione

Conteggi esatti dello snapshot principale: **33.045 Actor, 49.502 post,
25.268 commenti, 178.019 associazioni post-hashtag, 54.843 hashtag,
33.025 associazioni post-allegato, 66.049 media, 151 eventi, 52 luoghi post,
141 luoghi evento, 12 sondaggi, una community e nessun dominio bloccato**.
Il filtro conservativo sotto ammette 20.084 post nel periodo di due anni.
Il campione locale contiene pubblicazioni con date anche molto antecedenti
all'acquisizione; questi numeri non misurano il traffico corrente di produzione.

Le righe post sono state create localmente tra il 9 agosto e il 16 ottobre 2026;
l'ultima pubblicazione presente è del 16 ottobre. Quindi gli ultimi 15 giorni
rispetto al 22 ottobre non sono una finestra pienamente rappresentativa di
un'istanza attiva. È stata verificata anche la finestra di 15 giorni più popolata
del corpus ammesso, 29 agosto–13 settembre, con 16.757 post (Q13C).
Non si deduce la completezza storica dal solo MIN/MAX delle date.

I tempi indicati sono i millisecondi del **nodo radice** del piano, non la somma
dei tempi dei nodi e non la latenza HTTP. La prima ripetizione non è un test
"a freddo": il buffer poteva già contenere dati. Le altre due descrivono
ripetizioni ravvicinate, senza traffico concorrente simulato né svuotamento dei
buffer. Nessuna garanzia di carico sostenibile su shared hosting deriva da
questi tempi; CPU, I/O, concorrenza e scala del database vanno verificati lì.

### Casi saltati e perimetro dei prototipi

- **Provenienza dei contenuti per relay, prima acquisizione e contributo di
  tutte le sorgenti**: saltati, manca la persistenza richiesta. Non sostituiti
  con conteggi inbox semanticamente diversi. Anche i bucket proposti non
  esistono ancora, quindi nessun loro EXPLAIN è stato inventato.
- **Storico completo di due anni**, crescita storica degli Actor e statistiche
  indipendenti dalla retention: saltati come ricostruzione completa. Q02–Q04
  misurano invece la query sul **corpus ancora conservato**, utile per decidere
  il costo del prototipo, senza dichiarare completa la serie.
- **Lingue dei commenti strettamente public**: il commento non conserva una
  visibilità propria. Q05 include post ed eventi distinti. Q06/Q07 contano
  risposte pubblicamente consultabili secondo il modello corrente, su post
  strettamente public; non distinguono l'audience originale public/unlisted
  della singola risposta. Per esigere anche questa distinzione manca un dato.
- **Video incorporati esterni**: esclusi da Q12, che misura gli allegati MIME
  persistiti. Commenti ed eventi con allegati restano un'estensione futura;
  il prototipo selezionato riguarda i post.
- Le ulteriori idee della tabella precedente sono coperte da Q04 e Q14–Q19
  e incluse nel primo giro senza cache; Q18 è eseguibile ma non offre una
  misura rappresentativa.

### Revisione corrente: nessun filtro discoverable o indexable nelle statistiche

Per decisione del 22 ottobre 2026 tutte le statistiche aggregate includono
anche Actor con `discoverable=false` e `indexable=false`. Restano i filtri di stato,
sospensione, cancellazione, blocco e visibilità già previsti. Il filtro
condiviso A e l’implementazione sono aggiornati; le misure storiche qui sotto
e in ANALYTIC_STATS_EXPLAIN conservano i filtri originali e non vanno confuse
con la nuova popolazione. I piani aggiornati sono aggiunti al registro EXPLAIN.

### Prefissi SQL comuni e parametri

Le query sotto sono protoquery SQL MySQL: anteporre i CTE indicati a ciascun
corpo, collegandoli con virgole dopo un unico `WITH`. Le date letterali sono i
parametri effettivamente misurati: intervallo semiaperto
`[2024-10-22 15:00:00, 2026-10-22 15:00:00)` UTC, salvo Q13/Q13B/Q13C.
In applicazione saranno binding, come gli ID hashtag selezionati da Q02.

Scelta conservativa per questo benchmark e per il prototipo iniziale: richiedere agli autori sia
`discoverable` sia `indexable`, attività e assenza di sospensione, escludendo gli
Actor tecnici. È una scelta iniziale esplicita, non la riproduzione automatica di tutte le
query pubbliche esistenti; il suo effetto sulla popolazione è descritto nella
sezione sugli Actor.
La protoquery geografica è una prima distribuzione per paese: mappa a celle,
città e regioni non sono state misurate e richiedono una granularità definita.
Gli eventi privi di autore risolto sono esclusi. I blocchi sono qui confrontati
con `actors.domain`: in implementazione va mantenuto anche il controllo sugli
host degli URI canonici nei casi in cui differiscano dal dominio dell'handle,
riusando le regole del servizio. Il campione senza blocchi non misura quel caso.

Q06 usa i post radice pubblicati nel periodo come coorte: esclude risposte a
post radice più antichi anche se il commento è recente. Per misurare tutte le
risposte del periodo occorrerà separare il filtro temporale dei post da quello
dei commenti e rimisurare quella variante. I commenti padre non hanno invece
il filtro temporale del figlio, per non perdere l’interlocutore sul confine
del periodo. I record senza padre ammissibile non diventano risposte alla radice.

La configurazione osservata ha `hide_content_warnings_from_world = false` e
lista hashtag forzati vuota: i piani riguardano questa policy. Con CW/tag
esclusi servono i relativi predicati nei CTE, anche nel ramo eventi; i tempi
non vanno attribuiti a quella variante senza una nuova misura. Soglie sui
piccoli aggregati e normalizzazione geografica restano condizioni della UI
pubblica: i risultati di questo benchmark non vengono pubblicati come elenco
di singoli contenuti o autori.

CTE `eligible_actors` (Q01 e tutte le query sui contenuti):

```sql
WITH eligible_actors AS (
  SELECT a.id, a.domain, a.is_local, a.type
  FROM actors a
  WHERE a.status = 'active' AND a.remote_suspended = 0
    AND a.deleted_at IS NULL
    AND a.type <> 'application'
    AND NOT EXISTS (SELECT 1 FROM domain_blocks b WHERE b.domain = a.domain)
)
```

CTE `public_posts` da aggiungere per le query sui post:

```sql
, public_posts AS (
  SELECT p.id, p.actor_id, p.community_id, p.published_at, p.language,
         a.domain, a.is_local
  FROM posts p JOIN eligible_actors a ON a.id = p.actor_id
  WHERE p.status = 'published' AND p.visibility = 'public'
    AND p.conversation_id IS NULL
    AND (p.community_id IS NULL OR EXISTS (
      SELECT 1 FROM communities c WHERE c.id = p.community_id AND c.is_private = 0
    ))
    AND p.published_at >= '2024-10-22 15:00:00'
    AND p.published_at < '2026-10-22 15:00:00'
)
```

CTE `public_events` da aggiungere per le query sugli eventi:

```sql
, public_events AS (
  SELECT e.id, e.actor_id, e.published_at, e.start_at, e.language, e.status,
         a.domain, a.is_local
  FROM events e JOIN eligible_actors a ON a.id = e.actor_id
  WHERE e.visibility = 'public' AND e.deleted_at IS NULL
    AND e.status IN ('scheduled', 'tentative', 'postponed')
    AND e.published_at >= '2024-10-22 15:00:00'
    AND e.published_at < '2026-10-22 15:00:00'
)
```

Le funzioni `WEEKDAY`, `DATE_SUB` e `DATE_FORMAT` non sono portabili direttamente
su SQLite: l'implementazione dovrà adattare la generazione dei bucket o
centralizzarla in un componente testabile. I predicati temporali restano sulle
colonne originali; il calcolo della data del bucket è nel SELECT/GROUP BY.

### Risultati e modalità di calcolo suggerita

Cache qui significa file/database condivisa tra richieste, coerente con lo
shared hosting, con chiavi che includano periodo, filtri e versione della policy.
Le indicazioni nella tabella derivano dal benchmark e non anticipano
l’introduzione della cache nei primi sprint. I TTL operativi dell’ultima fase
sono definiti nel piano: coerenti con bucket e modificabilità dei dati, sempre
almeno 300 secondi; sostituiscono gli intervalli orientativi della tabella.
Non sono soglie ricavate matematicamente da tre misure. Invalidare per cambi di privacy
e moderazione:
un TTL da solo non è una garanzia di rimozione tempestiva. Prevenire il ricalcolo
concorrente dello stesso aggregato; non eseguire tutte le query della pagina a
ogni visita.

| Query | Prima esecuzione ms | Ripetizioni 2–3 ms | Indicazione per la fase finale |
| --- | ---: | ---: | --- |
| Q00 — Actor per dominio: query esplorativa originale | 33.5 | 11.3–11.7 | Cache facoltativa, 5–15 min |
| Q01 — Actor pubblici per dominio e tipo | 41.5 | 16.5–16.9 | Tempo reale possibile; cache 5–15 min consigliata |
| Q02 — Top 10 hashtag nel corpus conservato | 1642 | 562–1287 | Cache 15–60 min; preferire Q02B |
| Q03 — Serie settimanale di 10 hashtag selezionati | 193 | 202–211 | Cache 5–15 min, insieme al set dei top tag |
| Q04 — Denominatore settimanale e volume locale/remoto | 122 | 107–109 | Cache 5–15 min |
| Q05 — Lingue di post ed eventi, separate per tipo | 167 | 178–195 | Cache 5–15 min |
| Q06 — Scambi tra domini: risposte al destinatario effettivo | 803 | 102–112 | Cache 5–15 min |
| Q07 — Quota post con almeno una risposta di altro dominio | 180 | 168–173 | Cache 5–15 min |
| Q08 — Geografia: soli post | 2.33 | 0.132–0.133 | Tempo reale ragionevole sul campione |
| Q09 — Geografia: soli eventi | 1.02 | 0.34–0.34 | Tempo reale ragionevole sul campione |
| Q10 — Geografia: post ed eventi insieme e distinti | 0.537 | 0.528–0.537 | Tempo reale ragionevole sul campione |
| Q11 — Copertura dei geotag: denominatori per post ed eventi | 121 | 114–115 | Cache 5–15 min; riusare i denominatori |
| Q12 — Allegati immagine/video e post distinti, per settimana | 363 | 177–183 | Cache 5–15 min |
| Q13 — Ritmo degli ultimi 15 giorni: 360 bucket orari | 4.89 | 4.59–4.64 | Tempo reale sul campione recente; cache 5 min prudente |
| Q14 — Domini autori dei post per settimana | 119 | 114–114 | Cache 5–15 min |
| Q15 — Autori attivi per settimana | 125 | 122–125 | Cache 5–15 min |
| Q16 — Diffusione hashtag tra autori e domini | 302 | 328–343 | Cache 5–15 min |
| Q17 — Eventi pubblici per mese di inizio e stato | 0.213 | 0.175–0.178 | Tempo reale ragionevole sul campione |
| Q18 — Community pubbliche attive per settimana | 0.0111 | 0.00554–0.00604 | Non classificabile dal campione; cache da rivalutare |
| Q19 — Quota di post con sondaggio per settimana | 133 | 119–122 | Cache 5–15 min; costo dominato dal denominatore |
| Q02B — Top hashtag: aggregazione prima del join ai nomi | 616 | 373–473 | Variante preferibile; resta da mettere in cache |
| Q03B — Serie settimanale di 10 hashtag selezionati — indice hashtag forzato (diagnostica) | 196 | 184–187 | Solo diagnostica, nessun hint proposto come requisito |
| Q16B — Diffusione hashtag tra autori e domini — indice hashtag forzato (diagnostica) | 266 | 212–244 | Solo diagnostica; cache ancora indicata |
| Q13B — Ritmo orario: controllo su altra finestra storica | 4.6 | 4.32–4.37 | Controllo storico poco popolato, non prova di scala |
| Q13C — Ritmo orario: finestra di 15 giorni più popolata del corpus ammesso | 124 | 97.7–127 | Cache 5 min consigliata |
| Q13D — Distribuzione giornaliera per scegliere una finestra rappresentativa | 910 | 103–114 | Solo query ausiliaria del benchmark |

Non emerge al momento la necessità di tabelle metriche alimentate a ogni evento
per le statistiche sul corpus corrente. Dopo la fase iniziale senza cache,
il primo livello di ottimizzazione ragionevole è query aggregate con cache; per Q01,
geografia ed eventi il calcolo diretto è già
plausibile sul campione. Per Q13 decidere sulla finestra densa, non sui soli
4–5 ms della finestra recente: circa 98–127 ms consigliano una cache breve.

Se la crescita o i cache miss rendono troppo costosi hashtag, allegati o lingue,
il passo successivo è una tabella di aggregati ricalcolata in batch dal cron,
prima di aggiungere bookkeeping a tutti i flussi di scrittura. Per uno storico
che sopravviva alla retention, invece, i bucket persistenti rispondono a un
requisito funzionale e vanno alimentati durante il funzionamento, con le regole
di rettifica già discusse. COUNT DISTINCT di autori, domini o post non si
ottiene sommando indiscriminatamente bucket giornalieri.

### Corpi delle query e lettura dei piani

Q00 è la query originale della sezione 1, eseguita senza CTE. Le altre query
usano i prefissi seguenti: `A` = eligible_actors, `P` = public_posts,
`E` = public_events. Per la normalizzazione degli hashtag per 1.000 post,
unire in applicazione Q03 e Q04 per settimana, sommando le origini se necessario,
e applicare `1000 × utilizzi / post_count` quando il denominatore è positivo.
Una quota senza dati va lasciata mancante. Per gli allegati il denominatore
Q04 deve mantenere anche la stessa origine locale/remota.

#### Q00 — Actor per dominio: query esplorativa originale

Scansione covering di `actors_is_local_domain_index`, aggregazione temporanea e sort.
Non usa un lookup sul solo dominio; legge comunque tutto l’indice. È solo la baseline
senza filtri.

Baseline senza filtri pubblici: confronto tecnico, non contratto della pagina pubblica.

#### Q01 — Actor pubblici per dominio e tipo

Scansione di tutti i 33.045 Actor: `actors_is_local_domain_index` non viene scelto con
questi filtri. Anti-join sui blocchi tramite `domain_blocks_domain_unique`; 6.891 Actor
ammessi, 1.213 gruppi. Non giustifica da sola un nuovo indice.

Prefissi: **A**.

```sql
SELECT domain, type, is_local, COUNT(*) AS actors_count
FROM eligible_actors
GROUP BY domain, type, is_local
ORDER BY actors_count DESC, domain, type, is_local
```

#### Q02 — Top 10 hashtag nel corpus conservato

Legge 48.751 post candidati tramite `posts_popular_remote_actor_index`, ne ammette
20.084; lookup `post_hashtags.PRIMARY`, poi 67.775 lookup `hashtags.PRIMARY`. Aggrega
14.857 tag e ordina per estrarre 10 risultati: `LIMIT 10` non limita il lavoro a monte.

Prefissi: **A, P**.

```sql
SELECT h.id, h.name, COUNT(*) AS post_count
FROM public_posts p
JOIN post_hashtags ph ON ph.post_id = p.id
JOIN hashtags h ON h.id = ph.hashtag_id
WHERE h.name <> ''
GROUP BY h.id, h.name
ORDER BY post_count DESC, h.name, h.id LIMIT 10
```

#### Q03 — Serie settimanale di 10 hashtag selezionati

Il planner parte dai post con `posts_popular_remote_actor_index`, poi usa la PK della
pivot per post e filtra gli ID. Non sceglie automaticamente
`post_hashtags_hashtag_id_index`, pur esistendo. La selezione dei top tag Q02/Q02B è un
costo separato.

Gli ID sono i top 10 restituiti da Q02, fissi per tutta la serie; nessuna nuova
selezione per settimana.

Prefissi: **A, P**.

```sql
SELECT DATE_SUB(DATE(p.published_at), INTERVAL WEEKDAY(p.published_at) DAY) AS week_start,
       ph.hashtag_id, COUNT(*) AS post_count
FROM public_posts p JOIN post_hashtags ph ON ph.post_id = p.id
WHERE ph.hashtag_id IN (:tag1, :tag2, :tag3, :tag4, :tag5, :tag6, :tag7, :tag8, :tag9, :tag10)
GROUP BY week_start, ph.hashtag_id ORDER BY week_start, ph.hashtag_id
```

#### Q04 — Denominatore settimanale e volume locale/remoto

Scansione dei candidati via `posts_popular_remote_actor_index`, lookup autore sulla PK e
aggregazione temporanea. Riutilizzare questa risposta come denominatore di hashtag
normalizzati e quote degli allegati.

Prefissi: **A, P**.

```sql
SELECT DATE_SUB(DATE(published_at), INTERVAL WEEKDAY(published_at) DAY) AS week_start, is_local, COUNT(*) AS post_count
FROM public_posts
GROUP BY week_start, is_local ORDER BY week_start, is_local
```

#### Q05 — Lingue di post ed eventi, separate per tipo

Verificati entrambi i rami della UNION ALL: post via `posts_popular_remote_actor_index`
e PK Actor; eventi con scansione di 151 righe e PK Actor. Materializza 20.160 contributi
prima del raggruppamento. Le lingue restano codici completi (es. it e it-IT distinti
salvo normalizzazione successiva).

Prefissi: **A, P, E**.

```sql
SELECT kind, week_start, language, is_local, COUNT(*) AS content_count
FROM (
  SELECT 'post' AS kind, DATE_SUB(DATE(published_at), INTERVAL WEEKDAY(published_at) DAY) AS week_start,
         COALESCE(NULLIF(LOWER(TRIM(language)), ''), 'und') AS language, is_local
  FROM public_posts
  UNION ALL
  SELECT 'event', DATE_SUB(DATE(published_at), INTERVAL WEEKDAY(published_at) DAY),
         COALESCE(NULLIF(LOWER(TRIM(language)), ''), 'und'), is_local
  FROM public_events
) uses_by_language
GROUP BY kind, week_start, language, is_local
ORDER BY kind, week_start, language, is_local
```

#### Q06 — Scambi tra domini: risposte al destinatario effettivo

Scansione di 25.268 commenti; lookup sulla PK per post radice, autori e commento padre.
Il CTE viene in parte espanso dal planner, ripetendo i controlli del padre: nessuna
assunzione che un WITH venga materializzato una sola volta. 1.361 gruppi restituiti.

Prefissi: **A, P**.

```sql
,
public_comments AS (
  SELECT c.id, c.post_id, c.parent_comment_id, c.actor_id, c.created_at,
         ca.domain AS source_domain, p.domain AS root_domain
  FROM comments c JOIN public_posts p ON p.id = c.post_id
  JOIN eligible_actors ca ON ca.id = c.actor_id
  WHERE c.status = 'published'

), reply_edges AS (
  SELECT c.id, c.post_id, c.created_at, c.source_domain,
         CASE WHEN c.parent_comment_id IS NULL THEN c.root_domain
              ELSE pa.domain END AS target_domain
  FROM public_comments c
  LEFT JOIN public_comments parent ON parent.id = c.parent_comment_id
    AND parent.post_id = c.post_id
  LEFT JOIN eligible_actors pa ON pa.id = parent.actor_id
  WHERE c.parent_comment_id IS NULL OR parent.id IS NOT NULL
)
SELECT DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) AS week_start, source_domain, target_domain,
       COUNT(*) AS replies, COUNT(DISTINCT post_id) AS threads
FROM reply_edges
WHERE source_domain <> target_domain
  AND created_at >= '2024-10-22 15:00:00' AND created_at < '2026-10-22 15:00:00'
GROUP BY week_start, source_domain, target_domain
ORDER BY week_start, replies DESC, source_domain, target_domain
```

#### Q07 — Quota post con almeno una risposta di altro dominio

Il denominatore legge il corpus ammesso; restituire zero al posto di SUM NULL
se il corpus è vuoto. EXISTS usa `comments_post_id_created_at_index` per ciascuno dei
20.084 post, con lookup autore sulla PK. Non moltiplica i post per il numero di
risposte.

Qui si confronta il commentatore con l’autore del post radice; Q06 misura invece il
destinatario della singola risposta.

Prefissi: **A, P**.

```sql
SELECT COUNT(*) AS total_posts,
       SUM(EXISTS (
         SELECT 1 FROM comments c JOIN eligible_actors ca ON ca.id = c.actor_id
         WHERE c.post_id = p.id AND c.status = 'published'
           AND ca.domain <> p.domain
           AND c.created_at < '2026-10-22 15:00:00'
       )) AS posts_with_foreign_reply
FROM public_posts p
```

#### Q08 — Geografia: soli post

Parte dalle 52 righe di `post_locations`, poi lookup post e autore sulla PK: non
scandisce tutti i post per scoprire quelli geotaggati. Con i filtri attuali rimangono
soltanto 7 contributi, dunque necessarie soglie pubbliche.

Prefissi: **A, P**.

```sql
SELECT country, SUM(kind = 'post') AS posts_count,
       SUM(kind = 'event') AS events_count, COUNT(*) AS total
FROM (
  SELECT 'post' AS kind, COALESCE(NULLIF(UPPER(TRIM(l.country_code)), ''), 'UNKNOWN') AS country, p.id AS content_id
  FROM post_locations l JOIN public_posts p ON p.id = l.post_id
) locations
GROUP BY country ORDER BY total DESC, country
```

#### Q09 — Geografia: soli eventi

Scansione dei 151 eventi, poi lookup su `event_locations.PRIMARY` e `actors.PRIMARY`; 66
contributi ammessi. Il planner non usa gli indici temporali degli eventi su una tabella
così piccola.

Prefissi: **A, E**.

```sql
SELECT country, SUM(kind = 'post') AS posts_count,
       SUM(kind = 'event') AS events_count, COUNT(*) AS total
FROM (
  SELECT 'event' AS kind, COALESCE(NULLIF(UPPER(TRIM(l.country_code)), ''), 'UNKNOWN') AS country, e.id AS content_id
  FROM event_locations l JOIN public_events e ON e.id = l.event_id
) locations
GROUP BY country ORDER BY total DESC, country
```

#### Q10 — Geografia: post ed eventi insieme e distinti

Verificati separatamente i due rami UNION ALL: luoghi dei post → PK post/Actor; eventi →
PK luogo/Actor. Materializzazione finale di 73 contributi, senza deduplicare tra tipi
diversi.

Prefissi: **A, P, E**.

```sql
SELECT country, SUM(kind = 'post') AS posts_count,
       SUM(kind = 'event') AS events_count, COUNT(*) AS total
FROM (
  SELECT 'post' AS kind, COALESCE(NULLIF(UPPER(TRIM(l.country_code)), ''), 'UNKNOWN') AS country, p.id AS content_id
  FROM post_locations l JOIN public_posts p ON p.id = l.post_id
  UNION ALL
  SELECT 'event' AS kind, COALESCE(NULLIF(UPPER(TRIM(l.country_code)), ''), 'UNKNOWN') AS country, e.id AS content_id
  FROM event_locations l JOIN public_events e ON e.id = l.event_id
) locations
GROUP BY country ORDER BY total DESC, country
```

#### Q11 — Copertura dei geotag: denominatori per post ed eventi

Entrambi i rami UNION ALL controllati: il costo viene dal ramo post che legge il corpus,
non dai 141 luoghi evento. Anche una mappa economica può diventare più cara aggiungendo
il denominatore completo.

Prefissi: **A, P, E**.

```sql
SELECT 'post' AS kind, COUNT(*) AS total, COUNT(l.post_id) AS geotagged
FROM public_posts p LEFT JOIN post_locations l ON l.post_id = p.id
UNION ALL
SELECT 'event', COUNT(*), COUNT(l.event_id)
FROM public_events e LEFT JOIN event_locations l ON l.event_id = e.id
```

#### Q12 — Allegati immagine/video e post distinti, per settimana

Post via `posts_popular_remote_actor_index`; pivot via
`post_attachments_post_id_media_id_unique`, media via PK per 11.923 associazioni ammesse
prima del filtro MIME. COUNT DISTINCT impedisce di confondere numero di allegati e
numero di post. Nessun indice MIME usato o necessario per questo accesso dalla pivot.

Prefissi: **A, P**.

Implementazione sprint 6: conteggi condizionali in una sola riga per settimana,
senza divisioni locali/remoti. I post con entrambi i tipi contribuiscono alle due
categorie; `posts` conta invece ogni post una sola volta.

```sql
SELECT DATE_SUB(DATE(p.published_at), INTERVAL WEEKDAY(p.published_at) DAY) AS week,
       SUM(CASE WHEN LOWER(m.mime_type) LIKE 'image/%' THEN 1 ELSE 0 END) AS images,
       SUM(CASE WHEN LOWER(m.mime_type) LIKE 'video/%' THEN 1 ELSE 0 END) AS videos,
       COUNT(DISTINCT CASE WHEN LOWER(m.mime_type) LIKE 'image/%' THEN p.id END) AS image_posts,
       COUNT(DISTINCT CASE WHEN LOWER(m.mime_type) LIKE 'video/%' THEN p.id END) AS video_posts,
       COUNT(DISTINCT p.id) AS posts
FROM public_posts p
JOIN post_attachments att ON att.post_id = p.id
JOIN media m ON m.id = att.media_id
WHERE LOWER(m.mime_type) LIKE 'image/%' OR LOWER(m.mime_type) LIKE 'video/%'
GROUP BY week ORDER BY week
```

Query effettiva senza discoverable/indexable: 520,16 ms; EXPLAIN ANALYZE 620 ms.
Post tramite `posts_popular_remote_actor_index` (48.861 candidati), Actor via PK,
pivot tramite `post_attachments_post_id_media_id_unique`, media via PK per 32.832
associazioni, 32.651 file immagine/video ammessi su 77 settimane popolate. Il piano
ordina i post per settimana prima dei join; nessun nuovo indice MIME è giustificato
per questo percorso. Costo da coprire nella fase caching già prevista, senza
introdurre ora tabelle metriche. Piano completo nel registro EXPLAIN.


#### Q13 — Ritmo degli ultimi 15 giorni: 360 bucket orari

Range effettivo su `posts_visibility_status_published_at_index`: 1.356 post candidati,
non tutti i 49.502. 55 gruppi ora/origine presenti; completare i 360 bucket nel codice
soltanto per intervalli con copertura nota. Q13C mostra un carico più rappresentativo.

Estremo superiore comune 2026-10-22 15:00:00 escluso; riempimento degli intervalli
coperti senza risultati nel livello applicativo.

Prefissi: **A, P**.

```sql
SELECT DATE_FORMAT(published_at, '%Y-%m-%d %H:00:00') AS hour_start,
       is_local, COUNT(*) AS post_count
FROM public_posts
WHERE published_at >= '2026-10-07 15:00:00'
GROUP BY hour_start, is_local ORDER BY hour_start, is_local
```

#### Q14 — Domini autori dei post per settimana

Stesso accesso ai post di Q04; aggregazione per settimana e dominio e sort di 1.288
gruppi. Per grafici pubblici scegliere pochi domini sul periodo senza cambiare le serie
a ogni settimana.

Prefissi: **A, P**.

```sql
SELECT DATE_SUB(DATE(published_at), INTERVAL WEEKDAY(published_at) DAY) AS week_start, domain, COUNT(*) AS post_count
FROM public_posts
GROUP BY week_start, domain ORDER BY week_start, post_count DESC, domain
```

#### Q15 — Autori attivi per settimana

Post via `posts_popular_remote_actor_index`; COUNT DISTINCT degli autori per settimana.
Un contatore giornaliero di autori non è sommabile per ottenere questo risultato
settimanale.

Prefissi: **A, P**.

```sql
SELECT DATE_SUB(DATE(published_at), INTERVAL WEEKDAY(published_at) DAY) AS week_start, is_local, COUNT(DISTINCT actor_id) AS authors
FROM public_posts
GROUP BY week_start, is_local ORDER BY week_start, is_local
```

#### Q16 — Diffusione hashtag tra autori e domini

Accesso ai post e lookup `post_hashtags.PRIMARY` come Q03; DISTINCT separati per autore
e dominio. La variante Q16B testa l’indice inverso, ma non elimina la necessità di
evitare il ricalcolo per ogni visita.

Prefissi: **A, P**.

```sql
SELECT ph.hashtag_id, COUNT(*) AS post_count,
       COUNT(DISTINCT p.actor_id) AS authors, COUNT(DISTINCT p.domain) AS domains
FROM public_posts p JOIN post_hashtags ph ON ph.post_id = p.id
WHERE ph.hashtag_id IN (:tag1, :tag2, :tag3, :tag4, :tag5, :tag6, :tag7, :tag8, :tag9, :tag10)
GROUP BY ph.hashtag_id ORDER BY post_count DESC, ph.hashtag_id
```

#### Q17 — Eventi pubblici per mese di inizio e stato

Scansione dei 151 eventi, lookup autore sulla PK, aggregazione temporanea di 9 gruppi.
Campione piccolo: ripetere le misure prima di generalizzare a grandi cataloghi.

Eventi pubblicati nel periodo di osservazione; start_at può essere futuro. Cancellati
esclusi, senza fingere di ricostruire la loro storia.

Prefissi: **A, E**.

```sql
SELECT DATE_FORMAT(start_at, '%Y-%m-01') AS month_start, status,
       COUNT(*) AS events_count
FROM public_events
GROUP BY month_start, status ORDER BY month_start, status
```

#### Q18 — Community pubbliche attive per settimana

La query comprende community locali, post pubblicati da Actor Group e rilanci
diretti (Announce `is_direct = 1`) dei Group locali e remoti. La settimana segue
la pubblicazione originale del post, non la ricezione del rilancio. Ogni Group
conta una volta per settimana e ogni post una volta, anche se rilanciato da più
community o collegato anche tramite `community_id`. Non si deduce che il Group
sia autore del contenuto rilanciato.

Prefissi: **A, P**. `eligible_groups` deriva da A con `type = 'group'`, escludendo
anche gli Actor collegati a community locali private.

```sql
SELECT week_start, COUNT(DISTINCT community_actor_id) AS active_communities,
       COUNT(DISTINCT post_id) AS posts_count
FROM (
  SELECT DATE_SUB(DATE(p.published_at), INTERVAL WEEKDAY(p.published_at) DAY) AS week_start,
         p.id AS post_id, g.id AS community_actor_id
  FROM public_posts p JOIN communities c ON c.id = p.community_id
  JOIN eligible_groups g ON g.id = c.actor_id WHERE c.is_private = 0
  UNION ALL
  SELECT DATE_SUB(DATE(p.published_at), INTERVAL WEEKDAY(p.published_at) DAY), p.id, g.id
  FROM public_posts p JOIN eligible_groups g ON g.id = p.actor_id
  UNION ALL
  SELECT DATE_SUB(DATE(p.published_at), INTERVAL WEEKDAY(p.published_at) DAY), p.id, g.id
  FROM public_posts p JOIN announces an ON an.post_id = p.id
  JOIN eligible_groups g ON g.id = an.actor_id WHERE an.is_direct = 1
) activity
GROUP BY week_start ORDER BY week_start
```

Correzione del 22 ottobre 2026: per questo aggregato anonimo `indexable`
non è un requisito, né per il Group né per l’autore del post. `public_posts`
e `eligible_groups` in Q18 applicano gli altri filtri A/P ma omettono
`a.indexable = 1`. Anche `discoverable` è stato successivamente rimosso.
Restano stato attivo, sospensioni, cancellazioni,
blocchi e visibilità pubblica, oltre ai filtri CW previsti dalla configurazione.
La stessa regola è ora applicata a tutte le statistiche aggregate.

Verifica MySQL della query effettiva corretta: richiesta 94,38 ms,
EXPLAIN ANALYZE 17,6 ms, 20 settimane non vuote, picco 17 Group attivi.
Ramo locale: una community, PK Actor e `posts_community_feed_timeline_index`,
una riga. Ramo pubblicazione Group: `actors_type_index` (34 Group, 33 ammessi),
PK autore e `posts_popular_remote_actor_index`, nessun post diretto nel campione.
Ramo Announce: `actors_type_index`, `communities_actor_id_unique`,
`announces_actor_id_post_id_unique` (1.151 rilanci), lookup PK post/autore,
958 righe ammesse. UNION materializza 959 righe prima della deduplicazione.
Nessuna scansione completa dei post nel piano osservato; costo compatibile
con questa fase senza cache sul campione attuale, da rivalutare con la crescita.
Test positivi preparati per la verifica finale, inclusa ammissione con
`indexable=false`. Nessuna fixture inserita nel DB applicativo.

#### Q19 — Quota di post con sondaggio per settimana

Post via `posts_popular_remote_actor_index`; LEFT JOIN su `polls_post_id_unique`, al
massimo un sondaggio per post. I pochi sondaggi non rendono economico il conteggio di
tutti i post senza sondaggio.

Prefissi: **A, P**.

```sql
SELECT DATE_SUB(DATE(p.published_at), INTERVAL WEEKDAY(p.published_at) DAY) AS week,
       COUNT(*) AS posts, COUNT(pol.id) AS polls
FROM public_posts p LEFT JOIN polls pol ON pol.post_id = p.id
GROUP BY week ORDER BY week
```

Implementazione sprint 6 senza filtri discoverable/indexable: 133,93 ms;
EXPLAIN ANALYZE 124 ms. `posts_popular_remote_actor_index`, Actor via PK e
`polls_post_id_unique` (lookup covering univoco), 48.860 post e 12 sondaggi.
Denominatore e numeratore nello stesso snapshot; quota totale 12/48.860,
non media delle quote settimanali. Nessun dato sui voti è letto. I bucket vuoti
sono trasmessi con conteggi zero ma visualizzati come percentuale assente.


#### Varianti e controllo degli indici

- **Q02B**, variante da preferire per il top hashtag: stesso prefisso A/P e
  corpo sotto. Riduce i join ai nomi; non elimina l'aggregazione del corpus.
- **Q03B/Q16B**: stessi SQL di Q03/Q16 sostituendo il join della pivot con
  `JOIN post_hashtags ph FORCE INDEX (post_hashtags_hashtag_id_index) ON ...`.
  Sono prove diagnostiche: non si propone di rendere obbligatorio il hint.
- **Q13B**: stesso SQL di Q13 con finestra 15–30 settembre 2026 alle 15:00 UTC;
  **Q13C**: finestra 29 agosto–13 settembre 2026 alle 00:00 UTC.
  In Q13C il planner usa `posts_popular_remote_actor_index` e legge 41.718
  candidati, ammettendone 16.757: non sceglie il range temporale selettivo di
  Q13. È un esempio concreto di piano diverso al cambiare della selettività.
- **Q13D**: prefisso A/P, corpo
  `SELECT DATE(published_at) AS day, COUNT(*) AS n FROM public_posts GROUP BY day ORDER BY day`;
  usato soltanto per individuare la finestra densa, sommando in Python i giorni
  negli intervalli semiaperti di 15 giorni. La scelta è sul campione ammesso.

```sql
,
tag_counts AS (
  SELECT ph.hashtag_id, COUNT(*) AS post_count
  FROM public_posts p JOIN post_hashtags ph ON ph.post_id = p.id
  GROUP BY ph.hashtag_id
)
SELECT h.id, h.name, tc.post_count
FROM tag_counts tc JOIN hashtags h ON h.id = tc.hashtag_id
WHERE h.name <> ''
ORDER BY tc.post_count DESC, h.name, h.id LIMIT 10
```

Nessun indice è stato aggiunto: l'obiettivo è misurare le query, non alterare
il database applicativo. I piani mostrano che l'indice temporale è usato per
la finestra breve, mentre sul periodo lungo il planner preferisce spesso
`posts_popular_remote_actor_index`, che ha `actor_id` prima di `published_at`:
la data filtra nell'index condition ma non limita un singolo range continuo
come in Q13. I lookup degli autori avvengono sulla PK, e le aggregate usano
spesso tabelle temporanee e sort.

Prima di una migration valutare prima riscrittura, periodo/serie limitati e
cache; poi confrontare piani su MySQL/MariaDB di destinazione con volumi e
selettività realistici, inclusi blocchi non vuoti, CW attivi e community popolate.
Un eventuale indice covering va giustificato con una nuova misura e con il
costo in spazio/scritture, non proposto perché manca il nome di un indice nel
piano. Le stime del planner sono talvolta molto inferiori alle righe effettive:
le conclusioni qui usano le righe effettive di ANALYZE.

## Esito della revisione critica e decisioni operative

La sequenza approvata è: **tutte le query disponibili senza cache e con UI;
nuovi dati e grafici di provenienza; cache come ultima fase**, con minimo di
cinque minuti. Il piano eseguibile è in
[ANALYTIC_STATS_SPRINTS.md](ANALYTIC_STATS_SPRINTS.md); le misure precedenti
restano evidenza tecnica, non un ordine di implementazione diverso.

Correzioni e limiti da mantenere durante lo sviluppo:

- Login richiesto a pagina e dataset, senza privilegi di staff. Nessun dato
  privato viene incluso per il solo fatto che il visitatore sia autenticato.
- La popolazione attuale non applica `discoverable` o `indexable`. I benchmark
  iniziali che li applicavano descrivono un campione più ristretto; le misure
  aggiornate sono nel registro EXPLAIN.
- Le 360 ore sono complete e ancorate all'inizio dell'ora UTC; manca una prova
  di copertura completa dei due anni. La prima fase descrive il corpus
  conservato e distingue gli zeri del corpus dall'assenza di osservazioni
  storiche. Non si presentano entrambi come «nessuna attività nel Fediverso».
- Q06/Q07 riguardano commenti consultabili secondo il modello applicativo,
  inclusi eventuali unlisted di cui non conserviamo l'audience, su radici
  strettamente public. La coorte è quella dei post radice del periodo;
  risposte a radici precedenti non sono comprese. Non è un grafo completo
  della federazione né una misura di tutte le risposte ricevute nel periodo.
- Q06 non deve reindirizzare al post radice una risposta il cui padre non è
  disponibile o ammissibile. Q07 deve usare zero per SUM su corpus vuoto.
- La geografia iniziale è per paese, con post, eventi e totale; mappa, città
  e regioni restano estensioni. I denominatori devono avere stessa popolazione,
  stesso intervallo e stessa generazione dei numeratori.
- Il campione Q18 non esercita il ramo dei post community: servono fixture
  rappresentative e nuovo piano MySQL prima di dichiararne le prestazioni.
- Le prove con CW disattivati e blocchi assenti non validano le policy attive:
  gli SQL implementati dovranno includerle e avere nuove misure, senza
  alleggerire i controlli per inseguire i tempi del benchmark.
- I bucket relay saranno dati durevoli della nuova metrica, introdotti nella
  seconda fase; non sono una cache anticipata e non vanno eliminati da
  «svuota cache». Il primo grafico conta nuove acquisizioni pubbliche riuscite,
  non traffico inbox o tutte le consegne duplicate.
- Cache e bucket non sostituiscono una politica di rettifica: se si vogliono
  correggere aggregati dopo moderazione o cambio di visibilità, conservare
  il contributo necessario a farlo. Non estendere la retention dei payload.

Non risultano domande bloccanti per il primo giro. Le estensioni su pubblico
anonimo, mappe dettagliate, audience originaria dei commenti, tutte le sorgenti
osservate e ricostruzioni retroattive complete restano fuori dal primo rilascio.
I punti tecnici sui relay vengono risolti e annotati prima della relativa
migration, nello sprint che consegna anche il grafico corrispondente.

## Riferimenti nel repository

- [Architettura di sviluppo](../development/architecture.it.md).
- [Analisi hashtag](HASHTAG.md) e [supporto relay](RELAY_SUPPORT.md).
- [Retention remota](REMOTE_POST_RETENTION.md).
- [Tendenze attuali](../app/Application/Queries/PopularHashtagsQuery.php).
- [Ingresso inbox](../app/Http/Controllers/Federation/InboxController.php) e
  [riconoscimento relay](../app/Federation/Inbox/RelayIngressResolver.php).
- [Modello inbox](../app/Federation/Inbox/InboxItem.php) e
  [pulizia database](../app/Application/Services/DatabaseMaintenanceService.php).

## Implementazione provenienza — sprint 7 (delivery best effort)

Semantica rivista con l’utente: contare delivery elaborate di post pubblici,
anche già noti. Niente deduplicazione per URI; relay diversi e consegne ripetute
contribuiscono ciascuno. I retry interni possono introdurre duplicazioni e gli
errori metriche possono perdere incrementi: il dato è esplicitamente best effort.

Tre tabelle: copertura, identità delle sorgenti e bucket giornalieri con PK
(day, source_id). Non sono conservati identificativi/hash dei post o dimensioni
autore/community. Il modello precedente di prima acquisizione è rimosso, incluso
il journal e ogni callback di ritiro del contributo. Blocchi, sospensioni e
cancellazioni successive non riscrivono lo storico. I controlli core d’ingresso
restano invariati, così come il confine pubblico/privato al conteggio.

```sql
SELECT DATE_SUB(b.day, INTERVAL WEEKDAY(b.day) DAY) AS week,
       s.id, s.kind, s.label, SUM(b.count) AS count
FROM stats_post_delivery_buckets b
JOIN stats_delivery_sources s ON s.id = b.source_id
WHERE b.day >= :from_day AND b.day <= :today_utc
GROUP BY week, s.id, s.kind, s.label
ORDER BY s.kind, s.id, week
```

Incrementi dopo il commit più esterno del post, su connessione dedicata e con
transazione propria. Catch Throwable silenzioso protegge sia la registrazione
sia il callback; nessun retry o errore statistico torna al flusso d’importazione.
Un rollback scarta il callback. Inbox conteggiato una volta per elaborazione
riuscita del post principale, inclusi Announce su oggetti già noti; niente
conteggio aggiuntivo per fetch/citazioni dipendenti. Recuperi espliciti e RSS
sono sorgenti distinte. Nessun backfill o scansione dei post alla migration.

Prove di isolamento, semantica e failure nel piano sprint 7; SQL e piani nel
registro EXPLAIN. Gli eventi rimangono alla fase successiva.
