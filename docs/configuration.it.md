> Documentazione: [Italiano](README.it.md) · [English](README.md)

## Pannello di amministrazione

Il menu laterale raggruppa le funzioni dell’istanza, gli strumenti tecnici e i
relay, separati da divisori discreti. Le schermate condividono lo stile dei tab
di Database.

| Pagina | Sezioni |
| --- | --- |
| Questa istanza | Generali; Registrazioni e policy; Contenuti; Visibilità pubblica |
| Video | Abilitazione, strumenti FFmpeg/ffprobe e limiti video |
| Moderazione | Contenuti; Domini bloccati |
| Code e processi | In attesa; Falliti; Inbox federazione |
| Segnalazioni | Aperte; Revisionate; Archiviate; Con azione; Tutte |

Ogni form salva solo le proprie impostazioni. In Questa istanza, il tab
Contenuti comprende limiti di post, commenti, allegati e periodo delle tendenze;
Registrazioni e policy comprende apertura delle iscrizioni, regole e privacy.
Le icone e lo sfondo restano in Generali, mentre il CSS personalizzato rimane
in Aspetto. Il vecchio indirizzo `/admin/domini` porta al tab Domini bloccati.

Le impostazioni e i domini sono riservati agli amministratori. I moderatori
continuano a gestire utenti e segnalazioni con i permessi già previsti. I tab
delle segnalazioni conservano filtro e paginazione; quelli delle code mantengono
i contatori visibili e riportano a Falliti dopo Riprova o Elimina.

## Object storage — media e immagini

**Admin → Object storage** (`/admin/object-storage`) configura una destinazione
compatibile S3 per i nuovi media: allegati immagine, audio, video preparati e
relative miniature/anteprime, avatar e copertine di persone e comunità,
favicon, icone PWA e sfondo dell'istanza. I file esistenti restano nella loro
posizione; attivare il servizio non trasferisce l'archivio.

### Configurazione e verifica

Scegli Cloudflare R2, Amazon S3, DigitalOcean Spaces, OVHcloud Object Storage S3
o «Altro compatibile S3», quindi inserisci bucket e credenziali. R2 richiede
l'Account ID; AWS e Spaces la regione; OVHcloud e Altro regione ed endpoint
HTTPS fornito dal servizio. Per Altro è disponibile path-style. I preset
semplificano la compilazione e vanno verificati con il proprio bucket.

Salva prima di usare **Verifica connessione**: scrive, rilegge e confronta un
piccolo oggetto temporaneo, poi lo cancella. Se la pulizia fallisce, controlla
`openbook-connection-test/` nel bucket. Il test usa la configurazione salvata,
ha un timeout di 15 secondi per operazione e non verifica l'URL pubblico.

I parametri hanno voci separate in `system_settings`; solo la secret access
key è cifrata con `APP_KEY`. Un campo password vuoto conserva il segreto salvato.
Non servono variabili AWS nel `.env`; le modifiche valgono con config cache.
Conserva insieme backup database e `APP_KEY`. L'adattatore incluso richiede
PHP `simplexml` anche quando lo storage è disabilitato.

### Nuovi upload e distribuzione

**Attiva per i nuovi upload** sceglie il bucket per i nuovi file. Spegnerlo
riporta i nuovi upload sul disco locale; quelli già registrati su S3 continuano
a usare il bucket. Conserva quindi configurazione e credenziali finché esistono
oggetti. Se una scrittura S3 fallisce, l'upload segnala un errore e non ripiega
sul disco locale.

**Pubblica direttamente da object storage** sceglie come distribuire gli oggetti
S3, compresi quelli precedenti. Inserisci l'URL pubblico di base del bucket/CDN,
incluso l'eventuale percorso bucket richiesto dal provider. Browser, REST e
ActivityPub usano direttamente tale URL, senza trasferire i byte dall'istanza.
Non usare l'endpoint API autenticato come URL pubblico.

L'accesso pubblico si configura presso il provider: OpenBook non cambia le
policy del bucket e non imposta ACL sugli oggetti. Spegnere la distribuzione
diretta non revoca URL già diffusi, accesso pubblico o copie nelle cache.
La scelta non modifica gli URL dei file locali.

Con distribuzione diretta disabilitata, OpenBook legge dal bucket e serve in
streaming gli allegati tramite `/media-files/...` e gli altri asset tramite
`/asset-files/...`. Il bucket può restare privato. Gli URL degli asset cambiano
quando l'immagine viene sostituita; manifest e documenti ActivityPub usano gli
URL correnti. Le immagini federate esterne restano esterne.

Audio/video intermediati supportano `HEAD` e un singolo intervallo byte
(`Range`) per avanzare nel player senza scaricare prima il file intero. Range
multipli o malformati vengono ignorati; intervalli non soddisfacibili
restituiscono 416. Con `If-Range`, in assenza di un validator confrontabile,
viene restituito il file intero. Gli oggetti mancanti restituiscono 404;
problemi di accesso/configurazione S3 restituiscono 503 senza dettagli del provider.

