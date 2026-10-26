# Bozza API REST

> [English](README.md) · [Specifica OpenAPI](openapi.json) · [Integrazione client](clients.it.md)

Tutti gli endpoint REST di contenuto richiedono utente attivo e Bearer token
OAuth, anche per contenuti pubblici sul sito. Il solo cookie Web non autorizza
le chiamate REST. Questo prototipo è limitato a local/testing.

- `GET /rest/v1/me` restituisce Me con myPerson, l’account autenticato come Person completa.
- `GET /rest/v1/persons/{personId}` restituisce un oggetto Person.
- `GET /rest/v1/persons/{personId}/followers` restituisce un array di PersonReference.
- `GET /rest/v1/persons/{personId}/following` restituisce un array di PersonReference.

PersonReference contiene personId, personUsername, personDomain, personName e
personAvatar opzionale. Le liste usano after, limit (predefinito 20, massimo 100),
sort=personId e direction=asc (predefinita) o desc. Link con rel="next" indica
la pagina successiva. Le informazioni remote possono essere parziali e non si
avviano fetch remoti. Profili locali sospesi e risorse non disponibili danno 404.

## Flusso OAuth

Si usa OAuth 2 Authorization Code con PKCE S256 e client pubblico. La libreria
client apre il login OpenBook esistente, riceve un codice sulla propria callback
e lo scambia con un token. Non c'è polling e il token non passa nell'URL della
callback. Lo scope unico è `api`; l'utente vede una normale conferma dell'app,
senza scelta di permessi granulari. OpenBook è un server OAuth, non un provider
OpenID Connect: non richiedere `openid` o un ID token.

| Operazione | Endpoint |
| --- | --- |
| Metadata OAuth (RFC 8414) | `GET /.well-known/oauth-authorization-server` |
| Registrazione client pubblico (RFC 7591) | `POST /oauth/register` |
| Autorizzazione nel browser | `GET /oauth/authorize` |
| Scambio codice e rinnovo | `POST /oauth/token` |

La registrazione riceve JSON. Supporta redirect HTTPS, HTTP loopback per app
locali e schemi privati basati sul dominio invertito per app native. Sono
rifiutati frammenti e credenziali nell'URL.

```json
{
  "client_name": "Example application",
  "redirect_uris": ["https://client.example/callback"],
  "token_endpoint_auth_method": "none",
  "grant_types": ["authorization_code", "refresh_token"],
  "response_types": ["code"],
  "scope": "api"
}
```

Conservare il client_id restituito e riusarlo sull'istanza: non si registra
l'app a ogni login. Ogni chiamata di registrazione crea un client; il server non
deduplica applicazioni arbitrarie per nome o redirect URI. I client pubblici non
ricevono un secret. Il server abilita Authorization Code e refresh e restituisce
i metadata effettivi. Gli altri metadata vengono ignorati; registrazione di
client riservati e gestione delle registrazioni non sono esposte.

Le richieste token usano application/x-www-form-urlencoded e i nomi standard
grant_type, client_id, code, redirect_uri e code_verifier. La risposta contiene
access_token, token_type, expires_in e refresh_token. Gli access token sono JWT
con revoca verificata sul server. Access token e refresh token durano per impostazione
predefinita 365 giorni. La durata dei nuovi token è configurabile in giorni con
`OPENBOOK_OAUTH_TOKEN_DURATION`; quelli esistenti conservano la loro scadenza.
Il rinnovo non richiede un nuovo login.
Disponibilità dell'account verificata ad autorizzazione, emissione, rinnovo e
accesso REST. Nelle impostazioni personali, «Applicazioni collegate» elenca
i singoli accessi: «Chiudi questo accesso» revoca sia il Bearer sia il rinnovo,
lasciando aperti gli altri accessi e il sito. Un accesso non più valido sparisce
dall’elenco; se l’app lo ha appena rinnovato, aggiornare l’elenco e chiudere
quello corrente. Non vengono mostrati i token. Il logout Web non revoca i token
OAuth. Amministrazione e strumenti staff restano fuori dalle API REST.

## Configurazione locale e prova

Installare le dipendenze Composer e applicare le migration OAuth pendenti.
Generare una sola volta le chiavi con `php artisan passport:keys`, conservare
storage/oauth-*.key e non sovrascrivere chiavi esistenti a ogni aggiornamento.
Aggiornare cache configurazione e route. Integrazione di produzione con
installer/updater Web ancora rinviata.

I client possono registrarsi automaticamente tramite l'endpoint sopra. Lo
sviluppatore può anche creare un client pubblico con il comando standard:

```sh
php artisan passport:client --public --name="Example application" --redirect_uri="https://client.example/callback" --no-interaction
```

```sh
curl -i -H "Authorization: Bearer $OPENBOOK_TOKEN" \
  'http://127.0.0.1:8000/rest/v1/persons/PERSON_UUID/following?limit=20'
```

In Paw o altri strumenti scegliere OAuth 2 / Authorization Code, inserire il
client_id registrato, lasciare vuoto client_secret, inviare client_id nel body
della richiesta token e impostare scope `api` e PKCE S256. La callback deve
corrispondere esattamente a quella registrata. Uno strumento senza supporto PKCE
non può completare automaticamente questo flusso.

CORS consente ai browser le chiamate Bearer a REST, metadata, registrazione e
token senza cookie Web. ETag, Link, Retry-After e WWW-Authenticate sono esposti
a JavaScript. Gli errori REST usano Problem Details; OAuth conserva il proprio
formato standard. Credenziali mancanti/non valide danno 401; account inattivo dà
403. ETag/If-None-Match consente 304 dopo tutti i controlli di accesso.
Last-Modified resta da implementare. Limiti indipendenti per indirizzo client:
60 chiamate REST, 30 scambi token e 10 registrazioni al minuto.
