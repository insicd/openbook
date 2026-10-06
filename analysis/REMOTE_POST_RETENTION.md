# Retention dei post remoti — issue #106

Stato: **analisi congelata nel commit `26a090d`; R1 committato, R2 verificato e approvato**.
Aggiornato il 6 ottobre 2026.

Riferimento: [issue #106](https://github.com/insicd/openbook/issues/106).
Il branch `issue_106` parte da `issue_107`, commit `79d4e34`, comprendendo anche
il lavoro per #105 e #107.

Questo documento è stato riordinato dopo il chiarimento sul ciclo di vita dei
commenti. Sostituisce le precedenti ipotesi di riferimenti residui e di protezione
separata dei commenti locali: quelle ipotesi **non fanno parte dell'intervento**.

## Obiettivo

Contenere la crescita del database dovuta ai post remoti, soprattutto quelli
ricevuti dai relay, senza modificare il ciclo di vita dei post locali.

La issue proponeva tre durate: contenuti senza rilevanza locale, contenuti con
hashtag e una scadenza massima. La discussione ha concordato una semplificazione:
**due sole categorie, Pertinenti e Non pertinenti**, ciascuna con una durata.
Nessuna durata intermedia per hashtag non seguiti e nessuna terza scadenza
universale sovrapposta. I 90 giorni e l'anno della issue restano esempi.

## Regole confermate

| Tipo di post | Retention del post | Retention dei commenti |
| --- | --- | --- |
| Locale | Non viene cancellato da questa manutenzione | Nessun commento viene cancellato da questa manutenzione, neppure remoto. |
| Remoto non pertinente | Durata breve configurabile | Alla scadenza si elimina tutto il thread, anche i commenti locali. |
| Remoto pertinente | Durata lunga configurabile, la «hard deadline» | Alla scadenza si elimina tutto il thread, anche i commenti locali. |

**Eccezione confermata:** un post remoto citato da un contenuto locale è
considerato incorporato (embedded) in quel contenuto e **non viene cancellato
dalla retention**, neppure alla durata lunga. Si conserva il post completo,
non soltanto un URL o un riferimento vuoto. L'eccezione comprende le citazioni
nei messaggi locali; il thread segue il post e non viene pulito autonomamente.
La protezione dipende dall'esistenza della citazione locale, non da una memoria
permanente di citazioni passate.

«Non viene cancellato» riguarda **questa manutenzione**: restano i flussi
ordinari di cancellazione dell'autore e moderazione. Non vengono modificati.

I commenti non hanno un timer autonomo. Un commento remoto recente o un thread
molto attivo all'estero non prorogano la conservazione. Un commento locale rende
pertinente il post remoto, ma non impedisce la cancellazione alla durata lunga.
Non si separa il destino del commento locale da quello del suo post radice.

### Definizione di pertinenza

Un post remoto è **pertinente** se:

1. Può comparire come voce autonoma nella Home di almeno un utente locale,
   secondo le regole correnti del feed; **oppure**
2. Ha almeno un commento di un attore locale, anche annidato sotto commenti remoti.

Per i post remoti, `FeedQuery::forActor` ammette a Home:

- Post di autori seguiti con follow accettato.
- Post di community seguite.
- Post pubblici con hashtag seguiti.
- Boost dell'utente stesso o di attori seguiti, comprese le attività Group ammesse
  dal feed. Gli announce impliciti delle citazioni Person non sono boost, come
  stabilito da #107.

È confermato che **il boost proprio conta**, anche senza altri follower locali:
il post compare nella Home di chi lo ha condiviso.

«Può comparire» significa ammissibile, non presente nella prima pagina o
recentemente visualizzato. Non registrare gli accessi né uno storico di pertinenza.
La manutenzione deve verificare l'esistenza di un utente locale per il quale
valgono le condizioni del feed e i vincoli di visibilità, senza eseguire e paginare
l'intera Home di ogni utente.

Un post remoto nel perimetro che non soddisfa nessuna delle due condizioni è
**non pertinente**. Il caso tipico è il post visibile soltanto in Mondo.
L'assenza da Mondo per un filtro di visualizzazione non è, da sola, una ragione
per cancellare contenuti privati fuori dal perimetro.

Like e menzioni non aggiungono una terza categoria. Le citazioni locali sono
invece un'esclusione dalla cancellazione, non un'altra durata né un boost Home.

### Età e passaggio fra categorie

I giorni si contano dall'**importazione in Openbook**, non dalla pubblicazione
originale. Per i post attuali il primo inserimento è rappresentato da `created_at`.
Non usare `updated_at`, che cambia anche per normali aggiornamenti.

La pertinenza si valuta sulle relazioni correnti. Un nuovo follow, boost o commento
locale può far applicare la durata lunga; perdere l'ultima condizione di pertinenza
può far applicare quella breve. Il passaggio **non rinnova la data d'importazione**.

Home oggi ordina i post per `published_at` e i boost per la data dell'Announce,
non per `posts.created_at`. Un commento non riporta il post in cima. Questa issue
non cambia l'ordinamento del feed.

### Cancellazione completa e nuova importazione

Alla scadenza, salvo l'esclusione per citazioni locali, si cancella fisicamente
il post remoto e tutto il suo albero di commenti, indipendentemente dall'origine locale o remota dei commenti.
Si puliscono le relazioni collegate; **non resta un riferimento al post scaduto**.

Se la stessa URI arriva nuovamente, è una nuova importazione con nuova data e
nuovo periodo di conservazione. Non serve un campo di recupero, uno stato di
scadenza o una blacklist permanente delle URI eliminate. Il vecchio ID e il
vecchio permalink locale non vengono ripristinati automaticamente.

**Confermato: la manutenzione non genera alcuna attività federata**, nemmeno
per i commenti locali eliminati con il thread. Niente Delete, Undo o falsi
Tombstone. Le copie già consegnate ad altre istanze possono restare; questa è
una pulizia del database locale, distinta dalla cancellazione ordinaria
dell'autore. Non richiamare implicitamente i suoi flussi di delivery.

### Perimetro e configurazione

- Escluse conversazioni dirette e messaggi privati.
- I post followers-only non hanno un'esclusione aggiuntiva: se locali non si
  cancellano; se remoti seguono le due durate e la pertinenza secondo Home,
  rispettando i follow e la visibilità già definiti dal feed.
- Esclusi i post generati da attori locali. L'esclusione non si estende ai loro
  commenti quando il post radice è remoto.
- Un post esterno boostato da una community locale resta remoto e segue la
  pertinenza: nessuna esenzione automatica dovuta a chi lo condivide.
- Esclusi i post remoti incorporati mediante citazioni in contenuti locali.
- **Nessuna eccezione di moderazione:** alla scadenza vengono eliminate anche
  le segnalazioni collegate al post e ai commenti, aperte o chiuse. Nessun
  archivio separato delle evidenze. Le esclusioni per post locali, conversazioni
  dirette e citazioni locali restano valide.
- Eventi fuori scope. RSS segue il percorso di pubblicazione locale, non quello
  delle copie remote ActivityPub, e non va classificato remoto soltanto perché
  importa materiale esterno.
- Media e file sul disco fuori scope: questa retention non introduce una loro
  cancellazione o un garbage collector. Restano le cascade sulle associazioni
  che il database già applica.
- Due campi: **Conservazione dei post pertinenti** e **Conservazione dei post
  non pertinenti**. Il testo di aiuto spiega i criteri di pertinenza e la
  cancellazione dell'intero thread, compresi eventuali commenti locali.
- **Default: entrambe le durate a 0**, nessuna cancellazione. `0` disabilita la
  retention della categoria, senza una scadenza universale che la scavalchi.
- La UI impedisce una durata dei pertinenti inferiore a quella dei non pertinenti
  quando entrambe sono attive; `0` resta disabilitazione, non una durata di zero giorni.
- I post remoti già `deleted` seguono le stesse policy e le stesse esclusioni:
  lo stato non li esenta dalla retention e non introduce una terza categoria.
- Cron CLI dedicato, separato da quello ordinario, **senza accesso HTTP**.
  L'esecuzione può durare anche mezz'ora; le transazioni restano brevi.

Ridurre una durata rende eleggibili al cron successivo i post già abbastanza
vecchi. Disabilitarla non ripristina dati eliminati. Prima della prima esecuzione
è utile un dry-run; un backup permette il ripristino anche dei contributi locali
eliminati con i thread remoti.

## Confronto con altri software

La ricerca usa fonti ufficiali e snapshot del codice consultato, non una promessa
sul comportamento di tutte le versioni distribuite.

| Software | Funzione osservata | Indicazione per Openbook |
| --- | --- | --- |
| Mastodon | `tootctl statuses remove`: rimuove vecchi post remoti non più rilevanti, con default 90 giorni e batch di 1.000. Protegge risposte e varie relazioni locali; normalmente anche gli autori seguiti. `--clean-followed` non elimina tutte le protezioni. | Openbook sceglie una politica diversa: anche i thread pertinenti scadono, con cancellazione dei commenti locali. Non copiare automaticamente le protezioni di Mastodon. |
| Friendica | `ExpirePosts` distingue thread remoti e post pubblici senza utenti che li conservino. Usa la ricezione e protegge, fra l'altro, thread con contributi locali e contenuti personali; lavoro limitabile. | Utile il riferimento alla ricezione e il controllo sul thread. Openbook non adotta la protezione permanente dei contributi locali sotto post remoti. |
| Pixelfed | Nei comandi esaminati, `media:gc` pulisce media non associati escludendo messaggi diretti; `storage:maintenance` pulisce file temporanei e directory vuote. Non è emersa una politica generale equivalente per i post remoti. | Pulizia media e retention post sono distinte. La ricerca non dimostra l'assenza della funzione in ogni versione o percorso amministrativo. |
| Akkoma | `prune_objects` elimina oggetti remoti vecchi; offre opzioni per conservare autori seguiti, thread rilevanti, contenuti non pubblici e per limitare il lavoro. Il recupero successivo è contemplato. | Il limite sui post non limita da solo tutte le righe figlie eliminate; recuperare dopo un hard delete è una nuova importazione. |

Fonti:

- Mastodon: [comando](https://docs.joinmastodon.org/admin/tootctl/#statuses-remove) e
  [codice, snapshot 955f7f9](https://github.com/mastodon/mastodon/blob/955f7f98d81aeff10ae3238e615a73096f4cc12f/lib/mastodon/cli/statuses.rb).
- Friendica: [ExpirePosts, snapshot develop ec809e9](https://github.com/friendica/friendica/blob/ec809e9222fd0420b99a86e0f90a6285efa48de1/src/Worker/ExpirePosts.php)
  e [impostazioni admin](https://github.com/friendica/friendica/blob/develop/src/Module/Admin/Site.php).
  Il significato di 0 per alcune durate Friendica differisce da quello concordato
  qui e non va copiato.
- Pixelfed, snapshot dev 67313ab: [media:gc](https://github.com/pixelfed/pixelfed/blob/67313ab3dd66b076f00bfa2269354576e6dfe123/app/Console/Commands/Internal/GarbageCollectorMedia.php),
  [storage:maintenance](https://github.com/pixelfed/pixelfed/blob/67313ab3dd66b076f00bfa2269354576e6dfe123/app/Console/Commands/Internal/StorageMaintenance.php),
  [catalogo comandi](https://github.com/pixelfed/pixelfed/blob/67313ab3dd66b076f00bfa2269354576e6dfe123/app/Console/Commands/README.md).
- Akkoma: [pruning remoto](https://docs.akkoma.dev/stable/administration/CLI_tasks/database/#prune-old-remote-posts-from-the-database).
- ActivityPub: [Delete](https://www.w3.org/TR/activitypub/#delete-activity-inbox).
  La distinzione fra manutenzione della copia e cancellazione dell'autore è
  una deduzione progettuale, non una politica di retention imposta dal protocollo.

## Openbook oggi: percorsi e dipendenze

[`DatabaseMaintenanceService`](../app/Application/Services/DatabaseMaintenanceService.php)
pulisce dati operativi, non post pubblicati. `openbook:purge-database` viene
richiamato dal cron ordinario; esiste una pagina amministrativa del database.
Non aggiungere la cancellazione dei contenuti implicitamente al suo pulsante
attuale. Riutilizzare `SystemSetting` e le convenzioni di `InstanceSettings`
per i due parametri.

[`FeedQuery`](../app/Application/Queries/FeedQuery.php) definisce Home e Mondo.
Riutilizzare o mantenere condivisi i predicati necessari, senza copiare ranking,
paginazione e ordinamento per costruire la selezione di retention.

`Post::isRemote()` verifica `uri`; controllare anche l'attore remoto e le
esclusioni. Non esiste una provenienza relay permanente sul post: il riferimento
nell'inbox è transitorio. I contatori globali di reazioni non identificano
azioni locali.

`RemoteNoteUpserter` è condiviso dai percorsi di ricezione e fetching. Dopo un
hard delete, l'importazione della stessa URI genera una nuova riga. I commenti
hanno una differenza importante: l'upserter può assegnare a `created_at` la data
di pubblicazione remota. Non serve reinterpretarla come importazione, perché
la retention dei commenti dipende soltanto dal post.

| Relazione | Comportamento attuale | Verifica richiesta |
| --- | --- | --- |
| Commenti e parent dei commenti | FK con cancellazione in cascata | L'intero thread viene cancellato, compresi i locali; nessuna pulizia autonoma sotto post locali. |
| `quoted_post_id` | SET NULL quando si elimina il post citato | Escludere dalla cancellazione gli originali citati da contenuti locali, anche messaggi; conservare relazione, card e `quoteUrl`. |
| Announce | Cancellazione in cascata | Eliminare anche le azioni locali collegate; non emettere Undo implicitamente. |
| Like, menzioni, notifiche | Relazioni polimorfiche senza FK al target | Riconciliazione generale degli orfani, indipendente dalla retention e senza passare gli ID dei post cancellati. |
| Allegati, hashtag, posizione | Associazioni eliminate | Lasciare lavorare le cascade; media e file fuori scope. Non eliminare la directory hashtag. |
| Report sul post/commenti | Cancellazione in cascata | Cancellazione confermata anche per segnalazioni aperte; nessuna protezione o archivio separato. |
| Community | Possibili contatori denormalizzati | Distinguere boost e appartenenza effettiva alla community; correggere i contatori quando necessario. |

La nuova regola autorizza la rimozione delle righe dei commenti anche locali
insieme al post remoto e le cascade sulle loro associazioni. Non aggiungere
alla retention una cancellazione di media o file sul disco.

## Esito della review e convenzioni operative

Le domande sul riferimento scaduto, sul suo rendering, sulla risposta AP e sulle
azioni da permettere prima del recupero sono **eliminate**: non esiste uno stato
residuo da implementare.

Citazioni, federazione della pulizia e moderazione sono chiuse dalle decisioni
riportate sopra. Non servono nuovi campi per memorizzare le URI delle citazioni.

Anche followers-only e community seguono il perimetro già concordato: nessuna
categoria o esenzione ulteriore. Il rispetto della visibilità è una verifica
tecnica del predicato di pertinenza, non una nuova domanda di prodotto.

**Il comportamento centrale è definito.** È confermato inoltre che il registro
audit si conserva: i riferimenti al soggetto di un'azione staff possono mancare
legittimamente, perché il registro è uno storico append-only e non una tabella
figlia da riconciliare. La sanity riguarda le relazioni polimorfiche effettive
di `likes`, `mentions` e `notifications`, coprendo tutti i relativi tipi padre.

Le seguenti convenzioni operative sono state confermate; R1 le traduce nelle
query e nei test senza introdurre nuove macrofunzioni:

1. Durate in giorni interi >= 0; quando entrambe sono attive, la durata dei
   pertinenti non può essere inferiore a quella dei non pertinenti. Nessun minimo
   di conservazione obbligatorio: gli esempi non diventano default.
2. La selezione comprende anche i post remoti già `deleted`: nessun filtro
   `status = published` nel perimetro dei candidati. Restano pertinenza, durata
   dalla data d'importazione ed esclusioni concordate. La normale query Home
   filtra gli stati pubblicati: verificare questa distinzione nel predicato di
   pertinenza senza trasformare lo stato `deleted` in un'esenzione dalla pulizia.
3. `--batch-size=N` limita ciascuna selezione: fino a N post per fascia per giro,
   quindi fino a 2N quando entrambe hanno candidati. `--max-time` limita la durata
   dell'esecuzione, non promette N cancellazioni totali. Il dry-run mostra un solo
   batch per fascia, con un campione limitato di link, senza ripetere gli stessi
   candidati in un ciclo. Il limite per fascia per giro è confermato.

Per la pertinenza conta la presenza di un commento locale, anche se la sua riga
è già `deleted`; non serve uno storico separato. La protezione delle citazioni
vale finché esiste il collegamento da un post locale, inclusi messaggi e righe
locali `deleted`: nessuno scope implicito deve eliminare queste dipendenze. Un
post remoto `deleted` non appare in Home, ma resta soggetto alla retention e può
essere pertinente tramite commenti locali. Queste condizioni vanno coperte da
fixture esplicite in R1.

Nessuna di queste convenzioni richiede nuovi stati di scadenza, media cleanup,
delivery di cancellazioni o un ciclo di vita separato dei commenti.

## Proposta tecnica KISS

Un servizio applicativo seleziona e cancella; un comando CLI orchestra il lavoro;
il controller amministrativo valida e salva le due durate. Nessun nuovo stato
post/commento, campo di recupero, worker obbligatorio o infrastruttura Redis.

### Macrotema 1: selezione e retention

Il nucleo della retention è composto da **due query di selezione**:

| Fascia | Set selezionato |
| --- | --- |
| 1 — Non pertinenti | Post remoti non pertinenti con data d'importazione oltre la durata breve. |
| 2 — Pertinenti | Post remoti pertinenti con data d'importazione oltre la durata lunga. |

Entrambe applicano le esclusioni concordate (post locali, conversazioni dirette,
originali citati da contenuti locali), un ordine stabile e un LIMIT controllato
dal parametro CLI. I due insiemi sono distinti: la query della fascia 1 deve
escludere tutto ciò che il predicato di pertinenza della fascia 2 riconosce.
Se la durata di una fascia è 0, quella fascia non produce candidati.

- **Dry-run:** SELECT dei candidati senza modificare dati; riepilogo distinto per
  fascia e primi N link locali `/posts/{id}`, completi del dominio configurato,
  copiabili nel browser. Il conteggio dei candidati selezionati è limitato dal
  LIMIT; non presentarlo come conteggio totale di tutti i post eleggibili.
- **Esecuzione:** DELETE dalla sola tabella `posts` degli ID selezionati, con
  ricontrollo dei vincoli prima della cancellazione; le FK fanno le cascade.
- Nessuna cancellazione satellite per oggetto, nessuna attività federata e
  nessuna pulizia dei media nel comando di retention.

Parametri del pannello, validazione e istruzioni di cron circondano questo
nucleo, senza cambiare i due criteri di selezione.

### Forma delle cancellazioni

L'unità del batch è il **post radice**. Il nucleo è una DELETE su `posts` per gli
ID del batch; le FK eliminano commenti, announce, pivot di allegati e hashtag,
posizioni e report collegati. Non iterare sui commenti chiamando il deleter
ordinario e non emettere una query per ogni commento o azione satellite.

Non tutte le relazioni attuali hanno però una FK verso il contenuto: like,
menzioni e notifiche usano coppie polimorfiche tipo/ID. La proposta aggiornata
dell'utente è **non pulirle in base al batch dei post**, ma introdurre un comando
generale di riconciliazione, descritto sotto. Non raccogliere gli ID dei commenti
per cancellare le relazioni satellite e non aggiungere logica di media/file.

### Macrotema 2: Database sanity

**Confermato:** per tutte le tabelle polimorfiche si definiscono operazioni di
riconciliazione che associano ciascun tipo alla sua tabella padre. Se l'ID
referenziato non esiste nella tabella corrispondente, si cancella la riga figlia.
Queste operazioni sono query SQL aggregate per tabella/tipo, non job individuali
per ogni record; possono essere orchestrate da un comando CLI senza worker
permanenti. Non si puliscono soltanto i riferimenti a post e commenti.

**Eccezione confermata:** conservare `audit_logs`. I suoi campi `subject_type` /
`subject_id` identificano un soggetto storico, non un padre che deve continuare
a esistere. Conservare anche le voci audit senza soggetto. Nessuna cancellazione
del registro audit viene aggiunta al comando.

Il comando è indipendente dalla retention e utile anche per residui di altre
cancellazioni o interruzioni. Per ciascuna tabella polimorfica:

- Leggere tipo e ID dell'oggetto referenziato.
- Eliminare la riga soltanto se quell'oggetto **non esiste fisicamente** nella
  tabella corretta. Un oggetto con stato `deleted` ma riga ancora presente non
  è un orfano e non viene eliminato da questo criterio.
- Usare query aggregate di tipo NOT EXISTS, limitate a piccoli batch, non un
  controllo o una DELETE per ciascuna riga in PHP.
- Il criterio SQL è tipo + assenza dell'ID nella tabella padre corretta: usare
  NOT EXISTS oppure NOT IN con una subquery non nullable, secondo il piano
  verificato sul database. Non costruire liste globali di ID in memoria.
- Riutilizzare gli alias del morph map di Openbook e coprire i tipi effettivamente
  ammessi per ciascuna relazione: per esempio una notifica può riferirsi anche
  a follow, eventi o partecipazioni, non soltanto a post e commenti.
- Non interpretare un tipo sconosciuto come prova di orfanità: segnalarlo senza
  cancellarlo automaticamente. Le tabelle coinvolte sono definite dal codice,
  non costruite da valori arbitrari del database.
- Verificare il predicato al momento della DELETE e provare le creazioni
  concorrenti. Riepilogo per tabella e dry-run, senza attività federate.

Il comando non riceve l'elenco dei post eliminati e può ripulire orfani già
presenti anche quando entrambe le durate sono a zero. **Confermato: deve poter
girare indipendentemente dalla retention disabilitata**; sono processi fratelli
ma slegati e il comando di sanity non deve fare un ritorno anticipato sulla base
delle due durate. L'esecuzione può seguire
il comando di retention nel cron CLI oppure essere periodica e autonoma. Non
richiede worker permanenti, Redis o un nuovo ciclo di vita dei contenuti.
La collocazione nella manutenzione esistente va definita senza confondere la
sanity degli orfani con l'abilitazione della retention.

La cancellazione delle notifiche orfane deve mantenere coerenti le revisioni
delle notifiche secondo le convenzioni esistenti; le FK eliminano eventuali
righe push collegate. Nessuna pulizia di media/file viene aggiunta a questo
comando nel perimetro corrente.

### Esecuzione CLI della retention

Interfaccia prevista per R3; R2 supporta soltanto `--dry-run`, `--batch-size`
e `--sample`, senza ancora `--max-time`:

```sh
php artisan openbook:prune-remote-posts --batch-size=500 --max-time=1800 --dry-run
```

- Cron dedicato in un orario poco trafficato, con lock contro sovrapposizioni
  compatibile con l'hosting attuale.
- Parametri e istante di riferimento fissati per l'esecuzione.
- Selezione a piccoli gruppi con cursore stabile per data e ID, senza OFFSET
  su righe in cancellazione e senza caricare tutti gli ID in memoria.
- Ricontrollo della pertinenza ed esclusioni prima di cancellare; transazioni
  brevi. Una nuova risposta locale può cambiare fascia mentre il cron lavora:
  definire lock e ordine delle operazioni senza perdita del controllo sulla policy.
- DELETE sui post e cascade; riconciliazione polimorfica separata. La scansione
  deve avanzare anche fra candidati esclusi.
- Limite di tempo complessivo e riepilogo di post selezionati/eliminati per fascia;
  niente log del corpo dei contenuti. Il dry-run usa gli stessi criteri e mostra
  link locali dei primi N candidati. I conteggi delle righe satellite non sono
  necessari al nucleo del comando.
- Nessun COUNT globale costoso a ogni apertura del form amministrativo.
- Un batch di 500 post non significa 500 righe totali: misurare i thread grandi
  e, se necessario, adattare i batch prima di scegliere altre infrastrutture.

Indici e query vanno verificati con EXPLAIN su MySQL/MariaDB, con dati
rappresentativi, per ciascuna categoria e ogni ramo di eventuali UNION ALL.
Aggiungere indici solo se giustificati dai piani reali; SQLite da solo non basta.
Verificare anche le migrazioni e le FK sui database supportati.

InnoDB può riusare lo spazio eliminato senza ridurre subito il file del database.
Niente OPTIMIZE TABLE automatico: compattazione fisica e retention sono separate.

## Piano di lavoro: due macrofasi, quattro sprint ciascuna

Gli sprint sono incrementi verificabili, non stime di settimane. Non si avvia
in questa fase alcuna implementazione. Ogni sprint futuro aggiorna qui lo stato,
riporta i controlli effettuati e lascia un risultato autonomamente verificabile.
Si passa al successivo quando i criteri d'uscita del precedente sono soddisfatti.

Stato iniziale: tutti gli sprint **da iniziare**.

### Macrofase 1 — Retention

#### R1 — Due query e contratto dei parametri

Attività:

- Applicare le convenzioni operative confermate sopra; centralizzare le due durate
  nelle impostazioni esistenti con default 0, senza ancora aggiungere il form.
- Implementare un predicato unico di pertinenza e le due query limitate,
  confrontandole con `FeedQuery::forActor`, `Post::visibleTo` e il filtro Announce
  introdotto da #107. Non importare ranking o paginazione del feed nel selettore.
- Escludere locali, direct/conversazioni e originali citati da contenuti locali;
  applicare età da `created_at`, condizioni del commento locale e ordine stabile.
- Preparare fixture per ogni fonte Home, visibilità followers-only/community
  privata, commenti annidati, quote nei messaggi locali e cambi di fascia.

Verifica / uscita:

- Test mirati delle selezioni: insiemi disgiunti, soglie, 0 indipendente e tutte
  le esclusioni, includendo post già `deleted`. Nessuna cancellazione viene
  implementata in questo sprint.
- EXPLAIN MySQL/MariaDB per entrambe le query e tutti i rami rilevanti, con dati
  rappresentativi; eventuali indici giustificati e migrazioni provate anche SQLite.
- Risultato: query che restituiscono esattamente gli ID attesi per ciascuna fascia.

#### R2 — Comando di anteprima verificabile

Attività:

- Aggiungere il comando di retention nella sola modalità dry-run, usando le
  query di R1 e la lettura delle impostazioni senza un secondo sistema config.
- Gestire batch-size positivo e campione dei link; riportare le fasce disabilitate,
  i conteggi dei candidati selezionati e i primi N URL locali completi.
- Distinguere il conteggio del batch da un totale globale; nessun log del corpo,
  della conversazione o dei destinatari privati.

Verifica / uscita:

- Test CLI su limite, input invalidi, default a zero, entrambe/una sola fascia
  attiva, ordinamento e formato dei link; il database resta identico.
- Prova locale: copiare i link nel browser e confrontare i candidati con le regole.
- Risultato: anteprima utilizzabile per validare la selezione prima di cancellare.

#### R3 — DELETE sui post e funzionamento a batch

Attività:

- Aggiungere la modalità effettiva: DELETE aggregate dalla tabella `posts`,
  cascade per thread e relazioni con FK; niente deleter del singolo commento,
  media cleanup, pulizia post-based delle polimorfiche o attività federate.
- Implementare loop di batch, limite di tempo e lock anti-sovrapposizione secondo
  le convenzioni compatibili con shared hosting. Ricontrollare le condizioni
  prima della DELETE e definire la concorrenza con commenti, follow e nuove quote.
- Verificare i contatori delle community che restano dopo la cancellazione;
  eventuali correzioni aggregate devono restare necessarie e circoscritte.

Verifica / uscita:

- Test su cascades, commenti locali eliminati sotto radici remote e preservati
  sotto radici locali, quote protette, report eliminati, ripetizione e interruzioni.
- Verificare assenza di nuovi job federati e che nessun media/file sia cancellato.
- Provare reimportazione con nuovo ID/data e verificare i casi concorrenti sul DB
  reale. Righe polimorfiche orfane temporanee sono attese fino alla sanity.
- Risultato: retention eseguibile in locale con limite e riepilogo per fascia.

#### R4 — Pannello e consegna della macrofase

Attività:

- Aggiungere i due campi al pannello amministrativo seguendo `InstanceSettings`,
  controller e view esistenti. Preferire una sezione manutenzione coerente con
  l'area database; il form salva soltanto, senza avviare cancellazioni HTTP.
- Testi IT/EN per pertinenza, 0, quote protette e cancellazione dell'intero thread,
  compresi commenti locali e report. Gestire validazione e autorizzazione admin.
- Documentare il cron CLI dedicato, l'anteprima, i limiti e l'assenza di federazione
  in `docs/configuration.md` / `.it.md`; aggiornare changelog secondo la regola.

Verifica / uscita:

- Test mirati del pannello e regressioni dei settings; prova nel browser locale.
- Suite completa, controlli di stile pertinenti e review dell'intera macrofase,
  riportando eventuali limiti della verifica MySQL/MariaDB.
- Risultato: Retention completa e verificabile, disabilitata per default e
  indipendente dalla futura sanity.

### Macrofase 2 — Database sanity

#### S1 — Inventario e query di riconciliazione

Attività:

- Inventariare `likes.likeable`, `mentions.mentionable`, `notifications.notifiable`
  e tutti i tipi effettivamente supportati; associare alias del morph map e
  tabelle padre in una definizione esplicita nel servizio di sanity.
- Conservare il registro audit. Distinguere tipo ignoto da padre mancante e
  oggetto `deleted` ancora presente da oggetto eliminato fisicamente.
- Definire selezioni aggregate di orfani per tabella/tipo con NOT EXISTS o NOT IN
  non nullable, batch limitati e ordinamento stabile, senza liste di padri in PHP.

Verifica / uscita:

- Fixture con righe valide, orfane, padre con stato deleted e tipi sconosciuti,
  includendo eventi, commenti evento, follow e partecipazioni per le notifiche.
- Test delle selezioni senza DELETE; EXPLAIN MySQL/MariaDB e verifica degli
  indici reali per ciascuna famiglia, con migrazioni SQLite se necessarie.
- Risultato: inventario completo e query che identificano soltanto gli orfani.

#### S2 — Riconciliazione di like e menzioni

Attività:

- Implementare DELETE aggregate limitate per like e menzioni, con ricontrollo
  dell'assenza del padre nella query effettiva e senza chiamate per ogni record.
- Rendere le operazioni ripetibili e verificare che non tocchino padri o contenuti
  ancora esistenti, di qualsiasi origine.
- Non leggere le durate di retention né ricevere l'elenco di post eliminati.

Verifica / uscita:

- Test su tutti i tipi supportati, batch successivi, ripetizione senza ulteriori
  cancellazioni, creazioni concorrenti e righe valide con stato deleted.
- Dimostrare funzionamento con entrambe le retention a 0 e su orfani creati
  indipendentemente dalla retention.
- Risultato: pulizia di like e menzioni autonoma e idempotente.

#### S3 — Notifiche e relazioni collegate

Attività:

- Applicare lo stesso criterio alle notifiche per ciascun tipo di oggetto padre,
  preservando quelle che puntano a oggetti ancora presenti.
- Lasciare alle FK la rimozione delle righe push collegate alle notifiche eliminate;
  aggiornare in modo aggregato `notifications_revision` dei destinatari coinvolti.
- Non cancellare il registro audit, media o file e non produrre attività federate.

Verifica / uscita:

- Test su notifiche valide/orfane di post, commenti, follow, eventi e partecipazioni;
  verificare righe push, revisioni, ripetibilità e stato deleted dei padri.
- Verificare che il registro audit resti integro anche con soggetto cancellato
  o nullo e che la riconciliazione non cambi oggetti ancora esistenti.
- Risultato: tutte le tabelle polimorfiche operative riconciliabili.

#### S4 — Comando autonomo e verifica integrata

Attività:

- Aggiungere il comando CLI di sanity con dry-run, batch-size, limite di tempo,
  lock e riepilogo per tabella/tipo; orchestrare i servizi di S2/S3 senza nuovi
  worker obbligatori. Nome indicativo: `openbook:database-sanity`.
- Documentare un cron autonomo, eseguibile anche senza retention; chi desidera
  può eseguire i due comandi in successione senza dipendenza applicativa.
- Aggiornare docs IT/EN e changelog. Niente pulsanti HTTP di esecuzione e niente
  aggancio obbligatorio al cron di retention o al purge esistente.

Verifica / uscita:

- Test CLI di dry-run, limiti, lock e default indipendenti; provare il percorso
  retention -> cascade -> orfani -> sanity, poi sanity da sola con retention a 0.
- Suite completa, controllo dei piani MySQL/MariaDB aggiornati se le query sono
  cambiate e review finale di tutte le modifiche prima della proposta di PR.
- Risultato: due comandi autonomi, operativi e documentati; nessuna rigenerazione
  di contenuti, nessuna attività federata, nessuna perdita del registro audit.

### Modalità di avanzamento

Alla fine di ogni sprint riportare: attività completate, risultato verificabile,
test eseguiti, eventuali problemi reali e stato dei criteri d'uscita. I test mirati
accompagnano le modifiche fin dall'inizio, non vengono rimandati allo sprint finale.
La suite completa chiude R4 e S4; altre esecuzioni si giustificano con cambiamenti
o regressioni. Nessun commit o apertura PR viene effettuato in questa fase di
pianificazione. La gestione futura dei commit si concorda durante il lavoro.

Casi di test: post locali e loro commenti sempre esclusi; post remoti nelle due
fasce; commenti locali che fanno applicare la durata lunga ma vengono poi
cancellati; commenti remoti recenti che non prorogano; boost proprio; follow,
community e hashtag seguiti; passaggi di categoria senza rinnovo; 0 indipendente;
reimportazione con nuova data; quote, report e privati secondo le decisioni;
notifiche, cascades, riconciliazione degli orfani per tutti i tipi ammessi,
conservazione degli oggetti con riga `deleted`, idempotenza, concorrenza e nessuna attività federata
indesiderata. Verifiche su SQLite e MySQL/MariaDB.

## Verifiche della fase di analisi

Esaminati issue, manutenzione attuale, feed, modelli e migrazioni, importazione
remota, serializer, commenti, notifiche e fonti ufficiali dei software confrontati.
Al freeze `26a090d` era stato aggiornato soltanto questo documento: nessuna modifica applicativa,
nessuna cancellazione di dati, nessun test o EXPLAIN dichiarato già eseguito.


## Avanzamento — R1 completato

Implementate le due SELECT limitate in `RemotePostRetentionQuery`, con un unico
predicato di pertinenza, soglia sulla prima importazione e ordine `created_at, id`.
Le due impostazioni di durata sono centralizzate in `InstanceSettings`, entrambe
con default 0; il form arriva in R4. Il selettore riusa le condizioni di visibilità
di `Post` e il filtro Announce di `FeedQuery`, senza ranking o paginazione Home.
Nessun comando di retention o cancellazione è stato introdotto.

Verifiche effettuate:

- 11 test del selettore: soglie, limiti, esclusioni, tutte le fonti Home,
  visibilità, quote locali, commenti annidati, righe `deleted`, cambio di fascia
  e disgiunzione dei risultati. Con i test di feed, community e condivisione
  nei messaggi: **103 test superati, 462 asserzioni** su SQLite.
- Controllo dello stile con Pint e revisione del diff. Per eseguire i test è
  stata bypassata la cache di configurazione locale e impostata la lunghezza
  estratti al default 150: il `.env` locale la imposta a 400, incompatibile con
  la fixture già esistente del test di troncamento. Nessuna modifica al `.env`.
- Migrazioni provate su MySQL locale in un database temporaneo isolato, inclusi
  applicazione e rollback del nuovo indice. Fixture: 20 utenti locali,
  300 attori remoti, 20.000 post remoti, 200 quote locali e relazioni di follow,
  commenti locali, boost e hashtag.
- EXPLAIN di entrambe le query: prima dell'indice il piano usava
  `posts_conversation_arrival_index` e ordinava i candidati; con
  `posts_retention_created_id_index (created_at, id)` entrambe usano una scansione
  per intervallo sulla data, senza quell'ordinamento. I rami correlati usano le PK
  e gli indici esistenti per visibilità/community, follow, commenti, boost e tag;
  MySQL può materializzare le dipendenze locali per le anti-join. Nessuna UNION
  nel selettore, nessun hint o indice aggiuntivo per queste relazioni.

La prova MySQL verifica l'accesso ai dati su fixture; non costituisce una stima
per database di produzione. La suite completa resta prevista alla chiusura R4.
R1 è stato verificato e approvato dall’utente, quindi committato in `64540bf`.
Regola concordata per il seguito: ogni sprint viene committato dopo le verifiche
dell’utente, anche manuali quando disponibili, e prima di iniziare il successivo.


## Avanzamento — R2 pronto per verifica manuale

Aggiunto `openbook:prune-remote-posts --dry-run --batch-size=100 --sample=10`.
Il comando usa le query di R1, mostra un solo batch per fascia e distingue il
conteggio selezionato da un totale globale. Stesso istante di riferimento per
entrambe le selezioni; link locali completi secondo `APP_URL`, senza corpi dei
post o metadati privati. Fasce a 0 dichiarate disabilitate, campione 0 ammesso,
input numerici invalidi respinti. Senza `--dry-run` il comando rifiuta l'esecuzione.
Non sono stati aggiunti DELETE, scheduling o accesso HTTP.

Verifiche effettuate: **25 test passati, 102 asserzioni** (14 del comando e 11 del
selettore). I test CLI verificano anche l’assenza di query di scrittura, il limite
per fascia, l’ordine dei link e la dimensione del campione. Pint e controllo del
diff superati. Avvio sull’istanza locale verificato: entrambe le durate sono
attualmente 0; nessuna impostazione locale è stata modificata.

Documentazione operativa aggiornata in `docs/configuration.md` e relativa
versione italiana, con istruzioni Tinker per impostare e ripristinare le durate
in attesa del form R4; aggiornata la voce nel changelog. Le query sono invariate
rispetto a R1, quindi non serve ripetere EXPLAIN.

Verifica manuale dell’utente completata con durate 30/40 giorni. Controverificati
in sola lettura tutti i 200 candidati dei batch e i 20 link condivisi: date
precedenti alle soglie, esclusioni rispettate, fasce disgiunte. I 100 pertinenti
risultano da autori seguiti; i non pertinenti non hanno fonti Home né commenti
locali. R2 approvato per commit prima di avviare R3.
