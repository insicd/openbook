# Creare e pubblicare l'immagine Docker

> [English](docker.md) · [Documentazione sviluppatori](README.it.md)

Questa guida è per sviluppatori e maintainer Openbook. Chi amministra un'istanza
può seguire [Installare Openbook da Docker Hub](../docs/docker.it.md). L'immagine
contiene dipendenze di produzione: il Compose locale serve a provare quella
distribuzione, non è un server di sviluppo con ricaricamento dei sorgenti.

## File e stadi della build

- `Dockerfile`: base ufficiale `php:8.5-apache-trixie`, con stadi `base`, `build`
  e `production`. Le estensioni vengono compilate una volta nella base condivisa.
  Lo stadio di build installa le dipendenze di produzione da `composer.lock` e
  ottimizza la classmap applicativa. Solo la sua directory vendor passa in produzione.
- `.dockerignore`: elenco dei sorgenti ammessi. Esclude `.env` locale, storage,
  file database, vendor di sviluppo, test e cache bootstrap generate. Prima di
  ampliarlo controlla che non includa dati dell'istanza o segreti.
- `docker/`: configurazione Apache/PHP, entrypoint, preparazione automatica del
  database e runner periodico. Composer/Git/unzip e header GD non entrano
  nell'immagine finale; la base PHP ufficiale conserva i propri strumenti di sviluppo.
- `compose.local.yaml`: build locale autonoma di `openbook:local`, senza pull di
  quel tag applicativo. `build --pull` aggiorna comunque le basi PHP/Composer.
- `compose.yaml`: distribuzione autonoma da registry, senza checkout o build di
  ripiego. `OPENBOOK_IMAGE` consente di sostituire il riferimento nei test dell'immagine.

I due Compose hanno gli stessi servizi, configurazione esterna e volume. Non
sovrapporli. Mantieni progetto e directory quando passi un'istanza da una modalità
all'altra; per i test usa progetto, database e storage separati.

## Build e avvio locale

Usa un checkout pulito della release e Docker con Compose v2. Leggi configurazione,
HTTPS e backup nella guida amministrativa. Per un'istanza di prova isolata:

```sh
cp docker/openbook.env.example docker/openbook.env
chmod 600 docker/openbook.env
docker compose -f compose.local.yaml build --pull web
```

Compila il file env con URL/dominio e un database MySQL/MariaDB dedicato e
sacrificabile. Genera una chiave una sola volta e inserisci l'output in `APP_KEY`:

```sh
docker compose -f compose.local.yaml run --rm --no-deps --entrypoint php web -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
docker compose -f compose.local.yaml up -d --wait web
docker compose -f compose.local.yaml run --rm --no-deps web php artisan openbook:make-admin
docker compose -f compose.local.yaml run --rm --no-deps web php artisan tinker --execute='App\Infrastructure\Installation\InstallationLock::lock((string) config("openbook.version"));'
docker compose -f compose.local.yaml up -d cron inbox delivery
```

Segna l'installazione completata solo dopo aver creato correttamente l'amministratore.
Abilita i video nella UI prima di `docker compose -f compose.local.yaml --profile video up -d video`.
La porta HTTP resta locale; la modalità produzione richiede un reverse proxy HTTPS.
Per aggiornare, ferma tutti i servizi, ricostruisci `web`, avvialo con `--wait`,
poi avvia i worker. Non lasciare vecchi worker attivi durante le migrazioni.

## Avvio e comportamento delle code

Soltanto l'entrypoint `apache2-foreground` esegue automaticamente le migrazioni.
Avvia `docker/prepare.php` come UID 33, acquisisce un lock esclusivo su file nello
storage condiviso, chiama `migrate --force --no-interaction`, poi `view:clear`.
Un errore impedisce l'avvio di Apache. Il lock serializza la preparazione web,
non coordina migrazioni multi-host. Non vengono generati APP_KEY, amministratori
o dati dimostrativi. I comandi CLI non avviano implicitamente migrazioni.

