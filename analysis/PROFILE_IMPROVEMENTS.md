# Profili Actor — analisi pre-implementazione

Aggiornato il 10 ottobre 2026.

Stato: priorità e macrofasi concordate; subsprint proposti e dettagli dei
requisiti da consolidare prima della rispettiva implementazione. Subsprint 1.1
completato e verificato. Nessun altro subsprint applicativo avviato.

Branch: `improve_profile`, creato dalla testa locale di
`multilanguage_support` al commit `8f42ea5`.

## 1. Problema e obiettivo

I documenti Actor federati possono contenere informazioni aggiuntive rispetto
ai dati essenziali di identità e instradamento. Openbook ne conserva un
sottoinsieme: alcuni profili remoti risultano quindi meno informativi rispetto
alla loro rappresentazione sull'istanza di origine. Anche i profili locali
possono contenere informazioni che non vengono pubblicate nel documento Actor.

L'obiettivo dell'analisi è identificare queste differenze, distinguere i
metadati di presentazione dalle proprietà che influenzano il comportamento e
scegliere interventi progressivi con un beneficio riconoscibile per l'utente.

Non è assunto come obiettivo il supporto indiscriminato di ogni estensione di
ogni software. La ricognizione non costituisce una matrice completa di
conformità ActivityPub e non misura ancora la frequenza dei campi nei payload
reali ricevuti dall'istanza.

## 2. Stato iniziale verificato (prima del subsprint 1.1)

### 2.1 Importazione dei profili remoti

`RemoteActorResolver::applyRemoteDocument()` è il percorso condiviso per
creazione e aggiornamento della cache degli Actor, anche a seguito di un
`Update` federato. Verifica la corrispondenza dell'identità del documento con
quella attesa e non permette di sovrascrivere un Actor locale.

Sono attualmente estratti e conservati:

- identità, username, nome visualizzato e bio;
- avatar (`icon`), copertina (`image`) ed emoji personalizzate;
- approvazione manuale dei follower;
- preferenze `discoverable` e `indexable`, usate nei percorsi di ricerca;
- data di pubblicazione del profilo e conteggi di follower/following quando
  forniti nei formati riconosciuti;
- chiave pubblica ed endpoint inbox, outbox, followers, following, shared inbox
  ed eventi.

Gli Actor `Group` sono distinti dalle persone. `Application` e `Service`
convergono nel tipo applicazione; `Organization` è accettato ma ricondotto al
tipo persona. La distinzione originale fra questi tipi non è interamente
conservata.

### 2.2 Presentazione remota

La pagina dell'Actor remoto mostra nome, handle, avatar, copertina, bio,
conteggi e data di creazione quando disponibili. La bio passa dalla
sanificazione esistente; nome e bio possono presentare emoji personalizzate.
Sono presenti indicazioni per gruppi, applicazioni e approvazione manuale dei
follower.

La pagina non presenta campi aggiuntivi del profilo, verifiche di collegamenti,
post fissati o indicazioni di migrazione e commemorazione.

### 2.3 Profili locali e pubblicazione

Il modello `Profile` conserva già `links` come elenco di etichette e URL. La
request attuale ammette al massimo quattro link, etichette fino a 50 caratteri
e URL fino a 255 caratteri. Il profilo web locale li mostra.

`ActorSerializer` non pubblica questi link come `attachment` di tipo
`PropertyValue`. Un serializer distinto, `MastodonAccountSerializer`, espone
dei `fields` per la rappresentazione API compatibile con Mastodon: questo non
equivale alla pubblicazione nel documento Actor ActivityPub.

I link locali non costituiscono già un modello di campi arbitrari: un valore
testuale come una professione o dei pronomi richiede una decisione sul modello
e sulla composizione del profilo.

## 3. Differenze individuate

