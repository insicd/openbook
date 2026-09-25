# Browser Push Notifications — Working Notes

Documento di progettazione e memoria degli sprint implementati.

## Obiettivo

Estendere le notifiche locali gia' esistenti con Web Push per browser desktop e
mobile, senza cambiare i servizi/eventi che oggi generano le notifiche e senza
introdurre worker permanenti.

Il sistema attuale resta autorevole per contenuto, stato letto/non letto, badge e
pagina notifiche. Il sottosistema push e' soltanto un canale di consegna aggiuntivo.

## Flusso esistente rilevante

- Tutti i principali eventi locali e federati convergono in
  `App\Application\Services\NotificationCreator::notify()`.
- `NotificationCreator` crea una riga in `notifications` e incrementa
  `users.notifications_revision`.
- `GET /notifiche/feed` viene interrogato ogni 60 secondi da
  `public/assets/js/notifications-live.js` soltanto quando la scheda e' visibile.
- Il feed usa `notifications_revision` come ETag e puo' rispondere `304`.
- Il polling non modifica `notifications.read_at`.
- Le notifiche diventano lette aprendo il pannello della campanella, visitando la
  pagina notifiche o chiamando esplicitamente l'endpoint `markAllRead`.

## Decisioni backend/cron consolidate

### Coda persistente dedicata

Quando `NotificationCreator` salva una nuova `Notification`, deve creare nella
stessa transazione una riga nella nuova tabella `push_notifications`.

Schema indicativo:

- `id` UUID, chiave primaria;
- `notification_id` UUID, univoco;
- timestamp Laravel `created_at` e `updated_at`.

Vincolo:

- FK `notification_id -> notifications.id`;
- `ON DELETE CASCADE`;
- `ON UPDATE CASCADE` non necessario: gli UUID delle notifiche non cambiano.

La riga e' lavoro temporaneo, non storico di consegna. Non aggiungere stati push
alla tabella `notifications`.

### Periodo di grazia

Il cron ignora sempre le righe piu' recenti del periodo di grazia, inizialmente 75
secondi.

Decisione esplicita: NON salvare `available_at = created_at + 75 seconds`.
La finestra viene calcolata nella query del comando:

```text
push_notifications.created_at <= now() - grace_period_seconds
```

Il valore deve vivere nella configurazione, indicativamente:

```php
'push' => [
    'grace_period_seconds' => 75,
    'batch_size' => 20,
    'http_timeout_seconds' => 5,
],
```

Non sono previsti retry: la push e' un avviso accessorio, mentre la notifica locale
resta il dato autorevole. Al termine del singolo tentativo la riga viene sempre
eliminata, per evitare duplicati, consegne tardive e notification storm dopo outage.

### Il poller consuma la coda push

Non viene modellata la presenza tramite sessioni Laravel, heartbeat o timestamp
sull'utente.

Una richiesta autenticata riuscita a `GET /notifiche/feed`, inclusa una risposta
`304`, dimostra che almeno una scheda visibile dell'utente e' attiva. L'endpoint
deve quindi eliminare le righe `push_notifications` pendenti per quell'utente.

La cancellazione e' user-level, non limitata alle sole otto notifiche restituite nel
payload. Questo evita push residue durante raffiche con piu' di otto notifiche.

Per non consumare una notifica creata durante l'elaborazione della richiesta:

- acquisire `poll_started_at` all'inizio;
- eliminare solo righe la cui notifica appartiene all'utente autenticato e ha
  `notifications.created_at <= poll_started_at`.

Semantica centrale:

> Se una riga sopravvive nella coda push oltre il periodo di grazia, nessuna scheda
> visibile dell'utente l'ha consumata e la push puo' essere inviata.

Non usare `notifications.read_at` come indicatore di polling: letto e ricevuto dal
poller sono concetti distinti.

### Elaborazione cron diretta

`push_notifications` e' direttamente la coda/outbox persistente. Non trasformare le
righe in una seconda coda di job Laravel.

Creare un comando dedicato, indicativamente:

