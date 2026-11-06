# Verifica email come requisito per creare contenuti

## 1. Obiettivo e classificazione dell'intervento

Impedire agli account locali non verificati di creare contenuti originali che
possano veicolare spam, mantenendo navigazione, personalizzazione del profilo e
interazioni con contenuti già presenti. È un **intervento mirato**, trasversale
ai pochi ingressi di pubblicazione già esistenti; non richiede un rework
architetturale, una nuova modalità generale read-only o nuove infrastrutture.

Baseline: main locale `d9202da9e1ee8fa9add3979868f31392db9fcdb2`.
Ramo: `new_user_confirmation`. Strategia riesaminata come requisito funzionale,
analisi di impatto e piano di implementazione prima delle modifiche applicative.

## 2. Comportamento di partenza verificato

AccountRegistrar crea User e Actor attivi, profilo, preferenze, chiavi ed endpoint;
email_verified_at rimane NULL. RegisterController emette Registered (che avvia
la notifica VerifyEmail), effettua il login e apre il feed. LoginRequest controlla
credenziali e stato attivo senza imporre verifica. L'avviso nel feed non impedisce
l'uso del composer. Rotte auth, policy e servizi non impongono questo requisito.
AuthenticateRest richiede Bearer, utente/Actor attivi e scope api, senza email.

Una sonda PHPUnit esterna al repository ha verificato creazione post, commenti,
eventi, conversazioni, messaggi, like e follow con account non verificato. Anche
login, consenso OAuth PKCE, emissione di un Bearer reale e pubblicazione REST
riescono senza verifica. Esito della ricognizione: 3 prove, 31 asserzioni;
suite Auth esistente: 30 test, 104 asserzioni, tutti passati. Queste prove
descrivono la baseline e non certificano il comportamento da implementare.

Le prove usano SQLite in memoria, HTTP esterno impedito e code simulate.
La cache di configurazione locale viene esclusa con APP_CONFIG_CACHE temporaneo,
senza alterare il database o la configurazione applicativi. Non provano consegna
SMTP, interoperabilità reale o comportamento MySQL/MariaDB.

La conferma esiste già: EmailVerificationController, EmailVerificationRequest,
URL firmato con id/hash dell'email, reinvio con throttle e limite applicativo.
Si riusa questo flusso, senza introdurre uno stato di attivazione parallelo.

## 3. Requisiti e confini

| Operazione prima della verifica | Regola |
| --- | --- |
| Letture, ricerca e navigazione | Consentite nei limiti di visibilità attuali |
| Profilo: nome, biografia, link, avatar e copertina | Consentiti |
| Follow/unfollow, like/unlike, boost/undo | Consentiti |
| Nuovi post, quote, post in community o diretti a Group, sondaggi | Vietati |
| Nuovi commenti e risposte a post/eventi | Vietati |
| Nuovi eventi | Vietati |
| Invio messaggi, anche senza testo e con sola citazione/condivisione | Vietato |
| Creazione community | Vietata |
| Staging e pubblicazione di nuovi post video | Vietati |
| Conversazioni vuote e lettura dei messaggi già ricevuti | Consentite |
| Voti, RSVP, iscrizioni, segnalazioni, preferenze, revoche e logout | Nessun vincolo email aggiuntivo |

Un boost crea Announce senza creare Post. Quote e condivisioni private creano
invece un nuovo Post/messaggio e ricadono nel blocco. Non si deve generalizzare
il vincolo a ogni INSERT: anche le interazioni consentite creano righe e attività.

Il profilo resta pubblico e può essere aggiornato tramite ActivityPub: questa
superficie è accettata per consentire la personalizzazione iniziale.
Il requisito riguarda la creazione di contenuti; modifica, cancellazione e
annullamento di contenuti preesistenti conservano le regole attuali. Non si
introduce un blocco ulteriore degli update in questa implementazione. Il rischio
residuo di spam mediante modifica di contenuti storici è fuori dal caso dei
nuovi account e resta un possibile requisito futuro, senza estendere questo lavoro.

Il vincolo si applica a tutti gli account locali non verificati, anche esistenti,
senza bypass staff né verifiche fittizie retroattive. Non cambia status/isActive,
login, visibilità dell'Actor, ricezione inbox, importazioni o diritti di lettura.
Attività già accettate e accodate per delivery non sono ritirate. I post video
ancora in staging devono invece superare la verifica prima della pubblicazione.

## 4. Strategia tecnica scelta

### Regola condivisa nei servizi applicativi