Il controllo web diventa disponibile dopo la preparazione. Compose subordina
cron, inbox, delivery e video a `service_healthy`. È soltanto un ordine di avvio:
non ferma worker già attivi quando il web si riavvia. Gli aggiornamenti devono
fermare prima tutti i servizi. L'immagine usa SIGTERM per CLI; il servizio web
seleziona SIGWINCH per l'arresto graduale di Apache. Durante la preparazione,
i segnali di arresto vengono inoltrati al processo che la esegue.

`openbook:cron` conserva il consumo delle code esistente. I worker permanenti
usano `queue:work --queue=inbox --sleep=1` e
`queue:work --queue=delivery --sleep=1`, una coda per servizio.
Con i default database, consegne ordinarie e transazionali condividono la tabella
dei job. Se cambi backend, consuma separatamente anche `local_delivery`, oltre
al backend predefinito: non presumere che il worker delivery predefinito legga
i job transazionali da una connessione diversa.

Non incorporare cache di configurazione nell'immagine. Configurazioni e script
di avvio devono restare nello stadio finale, senza invalidare l'installazione
Composer quando cambiano. La preparazione che dipende dal database deve
avvenire all'avvio, non durante la build.

## Verifiche prima della pubblicazione

Costruisci l'immagine ed esegui i controlli di regressione, con la sola libreria
standard Python 3:

```sh
docker compose -f compose.local.yaml build web
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/Docker -v
```

I controlli creano e rimuovono container temporanei, con un file SQLite isolato
per le prove di migrazione e riavvio. Verificano equivalenza dei Compose e ordine
di avvio, mancato avvio web se una migrazione fallisce, migrazioni pendenti e
pulizia viste al riavvio, assenza di dati locali nell'immagine, UID CLI, blocco
wizard anche tramite front controller, upload pubblici e blocco dell'esecuzione
PHP negli upload. Non usano mai il database di sviluppo.

Prova anche un'istanza completa con MySQL/MariaDB sacrificabile: primo avvio web
e migrazioni, amministratore, stato di installazione persistente, ambiente e
segreti su file, consumo inbox/delivery incluso `local_delivery`, funzionalità
video, arresti puliti e sostituzione/riavvio conservando lo storage. Verifica che
tutti i container usino la stessa immagine. La suite PHPUnit completa resta
richiesta prima di proporre una PR: segui [Test](testing.it.md), inclusa la
protezione dalla configurazione in cache del database di sviluppo.

Per provare il Compose del registry puoi impostare `OPENBOOK_IMAGE=openbook:local`
e usare `compose.yaml` da una directory temporanea contenente soltanto quel file
e la configurazione esterna. Verifica così che non servano i sorgenti.

## Pubblicazione

Il repository è `docker.io/skeyby/openbook`. Usa come tag immutabile la versione
dichiarata in `config/openbook.php`. Prima della pubblicazione rivedi il diff
completo, esegui le verifiche, crea il commit dei sorgenti previsti e controlla
che la directory di lavoro sia pulita. Verifica se il tag esiste già e non
sovrascrivere silenziosamente una release pubblicata. Tieni le release candidate
separate da un eventuale alias stabile `latest`.

Dopo il login locale su Docker Hub, per una sola piattaforma:

```sh
docker build --pull --build-arg VCS_REF="$(git rev-parse HEAD)" -t docker.io/skeyby/openbook:YOUR_VERSION .
docker push docker.io/skeyby/openbook:YOUR_VERSION
```

L'immagine include etichette OCI per sorgente, licenza e revisione. Registra il
digest del registry e quello della base risolta. Una build locale verifica
soltanto la propria architettura: prova ogni architettura prima di pubblicare
un manifest multipiattaforma. Con buildx e un builder appropriato:

```sh
docker buildx build --platform linux/amd64,linux/arm64 \
  --build-arg VCS_REF="$(git rev-parse HEAD)" \
  -t docker.io/skeyby/openbook:YOUR_VERSION --push .
docker buildx imagetools inspect docker.io/skeyby/openbook:YOUR_VERSION
```

Ricostruisci per aggiornare la sicurezza della base, usando un nuovo tag
documentato; conserva APP_KEY, storage e DB nelle distribuzioni successive.
Per altri registry OCI cambia l'hostname. Vedi [build Docker a più stadi](https://docs.docker.com/build/building/multi-stage/).