| Ambito | Informazioni ricevibili | Comportamento attuale / differenza |
| --- | --- | --- |
| Campi aggiuntivi | `attachment` / `PropertyValue`, coppie nome-valore con testo o collegamenti | Non estratti nei profili remoti. I link locali non sono pubblicati in questa forma. |
| Verifica dei link | Collegamenti reciproci con `rel="me"` | Nessuna verifica dedicata. Mostrare un collegamento e verificarlo sono funzionalità distinte. |
| Contenuti in evidenza | Collection `featured` e `featuredTags` | Nessun recupero dedicato o ordinamento in evidenza sul profilo remoto. Un post può comunque essere importato attraverso i flussi ordinari. |
| Migrazione | `movedTo`, `alsoKnownAs` e attività `Move` | Nessuna gestione specifica; tema già destinato a interventi autonomi sulle migrazioni. |
| Stato remoto | `memorial`, `suspended` | Flag non interpretati come tali. L'upsert valido imposta attualmente lo stato attivo; la gestione di `Delete` è un percorso distinto. |
| Restrizioni Misskey | Richiesta di login e restrizioni sui contenuti precedenti a una soglia temporale | Le proprietà specifiche dell'Actor non sono applicate. La normale visibilità del singolo contenuto è un meccanismo distinto. |
| Formattazione Misskey | `_misskey_summary` in MFM | Viene usata la bio ordinaria; non sono riprodotti gli effetti MFM specifici. |
| Presentazione specifica | `isCat`, `_misskey_followedMessage`, `backgroundUrl` di Sharkey/Akkoma | Nessuna gestione dedicata. Lo sfondo è distinto dalla copertina già supportata. |

L'elenco descrive differenze tecniche. La valutazione preliminare della
sezione 6 non stabilisce che ogni campo debba essere adottato.

## 4. Candidati e confini da discutere

### 4.1 Campi aggiuntivi del profilo

Il beneficio atteso è conservare informazioni utili che oggi non compaiono
nel profilo remoto e rendere visibili alle altre istanze quelle dei profili
locali. La ricezione e la pubblicazione possono essere suddivise in passi
indipendenti, mantenendo coerente il significato dei dati.

La prima macrofase comprende importazione e visualizzazione dei campi remoti
testuali e dei link, pubblicazione dei link locali esistenti ed estensione
dell’editor locale ai campi testuali.

Prima dell'implementazione occorre decidere:
- limiti di quantità e lunghezza, ordine, campi vuoti e nomi duplicati;
- trattamento di HTML, collegamenti, emoji e valori non riconosciuti;
- sostituzione e rimozione dei campi durante gli aggiornamenti del profilo;
- comportamento per Actor di tipo diverso da persona;
- aggiornamento dei profili già in cache, senza assumere un backfill massivo.

### 4.2 Verifica dei collegamenti

È un candidato separabile dalla visualizzazione dei campi. Il sistema non
deve presentare un link come verificato soltanto perché il profilo remoto lo
dichiara. L'analisi dovrà definire verifica del collegamento reciproco,
scadenza o rivalidazione, rimozione del risultato e limiti dei fetch.

### 4.3 Contenuti in evidenza

Lettura delle collection e presentazione dei post fissati costituiscono un
intervento distinto dai campi del profilo. Occorre definire aggiornamenti,
rimozione dall'evidenza, ordinamento e rispetto della visibilità dei contenuti
recuperati. I post fissati non devono essere anteposti alla timeline: la
scelta di uno spazio dedicato nel layout precede lo sviluppo della vista.
Gli hashtag in evidenza sono previsti in una macrofase successiva.

### 4.4 Stati e aspettative di visibilità

Migrazione, sospensione, commemorazione e restrizioni Misskey non sono
semplici campi descrittivi. Devono essere valutati con i flussi di identità,
moderazione e accesso ai contenuti.

Le restrizioni Misskey sono estensioni specifiche, non regole universali
ActivityPub. Prima di adottarle occorre chiarire semantica temporale,
aggiornamento della cache e conseguenze sui contenuti già importati.

Il lavoro sulle migrazioni rimane distinto e va coordinato con i requisiti
già individuati per ricezione di `Move` e trasferimenti verso/da Openbook.

### 4.5 Personalizzazioni di presentazione

MFM, sfondi ed effetti specifici restano candidati da valutare, senza impegno
implementativo. Non devono introdurre dipendenze o renderer complessi senza
un beneficio concordato.

## 5. Vincoli trasversali

- Riutilizzare importazione, aggiornamento, serializzazione e viste esistenti;
  mantenere i controller sottili e non creare percorsi paralleli.
- Considerare contenuti e URL remoti come dati non fidati: mantenere escaping,
  sanificazione e protezioni dei fetch, comprese quelle contro SSRF.