Una sola regola valuta l'autore esplicito, senza dipendere da auth() globale.
I servizi ricevono già Actor autore oppure User proprietario:

| Punto di applicazione | Scopo |
| --- | --- |
| PostComposer::compose | Post sito e REST, quote e sondaggi |
| CommentComposer::compose | Commenti e risposte a post |
| EventComposer::compose | Eventi |
| EventCommentComposer::compose | Commenti e risposte a eventi |
| MessageComposer::send | Messaggi e condivisioni private |
| CommunityRegistrar::register | Community, prima di Group/chiavi/endpoint |
| PostPublicationStager::stage | Prima di file temporanei e probe video |
| PendingPostFinalizer | Prima della preparazione media e della pubblicazione |

Per User locale la condizione è hasVerifiedEmail(). Per Actor locale Person
occorre un User verificato; la mancanza del User non costituisce un'esenzione.
Autori remoti, feed e Group tecnici non sono utenti locali privi di verifica.
Mantenere le altre autorizzazioni esistenti. La regola espone una capacità per
la UI e un controllo che solleva una specifica eccezione di autorizzazione.
Valutare lo stato corrente del User, senza claim persistenti nei token o cache
che sopravvivano alla conferma. Lo stesso Bearer deve sbloccarsi dopo verifica.

Nessun controllo equivalente in ogni controller, nessun middleware su tutte
le mutazioni e nessun observer aggiuntivo. Un futuro servizio di creazione di
contenuti deve riusare la regola; documentare questa convenzione nella guida
architetturale. La regola protegge le azioni applicative, non SQL amministrativo.

### Perché non un observer

I model ricevono actor_id oppure owner_user_id, non automaticamente l'identità
di chi opera. Sono creati anche da inbox, import RSS, cron e manutenzioni.
Un observer creating sarebbe possibile, ma avrebbe bisogno di distinguere questi
usi e non eviterebbe controlli anticipati: i video sono preparati prima di Post;
CommunityRegistrar genera chiavi prima di Community. Insert/upsert diretti o
salvataggi senza eventi possono inoltre aggirare gli eventi Eloquent.
I servizi già separano la creazione richiesta dall'utente dalla persistenza
tecnica: inserirvi la regola riduce modifiche, eccezioni e accoppiamento.

### Video pendenti

Pubblicazioni di utenti non verificati non devono consumare conversioni o
ripetuti tentativi, né impedire il lavoro sulle altre pubblicazioni. Mantenerle
in attesa e renderle eleggibili dopo conferma. La selezione della coda deve
rispettare tale vincolo e la finalizzazione deve ricontrollarlo. Se si modifica
la query, verificare il piano reale MySQL/MariaDB oltre ai test SQLite.

### Risposte e interfaccia

Gestione comune dell'eccezione: pagina/invito alla verifica per browser; 403 JSON
per AJAX; 403 application/problem+json per REST con motivo distinguibile
emailVerificationRequired. RestPersonRequest normalizza gli errori generici: la gestione comune deve
produrre direttamente il problem strutturato per questo caso, mantenendo il
formato degli altri errori. Credenziali
mancanti o invalide restano 401. Non aggiungere campi a /me o scope OAuth nuovi.

Rendere indisponibili composer e comandi di nuovo contenuto, conservando like,
follow e boost. Mostrare un avviso nel layout con accesso alla verifica/reinvio.
Applicare la stessa capacità a frammenti AJAX, modale e pagine di composizione.
Le richieste dirette devono comunque essere respinte dal servizio. Non riprodurre
automaticamente POST dopo la conferma. Testi IT/EN/FI coerenti.

## 5. Impatto e compatibilità

Riutilizzo di email_verified_at: nessuna migration, tabella, Redis o servizio
esterno obbligatorio. Nessuna modifica dei confini di visibilità o dei payload
ActivityPub. AutoAnnounceFanout rimane utilizzabile, perché i boost sono consentiti.
Non introdurre query N+1 nei batch; i dati dell'autore devono essere caricati
coerentemente con il percorso. Installer e MakeAdminCommand verificano già l'admin.

Documentazione da aggiornare durante l'implementazione: security EN/IT, guida
API e OpenAPI (solo contratto pubblico), architettura/test EN/IT e changelog
secondo le regole del repository. Nessuna revisione dei requisiti di installazione.
Client mobile dedicato e correzione autonoma di un'email errata sono fuori scope.
Attualmente il reinvio non corregge un refuso e UpdateAccountRequest non accetta
un nuovo indirizzo: l'assistenza operativa resta il percorso disponibile.

