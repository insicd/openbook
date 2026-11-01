# API REST sperimentale — primo sprint

> [English](README.md) · [OpenAPI JSON](openapi.json) · [Integrazione client](clients.it.md)

Questo sprint apre OpenBook alle applicazioni indipendenti: app native,
frontend specializzati e integrazioni di terzi. La UI nativa continua a
gestire il prodotto e tutte le operazioni amministrative.

**Il supporto è sperimentale e attivo soltanto in local/testing.** In produzione
le route REST/OAuth e la gestione delle app collegate non sono disponibili,
anche se vengono riutilizzate cache di route locali. L’integrazione nel normale
installer/updater e l’abilitazione in produzione sono rinviate. Non è un’API
Mastodon né una rappresentazione ActivityPub.

## Funzionalità disponibili

Sono rappresentate Instance, Me, Person, PersonReference, Follow e Post.
NewPost e PatchPost descrivono gli input dei post; PatchFollowRequest accetta
le richieste ricevute. Media e Location sono oggetti già incorporati nei Post,
senza endpoint autonomi di upload o gestione.

| Entità | Endpoint | Funzione |
| --- | --- | --- |
| Instance | `GET /rest/v1/instance` | Metadati statici dell’istanza |
| Me | `GET /rest/v1/me` | Identità autenticata |
| Me | `GET /rest/v1/me/posts` | Propri post, senza rilanci altrui |
| Person | `GET /rest/v1/persons/{personId}` | Dettaglio del profilo |
| Person | `GET /rest/v1/persons/{personId}/followers` | Follower del profilo |
| Person | `GET /rest/v1/persons/{personId}/following` | Persone seguite |
| Person | `GET /rest/v1/persons/{personId}/posts` | Post e rilanci del profilo |
| Follow | `GET /rest/v1/me/follow-requests` | Richiedenti in attesa |
| Follow | `PATCH /rest/v1/me/follow-requests/{personId}` | Accettazione della richiesta |
| Follow | `DELETE /rest/v1/me/follow-requests/{personId}` | Rifiuto della richiesta |
| Follow | `GET /rest/v1/persons/{personId}/follow` | Stato del proprio follow |
| Follow | `PUT /rest/v1/persons/{personId}/follow` | Follow o richiesta |
| Follow | `DELETE /rest/v1/persons/{personId}/follow` | Rimozione o ritiro del follow |
| Post | `GET /rest/v1/posts` | Mondo o timeline personale |
| Post | `POST /rest/v1/posts` | Creazione testuale |
| Post | `GET /rest/v1/posts/{postId}` | Dettaglio del post |
| Post | `PATCH /rest/v1/posts/{postId}` | Modifica dei propri post semplici |
| Post | `DELETE /rest/v1/posts/{postId}` | Eliminazione dei propri post locali |
| Post | `PUT /rest/v1/posts/{postId}/announce` | Rilancio manuale |
| Post | `DELETE /rest/v1/posts/{postId}/announce` | Rimozione del proprio rilancio |
| Post | `PUT /rest/v1/posts/{postId}/like` | Aggiunta del proprio Mi piace |
| Post | `DELETE /rest/v1/posts/{postId}/like` | Rimozione del proprio Mi piace |

Tutte le chiamate REST richiedono Bearer OAuth, account e attore attivi e scope
`api`. Il cookie della sessione Web non basta, neppure per contenuti pubblici.
Ruoli admin/moderatore non conferiscono privilegi aggiuntivi nelle route REST.

## Rappresentazioni e contenuti

- Oggetto o array diretto, senza wrapper `data`. L’OpenAPI definisce campi,
  obbligatorietà, nullabilità ed esempi; proprietà opzionali e null sono distinti.
- Nomi e enum case-sensitive in lowerCamelCase; i nomi del protocollo OAuth
  mantengono il formato standard.
- Person comprende `person`, `application`, `feed` e `group`, con lo stesso
  formato per identità locali/remoti disponibili. La Person group è l’identità
  della community, non il futuro oggetto Community. PersonReference è la forma
  compatta con ID, username, dominio, nome e avatar opzionale.
- `myPerson` è la Person completa dell’utente autenticato. `/me/posts` seleziona
  ownership; `/persons/{personId}/posts` comprende invece anche i rilanci.
- `personSummary` e `postContent` sono testo disponibile in OpenBook, senza nuovo
  rendering HTML: Markdown o testo semplice secondo la fonte. Non si promette
  il documento originale remoto. Il client gestisce rendering e sanitizzazione.
  `personFields` contiene coppie label/value, anche quando il valore non è un URL.
- Instance espone solo nome, dominio, URL canonico, descrizione e icona opzionale,
  senza statistiche. Una descrizione non configurata è una stringa vuota.
- I dati federati rappresentano ciò che l’istanza conosce: elenchi e cronologie
  remote possono essere parziali, non sono inventari globali.

