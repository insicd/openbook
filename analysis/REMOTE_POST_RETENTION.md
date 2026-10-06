# Retention dei post remoti — issue #106

Stato: **analisi congelata nel commit `26a090d`; Retention e Database sanity completate e verificate; automatismo cron, review complessiva e documentazione finale approvati**.
Aggiornato il 7 ottobre 2026.

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
  L’esecuzione può durare anche mezz’ora; ogni transazione copre un batch,
  la cui durata dipende anche dai commenti e dalle altre associazioni coinvolte.
  Il pannello offre soltanto l'anteprima HTTP in lettura richiesta in R4:
  riusa i selettori del dry-run, senza avviare il comando o il servizio DELETE.

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
La pagina Amministrazione → Database offre il tab dedicato «Database sanity»,
realizzato in S5, senza confondere la sanity degli orfani con l’abilitazione della
retention. Offre anteprima equivalente al dry-run, pulizia reale e report delle tabelle
e dei tipi su cui opera il comando; il comando CLI rimane autonomo.

La cancellazione delle notifiche orfane deve mantenere coerenti le revisioni
delle notifiche secondo le convenzioni esistenti; le FK eliminano eventuali
righe push collegate. Nessuna pulizia di media/file viene aggiunta a questo
comando nel perimetro corrente.

### Esecuzione CLI della retention

Interfaccia implementata in R3. Invocazione ordinaria consigliata:

```sh
php artisan openbook:prune-remote-posts
```

Default: 100 post per batch per fascia e 1800 secondi (30 minuti) per esecuzione.
I parametri sono opzionali, per adattare l'esecuzione alle esigenze dell'istanza.
Per l'anteprima basta aggiungere `--dry-run`; `--sample` controlla i link mostrati
(default 10 per fascia).

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

## Piano di lavoro: due macrofasi, quattro sprint Retention e cinque Database sanity

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
- Aggiornare docs IT/EN e changelog. L'interfaccia web è rimandata a S5; niente
  aggancio obbligatorio al cron di retention o al purge esistente.

Verifica / uscita:

- Test CLI di dry-run, limiti, lock e default indipendenti; provare il percorso
  retention -> cascade -> orfani -> sanity, poi sanity da sola con retention a 0.
- Suite completa, controllo dei piani MySQL/MariaDB aggiornati se le query sono
  cambiate e review finale di tutte le modifiche prima della proposta di PR.
- Risultato: due comandi autonomi, operativi e documentati; nessuna rigenerazione
  di contenuti, nessuna attività federata, nessuna perdita del registro audit.

#### S5 — Tab Database sanity e interazione web

Attività:

- Presentare l’anteprima equivalente al dry-run in una tabella, con tabella,
  tipo di oggetto e conteggio limitato. Come concordato dopo S4, offrire anche
  la pulizia reale da web con conferma e limiti fissi.
- Aggiungere il tab «Database sanity» alla pagina Amministrazione → Database,
  coerente con Retention e Maintenance e con accesso riservato agli admin.
- Riutilizzare i servizi del comando, senza duplicare le regole di
  riconciliazione; definire limiti e caricamento dei report adatti a HTTP.
- Aggiornare docs IT/EN e changelog secondo le funzionalità concordate.

Verifica / uscita:

- Test mirati di autorizzazione, coerenza con il dry-run CLI e comportamento
  delle azioni concordate; le anteprime non modificano il database.
- Verifica manuale dell'interfaccia e review finale della macrofase.
- Risultato: tab amministrativo per interagire con la Database sanity, con
  perimetro web concordato prima dell'implementazione e CLI sempre autonomo.

### Modalità di avanzamento

Alla fine di ogni sprint riportare: attività completate, risultato verificabile,
test eseguiti, eventuali problemi reali e stato dei criteri d'uscita. I test mirati
accompagnano le modifiche fin dall'inizio, non vengono rimandati allo sprint finale.
La suite completa chiude R4, S4 e S5; altre esecuzioni si giustificano con cambiamenti
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


## Avanzamento — R3 verificato e approvato

R2 è stato committato in `ad5e443` dopo le verifiche dell’utente. R3 aggiunge
la modalità effettiva al comando, delegando a `RemotePostRetention` la scansione
con cursore `(created_at, id)`, il ricontrollo e la DELETE aggregata delle radici.
Durate e istante di riferimento sono fissati all’avvio. Ogni giro seleziona fino
al batch-size per fascia; il totale dell’esecuzione non è limitato al batch-size.
Il limite di tempo usa un orologio monotono, viene controllato tra i batch e non
interrompe una DELETE in corso. Un nuovo avvio rivaluta i candidati rimasti.

Concorrenza ed errori:

- Lock di processo con `flock` in `storage/framework/cache/remote-post-retention.lock`,
  senza scadenza temporale né servizi esterni. La presenza del file non indica
  un lock attivo: la chiusura del processo libera il lock. Protegge le esecuzioni
  che condividono lo storage dell’istanza.
- Selezione iniziale fuori transazione; poi lock delle radici per ID e degli
  intervalli indicizzati su `comments.post_id` e `posts.quoted_post_id`, seguito
  dal ricontrollo completo. Commenti e citazioni confermati prima dei lock sono
  rivalutati; i nuovi inserimenti sugli intervalli attendono la fine del batch.
