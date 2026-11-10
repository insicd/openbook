# Verificare il chart Helm

> [English](helm.md) · [Documentazione sviluppatori](README.it.md)

Il chart essenziale è `helm/openbook`; le istruzioni amministrative sono in
[Kubernetes con Helm](../docs/kubernetes.it.md). Riusa l'immagine Docker pubblicata,
senza ricostruirla. Web e worker condividono Pod e PVC, con una replica, `Recreate`
e un init container per preparare il database. Mantieni semplice questa struttura:
niente sottochart database, Redis, operatori o autoscaling.

Per questi controlli non serve un cluster Kubernetes:

```sh
helm lint helm/openbook --strict
helm template openbook helm/openbook > /tmp/openbook.yaml
helm template openbook helm/openbook \
  --set video.enabled=true --set ingress.enabled=true \
  --set ingress.className=nginx --set persistence.existingClaim=test-storage \
  > /tmp/openbook-options.yaml
```

Per controllare gli schemi API Kubernetes, se hai kubeconform (senza cluster;
gli schemi vengono scaricati dal repository pubblico del validatore):

```sh
kubeconform -strict -summary -kubernetes-version 1.27.16 /tmp/openbook.yaml /tmp/openbook-options.yaml
```

Rivedi nomi e selettori delle risorse, immagine comune, riferimenti a ConfigMap
e Secret, mount, ordine di inizializzazione e conservazione del PVC. Se il claim
esiste già non deve essere creato un PVC. I valori dei segreti non devono apparire
nel rendering. Configurazioni richieste vuote e APP_KEY/DB_PASSWORD/MAIL_PASSWORD messi in `env`
devono fallire, per esempio:

```sh
helm template openbook helm/openbook --set existingSecret=
helm template openbook helm/openbook --set env.APP_KEY=do-not-store-in-values
```

Il chart è stato verificato con Helm 4.3.1 e kubeconform 0.8.0 contro gli schemi
Kubernetes 1.27.16, oltre a controlli strutturali dei manifest. Questo
non verifica provisionamento storage, ammissione Pod, permessi runtime, Ingress o
comportamento su un cluster reale. Prima di dichiarare supporto runtime serve
una prova su cluster con MySQL e storage sacrificabili. Non contattare o modificare
un contesto kubectl esistente soltanto per controllare il rendering locale.

Il Deployment ha un limite di avanzamento di 30 minuti, superiore ai 20 minuti
concessi all’arresto del Pod: l’attesa di un video non viene considerata un
aggiornamento bloccato dopo i 10 minuti predefiniti di Kubernetes.

Le modifiche al chart non richiedono una nuova immagine Docker. Mantieni appVersion
di Chart.yaml e il tag predefinito allineati alla release pubblicata prevista;
incrementa la versione del chart per le successive release del chart.