## Letture e paginazione

| Raccolta | Confine e dimensione | Ordine |
| --- | --- | --- |
| Follower, seguiti e richieste ricevute | `after` = ultimo personId; `limit` 20 predefinito, massimo 100 | `sort=personId`; `direction=asc` predefinita o `desc` |
| `/me/posts` e `/posts?engine=world` | `after` = ultimo postId; `limit` 20/100 | `sort=postPublishedAt`; `direction=desc` |
| `/posts?engine=timeline` | `after` + `afterAt`; `limit` 20/100 | `sort=postTimelineAt`; `direction=desc` |
| `/persons/{personId}/posts` | `after` + `afterAt`; dimensione pagina configurata per il Web, `limit` non ammesso | `sort=postTimelineAt`; `direction=desc` |

Seguire l’header standard `Link: <URL>; rel="next"` quando presente. Non ci sono
cursori persistiti sul server, né parametri `cursor` o `page` per queste liste.
Nella timeline/profilo `afterAt` è la data ISO restituita come postTimelineAt:
il link fornisce sempre entrambi i parametri, anche se l’ancora viene rimossa.
Nei gruppi la data di ordinamento del profilo è quella di pubblicazione.
Per World e /me/posts l’ancora deve essere ancora disponibile nella raccolta:
altrimenti 422, e il client deve ripartire dalla prima pagina.
La navigazione non è uno snapshot immutabile.

World è il motore predefinito e segue la selezione del Mondo Web; timeline segue
la home personale. `postAnnounce` è il contesto di un rilancio selezionato dal
feed e può essere assente. Non viene inventato per dettagli, World o /me/posts.
`postMyInteractions.postDirectAnnounced` indica invece il proprio rilancio
manuale; `postAnnounced` comprende anche le condivisioni implicite delle citazioni.

Il dettaglio Person aggiorna metadati e conteggi remoti secondo le cache
esistenti; il suo elenco post importa i contenuti quando scade il TTL.
Il dettaglio Post usa l’aggiornamento remoto del Web. Gli errori remoti
conservano i dati in cache quando ancora accessibili. Le autorizzazioni sono
ricontrollate dopo i refresh. Le liste di persone/richieste non avviano fetch.

## Scritture e relazioni

POST /posts accetta solo NewPost con `postContent` e `postVisibility` obbligatori.
Le visibilità ammesse in creazione/modifica sono public, unlisted e followers.
PATCH accetta PatchPost con gli stessi campi tutti opzionali, almeno uno presente.
Testo non vuoto e limite configurato per l’istanza; campi sconosciuti, null o
valori non validi danno 422. JSON malformato dà 400, Content-Type non JSON 415.

Creazione: 201 con Post e Location. Modifica: 200 con Post, soltanto per propri
post locali semplici. Media, posizione, sondaggi, community, citazioni e direct
restano fuori dalla modifica iniziale: 409. Campi omessi come titolo/CW/lingua
sono conservati. DELETE elimina solo propri post locali: 204; un post già
cancellato dà 404. Gli admin non possono operare su post altrui.

| Relazione | PUT | DELETE |
| --- | --- | --- |
| Mi piace | 201 alla creazione, 204 se già presente | 204 anche se già assente |
| Rilancio manuale | 201 all’attivazione, 204 se già attivo | 204 anche se già assente su un post leggibile |
| Follow | 201 con Follow alla creazione, 200 con Follow se esistente | 204 anche se già assente su una Person disponibile |

Queste operazioni non richiedono body. Follow contiene solo `followStatus`:
none, pending o accepted. GET restituisce 200 anche per none; la direzione è
sempre utente autenticato → Person nel percorso. Rifiuto/ritiro tornano a none.
Locali aperti e Feed sono accettati subito; locali protetti e remoti non Feed
possono restare pending fino alla conferma. PUT può ritentare la richiesta
remota pendente, senza duplicare la relazione. Self-follow/target non attivi
rifiutati dal servizio danno 422.

Nuovi rilanci richiedono post public/unlisted fuori da community private.
Contenuti leggibili ma non rilanciabili danno 403. Il rilancio esistente può
essere rimosso dopo perdita di visibilità; a rimozione avvenuta, ulteriori
DELETE su un post invisibile danno 404. Post cancellati e messaggi privati sono
sempre esclusi. La rimozione conserva citazioni e condivisioni implicite.
Remoti sospesi rifiutano nuovi follow/Mi piace/rilanci con 403; le rimozioni
restano possibili se l’identità/risorsa è ancora disponibile. Community group
rispettano i permessi di membership; il proprietario non può uscirne.

### Richieste ricevute

