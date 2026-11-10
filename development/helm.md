# Checking the Helm chart

> [Italiano](helm.it.md) · [Developer documentation](README.md)

The minimal chart is `helm/openbook`; administrator instructions are in
[Kubernetes with Helm](../docs/kubernetes.md). It reuses the published Docker
image without rebuilding it. Web and workers share one Pod and PVC, with one
replica, `Recreate`, and an init container for database preparation. Keep this
layout simple: no database subchart, Redis, operator or autoscaling.

A Kubernetes cluster is not required for these checks:

```sh
helm lint helm/openbook --strict
helm template openbook helm/openbook > /tmp/openbook.yaml
helm template openbook helm/openbook \
  --set video.enabled=true --set ingress.enabled=true \
  --set ingress.className=nginx --set persistence.existingClaim=test-storage \
  > /tmp/openbook-options.yaml
```

For Kubernetes API schema validation, if kubeconform is available (no cluster
required; schemas are fetched from its public schema repository):

```sh
kubeconform -strict -summary -kubernetes-version 1.27.16 /tmp/openbook.yaml /tmp/openbook-options.yaml
```

Review the rendered resource names/selectors, common image, ConfigMap and Secret
references, volume mounts, init order and retained PVC. With an existing claim,
no PVC should be created. No Secret values should be rendered. Empty required
configuration and APP_KEY/DB_PASSWORD/MAIL_PASSWORD placed in `env` must fail
rendering, for example:

```sh
helm template openbook helm/openbook --set existingSecret=
helm template openbook helm/openbook --set env.APP_KEY=do-not-store-in-values
```

The chart was checked with Helm 4.3.1 and kubeconform 0.8.0 against Kubernetes
1.27.16 schemas, plus structural manifest checks. This does not validate
storage provisioning, Pod admission, runtime permissions, ingress or application
behavior on an actual cluster. A test-cluster deployment with disposable MySQL
and storage is required before claiming runtime support. Do not contact or mutate
an existing kubectl context just to run local rendering checks.

The Deployment rollout deadline is 30 minutes, longer than the 20-minute Pod
termination grace period, so video draining is not reported as a stalled rollout
after Kubernetes' default 10 minutes.

Changing chart files does not require publishing a new Docker image. Keep
Chart.yaml appVersion and the default image tag aligned with the intended
published release; increment the chart version for subsequent chart releases.
