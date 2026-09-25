# Supporto video — analisi

Documento di analisi e memoria delle decisioni implementative. Le sezioni
"Stato iniziale verificato" e "Questioni aperte" fotografano la situazione
prima dello sviluppo; gli esiti sono riportati in "Stato di implementazione".

## Obiettivo

Consentire agli utenti locali di allegare video ai post con un supporto
inizialmente semplice, preservando una pubblicazione rapida quando il file e'
gia' adatto e spostando fuori dalla richiesta HTTP l'eventuale transcodifica.

Tutto il supporto ai video deve essere **disabilitato per default** e attivato
esplicitamente dall'amministratore dell'istanza. Se non attivato, Openbook deve
continuare a funzionare esattamente come oggi, senza accettare upload video,
senza eseguire probe/transcodifiche e senza richiedere FFmpeg.

## Stato iniziale verificato

### Upload locale

- Il form usa il campo storico `images[]`, nonostante accetti gia' immagini e
  audio. Il nome e' quindi ormai generico solo di fatto.
- `StorePostRequest` e `UpdatePostRequest` validano numero, MIME e dimensione
  degli allegati usando la configurazione media comune.
- Il limite predefinito e' 8 MiB per singolo file
  (`OPENBOOK_MEDIA_MAX_SIZE_KB`); non esiste un limite distinto per categoria.
- `MediaUploader` rileva il MIME effettivo tramite `UploadedFile::getMimeType()`:
  non si affida all'estensione dichiarata dal browser.
- Gli audio consentiti vengono copiati sul disco pubblico senza elaborazione.
- Le immagini JPEG/PNG/WebP vengono ricodificate con GD quando disponibile,
  rimuovendo di conseguenza i metadati EXIF; le GIF restano intatte.
- Per immagini oltre 640 px viene generata sincronicamente una variante
  `thumbnail` con dimensione massima 640 px.
- Upload, creazione del post e collegamento degli allegati avvengono dentro la
  stessa transazione applicativa. L'I/O sul filesystem non e' transazionale,
  quindi un rollback DB puo' comunque lasciare file orfani.

### Pubblicazione

- `PostComposer::compose()` crea immediatamente il post con stato `published`
  e `published_at = now()`.
- Nella stessa transazione collega media, hashtag e menzioni e crea eventuali
  notifiche locali.
- Dopo il commit aggiorna contatori/community, crea eventuali Announce e passa
  il `Create` serializzato ad `ActivityDelivery`.
- La consegna HTTP ActivityPub e' gia' asincrona tramite job, ma il payload
  viene costruito al momento della composizione. Non e' sufficiente ritardare
  il job di delivery: il post e i suoi effetti locali sarebbero gia' pubblici.
- Gli stati dei post sono attualmente soltanto `published` e `deleted`.

### Modello media e federazione

- `media` contiene gia' MIME, byte size, larghezza e altezza; riconosce i video
  tramite prefisso `video/`.
- `media_variants` contiene oggi la sola variante `thumbnail`.
- La view degli allegati sa gia' mostrare media video con `<video controls>`.
- L'ingresso federato riconosce gia' `Video`, `Document video/*` e alcuni video
  inline: il supporto di visualizzazione dei video remoti esiste.
- `NoteSerializer` serializza gia' un media `video/*` come ActivityStreams
  `Document`, con `mediaType`, URL definitivo e descrizione in `name`. Non
  serializza al momento una variante poster/thumbnail.

### Esecuzione periodica

- `openbook:cron` gira normalmente ogni minuto e divide un budget predefinito
  di 20 secondi fra inbox, delivery e push; esegue inoltre follow, feed e
  manutenzione.
- E' pensato anche per hosting condivisi e per un endpoint HTTP protetto.
- Una transcodifica FFmpeg puo' superare largamente tale budget: inserirla nel
  runner generale senza una politica esplicita rischierebbe timeout, processi
  interrotti e starvation delle altre attivita'.

## Ipotesi iniziale proposta

1. Alla ricezione del form si validano sempre tipo, dimensione massima assoluta
   e struttura minima del video.
2. Un video gia' conforme a un profilo supportato viene conservato senza
   transcodifica e il post segue il flusso immediato attuale.
3. Un video valido ma non conforme o troppo oneroso viene salvato e associato
   a un post non ancora pubblicato.
4. Un processo asincrono transcodifica il file, aggiorna il media e completa
   atomicamente la pubblicazione del post.
