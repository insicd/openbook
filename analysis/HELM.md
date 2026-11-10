# Minimal Helm deployment

Provide a small optional chart using the published Openbook image. No Docker
image change, database subchart, Redis, operator, autoscaler or cluster installation.

Use one Deployment with one replica and Recreate strategy. Web, cron, inbox,
delivery and optional video are containers in that same Pod, sharing a single
PVC. This supports a conventional ReadWriteOnce volume without cross-node
sharing or affinity rules. An init container runs the image's preparation helper
before any regular container starts. The normal web entrypoint still performs
its idempotent preparation on web restarts. All containers use the same version.

Use a ConfigMap for non-secret environment values and a pre-existing Kubernetes
Secret for APP_KEY and DB_PASSWORD (and optional SMTP secrets). Never generate
or rotate application keys in the chart or put passwords in Helm values/history.
The PVC is retained on uninstall; an existing claim is also supported. HTTPS is
provided by the cluster's existing ingress controller or external proxy.

An optional Ingress exposes only the web Service. Keep replicas fixed at one:
rolling upgrades, independent worker scaling, restricted/rootless Pod policies
and multi-host storage are outside this minimal chart's scope. The image needs
its existing Apache root startup and UID 33 writable storage.

Validate lint, local rendering, default and optional branches and invalid input.
Do not claim cluster/runtime validation: none is performed without an actual
Kubernetes cluster. Document administrator operations separately from developer
rendering checks, in English and Italian.

Validation completed with official Helm 4.3.1 downloaded to /tmp and checksum
verified, without installing it globally. Strict lint and default rendering pass;
video/Ingress/existing-claim branches render successfully. Parsed both outputs
and checked one replica/Recreate, init ordering, shared images/mounts, selectors,
retained/new PVC versus existing claim, Secret references and no rendered Secret
values. Missing configuration and passwords in values are rejected. No cluster
or kubectl context was contacted, and no Docker image or application code changed.


Review before commit:
- Corrected Secret file instructions to avoid trailing newlines (Kubernetes
  --from-file preserves exact bytes, unlike shell command substitution).
- Clarified that Docker _FILE inputs are unsupported here; use direct Secret keys.
- Aligned NOTES rollout timeout with the 30-minute installation guide and used
  five-second HTTP probe timeouts rather than the default one-second limit.
- Tested cancellation of the existing init command while it waits for the startup
  lock, using the published image in a disposable Docker container. It exits
  without SIGKILL; no additional supervisor/wrapper is needed.
- Validated base and optional manifests against Kubernetes 1.27.16 schemas with
  kubeconform 0.8.0; all thirteen resources across three rendered variants pass strict validation.
- Clarified backend client-IP trust as outside the minimal chart's scope.
No cluster/runtime deployment is claimed, no kubectl context was used, and the
application and published image remain unchanged.

The Deployment progress deadline is explicitly 1800 seconds, exceeding the
1200-second termination grace, so draining video is not prematurely considered
a failed rollout at the Kubernetes default 600 seconds. Helm NOTES and operator
commands use a matching 30-minute timeout. Chart packaging and package lint also
pass. No application changes required a new PHP test run for this chart-only
commit; cluster runtime testing remains outstanding and documented.
