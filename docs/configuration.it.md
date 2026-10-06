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

Configura `openbook:cron` ogni minuto per elaborare le attività in attesa e
eseguire le manutenzioni periodiche. Il comando coordina questi task:

- `openbook:process-inbox` — processa la coda `inbox`;
- `openbook:deliver` — processa la coda `delivery`;
- `openbook:deliver-push` — consegna le notifiche Web Push in attesa;
- `openbook:confirm-outgoing-follows` — conferma Follow remoti ancora pending
  se risultiamo gia' nella collection `followers` del target (Accept mancante);
- `openbook:fetch-feeds` — importa i feed RSS/Atom;
- `openbook:auto-announce` — esegue le condivisioni automatiche configurate;
- `openbook:purge-database` — pulisce le righe operative scadute ogni 24 ore;
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
