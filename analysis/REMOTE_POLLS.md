# Sondaggi remoti — issue #120

Stato al 15 ottobre 2026: analisi di prodotto consolidata e piano degli sprint
revisionato; implementazione non avviata. Le scelte tecniche indicate sotto
verranno risolte negli sprint prima del codice che ne dipende.
Issue: https://github.com/insicd/openbook/issues/120

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

### Vincolo tecnico da verificare nello sprint 1

Il flusso attuale di `ActivityDelivery` usa `afterCommit()` e può saltare la
consegna quando manca un'inbox o la destinazione è bloccata. Riutilizzarlo da
solo non dimostra che voto e accodamento siano persistiti coerentemente:
un errore dopo il commit non annulla la scrittura già effettuata.

Lo sprint 1 deve quindi definire come rendere durevole l'intenzione di consegna
insieme al voto e recuperabile un eventuale accodamento interrotto, riutilizzando
le strutture esistenti dove possibile. Evitare una nuova infrastruttura di code
parallela. Prima della conferma devono essere verificati destinatario, endpoint
e blocchi; i test devono coprire anche i fallimenti al confine del commit, non
soltanto un'eccezione simulata prima del salvataggio.

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

## Punti di integrazione individuati

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

### Sprint 5 — Recupero storico e chiusura

Il comando `openbook:reprocess-inbox` include già tutte le attività ignored e
pending, indipendentemente dal tipo di oggetto: i sondaggi sono quindi già
riaccodabili. Valutare un filtro selettivo per Question e un'anteprima dei
candidati per recuperare lo storico senza riprocessare tutta l'inbox; mantenere
il comportamento preesistente quando il filtro non è specificato.

Verifica: test del comando con sondaggi e attività ordinarie mescolati,
preservazione di processed/failed, anteprima priva di effetti se introdotta e
replay ripetuto. Provare prima su una copia del campione locale. Verificare
piano e costo della selezione su MySQL, evitando assunzioni sull'indicizzazione
dei payload JSON. Chiudere con suite completa, regressioni dei post ordinari,
documentazione EN/IT, changelog secondo le regole e revisione dell'intero diff.

## Progettazione tecnica da completare negli sprint

Le decisioni di prodotto sono fissate. Le voci seguenti non sono approvazioni
pendenti: fanno parte del lavoro tecnico e vanno risolte prima di implementare
il comportamento interessato, documentando qui la soluzione e i relativi test.

### Sprint 1 — prima di persistenza e accodamento

- Schema per sondaggio, opzioni e voto definitivo; identità delle opzioni
  secondo il protocollo, snapshot del testo votato, vincoli univoci e strategia
  per la concorrenza tra voto e aggiornamento delle opzioni.
- Accodamento durevole per tutte le scelte multiple, identità stabili per i
  retry e recupero da errori al commit nel flusso federato esistente.
- Destinatario e formato del voto verificati sulle fonti primarie; annotare
  fonti e limiti di interoperabilità insieme alle fixture.

### Sprint 2 — prima di importazione e aggiornamenti

- Ordinamento degli Update, incluse scadenze modificate, chiusura esplicita e
  assenza di timestamp affidabili. Evitare che il replay di uno stato vecchio
  riapra un sondaggio già concluso o sostituisca risultati più recenti.
- Riconoscimento delle Note di voto e comportamento per documenti parziali o
  malformati, mantenendo distinti dati sconosciuti e valori esplicitamente zero.

Coinvolgere nuovamente l'utente soltanto se un limite verificato del protocollo
richiede di modificare una decisione di prodotto concordata.

## Esito della revisione architetturale

Revisione completa del documento effettuata prima del commit iniziale.
Precisati il confine tra decisioni di prodotto e progettazione tecnica, il
rischio dell'accodamento dopo commit, la destinazione privata delle attività
di voto e la separazione tra cache del sondaggio e scelte personali. Questi
punti rientrano negli sprint previsti e non richiedono nuove decisioni di
prodotto. Nessun codice applicativo, migration o dato locale modificato.
