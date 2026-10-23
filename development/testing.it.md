> Documentazione: [Italiano](README.it.md) · [English](README.md)

## Test

Conservazione e ciclo completo (#119, sprint 5):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Moderation/ReportRetentionTest.php tests/Feature/Federation/FlagInboxTest.php tests/Feature/Admin/AdminDatabaseMaintenanceTest.php tests/Feature/Console/PurgeDatabaseCommandTest.php
OPENBOOK_TEST_REPORTS_MYSQL=1 APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Moderation/FederatedReportMysqlPersistenceTest.php
```

La suite di conservazione è condivisa SQLite/MySQL: casi locali/federati,
stati terminali, date mancanti, soglia esatta, mesi senza overflow, configurazione,
riapertura, chiusura automatica e ricevute minime dopo scadenza. Il percorso HTTP
firmato attraversa esame e provvedimento staff, pulizia tramite comando e
riconsegna senza ricreare il caso. Verificati permessi admin e conteggi del
pannello, audit e rollback dell’indice. Il test MySQL verifica EXPLAIN di
conteggio e DELETE reali. Le prove non attestano interoperabilità online con
un’altra implementazione: rimane da eseguire una consegna da un’istanza remota
di prova a un’istanza Openbook raggiungibile.


Provvedimenti manuali (#119, sprint 4):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Admin/AdminFederatedReportActionTest.php tests/Feature/Admin/AdminReportModerationTest.php tests/Feature/Admin/AdminCommentReportTest.php tests/Feature/Events
```

Coperti i quattro tipi di contenuto per admin e moderatore, selezione esplicita,
ruoli negati, riferimenti di altri casi, soggetti diventati remoti, cancellazioni
ripetute e target rimossi, audit, chiusura senza sanzioni, pulizia media e normali
Delete senza dati del reclamo. Il test MySQL del pannello verifica anche i piani
delle query del comando. Il test browser controlla l’invio delle selezioni, label e colore dinamici,
layout a quattro larghezze, profilo in italiano e fallback senza JavaScript.

Pannello delle segnalazioni federate (#119, sprint 3):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Admin/AdminFederatedReportTest.php tests/Feature/Admin/AdminReportModerationTest.php tests/Feature/Admin/AdminCommentReportTest.php tests/Feature/Admin/AdminAccessTest.php tests/Unit/Support/FinnishTranslationsTest.php
node tests/Browser/reports.cjs
```

La suite verifica provenienza, cinque tipi, permessi staff, anteprime private,
casi già gestiti e mancanti, escaping, revisione/archiviazione, dashboard e
paginazione. La fixture browser usa SQLite in memoria e le viste Blade/CSS
reali su desktop e mobile; `OPENBOOK_REPORT_SCREENSHOTS` salva le schermate.
Come gli altri test browser richiede Playwright; `BROWSER_EXECUTABLE_PATH`
permette di usare un browser locale compatibile già installato. Il test MySQL
delle segnalazioni verifica anche i piani di tutte le code e delle anteprime,
con ANALYZE sulle sole tabelle temporanee dopo il caricamento massivo.

Ricezione delle segnalazioni federate (#119, sprint 2):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Federation/FlagInboxTest.php tests/Feature/Moderation/FederatedReportPersistenceTest.php tests/Feature/Federation/InboxSignatureTest.php tests/Feature/Federation/InboxActivityProcessorTest.php tests/Feature/Federation/EventInboxActivityTest.php
```

I test attraversano inbox firmata, job e persistenza per i cinque tipi;
coprono contenuti mancanti, privacy, riconsegne dopo pulizia, firme non valide,
blocchi e i normali inoltri via firma Linked Data/relay o refetch dall'origine.
Il test MySQL sotto verifica anche gli indici delle query reali dei resolver,
motivazioni oltre 64 KiB e rollback dell'estensione LONGTEXT.

Persistenza delle segnalazioni federate (#119, sprint 1):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Moderation/FederatedReportPersistenceTest.php tests/Feature/Moderation/ReportPostTest.php tests/Feature/Admin/AdminReportModerationTest.php tests/Feature/Admin/AdminCommentReportTest.php
OPENBOOK_TEST_REPORTS_MYSQL=1 APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Moderation/FederatedReportMysqlPersistenceTest.php
```

Il test MySQL/MariaDB usa `OPENBOOK_TEST_MYSQL_*` (incluso il socket opzionale)
e crea/elimina soltanto schemi casuali `openbook_reports_test_*`. Quando attivato,
un errore di connessione o migration fa fallire il test. La copertura comprende
i cinque tipi, casi locali preesistenti, chiusura automatica, rollback atomico,
riconsegne dopo cancellazione del caso, FK, concorrenza tra due processi e
EXPLAIN delle query per ricevuta e riferimenti. La fixture `Reports/flag.json`
è illustrativa, non acquisita da un server reale.

Lo sprint 1 prepara la persistenza; lo sprint 2 aggiunge la ricezione descritta
sopra. La UI è descritta nella sezione del pannello sopra. La migration `2026_10_23_000000_extend_reports_for_federation`
preserva i casi locali; il suo rollback elimina i casi federati e le ricevute
prima di ripristinare il reporter locale obbligatorio. La manutenzione e la
verifica di interoperabilità online appartengono agli sprint successivi.

Statistiche dell’istanza:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/StatsTest.php tests/Feature/StatsCacheTest.php tests/Feature/DeliveryStatsTest.php
```

I test coprono autorizzazione, filtri statistici, bucket UTC, top hashtag mensili,
condivisione/scadenza della cache, reset admin, assenza di invalidazioni automatiche,
concorrenza con reset, fallback, errori non memorizzati e delivery ripetute di
post/eventi. Le prove delivery usano SQLite su file per una seconda connessione
reale; le altre usano SQLite in memoria, senza toccare il database dell’istanza.
Le verifiche MySQL delle query e degli incrementi sono registrate in
[ANALYTIC_STATS_EXPLAIN](../analysis/ANALYTIC_STATS_EXPLAIN.md), inclusi rami UNION,
cache miss/hit e quattro processi concorrenti. I tempi locali non sono garanzie
per altre installazioni. Eseguire comunque la suite completa prima di una PR.


CSV dei seguiti (#117):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/FollowingCsvTest.php tests/Feature/SettingsTest.php
OPENBOOK_TEST_CSV_MYSQL=1 APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/FollowingCsvMysqlTest.php
```

I test SQLite coprono export privato, importazione diretta, CSV Mastodon e senza
intestazione, file invalidi, duplicati e richieste esistenti, account protetti,
risoluzione remota e normali job di consegna. Il test MySQL opzionale crea uno
schema isolato con i parametri `OPENBOOK_TEST_MYSQL_*` descritti sotto. Controlla
EXPLAIN della prima pagina di export, delle successive e della ricerca di account
conosciuti, ed esporta più pagine senza modificare il database dell’applicazione.
Le verifiche catturano le tre query reali delle pagine, incluso il cursore, e
richiedono la lettura coperta da `follows_export_index`, senza filesort né
scansioni complete. Verificano gli account esportati e l’esclusione dei follow
pendenti. Rollback e riapplicazione dell’indice sono coperti su SQLite e MySQL,
senza perdere follow o alterare l’export. Per aggiornare un’istanza applicare
`2026_10_24_000000_add_follow_export_index`.

Partenza degli account (#117):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/AccountMigrationTest.php tests/Feature/AccountAliasesTest.php tests/Feature/Federation/RemoteAccountMoveTest.php
```

I test SQLite coprono verifica, conferma, consegna ordinaria, firme, notifiche,
rimozione dell’indicazione e trasferimenti successivi.

Schede delle impostazioni:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/SettingsTest.php tests/Feature/ProfileFieldsTest.php tests/Feature/UserTimezoneTest.php tests/Feature/PushSubscriptionTest.php
```

Questi test coprono le schede Profilo, Account e privacy, Notifiche, Trasferimento
account e Importa ed esporta, i ritorni dopo salvataggi ed errori e gli esempi tradotti dei trasferimenti
con il nome configurato dell’istanza.

Verifiche federate da eseguire su un’istanza online:

- Trasferire un account Mastodon su Openbook dopo aver preparato l’alias.
  Verificare che i Follow in arrivo rispettino l’approvazione della destinazione.
- Trasferire un account Openbook su Mastodon. Verificare la ricezione del Move
  firmato, il trasferimento dei follower remoti e la notifica #116 ai follower locali.
- Verificare avviso e normale utilizzo del vecchio account, poi rimuovere
  l’indicazione e controllare l’Update dell’Actor con `movedTo: null`.

Queste verifiche restano da eseguire e richiedono un’istanza raggiungibile dai
server remoti. I test automatici coprono flussi e payload locali, ma non
sostituiscono la verifica di interoperabilità.

Arrivo e alias degli account (#117):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/AccountAliasesTest.php tests/Feature/Federation/RemoteAccountMoveTest.php tests/Unit/Infrastructure/Security/LinkedData/LinkedDataSignatureTest.php
```

I test coprono proprietà, alias pubblici canonici, limiti/rimozione, firme degli
Update, destinazioni locali verificate, follow invariati e normale approvazione
dei follow in arrivo. Restano coperte le regressioni #116 sulle destinazioni
remote e sulla consultazione dei profili. Questo stadio non aggiunge migration.

Fusi orari personali:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test tests/Feature/UserTimezoneTest.php tests/Feature/UserDatePresentationTest.php tests/Unit/Support/UserDateTest.php
node tests/Browser/timezones.cjs
OPENBOOK_TEST_TIMEZONE_MYSQL=1 APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test tests/Feature/UserTimezoneMysqlTest.php
```

Il test MySQL usa le variabili `OPENBOOK_TEST_MYSQL_HOST`, `PORT`, `SOCKET`,
`USERNAME` e `PASSWORD` (con lo stesso prefisso); crea uno schema casuale e lo
elimina al termine, senza migrare il database dell'applicazione. Verifica anche
rollback, nullabilità e default. I test browser usano SQLite in memoria e
controllano rilevamento, cambio dispositivo, scelta esplicita, ospiti, eventi,
assenza di JavaScript/Intl/cookie e mancata ripetizione delle risposte POST.
Le schermate di verifica vengono salvate nella directory temporanea di sistema.

Scelta della lingua e pagine di accesso (#103):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test tests/Feature/LocalePreferenceTest.php tests/Feature/GuestLocaleTest.php tests/Feature/Auth tests/Feature/SettingsTest.php tests/Feature/HomeGuestFeedTest.php
node tests/Browser/locales.cjs
```

Il controllo browser opzionale usa il layout e il CSS di produzione con
SQLite in memoria; verifica desktop/mobile, tastiera, invio del form e menu
ospite senza JavaScript. Come gli altri test browser richiede Playwright;
`BROWSER_EXECUTABLE_PATH` permette di indicare un Chromium già installato.

La traduzione finlandese si verifica anche con:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test tests/Unit/Support/FinnishTranslationsTest.php tests/Unit/Support/CompactNumberTest.php tests/Feature/FinnishLocaleTest.php
OPENBOOK_BROWSER_LOCALE=fi node tests/Browser/locales.cjs
```

I controlli verificano tutti e cinque i file, chiavi, segnaposto e intervalli
dei plurali, oltre a selezione, registrazione, date e separatore decimale.

Le fondamenta dei sondaggi locali (#121) si verificano con:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Polls/LocalPollFoundationsTest.php tests/Feature/Posts/PostPublicationStagerTest.php
```

I casi coprono persistenza, validazione, Question canonica/outbox, audience,
immutabilità, cancellazioni e scadenza dopo lo staging video.
`LocalPollMysqlPersistenceTest` riusa i casi su schemi casuali isolati e aggiunge
rollback della migration ed EXPLAIN. Si abilita con `OPENBOOK_TEST_POLLS_MYSQL`
e le variabili `OPENBOOK_TEST_MYSQL_*` descritte sotto, selezionando la classe
con `--filter=LocalPollMysqlPersistenceTest`.

Raccolta dei voti locali/federati (#121, sprint 2):

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Polls/LocalPollVotingTest.php tests/Feature/Federation/LocalPollVoteImportTest.php
```

`LocalPollMysqlVotingTest` ripete i casi di dominio in schemi MySQL isolati e
verifica invii concorrenti reali e piani EXPLAIN. Attivare le stesse variabili
opt-in e selezionare `--filter=LocalPollMysqlVotingTest`. Lo sprint 2 non aggiunge
migration. Queste verifiche usano direttamente servizi e inbox.

Risultati e chiusura programmata (#121, sprint 3):

```bash
php artisan migrate
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Polls/PollResultsTest.php tests/Feature/Console/ProcessPollsCommandTest.php
```

La nuova migration aggiunge gli snapshot transitori `poll_distributions`.
Per una prova manuale, `php artisan openbook:process-polls --max-time=5` prepara
i job e `php artisan openbook:deliver --max-time=5` li invia; `openbook:cron`
include già entrambi. `--limit=1 --batch-size=1` sul comando dei sondaggi consente
di osservare un avanzamento parziale e riprenderlo al passaggio seguente.
`PollResultsMysqlTest` aggiunge processi cron concorrenti reali e piani EXPLAIN
per ogni ramo UNION dei destinatari, con le stesse variabili opt-in per schemi
isolati descritte sotto.

Interfaccia di creazione/voto (#121, sprint 4):

```bash
php artisan route:clear
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --compact tests/Feature/Polls/LocalPollUiTest.php tests/Feature/Polls/RemotePollUiTest.php
node tests/Browser/polls.cjs
```

Il controllo browser opzionale renderizza partial Blade di produzione con
modelli in memoria, carica JS/CSS reali e verifica campi desktop/mobile,
limiti Unicode, reset/editing del modal, passaggio ai risultati e conferma del
voto. Richiede Playwright e un browser installato (`BROWSER_EXECUTABLE_PATH`
per sceglierlo). I link nativi sono verificati con JavaScript disabilitato;
i test HTTP coprono controlli delle opzioni e invii HTML. PHPUnit usa un percorso
isolato per la cache delle route, evitando quelle obsolete dell’istanza locale.

Per il ritorno dal dettaglio post, una verifica browser opzionale usa JS/CSS
di produzione con una fixture HTTP locale per i redirect dei form normali:
`node tests/Browser/post-back.cjs`. Richiede Node.js, Playwright e un browser
installato sulla macchina di sviluppo; non sono requisiti dell'applicazione
o dell'hosting. `BROWSER_TYPE` (default `chromium`) e `BROWSER_EXECUTABLE_PATH`
permettono di scegliere un browser esistente. La verifica integra PHPUnit e
non sostituisce una prova della web app installata su un dispositivo iOS reale.
Il test browser del ritorno dal post attende l’elaborazione di `hashchange`,
oltre al cambio URL, e confronta sessione e cronologia dopo avanti, indietro,
refresh e ritorno alla lista.

Per l’editor del profilo, `node tests/Browser/profile-fields.cjs` verifica il
partial Blade, JS e CSS di produzione con valori preesistenti, pulsanti di
aggiunta/rimozione, limite condiviso di otto campi e layout desktop/mobile.
Richiede anche PHP e usa gli stessi strumenti browser opzionali descritti sopra.

Il progetto usa PHPUnit. La suite gira di default su SQLite in memoria (vedi
`phpunit.xml`), quindi non richiede un database MySQL per essere eseguita:

```bash
php artisan test
```

Il dettaglio riservato all’autore (#121, sprint 5) è coperto da `PollVotersTest`;
`PollVotersMysqlTest` ripete paginazione/cancellazione e verifica i piani reali
con 211 votanti del sondaggio e 1.000 di un altro sondaggio. La fixture browser
verifica anche schede, tastiera e paginazione desktop/mobile, senza accedere
al database applicativo.

Le basi dei sondaggi remoti sono coperte da `RemotePollDocumentTest` e
`RemotePollPersistenceTest`, con fixture anonimizzate in
`tests/Fixtures/Federation/Polls`. Una verifica MySQL/MariaDB opzionale esegue
gli stessi casi di persistenza, voto e transazione della coda database, oltre
al rollback della migration, alla concorrenza tra due processi e a `EXPLAIN`:

```bash
OPENBOOK_TEST_POLLS_MYSQL=1 php artisan test --filter=RemotePollMysqlPersistenceTest
```

Usa `OPENBOOK_TEST_MYSQL_HOST`, `OPENBOOK_TEST_MYSQL_PORT`,
`OPENBOOK_TEST_MYSQL_USERNAME` e `OPENBOOK_TEST_MYSQL_PASSWORD`; facoltativamente
`OPENBOOK_TEST_MYSQL_SOCKET` seleziona un socket Unix. L'account deve poter
creare ed eliminare schemi con nome `openbook_polls_test_*`. Ogni caso crea un
proprio schema casuale, esegue le migration applicative e lo elimina al termine.
Non migra né svuota il database applicativo locale o quello indicato da
`OPENBOOK_TEST_MYSQL_DATABASE`. Usare un server usa-e-getta. Senza il flag di
attivazione questi test vengono saltati; quando è attivo, errori di connessione
o migration fanno fallire il test. Importazione e servizio di voto sono
implementati e collegati all'interfaccia web. `RemotePollVotingTest`
verifica scelta singola/multipla, idempotenza, rollback e retry;
`DeliverPollVotesCommandTest` verifica il consumo attraverso il comando esistente.
`RemotePollUiTest` copre feed, dettaglio, form HTML/JSON, privacy tra utenti,
avvisi sui contenuti e snapshot delle scelte precedenti.
Per eseguirli con la configurazione di produzione in cache, indicare un percorso
inesistente per `APP_CONFIG_CACHE`, preservando così la cache locale:

```bash
APP_CONFIG_CACHE=/tmp/openbook-test-config.php php artisan test --filter=Poll
```

### Verifica manuale dei sondaggi

Dopo aver aggiornato questo ramo sull’istanza di sviluppo, aggiornare le cache
obsolete e applicare le migration (i test automatici non modificano il database
applicativo):

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan migrate
```

Se il deployment usa normalmente cache di configurazione/route, rigenerarle
con la consueta procedura di pubblicazione dopo l’aggiornamento.

1. Aprire un post remoto già importato tramite `polls.post_id`, anziché
   `source_item_id`. Controllare testi delle opzioni, conteggi e stato aperto/chiuso.
2. Creare un sondaggio locale pubblico di cinque minuti. Partire da due opzioni,
   aggiungerle/rimuoverle e provare scelta singola e multipla, fino a sei risposte.
   Includere un allegato e un avviso sul contenuto in un esempio.
3. Da un secondo account mostrare i risultati, tornare alle opzioni e votare.
   Verificare risposta personale e scelta definitiva; un altro account deve
   vedere soltanto i propri dati. Come autore aprire «Dettaglio risposte»,
   cambiare opzione e consultare i partecipanti. Lo stesso URL deve respingere
   altri account, compresi amministratori/moderatori.
4. Ripetere in una community locale privata e con visibilità follower.
   Verificare che un account escluso non apra il post e non voti. Correggere il
   testo: definizione e visibilità devono restare fissate.
5. Dopo cinque minuti verificare la chiusura, poi eseguire
   `php artisan openbook:cron`: elabora inbox, registra chiusure, prepara snapshot
   e tenta le normali consegne accodate. Nessuna nuova voce cron richiesta.
   Per la sola preparazione: `php artisan openbook:process-polls --max-time=5`.
6. Con una seconda istanza federata di test verificare pubblicazione, voti
   singoli/multipli in ingresso e Update di risultati/chiusura. Queste prove
   reali integrano le fixture HTTP automatiche e richiedono account di test.

La suite copre bootstrap/installer/autenticazione, il dominio sociale locale
(post, media, commenti, reazioni, follow, feed, notifiche), l'identita' e la
federazione sociale (`tests/Feature/Federation`,
`tests/Unit/Infrastructure/Security`), le community (`tests/Feature/Communities`)
e i casi di interoperabilita' (Article/Video/allegati, fallback Atom Pixelfed,
API blog Wafrn, Accept Lemmy, Mondo/scopri, rullino profilo). In particolare:

- generazione e verifica delle HTTP Signatures, `SsrfGuard` (rifiuto di IP
  privati/loopback/riservati, DNS che risolve a indirizzi non pubblici, fallimenti di
  risoluzione), WebFinger, NodeInfo, content negotiation su profilo/post/commento
  (incluse le regole di visibilita' per i richiedenti anonimi e la rappresentazione
  `Tombstone`), collezioni outbox/followers/following, e l'intero ciclo di vita
  dell'inbox a livello di trasporto (attivita' firmata correttamente, firma mancante,
  corpo manomesso, Actor firmatario non corrispondente, content-type non supportato,
  corpo troppo grande, deduplicazione, inbox condiviso);
- `InboxActivityProcessor`: ogni tipo di attivita' (`Follow` verso account aperti e
  protetti, `Accept`/`Reject` di un follow in uscita, `Undo`, `Like`/`Announce` e i
  relativi `Undo`, `Create` di un post o di una risposta rilevante, `Delete`, `Update`
  con oggetto `Note` e con oggetto `Person`/`Group` incluso il rifiuto di un documento
  che dichiara un id diverso dall'Actor firmatario) e il caso di un Actor firmatario
  sconosciuto;
- `ActivityDelivery` e `DeliverActivityJob`: deduplicazione delle inbox condivise,
  esclusione di follower locali/non ancora accettati, regole di consegna per i
  messaggi diretti, firma HTTP corretta della richiesta in uscita, fallimento
  permanente senza chiave privata, ritentativo su risposta non 2xx;
- sospensione remota (`RemoteSuspensionTest`): flag di origine e Update ricevuti,
  riattivazione senza annullare la moderazione locale, conservazione dei contenuti,
  interfaccia di consultazione, rifiuto delle richieste dirette di interazione,
  commenti sospesi sotto post/eventi altrui e pausa delle condivisioni automatiche
  senza perdita della preferenza;
- `RemoteActorResolver::resolveByUri()`/`resolveByHandle()`: fetch e cache con TTL,
  rifiuto di un documento che dichiara un id diverso da quello richiesto, rifiuto di
  trattare un URI locale come remoto, risoluzione WebFinger;
- l'intero ciclo "azione locale → attivita' consegnata a un Actor remoto" end-to-end
  per `Follow`/`Unfollow`, `Like`/`Unlike`, `Announce`/`Unannounce`, pubblicazione ed
  eliminazione di post e commenti (inclusi i controller HTTP);
- la ricerca remota (`/cerca`) e la pagina profilo di un Actor remoto in cache
  (`/attori/{id}`, incluso il redirect al profilo canonico quando l'id corrisponde a
  un Actor locale);
- `RemoteFollowCollectionsFetcher` (`RemoteFollowCollectionsFetcherTest`):
  visitando il profilo remoto si interrogano le collection `followers` e
  `following` (TTL `OPENBOOK_COLLECTIONS_CACHE_TTL_HOURS`, default 24h) per
  i conteggi `totalItems` e un campione della prima pagina, senza scrivere
  nel grafo locale `follows`; la data di iscrizione arriva da `published`
  sul documento Person;
- `RemoteOutboxFetcher` (`RemoteOutboxFetcherTest`): al primo caricamento della pagina
  profilo di un Actor remoto (o dopo la scadenza della cache) i post pubblici piu'
  recenti del suo outbox reale vengono recuperati e mostrati, esclusi risposte, post
  non pubblici e qualunque item che dichiari un autore diverso dal titolare
  dell'outbox; se l'outbox e' uno stub (solo `totalItems`, tipico Pixelfed) si usa
  il feed Atom; nessuna nuova richiesta prima della scadenza della cache; il tentativo
  viene comunque registrato quando il server remoto non risponde, per non rallentare i
  caricamenti successivi; nessuna notifica di menzione viene generata per contenuto
  recuperato in questo modo (non e' un evento "appena successo");
- `RemoteRepliesFetcher` (`RemoteRepliesFetcherTest`, `SignedFetchTest`): aprendo un
  post remoto (es. dal feed di chi si segue) viene interrogata la collection `replies`
  della Note originale (TTL `OPENBOOK_REPLIES_CACHE_TTL_HOURS`), seguendo anche la
  paginazione `next` tipica di Mastodon (dove la prima pagina e' spesso vuota); dallo
  stesso GET della Note si aggiornano i contatori Mi piace/Condivisioni delle card
  (`likes`/`shares` `totalItems`; al massimo un GET in piu' per collection se il
  totale non e' inline); i GET sono firmati (authorized fetch) con la chiave
  dell'utente che visita o di un Actor locale di fallback; i commenti
  pubblici/non elencati di terzi vengono messi in cache
  senza generare notifiche; le risposte a commenti gia' noti sotto lo stesso post
  vengono annidate correttamente;
- gli elenchi follower/seguiti (`FollowListTest`): visibilita' pubblica per un profilo
  locale, esclusione delle richieste ancora in attesa, stato corretto del pulsante
  segui/smetti di seguire per riga, redirect dell'elenco di un Actor remoto quando
  corrisponde in realta' a un account locale, obbligo di autenticazione per l'elenco
  di un Actor remoto.
- la pagina Impostazioni (`SettingsTest`): obbligo di autenticazione, aggiornamento di
  nome/biografia/campi del profilo con sincronizzazione del nome sull'Actor federato, caricamento e
  sostituzione dell'avatar (con rimozione del file precedente), rifiuto di un file non
  immagine, cambio della lingua dell'interfaccia effettivamente applicato dal
  middleware, propagazione della visibilita' predefinita al composer, sincronizzazione
  di "account protetto" fra `user_settings` e l'Actor, esclusione dai suggerimenti
  quando l'account non e' piu' "discoverable", invio di un `Update` federato ai
  follower remoti quando cambia il profilo pubblico, l'opzione "Account protetto"
  o i flag `discoverable`/`indexable` (e la sua assenza quando cambiano solo
  preferenze puramente locali); il servizio di
  caricamento immagini di
  profilo (`ProfileImageUploaderTest`): percorsi separati per avatar/copertina,
  rimozione del file precedente, validazione di tipo e dimensione, ridimensionamento
  delle immagini sovradimensionate, permessi della cartella creata al primo upload
  corretti anche con una `umask` restrittiva del processo PHP (verificata anche per
  gli allegati dei post in `MediaUploaderTest`); la costruzione dell'URL di
  avatar/copertina (`Tests\Unit\Domain\Profiles\ProfileTest`), per evitare regressioni
  sulla scelta del disco "public" al posto dell'helper `asset()`.
- la sezione "Mondo" (`WorldTest`): la timeline mostra solo post remoti pubblici gia' in
  cache ed esclude sia i post locali sia quelli remoti non pubblici; obbligo di
  autenticazione; classifica degli account da scoprire (priorita' ai follower locali
  accettati, poi all'attivita' piu' recente), esclusione di chi non ha ne' un follower
  locale ne' un post in cache, esclusione di chi e' gia' seguito dal visitatore;
  pagina `/mondo/scopri` con elenco completo e scorrimento infinito.
- visibilita' delle condivisioni (`AnnounceVisibilityTest`): un post condiviso (locale,
  di un altro Actor locale, o remoto) compare sul profilo e nel feed personale di chi
  lo ha condiviso con l'indicazione "ha condiviso questo post"; ordinamento per momento
  della condivisione anche quando il post originale e' molto piu' vecchio; nessuna
  indicazione ridondante quando si condivide un proprio post; scomparsa dal profilo
  dopo aver ritirato la condivisione; nessuna indicazione sul profilo dell'autore
  originale.

Un piccolo sottoinsieme di test (`Tests\Feature\Installer\InstallerMysqlFlowTest`)
verifica specificamente il passo 2 dell'installer (connessione e migration) contro un
**vero server MySQL/MariaDB**, perche' questo comportamento non e' esercitabile in modo
affidabile con SQLite. Questi test si auto-saltano (`markTestSkipped`) se non trovano
un server raggiungibile con le credenziali indicate dalle variabili d'ambiente
`OPENBOOK_TEST_MYSQL_HOST`, `OPENBOOK_TEST_MYSQL_PORT`, `OPENBOOK_TEST_MYSQL_DATABASE`,
`OPENBOOK_TEST_MYSQL_USERNAME`, `OPENBOOK_TEST_MYSQL_PASSWORD` (valori di esempio gia'
presenti in `phpunit.xml`). Per eseguirli davvero, avvia un'istanza MySQL/MariaDB
usa-e-getta con quelle credenziali prima di lanciare la suite.
