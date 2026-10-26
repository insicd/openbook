# Bozza API REST

> [English](README.md) · [Specifica OpenAPI](openapi.json)

Il primo prototipo espone `GET /rest/v1/persons/{personId}`, usando l'UUID
della persona nell'istanza. Restituisce direttamente un oggetto Person.

È disponibile solo negli ambienti local e testing. Il prototipo non richiede
autenticazione, anche per i profili remoti. Non è un'API per la produzione.
Aggiornare la cache delle route dopo aver installato le modifiche.

Esempio con il server di sviluppo:

```sh
curl -i -H 'Accept: application/json' \
  'http://127.0.0.1:8000/rest/v1/persons/PERSON_UUID'
```

Le risposte includono ETag e supportano If-None-Match con HTTP 304. Il limite
è 60 richieste al minuto per client. Gli errori usano application/problem+json.
I profili remoti usano i dati già disponibili nell'istanza, senza fetch remoti.
Profili locali sospesi e risorse non disponibili restituiscono 404.

Autenticazione, supporto CORS dedicato e Last-Modified non sono implementati
in questo primo prototipo. If-Modified-Since da solo non produce 304.

Gli endpoint seguenti restituiscono un array diretto di PersonReference relativo
alla persona specificata nel percorso:

- `GET /rest/v1/persons/{personId}/followers`
- `GET /rest/v1/persons/{personId}/following`

Ogni riferimento contiene personId, personUsername, personDomain e personName,
più personAvatar quando disponibile. Il personId permette di ottenere il profilo
completo. Sono incluse solo relazioni accettate. Per i remoti l'elenco può essere
parziale e contiene solo persone già conosciute dall'istanza.

Usare `limit` (predefinito 20, massimo 100) e `after` (l'ultimo personId ricevuto).
L'ordinamento iniziale è `sort=personId`, con `direction=asc` (predefinita) o
`desc`. Un riferimento non più presente non azzera la navigazione. L'header Link
con `rel="next"`, quando presente, contiene l'URL della pagina successiva.
Parametri non validi restituiscono HTTP 422.