- Preservare autorizzazione, visibilità, preferenze di ricerca e confini della
  federazione. I metadati non devono esporre contenuti riservati.
- Mantenere compatibilità con PHP 8.2 e shared hosting, senza nuovi servizi o
  worker permanenti obbligatori. Valutare esplicitamente nuove dipendenze.
- Verificare eventuali migrazioni su MySQL/MariaDB e SQLite; usare `EXPLAIN`
  per le query nontriviali e motivare eventuali nuovi indici.
- Localizzare la UI e mantenere allineata la documentazione italiana e inglese.
- Aggiungere test mirati ai comportamenti scelti e completare la suite prima
  della proposta di PR.

## 6. Matrice comparativa preliminare

### 6.1 Metodo

La valutazione usa tre fattori con peso uguale. Tutti i punteggi crescono nella
stessa direzione: un valore alto rende l'intervento più favorevole.

| Punteggio | Facilità di implementazione (effort inverso) | Coerenza con Openbook | Valore informativo |
| --- | --- | --- | --- |
| 1 | Molto impegnativa; coinvolge molti flussi o semantiche nuove | Poco compatibile con l'esperienza sobria e strutturata | Beneficio prevalentemente decorativo |
| 2 | Impegnativa; richiede più flussi e gestione operativa | Adattamento significativo o utilità marginale nel prodotto | Informazione accessoria |
| 3 | Media; comprende modello dati e UI circoscritti | Integrazione possibile con scelte di prodotto | Informazione utile in casi specifici |
| 4 | Contenuta; estende flussi esistenti con persistenza e UI limitate | Integrazione naturale con pochi adattamenti | Informazione rilevante per capire il profilo o fidarsi dei collegamenti |
| 5 | Molto contenuta; riutilizza dati e flussi già presenti | Pienamente coerente con l'esperienza attuale | Informazione essenziale per identità, comprensione o aspettative di accesso |

**Totale = facilità + coerenza + valore**, da 3 a 15. Il costo considera anche
aggiornamenti, rimozioni, test e documentazione, non soltanto il primo rendering.
I valori sono stime motivate dal codice e dai protocolli consultati, non
misurazioni né preventivi in ore. Il valore valuta l'informazione quando è
presente: non è stata ancora misurata la frequenza dei campi nel traffico reale.

### 6.2 Confronto

| ID | Intervento valutato | Facilità | Coerenza | Valore | Totale |
| --- | --- | ---: | ---: | ---: | ---: |
| P01 | Pubblicare i link locali esistenti come campi ActivityPub | 5 | 5 | 4 | **14** |
| P02 | Importare e mostrare campi aggiuntivi remoti, testuali e link | 4 | 5 | 5 | **14** |
| P03 | Consentire campi testuali arbitrari anche nei profili locali | 3 | 5 | 4 | **12** |
| P04 | Riconoscere e applicare lo stato remoto `suspended` | 2 | 5 | 5 | **12** |
| P05 | Conservare e mostrare lo stato commemorativo `memorial` | 4 | 5 | 3 | **12** |
| P06 | Mostrare i post remoti fissati sul profilo (`featured`) | 2 | 5 | 4 | **11** |
| P07 | Verificare i collegamenti reciproci (`rel="me"`) | 2 | 5 | 4 | **11** |
| P08 | Gestire identità e trasferimenti degli account | 1 | 5 | 5 | **11** |
| P09 | Applicare le restrizioni di visibilità dell'Actor Misskey | 1 | 5 | 5 | **11** |
| P10 | Mostrare gli hashtag in evidenza (`featuredTags`) | 3 | 4 | 3 | **10** |
| P11 | Conservare una distinzione più fedele dei tipi Actor | 2 | 5 | 3 | **10** |
| P12 | Mostrare il messaggio personalizzato di follow Misskey | 3 | 2 | 2 | **7** |
| P13 | Riprodurre gli effetti `isCat` | 4 | 1 | 1 | **6** |
| P14 | Mostrare lo sfondo aggiuntivo di Sharkey/Akkoma | 3 | 1 | 1 | **5** |
| P15 | Riprodurre la bio MFM e i suoi effetti specifici | 1 | 2 | 2 | **5** |

### 6.3 Motivazioni dell'effort e del valore

