# Bozza API REST

> [English](README.md) · [Specifica OpenAPI](openapi.json) · [Integrazione client](clients.it.md)

Tutti gli endpoint REST di contenuto richiedono utente attivo e Bearer token
OAuth, anche per contenuti pubblici sul sito. Il solo cookie Web non autorizza
le chiamate REST. Questo prototipo è limitato a local/testing.

- `POST /rest/v1/posts` crea un post testuale da NewPost e restituisce Post.
- `GET /rest/v1/posts` restituisce un array di Post da Mondo o dalla home personale.
- `GET /rest/v1/posts/{postId}` restituisce un singolo Post.
- `PATCH /rest/v1/posts/{postId}` modifica un proprio post semplice con PatchPost.
- `DELETE /rest/v1/posts/{postId}` elimina un proprio post locale.
- `PUT /rest/v1/posts/{postId}/like` aggiunge il proprio Mi piace.
- `DELETE /rest/v1/posts/{postId}/like` rimuove il proprio Mi piace.
- `GET /rest/v1/me` restituisce Me con myPerson, l’account autenticato come Person completa.
- `GET /rest/v1/persons/{personId}` restituisce un oggetto Person.
- `GET /rest/v1/persons/{personId}/followers` restituisce un array di PersonReference.
- `GET /rest/v1/persons/{personId}/following` restituisce un array di PersonReference.

PersonReference contiene personId, personUsername, personDomain, personName e
personAvatar opzionale. Le liste di persone usano after, limit (predefinito 20, massimo 100),
sort=personId e direction=asc (predefinita) o desc. Link con rel="next" indica
la pagina successiva. Le liste di relazioni remote possono essere parziali;
questi elenchi non avviano fetch remoti. Profili locali sospesi e risorse non disponibili danno 404.

Person supporta i tipi case-sensitive `person`, `application`, `feed` e
`group`. PersonReference usa gli stessi campi compatti per tutti i tipi.
L’identità delle community private locali rimane leggibile; le loro raccolte
di relazioni danno 403 e le identità private sono escluse dagli elenchi di relazioni.

## Schemi di creazione

Gli input di creazione usano schemi `New<Entity>`, separati dalle entità di
risposta: per esempio NewPost è la controparte di creazione di Post. Nomi e
raggruppamenti sono conservati quando applicabili; obbligatorietà e nullabilità
sono definite separatamente. I campi generati dal server o di sola lettura
non fanno parte degli input.

NewPost accetta per ora soltanto postContent e postVisibility, entrambi
obbligatori e non null. postContent deve contenere testo non composto soltanto
da spazi e rispettare il limite dell'istanza. postVisibility accetta public,
unlisted o followers, senza default. Gli altri campi che verranno aggiunti
saranno opzionali. POST /posts accetta NewPost come oggetto JSON e restituisce
201 con il Post completo e Location verso il dettaglio REST. La risposta usa
no-store. Proprietà sconosciute o riservate a funzioni future danno 422, come
i campi mancanti o non validi. JSON malformato dà 400; un Content-Type non JSON
dà 415. L’autenticazione precede la validazione.

Hashtag, menzioni note, notifiche e federazione seguono la pubblicazione Web.
201 conferma la pubblicazione locale, non la consegna remota. Ripetere un POST
può creare un altro post e un errore non garantisce che nulla sia stato creato.
Il limite del testo segue la configurazione dell’istanza come sul sito. Media,
sondaggi, citazioni, community, posizione e post direct non sono ancora supportati.

```json
{
  "postContent": "Ciao dalla mia applicazione! #OpenBook",
  "postVisibility": "public"
}
```

## Elenco dei post

`GET /rest/v1/posts` restituisce un array diretto di Post. `engine=world` è
il valore predefinito e riproduce selezione, esclusioni e ordine della sezione
Mondo. `engine=timeline` usa la home personale dell'utente autenticato, compresi
i rilanci. La rappresentazione espone testo, autore PersonReference, CW, media,
luogo, contatori e interazioni; sondaggi, citazioni e altri oggetti collegati
restano da aggiungere.

Entrambi gli engine accettano limit (default 20, massimo 100) e direction=desc.
Mondo usa sort=postPublishedAt e after (ultimo postId). Un riferimento Mondo
non più disponibile o non leggibile dà 422; ripartire dalla prima pagina.
La timeline usa sort=postTimelineAt e la coppia after + afterAt, contenente
l'ultimo postId e il suo postTimelineAt. Seguire il Link next, che include tutti
i parametri. Nessuno stato di paginazione viene conservato sul server.
cursor e page non sono supportati.