## 6. Piano di test: riusare le suite esistenti

Non duplicare le suite con una nuova batteria parallela per ogni endpoint.
Parametrizzare i test di successo esistenti per verificato/non verificato dove
la loro struttura consente asserzioni chiare; conservare separati solo i casi
che hanno un flusso differente (conferma, coda, errore prima di side effect).
I default UserFactory e CreatesAccounts sono verificati: aggiungere copertura
esplicita dello stato NULL, senza cambiare globalmente le fixture.

| Suite esistente | Adattamento previsto |
| --- | --- |
| Auth/EmailVerificationTest | Ampliare il test del link firmato: lettura e blocco prima, pubblicazione dopo nella stessa sessione; conservare firma/hash/reinvio. Integrare caso scaduto se non già coperto |
| Auth/RegistrationTest e LoginTest | Conservare login immediato e accesso; ampliare le asserzioni sul percorso della registrazione, senza duplicare tutti i test delle credenziali |
| Posts/PostComposerTest | Parametrizzare la creazione pubblicata per email; nel ramo negato verificare assenza di post/menzioni/notifiche/media. Copertura servizio diretto senza dipendere dal controller |
| Posts/PostControllerTest | Parametrizzare pubblicazione HTML e AJAX: verificato successo, non verificato invito/403 e nessuna pubblicazione |
| Posts/QuotePostTest | Estendere un caso quote per lo stato non verificato; mantenere i casi di visibilità esistenti |
| Comments/CommentTest | Adattare commento top-level e risposta per non verificato, verificando contatori/notifiche immutati |
| Events/EventBrowseTest e EventManagementTest | Adattare creazione HTTP e accesso al form; conservare test di update/cancel/delete con le autorizzazioni attuali |
| Events/EventCommentTest | Parametrizzare creazione locale e risposta, lasciando like e importazione remota ai rispettivi test esistenti |
| Messaging/ConversationTest e SharePost/Profile/EventToUserTest | Adattare invio HTML/JSON e un caso di condivisione senza testo; apertura/lettura consentite e nessun messaggio/notifica nel ramo negato |
| Communities/CommunityTest | Parametrizzare creazione Group: negazione prima di Actor/chiavi/community; usare i test di join esistenti per dimostrarlo consentito senza verifica |
| Posts/PostPublicationStagerTest e VideoUploadFlowTest | Adattare staging: negazione prima di probe/file; ampliare finalizzazione per coda non verificata, nessun consumo tentativi o starvation, ripresa dopo conferma |
| Rest/PostCreateTest | Ampliare il controllo autenticazione/identità con non verificato, problem 403 e nessun effetto; conservare test esistenti di payload/visibilità |
| Rest/OAuthTest | Ampliare PKCE e refresh con account non verificato: lettura consentita, creazione negata, stesso Bearer abilitato dopo conferma; evitare una seconda implementazione del flow |
| SocialGraph/FollowTest, Reactions/ReactionTest, Rest/PostAnnounceTest | Parametrizzare un caso significativo con email non verificata per dimostrare follow, like e boost consentiti |
| SettingsTest e ProfileFieldsTest | Eseguire i percorsi di personalizzazione anche da non verificato, inclusi bio/link/immagini e preferenze |
| Posts/PostCardActionsTest e UI delle suite di dominio | Verificare composer/quote/commenti assenti o disabilitati e like/boost/follow disponibili anche nei frammenti |
| Federation e importazioni esistenti | Eseguire regressioni su inbox post/eventi/DM, feed, Group e riparazione DM; aggiungere un caso solo se manca una distinzione d'autore realmente necessaria |

Per la regola condivisa, evitare test speculari dell'implementazione: coprire
nei test di servizio i confini Person locale senza User, remoto e tecnico solo
quando non già esercitati dalle suite. Il caso negato deve verificare assenza
di effetti, non soltanto il codice HTTP. Non espandere tutte le combinazioni di
payload per entrambi gli stati se non coinvolgono la nuova autorizzazione.

Eseguire test mirati durante sviluppo, suite completa prima del commit applicativo,
Pint e controlli OpenAPI/Markdown previsti. Per eventuali query modificate eseguire
EXPLAIN MySQL/MariaDB con dati rappresentativi e documentare risultati/limiti.
Verificare anche UI desktop/mobile e frammenti; dichiarare browser, SMTP,
ffmpeg o interoperabilità reale non esercitati invece di inferirli da PHPUnit.

## 7. Fasi e criteri di completamento

