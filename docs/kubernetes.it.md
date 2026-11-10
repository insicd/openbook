> [English](kubernetes.md) · [Tutte le guide](README.it.md)

# Kubernetes con Helm

Il chart opzionale si trova in `helm/openbook`. Usa l'immagine pubblicata
`skeyby/openbook:26.38.rc1` e un database MySQL/MariaDB esterno. La distribuzione
è essenziale, per una sola istanza: un Pod contiene container distinti per web,
cron, inbox e delivery, più video opzionale. Condividono un volume persistente.
Gli aggiornamenti sostituiscono l'intero Pod, con un'interruzione del servizio.
Anche il riavvio di un worker può influire temporaneamente sulla disponibilità
dell'intero Pod.

**Il chart è stato controllato localmente con lint, rendering e validazione
degli schemi Kubernetes. Non è stato distribuito o provato su un cluster.** Parti da un namespace di prova
e un database sacrificabile prima di valutarlo per la produzione.

## Requisiti e configurazione

Servono un cluster, Helm, kubectl, storage persistente con provisioner o claim
esistente, database/utente con permessi di modifica dello schema e HTTPS tramite
un controller Ingress esistente o proxy esterno. Il chart non installa questi
servizi. L'immagine usa root all'avvio di Apache e UID 33 per lo storage scrivibile:
le policy del cluster devono consentire questo comportamento dell'immagine.

Crea un namespace dell'istanza, ad esempio `openbook`, e al suo interno un Secret
`openbook-secrets` con le chiavi `APP_KEY` e `DB_PASSWORD`. Genera APP_KEY una
sola volta, oppure conserva quella esistente quando ripristini un'istanza.
Deve restare stabile negli aggiornamenti. Su una nuova istanza, se hai OpenSSL:

```sh
umask 077
printf 'base64:%s' "$(openssl rand -base64 32)" > /secure/path/app-key
```

Usando file protetti con chiave e password database effettive:

```sh
kubectl create namespace openbook
kubectl -n openbook create secret generic openbook-secrets \
  --from-file=APP_KEY=/secure/path/app-key \
  --from-file=DB_PASSWORD=/secure/path/db-password
```

Non inserire password o APP_KEY nei valori Helm. Puoi aggiungere allo stesso
Secret altri parametri segreti, ad esempio `MAIL_PASSWORD`. I file devono
contenere il valore esatto, senza un a capo finale: `--from-file` lo conserva
nella password e può impedire la connessione. Usa le chiavi dirette `APP_KEY` e
`DB_PASSWORD`, non le varianti Docker `_FILE`.

Crea un file locale `instance-values.yaml` fuori dal repository:

```yaml
existingSecret: openbook-secrets
env:
  APP_URL: https://social.example.org
  OPENBOOK_DOMAIN: social.example.org
  DB_HOST: mysql.example.org
  DB_PORT: "3306"
  DB_DATABASE: openbook
  DB_USERNAME: openbook
persistence:
  size: 10Gi
  # storageClass: your-storage-class
  # existingClaim: your-existing-claim
```

Lo storage è condiviso all'interno di un solo Pod: basta un normale claim
ReadWriteOnce, non serve ReadWriteMany. Imposta la classe o il claim effettivo del
cluster. Mantieni stabili nome della release e namespace; per istanze indipendenti
usa segreti, storage e database distinti.

SMTP, registrazioni, impostazioni, limiti upload e backup seguono la
[guida amministrativa Docker](docker.it.md). Aggiungi a `env` i parametri non
segreti e al Secret quelli sensibili. Dopo aver modificato il contenuto del Secret
riavvia il Pod (`kubectl rollout restart deployment/openbook-openbook -n openbook`).
Non cambiare APP_KEY nei normali aggiornamenti di configurazione.

## Installazione e completamento

Dopo aver verificato i valori in un ambiente di prova:

```sh
helm upgrade --install openbook ./helm/openbook -n openbook \
  -f /path/to/instance-values.yaml --wait --timeout 30m
kubectl -n openbook logs deployment/openbook-openbook -c prepare
```

Un init container applica le migrazioni e svuota le viste prima degli altri
container. L'entrypoint web esegue anche la preparazione normale quando si riavvia
il web. Se la preparazione fallisce, il Pod non diventa pronto: controlla eventi
e log prepare/web. Le sonde non controllano continuamente elaborazione delle
code e disponibilità del database.

Su un'istanza nuova crea l'amministratore, poi segna l'installazione completata
soltanto dopo il successo della creazione:

```sh
kubectl -n openbook exec -it deployment/openbook-openbook -c web -- openbook-entrypoint php artisan openbook:make-admin
kubectl -n openbook exec deployment/openbook-openbook -c web -- openbook-entrypoint php artisan tinker --execute='App\Infrastructure\Installation\InstallationLock::lock((string) config("openbook.version"));'
```

L'entrypoint in questi comandi esegue la CLI con l'utente web. Non vengono
generati automaticamente amministratori predefiniti o nuove APP_KEY.

## HTTPS e video opzionale

Il Service è interno (`ClusterIP`, porta 80). Per usare un controller Ingress
e un Secret TLS già disponibili aggiungi ai valori:

```yaml
ingress:
  enabled: true
  className: your-ingress-class
  tlsSecretName: openbook-tls
  annotations: {}
```

L'hostname deriva da `env.OPENBOOK_DOMAIN`. Prepara il Secret del certificato
nello stesso namespace e configura DNS e limiti upload/tempo per il tuo controller.
L'immagine non considera automaticamente fidati gli header con l'IP originale;
la configurazione di fiducia dei proxy nel backend è fuori da questo chart
minimale. Fino ad allora limiti basati su IP e log vedono l'indirizzo del proxy. Se Ingress è disabilitato usa un proxy HTTPS
esterno già presente o un tuo Ingress configurato separatamente.

Per i video abilitali prima nella UI amministrativa, poi imposta
`video.enabled: true` e applica l'upgrade Helm. Quando li disabiliti nella UI,
riporta anche il valore del chart a false per evitare riavvii ripetuti del worker.

## Aggiornamenti e conservazione dei dati

Salva database, intero volume, chiave applicativa e configurazione. Imposta in
`image.tag` la nuova versione pubblicata e ripeti `helm upgrade --install` come
sopra. Il Deployment ha una replica e strategia `Recreate`: i vecchi container si
fermano prima che il nuovo Pod applichi le migrazioni. Prevedi un'interruzione e
fino a venti minuti perché termini un job video attivo. Con questo chart non
aumentare le repliche e non passare agli aggiornamenti progressivi.

Il PVC creato dal chart viene conservato da `helm uninstall`; un claim esistente
si gestisce separatamente. La disinstallazione non cancella database esterno o
Secret. Prima di reinstallare controlla esplicitamente i volumi conservati e
riusali consapevolmente, ad esempio tramite `persistence.existingClaim`: conservarli non sostituisce il backup. Tornare alla
vecchia immagine non annulla le migrazioni; quando serve ripristina backup coerenti
di database e storage.

Per validazione locale e sviluppo del chart vedi
[la guida sviluppatori](../development/helm.it.md).