Le voci della timeline conservano postAuthor e postPublishedAt originali.
postTimelineAt è la data usata per ordinare; postAnnounce, quando presente,
contiene announceAuthor (PersonReference) e announcePublishedAt. Ogni post
compare una sola volta con l'ultimo rilancio pertinente; sono inclusi i rilanci
dei propri post e la distribuzione dei gruppi. postAnnounce è facoltativo per
entrambi gli engine e viene omesso quando manca un contesto di rilancio. Mondo
attualmente elenca gli originali senza selezionare un rilancio e omette
postTimelineAt. La paginazione non è
una fotografia del feed: le modifiche possono influire sulle pagine successive.

Il testo non viene renderizzato in HTML dall’API. I campi facoltativi senza
valore vengono omessi, compresi titolo, fonte ed emoji.

## Modifica e cancellazione dei post

PATCH /posts/{postId} accetta un oggetto JSON PatchPost. postContent e
postVisibility sono entrambi opzionali, ma almeno uno deve essere presente.
I valori forniti non ammettono null e seguono i vincoli di NewPost. I campi
omessi mantengono i loro valori, inclusi titolo, CW e lingua già esistenti.
Oggetto vuoto, proprietà sconosciute o valori non validi danno 422; JSON
malformato dà 400 e Content-Type non JSON dà 415.

Il primo giro di modifica copre i propri post locali pubblicati, senza
allegati, località, sondaggi, community o citazioni, con visibilità public,
unlisted o followers. Post non supportati danno 409 senza modifiche. Il
successo restituisce 200 con il Post aggiornato; hashtag, menzioni, notifiche
e federazione seguono il flusso di modifica Web.

```json
{
  "postContent": "Un messaggio aggiornato. #OpenBook"
}
```

DELETE /posts/{postId} elimina un proprio post locale, anche fuori dal
perimetro semplice della modifica. Restituisce 204 senza body in caso di
successo. Un post già cancellato dà 404 alla ricerca iniziale, prima della
policy e del servizio di cancellazione. Il GET successivo dà 404. Messaggi privati, post
inesistenti o non disponibili e post altrui danno 404. Il ruolo admin non
consente di operare sui post altrui tramite queste API.
Entrambe le scritture richiedono Bearer attivo e restituiscono no-store.

## Dettaglio Post e Mi piace

GET /posts/{postId} restituisce la stessa rappresentazione Post degli elenchi.
Sono disponibili soltanto post pubblicati e visibili all'utente autenticato;
i messaggi privati sono esclusi. Risposte e contatori remoti vengono aggiornati
con la politica di cache già usata dal Web. Se il server remoto non risponde,
si usano i dati disponibili. Dopo il refresh si ricontrolla la visibilità,
prima di restituire dati o 304. Il dettaglio omette postAnnounce e postTimelineAt
perché non seleziona un contesto di feed.

PUT /posts/{postId}/like e DELETE /posts/{postId}/like non richiedono un body.
PUT restituisce 201 con Location alla creazione, oppure 204 se già presente.
DELETE restituisce 204 anche se già assente. Entrambi restituiscono un body
vuoto e no-store; contatori, notifiche e federazione seguono il comportamento
Web. Post non disponibili danno 404; Mi piace ad autori sospesi dal server
remoto danno 403.

## Aggiornamento del dettaglio Person

GET /persons/{personId} aggiorna metadati e conteggi followers/following remoti
quando scadono le rispettive cache, con la stessa politica del Web. Se il server
remoto non risponde, si usano i dati disponibili. Non si importano liste di post.
La disponibilità viene ricontrollata dopo il refresh, prima dell'eventuale 304.
Profili locali e feed non attivano questi recuperi del profilo remoto. Gli
elenchi continuano a usare i dati locali. Le letture condizionali usano
ETag/If-None-Match; gli endpoint REST attuali non emettono Last-Modified.

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
token senza cookie Web. ETag, Link, Location, Retry-After e WWW-Authenticate sono esposti
a JavaScript. Gli errori REST usano Problem Details; OAuth conserva il proprio
formato standard. Credenziali mancanti/non valide danno 401; account inattivo dà
403. ETag/If-None-Match consente 304 dopo tutti i controlli di accesso.
Last-Modified resta da implementare. Limiti indipendenti per indirizzo client:
60 chiamate REST, 30 scambi token e 10 registrazioni al minuto.