- **P01:** dati, form e aggiornamento locale esistono già. Serve completare la
  serializzazione, verificare aggiornamenti/rimozioni e compatibilità, senza
  introdurre nuovi campi compilabili. Rende disponibili agli altri siti
  informazioni già pubblicate sul profilo web.
- **P02:** richiede persistenza, estrazione, limiti e rendering sicuro. Si
  innesta nell'upsert condiviso e nella pagina remota esistenti; non richiede
  nuove collection o fetch dedicati. Recupera informazioni che possono essere
  del tutto assenti dalla bio, come contatti, attività e pronomi.
- **P03:** estende modello e form oltre gli URL, preservando i link già
  salvati e pubblicati. È coerente con P02, ma il beneficio dipende da ciò che
  gli utenti locali desiderano compilare.
- **P04:** leggere un booleano è semplice; applicare correttamente lo stato
  coinvolge profili, contenuti, follow, ripristino e aggiornamenti. Deve restare
  distinto dalla moderazione locale e non riattivare accidentalmente account
  bloccati o eliminati.
- **P05:** persistenza, aggiornamento e indicazione discreta nel profilo sono
  circoscritti. La valutazione riguarda il metadato commemorativo, senza
  dedurne nuove restrizioni di accesso o sanzioni automatiche.
- **P06:** richiede recupero della collection, cache, ordinamento, rimozione
  dall'evidenza e integrazione con la paginazione. L'utente ritrova ciò che
  l'autore considera introduttivo o importante.
- **P07:** richiede fetch protetti, interpretazione del collegamento di
  ritorno e rivalidazione; non basta un badge nel template. Il valore è la
  verifica del legame fra sito e account, non una certificazione della persona.
- **P08:** è un insieme di interventi, con verifiche di controllo delle
  identità e trasferimento dei follow. Il punteggio è indicativo del tema
  complessivo; le issue già individuate separano ricezione di Move e migrazioni
  verso/da Openbook. Non va assorbito implicitamente in questo ramo.
- **P09:** incide su accesso autenticato, follower, soglie temporali e dati già
  importati. Il valore riguarda le aspettative di accesso dell'autore;
  l'implementazione non può limitarsi a conservare i flag.
- **P10:** richiede una collection aggiuntiva ma può riutilizzare la
  navigazione degli hashtag. È utile per esplorare gli interessi dell'autore,
  meno essenziale dei suoi campi descrittivi.
- **P11:** la distinzione originale dei tipi può aiutare a riconoscere persone,
  organizzazioni e servizi. Cambiare i tipi usati dal dominio richiede però
  una revisione dei filtri, delle policy e delle assunzioni sugli actor; non è
  un semplice cambio di etichetta.
- **P12:** richiede salvataggio e integrazione sicura nelle notifiche di follow,
  evitando ripetizioni. Può aggiungere rumore senza informazioni essenziali.
- **P13:** l'effetto è relativamente circoscritto, ma non aggiunge informazioni
  utili all'esperienza strutturata del prodotto. Il costo basso non ne giustifica
  da solo l'introduzione.
- **P14:** modifica la presentazione oltre la copertina esistente, con verifiche
  di contrasto e resa mobile; beneficio informativo minimo.
- **P15:** richiede interpretazione e rendering di un formato ulteriore. La bio
  ordinaria è già disponibile, mentre animazioni ed effetti specifici sono poco
  coerenti con l'esperienza di Openbook.

### 6.4 Uso della matrice

La somma serve a confrontare i candidati, non è una regola automatica di rilascio.
La sequenza concordata è riportata nella sezione 7 e prevale sull'ordinamento
numerico. Un punteggio uguale non implica uguale urgenza né uguale costo.

## 7. Macrofasi e subsprint

L'ordine delle macrofasi è concordato. La suddivisione seguente definisce
incrementi verificabili; non implica una PR per ogni subsprint. I dettagli
funzionali e tecnici vengono consolidati prima del relativo intervento.

### Macrofase 1 — Campi aggiuntivi e campi testuali (P01–P03)

**Obiettivo:** rendere disponibili i campi descrittivi dei profili in entrambe
le direzioni della federazione, con una presentazione coerente in Openbook.

1. **1.1 — Pubblicazione dei link locali esistenti.** Esporre i link del profilo
   come campi ActivityPub, riutilizzando i dati attuali. Verificare modifiche e
   rimozioni, senza cambiare ancora il form di compilazione.
