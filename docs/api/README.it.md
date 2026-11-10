# API REST sperimentale — primo sprint

> [English](README.md) · [OpenAPI JSON](openapi.json) · [Integrazione client](clients.it.md)

Questo sprint apre OpenBook alle applicazioni indipendenti: app native,
frontend specializzati e integrazioni di terzi. La UI nativa continua a
gestire il prodotto e tutte le operazioni amministrative.

**Il supporto è sperimentale per copertura e contratto, ed è utilizzabile anche
in produzione.** REST/OAuth e gestione degli accessi non dipendono da APP_ENV.
Restano obbligatori installazione completata, migrazioni OAuth e configurazione
sicura dell’istanza. Le chiavi di firma vengono inizializzate automaticamente.
Non è un’API Mastodon né una rappresentazione ActivityPub. La preparazione operativa è descritta sotto.

## Funzionalità disponibili

Sono rappresentate Instance, Me, Person, PersonReference, Follow, Post e Comment.
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
| Post | `POST /rest/v1/posts` | Creazione testuale o con allegati |
| PostPublication | `GET /rest/v1/postPublication/{postPublicationId}` | Monitoraggio di una propria pubblicazione differita |
| PostPublication | `DELETE /rest/v1/postPublication/{postPublicationId}` | Annullamento di una propria pubblicazione in attesa o fallita |
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
- Instance espone nome, dominio, URL canonico, descrizione, icona opzionale e
  limiti effettivi di upload, senza statistiche. Una descrizione non configurata
  è una stringa vuota.
- I dati federati rappresentano ciò che l’istanza conosce: elenchi e cronologie
  remote possono essere parziali, non sono inventari globali.

## Limiti di upload

GET /instance include `instanceUploadLimits`, con tutte le dimensioni in byte:

```json
{
  "maxAttachmentsPerPost": 4,
  "maxAttachmentBytes": 8388608,
  "maxVideoAttachmentBytes": null,
  "maxRequestBytes": 67108864
}
```

Numero e dimensioni per file sono il minimo tra il limite OpenBook e quello del
runtime che serve la richiesta; immagini/audio e video possono avere soglie
diverse. Il video è null se disabilitato. Numero zero indica che non si possono
caricare allegati; con upload HTTP disabilitati anche le dimensioni per file sono
zero. `maxRequestBytes` è il limite separato per l'intero body multipart, compresi
tutti i file, il JSON e la struttura; null indica assenza di una soglia finita nel
runtime. Non riempire questa soglia con i soli byte dei file. Server web o proxy
possono imporre un limite inferiore che l'endpoint non può conoscere.
L'ETag cambia quando cambiano i limiti esposti. Sono limiti dell'installazione,
non quote residue dell'account o garanzie di successo di un particolare upload.

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

POST /posts accetta NewPost con `postContent` e `postVisibility` obbligatori.
Inviare `application/json` per post solo testuali, mantenendo il contratto attuale.
Per gli allegati usare `multipart/form-data`: la parte `post` contiene il JSON di
NewPost con `Content-Type: application/json` e senza filename; le parti binarie
nominate contengono i file referenziati. L'ordine fisico delle parti non conta.

Esempio della parte JSON:

```json
{
  "postContent": "A walk by the lake.",
  "postVisibility": "public",
  "postAttachments": [
    {"filePart": "attachment1", "mediaAltText": "The lake surrounded by mountains."}
  ]
}
```

Inviare l'immagine in una parte file chiamata `attachment1`. Ogni allegato
referenzia un file distinto nella stessa richiesta; riferimenti mancanti, ripetuti
o file non referenziati vengono rifiutati. I nomi iniziano con una lettera ASCII
e contengono solo lettere ASCII o cifre, fino a 64 caratteri; `post` è riservato.
L'ordine nell'array determina la presentazione. Immagini, audio e video abilitati
usano la stessa struttura e i limiti esistenti dell'istanza per tipo, dimensioni
e numero. `mediaAltText` è facoltativo e nullable, massimo 1.000 caratteri; stringa
vuota significa nessuna descrizione. `postAttachments` è facoltativo e solo
multipart. `postContent` vuoto è consentito solo in multipart con almeno un
allegato valido; testo composto soltanto da spazi è rifiutato. Il limite testuale
dell'istanza vale per entrambe le modalità.