```text
openbook:deliver-push --max-time=N
```

Il comando viene richiamato da `openbook:cron`, in modo analogo agli altri comandi
specializzati, e rispetta il budget temporale adatto allo shared hosting.

Per ogni riga abbastanza vecchia:

1. notifica gia' letta: elimina la riga push;
2. nessuna sottoscrizione valida: elimina la riga push;
3. tenta una sola consegna best-effort verso tutte le sottoscrizioni correnti;
4. sottoscrizione scaduta/permanentemente invalida (es. HTTP 404/410): elimina la
   sottoscrizione;
5. qualunque esito: logga quanto utile e elimina sempre la riga push.

Il comando generale resta un orchestratore, indicativamente:

```text
openbook:cron
  -> openbook:process-inbox
  -> openbook:deliver
  -> openbook:deliver-push
  -> openbook:confirm-outgoing-follows
  -> openbook:fetch-feeds
  -> openbook:purge-database
```

### Concorrenza

Poller e cron possono agire sulla stessa riga vicino alla soglia temporale; due cron
possono inoltre sovrapporsi. Il cron generale non possiede un vero semaforo globale:
il timestamp del cron web limita la frequenza ma non copre il cron CLI e non e' un
lock atomico.

Proprieta' richieste:

- una push non deve essere inviata due volte per concorrenza;
- il poller deve poter sopprimere una riga ancora realmente pendente;
- il claim distruttivo puo' perdere una push se PHP termina prima dell'invio: e'
  accettato dal modello best effort.

Decisione: usare la cancellazione condizionale come claim atomico, senza aggiungere
`reserved_at`:

1. il cron legge `id` e `notification_id` di una candidata stale;
2. esegue un `DELETE push_notifications WHERE id = ?` condizionato alla soglia;
3. se le righe eliminate sono 1, ha vinto il claim e tenta la consegna;
4. se sono 0, poller o altro cron l'hanno gia' consumata e non invia.

Due cron concorrenti non possono ottenere entrambi `deleted = 1`. Se il poller
cancella per primo, la push viene soppressa; se il cron cancella per primo, completa
il tentativo. Un crash PHP dopo il claim puo' perdere la push: accettato esplicitamente
dal modello best-effort. La `Notification` originale non viene eliminata.

Il comando prende al massimo `batch_size` candidate per giro, sempre ordinate dalla
piu' vecchia, e controlla il budget temporale fra una notifica e la successiva. Il
timeout HTTP deve essere breve e configurabile: `--max-time` non interrompe una
richiesta HTTP gia' bloccata. Questa e' una prima implementazione: con la crescita
di utenti/device andra' monitorata l'eta' della coda e raffinata la capacita', per
evitare che le righe in fondo arrivino con ritardi eccessivi.

## VAPID

### Identita' crittografiche separate

Non riutilizzare le coppie RSA per-Actor di ActivityPub.

- ActivityPub: coppia RSA distinta per ogni Actor locale;
- VAPID: una coppia P-256 stabile per l'intera installazione Openbook;
- PushSubscription: materiale `p256dh` e `auth` distinto per ogni sottoscrizione,
  generato dal browser e usato per cifrare il payload.

La stessa chiave pubblica VAPID dell'istanza viene passata a tutti i browser durante
`PushManager.subscribe()`. Il backend usa la privata corrispondente per autenticarsi
verso i push service.

### Persistenza e generazione

Salvare la coppia VAPID in una sola riga `system_settings`:

- chiave logica `push_vapid_keys`;
- valore logico JSON con `publicKey` e `privateKey`;
- intero JSON cifrato a riposo tramite `APP_KEY`, aggiungendo la chiave logica a
  `SystemSetting::ENCRYPTED_KEYS` (o equivalente incapsulamento dedicato).

Una sola riga evita coppie parziali/disallineate e consente un `insertOrIgnore`
atomico sulla chiave unique. Il VAPID subject e' l'URL pubblico dell'istanza
(`config('app.url')`).

