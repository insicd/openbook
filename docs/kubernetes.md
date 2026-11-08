> [Italiano](kubernetes.it.md) · [All guides](README.md)

# Kubernetes with Helm

The optional chart is in `helm/openbook`. It uses the published
`skeyby/openbook:26.38.rc1` image and an external MySQL/MariaDB database.
It is a minimal single-instance deployment: one Pod contains separate web,
cron, inbox and delivery containers, plus optional video. They share one
persistent volume. Updates replace the whole Pod, with downtime. A worker restart
can also temporarily affect readiness of the whole Pod.

**The chart has been checked locally with linting, rendering and Kubernetes
schema validation. It has not been deployed or tested on a Kubernetes cluster.** Start with a test namespace
and disposable database before considering production use.

## Prerequisites and configuration

You need a cluster, Helm, kubectl, a persistent storage provisioner (or an existing
claim), a database/account with schema-change privileges, and HTTPS through an
existing ingress controller or external proxy. The chart installs none of those
services. The image uses root for Apache startup and UID 33 for writable storage;
cluster policies must permit that existing image behavior.

Create an instance namespace, for example `openbook`, and create a Secret there
named `openbook-secrets` with keys `APP_KEY` and `DB_PASSWORD`. Generate APP_KEY
once, or reuse the existing key when restoring an instance. Keep it stable
across upgrades. For a new instance, if OpenSSL is available:

```sh
umask 077
printf 'base64:%s' "$(openssl rand -base64 32)" > /secure/path/app-key
```

Using protected files containing the actual key and database password:

```sh
kubectl create namespace openbook
kubectl -n openbook create secret generic openbook-secrets \
  --from-file=APP_KEY=/secure/path/app-key \
  --from-file=DB_PASSWORD=/secure/path/db-password
```

Do not put passwords or APP_KEY in Helm values. Additional secret environment
settings, such as `MAIL_PASSWORD`, may be placed in the same Secret. Files must
contain the exact value without a trailing newline: `--from-file` preserves it
in the password and can prevent the connection. Use direct `APP_KEY` and
`DB_PASSWORD` keys, not Docker's `_FILE` variants.

Create a local `instance-values.yaml` outside the repository:

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

All storage is shared within a single Pod, so a normal ReadWriteOnce claim is
sufficient; ReadWriteMany storage is not required. Configure the actual storage
class or existing claim for your cluster. Keep the release name and namespace
stable; use independent secrets, storage and databases for independent instances.

SMTP, registration, instance settings, upload limits and backups have the same
behavior as in the [Docker administrator guide](docker.md). Add non-secret
settings to `env`; secrets belong in the existing Secret. Secret-content changes
require a Pod restart (`kubectl rollout restart deployment/openbook-openbook -n openbook`).
Do not change APP_KEY as part of normal configuration updates.

## Install and complete setup

After checking the values in a test environment:

```sh
helm upgrade --install openbook ./helm/openbook -n openbook \
  -f /path/to/instance-values.yaml --wait --timeout 30m
kubectl -n openbook logs deployment/openbook-openbook -c prepare
```

An init container applies migrations and clears views before other containers
start. The web entrypoint also performs its normal preparation on web restarts.
If preparation fails, the Pod does not become ready; inspect its events and
prepare/web logs. Readiness checks do not monitor queue processing or database
availability continuously.

On a new instance, create the administrator, then mark installation complete
only after successful account creation:

```sh
kubectl -n openbook exec -it deployment/openbook-openbook -c web -- openbook-entrypoint php artisan openbook:make-admin
kubectl -n openbook exec deployment/openbook-openbook -c web -- openbook-entrypoint php artisan tinker --execute='App\Infrastructure\Installation\InstallationLock::lock((string) config("openbook.version"));'
```

The entrypoint in those commands ensures CLI runs as the web user. No default
administrator or new APP_KEY is generated automatically.

## HTTPS and optional video

The Service is internal (`ClusterIP`, port 80). To use an existing ingress
controller and TLS certificate Secret, add to your values:

```yaml
ingress:
  enabled: true
  className: your-ingress-class
  tlsSecretName: openbook-tls
  annotations: {}
```

The hostname comes from `env.OPENBOOK_DOMAIN`. Provision the certificate Secret
in the same namespace and configure DNS and proxy upload/time limits for your
controller. Forwarded client-IP headers are not automatically trusted by the
image; configuring backend proxy trust is outside this minimal chart. Until
configured, IP-based limits and logs see the proxy address. With Ingress disabled, use an existing
external HTTPS proxy or configure your own ingress resource.

For video, first enable it in the administration UI, then set
`video.enabled: true` and run the Helm upgrade command. Disabling it in the UI
also requires setting the chart value back to false to avoid repeated restarts.

## Updates and retained data

Back up database, the whole storage volume, application key and configuration.
Set `image.tag` to the next published version and run `helm upgrade --install`
as above. The Deployment uses one replica and `Recreate`: old containers stop
before the replacement Pod applies migrations. Allow downtime and up to twenty
minutes for an active video job to finish. Do not increase replicas or change
the strategy to rolling updates with this chart.

The chart-created PVC is retained on `helm uninstall`; an existing claim is
managed separately. Uninstalling does not delete the external database or Secret.
Check retained volumes explicitly before reinstalling and reuse them deliberately,
for example through `persistence.existingClaim`; retention is not a backup. Image rollback does not undo migrations, so restore
matching database/storage backups when necessary.

Local chart validation and development instructions are in
[the developer guide](../development/helm.md).
