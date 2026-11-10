{{- define "openbook.name" -}}
{{- printf "%s-openbook" .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "openbook.labels" -}}
app.kubernetes.io/name: openbook
app.kubernetes.io/instance: {{ .Release.Name | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service | quote }}
{{- end -}}

{{- define "openbook.image" -}}
{{- printf "%s:%s" (required "image.repository is required" .Values.image.repository) (required "image.tag is required" .Values.image.tag) -}}
{{- end -}}

{{- define "openbook.claim" -}}
{{- default (include "openbook.name" .) .Values.persistence.existingClaim -}}
{{- end -}}

{{- define "openbook.runtime" -}}
image: {{ include "openbook.image" . | quote }}
imagePullPolicy: {{ .Values.image.pullPolicy }}
env:
  - name: APP_KEY
    valueFrom:
      secretKeyRef:
        name: {{ .Values.existingSecret | quote }}
        key: APP_KEY
  - name: DB_PASSWORD
    valueFrom:
      secretKeyRef:
        name: {{ .Values.existingSecret | quote }}
        key: DB_PASSWORD
envFrom:
  - configMapRef:
      name: {{ include "openbook.name" . }}
  - secretRef:
      name: {{ required "existingSecret must name an existing Kubernetes Secret" .Values.existingSecret | quote }}
volumeMounts:
  - name: storage
    mountPath: /var/www/html/storage
resources:
  {{- toYaml .Values.resources | nindent 2 }}
{{- end -}}