La generazione deve essere lazy e atomica tramite un servizio dedicato (es.
`VapidKeyManager::getOrCreate()`). Non generarla alla prima esecuzione cron: il
browser ha bisogno della pubblica prima del cron, quando crea la subscription.

Il gestore viene invocato dal caricamento della pagina Impostazioni autenticata. Se
la coppia non esiste, la crea silentemente e la salva; il controller passa la chiave
pubblica alla view, che la incorpora direttamente nel markup/configurazione JS. Non
creare un endpoint AJAX dedicato al solo recupero della chiave pubblica.

La prima visita alla pagina Impostazioni puo' quindi causare una singola scrittura
di configurazione anche se l'utente non attiva poi le push. Questo e' intenzionale e
semplifica il frontend. Gestire comunque accessi concorrenti senza produrre due
coppie diverse.

La coppia deve restare stabile. Una rotazione invalida il vincolo delle subscription
esistenti e richiede una nuova sottoscrizione dei browser.

## Sottoscrizioni browser

### Modello dati

Nuova tabella `push_subscriptions`, relazione 1:N con `users`.

Schema concordato, compatibile con MySQL/MariaDB e SQLite:

- `id` UUID;
- `user_id` UUID, FK con `ON DELETE CASCADE`;
- `endpoint_hash` char(64), SHA-256 dell'endpoint e indice unique portabile;
- `endpoint` text, cifrato tramite cast Eloquent `encrypted`;
- `public_key` text, valore `keys.p256dh`, cifrato;
- `auth_token` text, valore `keys.auth`, cifrato;
- `expiration_time` unsigned big integer nullable, millisecondi Unix nel formato
  originale di `PushSubscription.expirationTime`;
- timestamp Laravel.

Usare campi separati: il backend e la libreria devono comunque conoscere i campi
standard necessari e un JSON cifrato non sarebbe interrogabile dal database.
`endpoint_hash` resta in chiaro per lookup/deduplicazione ed evita vincoli unique
problematici su URL `TEXT` lunghi. Il backend calcola sempre l'hash; non lo accetta
dal client.

Conservare `expirationTime` nel formato numerico originale evita conversioni fra
millisecondi JavaScript e secondi/timestamp PHP; normalmente il valore e' `null`.

Un utente puo' avere piu' sottoscrizioni (browser/dispositivi/profili differenti).
Non creare un device ID artificiale: l'endpoint identifica la subscription.

### Validazione input

Il POST autenticato/CSRF accetta soltanto i campi attesi:

- `endpoint`: required, stringa, URL HTTPS, lunghezza massima finita;
- `expirationTime`: nullable, intero/timestamp nel formato prodotto dal browser;
- `keys.p256dh`: required, stringa base64url con limite di lunghezza;
- `keys.auth`: required, stringa base64url con limite di lunghezza.

Ignorare qualunque campo extra usando esclusivamente l'input validato. Dati mancanti
o malformati producono la normale risposta Laravel `422 Unprocessable Content`.
Non serve conservare estensioni sconosciute del JSON browser.

### Proprietario e riallineamento browser/database

`endpoint_hash` e' univoco globalmente. Se un endpoint viene registrato esplicitamente
da un altro utente autenticato, l'upsert sostituisce `user_id`: una subscription
della stessa origine/browser non deve consegnare notifiche di piu' account.

Non riassegnare automaticamente al solo caricamento della pagina. Il controller passa
alla view gli `endpoint_hash` appartenenti all'utente corrente. JavaScript recupera
la subscription locale, calcola SHA-256 dell'endpoint con Web Crypto e confronta:

- nessuna subscription browser: mostra `Attiva notifiche push`;
- hash presente fra quelli dell'utente: mostra stato attivo;
- subscription presente ma hash assente: mostra
  `Attiva notifiche push per questo account`.

Nel terzo caso il click invia la subscription esistente e l'upsert la riassegna. Se
la tabella backend e' stata persa/svuotata, lo stesso flusso ricrea la riga.