5. Solo allora partono gli effetti oggi legati alla pubblicazione: visibilita'
   locale, menzioni/notifiche, contatori e consegna ActivityPub.

## Prima valutazione architetturale

L'idea fast-path/slow-path e' sensata, ma la soglia non dovrebbe basarsi
soltanto sulla dimensione in byte. Un file piccolo puo' usare codec o parametri
non compatibili; un file grande puo' essere gia' perfettamente conforme e non
richiedere lavoro. Servono due controlli distinti:

- limite assoluto di upload: protegge PHP, disco e applicazione;
- profilo tecnico del video: container, codec video/audio, risoluzione e
  possibilmente durata/bitrate determinano copy-through o transcodifica.

Una prima scelta interoperabile potrebbe essere MP4 con H.264 + AAC, ma codec,
container e soglie non sono ancora decisi. La verifica affidabile richiede un
probe del file (tipicamente `ffprobe`), non soltanto MIME ed estensione.

## Valutazione di una `posts_queue`

Una tabella separata e' coerente con l'obiettivo di non far esistere un vero
`Post` finche' il video non e' pronto. E' probabilmente piu' sicura di un nuovo
stato su `posts`, perche' tutte le query esistenti assumono che un post non
deleted sia pubblicabile e non dovrebbero essere corrette una per una.

La coda non puo' pero' contenere soltanto una FK a `posts`, dato che per scelta
il post non esiste ancora. Deve conservare uno snapshot sufficiente a invocare
alla fine il flusso normale di composizione:

- autore e dati del composer (testo, titolo, CW, lingua, visibilita');
- riferimenti a citazione/community/Group indirizzato;
- percorso sicuro del file sorgente temporaneo e relativi metadati;
- alt text e posizione degli allegati;
- stato di lavorazione, errore, tentativi e timestamp di claim;
- identificatore/idempotency key per impedire doppie pubblicazioni.

Quando l'elaborazione termina, il finalizzatore dovrebbe costruire un
`UploadedFile`/input media gia' definitivo oppure offrire a `PostComposer` un
metodo equivalente per media preparati. Deve poi chiamare una sola volta il
normale percorso di pubblicazione, cosi' hashtag, menzioni, community,
notifiche e federazione restano centralizzati.

Con allegati misti emerge una scelta UX: se un post contiene immagini/audio e
un video da transcodificare, l'intero post resta in coda. Pubblicare prima gli
altri allegati e aggiungere il video dopo produrrebbe un `Create` incompleto e
un successivo `Update`, oltre a mostrare localmente un contenuto parziale.

## Benchmark da progetti federati

### Loops (repository ufficiale `joinloops/loops-server`, settembre 2026)

- Default applicativo: 40 MiB, durata massima configurata inizialmente a 60
  secondi (una migration successiva porta il valore a 180 secondi).
- Formati configurati di default: MP4, MOV e M4V; l'impostazione pubblica
  iniziale dichiara MP4.
- Pipeline sempre asincrona con coda dedicata `video-processing`, lock
  `WithoutOverlapping`, timeout 300 secondi e 3 tentativi.
- Stato video esplicito `pending / processing / completed / failed`; la
  federazione avviene nel job finale dopo il completamento.
- Output: MP4 H.264/AAC, short edge predefinito 720 px, CRF 23, preset `slow`,
  audio 128 kbps, massimo 60 fps, `yuv420p`, `faststart`, profilo High livello
  4.1. Il job tronca cautelativamente a 180 secondi.
- Genera la thumbnail con un job separato prima dell'ottimizzazione.
- Loops non usa un fast-path copy-through: il prodotto e' video-centrico e
  normalizza ogni upload. La sua architettura richiede Redis/Horizon e non e'
  direttamente trasferibile all'obiettivo leggero di Openbook.

### Mastodon (sorgente ufficiale corrente, settembre 2026)

- Limite video: 99 MiB.
- Input dichiarati: WebM, MP4, M4V/MOV e Ogg video.
- Limiti: 8.294.400 pixel (equivalente a 3840x2160), 120 fps e 36.000 frame.
- Pass-through soltanto per codec video H.264 e audio AAC oppure assente.
- Conversione canonica: MP4, H.264/AAC a 192 kbps, `yuv420p`, preset
  `veryfast`, metadata rimossi e `faststart`; larghezza/altezza vengono rese
  pari per H.264.
- Modello media con stato di processing `queued / in_progress / complete /
  failed`. Il benchmark e' prezioso per compatibilita', ma 4K/120 fps e 99 MiB
  sono limiti massimi generosi, non necessariamente target adatti a Openbook.

### PeerTube (documentazione ufficiale corrente)

- Dopo l'upload crea job asincroni di ottimizzazione/transcodifica.
- Il file Web Video compatibile e' MP4 con codec x264/AAC e bitrate scelti in
  base alla risoluzione.
- Puo' generare risoluzioni multiple e HLS; la documentazione evidenzia
  esplicitamente costo CPU, tempo e moltiplicazione dello storage.
- Conferma MP4/H.264/AAC come minimo comune denominatore, ma il modello
  multi-risoluzione/HLS e' fuori scala per il supporto basico previsto.

### Sintesi provvisoria per Openbook

Il minimo comune interoperabile emerso e': MP4 + H.264 + AAC (o nessun audio),
pixel format `yuv420p`, dimensioni pari e metadata `faststart`. Come target
semplice e prudente si puo' valutare un massimo 1080p/30 o 60 fps; Loops sceglie
invece 720 px sul lato corto, adatto al video verticale ma potenzialmente
eccessivo per un social generalista. In questa fase i limiti definitivi erano
ancora da decidere.

Nota emersa dalla revisione: conformita' tecnica e dimensione sono condizioni
indipendenti. Un MP4 H.264/AAC molto grande e' riproducibile, ma puo' comunque
dover essere ricodificato per ridurre storage e banda. Servono pertanto:

1. un limite assoluto oltre il quale l'upload e' rifiutato;
2. una soglia di copy-through sotto la quale un file conforme e' immediato;
3. transcodifica per file non conformi oppure conformi ma sopra soglia.

Fonti consultate:

- https://github.com/joinloops/loops-server
- https://github.com/mastodon/mastodon/blob/main/app/models/media_attachment.rb
- https://docs.joinpeertube.org/contribute/architecture
- https://docs.joinpeertube.org/admin/configuration

## Vincoli da non perdere

- Feature opt-in amministrativa, `false` per default. Il form non deve proporre
  video e la validazione backend deve rifiutarli quando la capability e'
  disabilitata: nascondere soltanto l'opzione nella UI non e' sufficiente.
- FFmpeg/ffprobe non devono diventare dipendenze necessarie per installazioni
  che lasciano il supporto video disabilitato.
- L'attivazione deve verificare o almeno diagnosticare chiaramente la
  disponibilita' dei binari; un'istanza configurata male non deve lasciare
  lavori in coda senza spiegazione.
- Il caricamento del file resta necessariamente parte della richiesta HTTP,
  anche quando l'elaborazione viene differita; i limiti PHP/web server devono
  permettere la dimensione massima scelta.
- Un post in elaborazione non deve essere federato né apparire come pubblicato.
- Il payload ActivityPub deve essere costruito dopo la transcodifica, usando
  URL e MIME definitivi.
- Fallimento e retry della transcodifica richiedono uno stato esplicito e una
  UX comprensibile; non si deve lasciare indefinitamente un post fantasma.
- File sorgente, output parziali, cancellazione e rollback richiedono una
  politica di pulizia.
- Va preservato il percorso immediato per immagini, audio e video conformi.
- Modifica e cancellazione di un post in elaborazione devono avere un
  comportamento definito.
- La transcodifica usa esclusivamente un worker CLI dedicato e non viene
  inserita nel cron generale o nel relativo endpoint HTTP.

## Questioni aperte

1. Formati di input accettati e profilo di output canonico.
2. Limite assoluto di upload, soglia fast-path e limiti di durata/risoluzione.
3. Disponibilita' obbligatoria oppure opzionale di FFmpeg/ffprobe.
4. Modello degli stati: stato aggiuntivo su `posts`, stato su `media`, oppure
   outbox dedicata per i lavori di transcodifica.
5. Momento esatto degli effetti locali e federati della pubblicazione.
6. Modalita' di installazione e supervisione del worker su systemd/Supervisor.
7. Generazione di poster/thumbnail del video e sua serializzazione ActivityPub.
8. UX per post in elaborazione, completati e falliti.
9. Supporto iniziale ai commenti e alla modifica dei post: incluso subito o
   rinviato a uno sprint successivo.
10. Collocazione della feature flag: setting amministrativo persistente,
    variabile `.env`, o combinazione delle due. Ipotesi: setting runtime per
    abilitare la funzione e `.env` per percorsi/opzioni infrastrutturali di
    FFmpeg.

## Revisione architetturale

Non emergono errori che invalidino il disegno `post in staging -> media pronto
-> PostComposer -> pubblicazione`. La tabella separata protegge bene il dominio
pubblico dalle lavorazioni incomplete. Prima di implementare vanno pero'
chiusi o resi espliciti questi punti:

### Decisione operativa: worker video dedicato

Il cron web di Openbook ha un budget breve, mentre FFmpeg non offre una ripresa
semplice dal punto in cui viene interrotto. La transcodifica non verra' quindi
inserita in `openbook:cron` ne' nel relativo endpoint HTTP.

Si prevede un comando Artisan **always-on** dedicato, supervisionabile con
systemd/Supervisor, che:

- interroga la coda ogni 5 secondi quando non trova lavoro;
- elabora un solo video per volta per default, evitando di saturare CPU/RAM;
- dopo un lavoro interroga subito la coda, senza attesa artificiale;
- non viene mai avviato da una richiesta HTTP;
- supporta arresto graceful su SIGTERM/SIGINT tra un lavoro e il successivo;
- offre una modalita' `--once` utile per test e manutenzione;
- viene riavviato automaticamente dal process supervisor in caso di crash.

Il singolo processo riduce concorrenza e latenza ma non sostituisce i lock: una
configurazione duplicata o un restart sovrapposto puo' creare due worker. Sono
quindi comunque necessari un lock globale con lease e un claim atomico della
riga. La concorrenza resta uno nella prima versione.

Se la feature viene disabilitata mentre il worker e' attivo, il lavoro gia'
preso in carico termina in sicurezza e il worker non ne reclama altri. Se
FFmpeg/ffprobe non sono disponibili, il comando deve fallire subito con un
errore diagnostico, senza entrare in un loop ogni 5 secondi.

### Atomicita' e idempotenza

- Il claim deve impedire a due worker di elaborare lo stesso elemento.
- La finalizzazione deve registrare in modo persistente il `post_id` creato;
  un retry dopo la pubblicazione non deve generare un secondo post.
- Spostamenti/ridenominazioni dei file devono precedere o accompagnare la
  transazione DB con cleanup compensativo, perche' il filesystem non effettua
  rollback.
- I riferimenti salvati in staging vanno rivalidati al completamento: nel
  frattempo community, post citato, Group o account potrebbero essere stati
  eliminati/disabilitati.

### Configurazione

- La feature flag deve avere un unico valore effettivo, applicato sia alla UI
  sia alla validazione e al runner.
- La configurazione non deve permettere di attivare la transcodifica senza
  binari funzionanti, oppure deve mostrare chiaramente lo stato degradato.
- `upload_max_filesize`, `post_max_size` e limiti del reverse proxy restano
  esterni a Laravel: il pannello deve almeno ricordarlo all'amministratore.

### UX minima

- La risposta al submit deve distinguere "pubblicato" da "in elaborazione".
- Deve esistere almeno una pagina/elenco dove l'autore vede i lavori pendenti
  o falliti; altrimenti una failure equivale a perdere apparentemente il post.
- Va prevista la cancellazione di un elemento in attesa, con pulizia dei file.

## Action list proposta

### 1. Contratto, configurazione e capability check

- Chiudere profilo input/output, limiti assoluti, soglia copy-through, durata,
  risoluzione e frame rate.
- Definire contratto CLI, lock e documentazione operativa del worker always-on
  dedicato, con polling predefinito ogni 5 secondi e modalita' `--once`.
- Aggiungere feature flag amministrativa `false` per default e configurazione
  `.env` dei binari/parametri infrastrutturali.
- Introdurre un servizio di capability/preflight che verifichi FFmpeg e
  ffprobe solo quando la funzione e' attiva.
- Esporre nel pannello admin stato della capability e promemoria sui limiti
  PHP/proxy.

Criterio di uscita: con flag spento Openbook e i test si comportano esattamente
come prima; con flag acceso una configurazione incompleta viene diagnosticata.

### 2. Staging persistente, upload e probe

- Creare `post_publication_queue` (nome definitivo da scegliere) e il modello
  degli allegati temporanei; supportare piu' file e allegati misti.
- Salvare in sicurezza lo snapshot del composer e i sorgenti senza creare una
  riga in `posts`.
- Eseguire validazione assoluta e probe tecnico controllato; classificare il
  lavoro come copy-through o transcode.
- Implementare claim, stati, errore, tentativi, cleanup e idempotency key.

Criterio di uscita: un input video valido e' persistito in staging, e un input
non valido viene rifiutato/pulito senza post o file orfani.

### 3. Preparazione media e finalizzazione della pubblicazione

- Implementare copy-through conforme e transcodifica canonica FFmpeg.
- Generare una thumbnail/poster e registrarla come `media_variant` se deciso.
- Adattare `MediaUploader`/`PostComposer` per accettare media gia' preparati,
  senza duplicare il flusso di pubblicazione.
- Finalizzare una sola volta tramite il composer esistente; rivalidare autore
  e riferimenti, registrare `post_id`, quindi pulire sorgenti e output parziali.
- Verificare serializzazione ActivityPub di video e poster.

Criterio di uscita: sia fast-path sia slow-path producono lo stesso post
pubblicato e una sola attivita' federata con URL/MIME definitivi.

### 4. Worker asincrono e recovery

- Implementare il comando Artisan always-on definito nello sprint 1, polling
  configurabile (default 5 secondi), modalita' `--once` e arresto graceful.
- Garantire una sola transcodifica globale tramite lock con lease e claim
  atomico, anche se vengono avviati accidentalmente piu' processi.
- Gestire timeout, retry limitati e failure definitiva senza interrompere il
  loop per un singolo file corrotto.
- Non integrare il comando in `openbook:cron` o nel suo endpoint HTTP.
- Aggiungere recupero dei claim scaduti e purge periodico dei lavori/file
  abbandonati.
- Documentare esempi di unita' systemd e configurazione Supervisor con restart
  automatico, timeout di stop sufficiente e un solo processo.

Criterio di uscita: esecuzioni concorrenti o interrotte non duplicano post e
non lasciano la coda bloccata indefinitamente.

### 5. Composer e UX utente

- Abilitare `accept` video e messaggi del composer solo quando la feature e'
  attiva; mantenere la validazione backend autorevole.
- Mostrare esito immediato "pubblicato" oppure "video in elaborazione".
- Aggiungere elenco/stato minimo dei post pendenti, falliti e cancellabili.
- Decidere esplicitamente se la prima release include modifica post e commenti
  con video; in caso contrario rifiutarli chiaramente.
- Completare test end-to-end con flag spento/acceso, allegati misti, failure,
  retry, cancellazione e output ActivityPub.

Criterio di uscita: l'utente non vede controlli video quando disabilitati e
puo' seguire senza ambiguita' il ciclo di vita quando abilitati.

## Stato di implementazione

### Sprint 1 — completato localmente

- Feature flag `video_enabled` in `system_settings`, default `false`.
- Percorsi `video_ffmpeg_path` e `video_ffprobe_path` configurabili in
  `system_settings`; default rispettivamente `ffmpeg` e `ffprobe` risolti dal
  `PATH` del processo PHP.
- Profilo iniziale configurabile dal pannello:
  - upload massimo 40 MiB;
  - copy-through massimo 8 MiB;
  - durata massima 180 secondi;
  - lato lungo massimo 1080 px;
  - frame rate massimo 60 fps.
- Servizio `VideoCapability` basato su Symfony Process: esegue direttamente
  `<binario> -version` con timeout 5 secondi, senza shell, e verifica che le
  risposte appartengano davvero a FFmpeg/ffprobe.
- La diagnostica viene eseguita al salvataggio solo quando si tenta di
  abilitare la feature; a feature spenta i binari non vengono eseguiti.
- Se la feature e' gia' attiva, il pannello mostra versioni oppure errore della
  capability corrente.
- L'attivazione con strumenti indisponibili viene rifiutata senza modificare
  le impostazioni.
- Nessuna migration necessaria: `system_settings` e' gia' un archivio KV.
- Lo sprint non abilita ancora MIME video nel composer e non introduce code o
  transcodifiche.

### Sprint 2 — completato localmente

- Aggiunte le tabelle `post_publication_queue` e
  `post_publication_queue_attachments`, compatibili con SQLite/MySQL.
- La testata conserva autore, snapshot del composer, stato, tentativi, claim,
  errore e `post_id` finale per l'idempotenza.
- Gli allegati conservano posizione e alt text, percorso privato e i metadati
  tecnici utili al worker in colonne separate.
- `PostPublicationStager` salva tutti gli allegati di un post misto nel disco
  privato `local`, con directory e nomi casuali, senza creare righe in `posts`.
- Un errore durante copia, validazione o probe causa rollback DB e rimozione
  compensativa dell'intera directory temporanea.
- `VideoProbe` usa il binario ffprobe configurato, senza shell, con timeout di
  15 secondi; richiede un vero stream video, durata, dimensioni e frame rate.
- Gli MP4 sono accettati anche quando `fileinfo` restituisce il MIME standard
  `application/mp4`; il probe obbligatorio impedisce di scambiarli per file
  non video.
- I video oltre la durata o dimensione upload massima vengono rifiutati; gli
  altri vengono classificati `copy` solo se MP4/H.264/AAC-or-no-audio,
  yuv420p, dimensioni pari, entro risoluzione/fps e soglia byte configurate.
  Tutti gli altri input video ammessi vengono classificati `transcode`.
- La coda non e' ancora collegata al composer HTTP: tale integrazione resta
  nello sprint UX, dopo che finalizzatore e worker saranno disponibili.

### Sprint 3 — completato localmente

- `VideoPreparer` conserva direttamente gli MP4 gia' conformi oppure produce
  un MP4 H.264/AAC, yuv420p, faststart, entro lato lungo e fps configurati.
  MOV e altri container vengono sempre convertiti anche quando ffprobe li
  descrive usando la famiglia condivisa `mov,mp4,...`.
- La transcodifica usa Symfony Process senza shell, timeout applicativo e
  output ancora privato; output parziali e poster vengono rimossi in caso di
  errore.
- Ogni video riceve un poster JPEG, importato come variante `thumbnail` e
  usato dall'elemento `<video>` locale tramite attributo `poster`.
- `MediaUploader` sa importare media privati preparati. Immagini e audio
  ripassano dal percorso storico, mantenendo ricodifica EXIF/thumbnail e le
  validazioni esistenti; i video vengono copiati in streaming sul disco
  pubblico con MIME definitivo `video/mp4`.
- `PostComposer` accetta anche una lista interna di `PreparedMedia` e continua
  a essere l'unico responsabile di post, allegati, hashtag, menzioni,
  notifiche, community e federazione.
- `PendingPostFinalizer` rivalida l'autore, prepara tutti gli allegati e crea
  post + collegamento idempotente `post_id` in un'unica transazione. Solo dopo
  il commit elimina la directory privata di staging.
- Gli allegati ActivityPub video includono il poster come `icon` Image, senza
  sostituire il video originale; la logica inbox gia' esistente lo tratta
  come anteprima del media audiovisivo.

### Sprint 4 — completato localmente

- Comando CLI dedicato `openbook:process-videos`, volutamente assente dal cron
  generale e da qualunque route HTTP.
- Senza opzioni resta attivo e interroga la coda ogni 5 secondi; `--poll=N`
  modifica l'attesa e `--once` acquisisce al massimo un elemento e termina.
- All'avvio verifica feature flag e capability FFmpeg/ffprobe. Se la funzione
  e' disabilitata termina senza reclamare righe; se i binari non funzionano
  fallisce subito con diagnostica.
- Un cache lock breve serializza soltanto il claim. La transcodifica avviene
  fuori dal lock, quindi piu' copie possono lavorare in parallelo su righe
  diverse senza duplicarle.
- Ogni claim usa token UUID, timestamp e incremento tentativo in transazione;
  il finalizzatore ricontrolla il token sotto row lock prima di pubblicare.
- Claim scaduti tornano acquisibili; la lease non puo' essere inferiore al
  timeout FFmpeg piu' 60 secondi. Dopo tre fallimenti il lavoro resta `failed`.
- SIGTERM/SIGINT richiedono l'arresto graceful: il lavoro corrente termina e
  il processo esce prima di acquisirne un altro.
- Per ogni lavoro l'output riporta i secondi occupati da preparazione, poster e
  pubblicazione, indicando esplicitamente se e' avvenuta una transcodifica;
  anche gli errori riportano il tempo trascorso prima del fallimento.

### Sprint 5 — completato localmente

- Il composer calcola `accept` dalla configurazione: i MIME video compaiono
  soltanto per nuovi post e con feature attiva, mai per commenti o modifiche.
- La validazione HTTP usa la stessa feature flag e applica separatamente il
  limite video e quello storico per immagini/audio.
- Un submit che contiene almeno un video viene inviato allo staging e torna
  alla home con stato “in elaborazione”; senza video usa invariato il normale
  `PostComposer` sincrono.
- La home elenca i lavori dell'autore `pending`, `processing` e `failed`.
- L'autore puo' cancellare in sicurezza lavori pending/failed con cleanup dei
  file; un row lock impedisce la corsa col claim del worker. Un lavoro gia'
  processing non e' cancellabile fino al termine.
