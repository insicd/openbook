> Documentazione: [Italiano](README.it.md) · [English](README.md)

## Configurazione

Tutte le impostazioni specifiche di Openbook sono centralizzate in
`config/openbook.php` e configurabili tramite variabili d'ambiente (vedi
`.env.example` per l'elenco completo con commenti). Le principali:

| Variabile | Descrizione |
|---|---|
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
| `OPENBOOK_ACTOR_KEY_BITS` | Lunghezza (bit) delle chiavi RSA generate per i nuovi Actor ActivityPub (minimo consigliato: 2048). |
| `OPENBOOK_SIGNATURE_MAX_SKEW` | Scarto massimo (secondi) tollerato tra l'header `Date` di una richiesta firmata in ingresso e l'orologio locale, prima di rifiutarla. |
| `OPENBOOK_FETCH_MAX_REDIRECTS` / `OPENBOOK_FETCH_TIMEOUT` / `OPENBOOK_FETCH_CONNECT_TIMEOUT` / `OPENBOOK_FETCH_MAX_BYTES` | Limiti applicati dal client HTTP protetto da SSRF (`SafeHttpClient`) usato per recuperare Actor e risorse remote. |
| `OPENBOOK_FETCH_ALLOW_INSECURE` | Consente richieste in uscita su HTTP semplice (solo per sviluppo locale; in produzione resta sempre richiesto HTTPS). |
| `OPENBOOK_ACTOR_CACHE_TTL_HOURS` | Per quante ore un Actor remoto gia' risolto viene considerato "fresco" prima di essere ri-scaricato. |
| `OPENBOOK_INBOX_MAX_BODY_BYTES` / `OPENBOOK_INBOX_MAX_JSON_DEPTH` | Limiti di dimensione e profondita' JSON applicati alle attivita' in ingresso, prima ancora della verifica crittografica. |
| `OPENBOOK_DELIVERY_MAX_ATTEMPTS` | Numero massimo di tentativi per la consegna di una singola attivita' in uscita, prima che finisca in `failed_jobs`. Gli intervalli di backoff tra un tentativo e l'altro (1, 5, 15, 60, 360, 1440 minuti) sono fissi. |

Il caricamento di immagini richiede l'estensione PHP `gd` (verificata dall'installer come
requisito **consigliato**, non bloccante): senza `gd` l'istanza funziona regolarmente,
ma sara' possibile pubblicare solo post testuali.

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

## Cron e attivita periodiche

Openbook usa la coda **database** di Laravel (tabelle `jobs`/`failed_jobs`, nessun
Redis/RabbitMQ e, salvo il worker video opzionale descritto sotto, nessun processo
permanente): l'elaborazione dell'inbox e la consegna delle
attivita' in uscita avvengono solo quando qualcuno esegue periodicamente il comando
`openbook:cron`, che a sua volta invoca in sequenza:

- `openbook:process-inbox` — processa la coda `inbox` (`InboxActivityProcessor`);
- `openbook:deliver` — processa la coda `delivery` (`DeliverActivityJob`);
- `openbook:confirm-outgoing-follows` — conferma Follow remoti ancora pending
  se risultiamo gia' nella collection `followers` del target (Accept mancante).

I primi due sotto-comandi girano con `queue:work --stop-when-empty`, cosi' terminano da
soli invece di restare in ascolto indefinitamente: adatto a un cron classico, mai a un
supervisore di processi permanenti.

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

Il token viene confrontato con `hash_equals()` (nessun timing attack) e l'endpoint
rifiuta richieste troppo ravvicinate (`OPENBOOK_WEB_CRON_MIN_INTERVAL`, default 55
secondi, risposta 429) restituendo 404 se la funzione e' disabilitata o 403 se il
token e' mancante o errato.

### Retention dei post remoti

Il comando CLI dedicato consente di vedere prima i candidati senza cancellarli:

```bash
php artisan openbook:prune-remote-posts --dry-run
```

Mostra un solo batch per fascia (Pertinenti e Non pertinenti), ordinato dalla
prima importazione, e fino a `--sample` link locali per fascia. I conteggi
riguardano il batch, non tutti i post scaduti. `--batch-size` deve essere un
intero positivo; `--sample` può essere 0 per omettere i link. In dry-run il
comando non modifica dati. Per l'esecuzione ordinaria della retention basta:

```bash
php artisan openbook:prune-remote-posts
```

I default sono **100 post per batch per fascia** e **1800 secondi (30 minuti)**
per esecuzione; l'anteprima mostra fino a 10 link per fascia. I parametri sono
opzionali e consentono di adattare questi valori alle esigenze dell'istanza.

**Senza `--dry-run` elimina fisicamente i post scaduti e tutto il loro thread**,
compresi commenti locali e report. I post locali e i loro thread restano; sono
esclusi messaggi diretti/conversazioni e originali citati da post locali.
`--batch-size` è il limite **per fascia e per giro**, non un limite totale:
il comando continua a batch fino all'esaurimento o al limite di tempo.
`--max-time` deve essere un intero positivo (default 1800 secondi); viene
controllato fra i batch e il batch già iniziato termina anche se supera la soglia.
Il riepilogo conta le radici eliminate per fascia, senza sommare i figli.
Un lock su file impedisce esecuzioni sovrapposte e si libera alla chiusura del
processo; un nuovo avvio riparte dai candidati ancora presenti. Un errore
annulla il batch corrente, lasciando confermati quelli già completati.

Non vengono inviate cancellazioni federate. La riconciliazione delle righe
polimorfiche orfane appartiene alla Database sanity separata. Il comando resta
esclusivamente CLI, senza richiamo da `openbook:cron` o dall'endpoint HTTP.