Confrontare inoltre `subscription.options.applicationServerKey` con la VAPID pubblica
corrente. Se non coincide (es. perdita/rotazione delle VAPID), al click annullare la
vecchia subscription e crearne una nuova prima del POST.

### Attivazione dalla pagina Impostazioni

Posizione: card `Account e privacy` in `resources/views/settings/index.blade.php`,
vicino alle preferenze esistenti ma indipendente dal submit account.

Usare un `<button type="button">Attiva notifiche push</button>`, non link, checkbox o
campo di `UpdateAccountRequest`:

- la richiesta permesso deve nascere da un gesto esplicito;
- il flusso e' asincrono e specifico del browser corrente;
- non deve causare un Update ActivityPub del profilo.

Il contenitore parte nascosto; JavaScript rileva supporto, permesso e subscription:

- API mancanti: nascondi o mostra "non supportato";
- `Notification.permission === 'denied'`: stato bloccato, pulsante disabilitato;
- subscription assente: mostra Attiva.
- subscription esistente: applica sempre il confronto ownership/VAPID descritto
  sopra; la sola presenza nel browser non implica stato attivo per questo utente.

Flusso del click:

1. registra/recupera il service worker;
2. chiede `Notification.requestPermission()`;
3. legge dal markup la chiave pubblica VAPID gia' fornita dalla view;
4. chiama `pushManager.subscribe({userVisibleOnly: true, applicationServerKey})`;
5. invia la subscription JSON al backend con fetch autenticato e CSRF;
6. il backend esegue un upsert sicuro;
7. la UI mostra che le push sono attive sul browser corrente.

Prevedere endpoint autenticati e CSRF per subscribe/unsubscribe. La disattivazione
e' inclusa nella prima versione:

1. nello stato attivo mostra `<button type="button">Disattiva notifiche push</button>`;
2. invia DELETE autenticata/CSRF con l'endpoint corrente;
3. il backend calcola `endpoint_hash` ed elimina soltanto la riga appartenente
   all'utente autenticato;
4. dopo il successo backend chiama `PushSubscription.unsubscribe()` nel browser;
5. aggiorna la UI allo stato non sottoscritto/attivabile.

Se il DELETE backend fallisce, non dichiarare la funzione disattivata e lasciare la
subscription browser intatta, mostrando un errore non invasivo. Se l'unsubscribe
browser fallisce dopo il DELETE, il backend non consegna comunque piu' push; una
visita successiva rilevera' la desincronizzazione e proporra' il riaggancio.

### Creazione condizionale del lavoro push

`NotificationCreator` mantiene autorevoli la notifica locale e l'incremento della
revisione. Dopo il commit registra una callback `DB::afterCommit` che, in un
`try/catch`, verifica se il destinatario possiede almeno una subscription e in caso
positivo crea `push_notifications`. Gli errori vengono loggati in modo essenziale e
non propagati al flusso principale: il canale accessorio non deve rollbackare la
notifica locale. Un crash dopo il commit puo' perdere la push ed e' coerente col
best effort.

Se non esistono sottoscrizioni, non viene creata alcuna riga di lavoro. Attivare le
push non deve recuperare notifiche nate prima dell'attivazione.

## Service worker e contenuto

Prima versione volutamente minimale:

- evento `push`: mostra una Web Notification;
- titolo: `APP_NAME`/nome runtime dell'istanza (`config('app.name')`);
- body: testo localizzato prodotto da `Notification::message($locale)`;
- click: porta in primo piano una finestra Openbook esistente oppure ne apre una;
- destinazione iniziale unica: `/notifiche`.

Estendere `Notification::message()` con un parametro locale opzionale. Se omesso,
continua a usare la locale corrente dell'applicazione come tutto il codice esistente;
il cron passa esplicitamente `recipient.settings.locale`.

Payload concordato:

```json
{
  "title": "Nome istanza",
  "body": "Testo localizzato della notifica",
  "url": "/notifiche",
  "icon": "https://istanza.example/.../icon-192.png",
  "tag": "notification-<uuid>"
}
```

