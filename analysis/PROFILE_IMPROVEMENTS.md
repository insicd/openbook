# Profili Actor — analisi pre-implementazione

Aggiornato il 10 ottobre 2026.

Stato: priorità e macrofasi concordate; subsprint proposti e dettagli dei
requisiti da consolidare prima della rispettiva implementazione. Subsprint 1.1
e 1.2 completati e verificati. Subsprint 1.3 completato e verificato.
Subsprint 2.1, 2.2, 2.3 e 2.4 completati e verificati. Review complessiva delle
macrofasi 1 e 2 completata; il prossimo subsprint è 3.1.

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

### Decisioni consolidate per il subsprint 2.1

La sospensione dichiarata dall'istanza di origine viene conservata in un
attributo dedicato `actors.remote_suspended`, separato da `actors.status`.
Un documento Actor valido importato tramite refresh o Update aggiorna il flag;
assenza del flag equivale a false, come per un profilo ordinario. Errori di
recupero o documenti invalidi non modificano lo stato conosciuto. Un aggiornamento
remoto non riattiva Actor bloccati, sospesi o cancellati localmente.

Il profilo mostra «Account sospeso sul server di origine». Sono impedite nuove
interazioni locali verso l'Actor e i suoi contenuti: follow, messaggi, like,
condivisioni/citazioni, commenti e partecipazioni agli eventi. Le rimozioni di
relazioni o reazioni precedenti restano possibili per consentire all'utente di
ritirarle; non viene avviata una cancellazione automatica di follow o contenuti.
Le funzioni di consultazione e segnalazione restano disponibili secondo le
regole di visibilità esistenti. Le risposte a commenti sospesi sono bloccate
anche quando il post o evento originario è di un altro autore.

La riattivazione dichiarata dal server di origine ripristina le interazioni,
senza annullare eventuali blocchi o altre decisioni di moderazione locale.
La sospensione non cancella i contenuti importati, non ne modifica la visibilità
e non trasforma il profilo in un account cancellato. Il flusso in ingresso di
Update/Delete/Undo resta operativo; non viene introdotta una nuova politica di
ban del mittente federato. Nessun worker o servizio esterno necessario.

### Esito del subsprint 2.1

Implementati importazione e aggiornamento del flag, indicazione nel profilo e
controlli condivisi sulle nuove interazioni locali, inclusi i form di risposta
ai commenti. Il resolver conserva gli stati di moderazione esistenti durante
ogni aggiornamento remoto. I contenuti rimangono consultabili e le relazioni
non vengono eliminate automaticamente. La preferenza di condivisione automatica
resta conservata durante la pausa.

Migrazione della colonna boolean `remote_suspended`, con default false, applicata
con successo sul MySQL locale e verificata dalla suite SQLite. Non sono state
modificate query di scansione, ordinamento o paginazione: il flag viene letto
sugli Actor già caricati e non richiede un nuovo indice. Nessuna scansione o
ripopolamento dell'inbox storica; i profili acquisiscono il dato tramite i
refresh ordinari (TTL attuale) e gli Update ricevuti.

Verifiche finali: 1.432 test, 6.355 asserzioni, nessun fallimento; due test
dell'installer MySQL saltati per server di test non disponibile. I 16 nuovi test
coprono flag semplici/JSON-LD, Update e riattivazione, conservazione della
moderazione locale, errori di fetch e identità discordanti, profili e contenuti
consultabili, richieste dirette respinte, citazioni, commenti su post/eventi
altrui e pausa/ripresa delle condivisioni automatiche. Pint e controllo diff
superati. Documentazione bilingue e changelog aggiornati.