### Elaborazione e gestione dei file

Le immagini vengono validate ed elaborate localmente, mantenendo rimozione
metadati, ridimensionamenti e miniature dei flussi esistenti. I video usano
preparazione e staging locali, con i normali strumenti ffmpeg/ffprobe,
abilitazione e limiti. I file preparati vengono trasferiti tramite stream,
senza una copia permanente in `public` per gli oggetti registrati sul bucket.
Il timeout delle operazioni S3 è di 300 secondi.

Un salvataggio fallito conserva gli asset precedenti e tenta di rimuovere i
nuovi upload parziali. Sostituzioni e rimozioni seguono il disco del singolo
file; i vecchi asset vengono eliminati dopo il salvataggio riuscito dei nuovi
riferimenti. Errori di pulizia vengono registrati senza credenziali o messaggi
SDK; possono richiedere una pulizia manuale. Le semantiche di conservazione dei
media nei soft-delete esistenti non cambiano.

Dopo un errore di pubblicazione gli originali in staging restano disponibili
per ritentare e i derivati temporanei vengono rimossi. Lo staging resta locale:
un lavoro non ancora pubblicato dipende ancora dal disco del server.
Il processo video ricarica la connessione S3 per ogni pubblicazione; modificare
le impostazioni non richiede un suo riavvio. Dopo un aggiornamento del codice,
riavvia una volta un processo video già in esecuzione per caricare la nuova versione.

### Aggiornamento e limiti operativi

Esegui le migrazioni (`php artisan migrate`) e rigenera le cache secondo la
normale procedura di deploy, incluse le route. Le migrazioni aggiornano i
riferimenti dei file senza spostarli o cancellarli. I vecchi URL locali
riconoscibili delle comunità diventano riferimenti al disco pubblico; gli URL
esterni o relativi a un'altra istanza restano utilizzabili come riferimenti storici.

È configurata una sola destinazione S3. Prima di cambiare provider, bucket o
endpoint, trasferisci esternamente gli oggetti conservandone le chiavi e
verifica la nuova destinazione. OpenBook non gestisce bucket storici.

**Gap noto:** non sono disponibili un comando di trasferimento dell'archivio
locale, una risoluzione dei vecchi URL `/storage/...` verso oggetti spostati,
né una procedura completa di ripristino collaudata con disco pubblico vuoto.
Queste attività non sono attualmente pianificate per l'implementazione.
Conserva i file locali esistenti: una semplice copia sul bucket non aggiorna
riferimenti e URL già distribuiti. Il solo object storage non costituisce un
backup completo dell'istanza; database, `APP_KEY` e protezione indipendente
del bucket rimangono necessari.

## Configurazione

Tutte le impostazioni specifiche di Openbook sono centralizzate in
`config/openbook.php` e configurabili tramite variabili d'ambiente (vedi
`.env.example` per l'elenco completo con commenti). Le principali:

| Variabile | Descrizione |
|---|---|
| `OPENBOOK_OAUTH_TOKEN_DURATION` | Durata in giorni dei nuovi token OAuth di accesso e rinnovo. Intero positivo; se assente, vuoto o non valido si usano 365 giorni. I token esistenti conservano la loro scadenza. Dopo la modifica eseguire `php artisan config:cache`.  Per il supporto REST/OAuth sperimentale, anche in produzione; vedere [guida API](api/README.it.md). |
| `OPENBOOK_DOMAIN` | Dominio pubblico dell'istanza, usato negli indirizzi `utente@dominio`. Deve coincidere con l'host di `APP_URL`. Se cambi dominio, aggiorna entrambi e poi `php artisan openbook:repair-federation-urls` (altrimenti Lemmy rifiuta i Follow se id e inbox sono su host diversi). Il comando aggiorna anche l'Actor tecnico `/relay` mantenendo i suoi percorsi dedicati; usa `--dry-run` per vedere prima le modifiche. |
| `OPENBOOK_INSTALLED` | Impostata automaticamente dall'installer; non modificare a mano. |
| `OPENBOOK_WEB_CRON_ENABLED` / `OPENBOOK_WEB_CRON_TOKEN` | Abilitano l'esecuzione dei processi periodici via richiesta HTTP, per hosting privi di cron reale. |
| `OPENBOOK_REGISTRATION_OPEN` / `OPENBOOK_REGISTRATION_REQUIRES_APPROVAL` | Controllano l'apertura delle registrazioni. |
| `OPENBOOK_MEDIA_MAX_SIZE_KB` / `OPENBOOK_MEDIA_MAX_ATTACHMENTS` | Dimensione massima (KB) e numero massimo di immagini allegabili a un post. |
| `OPENBOOK_POST_MAX_LENGTH` | Lunghezza massima (caratteri) del testo di un post. |
| `OPENBOOK_COMMENT_MAX_DEPTH` | Livelli di annidamento dei commenti considerati "normali" in configurazione (la struttura reale non ha un limite rigido, vedi [Limitazioni](roadmap.it.md#limitazioni-note)). |
| `OPENBOOK_SEARCH_MIN_LENGTH` / `OPENBOOK_SEARCH_PER_SECTION` | Lunghezza minima della query e risultati massimi per sezione nella pagina Cerca, incluse le persone locali e remote gia' note. |
| `DB_PERSISTENT` | Se `true`, riusa le connessioni PDO MySQL/MariaDB fra richieste. Consigliato su hosting con limite di nuove connessioni/secondo (es. Hostinger: errore `2002 Operation not permitted`). |
| `OPENBOOK_FEED_PER_PAGE` | Numero di post per pagina nel feed personale, nel feed locale e nelle pagine profilo/hashtag. |
| `OPENBOOK_CLOSED_REPORT_RETENTION_MONTHS` | Mesi di calendario per conservare le segnalazioni locali e federate dalla chiusura effettiva: default 12, minimo 1. I casi aperti, solo esaminati o senza data di chiusura sono conservati. |
| `OPENBOOK_ACTOR_KEY_BITS` | Lunghezza (bit) delle chiavi RSA generate per i nuovi Actor ActivityPub (minimo consigliato: 2048). |
| `OPENBOOK_SIGNATURE_MAX_SKEW` | Scarto massimo (secondi) tollerato tra l'header `Date` di una richiesta firmata in ingresso e l'orologio locale, prima di rifiutarla. |
| `OPENBOOK_FETCH_MAX_REDIRECTS` / `OPENBOOK_FETCH_TIMEOUT` / `OPENBOOK_FETCH_CONNECT_TIMEOUT` / `OPENBOOK_FETCH_MAX_BYTES` | Limiti applicati dal client HTTP protetto da SSRF usato per recuperare Actor e risorse remote. |
| `OPENBOOK_FETCH_ALLOW_INSECURE` | Consente richieste in uscita su HTTP semplice (solo per sviluppo locale; in produzione resta sempre richiesto HTTPS). |
| `OPENBOOK_ACTOR_CACHE_TTL_HOURS` | Per quante ore un Actor remoto gia' risolto viene considerato "fresco" prima di essere ri-scaricato. |
| `OPENBOOK_INBOX_MAX_BODY_BYTES` / `OPENBOOK_INBOX_MAX_JSON_DEPTH` | Limiti di dimensione e profondita' JSON applicati alle attivita' in ingresso, prima ancora della verifica crittografica. |
| `OPENBOOK_DELIVERY_MAX_ATTEMPTS` | Numero massimo di tentativi per la consegna di una singola attivita' in uscita, prima che finisca in `failed_jobs`. Gli intervalli di backoff tra un tentativo e l'altro (1, 5, 15, 60, 360, 1440 minuti) sono fissi. |

L’estensione PHP `gd` è consigliata per generare miniature e rimuovere i
metadati dalle immagini compatibili. Senza GD il caricamento resta disponibile,
ma queste elaborazioni vengono saltate.

### Impostazioni personali

Le impostazioni hanno cinque schede: **Profilo** per immagini, biografia e campi
pubblici; **Account e privacy** per lingua, fuso orario, visibilità e approvazione
dei follower; **Notifiche** per le notifiche push del browser;
**Trasferimento account** per arrivi e partenze; **Importa ed esporta** per i CSV
degli account seguiti. Ogni sezione ha le proprie azioni sotto la scheda dei contenuti.
Salvataggi ed errori di validazione riportano alla scheda corrispondente.

### Fusi orari personali

Gli utenti scelgono il fuso da **Impostazioni → Account e privacy**, oppure lasciano
Automatico per seguire il dispositivo. L'aggiornamento richiede la normale
esecuzione delle migration e imposta Automatico anche per gli account esistenti.
Non serve modificare `APP_TIMEZONE` per consentire queste scelte: il riferimento
dei dati salvati, dei log e del cron resta quello dell'istanza. In assenza di
rilevamento automatico viene usato lo stesso riferimento.

Il pannello amministrativo mostra gli orari nel fuso personale dell'operatore;
nelle pagine con orari assoluti una nota indica il fuso usato, utile per il
confronto con i log del server.

### Posizione dei post

La posizione dei post è una funzionalità facoltativa e usa un catalogo locale
[GeoNames](https://www.geonames.org/). I relativi controlli restano nascosti
finché un amministratore non completa correttamente l'importazione; tutte le
altre funzioni di Openbook continuano a funzionare normalmente. Dopo
l'installazione o un aggiornamento, scarica il dataset predefinito `cities500`
con:

```bash
php artisan openbook:update-cities
```

Il download predefinito importa anche i dizionari ufficiali di paesi e regioni
amministrative di primo livello, usati per produrre label leggibili. Per
importare l'archivio ZIP serve l'estensione PHP `zip`. Nelle installazioni
offline è possibile indicare un archivio GeoNames o il TSV già estratto:

```bash
php artisan openbook:update-cities /percorso/cities500.zip
php artisan openbook:update-cities /percorso/cities500.txt
```

La modalità da file non effettua richieste di rete. Se nella stessa directory
sono presenti `admin1CodesASCII.txt` e `countryInfo.txt`, vengono usati per
completare le label. Senza un catalogo importato Openbook continua a funzionare,
ma gli utenti locali non possono scegliere la posizione di un post.

La posizione viene aggiunta sempre in modo esplicito. Le coordinate richieste
dal browser tramite il pulsante “Posizione attuale” vengono usate solo in modo
transitorio per trovare la città più vicina: non sono salvate, registrate nei
log, mostrate o federate. Openbook conserva e pubblica esclusivamente la città
GeoNames selezionata e le coordinate del suo centro. I dati geografici sono
forniti da GeoNames con licenza
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).

### Visibilità pubblica e feed nella home

In **Questa istanza → Visibilità pubblica** si sceglie cosa possono consultare i
visitatori senza accedere:

- **Tutto visibile** conserva l’accesso esistente ai contenuti pubblici locali e
  federati. Un’opzione aggiuntiva consente l’esplorazione di tag e tendenze.
- **Solo contenuti locali** permette di consultare profili e community locali,
  con post ed eventi di autori locali. Restano nascosti i post remoti rilanciati
  da utenti locali o pubblicati in una community locale e le citazioni remote
  incorporate.
- **Tutto chiuso** richiede l’accesso per consultare i contenuti. Restano aperte
  la presentazione dell’istanza in home, l’accesso, la registrazione se abilitata,
  le regole e la privacy.

I commenti seguono il post o l’evento padre: una risposta locale non rende
pubblica una discussione remota, mentre una discussione locale ammessa conserva
le risposte remote. Permessi esistenti, preferenze di scoperta e consenso
all’indicizzazione continuano a valere; questi controlli non rendono pubbliche
pagine già riservate. La regolazione riguarda la consultazione web, senza
modificare ActivityPub, REST autenticata o gli URL degli allegati.

Senza impostazioni salvate restano l’accesso pubblico e l’esplorazione dei tag
attuali. Il controllo esistente **Mostra il feed nella home pubblica** si trova
ora in questo stesso tab ed è disattivo di default. Se attivo, mostra le
informazioni sull’istanza a sinistra (con scorrimento indipendente su desktop)
e al centro i post pubblici già presenti nell’istanza, con scorrimento infinito.
Il feed segue la modalità selezionata: tutti i post pubblici disponibili oppure
solo quelli di autori locali; Tutto chiuso lo nasconde. Non è un feed completo
del fediverso. Sugli schermi piccoli, una freccia verso destra apre la colonna
con le informazioni.

## Cron e attivita periodiche

Configura `openbook:cron` ogni minuto per elaborare le attività in attesa e
eseguire le manutenzioni periodiche. Il comando coordina questi task:

- `openbook:process-inbox` — processa la coda `inbox`;
- `openbook:process-polls` — registra le chiusure programmate dei sondaggi e accoda
  pubblicazione/risultati pendenti in batch riprendibili;
- `openbook:deliver` — processa la coda `delivery`;
- `openbook:deliver-push` — consegna le notifiche Web Push in attesa;
- `openbook:confirm-outgoing-follows` — conferma Follow remoti ancora pending
  se risultiamo gia' nella collection `followers` del target (Accept mancante);
- `openbook:fetch-feeds` — importa i feed RSS/Atom;
- `openbook:auto-announce` — esegue le condivisioni automatiche configurate;
- `openbook:purge-database` — pulisce le righe operative scadute e le segnalazioni chiuse oltre la conservazione prevista, ogni 24 ore;
- `openbook:database-sanity --scheduled` — riconcilia gli orfani ogni 24 ore,
  rimandando al giro successivo quando viene eseguita la Maintenance operativa.

Ogni chiamata elabora il lavoro in attesa e termina autonomamente. Per questi
task basta configurare il cron periodico; non serve un worker permanente.

**Con accesso a un vero cron di sistema:**

```cron
* * * * * php /percorso/openbook/artisan openbook:cron >/dev/null 2>&1
```

**Su hosting privi di cron reale o di accesso CLI**, l'installer genera un token
segreto e abilita un endpoint HTTP equivalente, da richiamare con un qualunque
servizio di "cron esterno" (es. cron-job.org) puntato a intervalli regolari:

```
GET https://tuo-dominio.example.org/cron/run?token=IL_TUO_TOKEN
```

L’endpoint richiede il token corretto e rifiuta richieste troppo ravvicinate (`OPENBOOK_WEB_CRON_MIN_INTERVAL`, default 55
secondi, risposta 429) restituendo 404 se la funzione e' disabilitata o 403 se il
token e' mancante o errato.

### Conservazione delle segnalazioni

La manutenzione ordinaria `openbook:purge-database`, già richiamata da
`openbook:cron`, elimina i casi locali e federati in stato archiviato o gestito
con data di chiusura precedente a 12 mesi di calendario. La durata è configurabile
con `OPENBOOK_CLOSED_REPORT_RETENTION_MONTHS` (minimo 1; 0 non disabilita la pulizia).
Non usa la data di creazione o dell’ultimo esame: i casi aperti, soltanto esaminati
oppure preesistenti senza `closed_at` restano conservati. Una riapertura azzera
la data; una nuova chiusura fa ripartire il periodo.

In **Admin → Database → Maintenance**, la riga **Segnalazioni chiuse** mostra
conteggio e durata applicata. Il comando di pulizia e la pulizia manuale usano
la stessa selezione. I riferimenti del caso vengono rimossi; la ricevuta tecnica
federata rimane con il solo digest dell’ID attività e collegamento al caso nullo.
Non contiene motivazione, riferimenti o identità del mittente e impedisce che
la stessa attività ricrei un caso dopo la scadenza. La pulizia non rimuove i
soggetti segnalati, gli utenti o il registro audit, né invia messaggi federati.
La pulizia ordinaria dell’inbox resta indipendente e non elimina casi o ricevute.

### Retention dei post remoti

La retention permette di conservare i post remoti per un periodo configurabile.
Apri **Amministrazione → Database → Retention** e imposta i giorni per le due fasce:

- **Pertinenti:** possono comparire nella Home di almeno un utente locale oppure
  hanno commenti di attori locali.
- **Non pertinenti:** non soddisfano questi criteri; il caso tipico è un post
  che compare soltanto in Mondo.

Le durate decorrono dalla prima importazione in Openbook. Nuovi commenti o
interazioni non fanno ripartire il conteggio. Entrambe le fasce sono inizialmente
**disabilitate (0)**; puoi abilitarle separatamente. Quando sono entrambe attive,
i Pertinenti devono essere conservati almeno quanto i Non pertinenti.

**Alla scadenza si eliminano definitivamente il post remoto e tutti i suoi
commenti, anche locali, insieme alle segnalazioni collegate.** Restano esclusi
post locali e relativi commenti, conversazioni dirette e post remoti citati da
contenuti locali. Media e file non vengono rimossi da questa pulizia. Le altre
istanze non ricevono richieste di cancellazione.

**Salva durate di conservazione** salva soltanto le impostazioni. **Anteprima**
usa le durate già salvate e mostra fino a 10 post per fascia, con link apribili
in una nuova scheda. Un elenco vuoto è normale quando la fascia è disabilitata
o non ci sono post abbastanza vecchi. I post già segnati come cancellati possono
essere ancora da ripulire, anche se la loro pagina non è più consultabile.

La pulizia si esegue da terminale o con un cron dedicato; **non è inclusa nel
cron ordinario**, neppure quando quest'ultimo viene richiamato via web.
Per vedere prima un campione dei post da eliminare:

```bash
php artisan openbook:prune-remote-posts --dry-run
```

Per eseguire la pulizia con i parametri ordinari:

```bash
php artisan openbook:prune-remote-posts
```

Il comando procede a batch fino a esaurimento dei candidati o per un massimo
indicativo di 30 minuti. Il batch in corso termina anche se supera il limite.
Puoi rilanciare il comando per continuare; il riepilogo conta i post eliminati
per fascia, senza sommare i commenti. La cancellazione è definitiva: conserva
un backup se vuoi poter recuperare anche i contributi locali dei thread rimossi.

I parametri opzionali consentono di adattare la pulizia:

| Parametro | Default | Significato |
| --- | --- | --- |
| `--batch-size` | 100 | Post per fascia in ciascun batch, non limite totale dell'esecuzione. |
| `--max-time` | 1800 | Secondi disponibili, controllati fra i batch. |
| `--sample` | 10 | Link mostrati per fascia nel dry-run; 0 li nasconde. |

I conteggi del dry-run riguardano un solo batch per fascia, **non il totale** dei
post da eliminare. I link usano `APP_URL`: aprili con il tuo account per vedere
soltanto i contenuti ai quali hai accesso. Se un post eliminato viene importato
nuovamente, riceve una nuova data d'importazione e un nuovo link locale.

Per una pulizia giornaliera, aggiungi un cron dedicato in un orario poco trafficato,
adattando i percorsi di PHP e dell'installazione:

```cron
0 3 * * * cd /percorso/openbook && /usr/bin/php artisan openbook:prune-remote-posts >> storage/logs/remote-post-retention.log 2>&1
```

La testata di **Amministrazione → Database** mostra la dimensione stimata
dell'intero database, dati e indici inclusi. Il tab **Maintenance** contiene
statistiche e pulizia delle tabelle operative; **Database sanity** gestisce
invece i riferimenti rimasti dopo una cancellazione, come descritto sotto.

### Statistiche dell’istanza e cache

La pagina **Stats** si apre dal footer e richiede soltanto un account autenticato.
I tredici grafici descrivono i dati di questa istanza. Non richiedono nuovi cron,
worker permanenti, Redis, CDN o una compilazione degli asset.

I risultati sono condivisi tra gli utenti e usano lo store Laravel configurato
con `CACHE_STORE`; sono supportati `database` e `file`. Non usare `array` o `null`
per ottenere una cache condivisa persistente. I TTL si configurano in
`config/stats.php`, in secondi, con minimo effettivo di 300 secondi:

| Grafico | Aggregazione | TTL predefinito |
| --- | --- | ---: |
| Quindici giorni, ora per ora | Oraria, ultimi 15 giorni | 5 minuti |
| Le lingue che si incontrano qui | Per lingua, ultimi 2 anni | 1 ora |
| Un mondo di istanze | Account per dominio, situazione attuale | 15 minuti |
| Due anni di pubblicazioni | Settimanale | 1 ora |
| Quante voci, ogni settimana | Autori distinti per settimana | 1 ora |
| Di cosa si parla | Top 10 hashtag di ogni mese, ultimi 24 mesi | 1 ora |
| Da dove arrivano le storie | Per paese, ultimi 2 anni | 15 minuti |
| Il calendario degli eventi | Mese di inizio dell’evento | 1 ora |
| I luoghi dove si conversa | Community attive per settimana | 1 ora |
| Le storie prendono forma | Totali immagini/video degli ultimi 2 anni | 1 ora |
| Immagini e video, settimana per settimana | Settimanale | 1 ora |
| La voglia di chiedere | Numero settimanale di sondaggi | 1 ora |
| Come arrivano i contenuti | Settimanale per sorgente, da bucket giornalieri | 15 minuti |

Volume/autori condividono il dataset `weekly`; totali/trend degli allegati
condividono `media`. I TTL non indicano la dimensione dei bucket. La «i» grigia
sotto ciascun grafico mostra frequenza e una sola data, **Dati aggiornati il…**,
nel fuso dell’utente; le date dei periodi rimangono in UTC.

In **Amministrazione → Database → Maintenance**, il pulsante **Svuota cache delle
statistiche** è riservato agli amministratori. Invalida tutti i dataset Stats,
lasciando intatte le altre cache e le tabelle delle delivery. I grafici vengono
ricalcolati alle richieste successive; il comando non lancia tutte le query.
Lo svuotamento è logico: le vecchie voci non sono riutilizzate e seguono la
gestione ordinaria dello store cache.

**Nessuna invalidazione automatica:** modifiche ai post, cancellazioni,
visibilità, sospensioni, blocchi e impostazioni non anticipano il TTL. Per vedere
subito i nuovi risultati, usare il pulsante. I filtri correnti si applicano al
successivo ricalcolo; di conseguenza retention, cancellazioni e moderazione
possono modificare anche i conteggi dei periodi passati. A eccezione dei conteggi storici degli hashtag, questi grafici descrivono
il contenuto attualmente conservato e ammesso, non uno storico immutabile.
Restano esclusi contenuti privati, account sospesi/eliminati e domini bloccati;
`discoverable` e `indexable` non sono filtri statistici. Le impostazioni globali
che nascondono CW/hashtag sensibili da Mondo sono applicate anche alle query.

**Le delivery hanno una semantica diversa:** contano le consegne elaborate di
post ed eventi pubblici, anche ripetute e già note, negli stessi bucket
`stats_content_delivery_buckets` per giorno UTC e sorgente. Non vengono salvati
hash dei contenuti o dimensioni per autore/tipo. Gli incrementi avvengono dopo
il commit, su connessione e transazione separate; gli errori vengono ignorati
senza far fallire l’importazione. Cancellazioni, blocchi, rimozione di relay o
annullamenti successivi non riscrivono questi contatori. «Consegna diretta»
indica l’inbox senza relay configurato riconosciuto, non necessariamente
l’invio dell’autore originale.

Le sorgenti statistiche sono indipendenti dalla configurazione dei relay.
Eliminare una riga da `stats_delivery_sources` elimina in cascata tutti i suoi
bucket; rimuovere un relay non elimina la sorgente statistica né lo storico.
Nuove consegne possono ricreare una sorgente eliminata. I dataset già in cache
si aggiornano alla scadenza del TTL o con **Svuota cache**.

Non esiste un recupero retroattivo delle delivery: la raccolta parte
all’attivazione della funzione; gli eventi contribuiscono dall’attivazione del
loro tracciamento. Il grafico legge al massimo due anni. Non è prevista una
pulizia automatica dei bucket più vecchi; includere bucket, sorgenti e metadati
di raccolta nei normali backup. **Svuota cache** non azzera lo storico.

Per il deploy, seguire la [procedura ordinaria](install.it.md): applicare le
migration pendenti, comprese `2026_10_22_000000_create_post_delivery_metrics`,
`2026_10_22_000001_unify_content_delivery_metrics` e
`2026_11_03_000001_constrain_delivery_bucket_sources`, e ricostruire le cache di
configurazione, route e view se utilizzate. La seconda migration conserva i
conteggi eventualmente già raccolti dalla prima. La migration del vincolo sulle sorgenti
elimina prima i bucket giornalieri senza sorgente, conserva tutti i contatori
validi e aggiunge la FK con cancellazione in cascata. Il rollback rimuove il
vincolo e il relativo indice, senza ricostruire gli orfani eliminati.
Dopo modifiche ai TTL, ricostruire la cache di configurazione e svuotare la
cache Stats per applicarli subito. Le risposte JSON restano autenticate e `private, no-store`; se lo store
cache non funziona, la pagina tenta la lettura diretta senza dichiarare dati
inseriti in cache.

### Storico persistente degli hashtag

L'intestazione dell'hashtag mostra un piccolo trend Chart.js degli ultimi 30 giorni
UTC, senza assi, tooltip o tabella dati. Senza JavaScript il trend resta nascosto. Stats legge i
conteggi mensili degli ultimi 24 mesi di calendario, conservando la classifica
mensile dei dieci tag principali. Lo storico approssimativo resta dopo la
pulizia locale dei post.

Si contano post ed eventi pubblici ammessi al momento della registrazione o
correzione. Il periodo deriva dalla pubblicazione originale, non dalla data
di svolgimento. Sono ammessi eventi scheduled, tentative e postponed;
annullamento e cancellazione rimuovono il contributo ancora modificabile.
Una riconsegna non aggiunge un nuovo contributo. Le correzioni si fermano alla più
breve retention abilitata dei post remoti; zero disabilita una categoria.
Se entrambe sono disabilitate, si può correggere entro i 24 mesi conservati.
Cambiare retention può riaprire periodi vecchi e alterare i conteggi: è un limite
accettato. Non ci sono ricevute per contenuto o soglie permanenti di
consolidamento. Cambiare moderazione non ricalcola tutto lo storico.

La migrazione `2026_11_08_000000_create_hashtag_statistic_buckets` crea **e popola**
i conteggi giornalieri/mensili dai post ed eventi pubblici ancora presenti. Su istanze
grandi il passaggio può richiedere minuti. Usare la finestra di manutenzione
e sospendere ricezione, pubblicazione e cron durante l'operazione. I contenuti già
rimossi non sono recuperabili. I mesi completati sono registrati, così un
popolamento interrotto può riprendere senza sommarli due volte.

Per un ricalcolo completo esplicito, sospendere gli stessi processi ed eseguire:

```sh
php artisan down
php artisan openbook:backfill-hashtag-stats --reset
php artisan up
```

**Il reset elimina lo storico raccolto**, inclusi i conteggi dei contenuti non più
presenti. Ricostruisce solo dai post ed eventi conservati, con le regole correnti.
Se interrotto, rilanciare senza `--reset` per riprendere; `--month=YYYY-MM`
inizializza un singolo mese incompleto. Non ricalcolare insieme alle scritture
dei contenuti. Il comando richiede maintenance mode; riattivare poi cron e
ricezione.

**Amministrazione → Database → Manutenzione** mostra entrambe le tabelle con
numero di righe, dimensione stimata e righe scadute. La pulizia singola/globale
Web e il cron di pulizia database esistente eliminano solo i giorni fuori dalla
finestra di 30 giorni e i mesi fuori dai 24 mesi. L'eliminazione è a blocchi e
con budget temporale: arretrati grandi possono richiedere un altro passaggio.
Non azzera lo storico corrente. Non c'è consolidamento giornaliero → mensile
e non serve un nuovo worker.

Le scritture avvengono dopo il commit del contenuto, su transazione/connessione
separata. I due bucket applicabili vengono aggiornati insieme. Gli errori
statistici vengono ignorati e non fanno ripetere o annullare l'operazione sul
contenuto. Le letture dei grafici non fanno più join fra post/eventi e associazioni ai tag.
Includere entrambi i bucket e i metadati di raccolta nei backup del database.
Il rollback della migrazione elimina i nuovi contatori; una migrazione
successiva può ricostruire solo dai contenuti conservati.

Tendenze mostra anche un ticker SVG di sette giorni per hashtag, con una sola
query ai bucket per tutti i tag visualizzati. Colori, sfumatura e punto finale
riprendono il dettaglio; funziona senza JavaScript e le colonne restano fisse
con Segui/Smetti di seguire. La classifica conta ancora post/eventi pubblici e
non elencati; i ticker mostrano lo storico approssimativo pubblico. Oggi è
parziale in UTC.

Su un'istanza già avviata con i bucket dei soli post, distribuire questo codice
e seguire la procedura completa `openbook:backfill-hashtag-stats --reset` sopra
per includere gli eventi conservati. La semplice ripresa salta i mesi già
inizializzati. Sospendere cron e ricezione come descritto; nessuna operazione
sullo staging viene eseguita automaticamente.

### Database sanity

La Database sanity elimina **like, menzioni e notifiche orfani**, cioè riferiti
a contenuti o altri oggetti che non esistono più nel database. Conserva i
riferimenti a oggetti ancora presenti, anche se segnati come cancellati, e
non rimuove contenuti, registro audit, media o file. Elimina anche i push
collegati alle notifiche rimosse e aggiorna i contatori delle notifiche.
Funziona anche con entrambe le fasce di retention disabilitate.

Il cron ordinario, sia da terminale sia via web, la esegue automaticamente
**al massimo una volta ogni 24 ore**, con batch da 100 righe e 5 secondi
disponibili fra i batch. Se nello stesso giro esegue la Maintenance, rimanda
la sanity alla chiamata successiva. Un giro parziale lascia il lavoro restante
al giorno successivo; puoi completarlo subito dal pannello o da terminale.
Non serve configurare un altro cron.

Apri **Amministrazione → Database → Database sanity** per vedere l'anteprima.
La tabella riporta fino a 100 orfani per tabella e tipo di oggetto: sono
**campioni, non totali globali**. **Aggiorna anteprima** ricarica i numeri senza
modificare dati. I tipi non supportati sono elencati separatamente e conservati;
i loro numeri indicano invece il totale per tipo.

**Pulisci gli orfani**, dopo conferma, esegue subito la pulizia. Il pannello
usa batch da 100 e un limite di 5 secondi controllato fra i batch: quello già
iniziato termina. Al ritorno mostra i campioni aggiornati e le righe eliminate
durante quell'esecuzione. Se la pulizia è parziale, puoi ripeterla. L'operazione
viene registrata nel registro audit.

Da terminale, per vedere l'anteprima oppure eseguire la pulizia:

```bash
php artisan openbook:database-sanity --dry-run
php artisan openbook:database-sanity
```

Il comando usa **100 righe per batch e per tabella/tipo** e un limite di
**1800 secondi (30 minuti)**, controllato fra i batch. L'anteprima mostra un
solo batch per tabella/tipo; la pulizia prosegue fino a esaurimento o al limite
e riporta le righe effettivamente eliminate. Per adattare i parametri:

```bash
php artisan openbook:database-sanity --batch-size=500 --max-time=300
```

Pannello e comando dedicato possono essere usati in qualsiasi momento,
indipendentemente dalla cadenza automatica. Se una pulizia sanity è già in corso,
un secondo avvio viene saltato. In caso di errore restano applicati i batch già
completati; puoi rilanciare la pulizia. `--max-time` limita la pulizia, non la
lettura dell'anteprima o del report dei tipi non supportati.

L'opzione `--scheduled`, usata dal cron ordinario, applica la cadenza giornaliera.
I lanci manuali senza questa opzione non spostano il giro automatico; il dry-run
non cambia dati né la cadenza. Se preferisci una pulizia completa in un orario
specifico, puoi aggiungere un cron dedicato, oppure lanciare il comando subito
dopo la retention nello stesso script:

```cron
30 3 * * * cd /percorso/openbook && /usr/bin/php artisan openbook:database-sanity >> storage/logs/database-sanity.log 2>&1
```

### Worker video (solo quando il supporto video e' abilitato)

Gli upload video locali sono **disabilitati per default**. Un amministratore
deve abilitarli dalle impostazioni dell'istanza dopo aver configurato binari
`ffmpeg` e `ffprobe` funzionanti. Le istanze che lasciano la funzione spenta
non richiedono i due strumenti e continuano ad accettare immagini e audio come
prima.

La transcodifica video e' volutamente esclusa da `openbook:cron` e
dall'endpoint cron web. La configurazione consigliata usa un processo CLI
permanente:

```bash
php /percorso/openbook/artisan openbook:process-videos
```

Il worker interroga la coda ogni cinque secondi, gestisce SIGTERM/SIGINT in
modo graceful e verifica FFmpeg/ffprobe prima di acquisire un lavoro. `--once`
elabora al massimo un post ed e' utile per la diagnostica. Piu' worker possono
convivere: ogni lavoro viene assegnato in modo esclusivo per evitare
pubblicazioni duplicate.

Come alternativa piu' semplice, ma con maggiore latenza, un cron di sistema
puo' elaborare un post in coda per ogni esecuzione:

```cron
* * * * * php /percorso/openbook/artisan openbook:process-videos --once >/dev/null 2>&1
```

Il comando e' esclusivamente CLI e non va esposto tramite endpoint web. Poiche'
ogni esecuzione gestisce al massimo un post, il worker permanente e' preferibile
nelle istanze dove gli upload video possono accumularsi.

Esempio di `ExecStart` systemd:

```ini
ExecStart=/usr/bin/php /percorso/openbook/artisan openbook:process-videos
Restart=always
RestartSec=5
TimeoutStopSec=960
```

Programma Supervisor equivalente:

```ini
command=/usr/bin/php /percorso/openbook/artisan openbook:process-videos
autostart=true
autorestart=true
stopwaitsecs=960
numprocs=1
```

Va usato un percorso assoluto e lo stesso utente di sistema proprietario delle
directory storage di Openbook. Aumentare `numprocs` solo se CPU, memoria e I/O
sono sufficienti a sostenere piu' processi FFmpeg contemporanei.
