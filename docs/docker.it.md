> Documentazione: [English](docker.md) · [Tutte le guide](README.it.md)

# Installare Openbook da Docker Hub

Questa guida è per chi amministra un'istanza usando l'immagine Openbook già pronta.
Sull'host non servono sorgenti, PHP o Composer. Docker resta opzionale; per lo
shared hosting vedi [Installazione](install.it.md).

Il repository delle immagini è `skeyby/openbook` su Docker Hub. Creazione e
pubblicazione sono nella [guida per sviluppatori](../development/docker.it.md).

## Requisiti e file

Servono Docker con Compose v2, un database MySQL/MariaDB e relativo utente già
creati, un dominio pubblico stabile e un reverse proxy HTTPS. L'utente del database
deve poter applicare le modifiche allo schema. L'esempio non crea un server database.

Copia `compose.yaml` e `docker/openbook.env.example` dalla stessa release Openbook
in una directory di installazione, mantenendo la sottodirectory `docker/`.
Mantieni stabili directory e nome del progetto Compose: identificano container
e volume dell'istanza. Per istanze indipendenti usa progetti e database distinti.

In `compose.yaml` controlla il riferimento all'immagine vicino all'inizio del file
e seleziona la versione pubblicata che vuoi usare, per esempio:

```yaml
image: docker.io/skeyby/openbook:26.38.rc1
```

Tutti i servizi usano quella stessa immagine. Salva il riferimento effettivo nel
file, così i comandi successivi e i nuovi terminali useranno la stessa versione.
Poi:

```sh
cp docker/openbook.env.example docker/openbook.env
chmod 600 docker/openbook.env
docker compose -f compose.yaml --profile video pull
```

I comandi seguenti selezionano esplicitamente `compose.yaml`: eseguili dalla
directory di installazione.

## Configurazione dell'istanza

Compila `docker/openbook.env` prima di avviare i servizi:

| Variabile | Significato |
| --- | --- |
| `APP_URL` | URL HTTPS pubblico canonico, es. `https://social.example.org` |
| `OPENBOOK_DOMAIN` | Dominio federato stabile, uguale all'host dell'URL |
| `APP_KEY` | Chiave di cifratura applicativa, da generare una volta e conservare |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Connessione al database esistente |
| `DB_PORT` | Opzionale; default `3306` |

Genera la chiave **una sola volta**, poi copia tutto l'output in `APP_KEY`:

```sh
docker compose -f compose.yaml run --rm --no-deps --entrypoint php web -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Non cambiarla a ogni riavvio o aggiornamento: impostazioni cifrate e accessi OAuth
dipendono da quella chiave. Salvala nel backup con il database. Mantieni stabile
il dominio federato. `DB_HOST=localhost` indica il container stesso: usa un host
database raggiungibile da Docker. Nel file env racchiudi tra apici singoli le
password con `$`, `#` o spazi, ad esempio `DB_PASSWORD='example$with#symbols'`.

I default includono produzione, debug spento, UTC, cookie Secure e log nei log
del container. Non serve un ulteriore `.env` dentro il container. Nome istanza,
regole, registrazioni, limiti media, video e object storage si gestiscono dalle
pagine amministrative esistenti e si salvano nel database. `APP_LOCALE=it` è
opzionale; la lingua predefinita è l'inglese.

Le registrazioni partono **chiuse**. `MAIL_MAILER=log`, predefinito, **non invia
email**. Prima di aprirle configura SMTP tramite `MAIL_MAILER=smtp`, `MAIL_HOST`,
`MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` e `MAIL_FROM_NAME`
secondo il provider. `MAIL_SCHEME=smtps` seleziona TLS implicito. Attualmente SMTP
si configura tramite ambiente, non dalla UI. Dopo aver modificato il file env,
ricrea i servizi per applicare i valori, incluso video se abilitato.

L'immagine accetta singoli upload fino a 128 MiB e POST fino a 136 MiB. I limiti
impostati nella UI possono essere inferiori. Per aumentare quelli PHP, monta un
`.ini` aggiuntivo in `/usr/local/etc/php/conf.d/` e allinea anche proxy e UI.

### Segreti su file opzionali

Puoi usare `APP_KEY_FILE` e `DB_PASSWORD_FILE` al posto dei valori diretti.
Rimuovi `APP_KEY`/`DB_PASSWORD` dal file env quando usi i file: fornire entrambe
le forme genera un errore. Monta gli stessi segreti in tutti i servizi.
Per esempio, integra queste opzioni in `compose.yaml`:

```yaml
services:
  web:
    environment:
      APP_KEY_FILE: /run/secrets/app_key
      DB_PASSWORD_FILE: /run/secrets/db_password
    secrets: [app_key, db_password]
  # Aggiungi gli stessi environment e secrets anche a cron, inbox, delivery e video.
secrets:
  app_key:
    file: ./docker/secrets/app_key
  db_password:
    file: ./docker/secrets/db_password
```