La pagina **Amministrazione → Database** mostra nella testata la dimensione
stimata dell'intero database, inclusi dati e indici. Il tab **Maintenance**
contiene le statistiche e le azioni delle sole tabelle operative da pulire.
Le durate si configurano nel tab **Retention**, aperto inizialmente, e hanno
default **0 (disabilitata)**, indipendente per ciascuna fascia.
Il form salva soltanto le impostazioni: non esegue cancellazioni.
Il pulsante **Anteprima** usa le durate già salvate e mostra fino a 10 candidati
per fascia con le stesse query del dry-run CLI, senza modificare dati. I link
aprono il dettaglio del post in una nuova scheda.
I **Pertinenti** sono post remoti che possono comparire nella Home di almeno un
utente locale o hanno commenti di attori locali; i **Non pertinenti** sono quelli
che comparirebbero soltanto in Mondo, senza commenti locali. L'età decorre dalla
prima importazione, senza rinnovi per nuovi commenti o interazioni.
Con entrambe le fasce attive il form richiede una durata dei Pertinenti almeno
pari a quella dei Non pertinenti. Per disabilitare tutto, riportare entrambe a 0.

Per una pulizia giornaliera, aggiungere un **cron dedicato** in un orario poco
trafficato, indicando i percorsi assoluti di PHP e della propria installazione:

```cron
0 3 * * * cd /percorso/openbook && /usr/bin/php artisan openbook:prune-remote-posts >> storage/logs/remote-post-retention.log 2>&1
```

La Database sanity resta un processo separato, anche quando la retention è
disabilitata; si esegue con il comando descritto sotto.
Un campione vuoto è normale se non
esistono post importati prima della soglia. I link rispettano `APP_URL`; aprirli
con il proprio account per verificare i contenuti visibili. I post già segnati
come cancellati possono comparire nel batch ma non avere una pagina consultabile.

### Database sanity

La riconciliazione elimina **like, menzioni e notifiche orfani**, cioè riferiti
a un oggetto padre che non esiste più fisicamente. Funziona anche con entrambe
le durate di retention a 0 e può ripulire residui di qualsiasi cancellazione.
Non elimina padri ancora presenti, anche se segnati come `deleted`, e conserva
il registro audit, media e file. I push collegati alle notifiche eliminate
spariscono tramite FK; le revisioni dei destinatari vengono aggiornate nella
stessa transazione. Nessuna attività federata viene generata.

Per verificare prima della pulizia:

```bash
php artisan openbook:database-sanity --dry-run
```

L'anteprima esegue soltanto SELECT e mostra una riga per tabella e tipo padre,
ad esempio `likes / post: 12 orfani nel campione (massimo 100).` Seleziona un
solo batch per coppia: i numeri **non sono totali globali**. I tipi non
supportati sono segnalati e conservati; il loro conteggio è invece un totale
per tipo, con al massimo 100 tipi segnalati per tabella. Nessun corpo o altro
contenuto degli oggetti viene mostrato.

L'invocazione ordinaria per pulire è:

```bash
php artisan openbook:database-sanity
```

Default: **100 righe per batch e per tabella/tipo**, **1.800 secondi** per la
pulizia. Le coppie vengono percorse a turno fino all'esaurimento o al limite
di tempo, verificato fra i batch; il batch in corso termina. Il report finale
indica le righe realmente eliminate in questa esecuzione. Se si raggiunge
il limite, rilanciare il comando; una pulizia già conclusa è ripetibile senza
ulteriori cancellazioni. Per cambiare i limiti:

```bash
php artisan openbook:database-sanity --batch-size=500 --max-time=300
```

`--max-time` riguarda la pulizia, non l'anteprima o il report dei tipi sconosciuti.
Un lock su file impedisce sovrapposizioni fra due pulizie sanity, senza scadenza
mentre il processo è attivo, e viene rilasciato anche in caso di errore. È
indipendente dal lock della retention. Ogni batch notifiche è transazionale;
in caso di errore i batch precedenti già completati restano applicati.

Il comando è **CLI autonomo**, senza richiamo obbligatorio da retention,
`openbook:cron` o purge delle tabelle operative. Può essere
eseguito periodicamente con un cron dedicato:

```cron
30 3 * * * cd /percorso/openbook && /usr/bin/php artisan openbook:database-sanity >> storage/logs/database-sanity.log 2>&1
```

Se si desidera pulire subito dopo la retention, i due comandi possono essere
eseguiti in successione nello stesso script cron. Non servono worker permanenti
o Redis.

Nel tab **Amministrazione → Database → Database sanity**, l'anteprima si carica
all'apertura e usa gli stessi selettori e campioni da 100 righe del dry-run CLI.
La tabella mostra tabella, tipo di oggetto e numero di orfani nel campione;
**Aggiorna anteprima** ricarica soltanto le letture. I tipi non supportati sono
segnalati separatamente e vengono conservati.

**Pulisci gli orfani**, dopo conferma, esegue il servizio di sanity mediante
POST protetta da CSRF e riservata agli amministratori. I limiti web sono fissi:
100 righe per batch e 5 secondi controllati fra i batch; quello in corso termina
anche se supera il limite. Una pulizia parziale può essere ripetuta oppure
completata tramite CLI. Il lock è condiviso con il comando, così web e CLI non
avviano pulizie contemporanee. L'azione viene registrata nell'audit con i conteggi.
Dopo la pulizia si torna allo stesso tab, con campioni aggiornati e una colonna
aggiuntiva per le righe effettivamente eliminate nell'ultima esecuzione.

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
convivere: un lock breve sul claim e una lease per riga impediscono la doppia
pubblicazione.

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