1. Congelare questa analisi con un commit prima del codice applicativo.
2. Regola comune, servizi e risposte; adattare test di pubblicazione esistenti.
3. Coda video, UI e percorsi consentiti; completare adattamenti alle regressioni.
4. Documentazione pubblica/tecnica e changelog, controlli e review dell'intero diff.

Completamento: nessun nuovo contenuto originale creato da account locale non
verificato attraverso sito, REST o staging; lettura, personalizzazione e interazioni
ammesse funzionanti; verifica abilita la creazione senza nuova autenticazione;
nessuna regressione di federazione o utenti verificati. Registrare qui eventuali
decisioni tecniche emerse e gli esiti effettivi, separandoli dalla baseline.

## 8. Attuazione e review tecnica

La regola è implementata in ContentCreationGuard e richiamata dai sette servizi
di creazione e dal finalizzatore video. Il Gate createContent è usato da composer,
azioni e avviso nel layout; la gestione comune dell'eccezione rende il 403 JSON
oppure l'invito alla verifica. RestPersonRequest non richiede modifiche: il
renderer comune restituisce già application/problem+json per le rotte REST.
Laravel prepara AuthorizationException come AccessDeniedHttpException prima
delle callback: il renderer riconosce la causa specifica e lascia intatti gli
altri errori. Il controllo sull'Actor rilegge il User per non usare una relazione
caricata prima della conferma; nessuna verifica viene memorizzata nel Bearer.

La coda aggiunge EXISTS su Actor e User verificato, prima di acquisire un lavoro.
Sonda MySQL isolata, schema temporaneo con tutte le migration, 500 User/Actor e
10.000 pubblicazioni (500 pendenti, metà autori verificati): claim effettivo e
EXPLAIN passati. Il piano usa post_publication_queue_pending_index con range
pending/processing, ordinamento dei candidati per created_at e lookup singoli
PRIMARY su actors e users. Nessun indice aggiuntivo giustificato dal piano
osservato. Test su MySQL 26.10.0; MariaDB non esercitato.

Le suite esistenti sono state parametrizzate, senza nuove classi di test nel
repository: copertura di contenuti negati e interazioni consentite nei due stati,
conferma nella stessa sessione, PKCE/refresh e sblocco dello stesso Bearer,
staging/worker e ripresa senza starvation. I link invalidi sono ora una matrice
di hash, scadenza, firma e identità. ProfileTest riusa CreatesAccounts per le
fixture che pubblicano, evitando un secondo helper con account non verificati.
La fixture browser dei sondaggi dichiara l'email verificata e rende anche il
composer non verificato per controllarne l'assenza su desktop e mobile.

Review applicativa: proprietà/visibilità restano nei controlli esistenti;
nessun allargamento dei destinatari o cambiamento di federazione. Nuove quote,
commenti e DM sono contenuti; boost e profilo mantengono i flussi precedenti.
Modifiche/cancellazioni di contenuti storici restano intenzionalmente fuori dal
nuovo requisito. Nessuna migrazione, servizio esterno o worker aggiuntivo.

Esiti di validazione:

- Suite completa: 2.401 casi, 18.812 asserzioni, nessun errore/fallimento,
  131 test opzionali saltati (integrazioni non abilitate/disponibili).
- Ultimo ampliamento della matrice dei link invalidi e regressione video:
  18 casi e 78 asserzioni passati, inclusi i tre nuovi casi firma/scadenza/identità
  rispetto alla scoperta della suite completa precedente.
- Regressioni profilo e ciclo di modifica/cancellazione post/eventi: 23 casi,
  254 asserzioni passati. Altri test mirati di creazione/UI/impostazioni: 103 casi,
  620 asserzioni passati. I numeri dei sottoinsiemi non si sommano alla suite.
- Browser esistente polls.cjs: passato con Chromium già installato su viewport
  desktop 1280 e mobile 375, includendo il composer assente per i non verificati.
- Sonda MySQL isolata di claim/EXPLAIN: 1 caso e 4 asserzioni passati;
  no nuovi indici. Server temporaneo arrestato al termine delle verifiche.
- Pint, controlli sintattici JavaScript e diff whitespace passati. OpenAPI JSON
  letto con successo, 168 riferimenti locali risolti e nuovo esempio 403 verificato;
  non è stata eseguita una validazione OpenAPI mediante validatore esterno.

Non esercitati: MariaDB, consegna SMTP effettiva, interoperabilità tra istanze
reali e client mobile nativo. Video elaborati tramite gli strumenti finti dei
fixture già esistenti, non tramite una conversione reale con ffmpeg.