Proteggi i file ed escludili dal controllo versione. I file secrets di Compose
sono file montati, non un archivio cifrato. Vedi [segreti Compose](https://docs.docker.com/compose/how-tos/use-secrets/).

## Primo avvio

Avvia il servizio web e attendi che sia pronto:

```sh
docker compose -f compose.yaml up -d --wait web
```

**Ogni avvio del container web applica automaticamente le migrazioni pendenti**,
poi svuota le viste compilate e avvia Apache. Le migrazioni già eseguite non
vengono ripetute. Se una delle operazioni fallisce, Apache resta fermo; la
politica di riavvio ritenta l'avvio e gli errori si consultano con:

```sh
docker compose -f compose.yaml logs -f web
```

Su un'istanza nuova crea il primo amministratore interattivamente e segna
l'installazione completata **solo dopo il successo del comando amministratore**:

```sh
docker compose -f compose.yaml run --rm --no-deps web php artisan openbook:make-admin
docker compose -f compose.yaml run --rm --no-deps web php artisan tinker --execute='App\Infrastructure\Installation\InstallationLock::lock((string) config("openbook.version"));'
docker compose -f compose.yaml up -d cron inbox delivery
```

La password viene richiesta senza mostrarla: evita di passarla sulla riga di
comando. Non vengono creati utenti dimostrativi. Il wizard web è disabilitato
in questa immagine: completa la procedura sopra prima di usare il sito.
L'immagine non crea automaticamente amministratori e non cambia la chiave applicativa.

## HTTPS e storage

Il servizio web espone HTTP soltanto su `127.0.0.1:8080` dell'host. Configura il
reverse proxy HTTPS per inoltrare il dominio pubblico a quell'indirizzo,
conservando l'Host pubblico. Un proxy in un altro container richiede una rete
condivisa e può collegarsi a `web:80`. Configura certificati e limiti upload/tempo
nel proxy prima di aprire l'istanza agli utenti. L'immagine non considera
attendibili automaticamente gli header con l'IP originale: configura
esplicitamente gli indirizzi dei proxy fidati se serve l'IP del client per
limiti di frequenza e log.

Il volume `openbook-storage` conserva upload, file privati, sessioni, cache,
stato dell'installazione e staging video quando sostituisci l'immagine. Salva
l'intero volume, database, `docker/openbook.env`, file Compose e segreti come
backup coerente dell'istanza. **Non usare `docker compose down -v` durante gli
aggiornamenti**: elimina il volume persistente.

Il volume predefinito viene inizializzato automaticamente. Eventuali bind mount
esistenti devono consentire lettura/scrittura all'UID 33 (`www-data`). La
configurazione è per una singola istanza su un host: non fornisce storage
multi-host o aggiornamenti progressivi senza interruzione.

## Servizi in background e video

| Servizio | Funzione |
| --- | --- |
| `web` | Applica le migrazioni, poi serve il sito tramite Apache |
| `cron` | Esegue attività periodiche di federazione, sondaggi, push, feed e manutenzione |
| `inbox` | Elabora continuamente il lavoro federato in ingresso |
| `delivery` | Elabora continuamente il lavoro federato in uscita |
| `video` | Worker video permanente opzionale |

I servizi in background attendono il controllo di disponibilità del web prima
di avviarsi, così non partono mentre il database è ancora in migrazione. È un
ordine di avvio: non arresta worker già attivi se il web smette di funzionare.
Avvia un solo cron per istanza, senza duplicarlo con cron dell'host o web cron.
Mantieni le impostazioni predefinite delle code fornite dall'immagine.
Inbox/delivery vengono elaborate dai worker permanenti; anche il comando
periodico esistente elabora quelle code. Vedi [attività periodiche](configuration.it.md#cron-e-attivita-periodiche)
per pianificazioni aggiuntive come la retention dei post remoti.

Per i video abilitali prima nella UI amministrativa. FFmpeg e ffprobe sono già
installati e i percorsi possono restare predefiniti. Poi:

```sh
docker compose -f compose.yaml --profile video up -d video
docker compose -f compose.yaml logs -f cron inbox delivery video
```

I servizi si riavviano automaticamente salvo arresto esplicito. Inbox/delivery
e cron hanno due minuti per terminare; il worker video ha venti minuti. Se
disabiliti i video nella UI, esegui anche `docker compose -f compose.yaml stop video`
per evitare riavvii ripetuti di un worker inattivo.

## Aggiornamenti

Salva prima un backup dell'istanza. **Ferma tutti i servizi**, incluso video,
prima di cambiare in `compose.yaml` il riferimento all'immagine con quello
della nuova versione pubblicata:

```sh
docker compose -f compose.yaml --profile video stop
```

Dopo aver salvato il nuovo riferimento:

```sh
docker compose -f compose.yaml --profile video pull
docker compose -f compose.yaml up -d --wait web
docker compose -f compose.yaml up -d cron inbox delivery
# Solo se i video erano abilitati:
# docker compose -f compose.yaml --profile video up -d video
```

Non serve un comando separato per le migrazioni: l'avvio web le applica e
svuota le viste persistenti prima di avviare i servizi in background. Controlla
i log web se l'avvio fallisce. Alcune migrazioni su database grandi possono
richiedere tempo. Non aggiornare sostituendo o riavviando soltanto il web mentre
i vecchi worker sono ancora attivi. Tutti i servizi devono usare la stessa versione.

In Docker aggiorna sostituendo l'immagine; non usare l'aggiornamento tramite
archivio dalla UI. Tornare alla vecchia immagine non annulla le migrazioni:
un rollback può richiedere il ripristino di database e storage dal backup corrispondente.

Il controllo `/up` conferma che l'applicazione web risponde dopo l'avvio; non
verifica continuamente disponibilità del database, cron o elaborazione video.