- `icon`: riusa l'icona dinamica 192 configurata nel sistema favicon/manifest; se
  non configurata, omettere il campo senza fallback custom;
- niente `badge` nella prima versione;
- `tag` distinto per notifica: `notification-<notification UUID>`;
- niente aggregazione backend e niente tag unico;
- cinque notifiche applicative producono cinque Web Notification distinte e il
  sistema operativo puo' mostrarne il conteggio come cinque;
- nessun HTML, contenuto post o dato ActivityPub nel payload.

La preview specifica e' accettata; l'utente puo' limitarla tramite le impostazioni
del proprio dispositivo/browser. Non e' richiesto nella prima versione il deep link
al singolo post/commento.

### Posizione e scope

Distinguere i due script:

- `public/assets/js/push-notifications.js`: normale script UI della pagina
  Impostazioni, caricato tramite `Assets::url()`;
- `public/service-worker.js`: service worker statico raggiungibile come
  `/service-worker.js`.

Il service worker e' volutamente nella root pubblica per ottenere naturalmente lo
scope `/`. Non collocarlo sotto `/assets/js`: avrebbe scope predefinito limitato a
quella cartella e richiederebbe `Service-Worker-Allowed: /`, quindi configurazione o
controller aggiuntivi senza beneficio.

Registrazione KISS:

```javascript
navigator.serviceWorker.register('/service-worker.js');
```

Non passare il service worker attraverso `Assets::url()` e non aggiungere query
string di cache busting. Il browser gestisce autonomamente il ciclo di aggiornamento
del worker confrontando lo script. Il worker non implementa cache offline, fetch
interception o precache: gestisce soltanto `push` e `notificationclick`.

## Compatibilita' browser

Usare soltanto Web Push standard e progressive enhancement, senza workaround o
browser/version sniffing.

Feature detection:

- `serviceWorker` in `navigator`;
- `PushManager` in `window`;
- `Notification` in `window`;
- contesto sicuro.

HTTPS e' obbligatorio in produzione; localhost resta il percorso di sviluppo.

Stati UI:

- supportato e non sottoscritto: pulsante Attiva;
- sottoscrizione esistente e riconosciuta per l'utente: stato attivo, pulsante
  `Disattiva notifiche push`;
- permesso negato: messaggio che rimanda alle impostazioni del browser;
- API mancanti: messaggio non supportato, con nota che su iPhone/iPad Openbook va
  aggiunto alla schermata Home e aperto dalla sua icona.

Su iOS/iPadOS si usa la Home Screen web app standard. Openbook possiede gia' manifest
con `display: standalone`, start URL e icone. Non aggiungere implementazioni Apple
specifiche, SDK o fallback proprietari.

### Account diversi nello stesso browser

La subscription resta associata all'ultimo utente che l'ha attivata o riagganciata.
Dopo il logout puo' continuare a ricevere le preview di quell'utente finche' un altro
account non esegue esplicitamente il riaggancio dalle Impostazioni. Questo
comportamento device-level e' accettato nella prima versione: non disattivare al
logout e non introdurre gestione speciale per browser condivisi/kiosk.

## Consegna multi-subscription

Best effort esplicito:

- un singolo giro tenta tutte le subscription correnti dell'utente;
- successi e fallimenti possono differire fra dispositivi;
- nessun retry e nessuna tabella per delivery per-device;
- elimina subscription certamente scadute/invalide;
- elimina sempre `push_notifications` al termine del giro;
- la notifica locale resta sempre disponibile nella UI Openbook.

### Protezione endpoint (SSRF)

Riutilizzare semplicemente `App\Infrastructure\Security\Http\SsrfGuard`, gia'
presente in Openbook, immediatamente prima di consegnare a ciascun endpoint.

- se l'endpoint e' accettato, passarlo alla libreria Web Push;
- se il guard lo rifiuta, non inviare, elimina la subscription e logga in modo
  essenziale;
- nessuna allowlist di provider push;
- nessuna doppia verifica registrazione/consegna;
- nessun client PSR custom o nuova infrastruttura SSRF nella prima versione.