- Su MySQL/MariaDB il batch usa REPEATABLE READ, impostato per la sola prossima
  transazione, senza cambiare i default di sessione o server. Servono lock
  espliciti degli intervalli: nel MySQL 26.7 locale, con FK gestite al livello SQL,
  il solo lock del padre non ha bloccato gli inserimenti nella prova concorrente.
  Il modello di gestione FK è descritto nel [manuale MySQL](https://dev.mysql.com/doc/refman/9.7/en/create-table-foreign-keys.html).
- Follow, membership e hashtag sono valutati al ricontrollo; non vengono bloccati
  tutti i grafi sociali dell’istanza. Un cambiamento successivo a tale valutazione
  non annulla una cancellazione già decisa. Gli ID scartati vengono superati dal
  cursore per evitare cicli; potranno essere rivalutati al prossimo avvio.
- Errori DB annullano il batch corrente e terminano il comando; i batch precedenti
  restano confermati. Nessuna consegna federata, pulizia dei singoli commenti,
  riconciliazione polimorfica o rimozione di media/file nel servizio.

Compatibilità delle cascade: la prima prova manuale ha eliminato 890 post; il
successivo avvio ha incontrato l'errore MySQL 6575 su un thread di 14 commenti
con profondità massima 5. Tutti i commenti fanno già riferimento alla radice
tramite `post_id`, ma la FK su `parent_comment_id` introduce anche cascade
ricorsive. Il caso è riproducibile su un database temporaneo; cancellare
direttamente i commenti, anche con DELETE JOIN, produce lo stesso errore.

Prima della DELETE delle radici, una sola UPDATE aggregata azzera
`parent_comment_id` nei commenti dei soli post confermati per la cancellazione.
La DELETE dei post continua a eliminare tutti i commenti tramite `post_id` e
le altre relazioni tramite cascade. Le due operazioni sono nella stessa
transazione: in caso di errore vengono ripristinati anche i legami tra commenti.
Non vengono modificati i thread conservati né introdotte DELETE per commento.

Prestazioni dopo l'esaurimento dei non pertinenti: nella prova manuale successiva
la SELECT di questa fascia ha superato 130 secondi. EXPLAIN sul DB locale mostra
che l'indice cronologico dei post viene usato, ma la negazione del predicato
di pertinenza viene trasformata in un antijoin che scansiona tutti gli attori
(`retention_viewers`: ALL, stima 30.793 righe). Il ramo positivo usa invece
l'indice dei pochi utenti locali. Quando non restano candidati, LIMIT 10 non
evita di esaminare i post vecchi per dimostrare che il risultato è vuoto.

Prova di sola lettura, stesso cutoff e stessi dati: esprimendo il predicato
booleano come `(pertinenza) = 0` anziché `NOT (pertinenza)`, MySQL usa
`actors_user_id_unique` per gli utenti locali (stima 5 righe); la fascia non
pertinente restituisce zero righe in 1,401 secondi. La fascia pertinente
restituisce 10 righe in 0,005 secondi. La SELECT originale è stata interrotta
dal timeout di prova a 5 secondi. Nessun nuovo indice necessario nella prova.
Variante integrata nel codice: un unico predicato compilato con la grammatica
del DB e parametri associati, confrontato con 0/1 senza cambiare le policy.
Verifica della query effettivamente generata: entrambe le fasce usano
`actors_is_local_index` (stima 7 attori locali), con indice cronologico sui post;
zero non pertinenti in 1,434 secondi e 10 pertinenti in 0,002 secondi.
Selettori, servizio e comando: 41 test passati, 165 asserzioni. Pint superato.

Verifica dei contatori: l’attuale `communities.posts_count` viene incrementato
soltanto da `PostComposer`; l’importazione remota non lo incrementa. La retention
remota non lo decrementa né ricalcola includendovi contenuti diversi. Un test
verifica che la rimozione remota preservi il contatore dei post locali.

Verifiche effettuate:

- **133 test passati, 588 asserzioni** su SQLite, comprendendo selettori, comando,
  servizio, feed, community e condivisione nei messaggi. Coperti batch multipli,
  cascade di commenti annidati locali/remoti e report, esclusioni, rollback,
  rilascio del lock anche su errore, limite di tempo, durate fisse, reimportazione
  con nuovo ID/data, assenza di job federati, conservazione di media/file e righe
  polimorfiche in attesa della sanity. Pint e revisione del diff superati.
- Prova su database MySQL temporaneo: 2.000 post remoti e 20 quote locali,
  con relazioni di follow, commenti, boost, hashtag, report e allegati. Eliminati
  esattamente **1.179 non pertinenti e 608 pertinenti**, secondo le selezioni
  iniziali allo stesso istante; originale delle quote conservato, seconda
  esecuzione senza ulteriori eliminazioni. Cascade, media e assenza di job
  verificati anche su MySQL.
- Due connessioni MySQL reali: inserimenti concorrenti di commenti/citazioni
  attendono i lock; una citazione locale già confermata protegge la radice.
  Verificato anche con default di sessione READ COMMITTED: il batch protegge
  gli intervalli e lascia invariato il default della sessione.
- EXPLAIN dei cursori per entrambe le fasce: indice cronologico per il ramo non
  pertinente; sulle fixture più piccole il ramo pertinente sceglie l’accesso per
  autore con ordinamento. Lock su indici già presenti di commenti e citazioni;
  DELETE per PK. Nessun ulteriore indice o hint introdotto.
- Regressione MySQL 6575: servizio verificato su database temporaneo con la
  struttura dei 14 commenti del thread problematico. Cancellazione e cascade
  completate; un errore provocato dopo la DELETE ripristina tutti i commenti e
  i legami originari. EXPLAIN della nuova UPDATE sul DB locale usa
  `comments_post_id_created_at_index`, accesso range sui 14 commenti.

Documentazione EN/IT e changelog aggiornati. Nessuna cancellazione eseguita sul
DB locale dell’utente: le prove effettive sono state effettuate solo su database
temporaneo. L'utente ha verificato con successo entrambe le fasce, incluse le
correzioni alle cascade e al piano della SELECT, e approvato il commit R3.
R4 inizierà dopo il commit dello sprint e completerà UI e documentazione operativa.

## Avanzamento — R4 completato e approvato

R3 è stato committato in `d72c072` dopo le verifiche dell'utente. R4 aggiunge
alla pagina Amministrazione → Database il form «Conservazione dei post remoti»:

- Testata comune con dimensione stimata di tutto il database (dati e indici),
  tab Retention iniziale e tab Maintenance per le sole tabelle operative.
  Navigazione con query string, senza JavaScript; dopo salvataggio o errore
  si resta in Retention, dopo pulizia o errore relativo in Maintenance.
  Pulsanti principali in fondo alle rispettive sezioni.
- Su MySQL/MariaDB il totale somma `data_length` e `index_length` delle tabelle
  dello schema corrente in `information_schema.tables`; su SQLite misura le
  pagine allocate. È una stima della dimensione allocata, non dello spazio
  immediatamente recuperabile cancellando righe. EXPLAIN locale: accesso allo
  schema e alle sue tabelle tramite indici dei metadati, senza scansioni dei
  contenuti. Totale verificato su tutte le 58 tabelle del DB locale.
  Le query dei conteggi di manutenzione si eseguono soltanto nel relativo tab.
- Due durate persistenti in giorni dalla prima importazione, inizialmente 0;
  ciascuno zero disabilita soltanto la propria fascia. Con entrambe attive,
  la durata dei Pertinenti deve essere almeno pari a quella dei Non pertinenti.
- PUT dedicata, protetta dallo stesso middleware admin della pagina; validazione
  lato server, messaggi e mantenimento dei valori inseriti in caso di errore.
  Salvataggio delle sole due chiavi tramite `InstanceSettings`, transazionale
  e registrato nell'audit. Nessuna invocazione del servizio di cancellazione.
- Testi italiano/inglese organizzati in Non pertinenti, Pertinenti e Contenuti
  sempre conservati. Definizioni sotto i titoli, giorni accanto agli input e
  nota separata sul vincolo delle durate. Una frase indica che i commenti
  seguono il post; dettagli su report, federazione e comandi restano nel manuale.
  Gli input numerici riusano gli stili dei form, limitando il CSS alla nuova card.
- Su richiesta dell'utente, pulsante Anteprima nel tab Retention: GET admin
  esplicita, durate già salvate, stesso istante per le due query del dry-run,
  LIMIT 10 per fascia e link al dettaglio in nuova scheda. Mostra solo ID,
  senza corpi o metadati privati; il dettaglio conserva i controlli di visibilità.
  Fasce a 0 ed esaurite hanno messaggi distinti. Nessun salvataggio, job o DELETE;
  il campione si carica soltanto su richiesta e mai nel tab Maintenance.
  Due colonne, una su schermi piccoli. Eliminato il margine tra le card della
  griglia statistiche che rendeva più basso il secondo box Maintenance.
- Guide EN/IT aggiornate con configurazione da pannello, invocazione ordinaria
  senza parametri e cron dedicato. Nessun collegamento al cron HTTP o ai pulsanti
  di pulizia delle tabelle operative. Changelog aggiornato.

Verifiche dell'anteprima: **53 test mirati passati, 311 asserzioni**, su pannello,
selettori e comando CLI; coperti ordine/limite, assenza di scritture, accesso
admin, link in nuova scheda, fasce disabilitate/esaurite e caricamento esplicito.
Suite completa finale, inclusa l'anteprima HTTP: **1.193 test passati,
5.147 asserzioni**, con 2 test installer MySQL saltati per server di test non
raggiungibile nell'ambiente della suite. I controlli MySQL della retention
documentati in R1/R3 sono stati effettuati separatamente. Pint e diff check
superati. Review della macrofase senza ulteriori problemi critici individuati.

Verifica visiva del template Blade e CSS reali nel browser integrato, con dati
di esempio e senza salvataggi sull'istanza; Safari non accessibile tramite
automazione. Cache locale delle route rimossa per rendere disponibile la nuova
PUT. Dopo il riordino in tab, verificati visivamente entrambi i template con
dati di esempio, testata comune, tab attivo e ordine dei pulsanti.
L'utente ha verificato e approvato il pannello, compresi il riordino in tab e
l'anteprima, autorizzando il commit conclusivo di R4. La macrofase Retention
è completata. Su richiesta dell'utente ci fermiamo prima della macrofase
Database sanity, che resta da avviare.

## Avanzamento — S1 completato e approvato

Implementato `DatabaseSanityQuery`, senza DELETE, comando CLI o interfaccia web.
La definizione esplicita usa gli alias del morph map dei modelli e le rispettive
tabelle, senza risolvere nomi arbitrari provenienti dai dati:

| Tabella | Relazione | Tipi padre supportati |
| --- | --- | --- |
| `likes` | `likeable` | `post`, `comment`, `event`, `event_comment` |
| `mentions` | `mentionable` | `post`, `comment`, `event`, `event_comment` |
| `notifications` | `notifiable` | `post`, `comment`, `follow`, `actor`, `event`, `event_comment`, `event_participation` |

Il tipo `actor` serve alle notifiche di rifiuto di una richiesta follow: il
servizio esistente usa l'attore come oggetto della notifica, non il follow
rimosso. L'inventario comprende tutte le relazioni polimorfiche operative;
`audit_logs` resta escluso, anche con soggetto mancante o nullo.

`orphans(table, type, limit, afterId)` seleziona soltanto gli ID delle righe
figlie con padre fisicamente assente, tramite NOT EXISTS, ordine per ID e
cursore `id > afterId`. Nessun filtro di stato, origine o visibilità sul padre,
nessuna lettura delle durate di retention. `unknownTypes(table, limit)` riporta
tipi non supportati e relative quantità; non li tratta come orfani. Anche un
alias presente nel morph map generale ma non previsto per quella relazione
resta da segnalare. Tabelle/tipi non supportati e limiti non positivi sono
rifiutati prima di costruire la selezione.

Indici: aggiunta una migrazione reversibile con indice
`(tipo polimorfico, id figlio, id padre)` su ciascuna delle tre tabelle, per
supportare filtro, cursore, ordine e lettura del riferimento. Conservati gli
indici precedenti, usati anche dalle normali relazioni dell'applicazione.

Verifiche effettuate:

- **48 test mirati passati, 251 asserzioni**, includendo i test della retention.
  I 24 casi di sanity coprono tutti i 15 abbinamenti tabella/tipo, padri con
  stato deleted ancora presenti, ID esistente soltanto in un'altra tabella,
  tipi sconosciuti, limiti/cursori e integrità delle righe dopo le selezioni.
  Provati anche cancellazione fisica e reinserimento del medesimo ID padre:
  la selezione successiva riflette la sua presenza attuale. Migrazione SQLite
  verificata in applicazione, rollback e riapplicazione.
- EXPLAIN MySQL e selezioni reali prima della migrazione su tutte le famiglie,
  inclusa la segnalazione dei tipi sconosciuti; nessuna modifica all'istanza.
  Trovati orfani di post/commenti e follow, nessun tipo sconosciuto nel DB locale.
- Migrazione MySQL up/down e 30 piani di selezione, iniziali e con cursore
  successivo, verificati su copia temporanea isolata delle tabelle locali,
  rimossa al termine. Lookup del padre normalmente `eq_ref` sulla PRIMARY;
  MySQL può materializzare l'insieme dei follow quando lo considera conveniente.
  Il nuovo indice viene scelto per alcune selezioni; per altre il motore
  preferisce PRIMARY o l'indice polimorfico precedente, con filesort su insiemi
  piccoli. Non si presume quindi che l'indice nuovo sia sempre usato.
  I batch verificati restano inferiori a 10 ms sui dati della prova.
  Non effettuata una prova separata su server MariaDB.
- Pint e diff check superati; review senza problemi critici individuati.

S1 è ancora interno: il comando utilizzabile dall'amministratore arriva in S4,
il tab web in S5. È possibile verificare le selezioni in sola lettura tramite
Tinker; non è necessario applicare la migrazione per provarne la correttezza,
ma gli indici serviranno per il percorso operativo. Nessun changelog o manuale
operativo aggiornato in questo sprint, che non introduce funzionalità esposte.
L'utente ha verificato e approvato S1, autorizzando commit e avvio di S2.
Confermato come riferimento per il dry-run CLI e il futuro tab web il report
per tabella e tipo di padre, con numero di orfani e limite del campione
esplicito, distinto da un eventuale conteggio totale.

## Avanzamento — S2 completato e approvato

S1 committato in `0d2ba4d` dopo approvazione dell'utente. Implementato il servizio
`DatabaseSanity::reconcileBatch(table, type, batchSize, afterId)` per `likes` e
`mentions`, riusando interamente il selettore S1. Un batch esegue una SELECT
limitata degli ID e, se non vuota, una sola DELETE aggregata sugli ID selezionati.
La DELETE contiene nuovamente tipo e NOT EXISTS del padre: preserva una riga
diventata valida dopo la selezione e non coinvolge righe nuove non selezionate.
Restituisce numero di righe eliminate e ultimo ID selezionato come cursore;
un batch vuoto restituisce cursore nullo. Il cursore avanza anche quando tutti
i candidati vengono preservati dal ricontrollo.

Nessuna lettura dei parametri di retention, nessun passaggio di ID dal comando
di retention, nessuna chiamata per singolo record e nessuna attività federata.
Notifiche, audit, contenuti e media restano fuori da questa operazione. Il
servizio rifiuta esplicitamente tabelle di pulizia diverse da like e menzioni;
le notifiche saranno aggiunte in S3 con la gestione delle revisioni.

Verifiche effettuate:

- **68 test mirati passati, 407 asserzioni**, includendo selettori e servizio
  sanity e i test di retention. Coperti tutti gli otto abbinamenti di pulizia,
  batch successivi e ripetizione a vuoto, padri deleted ancora presenti,
  tipi sconosciuti, conservazione delle altre relazioni, retention a 0,
  audit/notifiche invariati e assenza di job federati.
- Test di modifica tra SELECT e DELETE: padre reinserito, tipo della relazione
  cambiato e nuovo orfano inserito dopo la selezione. Il ricontrollo preserva
  il primo, esclude la relazione di tipo cambiato e limita la cancellazione
  al batch originale.
- Servizio reale MySQL provato su schema temporaneo isolato per tutti gli otto
  abbinamenti, con batch da due righe, conservazione dei padri deleted e dei
  tipi sconosciuti, esaurimento e ripetibilità. EXPLAIN delle otto DELETE:
  accesso `range` sulla PRIMARY della tabella figlia per gli ID del batch,
  lookup del padre `eq_ref` sulla sua PRIMARY, senza scansioni globali.
- Due connessioni MySQL: il padre inserito e committato dopo la SELECT viene
  riconosciuto dalla DELETE e la relazione è conservata. Con inserimento
  ancora non committato, la DELETE attende il lock del padre; provocato il
  timeout di test, nessuna riga figlia viene persa. Dopo il commit del padre,
  la pulizia ripetuta non elimina la relazione. Non sono necessari lock
  applicativi aggiuntivi o transazioni intorno alla SELECT preliminare.
  Il database temporaneo è stato rimosso; nessuna cancellazione effettuata
  sull'istanza locale. MariaDB non verificato su un server separato.
- Pint e diff check superati; review senza problemi critici individuati.

Il servizio è provabile manualmente da Tinker, con una chiamata per batch:

```php
$sanity = app(\App\Application\Services\DatabaseSanity::class);
$sanity->reconcileBatch('likes', 'post', 100);
$sanity->reconcileBatch('mentions', 'post', 100);
```

Ogni chiamata elimina al massimo 100 orfani della tabella/tipo indicati e
restituisce `deleted` e `cursor`. Le selezioni S1 restano disponibili per
confrontare il campione prima e dopo. Comando CLI e pannello restano previsti
in S4/S5; documentazione operativa e changelog saranno aggiornati alla consegna
delle funzionalità esposte. L'utente ha approvato S2 e autorizzato il commit
e l'avvio di S3, rimandando la verifica manuale complessiva al comando CLI
di S4, con e senza dry-run.

## Avanzamento — S3 completato e approvato

S2 committato in `3562397` dopo approvazione dell'utente. Il servizio
`DatabaseSanity::reconcileBatch` ora gestisce anche `notifications`, per tutti
i sette tipi inventariati in S1. Resta invariata la selezione limitata e il
ricontrollo del tipo e dell'assenza fisica del padre nella DELETE aggregata.

Per le notifiche, il batch racchiude in una transazione il lock degli ID
selezionati, la DELETE e l'incremento aggregato di `notifications_revision`.
Le righe push collegate vengono eliminate dalle FK. Una lettura degli ID
rimasti nel batch permette di aggiornare soltanto i destinatari delle
notifiche effettivamente cancellate: nessun incremento per una notifica
preservata dal ricontrollo, un solo incremento per destinatario e per batch,
anche quando gli vengono eliminate più notifiche. Il lock mantiene stabile
l'associazione ID/destinatario durante questa operazione; tutte le letture
aggiuntive sono limitate agli ID del batch. Usato il retry transazionale
standard di Laravel, fino a tre tentativi in caso di deadlock.

Non cambiano padri, audit, media, file o regole di retention. Nessuna attività
federata o nuova consegna push. Notifiche lette e non lette seguono lo stesso
criterio di orfanità; i padri deleted ancora presenti e i tipi non supportati
restano conservati.

Verifiche effettuate:

- **94 test mirati passati, 675 asserzioni**, comprendendo sanity, notifiche,
  outbox/consegna push e servizio di retention. I nuovi test coprono i sette
  tipi padre, conservazione delle notifiche valide e dei tipi sconosciuti,
  cascade push, audit con soggetto mancante o nullo e revisioni dei soli
  destinatari coinvolti. Provati batch successivi, incremento unico per
  destinatario/batch e ripetizione a vuoto senza ulteriori incrementi.
- Padre ricreato dopo il lock del batch: la DELETE preserva la relativa
  notifica e il suo push, senza cambiare la revisione del destinatario, mentre
  elimina l'altro orfano dello stesso batch. Errore iniettato durante
  l'incremento: rollback di notifiche, push e revisioni.
- Verifica HTTP del polling: dopo la pulizia l'ETag precedente non produce
  una risposta 304; il client riceve il conteggio non letto aggiornato e
  l'elenco privo delle notifiche eliminate.
- Servizio reale MySQL provato su schema temporaneo isolato con FK per push
  e destinatari, tutti i sette tipi padre, cascade, ripetibilità, revisioni e
  rollback. Una seconda connessione inserisce il padre tra lock del batch e
  DELETE: la notifica/push sopravvive e la revisione del destinatario resta
  invariata. EXPLAIN delle sette DELETE: `range` sulla PRIMARY della tabella
  figlia e `eq_ref` sulla PRIMARY del padre. EXPLAIN dell'UPDATE aggregato delle
  revisioni: `range` sulla PRIMARY di `users`. Nessun nuovo indice necessario.
  Schema temporaneo rimosso, nessuna cancellazione sull'istanza locale;
  MariaDB non verificato su un server separato.
- Pint e diff check superati; review senza problemi critici individuati.

L'utente ha approvato S3, autorizzando il commit e l'avvio di S4.
Come concordato, la verifica manuale complessiva sarà effettuata con il comando
CLI di S4, con e senza dry-run. Nessuna interfaccia operativa o documentazione
d'uso aggiunta in questo sprint interno; il tab web resta previsto in S5.

## Avanzamento — S4 completato e approvato

S3 committato in `fd35017` dopo approvazione dell'utente. Implementato il comando
autonomo `openbook:database-sanity`, senza collegamenti obbligatori a retention,
cron ordinario, purge operativo o HTTP. Invocazione ordinaria:

```sh
php artisan openbook:database-sanity
```

Default: batch di 100 righe per tabella/tipo e 1.800 secondi. Il servizio
percorre a turno tutte le 15 coppie supportate, con un cursore distinto per
coppia e batch aggregati S2/S3, fino all'esaurimento o al limite monotono di
tempo verificato fra i batch. Un batch già in corso termina. Il riepilogo
indica per tabella/tipo quante righe sono state eliminate in questa esecuzione;
se il tempo termina è possibile rilanciare il comando. I candidati preservati
dal ricontrollo fanno comunque avanzare il cursore, evitando cicli sullo stesso
batch. Orfani arrivati durante la pulizia prima del cursore potranno essere
raccolti nell'esecuzione successiva.

`--dry-run` seleziona un solo batch per tabella/tipo e non scrive sul database.
Formato concordato con l'utente: `likes / post: 12 orfani nel campione (massimo
100).` Il limite è esplicito e questi conteggi non sono totali globali. I tipi
non supportati sono segnalati e conservati: il loro conteggio è un totale per
tipo, con al massimo 100 tipi segnalati per tabella. Non si mostrano corpi o
metadati dei contenuti; il nome del tipo sconosciuto è rappresentato come stringa
JSON, così eventuali caratteri di controllo non diventano righe di output.

Lock su file dedicato `database-sanity.lock`, senza scadenza mentre il processo
è attivo; impedisce sovrapposizioni fra pulizie sanity e viene chiuso anche
su errore. È indipendente dalla retention e non serve per il dry-run. Validati
interi positivi per `--batch-size` e `--max-time`. Quest'ultimo limita il ciclo
di pulizia, non l'anteprima o il report dei tipi non supportati.

Guide EN/IT e changelog aggiornati con comando ordinario, anteprima, limiti,
semantica dei report, indipendenza dalla retention e cron CLI autonomo. Chi
desidera può eseguire retention e sanity in successione nello stesso script.
Il tab amministrativo resta da definire e implementare in S5.

Verifiche completate:

- **17 test CLI passati, 94 asserzioni**: dry-run senza scritture, tutte le
  coppie nel report, campioni limitati/default, tipi sconosciuti conservati,
  limiti invalidi, timeout fra batch e ripresa, lock/indipendenza dalla
  retention/rilascio su errore. Percorso integrato retention → cascade →
  orfani → dry-run sanity → pulizia sanity, con entrambe le retention a 0
  durante la sanity e ripetizione a vuoto senza incrementi di revisione.
- Comando dry-run reale sul MySQL locale: campioni di like/menzioni orfani di
  post e commenti, notifiche orfane di post/commenti/follow, nessun tipo
  sconosciuto. Nessuna cancellazione effettuata sull'istanza.
- CLI reale con e senza dry-run su schema MySQL temporaneo isolato: anteprima
  senza modifiche, pulizia corretta con batch da una riga e ripetizione vuota,
  notifiche valide e sconosciute preservate. Lo schema è stato rimosso.
  Verificati anche i piani delle DELETE e dell'UPDATE revisioni riusati da S3;
  nessuna query nuova di selezione o cancellazione richiede altri indici.
  MariaDB non verificato su server separato.

Suite completa: **1.265 test passati, 5.732 asserzioni**, con 2 test installer
MySQL saltati perché il database di test non è raggiungibile nell'ambiente
della suite. Le prove MySQL della sanity sono state eseguite separatamente.
Pint, diff check e review finale completati senza problemi critici individuati.
L'utente ha verificato con successo il comando con e senza dry-run e approvato
il commit di S4 e l'avvio di S5. Per S5 richiede la tabella equivalente al
dry-run e propone anche la pulizia reale da web, con limiti adatti a HTTP.


## Avanzamento — S5 completato e approvato

S4 committato in `b29a594` dopo la verifica positiva dell’utente. Aggiunto il
terzo tab **Database sanity** in Amministrazione → Database. L’apertura carica
la tabella delle 15 coppie tabella/tipo, con campioni di massimo 100 orfani per
coppia; non sono conteggi globali. Il pulsante **Aggiorna anteprima** ricarica
soltanto le letture. CLI e pannello riusano gli stessi metodi di selezione e
conteggio, senza duplicare i criteri di riconciliazione. I tipi non supportati
restano segnalati separatamente e conservati.

Come richiesto, in fondo alla tabella è presente anche **Pulisci gli orfani**,
con conferma, POST protetta da CSRF e accesso riservato agli amministratori.
Riusa il servizio CLI con batch da 100 righe e limite web di 5 secondi,
verificato fra i batch: un batch già iniziato termina. Il lock su file è stato
spostato nel servizio di ingresso condiviso, così CLI e web non possono
avviare due pulizie contemporanee. Un risultato parziale invita a ripetere
l’operazione; il CLI mantiene i propri default e resta utilizzabile da solo.

Dopo la pulizia il pannello torna al tab sanity, aggiorna i campioni e aggiunge
una colonna con le righe realmente eliminate durante quella esecuzione.
L’azione amministrativa registra conteggi e indicazione di timeout nel registro
audit, senza corpi di contenuti. Le regole di cancellazione aggregate e il
trattamento di notifiche, push e revisioni rimangono quelli verificati in S2/S3.
Non sono state introdotte nuove query di cancellazione o nuovi indici.

Verifiche completate:

- **9 test del pannello, 66 asserzioni**: anteprima senza scritture e coerente
  con il CLI, caricamento solo nel tab sanity, autorizzazioni admin/moderatori/
  utenti/ospiti, POST obbligatoria, tipi sconosciuti escapati e conservati,
  pulizia reale con report e audit, lock condiviso, limiti web non alterabili
  dalla richiesta e ripresa dopo timeout, rollback del batch notifiche e
  rilascio del lock in caso di errore.
- Regressioni CLI, Maintenance e Retention passate; suite completa:
  **1.274 test passati, 5.798 asserzioni**, con i 2 test installer MySQL
  saltati per database di test non raggiungibile nell’ambiente della suite.
- Comando aggiornato eseguito su schema MySQL temporaneo isolato: dry-run
  senza modifiche, pulizia corretta e ripetizione vuota; piani delle DELETE
  e dell’UPDATE revisioni confermati. Schema rimosso al termine. MariaDB
  non verificato su server separato.
- Controllo nel browser con template reale e dati dimostrativi: tabella,
  tab attivo e pulsanti in fondo; a 375 px i tab vanno a capo e la pagina
  non produce overflow orizzontale. La tabella scorre nel proprio contenitore.
  Nessuna pulizia eseguita dall’assistente sull’istanza locale.
- Guide EN/IT, changelog e analisi aggiornati; Pint, diff check e review
  completa senza problemi critici individuati.

L’utente ha verificato il pannello e approvato il commit di S5, comprese le
rifiniture dei pulsanti descritte sotto. Le due macrofasi sono completate.


### Rifinitura S5 — Pulsanti coerenti fra i tab

Dopo la prova manuale positiva, l’utente ha richiesto di uniformare il fondo
pagina dei tre tab. I pulsanti Retention sono stati spostati fuori dalla card,
con lo stesso margine superiore di Maintenance e Database sanity. Il pulsante
di salvataggio rimane associato al form tramite l’attributo HTML `form`, così
mantiene invio dei campi, validazione del browser e protezione CSRF. Anche con
l’anteprima aperta le azioni restano in fondo. Nessuna modifica alle operazioni
sui dati. I 34 test mirati del pannello sono passati (269 asserzioni);
l’utente ha approvato la rifinitura e autorizzato il commit dello sprint.


## Rifinitura finale — Sanity nel cron ordinario

Dopo S5 (`e11ddf4`), l’utente ha richiesto e approvato l’aggancio della Database
sanity al cron ordinario, incluso l’innesco web, seguendo l’approccio della
Maintenance. Questa decisione aggiorna la precedente scelta di un cron
esclusivamente dedicato alla sanity; la retention dei post resta CLI dedicata.

- `openbook:cron` richiama la sanity con `--scheduled`, batch da 100 e limite
  di 5 secondi verificato fra i batch. Un batch iniziato termina.
- Se `openbook:purge-database` aggiorna la propria data di esecuzione in quel
  giro, la sanity viene rimandata alla successiva chiamata cron. Non vengono
  avviate entrambe le manutenzioni nello stesso giro; un errore della
  Maintenance impedisce anche l’avvio della sanity in quel giro.
- La sanity conserva `database_sanity_last_run_at` in `system_settings`:
  esegue al massimo una volta ogni 24 ore, controllando la data dopo aver
  acquisito il lock condiviso con CLI e pannello. Lock occupato o errore non
  registrano una nuova esecuzione. Anche una pulizia parziale registra il giro:
  il lavoro restante torna nel giro giornaliero successivo.
- Comando CLI ordinario e pulsante web ignorano la cadenza automatica e non
  aggiornano il relativo timestamp: restano disponibili per pulire subito o
  completare un giro parziale. Il dry-run resta in sola lettura, anche con
  `--scheduled`. Non ci sono nuove migrazioni, endpoint o query di selezione
  degli orfani. La nuova lettura usa la chiave univoca di `system_settings`.

Verifiche mirate: 7 nuovi test, 42 asserzioni, per periodicità e confine delle
24 ore, rinvio dopo Maintenance, cron web, lock occupato, errori e rilascio del
lock, dry-run e ripresa manuale di una pulizia parziale. Regressioni CLI e
pannello verificate insieme durante lo sviluppo. Guide EN/IT e changelog
aggiornati. Verifica manuale e commit di questa rifinitura ancora da approvare.


Suite completa della rifinitura cron: **1.281 test passati, 5.840 asserzioni**,
con i consueti 2 test installer MySQL saltati per database di test non
raggiungibile. Pint, diff check e review completati. Nessuna cancellazione
eseguita dall’assistente sui dati dell’istanza locale.


## Review complessiva del branch rispetto a issue_107 — 7 ottobre 2026

Perimetro: tutti i commit di issue_106 rispetto a issue_107, più le modifiche
non committate dell’automatismo sanity e le rifiniture della review. Verifica
per flussi completi, oltre ai singoli sprint: criteri di pertinenza, cancellazioni,
concorrenza, polimorfismo, notifiche, configurazione, cron, accessi al pannello,
migrazioni, test e coerenza delle guide EN/IT.

Esito: nessun problema bloccante individuato e nessuna necessità di un refactor
strutturale. La selezione della retention riusa i vincoli di visibilità del
feed e la distinzione fra boost e citazioni di #107; i criteri delle sorgenti
Home sono verificati contro il feed reale. Il predicato unico mantiene
separate le due fasce e le condizioni sono ricontrollate prima della DELETE.
La gestione MySQL dei lock sui thread e sulle citazioni, l’isolamento del batch
e il distacco aggregato delle risposte sono giustificati dalle prove precedenti,
non sono un meccanismo astratto di navigazione o cancellazione parallela.

La sanity ha una sola mappa dei tipi ammessi e una sola selezione degli orfani,
riusata da anteprima, pulizia CLI, pannello e cron. Il ricontrollo nella DELETE
preserva padri ripristinati dopo la selezione; le notifiche conservano la propria
transazione per push e revisioni. La differenza rispetto a like e menzioni è
necessaria. Registro audit e tipi non supportati restano preservati. Nessuna
pulizia aggiuntiva di media/file né attività federata.

Controllati autorizzazione admin, CSRF, GET in sola lettura, escaping dei tipi
non supportati, attribuzione del pulsante Retention al form esterno, accesso
alle anteprime tramite le normali pagine autorizzate dei post. Cron automatico
e lanci manuali usano lo stesso lock; il controllo giornaliero avviene sotto
lock, mentre i lanci manuali restano liberi dalla cadenza. Il rinvio dopo
Maintenance non cambia la sua policy esistente.

Rifiniture effettuate durante la review:

- Centralizzati nel servizio sanity i limiti dei giri brevi usati da cron e
  pannello (100 righe, 5 secondi). I default del CLI autonomo restano invariati.
- Uniformata la variabile CSS dei bordi delle due tabelle al token effettivo
  del tema, evitando un fallback fisso che ignorava il colore configurato.
- Allineato il riepilogo del cron nelle guide EN/IT a tutti i task realmente
  eseguiti. Precisato nell’analisi che un batch di post non garantisce una
  transazione breve quando il thread ha molti figli.

Controverifiche MySQL della review: entrambe le fasce usano l’indice
`posts_retention_created_id_index` sul database locale; controllo esclusivamente
in lettura, con zero candidati residui per le soglie del campione. La fascia
non pertinente ha impiegato circa 0,73 secondi, la pertinente circa 0,001 secondi;
questi numeri descrivono il campione attuale e non costituiscono un benchmark
generale. Ripetuta anche la prova sanity su schema MySQL temporaneo isolato:
recheck concorrente, cascade push, revisioni, rollback, CLI dry-run e pulizia
ripetibile corretti; schema rimosso. Nessuna cancellazione sull’istanza locale.

Una verifica aggiuntiva della durata numerica massima accettata dal form ha
prodotto un insieme vuoto anche su MySQL, senza errore: non è stata introdotta
una restrizione arbitraria del prodotto. Restano i limiti operativi già
concordati: il tempo è controllato fra i batch, un singolo batch/query può
superarlo; il giro giornaliero breve può lasciare lavoro per il giorno dopo
oppure per CLI/pannello. MariaDB non provato su un server separato.

Le modifiche del cron e le rifiniture restano non committate; nessun push o PR
è stato eseguito durante la review.


Verifica finale dopo le rifiniture: suite completa **1.281 test passati,
5.840 asserzioni**, 2 test installer MySQL saltati per server di test non
raggiungibile. Le prove MySQL della review sono state eseguite separatamente.
Pint e diff check passati. Non sono emersi ulteriori problemi bloccanti.


## Chiusura — Documentazione operativa e commit finale

L’utente ha autorizzato il commit finale dopo la review complessiva, chiedendo
che il manuale sia orientato all’amministratore. Rilette e riordinate le sezioni
Retention e Database sanity delle guide EN/IT: impostazioni, effetto della
cancellazione, anteprima, comando ordinario, parametri opzionali e cron.
Rimossi i dettagli di implementazione su FK, query, revisioni, CSRF e lock;
conservate le informazioni operative su campioni, limiti fra batch, errori,
concorrenza e ripresa. Indice documentazione e collegamenti README aggiornati;
changelog reso più sintetico e allineato ai tre tab e al cron automatico.

Il controllo finale riusa la suite completa appena passata nella review
(1.281 test, 5.840 asserzioni; 2 installer MySQL saltati), poiché dopo tale
esecuzione sono cambiati soltanto file Markdown. Verificati diff, formattazione,
collegamenti locali e coerenza dei comandi con le firme CLI. Nessun push:
l’utente invierà il branch dopo il commit.
