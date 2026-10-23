# Segnalazioni federate — kickoff issue #119

**Issue:** [#119 — Ricevere e gestire segnalazioni federate sui soggetti locali](https://github.com/insicd/openbook/issues/119).
**Data:** 23 ottobre 2026. **Ramo:** `issue_119`.
**Base esaminata:** `main`, commit `d86d70b`.
**Stato:** kickoff `c51b1ea`; sprint 1 revisionato e committato (`2e41184`).
Sprint 2 revisionato e committato (`2ba899a`). Sprint 3 revisionato e committato
(`dc17c0a`), con prova UI locale confermata dall’utente. Sprint 4 revisionato e committato (`a281787`). Sprint 5 concluso e revisionato; verifiche automatiche superate, con i limiti indicati nella sezione 13.

## 1. Obiettivo e perimetro

Aggiungere la gestione di `Flag` al normale flusso ActivityPub di Openbook e
integrare le segnalazioni federate nel pannello di moderazione esistente.
I soggetti previsti sono persone locali e i loro post, commenti, eventi e
commenti degli eventi. Community e Actor infrastrutturali locali sono esclusi.
Un contenuto personale pubblicato in una community rimane nel perimetro.

La ricezione registra il reclamo; lo staff lo esamina, lo archivia o interviene
con gli strumenti ordinari. Non comporta notifiche al segnalato né sanzioni
automatiche. Il mittente è l'Actor autenticato, che può rappresentare l'istanza
remota: non identifica necessariamente la persona che ha presentato il reclamo.

Sono esclusi l'invio di segnalazioni verso altre istanze, conversazioni fra
moderatori e sincronizzazione degli esiti. Nessun nuovo endpoint, servizio
esterno, Redis o worker permanente è richiesto.

## 2. Decisioni funzionali concordate

### Flusso ActivityPub comune

Usare `InboxController` → `InboxItem` → `ProcessInboxActivityJob` →
`InboxActivityProcessor`, aggiungendo il caso `Flag` al dispatch esistente.
Autenticazione, firme, identità, inoltri, relay, eventuale refetch, blocchi,
limiti, deduplicazione di trasporto e gestione degli errori restano quelli
ordinari. Nessun percorso o vincolo ActivityPub specifico per Flag.

### Caso e riferimenti

Un'attività Flag produce un caso con uno o più riferimenti distinti. Una
riconsegna non duplica il caso e non ne modifica motivazione o stato. Attività
con ID diversi restano casi distinti. Non deduplicare per mittente e soggetto.

Usare resolver e convenzioni URI esistenti, verificando che i soggetti risolti
siano locali e nel perimetro. Una riga remota in cache, o un commento remoto
sotto un post locale, non è un soggetto locale. Il testo e gli oggetti incorporati
ricevuti non provano proprietà o contenuto dei soggetti segnalati.

Se il Flag elenca contenuti, il profilo accompagnatorio ne costituisce il
contesto; se elenca soltanto persone locali, il reclamo riguarda i profili.
Non interpretare automaticamente il testo libero per attribuire responsabilità.
Autori e riferimenti diversi rimangono distinti; ogni provvedimento richiede
una selezione esplicita e le normali autorizzazioni.

### Contenuti non più disponibili

I contenuti locali cancellati o non più disponibili sono **già gestiti con
esito positivo**. Se tutti i contenuti pertinenti sono in questo stato,
registrare il caso direttamente chiuso con causale «contenuti non più disponibili».
Nei casi misti rimangono da esaminare i contenuti ancora disponibili.
Una segnalazione sul solo profilo esistente resta da esaminare.

Un URI di contenuto locale riconosciuto dalle convenzioni dell'applicazione,
ma senza più una riga o tombstone, è registrato come non disponibile. Non
attribuirgli un autore non verificato, né affermare che sia esistito o fosse
illecito. URI esterni o percorsi locali estranei ai tipi previsti non aprono casi.
Un diniego di lettura per privacy non equivale a contenuto cancellato.

La chiusura positiva descrive l'assenza di ulteriori interventi necessari,
non una sanzione applicata o una valutazione del merito. Non inventare un
revisore umano per una chiusura automatica.

### Privacy e strumenti dello staff

Applicare le linee guida di privacy, i permessi e il rendering già presenti.
Segnalazioni, motivazioni e provenienza sono riservate alla moderazione; nessuna
pubblicazione in feed, ricerca, outbox o notifiche al segnalato. Le anteprime
non ampliano l'accesso a conversazioni, contenuti privati o votanti dei sondaggi.

Riutilizzare revisione, archiviazione, audit e strumenti utenti esistenti.
Per eventi e commenti evento completare la cancellazione manuale da parte dello
staff autorizzato, condividendo servizi, media, tombstone e normale Delete
federato. Le attività prodotte dai provvedimenti non includono i dati del reclamo.

### Conservazione

I casi non chiusi restano conservati fino alla gestione. I casi chiusi vengono
conservati per 12 mesi dalla chiusura, come default configurabile. Lo stato
`reviewed` indica esame, non chiusura: non avvia il termine di conservazione.

Dopo la pulizia, mantenere per la vita operativa dell'istanza una ricevuta
tecnica minima per riconoscere la stessa attività, senza motivazione, target
né identità personale del segnalante. Non conservare copie integrali dei
contenuti cancellati. La pulizia inbox non deve eliminare casi o ricevute.

## 3. Base esaminata al kickoff e componenti da estendere

La tabella descrive la base `main` al kickoff, prima dell’implementazione;
gli esiti aggiornati sono nelle sezioni 9–13.

I percorsi `app/` sono abbreviati nella tabella.

| Componente | Evidenza e intervento |
| --- | --- |
| `Http/Controllers/Federation/InboxController`, `Federation/Inbox/ForwardedActivityAuthenticator`, job inbox | Infrastruttura già presente; riutilizzo ordinario. |
| `Federation/Inbox/InboxActivityProcessor` | Flag oggi non è nel dispatch ed è ignorato; aggiungere il caso. |
| `Federation/Resolution/ObjectResolver`, `Federation/Support/ActivityPubUri` | Risoluzione e normalizzazione comuni; verificare la pertinenza locale sui risultati. |
| `Domain/Moderation/Report`, `Application/Services/ReportManager` | Oggi User locale obbligatorio, un post oppure commento. Estendere origine, provenienza e riferimenti multipli. |
| Migration `000024` e `000026` in `database/migrations/` | Unicità reporter/post e reporter/commento, FK reporter e contenuto con cascade; preservare il percorso locale. |
| `Http/Controllers/Admin/ReportController`, viste `admin/reports` | Elenco, dettaglio e azioni oggi assumono post/commento; adeguare ai cinque tipi. |
| `routes/web.php`, `ReportManager` | Middleware staff e controlli `canModerate()` già presenti. |
| `Policies/EventPolicy`, `Policies/EventCommentPolicy`, servizi eventi | Permessi oggi non equivalenti per moderatori e admin; completare il provvedimento staff senza impersonare l'autore. |
| `Application/Services/DatabaseMaintenanceService` | Può eliminare l'inbox elaborata; integrare successivamente la conservazione dei casi. |

I test esistenti di riferimento sono `InboxSignatureTest`,
`InboxActivityProcessorTest`, `EventInboxActivityTest`, `AdminReportModerationTest`,
`AdminCommentReportTest`, `AdminAccessTest` e `Moderation/ReportPostTest`.

## 4. Contratto Flag e riscontro esterno

Il [vocabolario ActivityStreams](https://www.w3.org/TR/activitystreams-vocabulary/#dfn-flag)
definisce Flag come segnalazione di un oggetto ritenuto inappropriato.
L'[handler Mastodon](https://github.com/mastodon/mastodon/blob/main/app/lib/activitypub/activity/flag.rb)
esaminato gestisce oggetto singolo/lista, account e contenuti, e motivazione
in `content`. Queste evidenze guidano fixture realistiche, senza introdurre
regole di autenticazione diverse dal framework Openbook.

Supportare URI singolo, lista e oggetti con `id` nelle forme già usate dal
processor. Estrarre gli identificatori, senza importare dati incorporati come
contenuto locale attendibile. Motivazione vuota e riferimenti ripetuti non
richiedono percorsi speciali. Usare `reason=other` quando manca una categoria
locale e conservare la motivazione come testo secondo le convenzioni attuali.

Esempio illustrativo, non prova di interoperabilità:

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://remote.example/reports/123",
  "type": "Flag",
  "actor": "https://remote.example/actor",
  "object": [
    "https://openbook.example/users/alice",
    "https://openbook.example/posts/00000000-0000-4000-8000-000000000001"
  ],
  "content": "Motivazione della segnalazione"
}
```

Le specifiche esaminate non definiscono un riscontro interoperabile di
«segnalazione risolta positivamente»; [ActivityPub §7.6](https://www.w3.org/TR/activitypub/#accept-activity)
non stabilisce tale effetto per un Accept di Flag. Conservare il normale
riscontro HTTP dell'inbox: il 202 conferma ricezione, non risoluzione.
Registrare l'esito internamente senza introdurre risposte federate ad hoc.
Il riscontro esterno di risoluzione è un limite documentato dell'interoperabilità.

## 5. Disegno applicativo e dati

### Persistenza e compatibilità

Estendere `reports` e `ReportManager`, senza una seconda coda di moderazione.
Usare nel processor un metodo privato per il caso Flag; estrarre un collaboratore
soltanto se richiesto dalla dimensione effettiva della logica.

- `reports`: origine `local`/`federated` con default locale; `reporter_id`
  nullable per origine federata; Actor mittente opzionale e snapshot URI/dominio;
  ID Flag; timestamp e causale di chiusura. Conservare le unicità locali.
- `report_targets`: URI, tipo, riferimento locale opzionale ed esito del
  riferimento per ciascuno dei cinque tipi. Preferire FK esplicite nullable
  con `SET NULL` per conservare il caso dopo la rimozione del soggetto.
- Ricevuta minima: digest dell'ID attività con vincolo univoco e collegamento
  nullable al caso. Inserirla nella transazione di creazione; dopo la pulizia
  del caso rimane il solo digest. La riconsegna di un caso eliminato non lo ricrea.

Conservare inizialmente `post_id`/`comment_id` per il percorso locale e usare la
collezione di riferimenti per quello federato, condividendo la presentazione
quando verrà estesa la UI. Evitare una migrazione generale dei casi locali.

Usare gli stati ordinari: `open` e `reviewed` non sono terminali;
`dismissed` e `actioned` chiudono il caso. La chiusura positiva automatica usa
`actioned` con causale dedicata e revisore nullo, distinguendosi da un provvedimento
manuale. Ogni chiusura effettiva valorizza `closed_at`; una riapertura lo svuota.
Non aggiungere un nuovo stato che richieda filtri paralleli.

### Integrità e accesso ai dati

Caso, ricevuta e riferimenti sono atomici. Un vincolo DB gestisce anche la
concorrenza; una riconsegna restituisce il caso esistente, oppure nessun nuovo
caso se è già stato eliminato. L'unicità riguarda l'ID attività riconosciuto
dal flusso comune, senza nuovi vincoli sull'origine o sul mittente.

I target esistenti devono riferirsi a persone locali o loro contenuti; la
risoluzione degli URI e dei contenuti mancanti appartiene allo sprint 2.
Il servizio di persistenza verifica la coerenza dei riferimenti risolti.
Non basare decisioni di moderazione su tipi o ID non verificati.

Per MySQL/MariaDB verificare FK, nullabilità, lunghezze e indici effettivamente
usati. Il digest evita dipendenze dalla collation degli URI per l'unicità.
L'indice attuale `reports(status, created_at)` è il punto di partenza; aggiungere
indici giustificati dai piani delle query reali, inclusa la futura pulizia.
I test SQLite non sostituiscono le prove MySQL/MariaDB.

Le migration sono incrementali e preservano i report locali. Il rollback
rimuove i dati federati introdotti dalla nuova struttura prima di ripristinare
lo schema precedente, che richiede un reporter locale; questa perdita di dati
federati deve essere esplicita nelle istruzioni di aggiornamento.

## 6. Piano di sprint verificabili

Gli sprint sono sequenziali. Si passa al successivo dopo aver verificato i
criteri di uscita del precedente. Ogni sprint comprende test PHPUnit mirati,
revisione dell'intero diff e aggiornamento di questa analisi con esiti e limiti
effettivamente riscontrati. I risultati effettivi sono riportati nella sezione 9.
L'implementazione resta sul ramo `issue_119`.

### Sprint 1 — Persistenza delle segnalazioni federate

**Risultato:** il sistema di moderazione può conservare una segnalazione
federata con mittente, origine, più riferimenti e chiusura positiva automatica.

- Estendere `reports` e `ReportManager`, aggiungere i riferimenti per persone,
  post, commenti, eventi e commenti evento secondo le convenzioni del progetto.
- Conservare provenienza e ID Flag senza richiedere un User locale; mantenere
  invariata la creazione delle segnalazioni locali.
- Inserire caso e riferimenti nella stessa transazione, con unicità durevole
  per attività e dati di chiusura separati da una revisione umana. Preparare
  subito la ricevuta minima che sopravvive alla futura pulizia del caso.
- Finalizzare le fixture rappresentative del payload, usando gli strumenti e
  le forme ActivityPub già supportate dall'applicazione.

**Verifica di uscita:** test di creazione locale/federata, target multipli,
rollback della transazione, riconsegna e concorrenza senza duplicati. Upgrade
con report locali preesistenti e rollback delle migration verificati su SQLite
e MySQL/MariaDB; conservazione del caso dopo eliminazione dei riferimenti.
Registrare eventuali prove non eseguibili senza dichiararle superate.

### Sprint 2 — Ricezione Flag nel flusso ActivityPub ordinario

**Risultato:** un Flag ricevuto dall'inbox genera il caso di moderazione.

- Aggiungere `Flag` al dispatch di `InboxActivityProcessor`, delegando a
  `ReportManager` e riusando resolver, autenticazione, inbox, job e cron esistenti.
- Risolvere i cinque tipi previsti e verificare la pertinenza alle persone locali;
  supportare segnalazioni sul solo profilo e liste di riferimenti.
- Registrare i contenuti non più disponibili come già gestiti positivamente;
  mantenere aperti i casi con contenuti residui da esaminare.
- Non introdurre eccezioni per firme, inoltri, relay, errori o trasporto.

**Verifica di uscita:** test attraverso inbox personale e condivisa, fino al
caso persistito, per i cinque tipi. Autenticazione e blocchi seguono i test del
framework; riconsegne non duplicano né riaprono il caso. Verificare profilo
soltanto, contenuti rimossi e casi misti. Nessuna notifica al segnalato, sanzione
automatica o nuova attività di riscontro federato. I contenuti riservati ancora
esistenti non vengono considerati rimossi.

### Sprint 3 — Esame nel pannello di moderazione

**Risultato:** lo staff può trovare, leggere, revisionare e archiviare i casi
federati nel pannello già esistente.

- Adeguare elenco, dashboard e dettaglio a origine federata, Actor mittente,
  istanza, motivazione e riferimenti multipli.
- Mostrare chiaramente i casi già gestiti positivamente e i contenuti mancanti.
- Riutilizzare stati, comandi di revisione/archiviazione, autorizzazioni e audit.
- Applicare privacy e rendering attuali; aggiornare le traduzioni IT/EN/FI.

**Verifica di uscita:** test feature per admin/moderatore e diniego ad anonimi
e utenti ordinari; anteprime conformi alle autorizzazioni esistenti. Segnalazioni
locali, conteggi e paginazione continuano a funzionare. Verifica visiva di elenco
e dettaglio con caso locale, federato multiplo e già gestito. Registrare EXPLAIN
delle query reali su MySQL/MariaDB e adeguare gli indici solo dove necessario.

### Sprint 4 — Provvedimenti manuali sui soggetti segnalati

**Risultato:** lo staff può adottare i provvedimenti previsti sui target locali.

- Collegare i profili agli strumenti utenti esistenti.
- Riutilizzare la cancellazione di post e commenti, con selezione esplicita
  dei riferimenti quando il caso ne contiene più di uno.
- Completare la cancellazione staff di eventi e commenti evento, condividendo
  i normali servizi, pulizia media, tombstone e consegna federata Delete.
- Ricontrollare autorizzazioni e stato del target al momento dell'azione.

**Verifica di uscita:** test di azioni consentite e negate per tipo e ruolo;
nessun intervento su contenuti remoti o non selezionati. Verificare audit,
stato del caso e normale consegna federata, senza motivo o provenienza del
reclamo. Un target già rimosso non produce una seconda cancellazione.

### Sprint 5 — Conservazione, regressioni e completamento

**Risultato:** funzionalità verificata e documentata, pronta per la revisione finale.

- Integrare la pulizia nel sistema di manutenzione esistente: casi aperti
  preservati, chiusi conservati per 12 mesi configurabili, chiave minima
  anti-replay mantenuta dopo la rimozione del caso.
- Verificare il ciclo completo: ricezione, esame, provvedimento o archiviazione,
  pulizia e successiva riconsegna della stessa attività.
- Aggiornare le guide pertinenti IT/EN in `docs/` e `development/`, con le
  istruzioni di aggiornamento necessarie. Leggere `.cursor/rules/changelog.mdc`
  e aggiornare il changelog per il comportamento effettivamente implementato.
- Eseguire la suite completa, rivedere l'intero diff e raccogliere gli esiti
  SQLite/MySQL/MariaDB, inclusi i piani delle query di manutenzione introdotte.

**Verifica di uscita:** test di scadenza/configurazione, casi aperti intatti e
riconsegna dopo pulizia incapace di ricreare un caso. Suite completa verde,
oppure fallimenti estranei esplicitamente documentati; nessuna regressione nota
introdotta. Eseguire una prova online Mastodon → Openbook, anche sul solo
profilo, se è disponibile un'istanza raggiungibile. Se manca, registrare la
verifica di interoperabilità come pendente, distinguendola dai test automatici.
Le fixture di eventi e commenti evento completano la copertura dei cinque tipi.

## 7. Criteri di completamento della funzionalità

- I cinque tipi previsti sono gestiti dall'inbox al pannello con autenticazione
  e infrastruttura ActivityPub comuni.
- Le riconsegne non duplicano o riaprono casi, anche dopo pulizia inbox e report.
- Contenuti rimossi risultano già gestiti; casi misti e profili soli seguono
  le regole concordate. La chiusura automatica non simula un intervento umano.
- Accesso staff, privacy, rendering e azioni rispettano i permessi esistenti;
  nessuna sanzione automatica o divulgazione al segnalato.
- Le segnalazioni locali preesistenti continuano a funzionare; cancellare un
  riferimento o mittente non distrugge il caso federato.
- Conservazione, migration e query sono verificate, con EXPLAIN dove necessario.
- Test pertinenti e suite completa eseguiti; documentazione e changelog aggiornati
  per il comportamento effettivamente disponibile. Nessun annuncio prematuro di
  supporto Flag durante gli sprint di sola persistenza.
- La prova online e le eventuali verifiche non disponibili sono riportate
  distintamente dai test automatici, senza dichiarare interoperabilità non provata.

## 8. Esito della revisione di kickoff

Documento riorganizzato per separare requisiti concordati, situazione attuale,
contratto, disegno e verifiche. Risolte le ambiguità fra revisione e chiusura,
chiusura automatica e azione umana, e deduplicazione dopo eliminazione del caso.
Rimosse le eccezioni di autenticazione e trasporto Flag: vale il framework comune.
Non rimangono decisioni di prodotto bloccanti per lo sprint 1.

L'analisi di kickoff non cambia il comportamento applicativo e non richiede
release note. I risultati di implementazione e verifica saranno registrati
qui a conclusione di ciascuno sprint.

## 9. Esito dello sprint 1 — Persistenza

### Implementazione

- Migration `2026_10_23_000000_extend_reports_for_federation`: campi di origine,
  mittente federato e chiusura in `reports`, tabella `report_targets` con FK
  esplicite nullable e tabella `report_receipts` con digest SHA-256 dell'ID Flag.
- `ReportManager::recordFederated()` riceve riferimenti già risolti dal futuro
  ramo del processor, ne verifica tipo e pertinenza locale e salva caso,
  riferimenti e ricevuta in un'unica transazione. Una ricevuta senza più caso
  impedisce di ricrearlo. Nessun collegamento dell'inbox introdotto in questo sprint.
- Un contenuto risolto cancellato o un riferimento locale riconosciuto dal
  chiamante come non più disponibile determina l'esito del riferimento.
  Il caso chiude automaticamente soltanto se tutti i contenuti pertinenti
  sono indisponibili; il profilo accompagnatorio non impedisce la chiusura.
- La chiusura automatica valorizza `status=actioned`, `closed_at` e
  `closure_reason=content_unavailable`, senza revisore umano. Le transizioni
  ordinarie aggiornano il timestamp di chiusura; `reviewed` lo svuota.
- Le FK dei riferimenti e del mittente usano `SET NULL`; URI e caso sopravvivono
  alla cancellazione delle righe. La cancellazione del caso elimina i suoi
  riferimenti ma conserva il digest minimo della ricevuta.
- Aggiornate le guide dei test IT/EN. La fixture `Reports/flag.json` è un
  esempio rappresentativo, non una cattura da un server reale.

### Verifiche eseguite

- SQLite: 8 test di persistenza, 103 asserzioni; tutti superati.
- Regressioni locali mirate: 10 test per segnalazioni post/commenti e pannello
  di moderazione, 44 asserzioni; tutti superati.
- MySQL 26.10.0 Homebrew, isolamento `REPEATABLE-READ`: 10 test, 119 asserzioni;
  tutti superati su schemi temporanei `openbook_reports_test_*`, senza modifiche
  al database applicativo. Coperti upgrade/rollback con casi locali preesistenti,
  cancellazioni fisiche e replay dopo rimozione del caso.
- La prova a due processi stabilisce snapshot esterni prima della creazione:
  entrambi ricevono lo stesso caso e rimangono un caso, un riferimento e una
  ricevuta. In caso di conflitto, la lettura del vincitore usa un locking read
  per non restare sullo snapshot precedente al commit dell'altro processo.
- Piani EXPLAIN su 1.001 casi, 1.001 ricevute e 1.005 riferimenti:

| Query effettiva | Indice scelto verificato |
| --- | --- |
| Ricevuta per `activity_hash` | `report_receipts.PRIMARY` |
| Caso per ID | `reports.PRIMARY` |
| Riferimenti per `report_id` | `report_targets_report_id_uri_hash_unique` |

Nessuna delle tre query usa una scansione completa (`ALL`). Non sono state
modificate query di elenco o manutenzione: i loro piani saranno verificati
negli sprint che le introducono. Pint e controllo whitespace superati.

Suite completa: **1.904 test superati, 121 saltati, 14.374 asserzioni**
(150,08 secondi). I test MySQL opzionali dello sprint, saltati nella normale
suite SQLite, sono stati eseguiti separatamente con l'esito riportato sopra.
Revisione completa del diff conclusa senza anomalie bloccanti.

### Limiti e lavoro successivo

Non è disponibile un server MariaDB separato;
la compatibilità non è stata verificata con un'esecuzione su quel motore.
La ricevuta è già predisposta per la futura pulizia, ma conservazione automatica,
inbox Flag, UI e provvedimenti restano agli sprint successivi. Nessuna prova
di interoperabilità online eseguita. Nessuna release note di supporto Flag:
questa fase introduce soltanto le basi interne della funzionalità.

## 10. Esito dello sprint 2 — Ricezione Flag

### Implementazione

- Aggiunto `Flag` al dispatch ordinario di `InboxActivityProcessor`, con metodo
  privato che legge i riferimenti e delega a `ReportManager::recordFederated()`.
  Nessuna modifica a controller inbox, verifica firme, inoltri, relay o job.
- `ObjectResolver::resolveLocalReference()` riusa percorsi, normalizzazione e
  resolver esistenti, restituendo anche il tipo di un contenuto locale mancante.
  La ricerca dei profili include persone locali sospese, senza cambiare la
  risoluzione ordinaria degli Actor usata dalle altre attività.
- La pertinenza ai soggetti locali è condivisa da processor e persistenza
  attraverso `ReportTarget::isLocalPersonSubject()`. Gli oggetti incorporati
  forniscono soltanto l'ID; non aggiornano autore o corpo dei contenuti.
- URI singolo, liste e oggetti con `id` sono supportati. I riferimenti esterni,
  le community e i contenuti remoti in cache sono esclusi; i riferimenti locali
  validi della stessa lista rimangono elaborabili. Una lista senza soggetti
  pertinenti segue il normale esito inbox `ignored`.
- I contenuti mancanti o cancellati, i casi misti e i profili soli seguono le
  decisioni del kickoff. La ricezione non cambia visibilità, non genera notifiche
  al segnalato, provvedimenti o attività federate di riscontro.
- La motivazione passa nel normale `RemoteContentSanitizer`. Una migration
  incrementale porta `reports.details` da TEXT a LONGTEXT: il testo ammesso
  dall'inbox può superare 64 KiB e non deve essere troncato o limitato dal form
  locale. Su MySQL il rollback a TEXT con testi troppo grandi fallisce in modalità
  strict, senza perdere dati; con testi compatibili il rollback è verificato.
- Aggiornati changelog, guide di federazione e test IT/EN, distinguendo la
  ricezione disponibile dal dettaglio completo del pannello previsto nello sprint 3.

### Verifiche eseguite

- 113 test mirati, 630 asserzioni: tutti superati. Comprendono la nuova suite
  `FlagInboxTest`, persistenza e regressioni di firme, processor ed eventi.
- Il percorso verificato parte da una richiesta HTTP firmata all'inbox personale
  o condivisa, passa dal normale job e termina nel caso persistito con tutti
  e cinque i tipi. Coperti motivazione HTML, profilo sospeso, privacy, autori
  distinti, oggetti incorporati, contenuti mancanti e riconsegne dopo pulizia.
- Verificati i normali rifiuti per firma non valida e dominio bloccato, oltre
  all'accettazione tramite relay/firma Linked Data e refetch autenticato
  dall'origine. Il caso registra l'Actor autenticato e il testo recuperato,
  non un'identità o motivazione attribuita dal trasportatore.
- MySQL: 12 test, 141 asserzioni, più un test dedicato di rollback LONGTEXT
  con 4 asserzioni; tutti superati su schemi temporanei isolati. Le motivazioni
  UTF-8 da 80.000 byte sono conservate integralmente.
- EXPLAIN delle cinque query realmente emesse dal resolver, con 1.002 Actor
  nella fixture: ciascuna usa un indice e nessuna usa scansione `ALL`. Restano
  verdi i controlli su ricevute e riferimenti, migration e concorrenza dello sprint 1.
- Suite completa: **1.917 test superati, 124 saltati, 14.463 asserzioni**
  (148,06 secondi). I 13 test MySQL opzionali sono stati verificati separatamente
  nelle due esecuzioni riportate sopra, per un totale di 145 asserzioni.
- Review dell'intero diff, Pint e controllo whitespace superati. Nessuna
  anomalia bloccante emersa. Review finale prima del commit: confermati
  21 test di ricezione/persistenza (192 asserzioni), Pint e controllo del diff.

### Limiti e lavoro successivo

Il pannello federato completo rimane allo sprint 3,
i provvedimenti allo sprint 4 e la pulizia automatica allo sprint 5.
Nessuna verifica su un server MariaDB separato o tra istanze online; i test HTTP
usano firme reali e risposte remote simulate, non attestano interoperabilità live.

## 11. Esito dello sprint 3 — Pannello di moderazione

### Implementazione

- Elenco e dettaglio esistenti ora distinguono origine locale/federata e i
  cinque tipi di soggetto, senza classificare automaticamente i casi come post.
  Mittente e istanza derivano dagli snapshot autenticati e restano visibili
  anche dopo la cancellazione dell'Actor remoto. Il dettaglio chiarisce che
  l'identità personale del segnalante originale non è nota.
- `ReportModerationQuery` prepara elenco e anteprime condivisi anche dai casi
  locali. I riferimenti locali precedenti sono adattati soltanto in memoria,
  senza migrare i dati o creare casi paralleli. I controller restano sottili.
- Le anteprime testuali caricano soltanto i soggetti leggibili tramite gli
  scope `visibleTo` esistenti; i commenti dipendono dall'accesso al loro
  post/evento. Nessun caricamento di conversazioni, citazioni, media o votanti.
  Il link al contenuto completo usa le normali route interne. I riferimenti
  mancanti e quelli esistenti ma non leggibili hanno messaggi distinti.
- Le chiusure automatiche mostrano «già gestita positivamente», causale e data
  senza inventare un revisore. Le date usano il fuso del visitatore previsto
  dal pannello. Motivazione, URI, titoli e testo delle anteprime sono escapati.
- Revisione e archiviazione riusano route, autorizzazioni e audit esistenti;
  l'archiviazione rimane disponibile dopo la revisione. Nessun provvedimento
  sui riferimenti federati introdotto prima dello sprint 4. Le azioni locali
  già disponibili restano nel pannello.
- Ordinamento stabile `created_at DESC, id DESC`. La migration incrementale
  `2026_10_23_000002_add_report_listing_indexes` aggiunge `(created_at, id)`
  per tutte le segnalazioni e sostituisce `(status, created_at)` con
  `(status, created_at, id)` per le code filtrate. Rollback verificato.
- Traduzioni IT/EN/FI, CSS responsive, documentazione IT/EN e changelog aggiornati.
  I test condividono la stessa costruzione delle fixture di persistenza.

### Verifiche eseguite

- 22 test mirati del pannello e delle traduzioni, 5.206 asserzioni: superati.
  Inclusi admin e moderatore, diniego ad anonimi/utenti ordinari, cinque tipi,
  chiusure positive, mittenti cancellati, input ostile, privacy di post/eventi
  e community private, revisione seguita da archiviazione, dashboard e paginazione.
- Suite completa: **1.925 test superati, 125 saltati, 14.642 asserzioni**
  (154,30 secondi). Ricontrollati i test mirati dopo l'ultimo ritocco ai pulsanti.
- Intera suite MySQL delle segnalazioni: **14 test superati, 202 asserzioni**
  (59,41 secondi), su schemi temporanei isolati. Comprende anche le verifiche
  dei due sprint precedenti, concorrenza e migration.
- EXPLAIN sulle query realmente usate dal pannello con 1.001 casi e 1.005
  riferimenti: cinque filtri di stato, prima pagina e pagina successiva usano
  indici senza `ALL` o `filesort`. Controllate anche le query dei conteggi,
  degli eager load e delle anteprime, senza scansioni complete dei dataset
  significativi. Nella fixture bulk MySQL inizialmente stimava una sola riga;
  ANALYZE sulle sole tabelle temporanee ha aggiornato le statistiche prima della
  verifica dei piani. Nessun hint o percorso SQL speciale nell'applicazione.
- `tests/Browser/reports.cjs`: viste Blade e CSS di produzione, dati SQLite in
  memoria; elenco misto, dettaglio federato, locale e già gestito verificati a
  1.280 e 375 pixel. Nessun errore JS, chiave traduzione irrisolta o overflow
  orizzontale. Schermate ispezionate, salvate in `/tmp/openbook-report-review`.
  Usato Chromium headless già presente nella cache locale.
- Pint, controllo whitespace e review dell'intero diff superati; nessuna
  anomalia bloccante rimasta.

### Limiti e lavoro successivo

Prova manuale sul MySQL locale completata: inbox Flag elaborata dal comando
`openbook:process-inbox`, job consumato senza errori, un solo caso aperto con
riferimento al profilo locale e ricevuta anti-replay. L’utente ha confermato
il corretto risultato nel pannello web. La prova parte da un inbox item simulato
già accettato, quindi non costituisce una verifica HTTP tra istanze.

Review finale dell’intero diff confermata senza anomalie bloccanti; i 22 test
mirati sono stati confermati prima del commit `dc17c0a`. I provvedimenti sono
descritti nello sprint 4 seguente; la conservazione automatica rimane allo
sprint 5. MariaDB separato e interoperabilità online restano non verificati.


## 12. Esito dello sprint 4 — Provvedimenti manuali

### Implementazione

- Il dettaglio offre la gestione degli utenti tramite la pagina staff esistente,
  con ricerca dell’autore locale e senza sanzioni automatiche. I profili non
  sono cancellabili dal comando dei contenuti; il caso può essere segnato come
  gestito dopo un intervento utente, anche senza selezionare contenuti.
- Ogni riferimento federato disponibile offre una casella esplicita, inizialmente
  vuota. Il form esistente invia solo gli ID dei riferimenti selezionati.
  `ReportActionManager` ricontrolla ruolo staff, stato del caso, appartenenza
  al caso e pertinenza locale dei soggetti. Un ID di altro caso o un profilo
  passato come contenuto invalida il comando prima di qualsiasi rimozione.
- Caso e soggetti vengono riletti con lock in transazione. Soggetti già rimossi
  non generano un’altra cancellazione; un caso già chiuso non ripete provvedimenti
  né audit. Le azioni locali precedenti continuano a usare gli stessi controlli.
- `LocalContentDeleter` estrae e condivide il normale flusso di rimozione tra
  controller web e pannello: post, contatori dei commenti, pulizia dei commenti
  evento e `EventComposer` per eventi. Usa i serializzatori e `ActivityDelivery`
  esistenti, con firma dell’autore originale e senza dati del reclamo.
- Admin e moderatori possono cancellare eventi e commenti evento anche dalle
  normali route. L’Actor che avvia la rimozione è quello effettivo dello staff;
  l’editing degli eventi resta riservato all’autore. Il normale cascade degli
  eventi su commenti e allegati è esplicitato vicino alla selezione.
- Ogni rimozione effettiva registra `report.target_deleted`, con ID del caso;
  la chiusura usa `report.actioned` e valorizza revisore e data. Non vengono
  inviate notifiche di segnalazione al soggetto. Guide IT/EN, traduzioni IT/EN/FI
  e changelog aggiornati; nessuna nuova migration richiesta.

### Verifiche

- Nuova suite dei provvedimenti: **22 test superati, 277 asserzioni**. Coperti
  i quattro tipi per admin e moderatore, dinieghi agli utenti ordinari, target
  estranei/profili, cambio di pertinenza locale dopo la ricezione, soggetti già
  rimossi, invii ripetuti, chiusura senza selezione e normali route eventi.
  Verificati media rimossi, contenuti non selezionati conservati, audit e Delete
  firmati dall’autore originale senza motivazione o identità del mittente.
- Suite completa: **1.947 test superati, 125 saltati, 14.939 asserzioni**
  (151,26 secondi), incluse le regressioni di post, commenti ed eventi.
- MySQL: **14 test superati, 223 asserzioni** (50,23 secondi) su schemi
  temporanei isolati. La verifica EXPLAIN delle query reali del pannello ora
  comprende anche selezione dei riferimenti, riletture con lock e comando di
  cancellazione; nessuna scansione completa sui dataset significativi. Non
  sono stati necessari indici o migration aggiuntivi.
- Browser: elenco e dettagli a 1.280 e 375 pixel, nessun overflow o errore JS;
  verificato che il form invii soltanto la casella selezionata anche se esterna
  al form tramite attributo `form`. Anteprime riservate prive di selezioni.
  Schermate in `/tmp/openbook-report-review-sprint4`, dettaglio mobile ispezionato.
- Pint e controllo whitespace superati. Review dell’intero diff conclusa senza
  anomalie bloccanti. Documentazione IT/EN e chiavi IT/EN/FI allineate.

### Stato e limiti

Sprint 4 verificato manualmente dall’utente, revisionato e committato (`a281787`). Nessuna
nuova migration e nessuna modifica ai dati applicativi locali in questo sprint:
la segnalazione simulata sul profilo rimane disponibile per provare il link
alla gestione utenti e la chiusura manuale. La conservazione automatica resta
allo sprint 5. MariaDB separato e interoperabilità online non verificati.


### Affinamento UI/UX dello sprint 4

Su richiesta dell’utente dopo la prova locale, ridisegnato il dettaglio:
lettura e decisioni in due colonne desktop, impilate su mobile; motivazione
in primo piano, metadati compatti, URI espandibili, link profilo/gestione utente
affiancati. Eliminata la ripetizione autore/titolo sui profili. Gli stati del
dettaglio sono singolari e distinguono l’esame dalla chiusura.

I comandi ora indicano l’effetto: «Segna come esaminata», «Chiudi senza
interventi», «Chiudi come gestita». Selezionando contenuti, l’ultimo comando
mostra «Elimina selezionati e chiudi», diventa rosso e indica il numero di
selezioni. Il comportamento del backend resta quello verificato nello sprint 4.
Spiegazioni persistenti associate ai pulsanti con `aria-describedby`, suggerimenti
`title` e stati hover/focus. Le selezioni locali sono accanto al contenuto come
quelle federate. Nessuna casella preselezionata; fallback senza JavaScript con
label che esplicita applicazione delle selezioni e chiusura.


Verifica dell’affinamento: **39 test mirati superati, 5.576 asserzioni**
(pannello locale/federato, provvedimenti e traduzioni). Browser a 375, 768, 1.024
e 1.280 pixel, inclusa la vista italiana del profilo: nessun overflow o errore
JavaScript; controllati etichetta/colore/conteggio dopo selezione e deselezione,
link affiancati, descrizioni associate ai comandi e fallback senza JavaScript.
Schermate in `/tmp/openbook-report-redesign`; ispezionati dettaglio mobile e
profilo desktop IT/EN. Pint, sintassi JavaScript e whitespace verificati.
La suite completa dello sprint resta quella registrata sopra; dopo questo
ritocco di presentazione sono state rieseguite le verifiche mirate e browser.


Review finale dello sprint 4: ricontrollati diff completo, autorizzazioni,
privacy, selezione e idempotenza, layout, script, traduzioni e documentazione.
Allineati i messaggi di esame/chiusura inglesi e finlandesi alla terminologia
finale. Confermati 99 test mirati (5.916 asserzioni), Pint e whitespace;
nessuna anomalia bloccante rimasta.


## 13. Esito dello sprint 5 — Conservazione e completamento

### Implementazione

- `DatabaseMaintenanceService` include i casi locali e federati chiusi con
  data effettiva oltre 12 mesi di calendario, configurabili tramite
  `OPENBOOK_CLOSED_REPORT_RETENTION_MONTHS` (minimo 1, come le configurazioni
  di manutenzione esistenti). Conteggio e cancellazione condividono il predicato.
- Nessuna scadenza per aperti, soltanto esaminati o vecchi casi senza
  `closed_at`; non viene inventata una data da `created_at` o `reviewed_at`.
  Le normali transizioni già svuotano la data alla riapertura e la valorizzano
  alla nuova chiusura. Soglia stretta, con mesi senza overflow nei fine mese.
- Le transizioni staff rileggono il caso con lock, in transazione con l’audit:
  una richiesta basata su un caso già scaduto non lo ricrea né registra una
  revisione inesistente.
- La pulizia usa il comando giornaliero e la pagina Database esistenti, con
  una nuova riga «Segnalazioni chiuse» e durata/configurazione nell’audit.
  Nessun nuovo job, worker, endpoint o servizio esterno. Non sono state lanciate
  pulizie sul database applicativo locale.
- Una DELETE verificata sullo stato corrente elimina i casi idonei; le FK
  rimuovono i riferimenti e azzerano il collegamento della ricevuta. Rimane
  solo il digest e la FK nulla, senza dati del reclamo. La pulizia inbox resta
  indipendente, l’audit non viene rimosso e nessuna attività federata è inviata.
- Migration `2026_10_23_000003_add_report_retention_index` con indice
  `(closed_at, status)`; rollback dell’indice senza eliminazione di dati.
  `.env.example`, configurazione, messaggi IT/EN/FI, guide di federazione,
  aggiornamento/rollback e configurazione IT/EN e changelog aggiornati.

### Verifiche

- Verifiche mirate finali: **85 test superati, 5.936 asserzioni**; i 19 test
  MySQL opzionali saltati in questa esecuzione sono stati eseguiti separatamente.
  Inclusi persistenza, retention, ciclo HTTP firmato, pannello, provvedimenti,
  permessi Database, comando periodico e traduzioni.
- Suite completa finale: **1.954 test superati, 130 saltati, 15.107 asserzioni**
  (142,58 secondi). Nessun fallimento rimasto. Nel primo passaggio il test
  storico annullava soltanto la migration iniziale lasciando il nuovo indice
  sulla colonna da rimuovere: aggiornato per eseguire down/up di tutte e quattro
  le migration nel loro ordine reale. Nessuna modifica retroattiva alle
  migration già committate; ciclo completo confermato su SQLite e MySQL.
- MySQL: **19 test superati, 272 asserzioni** (72,28 secondi), su schemi
  temporanei isolati. Suite di conservazione condivisa con SQLite, upgrade e
  rollback completi, concorrenza di ricezione e regressioni dei quattro sprint.
- EXPLAIN delle query effettive di conteggio e DELETE su 1.001 casi, 11 scaduti:
  entrambe scelgono `reports_closed_at_status_index`, senza scansione `ALL`.
  Dopo la pulizia restano 990 casi; verificati cascade dei riferimenti e
  ricevuta senza collegamento al caso. Gli altri piani di resolver, elenco,
  anteprime e provvedimenti rimangono verificati nella stessa suite.
- Il ciclo firmato parte dall’inbox ordinaria, attraversa UI di esame e
  provvedimento, comando di pulizia e nuova consegna dello stesso Flag:
  ricevuta preservata, nessun caso o riferimento ricreato, nessuna notifica.
  Test di soglia esatta, fine mese, configurazione, riapertura, chiusura
  positiva automatica, vecchi casi senza data e transizione su caso già scaduto.
- Browser finale con tutte le migration: elenco e casi locali/federati,
  profilo in italiano, chiusure automatiche, selezioni e fallback senza JS,
  a 375/768/1.024/1.280 pixel. Nessun overflow, errore JS o chiave irrisolta.
  Schermate in `/tmp/openbook-report-final`.
- Pint, controllo whitespace e review completa del diff superati.

### Stato e limiti

Sprint 5 revisionato e approvato per il commit. L’utente ha confermato la
presenza della riga «Segnalazioni chiuse» in Admin → Database → Maintenance.
Il controllo locale di sola lettura ha confermato l’indice applicato, un caso
e zero casi eliminabili. Nessuna migration, pulizia cron o consegna in uscita
eseguita dall’agente sul database applicativo durante lo sprint.

Review finale dell’intero diff conclusa senza anomalie bloccanti: controllati
criteri di scadenza, transizioni, autorizzazioni, FK e ricevute, configurazione,
migration e rollback, privacy, test, documentazione IT/EN e traduzioni IT/EN/FI.
Confermati **45 test mirati, 5.496 asserzioni**, Pint e controllo whitespace.
Il codice coincide con quello della suite completa e della verifica MySQL
riportate sopra; nessuna ulteriore modifica funzionale dopo quelle esecuzioni.

La verifica automatica copre il ciclo richiesto. Non è stato usato un server
MariaDB separato e non è stata eseguita una consegna Mastodon → Openbook online:
non è predisposta un’istanza remota di prova per questo ambiente locale. La
prova manuale confermata dall’utente riguarda inbox simulata e UI; le prove HTTP
usano firme reali con risposte remote simulate. La verifica di interoperabilità
live resta pendente e non viene dichiarata superata.