GET /me/follow-requests restituisce le PersonReference dei richiedenti pendenti
verso l’account autenticato. Non mostra richieste altrui o di community.
PATCH sul dettaglio richiede `{"followStatus":"accepted"}` come PatchFollowRequest
e restituisce 204; DELETE rifiuta e restituisce 204. Richieste assenti, già gestite,
ritirate, altrui o non disponibili danno 404. DELETE non rimuove follower già
accettati. Le richieste rimangono gestibili anche se il profilo è stato aperto
nel frattempo. Solo lo stato in attesa determina la presenza nella raccolta.

## Autenticazione e accessi collegati

OAuth 2 Authorization Code con PKCE S256, scope unico `api` e consenso tramite
login Web esistente. Non è OpenID Connect: niente ID token/userinfo, grant
password, credenziali utente affidate al client o polling. Endpoint di protocollo
alla root, senza il prefisso /rest/v1:

- `GET /.well-known/oauth-authorization-server`: metadata OAuth.
- `POST /oauth/register`: registrazione pubblica RFC 7591, senza client secret.
- `GET/POST/DELETE /oauth/authorize`: avvio, consenso/rifiuto con sessione e CSRF.
- `POST /oauth/token`: scambio codice e rinnovo con refresh token.

Registrare il client una volta per istanza e conservarne client_id; le chiamate
di registrazione non sono deduplicate per nome. Una registrazione non è una
sessione utente. Sono supportati callback HTTPS, HTTP loopback per test/app
native e schemi privati con nome di dominio invertito; nessun frammento o
credenziale nell’URI. Il redirect deve corrispondere a quello registrato.
Token request form-urlencoded con i nomi standard grant_type, client_id,
code, redirect_uri, code_verifier. Le librerie client gestiscono PKCE e state:
vedi [integrazione](clients.it.md).

Access token JWT con verifica e revoca server-side. Durata implicita 365 giorni
per accesso e refresh; `OPENBOOK_OAUTH_TOKEN_DURATION` controlla i nuovi token
in giorni, non modifica quelli emessi. Rinnovo senza nuovo login.
Disponibilità dell’account verificata in autorizzazione, emissione, rinnovo
e chiamate REST.

Nelle impostazioni, Applicazioni collegate elenca i singoli accessi e permette
di chiuderli con i relativi refresh token. Altri accessi e sessione Web restano
aperti. I token non sono mostrati. Se il client ha già ruotato un accesso,
l’elenco segnala il cambiamento: ricaricare e chiudere quello corrente.
Il logout Web non revoca automaticamente gli accessi OAuth. Password, email,
eliminazione account, staff e amministrazione rimangono nella UI nativa.

## HTTP, cache e sicurezza

Le letture usano `Cache-Control: private, no-cache`, ETag e Vary Authorization.
Inviare If-None-Match per ottenere 304 solo dopo autenticazione e controlli
attuali di accessibilità. Separare la cache del client per utente e URL;
invalidare/rivalidare i dati interessati dopo le scritture.
Le scritture e gli errori usano no-store. Last-Modified/If-Modified-Since non
sono ancora implementati: usare ETag. Gli stati HTTP sono autorevoli;
gli errori REST sono Problem Details, con errors per la validazione.
Gli errori OAuth mantengono il formato del protocollo. 401 per credenziali
mancanti/non valide; 403 per account non attivo o permessi insufficienti.
404 tutela le risorse non accessibili, come descritto per ogni operazione.

CORS espone ETag, Link, Location, Retry-After e WWW-Authenticate ai client
Bearer senza cookie Web. Limiti per indirizzo: 60 chiamate REST, 30 scambi token
e 10 registrazioni al minuto. Servizi di federazione e protezioni degli URL
restano quelli di OpenBook; successo locale non garantisce consegna remota.
I client devono trattare contenuti, nomi e URL come dati non fidati.

## Preparazione locale

Installare le dipendenze Composer e applicare le migrazioni OAuth. Generare
le chiavi una sola volta con `php artisan passport:keys` e preservare i file
`storage/oauth-*.key`; non sovrascriverli negli aggiornamenti ordinari.
Aggiornare cache di configurazione e route. Nessun Redis, worker permanente
o servizio esterno nuovo è richiesto.

```sh
php artisan passport:client --public --name="Example application" --redirect_uri="https://client.example/callback" --no-interaction
```

Paw: OAuth 2, Authorization Code, client_id registrato, secret vuoto,
scope api e PKCE S256. Il callback deve coincidere con quello registrato.
Se il client non supporta PKCE, non completa il flusso automaticamente.

## Sprint successivo

La seconda PR è prevista per ampliare le entità Comment, Community, Event,
Notification e Hashtag e le loro relazioni. Upload Media e post complessi
richiedono contratti dedicati. Non sono già implementati da questo sprint.
Attivazione in produzione, installer/updater, cache basata sulle date,
ricerca, sondaggi, messaggistica e ulteriori impostazioni restano da definire.