La consegna resta best effort e il rischio e' proporzionato a notifiche social non
critiche.

## Separazione concettuale

- `notifications`: dato applicativo autorevole e stato letto/non letto;
- `push_notifications`: lavoro temporaneo di consegna push;
- polling feed: consumo/soppressione user-level del lavoro push;
- sottoscrizioni: dispositivi/browser abilitati a ricevere Web Push;
- cron push: singolo tentativo di consegna best-effort e pulizia.

## Dipendenza scelta

Usare `minishlink/web-push:^11.0`, compatibile con PHP 8.2+, VAPID e subscription
Web Push standard. `gmp`/`bcmath` restano ottimizzazioni opzionali. Non usare un
Laravel notification channel parallelo e non implementare la crittografia a mano.

## Aspetti operativi ancora da dettagliare nel piano di implementazione

- eager loading nel cron (`notification.recipient.settings`, actor, notifiable e
  relazioni community quando necessarie) per evitare N+1 e generare il testo nella
  locale del destinatario;
- piano dettagliato dei test backend, concorrenza, service worker e integrazione;
- monitoraggio futuro di profondita'/eta' della coda oltre il batch iniziale.

## Sprint 1 implementato localmente

- dipendenza `minishlink/web-push:^11.0` installata;
- migration `push_subscriptions` applicata al database locale;
- endpoint massimo 4096 caratteri, chiavi base64url massimo 512 caratteri;
- endpoint, `p256dh` e `auth` cifrati a riposo; hash SHA-256 endpoint unico;
- generazione lazy e atomica della coppia VAPID nella pagina Impostazioni;
- API autenticate POST/DELETE con CSRF per aggancio, riaggancio e disattivazione;
- UI progressiva nella scheda Account e privacy con rilevamento degli stati del
  browser e confronto con le subscription dell'utente;
- service worker root minimale, usato in questo sprint solo per consentire la
  subscription; ricezione e click saranno aggiunti nello sprint dedicato;
- test feature dedicati a VAPID, cifratura, validazione, ownership e DELETE.

## Sprint 2 implementato localmente

- migration `push_notifications` applicata al database locale, con una riga
  univoca per notifica e FK `ON DELETE CASCADE`;
- creazione condizionale dell'outbox tramite `DB::afterCommit`, soltanto quando il
  destinatario possiede almeno una subscription;
- errori di enqueue assorbiti e loggati senza invalidare la notifica locale;
- il polling `GET /notifiche/feed` elimina tutte le push pendenti dell'utente,
  anche quando risponde `304`, rispettando il limite temporale di inizio poll;
- `Notification::message(?string $locale = null)` supporta una locale esplicita
  senza modificare il comportamento delle chiamate esistenti;
- test di integrazione per enqueue, bypass, isolamento errori, pulizia user-level,
  ramo `304` e localizzazione esplicita.

## Sprint 3 implementato localmente

- comando `openbook:deliver-push --max-time=N`, richiamato anche da
  `openbook:cron` dopo la delivery ActivityPub;
- selezione oldest-first limitata dal batch e da `created_at <= now() - 75s`;
- claim atomico tramite DELETE condizionale prima di qualunque invio;
- payload con nome istanza, messaggio nella locale del destinatario, URL notifiche,
  tag univoco `notification-<uuid>` e icona dinamica soltanto se configurata;
- consegna Web Push `aes128gcm`, timeout breve, TTL provider configurabile (default
  3600 secondi) e un solo tentativo Openbook per device;
- eliminazione delle subscription gia' scadute, rifiutate dal controllo SSRF o
  dichiarate scadute dal push service con HTTP 404/410;
- errori transitori consumano comunque l'outbox ma conservano la subscription;
- service worker completo: attivazione immediata, visualizzazione push e click che
  riusa una finestra Openbook oppure apre `/notifiche`;
- test con gateway HTTP simulato per successo, 410, 503 e destinazione non sicura,
  oltre ai test di comando e del service worker.