Le visibilità in creazione/modifica sono public, unlisted e followers. Campi
sconosciuti, null non ammessi e valori non validi danno 422; JSON malformato dà 400,
Content-Type della richiesta non supportato 415. Un upload troppo grande può
essere rifiutato direttamente dal server web, con errore non necessariamente JSON.

La creazione restituisce 201 con Post e relativa Location dopo la pubblicazione
locale. Invii con video restituiscono 202 con PostPublication e URL di monitoraggio
in Location, dopo il salvataggio privato dell'intera richiesta. I post misti
vengono pubblicati insieme dopo l'elaborazione. Nessuno dei codici conferma la
consegna remota completata; 202 non conferma ancora la pubblicazione. Ripetere
POST può duplicare l'invio, anche dopo una risposta persa.

GET /postPublication/{postPublicationId} restituisce solo `postPublicationId`,
`postPublicationStatus` (`pending`, `processing`, `failed`, `published`) e `postId`
nullable. Interrogare Location durante pending/processing; con published leggere
il risultato tramite GET /posts/{postId}. Tentativi interni del backend possono
riportare processing a pending senza nuovi invii del client. Non ci sono
percentuali o scadenze di completamento. I record terminali restano consultabili
fino alla pulizia secondo la retention dell'istanza: default sette giorni
dall'ultimo aggiornamento. Dopo, GET dà 404 senza eliminare il post pubblicato.
Un post finale eliminato viene rappresentato con postId null.

DELETE /postPublication/{postPublicationId} annulla lavori pending/failed e pulisce
i temporanei, restituendo 204. Processing dà 409; pubblicazioni published, assenti
o altrui danno 404. GET e DELETE sono riservate all'autore, richiedono account
Bearer attivo e scope api, senza obbligo di email verificata. Record esistenti
restano consultabili/annullabili se la creazione video viene disabilitata.
Risposte con Cache-Control: no-store. Nessuna delega staff o upload media indipendente.

PATCH accetta PatchPost con testo e visibilità opzionali, almeno uno presente;
il testo non può essere vuoto. La modifica restituisce 200 con Post solo per
propri post locali semplici. Allegati, posizione, sondaggi, community, citazioni e
direct restano esclusi dalla modifica: 409. Titolo/CW/lingua omessi si conservano.
DELETE /posts/{postId} elimina solo propri post locali: 204; post già eliminato
404. Gli admin non possono modificare post altrui.

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

## Preparazione e produzione

Installare le dipendenze Composer e applicare le migrazioni OAuth con
`php artisan migrate --force`. Le chiavi di firma vengono generate automaticamente
al primo utilizzo OAuth e salvate nel database cifrate con APP_KEY. Non servono
comandi manuali né file OAuth da conservare. Includere nei backup il database e
APP_KEY e mantenerli nei deploy: cambiare o perdere la coppia invalida i token
esistenti; perdere APP_KEY impedisce di decifrarla. Chiavi esistenti non leggibili
producono un errore, senza essere sostituite automaticamente.
Per la produzione usare APP_ENV=production, APP_DEBUG=false, APP_URL HTTPS e
cookie di sessione sicuri (SESSION_SECURE_COOKIE=true). Aggiornare cache di
configurazione e route dopo il deploy; una vecchia cache può conservare
l’assenza delle route REST. Nessun Redis, worker permanente o servizio esterno
nuovo è richiesto.

```sh
php artisan passport:client --public --name="Example application" --redirect_uri="https://client.example/callback" --no-interaction
```