Riferimenti del comportamento remoto: [flag suspended di Mastodon](https://docs.joinmastodon.org/spec/activitypub/#suspended-flag)
e [serializzazione dell'Actor](https://github.com/mastodon/mastodon/blob/main/app/serializers/activitypub/actor_serializer.rb).
Il divieto delle nuove interazioni e la conservazione dei contenuti sono scelte
di prodotto Openbook, non una cancellazione imposta dal flag del protocollo.

### Decisioni consolidate per il subsprint 2.2

Il flag remoto `memorial` è informativo e viene conservato come boolean in
`actors.memorial`, con default false. L'importazione usa il medesimo flusso
Actor per fetch, refresh e Update, incluse le forme booleane JSON-LD già
supportate. Un documento valido senza flag o con false rimuove l'indicazione;
fetch falliti o documenti invalidi non modificano il dato conosciuto.

La pagina del profilo remoto mostra «Profilo commemorativo» vicino al nome e
all'handle, usando lo stile discreto dei badge esistenti. Il flag non modifica
follow, approvazione delle richieste, messaggi, like, commenti o condivisioni.
Gli eventuali stati di sospensione remota e moderazione locale continuano ad
applicarsi indipendentemente. Nessuna modifica alla visibilità o cancellazione
dei contenuti e nessuna gestione dei profili commemorativi locali in questo
subsprint. Verifica con test mirati; suite completa rinviata alla review finale.

### Esito del subsprint 2.2

Implementati persistenza di `actors.memorial`, aggiornamento tramite il resolver
Actor condiviso e badge localizzato nel profilo remoto. Nessun controllo di
interazione dipende dal flag commemorativo. La colonna boolean con default
false è stata aggiunta con una migrazione applicata sul MySQL locale e verificata
su SQLite; non sono cambiate query o indici.

Tre test nuovi verificano importazione/refresh e forme JSON-LD, false/assenza,
errori di fetch e documenti discordanti, Update ricevuti e indipendenza dagli
stati di sospensione/moderazione, visualizzazione/rimozione del badge e follow,
like, risposte e permessi dei messaggi. Test mirati eseguiti: `MemorialProfileTest`,
`RemoteActorResolverTest`, `ActorProfileTest`, `RemoteSuspensionTest`: 61 test,
269 asserzioni, nessun fallimento. Pint e controllo diff superati. Suite
completa intenzionalmente rinviata alla review finale come concordato.
Documentazione di federazione italiano/inglese e changelog aggiornati.

### Decisioni consolidate per i subsprint 2.3 e 2.4

Il profilo remoto presenta i tab «Post», «Post fissati», «Foto e video» in
quest'ordine, seguiti dagli altri tab esistenti. Il tab dei fissati compare
soltanto quando almeno un post importato risulta visibile al lettore; la timeline
ordinaria mantiene l'ordinamento attuale. Accesso diretto a una scheda vuota:
stato vuoto, senza contenuti riservati.

L'endpoint `featured` viene conservato tra gli endpoint Actor. Una cache JSON
limitata conserva in `actors` gli identificativi dei post nell'ordine ricevuto,
con timestamp dell'ultimo tentativo. Non occorre una relazione interrogabile
trasversalmente: l'elenco è piccolo e appartiene soltanto al profilo. Il recupero
riusa HTTP sicuro, firma federata e importazione dei post dell'outbox, senza
notifiche o nuove dipendenze. TTL uguale alla cache dei post (default 6 ore),
limite 20 elementi e 3 pagine per visita. Gli Add/Remove autenticati verso la
collection dichiarata invalidano la cache; un cambio o rimozione dell'endpoint
la azzera. Errori di collection preservano l'ultimo elenco valido.

Sono importabili gli originali pubblici/non elencati dell'autore, nei tipi già
supportati; risposte, contenuti privati, oggetti di altri autori e post cancellati
non vengono trasformati in nuovi post. La lettura applica inoltre le regole
ordinarie di visibilità, incluse le community private. Rimozioni dalla collection
non cancellano il post dalla cache generale. Collection inline, riferimenti URI,
`items`/`orderedItems` e pagine `first`/`next` vengono gestiti entro i limiti;
una collection più ampia viene rappresentata dai primi 20 elementi. La barra
dei tab conserva lo scorrimento orizzontale; l'allineamento sicuro evita di
tagliare il primo tab quando lo spazio mobile non basta.

### Esito dei subsprint 2.3 e 2.4

Implementati endpoint `featured`, cache ordinata degli identificativi dei post,
recupero limitato e tab dedicato con le card esistenti. La query applica visibilità,
esclusione dei messaggi privati e controllo dell'autore; l'ordinamento della
collection viene ricostruito in memoria su al massimo 20 post. Add/Remove ricevuti
invalidano soltanto la collection del firmatario. Nessuna modifica ai fissati
locali o al criterio di ordinamento della timeline.

Migrazione applicata sul MySQL locale e verificata dai test SQLite. EXPLAIN su
20 post reali: range sulla chiave primaria `posts`, lookup sugli indici esistenti
`communities.PRIMARY`, `follows_follower_id_following_id_unique` e
`mentions_target_actor_unique`; non sono necessari nuovi indici. Prova reale:
refresh del profilo `gubi@sociale.network` e importazione di 5 post fissati dalla
collection dichiarata. Verifica visiva interattiva non completata: il browser
integrato richiede login e non è disponibile accesso al browser nativo.

Test mirati: `RemoteFeaturedPostsTest`, `RemoteOutboxFetcherTest`,
`RemoteActorResolverTest`, `ActorProfileTest`, `RemoteSuspensionTest`,
`InboxActivityProcessorTest` e `ProfileTest`: 135 test, 598 asserzioni, superati.
Nove test nuovi coprono ordine, pagine/riferimenti, limiti, firma, TTL/errori,
rimozioni, endpoint, invalidazione autenticata, visibilità e community private.
Suite completa rinviata alla review finale. Documentazione bilingue e changelog
aggiornati.

### Review complessiva delle macrofasi 1 e 2

Revisione dell'intero diff rispetto alla base `8f42ea5`: responsabilità dei
servizi, riuso dei flussi di importazione e serializzazione, compatibilità con
i campi locali preesistenti, sanificazione e limiti degli input, autorizzazioni,
visibilità dei contenuti e conservazione della moderazione locale. Nessun
problema bloccante individuato. Rimossi un controllo duplicato nella card dei
commenti agli eventi e un riferimento documentale a uno sprint già concluso;
completata la tipizzazione del servizio di condivisione automatica.

Suite completa: 1.444 test, 6.428 asserzioni, nessun errore; saltati soltanto i
due test dell'installer che richiedono un database MySQL di test dedicato.
Pint supera il controllo su tutti i file PHP modificati dal ramo; nessun errore
di whitespace. Riconfermato sul MySQL locale il piano della query dei fissati:
range sulla chiave primaria dei post e lookup sugli indici esistenti delle
relazioni, senza scansioni complete o necessità di nuovi indici.

Documentazione inglese e italiana e changelog risultano coerenti con il
perimetro implementato. Le note di rilascio sono organizzate per funzionalità.
La review non introduce verifica `rel="me"`, gestione dei fissati locali o
migrazione di `profiles`: restano nei rispettivi perimetri successivi.

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

### Requisiti del subsprint 1.2

- Nuova colonna nullable JSON `actors.links`, riservata in questo intervento
  ai campi remoti. Nessuna copia o sincronizzazione dei link locali.
- Struttura: elenco ordinato di coppie `label`/`value`; il valore conserva
  testo e collegamenti nella forma sicura già usata dalla pipeline remota,
  senza conservare HTML arbitrario. Le etichette sono testo semplice.
- Importazione dei soli attachment `PropertyValue`, anche in forma di oggetto
  singolo. Ignorare attachment di altro tipo e valori non stringa o vuoti.
  Conservare ordine ed etichette ripetute.
- Limiti applicativi: 16 campi, 100 caratteri per etichetta e 1.000 per valore
  normalizzato, con limiti anche agli input elaborati. Sono limiti del sistema,
  non vincoli dello standard ActivityPub.
- Ogni documento Actor valido sostituisce l'elenco precedente: attachment
  assente, vuoto o senza campi validi azzera i dati precedentemente importati.
  Documenti non validi o con identità incongruente non devono modificarli.
- Visualizzazione sotto la bio, come coppie etichetta/valore, nella pagina
  remota già protetta da autenticazione. Nessuna verifica `rel="me"`, né
  riproduzione di media, effetti o emoji personalizzate nei campi.
- Profili già in cache aggiornati dai normali fetch o dagli Update ricevuti,
  senza recupero massivo o nuove richieste dedicate ai campi.
- Migrazione senza query di scansione/backfill e senza nuovo indice: lettura
  dalla riga Actor già individuata. Verificare lo schema su SQLite e MySQL.

### Avanzamento del subsprint 1.2

Implementazione del 10 ottobre 2026:

- aggiunta colonna JSON nullable `actors.links` e cast sul modello;
- estrazione dei campi nel percorso condiviso `applyRemoteDocument`, usato
  da fetch e Update Actor, senza modificare la fonte dei profili locali;
- normalizzazione tramite la pipeline remota esistente e visualizzazione
  sotto la bio come elenco etichetta/valore, con layout adattabile e label
  accessibile localizzata;
- nessun fetch dedicato ai soli campi, backfill o nuovo indice;
- test mirati: 85 test, 327 asserzioni, inclusi ordine, campi ripetuti, limiti,
  input malformati, link non sicuri, refresh, Update e protezione Actor locali;
- suite completa: 1.402 test, 6.166 asserzioni, nessun errore e 2 test
  dell'installer MySQL saltati per server di test non raggiungibile;
- migrazione verificata su MySQL locale con tabella di prova isolata tramite
  prefisso casuale, rimossa al termine: up/down, dati preesistenti, valori NULL
  e JSON Unicode. Verifica SQLite inclusa nella suite;
- nuova migrazione applicata al database locale, senza elaborare o recuperare
  retroattivamente i profili remoti;
- controlli Pint e revisione del diff completati; documentazione bilingue
  e voce di changelog della macrofase aggiornate.

### Riscontro reale — refresh del profilo

Verifica del 10 ottobre 2026 su `@skeybu@mastodon.uno`: il documento remoto
restituisce quattro campi validi (Website, Pixelfed, Gravatar, Medium), tutti
correttamente estratti da `RemoteProfileFields`. In cache locale `links` era
NULL e l'ultimo recupero del documento Actor risaliva all'11 settembre 2026,
mentre outbox e collection erano state recuperate il 9 ottobre.

La visita tramite `ActorProfileController` aggiornava outbox e collection,
ma non richiedeva normalmente un refresh del documento Actor anche a TTL
scaduto. Correzione: prima del recupero dei contenuti, i profili remoti attivi
passano anche da `RemoteActorResolver::resolveByUri`, che riusa la cache fresca
o recupera il documento scaduto. Gli errori lasciano consultabile la copia
in cache. I feed e gli Actor non attivi sono esclusi dal nuovo passaggio;
nessuna riattivazione viene richiesta dalla visita del profilo.

Test dedicati per refresh e visualizzazione nella stessa visita, riuso della
cache fresca, fallback dopo risposta 503 e mancato refresh degli Actor sospesi.
Verifica reale del resolver su skeybu completata: i quattro campi sono stati
importati nella cache locale attraverso il normale flusso di risoluzione.
Verifica della correzione: 47 test mirati e 151 asserzioni; suite completa
con 1.405 test, 6.185 asserzioni e nessun errore (2 test installer MySQL
saltati). Controlli di stile e revisione del diff superati.

### Vincolo dell'ambiente locale — authorized fetch

Verifica del 10 ottobre 2026 su `@arstechnica@mastodon.social`: il documento
Actor restituisce HTTP 401. Il GET anonimo riceve `Request not signed`; il
GET firmato viene rifiutato perché l'identità firmataria locale espone la
chiave su `http://127.0.0.1:8000/users/skeyby#main-key`, un indirizzo privato
che mastodon.social non può interrogare per verificare la firma.

Il record locale conserva quindi i dati precedenti (`links` NULL e ultimo
fetch dell'Actor del 12 settembre), mentre il tentativo di recuperare outbox
e collection aggiorna separatamente i rispettivi timestamp. Questo caso
non prova un errore di estrazione dei campi: il documento Actor non viene
restituito dal server remoto. Per una verifica end-to-end su istanze con
fetch autenticato, l'identità firmataria e la sua chiave pubblica devono
essere raggiungibili dal server remoto. Nessuna configurazione o dato è
stato modificato durante questa verifica.

### Requisiti concordati per il subsprint 1.3

- Due sezioni nell'editor: **Link** e **Informazioni aggiuntive**, entrambe
  con coppie etichetta/valore e pulsanti per aggiungere e rimuovere righe.
- Limite complessivo di **8 campi**, condiviso tra le sezioni. È una scelta
  di prodotto Openbook, non un vincolo del protocollo.
- La sezione Link accetta URL validi HTTP/HTTPS; Informazioni aggiuntive
  accetta testo libero. I link già salvati vengono preservati.
- Persistenza in un unico elenco sul profilo locale, senza duplicazioni in
  `actors.links` e senza spostare `profiles` in `actors`.
- Alla riapertura dell'editor, un valore che costituisce un URL valido
  HTTP/HTTPS viene presentato nei Link; gli altri valori nelle Informazioni
  aggiuntive. Non basta un controllo del solo prefisso `http`.
- Un URL inserito nelle Informazioni aggiuntive viene quindi riclassificato
  nei Link alla riapertura dell'editor.
- Visualizzazione pubblica e serializzazione ActivityPub gestiscono coppie
  etichetta/valore testuali o collegamenti. La separazione del form guida la
  compilazione e non introduce due categorie federate distinte.

Decisioni implementative: nuovi salvataggi in `profiles.links` come coppie
`label`/`value`, con lettura compatibile dei vecchi `label`/`url` e senza
migrazione massiva. Etichette fino a 50 caratteri, URL HTTP/HTTPS fino a 255,
testo fino a 1.000. Al salvataggio, ordine dei Link seguito da quello delle
Informazioni aggiuntive, mantenendo l'ordine interno di ciascuna sezione.
Il tipo della sezione serve solo alla validazione del form, non è persistito.
Valori testuali resi come testo semplice con escaping, senza Markdown o HTML.
Le righe completamente vuote vengono ignorate; quelle parziali generano errori.

### Esito del subsprint 1.3

Editor dinamico implementato con due sezioni e limite condiviso, validazione
server e lettura dei dati preesistenti. Il profilo pubblico, il documento Actor,
le attività Update e la rappresentazione dei campi nell'API compatibile Mastodon
usano i nuovi valori testuali e i link. Nessuna migrazione o nuova dipendenza
di runtime. Documentazione bilingue e voce unitaria del changelog aggiornate.

Verifiche: suite completa con 1.416 test, 6.243 asserzioni e nessun fallimento;
due test dell'installer MySQL saltati per server di test non disponibile.
Verifica browser sul partial Blade e sugli asset di produzione a 1280 e 375 px:
aggiunta/rimozione, limite condiviso, indici univoci, valori preesistenti e
assenza di overflow orizzontale. Test mirati coprono validazione, compatibilità,
riapertura del form, escaping e pubblicazione federata.

Il salvataggio del profilo pubblico torna alla pagina di visualizzazione,
preservando il messaggio di conferma. Verifica successiva della modifica:
27 test mirati e 105 asserzioni senza fallimenti.

## 8. Conservazione dei campi — decisione e unificazione futura

### 8.1 Decisione per il perimetro corrente

La separazione attuale fra `profiles` e `actors` viene mantenuta. Il subsprint
1.2 ha aggiunto `actors.links` per conservare i campi aggiuntivi remoti e ne
ha implementato importazione, aggiornamento, rimozione e visualizzazione.

- I link locali restano autorevoli in `profiles.links`, con i flussi di
  pubblicazione completati nel subsprint 1.1 e l’editor esteso nel subsprint 1.3.
- Non vengono copiati o sincronizzati su `actors.links` i link locali.
- Nome, bio, avatar e copertina mantengono persistenza e comportamento attuali.
- Il nome della nuova colonna è `actors.links`; la struttura dei valori remoti,
  testuali e collegamenti, è costituita da coppie `label`/`value`.
- L'estensione locale ai campi testuali è completata nel subsprint 1.3 sul
  modello locale, senza dipendere dall'unificazione delle tabelle.

Lo spostamento di `profiles` in `actors` è rinviato a un task dedicato e non
costituisce un prerequisito di 1.2 o 1.3. Le sezioni successive conservano la
ricognizione come materiale preparatorio per quel task: non sono un piano di
implementazione approvato per il ramo corrente.

### 8.2 Inventario verificato e corrispondenze

`profiles` contiene soltanto i cinque dati pubblici elencati di seguito, oltre
a `id`, `user_id`, `created_at` e `updated_at`. Non contiene credenziali,
preferenze dell'account o dati privati da preservare in un contenitore separato.

| Fonte locale attuale | Destinazione candidata | Differenza da gestire |
| --- | --- | --- |
| `profiles.links` | Nuovo `actors.links` JSON | Nessuna colonna corrispondente esiste oggi. I link locali sono coppie `label`/`url`; i campi remoti possono avere valori testuali e HTML. |
| `profiles.display_name` | `actors.name` | Il nome è già copiato da registrazione e aggiornamento, ma `Actor::displayName()` legge ancora il profilo locale. |
| `profiles.bio` | `actors.summary` | La bio locale è testo/Markdown; il valore remoto è HTML non fidato. Un'unica colonna non implica un unico trattamento di rendering. |
| `profiles.avatar_path` | Nuovo `actors.avatar_path` | `actors.icon_url` conserva un URL remoto, non il percorso di un upload gestito dall'istanza. |
| `profiles.cover_path` | Nuovo `actors.cover_path` | `actors.image_url` ha la stessa differenza rispetto al percorso della copertina locale. |

Gli identificativi e i timestamp della riga `profiles` non hanno un equivalente
funzionale da copiare sui dati pubblici. `actors.created_at` non deve essere
sovrascritto con la data del profilo. Se occorre conservare una data specifica
di modifica del profilo, va definita separatamente, senza equipararla a
`actors.updated_at`, modificato anche da altri flussi.

### 8.3 Ipotesi futura — Spostamento dei soli link

Intervento circoscritto, con effort relativo basso rispetto all'unificazione
completa:

1. Aggiungere `actors.links` e il relativo cast, quindi copiare i link dei
   profili sugli Actor locali corrispondenti tramite `user_id`.
2. Adeguare `ProfileUpdater`, impostazioni, vista del profilo locale,
   `ActorSerializer` e `MastodonAccountSerializer` alla nuova fonte.
3. Adeguare fixture e test; rimuovere `profiles.links` quando tutte le letture
   e scritture sono state trasferite e la migrazione dati è verificata.
4. Proseguire con importazione e visualizzazione dei campi remoti, quindi
   estensione dell'editor locale ai valori testuali.

Il form mantiene inizialmente gli stessi nomi, limiti e comportamento.
`profiles` resta necessario per nome, bio e immagini: questa opzione unifica
soltanto i campi aggiuntivi, non l'intero profilo.

**Contratto JSON da consolidare:** il nome `links` è appropriato ai dati
attuali, ma meno espressivo se conterrà anche campi testuali. Prima della
migrazione scegliere fra mantenerlo per tutti i campi oppure usare un nome
come `profile_fields`. Scegliere anche una struttura comune che rappresenti
valori testuali e link senza obbligare a memorizzare HTML locale. Evitare una
prima struttura remota incompatibile con la successiva estensione locale.
La scelta di un nome più generale non comporta un sottosistema aggiuntivo.

### 8.4 Ipotesi futura — Unificare tutti i dati pubblici del profilo

Obiettivo architetturale plausibile e circoscritto ai cinque dati pubblici,
con effort relativo medio e una superficie di regressione più ampia.

- Nome e bio diventano autorevoli su `Actor`; per i locali l'input continua
  a essere testo/Markdown, per i remoti conserva il trattamento sicuro già
  previsto per i documenti federati. Anche la serializzazione deve rispettare
  queste differenze, evitando doppio rendering o pubblicazione di HTML remoto
  non sanificato attraverso nuovi percorsi.
- I percorsi di avatar e copertina locali passano su `Actor`; gli URL remoti
  restano in `icon_url` e `image_url`. `avatarUrl()` e `coverUrl()` risolvono la
  sorgente corretta. I path sono metadati di gestione del file, non una copia
  sincronizzata dell'URL; non si memorizza un URL locale derivato da APP_URL.
- `ProfileUpdater` aggiorna la fonte unica attraverso il servizio applicativo;
  `AccountRegistrar` crea il profilo pubblico direttamente sull'Actor.
- Dopo la conversione di tutte le dipendenze, `Profile`, `User::profile()` e
  la tabella `profiles` possono essere eliminati. Non serve una tabella vuota
  né una facade permanente che simuli la vecchia persistenza.

L'impatto verificato comprende:

- helper di presentazione su `Actor`, entrambi i serializer e gestione upload;
- impostazioni, profilo pubblico, staff/suggerimenti, amministrazione;
- ricerca persone e suggerimenti per bio: `PeopleSearchQuery` e
  `SuggestedActorsByBioQuery` leggono o ordinano esplicitamente su `profiles`;
- eager loading `user.profile` in feed, commenti, eventi, notifiche, messaggi,
  follow, risoluzione Actor e altre viste;
- registrazione, factory/fixture, test e documentazione architetturale.

I riferimenti caricati con `user.profile` non implicano altrettanti comportamenti
indipendenti da riscrivere, ma vanno rimossi o adattati una volta eliminata la
relazione. L'eliminazione dei join deve mantenere visibilità, filtri e ranking:
non deve ampliare implicitamente la ricerca alle bio remote, oggi trattate in
modo diverso dalla ricerca locale. Verificare i nuovi piani MySQL con `EXPLAIN`.

Il modello e i commenti architetturali oggi descrivono `Actor` come
rappresentazione federata e `Profile` come dominio locale. L'opzione B modifica
esplicitamente questo confine di persistenza: aggiornare la documentazione e
le responsabilità dichiarate, conservando la logica applicativa nei servizi.
Non spostare account, credenziali, preferenze o notifiche dentro `Actor`.

### 8.5 Vincoli per l’eventuale migrazione futura

- Usare nuove migrazioni, senza riscrivere quelle storiche. Preparare schema e
  copia dati prima della rimozione delle colonne o della tabella precedente.
- La fonte autorevole per i locali è il profilo: conservare anche valori vuoti
  e `NULL`, senza ripristinare vecchie copie presenti su Actor con un fallback.
- Copiare solo sugli Actor locali collegati allo stesso utente. Verificare
  profili senza Actor e discrepanze prima di eliminare la fonte; nessuna perdita
  silenziosa e nessuna modifica a Group, feed, applicazioni o Actor remoti.
- Eseguire la copia a batch compatibili con MySQL/MariaDB e SQLite, sfruttando
  le chiavi uniche `profiles.user_id` e `actors.user_id`; verificare il piano
  delle query effettive. Non sono necessari indici JSON per la sola lettura
  dei campi dal profilo già identificato.
- Pianificare aggiornamento di schema e codice in manutenzione, fermando
  temporaneamente anche eventuali worker opzionali. Non mantenere due fonti
  scrivibili permanenti; eventuali passi di transizione devono avere una
  durata e una rimozione definite.
- Definire il rollback dati prima della rimozione della fonte. Dopo aver
  accettato campi testuali remoti/locali, tornare al solo formato `label`/`url`
  può essere una conversione con perdita: non promettere una reversibilità
  automatica e completa di quel passaggio.
- Conservare i file di avatar/copertina senza spostarli o cancellarli durante
  la migrazione. Il successivo upload deve continuare a rimuovere il precedente
  file gestito localmente, senza trattare URL remoti come percorsi del disco.
- Verificare equivalenza della UI, documenti Actor e Update, API Mastodon,
  registrazione, ricerca, suggerimenti, notifiche e messaggi. Suite completa
  e prove di migrazione con dati preesistenti su SQLite e MySQL/MariaDB.

### 8.6 Collocazione del task futuro

L'eventuale unificazione potrà essere suddivisa in trasferimento dei campi
aggiuntivi, nome/bio e immagini, seguito dalla rimozione delle dipendenze da
`Profile`. La sequenza e la migrazione verranno definite nel task dedicato,
considerando anche i campi remoti introdotti nel frattempo.

I subsprint 1.2 e 1.3 sono stati completati secondo la decisione della sezione
8.1, senza preparazioni di unificazione. L'eventuale migrazione rimane un task
separato e non è un prerequisito delle macrofasi successive.

## 9. Riferimenti

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
