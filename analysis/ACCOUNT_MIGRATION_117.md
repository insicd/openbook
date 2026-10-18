# Migrazione degli account verso e da Openbook — issue #117

**Stato:** primo stadio implementato: arrivo, alias locali e Move verso account locali.
Le sezioni sulla partenza e sul CSV restano proposte per gli stadi successivi.
**Data:** 17 ottobre 2026.
**Issue:** [#117](https://github.com/insicd/openbook/issues/117), senza commenti al momento dell'analisi.
**Ramo:** `issue_117`, creato dalla punta di `codex/user-timezone`, commit `ef5c569`.
**Analisi precedente:** [ACCOUNT_MOVE.md](ACCOUNT_MOVE.md), issue #116 già implementata.

## 1. Obiettivo e continuità con la #116

Consentire a una persona di preparare l'arrivo su Openbook e di annunciare
la partenza verso un altro server compatibile, verificando il controllo delle
identità coinvolte. Post, allegati, messaggi, voti, preferenze, ruoli e appartenenze
alle community non cambiano proprietario e non vengono copiati.

La #116 ha stabilito una scelta precisa: un Move autentico informa i follower
locali, ma non modifica i loro follow. La #117 deve conservarla. I server remoti
decidono autonomamente se trasferire i propri follow; Openbook non può garantire
il trasferimento di tutti i follower né misurarne il completamento globale.

Tre operazioni distinte:

1. **Preparare l'arrivo:** il nuovo account dichiara il precedente in `alsoKnownAs`.
2. **Annunciare la partenza:** il precedente account pubblica `movedTo` e firma un Move.
3. **Ricostruire i seguiti:** esportazione/importazione volontaria, indipendente dal Move.

Un alias è una dichiarazione preparatoria del nuovo account: aggiungerlo non
dimostra da solo il controllo dell'account precedente e non trasferisce nulla.
La prova completa nasce dall'annuncio autenticato della sorgente insieme
all'alias pubblicato dalla destinazione. Non chiedere password di altri server.

## 2. Cosa offre già il codice

| Componente attuale | Riuso e limite rilevato |
| --- | --- |
| `app/Federation/Actors/Actor.php` | `also_known_as`, `moved_to_actor_id`, relazione `movedTo()` e identità ActivityPub già disponibili. |
| `database/migrations/2026_10_10_040000_add_remote_account_move.php` | I due campi sono già nello schema; non duplicarli con colonne equivalenti per gli account locali. La FK della destinazione è `nullOnDelete`. |
| `app/Federation/Actors/RemoteActorResolver.php` | Risolve handle con WebFinger e URI, importa alias e movedTo, offre refresh effettivo senza ripiego sulla cache. `resolveMovedTo()` esclude esplicitamente le destinazioni locali. |
| `app/Federation/Actors/RemoteAccountMoveHandler.php` | Verifica actor/object, alias fresco, stato e destinazione; registra il collegamento e notifica tutti i follower locali accepted. Non sposta follow. Attualmente ignora i target locali. |
| `app/Federation/Inbox/InboxActivityProcessor.php` | Instrada Move; `handleFollow()` usa `FollowManager`, conserva l'id remoto e invia Accept quando appropriato. |
| `app/Application/Services/FollowManager.php` | Gestisce accepted/pending, account protetti, notifiche e follow in uscita. L'unicità della coppia follower/following evita relazioni duplicate. |
| `app/Federation/Serialization/ActorSerializer.php` | Non espone ancora `alsoKnownAs` o `movedTo`, neppure per i Person locali. |
| `app/Federation/Serialization/ActivitySerializer.php` | Offre `updateActor()` e il normale protocollo Follow/Accept/Undo; manca un serializer Move in uscita. |
| `app/Federation/Delivery/ActivityDelivery.php` | Firma, seleziona inbox remote accepted, deduplica sharedInbox e accoda le consegne. `deliverToFollowers()` carica l'intero insieme e usa dispatch dopo commit. |
| `app/Jobs/Federation/DeliverActivityJob.php` | Invio HTTP firmato, protezioni di rete e retry già presenti; l'accodamento durevole può riusare `enqueueDirectInTransaction()` e `local_delivery`. |
| `app/Application/Services/PollResultsProcessor.php` | Precedente concreto per preparazione a batch, cursore e job salvati nella stessa transazione. Riutilizzare il modello operativo, senza legare migrazioni e sondaggi. |
| `app/Console/Commands/CronCommand.php` | Punto di elaborazione anche per shared hosting e cron web; nessun worker permanente necessario. |
| `app/Http/Controllers/SettingsController.php`, `resources/views/settings/index.blade.php` | Punto d'ingresso naturale per alias, partenza e portabilità dei seguiti; controller sottili e servizi applicativi dedicati. |
| `app/Http/Controllers/ProfileController.php`, `resources/views/profile/show.blade.php` | Profilo locale da arricchire con l'avviso. Il banner esistente è nel profilo remoto `resources/views/actors/show.blade.php`. |

Non risulta un flusso di import/export dei seguiti nel codice applicativo letto.
`RemoteInteractionGuard` riguarda la sospensione remota e non costituisce già
una protezione generale delle operazioni di un account locale trasferito.

## 3. Arrivo su Openbook

### Preparazione da parte del nuovo account

In Impostazioni → Account → Migrazione, sezione «Arrivo da un'altra istanza»:

1. L'utente autenticato inserisce il vecchio handle o URI Actor.
2. Un servizio proposto `AccountAliasManager` risolve l'identità tramite il
   resolver esistente e mostra handle e profilo canonici prima del salvataggio.
3. Salva l'URI canonico nell'array `also_known_as` del proprio Actor locale.
   Limiti di numero/lunghezza, deduplicazione, blocchi e rifiuto di self-alias,
   Group, Feed e identità non utilizzabili devono essere espliciti.
4. `ActorSerializer` pubblica gli alias e il normale Update informa i follower.
   La verifica da parte della sorgente deve comunque poter leggere l'endpoint
   Actor anche se l'account nuovo non ha ancora follower.
5. La pagina spiega di completare la procedura sul server precedente.

Proposta iniziale: alias verso account remoti Person/Application seguibili,
con gli stessi criteri di compatibilità della #116. Alias multipli consentono
di riunire più vecchie identità; non consentono di cambiare l'identità canonica
dell'account Openbook. La loro rimozione richiede avviso: può impedire una
migrazione non ancora verificata. Nessuna modifica agli alias a partenza avviata.

### Ricezione dei follower

I server dei follower inviano normali Follow al nuovo account. Questo percorso
è già implementato e deve mantenere l'approvazione manuale: account aperto →
accepted e Accept; account protetto → pending, poi approvazione esplicita.
Un Follow non deve superare questi controlli perché associato a una migrazione.
Non serve importare una lista dei follower né firmare attività a loro nome.

### Move remoto verso una destinazione locale

Qui «remoto» indica il vecchio account su un altro server; «destinazione locale»
indica il nuovo account sulla nostra stessa istanza Openbook.

Esempio:

1. Anna ha un vecchio account su Mastodon.
2. Crea un nuovo account sulla nostra istanza Openbook e lo prepara dichiarando
   il vecchio account Mastodon come alias.
3. Dal vecchio account Mastodon avvia la migrazione verso il nuovo account Openbook.
4. Luca usa la nostra stessa istanza Openbook e segue il vecchio account Mastodon
   di Anna.

Quando Mastodon invia il Move, Openbook deve verificare l'annuncio e avvisare
Luca: «Anna si è trasferita qui», con un collegamento al nuovo profilo Openbook.
Luca può scegliere di seguirlo; il suo follow al vecchio account resta invariato,
come stabilito nella #116.

Oggi questo caso viene scartato: il gestore della #116 accetta soltanto
destinazioni su server remoti. La proposta è ammettere anche il nuovo account
sulla nostra istanza, quando la migrazione è verificata. Per controllare che
Anna abbia dichiarato il vecchio account come alias, Openbook può leggere il
nuovo account direttamente dal proprio database, senza una richiesta HTTP
a sé stesso.

Questo caso è distinto dall'arrivo dei follower che usano altri server:
sono i loro server a inviare normali Follow al nuovo account Openbook,
attraverso il percorso descritto nella sezione precedente.

Nel codice, separare nel validatore di migrazione la risoluzione della destinazione:

- per un target remoto: refresh protetto effettivo e alias nel documento attuale;
- per un target locale: risoluzione dell'URI canonico nel database, Person e
  User attivi, alias letto dai dati locali, nessuna richiesta HTTP a sé stessi.

Evitare di eliminare indiscriminatamente il divieto di target locale da
`resolveMovedTo()`: è usato anche durante i normali fetch di profili remoti.
Proposta: estrarre una verifica condivisa delle destinazioni per gli annunci
di migrazione, mantenendo esplicita la politica del percorso informativo.
I test della #116 che oggi richiedono di ignorare ogni target locale andranno
distinti: locale verificato ammesso, locale arbitrario ancora rifiutato.

## 4. Partenza da Openbook

### Verifica e conferma

Proposto `AccountMigrationService`, con request/controller dedicati:

1. Il proprietario indica la destinazione remota, risolta e mostrata con handle,
   URI e link. Primo perimetro: sorgente Person locale, destinazione remota
   Person/Application seguibile; escluse migrazioni tra due account locali,
   community e Feed.
2. Richiedere il refresh reale della destinazione e verificare che
   `also_known_as` contenga `source.activityPubId()` con i confronti URI già usati.
   Rifiutare self-move, target già trasferito, cicli conosciuti, sospensioni,
   blocchi amministrativi, id incoerenti e fetch falliti. Nessun alias ricavato
   soltanto dalla cache o da dati del form.
3. Mostrare le conseguenze: vecchio account in modalità archivio, contenuti
   esclusi, follower locali informati e server remoti liberi di applicare Move.
4. Conferma esplicita con password locale corrente, CSRF e limite di frequenza.
   La verifica finale deve ripetere il controllo remoto; un'anteprima vecchia
   non autorizza la partenza. Nessun accesso HTTP mentre si mantengono lock DB.
5. In transazione, bloccare la sorgente e ricontrollare proprietà, stato e assenza
   di una partenza precedente; salvare migrazione, URI stabili e collegamento.
   Doppio invio della conferma restituisce la stessa operazione, senza nuovo Move.

L'accesso al vecchio account autorizza la sorgente; l'alias aggiornato pubblicato
dal server di destinazione prova l'assenso della destinazione. Nessuna procedura
di trasferimento può aggirare la moderazione o cambiare le autorizzazioni sui dati.

### Pubblicazione federata

Esporre subito `movedTo` sul vecchio Actor e aggiungere un serializer Move con
id stabile derivato dalla migrazione, actor e object uguali all'URI della sorgente,
target uguale all'URI verificato, audience uguale alla collection dei follower.
Firmare con le chiavi del vecchio account e consegnare ai suoi follower remoti
accepted attraverso i job ordinari. Nessuna consegna ai relay.

Un Update dell'Actor può accompagnare il Move, ma non deve essere una dipendenza
di ordinamento fra job: i destinatari devono poter rileggere il profilo canonico
già aggiornato. Il Move resta l'annuncio che i server compatibili elaborano.

Verificare il contesto JSON-LD usato per la firma: il contesto locale
`resources/jsonld/activitystreams.jsonld` contiene `alsoKnownAs` ma non `movedTo`.
Definire esplicitamente `movedTo` come IRI con tipo `@id` nel contesto appropriato
e verificare espansione/firma round-trip; aggiungere solo una proprietà JSON
senza una mappatura coerente non basta.

### Follower locali della sorgente

Anche per una sorgente locale usare il testo/link della notifica di migrazione,
una volta per destinatario, preservando il vecchio follow e le preferenze.
Estrarre dal gestore della #116 la parte comune di creazione/deduplicazione
delle notifiche; non passare una sorgente locale al metodo remoto che la rifiuta
e non simulare una richiesta inbox firmata dall'istanza stessa.

## 5. Persistenza e lavoro riprendibile

Per la sola #116 non serviva una tabella delle migrazioni: nessun invio federato
e nessun processo locale avviato dall'utente. Per la #117 serve distinguere
«annuncio da preparare», «job accodati» e «server effettivamente raggiunti».
Il solo `moved_to_actor_id` non rappresenta questi stati e può diventare null
quando la destinazione viene eliminata dalla cache locale.

Proposta di dati nuovi, da finalizzare nell'implementazione:

- `account_migrations`: UUID, sorgente univoca nel primo perimetro, target URI
  e source URI immutabili, target Actor nullable, data di conferma, fase/cursore
  di preparazione e data di completamento dell'accodamento. Il riferimento
  pubblico rimane sul campo Actor esistente, con ripiego sull'URI persistito.
- Se necessario per deduplicare sharedInbox fra batch e recuperare preparazioni
  interrotte, `account_migration_deliveries`: migrazione, inbox, chiave univoca
  di destinazione e stato di preparazione. Una chiave hash dell'URL evita un
  indice MySQL su URL fino a 2048 caratteri; conservare anche l'URL originale.
  Questa tabella registra il lavoro, senza sostituire i retry dei job esistenti.

Un processor chiamato dal cron prepara batch limitati di notifiche locali e
destinazioni remote. Salvataggio del cursore, deduplicazione e accodamento con
`enqueueDirectInTransaction()` devono committare insieme sullo stesso DB.
Riutilizzare la soluzione dei sondaggi come riferimento, non le loro tabelle.
Firme e HTTP fuori dai lock; verificare la firma Linked Data del Move come
già avviene per le altre attività e conservare invariato il suo identificatore.

Politica proposta per i destinatari: dopo la conferma impedire nuovi follow alla
sorgente locale; selezionare nei batch i follow ancora accepted, consentendo
sempre agli utenti di smettere di seguirla. Non promettere uno snapshot esatto
dei follower al secondo della conferma. I follower già pending non fanno parte
del trasferimento. Se si decide invece per uno snapshot, persistente e completo,
va definito prima di implementare il cursore, non aggiunto implicitamente.

L'operazione è recuperabile dopo un arresto fra due batch. Più processi cron
concorrenti non devono preparare lo stesso lotto. Un errore d'invio mantiene
i normali retry; un reinvio manuale conserva lo stesso Move id e target.
«Annuncio accodato» non significa «tutti i follower trasferiti». Gli errori
definitivi devono restare visibili negli strumenti amministrativi esistenti.

Per questa funzionalità non basta chiamare `deliverToFollowers()` dopo aver
salvato il collegamento: una caduta nel mezzo perderebbe parte del lavoro e
il fan-out attuale carica tutti i follower in memoria.

## 6. Vecchio account dopo la partenza: proposta

Modalità archivio applicata alla sorgente locale, separata da deleted/suspended:

| Operazione | Comportamento proposto |
| --- | --- |
| Login, lettura, esportazione e impostazioni di sicurezza | Disponibili al proprietario. |
| Profilo HTML e Actor ActivityPub | Restano raggiungibili, con avviso/link e `movedTo`; nessun redirect obbligatorio che impedisca di leggere Actor e chiave. |
| Nuovi post, commenti, messaggi, voti, reazioni, condivisioni, eventi, follow e partecipazioni | Impediti anche nei servizi, non solo nascondendo i pulsanti. Bloccare anche la pubblicazione video differita e le condivisioni automatiche. |
| Rimozione di propri contenuti, unfollow, ritiro di reazioni/partecipazioni | Consentiti con i normali controlli; mantenere disponibili le attività Undo/Delete necessarie. |
| Vecchi contenuti e conversazioni | Attribuzione e permessi originali; nessun accesso aggiuntivo per il nuovo account. |
| Nuovi follow verso il vecchio account | Rifiutati anche dall'inbox; le vecchie relazioni e i pending rimangono, senza promozioni automatiche. |
| Lavoro già accodato | Move, Update del trasferimento, Undo/Delete restano inviabili; inventariare e fermare le nuove pubblicazioni differite incompatibili con l'archivio. |
| Moderazione e ruoli | Restano separati. Prima di partire, segnalare proprietà di community/eventi da sistemare; nessun trasferimento implicito di ruoli. |

Serve una regola applicativa condivisa per le nuove scritture di un account
trasferito, con controlli anche nei processi cron. Non allargare
`Actor::isActive()` alla cieca: rendere inattiva l'identità può nascondere il
profilo o impedire consegne necessarie. Lo stato di migrazione deve sopravvivere
alla perdita della FK target e continuare a impedire nuove pubblicazioni.

Proposta iniziale: una sola partenza, nessuna inversione automatica dopo il primo
annuncio. Un annullamento locale non può annullare ciò che altri server hanno
già applicato. Ritorno al vecchio account e successive partenze richiedono una
politica dedicata; non importare implicitamente periodi di cooldown di altri software.

## 7. Seguiti: esportazione e importazione separate

Prevedere una sezione «Importa/esporta seguiti», utilizzabile indipendentemente
dalla migrazione e disponibile in esportazione anche nell'archivio.

Proposta di primo formato: CSV compatibile con la colonna Mastodon
`Account address`. Esportare i follow accepted verso Person/Application,
includendo gli account locali con dominio completo. Community, Feed RSS/Atom,
hashtag e pending sono esclusi e questa limitazione deve comparire nella UI.
Non dichiarare esportabili preferenze che altri software non comprendono.

Importazione solo additiva, con anteprima e conferma: accettare il CSV prodotto
da Openbook e quello Mastodon, ignorando con messaggio le colonne opzionali non
supportate. Deduplicare, limitare dimensione/righe e risolvere gli account con
le normali protezioni; chiamare `FollowManager` sotto l'identità autenticata.
Non convertire un follow remoto in accepted: attendere Accept; rispettare
l'approvazione degli account locali protetti. Non importare follower.

Eseguire le risoluzioni a piccoli lotti via cron, con avanzamento durevole per
riga e riepilogo di già seguiti, richiesti, non trovati e rifiutati. Il flusso
attuale `FollowManager` non garantisce da solo atomicità fra riga importata e
consegna: definire un intento persistente o un accodamento transazionale per
evitare perdite dopo un crash, senza duplicare tutta la logica del follow.
Riprova esplicita dei fallimenti, senza reinviare indiscriminatamente richieste
pending che potrebbero essere state rifiutate dopo l'importazione.

File e resoconti privati, mai su disco pubblico. Esportazione riservata al
proprietario, generazione streaming, neutralizzazione delle celle interpretabili
come formule e cancellazione del file di importazione quando non più necessario.
Il relativo schema di importazione è separato da `account_migrations`.

La issue chiede di definirne le modalità: propongo uno stadio distinto per
evitare di rendere il completamento del protocollo Move dipendente dall'import CSV.

## 8. Database, sicurezza e verifiche

Gli indici attuali di `follows` includono unicità `(follower_id, following_id)` e
indice `(following_id, status)`: sono punti di partenza, non piani verificati
per le nuove query. Prima di fissare lo schema, eseguire EXPLAIN su MySQL/MariaDB
per selezione accepted + cursore, join Actor/User, deduplicazione inbox, lookup
della migrazione e importazione dei seguiti. Valutare un indice che includa
l'ordinamento soltanto sul piano effettivo; su UNION ALL verificare ogni ramo.
Testare migration up/down e vincoli sia SQLite sia MySQL/MariaDB isolati.

Non aggiungere richieste arbitrarie via client HTTP generici: WebFinger, fetch,
redirect e consegne devono passare dalle protezioni esistenti. Non rendere
pubblica la lista dei seguiti tramite l'export o i dettagli della migrazione.
I dati pubblici nuovi sono soltanto alias e collegamento intenzionalmente
pubblicati dall'utente; un alias non concede permessi di lettura.

Test essenziali da aggiungere:

- Gestione alias limitata al proprietario; serializer locale, contesti JSON-LD,
  canonicalità degli URI, round-trip delle firme e assenza di chiavi private.
- Arrivo: normali Follow verso account aperto/protetto, duplicati e Accept;
  Move verso target locale verificato, con notifiche e nessun follow automatico.
- Partenza: password errata, CSRF, target arbitrario, self/cicli, Group/Feed,
  blocchi/sospensioni, alias rimosso fra anteprima e conferma, fetch fallito
  nonostante cache valida, due conferme simultanee.
- Move id stabile, firma della sorgente, audience corretta, esclusione pending
  e relay, sharedInbox deduplicate anche fra batch, nessuna esposizione privata.
- Arresto e ripresa, rollback dell'accodamento, cron concorrenti e fallimento
  definitivo di una consegna; nessuna falsa dichiarazione di completamento remoto.
- Archivio verificato su endpoint, servizi e processi differiti; banner locale,
  conservazione delle vecchie autorizzazioni e perdita/cancellazione del target.
- CSV: proprietà, formati/limiti, celle formula, duplicati, errori parziali,
  richieste pending e nessuna cancellazione dei follow già presenti.

Eseguire una prova fra due istanze Openbook e una prova con un'istanza Mastodon
controllata prima di dichiarare interoperabilità. In Openbook → Openbook è
atteso l'avviso e la scelta manuale dei follower locali, per la politica #116.

## 9. Stadi e decisioni da discutere

1. **Arrivo:** alias locali, serializer/contesti e Follow ordinari; supporto
   del Move verso target locale e regressioni #116.
2. **Partenza:** verifica/conferma, stato persistente, archivio applicativo,
   serializer Move e notifiche locali. Non esporre il pulsante di conferma in
   produzione prima che anche il terzo stadio sia completo.
3. **Distribuzione:** preparazione durevole a batch, cron, job/retry e stato UI.
4. **Portabilità dei seguiti:** export CSV, poi import additivo con anteprima,
   progressi e recupero degli errori.
5. **Chiusura:** interoperabilità, suite completa, verifica MySQL/MariaDB,
   documentazione utente/federazione/configurazione EN/IT e changelog secondo
   `.cursor/rules/changelog.mdc`; traduzioni nelle lingue presenti, incluso fi.

Prima del codice vanno confermate soprattutto la modalità archivio (quali
rettifiche ai vecchi contenuti consentire), eventuali impedimenti per proprietari
di community/eventi, una sola partenza o politica di ritorno e quanto del CSV
debba rientrare nella prima consegna. Le scelte sopra sono raccomandazioni,
non decisioni già concordate con l'utente.

## 10. Verifiche dell’analisi iniziale (17 ottobre 2026)

Letti issue, analisi #116, CONTRIBUTING, guide architettura/test e i percorsi
applicativi citati. Eseguiti i test esistenti di `RemoteAccountMoveTest`,
`SocialGraph/FollowTest` e `SettingsTest`: **45 passati, 176 asserzioni**.
In quella sessione non era stato modificato codice applicativo o schema;
la consegna conteneva soltanto l’analisi, senza suite completa o prove fra server.

## Fonti esterne

- [Issue #117](https://github.com/insicd/openbook/issues/117): obiettivo e perimetro richiesti.
- [Mastodon: Account migration (Move)](https://docs.joinmastodon.org/spec/activitypub/#account-migration-move): verifica dell'alias e struttura dell'annuncio.
- [Mastodon: Moving or leaving accounts](https://docs.joinmastodon.org/user/moving/): distinzione fra migrazione, alias e portabilità dei seguiti. Il comportamento proposto per Openbook è definito sopra e mantiene la scelta della #116.

## 11. Primo stadio — implementazione completata (18 ottobre 2026)

- Aggiunta e rimozione degli alias in una sezione dedicata delle impostazioni,
  riservata al proprietario attivo. Massimo 10 alias, URI fino a 2048 caratteri;
  risoluzione di handle/URI tramite il resolver protetto esistente. Il vecchio
  account è un Person/Application remoto attivo, non sospeso o bloccato.
- Il salvataggio mostra l'URI canonico nella lista. Non serve una conferma
  separata: si tratta della sola dichiarazione pubblica preparatoria, senza
  trasferire follower o dimostrare il controllo della vecchia identità.
  Un avviso persistente spiega l'effetto della rimozione degli alias.
- Serializzazione di `alsoKnownAs` anche vuoto per comunicare le rimozioni,
  limitata ai Person locali; Update ai follower remoti come per le preferenze.
- Risoluzione dedicata dei target Move: remoti con refresh effettivo, locali
  dal database e con verifica dell'URI ActivityPub canonico. Per i target
  locali rileggere sotto lock alias e stato prima di creare notifiche.
  Il percorso informativo `RemoteActorResolver::resolveMovedTo()` è invariato.
- Nessuna nuova tabella o query di fan-out: riusare la selezione/deduplicazione
  delle notifiche della #116 e i normali Follow/Accept per i follower in arrivo.

### Verifiche finali dello stadio 1

- Test mirati alias, Move e firma Linked Data: **56 passati, 293 asserzioni**.
- Suite completa: **1.806 passati, 110 saltati, 2 falliti, 13.081 asserzioni**.
  Entrambi i fallimenti sono stati riprodotti su una copia del commit base
  `ef5c569`, con la stessa configurazione pertinente:
  - `VisibilityFromAudienceTest::test_it_treats_specific_recipients_without_public_as_direct`:
    manca la tabella `actors` nel test unitario senza preparazione dello schema.
  - `FeedTest::test_long_post_bodies_are_truncated_in_the_feed_with_a_read_more_control`:
    il test presume un estratto di 150 caratteri, ma l’ambiente locale imposta
    `OPENBOOK_FEED_BODY_EXCERPT=400`. Con il default 150 il caso passa.
- Controllo browser del partial Blade e CSS reali: desktop 1280 px e mobile
  375 px, URI molto lungo senza overflow, aggiunta/rimozione tramite moduli
  nativi e campi CSRF presenti, anche con JavaScript disabilitato. Verificata
  visivamente la resa mobile. Il controllo usa una fixture temporanea, senza
  modificare il database applicativo.
- Pint e revisione completa del diff superati. Changelog e guide di federazione
  utente/sviluppatore EN/IT aggiornati, testi UI EN/IT/FI allineati.
- Nessuna migration o nuova query complessa: riusati lookup degli Actor e query
  di fan-out della #116. Non eseguiti nuovi EXPLAIN né verifiche MySQL/MariaDB
  dedicate; i test MySQL opt-in risultano saltati nella suite.
- Nessuna prova fra server reali in questa sessione: il trasporto firmato è
  coperto dalle fixture HTTP/inbox e dai test di firma, non da una dichiarazione
  di interoperabilità già verificata con Mastodon in produzione.
- Gli stadi di partenza, archivio, distribuzione Move in uscita e CSV restano
  fuori da questa implementazione.

### Allineamento prima del commit

Su richiesta dell’utente, `issue_117` è stato avanzato con fast-forward a
`upstream/main` (`4f89fea`, merge PR #134), già aggiornato dal fetch dell’utente.
Il contenuto della nuova base coincide con quello di `ef5c569`: nessun conflitto
né modifica applicativa introdotta dall’allineamento. Rieseguiti Pint e i 56 test
mirati prima del commit; per la suite completa valgono gli esiti sopra, sullo
stesso contenuto applicativo. Il commit include soltanto il primo stadio e la
relativa analisi/documentazione; nessun push in questa operazione.