Paw: OAuth 2, Authorization Code, client_id registrato, secret vuoto,
scope api e PKCE S256. Il callback deve coincidere con quello registrato.
Se il client non supporta PKCE, non completa il flusso automaticamente.

## Sprint successivo

La seconda PR è prevista per ampliare le entità Community, Event,
Notification e Hashtag e le loro relazioni. Upload Media e post complessi
richiedono contratti dedicati. Non sono già implementati da questo sprint.
Cache basata sulle date, ricerca, sondaggi, messaggistica e ulteriori impostazioni
restano da definire.

## Verifica email

Gli account non verificati possono autorizzare applicazioni, ottenere e rinnovare
token, leggere risorse, seguire utenti, mettere mi piace e ricondividere post.
Creare un post richiede la verifica email: altrimenti `POST /rest/v1/posts`
restituisce HTTP 403, `application/problem+json` e
`code: "emailVerificationRequired"`. Dopo la conferma tramite il sito, lo stesso
Bearer permette di creare post senza una nuova autorizzazione. I permessi di
modifica e cancellazione dei post esistenti restano invariati.

## Commenti ai post

| Metodo | Percorso | Funzione |
| --- | --- | --- |
| GET | `/rest/v1/posts/{postId}/comments` | Elenco dei commenti pubblicati e delle risposte annidate |
| POST | `/rest/v1/posts/{postId}/comments` | Creazione di un commento testuale o risposta |
| GET | `/rest/v1/posts/{postId}/comments/{commentId}` | Dettaglio del commento |
| PATCH | `/rest/v1/posts/{postId}/comments/{commentId}` | Modifica del testo del proprio commento |
| DELETE | `/rest/v1/posts/{postId}/comments/{commentId}` | Cancellazione del proprio commento |

Comment include autore PersonReference, ID del post radice e ID nullable del
commento padre, testo, date di pubblicazione/modifica, allegati Media esistenti,
contatori noti e stato del proprio Mi piace. Il commento eredita l'audience del
post, che deve essere pubblicato e visibile. Le conversazioni private sono
escluse. Ogni commentId è verificato nel post indicato nel percorso.

NewComment richiede solo `commentContent`. Il campo facoltativo
`commentParentId` identifica un padre pubblicato nello stesso post; omesso o
null crea un commento di primo livello. La creazione richiede email verificata
e restituisce 409 sui post direct. PatchComment accetta solo il campo
obbligatorio `commentContent`, conservando allegati e collegamenti alle risposte.
Il testo deve contenere caratteri diversi dagli spazi e rispettare il limite
configurato dei commenti. Le proprietà sconosciute sono rifiutate. Queste
operazioni non espongono upload o gestione dei Mi piace dei commenti.

PATCH e DELETE richiedono un proprio commento locale pubblicato, anche per gli
admin. Non richiedono verifica email. POST restituisce 201 con Comment e
Location, PATCH 200 con Comment, DELETE 204. Ripetere la cancellazione dà 404.
Le modifiche generano i normali Create/Update/Delete ActivityPub; il successo
conferma l'azione locale, non la consegna remota completata.

La lista è piatta e include ogni livello di risposta, conservando i rapporti
tramite `commentParentId`. I cancellati sono omessi, ma le loro risposte
pubblicate restano disponibili: l'ID di un padre cancellato può quindi restare
in una risposta. Valori predefiniti: `sort=commentPublishedAt`, `direction=asc`,
`limit=20` (massimo 100). asc e desc ordinano per pubblicazione con spareggio
per commentId. `after` identifica l'ultimo commento; ancore estranee o non più
disponibili danno 422. Seguire Link next; page, cursor e afterAt non sono
supportati. Le letture remote riusano il refresh delle risposte esistente,
conservano i dati disponibili in cache in caso di errore remoto e ricontrollano
la visibilità prima dei dati o del 304. Le discussioni remote possono essere
incomplete. Le letture supportano ETag privato; scritture ed errori usano no-store.
