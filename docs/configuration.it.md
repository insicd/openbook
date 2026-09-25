> Documentazione: [Italiano](README.it.md) · [English](README.md)

## Configurazione

Tutte le impostazioni specifiche di Openbook sono centralizzate in
`config/openbook.php` e configurabili tramite variabili d'ambiente (vedi
`.env.example` per l'elenco completo con commenti). Le principali:

| Variabile | Descrizione |
|---|---|
| `OPENBOOK_DOMAIN` | Dominio pubblico dell'istanza, usato negli indirizzi `utente@dominio`. Deve coincidere con l'host di `APP_URL`. Se cambi dominio, aggiorna entrambi e poi `php artisan openbook:repair-federation-urls` (altrimenti Lemmy rifiuta i Follow se id e inbox sono su host diversi). |
| `OPENBOOK_INSTALLED` | Impostata automaticamente dall'installer; non modificare a mano. |
| `OPENBOOK_WEB_CRON_ENABLED` / `OPENBOOK_WEB_CRON_TOKEN` | Abilitano l'esecuzione dei processi periodici via richiesta HTTP, per hosting privi di cron reale. |
| `OPENBOOK_REGISTRATION_OPEN` / `OPENBOOK_REGISTRATION_REQUIRES_APPROVAL` | Controllano l'apertura delle registrazioni. |
| `OPENBOOK_MEDIA_MAX_SIZE_KB` / `OPENBOOK_MEDIA_MAX_ATTACHMENTS` | Dimensione massima (KB) e numero massimo di immagini allegabili a un post. |
| `OPENBOOK_POST_MAX_LENGTH` | Lunghezza massima (caratteri) del testo di un post. |
| `OPENBOOK_COMMENT_MAX_DEPTH` | Livelli di annidamento dei commenti considerati "normali" in configurazione (la struttura reale non ha un limite rigido, vedi [Limitazioni](roadmap.it.md#limitazioni-note)). |
| `OPENBOOK_SEARCH_MIN_LENGTH` / `OPENBOOK_SEARCH_PER_SECTION` | Lunghezza minima della query e risultati massimi per sezione nella ricerca locale. |
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