2. **1.2 — Campi dei profili remoti.** Importare, conservare e mostrare coppie
   nome-valore testuali e link. Definire limiti, ordine e rendering sicuro;
   gestire gli aggiornamenti e le rimozioni nel flusso condiviso degli Actor.
3. **1.3 — Campi testuali dei profili locali.** Estendere modello ed editor ai
   valori testuali, preservando i link esistenti. Allineare visualizzazione
   locale, documento Actor e rappresentazioni API interessate.

**Completamento:** campi locali pubblicabili e campi remoti leggibili, con
modifiche e rimozioni coerenti. Nessuna indicazione di verifica dei link fino
alla macrofase 3.

### Macrofase 2 — Stati remoti e post fissati (P04–P06)

**Obiettivo:** rappresentare gli stati significativi del profilo e rendere
accessibili i contenuti scelti dall'autore senza alterare la timeline.

1. **2.1 — Sospensione remota.** Definire e implementare gli effetti di
   `suspended` su profilo, contenuti e interazioni, incluso l'eventuale
   ripristino. Tenere distinti stato remoto, moderazione locale e cancellazione;
   impedire riattivazioni involontarie durante gli aggiornamenti.
2. **2.2 — Profilo commemorativo.** Conservare e aggiornare `memorial`, con
   un'indicazione discreta nel profilo. Non dedurre dal flag nuove sanzioni o
   restrizioni di accesso.
3. **2.3 — Scelta del layout dei post fissati.** Confrontare una sezione dedicata
   nel profilo e una scheda separata; scegliere posizione, ingombro e resa
   mobile. Vincolo acquisito: nessun inserimento in cima alla timeline.
4. **2.4 — Ricezione e visualizzazione dei post fissati.** Recuperare `featured`
   attraverso i flussi esistenti, conservare ordine e appartenenza alla
   collection e gestire rimozioni e indisponibilità. Integrare la soluzione
   scelta in 2.3 rispettando la visibilità dei post.

**Completamento:** sospensione e commemorazione correttamente distinguibili;
post fissati consultabili in uno spazio dedicato. La creazione di post fissati
locali non è compresa nel perimetro corrente.

### Macrofase 3 — Verifica dei collegamenti reciproci (P07)

**Obiettivo:** indicare quando un collegamento del profilo ha una relazione
reciproca verificata con l'account. La verifica riguarda il collegamento,
non l'identità personale del titolare.

1. **3.1 — Regole e verifica.** Definire i collegamenti ammissibili e il riscontro
   `rel="me"`; implementare controlli dei fetch e persistenza dell'esito senza
   fidarsi di dichiarazioni presenti nel payload remoto.
2. **3.2 — Ciclo di aggiornamento e UI.** Gestire rivalidazione, modifica e
   rimozione dei link; mostrare un'indicazione comprensibile e discreta nei
   profili locali e remoti, distinguendo assenza di verifica ed esito positivo.

**Completamento:** indicazioni basate su controlli effettivi e aggiornabili,
senza introdurre servizi permanenti obbligatori.

### Macrofase 4 — Hashtag in evidenza e tipi di Actor (P10–P11)

**Obiettivo:** migliorare l'esplorazione degli interessi dell'autore e la
comprensione della natura dell'account. I due filoni sono indipendenti.

1. **4.1 — Hashtag in evidenza.** Recuperare e aggiornare `featuredTags`,
   presentandoli nel profilo con accesso alla navigazione degli hashtag
   esistente. Gestire collection assenti, rimozioni e limiti di esposizione.
2. **4.2 — Analisi dei tipi di Actor.** Censire le assunzioni attuali su persone,
   gruppi e applicazioni; definire come conservare e rappresentare le
   distinzioni ulteriori, in particolare `Organization` e `Service`, senza
   modificare implicitamente autorizzazioni e capacità degli account.
3. **4.3 — Implementazione dei tipi di Actor.** Applicare le decisioni di 4.2
   a importazione, persistenza, filtri e indicazioni UI, verificando la
   compatibilità con gli Actor già presenti.

**Completamento:** interessi in evidenza consultabili e tipi di account
rappresentati in modo più fedele, con comportamento coerente nel dominio.

### Evoluzioni fuori dal percorso programmato

