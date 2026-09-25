> Documentazione: [Italiano](README.it.md) · [English](README.md)

## Test

Il progetto usa PHPUnit. La suite gira di default su SQLite in memoria (vedi
`phpunit.xml`), quindi non richiede un database MySQL per essere eseguita:

```bash
php artisan test
```

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
  nome/biografia/link con sincronizzazione del nome sull'Actor federato, caricamento e
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
