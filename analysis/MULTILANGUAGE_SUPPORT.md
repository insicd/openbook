# Lingua dei post — analisi pre-implementazione

Aggiornato il 9 ottobre 2026.

Stato: requisiti consolidati; macrofase 1 implementata e verificata nel ramo;
macrofase 2 pianificata e non avviata.

Branch: `multilanguage_support`, derivato da `main` locale.

## 1. Obiettivo e perimetro

Openbook deve conservare e presentare la lingua dichiarata per il testo di un
post quando tale informazione è disponibile senza ambiguità. Deve inoltre
consentire agli utenti locali di dichiarare la lingua dei propri post e di
impostare una preferenza di scrittura.

Il lavoro è suddiviso in due macrofasi, ciascuna oggetto di una PR autonoma:

| Macrofase | Risultato |
| --- | --- |
| 1. Ricezione e presentazione | Importazione conservativa della lingua dei post remoti e visualizzazione nella card. |
| 2. Dichiarazione locale | Preferenza di scrittura nel profilo e scelta della lingua nel composer. |

La prima macrofase deve essere utilizzabile senza la seconda. Il progetto
mantiene un solo testo importato per post; la gestione di più traduzioni dello
stesso post è esclusa dal perimetro attuale.

L'intervento riguarda nuove importazioni e aggiornamenti ordinari dei post.
La macrofase 1 include anche il salvataggio e la visualizzazione della lingua
dei commenti remoti, senza modifiche alla composizione (sezione 14).
Non è previsto un recupero massivo dei metadati dei post già presenti. Un post
preesistente può acquisire o perdere la lingua attraverso un successivo upsert
ordinario accettato dal sistema.

## 2. Semantica e requisiti trasversali

### 2.1 Significato del dato

`posts.language` rappresenta la lingua dichiarata per il testo importato o
pubblicato, non una classificazione verificata da Openbook.

- Un valore determinato identifica una sola lingua attribuibile a quel testo.
- `NULL` indica che non è possibile attribuire una sola lingua: dichiarazione
  assente, indeterminata, ambigua o non utilizzabile.
- Il sistema si fida della dichiarazione del mittente. Non verifica che il testo
  sia effettivamente scritto nella lingua indicata.
- La coerenza fra campi del payload è un controllo strutturale: non è un'analisi
  linguistica del contenuto.
- La lingua del post è distinta dalla lingua dell'interfaccia, dalla lingua
  dell'istanza e dalla preferenza di scrittura dell'autore.

### 2.2 Requisiti consolidati

| ID | Requisito |
| --- | --- |
| R1 | Importare la lingua soltanto quando la dichiarazione è attribuibile al testo salvato senza ambiguità. |
| R2 | Lasciare `NULL` se arrivano più lingue, anche quando i relativi testi sono identici. |
| R3 | Ricalcolare la lingua a ogni upsert remoto accettato, consentendo cambio e rimozione. |
| R4 | Conservare le protezioni esistenti contro aggiornamenti obsoleti e importazioni non autorizzate. |
| R5 | Presentare nella card la lingua dichiarata quando disponibile. |
| R6 | Consentire una lingua predefinita dei post nelle preferenze del profilo locale. |
| R7 | Consentire nel composer di cambiare o non specificare la lingua del singolo post. |
| R8 | In modifica usare la lingua salvata nel post, consentendone cambio e rimozione. |
| R9 | Non modificare retroattivamente i post quando cambia la preferenza di scrittura. |
| R10 | Non effettuare backfill, riconoscimento automatico o traduzione del testo. |

### 2.3 Vincoli tecnici

- Riutilizzare i servizi di importazione e pubblicazione esistenti, mantenendo
  sottili i controller e una semantica uniforme fra i percorsi di ingresso.
- Preservare autorizzazione, visibilità, moderazione e confini di federazione.
  Il metadato non deve creare nuovi canali di esposizione di contenuti privati.
- Mantenere il funzionamento su hosting condiviso senza servizi esterni,
  Redis o worker permanenti obbligatori.
- Garantire compatibilità MySQL/MariaDB e SQLite. Eventuali nuove query e
  migrazioni devono essere verificate anche sul database MySQL; per query
  nontriviali va verificato il piano effettivo con `EXPLAIN`.
- Non troncare i tag linguistici per adattarli alla colonna. Validazione,
  normalizzazione e capacità dello schema devono essere coerenti.
- Localizzare i testi UI nelle versioni italiana e inglese.

## 3. Modello ActivityStreams di riferimento

Riferimenti primari:

- [ActivityStreams 2.0, §4.7 Natural Language Values](https://www.w3.org/TR/activitystreams-core/#naturalLanguageValues).
- [ActivityStreams 2.0, §4.7.1 Default Language Context](https://www.w3.org/TR/activitystreams-core/#defaultLanguageContext).
- [ActivityStreams 2.0, §4.8 Marking up language](https://www.w3.org/TR/activitystreams-core/#markup).

Un solo oggetto ActivityStreams può contenere più versioni linguistiche,
associate allo stesso identificativo. Le proprietà `content`, `name` e `summary`
hanno forme a mappa `contentMap`, `nameMap` e `summaryMap`: le chiavi sono tag
BCP 47 e i valori sono stringhe, descritte come traduzioni equivalenti.

Esempio illustrativo:

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://example.org/posts/123",
  "type": "Note",
  "contentMap": {
    "it": "<p>Buongiorno!</p>",
    "en": "<p>Good morning!</p>"
  }
}
```

`@language` nel contesto JSON-LD assegna una lingua implicita ai testi senza
indicazione esplicita. Non identifica la variante principale di `contentMap`
e non prescrive una preferenza di importazione. Il tag `und` identifica una
lingua indeterminata.

Una sola riga per post è compatibile con lo standard. Una sola coppia
`body`/`language`, tuttavia, non conserva tutte le varianti linguistiche: il
perimetro attuale ammette questa limitazione senza attribuire una lingua
arbitraria nei casi ambigui.

## 4. Stato del sistema prima dell'intervento

| Componente | Comportamento attuale e impatto |
| --- | --- |
| Schema `posts` | Un solo `body`; `language` nullable, lunga 8 caratteri. |
| `RemoteNoteUpserter` | Non assegna `language`. È condiviso da inbox, outbox, recupero risposte e refresh dei post remoti. |
| `RemotePostObject::rawContent()` | Preferisce `content`, poi il primo valore testuale non vuoto di `contentMap`, poi `source.content`; non conserva la chiave linguistica. |
| `PostComposer` | Accetta già la lingua alla creazione. In modifica usa `$data['language'] ?? $post->language`: un `NULL` esplicito non rimuove la dichiarazione. |
| Request dei post | Accettano una stringa nullable di massimo 8 caratteri. |
| `PostPublicationStager` | Conserva già `language` fra gli attributi della pubblicazione. |
| `NoteSerializer` | Emette sempre `content`; se la lingua è valorizzata aggiunge `contentMap` con lo stesso HTML. |
| Composer condiviso | Non espone un selettore di lingua. È utilizzato anche per la modifica; va considerato anche il percorso modal. |
| `UserSetting` | Contiene `locale` per l'interfaccia, senza una preferenza distinta di scrittura. |
| Card dei post | Mostra data ed eventuale modifica, senza indicazione della lingua. |

Riferimenti nel repository: `app/Federation/Inbox/RemoteNoteUpserter.php`,
`app/Federation/Inbox/RemotePostObject.php`,
`app/Application/Services/PostComposer.php`,
`app/Application/Services/PostPublicationStager.php`,
`app/Federation/Serialization/NoteSerializer.php`,
`resources/views/composer/form.blade.php` e
`resources/views/posts/_card.blade.php`.

Gli eventi usano un percorso distinto: `RemoteEventObject` legge `inLanguage`
e `RemoteEventIngester` lo salva. Questa gestione non determina automaticamente
la semantica dei post e non è oggetto dell'intervento.

## 5. Macrofase 1 — importazione e visualizzazione

### 5.1 Regole di importazione

La selezione del corpo del post resta affidata al flusso esistente. L'aggiunta
del metadato linguistico non deve introdurre una nuova scelta della traduzione.

| Payload ricevuto | Esito atteso per `language` |
| --- | --- |
| `contentMap` con una sola lingua valida e determinata; il valore coincide con il `content` importato | Lingua dichiarata nella chiave. |
| `content` non utilizzabile; fallback effettivo sul valore non vuoto dell'unica lingua valida di `contentMap` | Lingua dichiarata nella chiave. |
| `contentMap` con più lingue, anche con testi identici | `NULL`. |
| `content` importato diverso dall'unico testo in `contentMap` | `NULL`: la dichiarazione non è attribuibile con certezza al testo importato. |
| Nessuna dichiarazione utilizzabile, tag indeterminato o metadati malformati | `NULL`. |

La corrispondenza iniziale fra `content` e valore della mappa è esatta e avviene
prima della conversione HTML/testo. Non sono previste normalizzazioni del testo
per dedurre equivalenze. Il default esplicito `@language` è utilizzabile per `content` quando non è
presente una mappa linguistica e il contesto applicabile è risolvibile localmente.
Una mappa ambigua o discordante non viene risolta tramite il default.
I contesti incorporati sono elaborati in ordine: il contesto dell’oggetto può
sovrascrivere quello dell’attività e un reset o `@language: null` annulla il
default. Contesti remoti non riconosciuti non vengono scaricati: rendono
il default non affidabile fino a un reset esplicito del contesto, anche se
seguiti da `@language`. Un contesto sconosciuto potrebbe infatti ridefinire
anche i termini, non soltanto il default.
Non è prevista una completa espansione JSON-LD o il supporto di alias e contesti
scoped; ridefinizioni rilevanti devono essere trattate conservativamente.

Negli aggiornamenti accettati il valore è ricalcolato dal documento corrente:
una nuova dichiarazione può sostituire la precedente, mentre assenza o ambiguità
producono `NULL`. Un aggiornamento scartato perché obsoleto non modifica né il
corpo né la lingua.

### 5.2 Presentazione nella card

La card deve rendere riconoscibile la lingua dichiarata per il testo quando
`language` è valorizzato. Il comportamento deve essere coerente nei feed e nel
dettaglio che riutilizzano la card.

Specifiche UI; il posizionamento concreto sarà verificato nel subsprint 1.2:

- metadato discreto vicino alla data, per esempio “2 ore fa · Italiano”;
- descrizione accessibile “Lingua dichiarata dall'autore”;
- nessuna etichetta per `NULL`;
- nome della lingua localizzato per il lettore, codice come fallback;
- nessuna bandiera e nessuna indicazione che suggerisca una verifica del testo.

Il catalogo di presentazione deve poter gestire le lingue remote, senza essere
limitato alle lingue dell'interfaccia. I valori ricevuti devono essere trattati
come dati e sottoposti all'escaping previsto dalle viste.

### 5.3 Subsprint e criteri di accettazione

| Subsprint | Attività | Criterio di completamento |
| --- | --- | --- |
| 1.1 Metadato in ingresso | Definire tag ammessi, capacità della colonna e trattamento di `@language`; integrare estrazione e upsert nei servizi esistenti. | Test mirati su lingua unica, assenza, ambiguità, testi discordanti, cambio, rimozione e aggiornamenti obsoleti; copertura dei percorsi condivisi. |
| 1.2 Card | Definire e implementare posizione, etichetta, accessibilità, nomi e fallback. | Rendering verificato con lingua nota, tag senza nome disponibile e `NULL`, su feed e dettaglio; controllo del layout mobile. |
| 1.3 Verifica e consegna | Review completa, controlli database se necessari, documentazione e changelog. | Suite completa eseguita, limiti o fallimenti documentati, docs italiano/inglese aggiornate e PR focalizzata sulla macrofase 1. |

La PR non contiene preferenze di scrittura, modifiche al composer o recuperi
dello storico. I casi ambigui continuano a essere importati secondo le regole
esistenti, con `language = NULL`.

## 6. Macrofase 2 — dichiarazione della lingua locale

### 6.1 Preferenza di scrittura

La pagina del profilo deve consentire all'utente di impostare una lingua
predefinita per i propri post. La preferenza è distinta da `UserSetting::locale`
e non deve essere dedotta silenziosamente dalla lingua dell'interfaccia.

Cambiare la preferenza riguarda i nuovi post: non riscrive quelli già pubblicati.
Il campo appartiene alla pagina di modifica del profilo. Il valore iniziale
per account nuovi ed esistenti è “Non specificata”. La collocazione concreta
seguirà le convenzioni del form esistente.

### 6.2 Composer e modifica

- Un nuovo post parte dalla preferenza di scrittura e permette di selezionare
  un'altra lingua o di non specificarla.
- La modifica parte dalla lingua salvata nel post, anche se la preferenza
  dell'account è nel frattempo cambiata.
- La dichiarazione può essere rimossa esplicitamente; il backend deve distinguere
  il campo omesso dalla richiesta di cancellazione del valore.
- Se un nuovo post viene esplicitamente inviato senza lingua, il backend non deve
  reinserire la preferenza annullando la scelta effettuata nel composer.
- Invio ordinario, staging, pagina di modifica e modal devono conservare la
  medesima semantica, compreso il ripristino del valore dopo errori di validazione.

### 6.3 Federazione in uscita

La dichiarazione monolingua deve usare il percorso già previsto dal serializer:
`content` resta presente e, con lingua determinata, `contentMap` contiene lo
stesso testo sotto la chiave dichiarata. Con `NULL` non viene emessa la mappa.
Creazione e aggiornamento devono riflettere il valore salvato.

### 6.4 Subsprint e criteri di accettazione

| Subsprint | Attività | Criterio di completamento |
| --- | --- | --- |
| 2.1 Preferenza | Definire catalogo e default; aggiungere persistenza e campo nel profilo. | Preferenza facoltativa verificata, indipendente dal locale UI, senza effetti retroattivi. |
| 2.2 Composer | Integrare selettore, default di creazione e cambio/rimozione in modifica. | Test dei percorsi ordinari, staging e modal applicabili; scelta esplicita non sovrascritta dalla preferenza; validazione coerente. |
| 2.3 Federazione e consegna | Verificare payload di creazione/aggiornamento, card, documentazione e changelog. | Test con `content` e `contentMap` coerenti, rimozione della lingua, suite completa e seconda PR successiva alla prima. |

## 7. Scelte tecniche e di prodotto consolidate

### 7.1 Tag linguistici

Il formato di interoperabilità è BCP 47, che comprende codici di lingua e
sottotag opzionali per alfabeto, regione e altre distinzioni. I tag ricevuti sono
validati sintatticamente e normalizzati in minuscolo, essendo case-insensitive.
Non vengono collassati `it`, `it-it` e `it-ch`: il metadato conserva la dichiarazione
ricevuta senza inventare equivalenze. Non è richiesta una verifica online dei tag
nel registro IANA. La validazione sintattica non certifica la registrazione di
ogni sottotag né la lingua effettiva del testo.

La colonna `posts.language` viene ampliata a 255 caratteri; questo è il limite
applicativo, con rifiuto dei valori più lunghi senza troncamento. Anche le request
locali dovranno essere coerenti con tale capacità. I valori `und`, `mul`, `zxx`,
le loro forme con sottotag, il tag generico `i-default`, la dichiarazione anomala `unknown` e i tag di solo uso
privato non attribuiscono una lingua determinata e producono `NULL` in ingresso.

Laravel 12 installato offre regole generiche e regole custom, ma nessuna regola
BCP 47 dedicata. La validazione condivisa non richiede nuove dipendenze.
Per i nomi localizzati la macrofase 1 aggiunge `symfony/intl`; `ext-intl`,
presente sulla macchina di sviluppo, non è un requisito di installazione e
non diventa obbligatorio per questa funzionalità.

Riferimenti: [validazione Laravel 12](https://laravel.com/docs/12.x/validation),
[BCP 47 / RFC 5646](https://www.rfc-editor.org/rfc/rfc5646.html),
[contesti e lingue JSON-LD](https://www.w3.org/TR/json-ld11/#string-internationalization).

### 7.2 Catalogo e selezione locale

La rappresentazione interna completa dei tag non impone un elenco esaustivo
nel composer. La selezione locale deve privilegiare le lingue base ed evitare
la proliferazione indiscriminata di varianti nazionali. Deve tuttavia mantenere
le distinzioni linguistiche e di scrittura sostanziali, ad esempio mandarino e
cantonese, e le varianti di scrittura utili per il cinese. Il catalogo concreto
sarà realizzato nella macrofase 2, riutilizzando dove possibile le risorse di
presentazione della prima macrofase. Non deve ridursi alle lingue dell'interfaccia.

### 7.3 Percorsi di importazione e UI

La stessa regola si applica ai post salvati da inbox, outbox e refresh tramite
i servizi condivisi. Include i messaggi privati memorizzati in
`posts`; la presentazione nelle conversazioni private può essere definita
separatamente dalla card dei post, senza impedire il salvataggio del metadato.
I commenti nella tabella dedicata ricevono il salvataggio del metadato e la
stessa indicazione accanto alla data. La loro composizione, gli eventi e i
relativi commenti rimangono fuori dal perimetro.

La card mostra un nome localizzato con codice di fallback; non mostra nulla
per `NULL`. La preferenza locale è separata dal locale dell'interfaccia e parte
da “Non specificata”. La seconda macrofase fornisce cambio e rimozione nel composer.

### 7.4 Verifica operativa dopo il subsprint 1.1

Dopo i test automatici è prevista una prova locale con un dump aggiornato
dell'inbox di produzione, quando disponibile. La prova usa un database di
sviluppo isolato e il percorso ordinario di processing; deve verificare lingua,
ambiguità e mantenimento dei criteri di rilevanza e visibilità. Il dump contiene
payload potenzialmente privati e non va incluso nel repository.

La prova non è un backfill della produzione. I record già processati non sono
automaticamente reimportati: la procedura di replay e i prerequisiti degli attori
vanno verificati prima dell'esecuzione. Le consegne verso server esterni devono
essere disabilitate nella configurazione della prova. Si tratta di post remoti
salvati nel database locale, non di nuovi post originati da account locali.

## 8. Evoluzioni intenzionalmente escluse

Le seguenti aree sono identificate, ma non sono necessarie per le due PR:

- **Varianti e traduzioni per post:** conservazione integrale di `contentMap`,
  scelta della versione per il lettore e relativo modello dati, eventualmente
  tramite mappa JSON o tabella dedicata.
- **Testo identico dichiarato in più lingue:** rappresentazione di più lingue
  associate allo stesso corpo. Nel perimetro attuale il caso resta `NULL`.
- **Titoli e content warning localizzati:** conservazione e selezione delle
  varianti `nameMap` e `summaryMap`, con un modello coerente con il corpo.
- **Estensione ad altri contenuti:** definizione di requisiti specifici per
  commenti ed eventi. I messaggi diretti che usano il modello `posts` richiedono
  invece una verifica di perimetro nella prima macrofase.
- **Funzioni basate sulla lingua:** filtri del feed, preferenze di lettura,
  traduzione automatica e riconoscimento linguistico del contenuto.
- **Markup linguistico HTML:** eventuale `lang` sul solo contenitore del testo,
  senza cambiare la lingua dei controlli; da valutare come miglioramento
  dell'accessibilità, non come requisito della prima consegna.

Il backfill è escluso dal progetto attuale e non è una fase successiva pianificata.

## 9. Evidenze della ricognizione iniziale

Le evidenze seguenti motivano i requisiti senza definire una percentuale attesa
di copertura futura. La fotografia locale è stata rilevata l'8 ottobre 2026;
non costituisce una verifica della produzione.


Verifica effettuata in sola lettura sul database configurato nel progetto.
Il campione comprende tutti i 9.606 record `inbox_items` presenti, ricevuti fra
il 18 e il 19 settembre 2026. La verifica riguarda gli oggetti incorporati nei
payload, senza fetch dei post indicati soltanto tramite URI né download dei
contesti JSON-LD remoti. Non è un campione rappresentativo dell'intero Fediverso.

La lettura integrale dell'inbox è stata controllata con `EXPLAIN`: scansione della
tabella attesa per questa ricognizione, senza introdurre query applicative o
modifiche agli indici.

| Riscontro | Quantità |
| --- | ---: |
| Messaggi inbox | 9.606 |
| Payload JSON non validi | 0 |
| Messaggi con oggetto post incorporato | 3.464 |
| Oggetti `Note` / `Article` / `Page` | 3.359 / 96 / 9 |
| Messaggi con `contentMap` non vuoto | 3.088 |
| Mappe con una chiave | 3.076 (99,61% delle mappe) |
| Mappe con due chiavi | 12 (0,39% delle mappe) |
| Mappe con più di due chiavi | 0 |
| Mappe con valori non stringa | 0 |
| Identificativi di post distinti con `contentMap` | 2.927 |
| Di questi, con almeno una mappa a due chiavi | 12 |
| Post complessivi nella tabella `posts` | 46.833 |
| Post con `language` non `NULL` | 0 |

I conteggi dei messaggi comprendono aggiornamenti dello stesso oggetto. Le
attività con `contentMap` sono 2.877 `Create`, 210 `Update` e un `Announce` con
oggetto incorporato. Gli stati sono 2.270 `processed`, 815 `ignored` e tre
`pending`: non tutti i payload ricevuti diventano necessariamente righe `posts`.

Le chiavi osservate comprendono `en`, `it`, `fr`, `de`, `fi`, `es` e altre lingue,
anche `zh-CN`; compare inoltre una chiave `unknown`, da non equiparare
automaticamente al tag standard `und`. La chiave `it` compare in 627 messaggi.

Sono state trovate tre corrispondenze dirette fra identificativi negli oggetti
con `contentMap` e URI di post ancora presenti nel database: origini
`ohai.social`, `mastodon.social` e `mt.abscue.de`, con chiavi `en` o `fr`.
In tutti e tre i casi `posts.language` è `NULL`. Il basso numero di corrispondenze
non è stato spiegato da questa ricognizione e non dimostra un errore di importazione
degli altri oggetti.

### I dodici casi con due lingue

Tutti sono `Create` in stato `processed`, provengono da `bsky.brid.gy` e riguardano
dodici identificativi distinti. Nove hanno chiavi `en`/`es`, tre `en`/`tr`.

In tutti i casi:

- i due valori di `contentMap` sono identici fra loro;
- anche `content` coincide esattamente con entrambi;
- non è presente un `@language` predefinito nei contesti incorporati dell'attività
  o dell'oggetto.

Non sono quindi state trovate traduzioni differenti fra cui scegliere nel
campione. È stato trovato lo stesso testo associato a più lingue. Dai metadati
non è possibile attribuire al testo una lingua unica: potrebbe anche essere testo misto,
ma questa è una possibilità, non un riscontro sul contenuto. Scegliere la prima
chiave sarebbe arbitrario; un riconoscimento automatico sarebbe una stima.

### Relazione fra `content` e `contentMap`

Confronto esatto delle stringhe ricevute, prima di sanitizzazione o conversione:

| Situazione, fra i 3.088 messaggi con mappa | Quantità |
| --- | ---: |
| `content` coincide con un solo valore della mappa | 3.045 |
| `content` coincide con due valori della mappa | 12 |
| `content` assente, vuoto o non stringa | 30 |
| `content` presente ma diverso da tutti i valori della mappa | 1 |

L'ultimo caso richiede una ricognizione ulteriore prima di stabilire una regola
di corrispondenza: non è stata analizzata la natura della differenza.

### Presenza di `@language`

Una ricerca ricorsiva nei JSON conservati trova `@language` in quattro messaggi
complessivi. Solo uno dei messaggi con oggetto post incorporato lo contiene:
una `Note` da `nixnet.social`, con `@language: "und"` nel contesto dell'attività.

Nessun oggetto post esaminato dichiara direttamente un default nel proprio
`@context`. Nessuno dei dodici casi a due lingue ha un default incorporato.
Gli altri tre messaggi con `@language` non sono stati approfonditi.

## 10. Stato di avanzamento e verifica del subsprint 1.1

Analisi consolidata nel commit `6ae0209`. Il subsprint 1.1 è completato e
verificato nel commit `3d09338`; quel commit non comprende il rendering nella
card (subsprint 1.2, descritto nella sezione 12).
La prova con un nuovo dump dell'inbox di produzione è stata eseguita nel
database locale; i risultati del controllo successivo sono nella sezione 11.

Sono implementati validazione sintattica BCP 47 e normalizzazione, estrazione
conservativa da `contentMap`, default espliciti locali `@language`, propagazione
dei contesti delle attività incorporate e ricalcolo della lingua nell'upsert
condiviso. Il metadato viene salvato anche sui messaggi privati. La migrazione
amplia la colonna a 255 caratteri e blocca rollback che troncherebbero dati.

Verifiche eseguite:

- test mirati di importazione, upsert, DM, outbox, refresh e gestione dei contesti;
- suite completa: 1.357 test, 5.948 asserzioni, nessun fallimento; i due test
  dell’installer MySQL sono saltati perché la connessione è bloccata dal sandbox;
  la migrazione specifica è stata verificata separatamente su MySQL;
- suite eseguita con `memory_limit=512M` e `OPENBOOK_FEED_BODY_EXCERPT=150`:
  il limite locale di 128 MB non basta per l'intera suite e il `.env` imposta
  l'excerpt a 400, incompatibile con il test del feed che presuppone 150;
  nessuna modifica permanente al `.env`;
- migrazione reale MySQL su tabella temporanea isolata nella connessione:
  ampliamento, conservazione di un tag lungo, blocco del rollback non sicuro,
  restringimento dopo rimozione del valore lungo;
- `EXPLAIN` del controllo di rollback: scansione con arresto al primo risultato,
  attesa per il predicato sulla lunghezza, senza nuovi indici applicativi;
- Pint e controllo whitespace superati; documentazione federazione italiana e
  inglese e changelog aggiornati.

Durante i controlli iniziali la migrazione non era stata applicata alla tabella
`posts` del database locale esistente. Il controllo successivo al replay
conferma ora `posts.language` come `varchar(255) NULL`. Per altri replay resta
necessario migrare il database di prova e verificare la configurazione di
isolamento descritta nella sezione 7.4.

### Strumento operativo per il replay locale

`openbook:reprocess-inbox` accoda ora sia `ignored` sia `pending`, escludendo
`processed` e `failed`. Questo permette di ricostruire i job per righe importate
senza la tabella `jobs`. Il comando esegue operazioni reali, non un dry-run, e
non deduplica i job già presenti. Con il driver database l'elaborazione avviene
successivamente tramite `openbook:process-inbox`; con `sync` è immediata.

Tre test mirati verificano gli stati, il batching e la creazione effettiva di un
job `inbox` per un record pending importato. Sul MySQL locale, `EXPLAIN` verifica
l'accesso tramite `PRIMARY` per lettura ordinata, chunk successivi e UPDATE,
e tramite `inbox_items_status_index` per il recheck del batch; non sono necessari
nuovi indici. I payload non vengono caricati durante la selezione dei candidati.
Il reprocessing dell'inbox importata non è stato eseguito durante questi
controlli iniziali; il replay successivo è verificato nella sezione 11.

## 11. Verifica sui dati reali dopo il replay locale

Controllo del 9 ottobre 2026, eseguito in una transazione MySQL di sola lettura
dopo l'elaborazione locale dell'inbox importata da produzione. Nessun record
modificato e nessuna richiesta HTTP verso server federati. I risultati sono
una fotografia del database, non una misura della copertura dell'intera rete.

### Distribuzione dei valori salvati

Su 47.479 post, 552 hanno una lingua valorizzata: `en` 306, `it` 159, `fi` 47,
`fr` 15, `de` 11, `es` 8, `ru` 3, `ja` 2, `da` 1. Tutti i valori rispettano
la validazione e la normalizzazione previste. I restanti 46.927 `NULL`
comprendono lo storico non rielaborato: il rapporto sul totale non misura
l'efficacia dell'importazione nuova. La colonna risulta `varchar(255) NULL`.

### Confronto fra payload e post persistiti

Gli oggetti incorporati nelle attività `Create`, `Update` e `Announce` sono
associati ai post tramite `uri` o `source_uri`. Il confronto richiede che il
timestamp remoto (`updated`, oppure `published`) corrisponda al
`remote_updated_at` persistito, convertito nel fuso applicativo. In presenza
di più payload della stessa versione viene considerato quello con
`processed_at` più recente. Questo evita di attribuire al replay metadati
provenienti da vecchie attività già elaborate in produzione.

Il confronto comprende 639 post distinti e non rileva discrepanze:

| Situazione del payload | Post | Lingua persistita |
| --- | ---: | --- |
| Mappa con una lingua valida e testo corrispondente | 545 | Lingua della mappa |
| Nessuna mappa né default locale utilizzabile | 71 | `NULL` |
| Mappa con più lingue | 9 | `NULL` |
| Mappa con una lingua, ma testo vuoto | 13 | `NULL` |
| Mappa con una lingua e valore diverso da `content` | 1 | `NULL` |

Per tutti i 545 valori non nulli è verificata anche direttamente la presenza
di una sola chiave, la corrispondenza della chiave normalizzata alla lingua
salvata e l'uguaglianza del valore non vuoto al contenuto selezionato, oltre
al confronto con l'estrattore applicativo.

Dieci di questi post hanno anche vecchi `Announce` senza mappa, elaborati in
settembre: i successivi `Create`/`Update` rielaborati in ottobre dichiarano
`it` e il valore persistito concorda. Non sono casi di mappa multilingua.

### Limiti e stato dell'inbox

Sette dei 552 post con lingua non hanno un oggetto incorporato già elaborato
con lo stesso timestamp remoto. La lingua di questi sette post non è
verificabile direttamente con questo confronto: una versione diversa o un
documento recuperato via HTTP non sono ricostruibili dal solo payload
incorporato. Non viene dedotto un errore né certificata la corrispondenza.

La fotografia dell'inbox comprende 23.707 `processed`, 11.719 `pending`,
1.101 `ignored` e tre `failed`. Il controllo non modifica né rielabora questi
stati; il replay non copre quindi tutta l'inbox. Non emergono default
`@language` utilizzabili negli oggetti incorporati esaminati: quel percorso
resta verificato dai test dedicati.

Le query di audit leggono integralmente le tabelle e aggregano i risultati;
`EXPLAIN` conferma le scansioni previste e l'indice di stato per il
raggruppamento dell'inbox. Non sono introdotti indici per query diagnostiche
occasionali. Nei risultati conservati non sono inclusi testi o identificativi
personali dei messaggi.

## 12. Presentazione della lingua: subsprint 1.2

Implementazione della visualizzazione completata insieme alla review finale,
successiva al commit del subsprint 1.1. La card condivisa mostra «data · lingua»
nei feed, nel dettaglio e negli incorporamenti dei post citati. Lingua assente e post
eliminati non mostrano un'etichetta. Il nome è accompagnato da un testo per
screen reader e da un titolo «Lingua dichiarata dall’autore: …», tradotto
nella lingua dell'interfaccia del lettore. Non vengono aggiunte bandiere,
azioni di traduzione o attribuzioni automatiche della lingua al corpo HTML.

### Catalogo e fallback

La dipendenza Composer `symfony/intl` fornisce i nomi localizzati CLDR/ICU
senza richiedere l'estensione PHP `intl`, servizi esterni o richieste HTTP.
È compatibile con PHP 8.2; il lock aggiunge un solo pacchetto, senza aggiornare
le altre dipendenze. Il catalogo non è limitato alle lingue dell'interfaccia.

Il presenter `App\Support\PostLanguageLabel` cerca il nome del tag completo,
conservando regioni e alfabeti. Per codici di lingua semplici usa anche il
catalogo delle lingue; `cmn`, assente dal catalogo CLDR, ha un nome esplicito
in italiano e inglese, distinto da `yue` (cantonese). Se manca un nome per il
tag completo viene mostrato il codice originale, senza ridurlo alla lingua
base. Il valore rimane invariato nel database.

Le viste eseguono escaping sia del testo sia del titolo, anche per eventuali
valori preesistenti non validati. Il testo è isolato tramite `bdi` e i codici
lunghi possono andare a capo senza allargare la card. Il composer e le
preferenze di scrittura del profilo restano nel perimetro della macrofase 2.

### Verifiche

- Test dedicati: 22 test e 69 asserzioni su nomi italiani/inglesi, regioni,
  alfabeti, mandarino/cantonese, codice non riconosciuto, estensioni BCP 47,
  assenza, escaping e post eliminati. I test HTTP coprono il feed caricato
  tramite AJAX e la pagina di dettaglio.
- Controllo Chromium locale su sei card renderizzate dalla vista Blade e dal
  CSS applicativo, con dati sintetici e richieste esterne bloccate: larghezze
  320, 375, 768 e 1280 pixel, nessun overflow orizzontale, incluso un tag
  composto lungo; screenshot mobile e desktop ispezionati.
- `composer validate`, Pint e controllo whitespace superati.
- Suite completa: 1.379 test, 6.017 asserzioni, nessun fallimento; gli stessi
  due test dell'installer MySQL restano saltati per la connessione bloccata
  dal sandbox. Usati gli override temporanei di memoria ed excerpt descritti
  nella sezione 10.
- Lettura dei nomi CLDR verificata anche con le classi native `Locale`,
  `ResourceBundle` e `Collator` disabilitate.

## 13. Chiusura della macrofase 1: subsprint 1.3

Review complessiva del ramo rispetto a `main`: estrazione, propagazione dei
contesti, upsert, schema, request, presenter, viste, comando di recupero inbox,
test, dipendenze e documentazione. Il salvataggio resta nell'upsert condiviso;
la risoluzione dei nomi è separata dalla validazione BCP 47. Nessun controller,
route, criterio di autorizzazione o selezione della visibilità viene modificato.

Le request condividono il limite tramite `PostLanguage::MAX_LENGTH`; la
migrazione mantiene il valore fisso del proprio schema. Un test aggiuntivo
copre l'ereditarietà del contesto più vicino in `Announce → Create → Note`; il test outbox Lemmy
verifica inoltre che un default non attribuisca una lingua all'URL di fallback.

La verifica web sui post realmente importati è confermata; si aggiunge ai
controlli automatici e al layout mobile/desktop descritti nella sezione 12.
Il changelog contiene una sola voce orientata alla funzionalità «Lingua del
post originale». Dettagli tecnici, migrazione e recupero dell'inbox restano
nella documentazione operativa e nell'analisi.

Verifica finale dopo le correzioni della review: suite completa con 1.380 test,
6.019 asserzioni e nessun fallimento; due test dell'installer MySQL saltati
per il limite del sandbox già documentato. Test mirati: 29 test, 139 asserzioni.
Pint, `composer validate` e controllo whitespace superati. Restano valide le
verifiche MySQL della migrazione e dei piani di query registrate nella sezione 10.

Per distribuire la macrofase 1 occorre installare le dipendenze dal lock con
`composer install --no-dev --optimize-autoloader` ed eseguire le migrazioni con
`php artisan migrate --force`, secondo il normale aggiornamento dell'istanza.
Non occorrono nuovi servizi né un replay dello storico. Il rollback della
migrazione resta bloccato se esistono tag più lunghi di otto caratteri.

Il ramo è pronto per una PR dedicata alla macrofase 1; profilo e composer
restano esclusi, con i requisiti della macrofase 2 già definiti nella sezione 6.

## 14. Estensione minima: lingua dei commenti

Il perimetro della macrofase 1 comprende anche `comments.language`, nullable
e lungo 255 caratteri, senza indici aggiuntivi o backfill. I commenti remoti
usano `RemotePostObject::language()` nell'upsert condiviso, con le stesse regole
su lingua unica, default locale e ambiguità. Ogni upsert ricalcola il valore,
consentendone cambio e rimozione; la selezione del testo non cambia.

Inbox e recupero delle risposte convergono già su `upsertComment()`. Il secondo
percorso conserva ora il contesto dei `Create` incorporati tramite l'helper
`JsonLdLanguage::inherit()`, necessario per il default `@language`. Non sono
introdotti percorsi paralleli o dipendenze. Autorizzazione e visibilità restano
quelle dei flussi esistenti.

Il componente condiviso `content-language` presenta la lingua accanto alla
data dei post e dei commenti, sia nel dettaglio del post sia nel thread del
commento. Riutilizza nomi localizzati, codice di fallback e descrizione
accessibile; i commenti senza lingua o eliminati non mostrano etichette.

I commenti locali continuano con lingua `NULL`. Composer, serializzazione
in uscita e commenti degli eventi restano esclusi. La protezione contro update
obsoleti dei commenti non viene modificata: il lavoro separato è registrato in
[`TODO.md`](TODO.md#ordinamento-degli-aggiornamenti-dei-commenti-federati).

Verifiche: test mirati su inbox e recupero risposte (17 test, 79 asserzioni),
inclusi cambio, rimozione, ambiguità, testo discordante e default ereditato.
Verifiche UI su dettaglio post e thread del commento: nomi localizzati,
varianti, fallback, valori assenti, escaping e risposte a commenti eliminati;
anche i test delle card dei post restano verdi dopo la condivisione del componente.
Suite completa: 1.391 test, 6.120 asserzioni, nessun fallimento; due test
dell'installer MySQL saltati per il limite del sandbox. La suite usa un
`APP_CONFIG_CACHE` temporaneo dedicato per evitare interferenze con la cache
rigenerata dell'istanza locale, oltre agli override già descritti nella
sezione 10. Nessuna modifica permanente alla configurazione.

Migrazione verificata su SQLite dalla suite e su una tabella temporanea MySQL:
aggiunta della colonna, valore iniziale `NULL` per una riga preesistente,
conservazione di un tag lungo e rimozione della colonna al rollback. La tabella
reale del database locale non è stata modificata. Pint e controllo whitespace
superati.