- **Trasferimenti e identità (P08):** esclusi da questo percorso; restano nelle
  issue dedicate alla ricezione dei Move e alle migrazioni verso/da Openbook.
- **Restrizioni di visibilità Misskey (P09):** tema ancora da collocare,
  non incluso implicitamente nelle macrofasi concordate. Richiede una decisione
  autonoma sugli effetti di accesso e sui contenuti già importati.
- **Personalizzazioni Misskey/Akkoma e simili (P12–P15):** nice to have a
  priorità bassissima, senza sprint programmati. Comprendono messaggi di follow
  personalizzati, effetti `isCat`, sfondi aggiuntivi e rendering MFM specifico.

### Verifica e chiusura dei subsprint

Ogni subsprint implementativo comprende test mirati, verifica dei flussi di
aggiornamento e rimozione, revisione del diff e aggiornamento della
documentazione interessata. Prima di proporre una PR si esegue la suite
completa e si aggiorna il changelog per le modifiche osservabili, secondo le
convenzioni del progetto. I subsprint di analisi e layout producono decisioni
concrete prima dell'avvio delle rispettive modifiche applicative.

### Avanzamento del subsprint 1.1

Implementazione del 10 ottobre 2026:

- i link locali già salvati vengono serializzati in `attachment` come
  `PropertyValue`, mantenendo etichetta e ordine, con URL sottoposti a escaping
  nel valore HTML;
- il contesto JSON-LD usa la mappatura schema.org compatibile con Mastodon;
- la rappresentazione HTML dei link è condivisa con il serializer dell'API
  compatibile Mastodon, senza cambiarne il risultato;
- il flusso esistente di `Update` del profilo pubblica anche modifiche e
  rimozioni dei link; un elenco vuoto produce `attachment: []`;
- nessuna modifica allo schema DB, ai form, alla UI o ai limiti dei link;
- nessun invio retroattivo massivo: i profili esistenti espongono i dati al
  successivo fetch dell'Actor o aggiornamento del profilo;
- test dedicati per documento Actor, contesto, ordine, escaping e aggiornamenti
  federati con sostituzione e rimozione dei link.

Verifica: test mirati superati (32 test, 150 asserzioni); suite completa con
1.394 test, 6.137 asserzioni e 2 test dell'installer MySQL saltati per server
di test non raggiungibile nell'ambiente. Controlli di stile Pint e revisione
del diff completati.

### Decisioni residue per i subsprint 1.2 e 1.3

- Consolidare limiti e trattamento dei valori dei campi aggiuntivi elencati
  nella sezione 4.1.
- Definire il modello dati compatibile con i link locali esistenti e la
  presentazione dei campi locali e remoti.
- Definire quando aggiornare i profili remoti già in cache, senza assumere
  un'importazione retroattiva massiva.

## 8. Riferimenti

### Codice verificato

- `app/Federation/Actors/RemoteActorResolver.php`
- `app/Federation/Actors/Actor.php` e `ActorEndpoint.php`
- `app/Federation/Serialization/ActorSerializer.php`
- `app/Federation/Serialization/MastodonAccountSerializer.php`
- `app/Domain/Profiles/Profile.php`
- `app/Http/Requests/Settings/UpdateProfileRequest.php`
- `resources/views/actors/show.blade.php`
- `resources/views/profile/show.blade.php`
- `app/Application/Queries/PeopleSearchQuery.php` e `LocalSearchQuery.php`

### Fonti primarie consultate

- [Mastodon: estensioni ActivityPub e metadati del profilo](https://docs.joinmastodon.org/spec/activitypub/#profile-metadata).
- [Mastodon: contenuti in evidenza](https://docs.joinmastodon.org/spec/activitypub/#featured-collection).
- [Mastodon: impostazione del profilo e verifica dei link](https://docs.joinmastodon.org/user/profile/).
- [Mastodon: migrazioni](https://docs.joinmastodon.org/user/moving/).
- [Misskey: estensioni ActivityPub](https://misskey-hub.net/ns/).
- [Akkoma: sfondi del profilo](https://docs.akkoma.dev/stable/development/ap_extensions/#user-profile-backgrounds).

Le fonti descrivono capacità delle rispettive implementazioni; la loro
presenza non implica supporto uniforme da parte di tutto il fediverso.
