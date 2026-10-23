# Segnalazioni federate — kickoff issue #119

**Issue:** [#119 — Ricevere e gestire segnalazioni federate sui soggetti locali](https://github.com/insicd/openbook/issues/119).
**Data:** 23 ottobre 2026. **Ramo:** `issue_119`.
**Base esaminata:** `main`, commit `d86d70b`.
**Stato:** kickoff `c51b1ea`; sprint 1 revisionato e committato (`2e41184`).
Sprint 2 revisionato e committato (`2ba899a`). Sprint 3 revisionato e verificato
su SQLite, MySQL e interfaccia locale; pronto per il commit. Sprint 4–5 da avviare.

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

## 3. Stato attuale e componenti da estendere

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

Review finale dell’intero diff confermata senza anomalie bloccanti. Lo sprint 4
completerà i provvedimenti sui soggetti federati; lo sprint 5 la conservazione
automatica. MariaDB separato e interoperabilità online restano non verificati.
