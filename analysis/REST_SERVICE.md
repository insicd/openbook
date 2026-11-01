# API REST per applicazioni indipendenti — sprint 1

Stato consolidato: 1 novembre 2026 · ramo `rest_service`. Questo documento
descrive le decisioni e l’implementazione dello sprint nel loro insieme, non
il diario delle prove. Il contratto pubblico è in [OpenAPI](../docs/api/openapi.json)
e nelle guide [italiana](../docs/api/README.it.md) / [inglese](../docs/api/README.md).

## 1. Obiettivo, disponibilità e confini

API di integrazione per client indipendenti, anche sviluppati da terzi:
app native, frontend specializzati e cataloghi. Non sostituisce la UI nativa,
non replica l’API Mastodon e non esporta gli oggetti ActivityPub come payload REST.
Semplicità: risorse esplicite, metodi standard, path prevedibili e rappresentazioni
comuni a locali/remoti; nomenclatura ActivityPub conservata quando appropriata.

Lo sprint è sperimentale per copertura e contratto, ma disponibile anche in
produzione. Bootstrap registra REST/OAuth in ogni ambiente; middleware e
impostazioni non applicano filtri su APP_ENV. Restano i controlli di installazione,
autenticazione e autorizzazione. Le chiavi OAuth sono generate automaticamente
al primo utilizzo e salvate cifrate in system_settings; migrazioni e config/route
cache seguono il normale deploy. Amministrazione,
moderazione, staff, credenziali, eliminazione account e gestione degli accessi
rimangono nella UI nativa. Un account admin non riceve deleghe REST su altri.

Ogni risorsa di contenuto richiede Bearer, scope api, User e Actor attivi.
Nessun accesso anonimo né autenticazione tramite il solo cookie Web. I controlli
valgono anche prima del 304. OAuth è naturalmente avviabile senza Bearer.

## 2. Convenzioni e inventario corrente

Prefisso `/rest/v1`, nomi in inglese, campi prefissati per tipo; enum
case-sensitive lowerCamelCase. I campi standard OAuth restano invariati.
Oggetto/array diretto senza wrapper data, riferimenti PersonReference per le
raccolte. Obbligatorietà e nullabilità sono distinte; nei campi annidati
l’obbligatorietà vale quando è presente il padre. Date ISO 8601, UUID locali
come identificatori e URI canonici dove specificati. Nessuna serializzazione
indiscriminata dei modelli, chiavi o campi privati.

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

## 3. Entità rappresentate

### Instance

Metadati statici da InstanceSettings, senza conteggi o statistiche. URL e dominio configurati, non derivati dall’header Host. Icona omessa se assente.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `instanceName` | string | Sì | No | Nome configurato dell’istanza; riusa InstanceSettings::siteName. |
| `instanceDomain` | string | Sì | No | Dominio configurato, come usato dagli indirizzi federati. |
| `instanceUrl` | string; uri | Sì | No | URL base configurato dell’istanza, senza slash finale. |
| `instanceDescription` | string | Sì | No | Descrizione configurata, senza rendering aggiuntivo; stringa vuota se assente. |
| `instanceIcon` | string; uri | No | No | Icona personalizzata PNG 512; omessa se non configurata. |

### Person

Identità Person/Application/Feed/Group disponibili, con profili locali e remoti normalizzati nella stessa struttura. I Group sono identità di community: Community sarà una risorsa distinta. La disponibilità usa PersonQuery; attori tecnici, cancellati o profili locali redatti sono esclusi. Conteggi, date e ruoli seguono la UI e non attribuiscono privilegi. personSummary usa il testo disponibile nel Core (locale sorgente, remoto normalizzato), senza promettere l’HTML originale.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `personId` | string; uuid | Sì | No | Identificativo della Person nell'istanza. |
| `personUri` | string; uri | Sì | No | Identificatore ActivityPub canonico. |
| `personType` | string; enum person, application, feed, group | Sì | No | Natura dell’identità: persona, applicazione, feed o gruppo. Valori case-sensitive. group identifica l’attore della community; Community rimane una risorsa distinta. |
| `personUsername` | string | Sì | No | Username senza dominio. |
| `personDomain` | string | Sì | No | Dominio dell'account. |
| `personStatus` | string; enum active, inactive | Sì | No | Stato pubblico uniforme per locali e remoti. active indica un account attivo; inactive include account bloccati, disabilitati o sospesi. Le persone cancellate non sono rappresentate: il dettaglio restituisce 404. |
| `personPublishedAt` | string; date-time | Sì | Sì | Data di iscrizione mostrata nella UI: iscrizione a OpenBook per i locali, data dichiarata dall'origine per i remoti. Null se non disponibile. |
| `personProfile` | object | Sì | No | Gruppo dei dati di presentazione, con le fonti locali o remote appropriate. |
| `personProfile.personName` | string | Sì | No | Nome visualizzato, con fallback allo username come nella UI. |
| `personProfile.personSummary` | string | No | Sì | Testo sorgente della biografia o descrizione, senza rendering HTML; il client ne cura la visualizzazione. È il testo disponibile nel Core, non necessariamente il documento originale remoto. Può contenere Markdown o essere una stringa vuota. |
| `personProfile.personAvatar` | string; uri | No | Sì | URL dell'avatar, quando disponibile; proprietà opzionale, con null ammesso. |
| `personProfile.personCoverImage` | string; uri | No | Sì | URL della copertina, quando disponibile; proprietà opzionale, con null ammesso. |
| `personProfile.personFields` | array di object | No | No | Campi aggiuntivi, in ordine; proprietà opzionale, con `[]` ammesso se presente e vuota. I valori possono essere testo oppure URL. |
| `personProfile.personFields[].label` | string | Sì | No | Etichetta del campo aggiuntivo, per esempio Lavoro o Website. La coppia usa i nomi label/value già presenti negli esempi. |
| `personProfile.personFields[].value` | string | Sì | No | Valore del campo, per esempio Operaio o un URL; non vincolato al formato URI. |
| `personAttributes` | object | Sì | No | Gruppo degli attributi pubblici e dello stato di migrazione e sospensione. |
| `personAttributes.personDiscoverable` | boolean | Sì | No | Preferenza di reperibilità nei suggerimenti, secondo i valori effettivi del Core. |
| `personAttributes.personIndexable` | boolean | Sì | No | Preferenza di indicizzazione. |
| `personAttributes.personFollowApproval` | boolean | Sì | No | Indica se il follow richiede approvazione; true significa richiesta da approvare. |
| `personAttributes.personCustomEmojis` | object | No | No | shortcode come `:nome:` associato all'URL dell'immagine; `{}` se non ci sono emoji. |
| `personAttributes.personAlsoKnownAs` | array di string | No | No | Alias dichiarati; `[]` se assenti. |
| `personAttributes.personMovedTo` | object | No | Sì | Destinazione di migrazione come riferimento minimo personId/personUri, senza incorporare ricorsivamente la persona completa. Il campo viene omesso se non è presente una destinazione; lo schema ammette null. |
| `personAttributes.personMovedTo.personId` | string; uuid | Sì | No | Identificatore della persona di destinazione nota all'istanza, quando il riferimento è presente. |
| `personAttributes.personMovedTo.personUri` | string; uri | Sì | No | Identificatore ActivityPub canonico della destinazione. |
| `personAttributes.personMemorial` | boolean | Sì | No | Indicazione di account commemorativo. |
| `personAttributes.personSuspended` | boolean | Sì | No | Sospensione effettiva, locale oppure dichiarata dall'origine, senza distinguerle per il client. |
| `personFollowersCount` | integer | No | Sì | Conteggio follower mostrato dalla UI, con le stesse regole. Null se non disponibile, mai zero per rappresentare un dato mancante. |
| `personFollowingCount` | integer | No | Sì | Conteggio seguiti mostrato dalla UI, con le stesse regole. Null se non disponibile. |
| `personRoles` | array di string; enum admin, moderator | No | No | Ruoli descrittivi dell'account, distinti dal tipo. Può contenere entrambi; [] per nessuno dei due, omesso se non disponibile. Non attribuisce funzioni staff alle API. |

### PersonReference

Forma compatta per autori, follower, seguiti e richiedenti: nessuna esportazione di profili completi nelle liste.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `personId` | string; uuid | Sì | No | Identificativo della Person nell'istanza. |
| `personUsername` | string | Sì | No | Username senza dominio. |
| `personDomain` | string | Sì | No | Dominio dell'account. |
| `personName` | string | Sì | No | Nome visualizzato; in assenza, username. |
| `personAvatar` | string; uri | No | No | URL dell'avatar; omesso se assente. |

### Me

Contesto del Bearer, inizialmente con il solo myPerson. Nessun myId duplicato e nessuna preferenza/sessione incorporata.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `myPerson` | Person | Sì | No | Account autenticato, con la stessa rappresentazione Person già definita. I campi interni mantengono il prefisso person. |

### Follow

Relazione diretta dall’utente autenticato verso la Person nel percorso. none include richiesta mai inviata, rifiutata, ritirata o relazione rimossa. Non espone lo stato inverso né cronologia delle decisioni.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `followStatus` | string; enum none, pending, accepted | Sì | No | Stato del follow dell'utente autenticato verso la Person richiesta. none: non seguita e nessuna richiesta pendente; pending: richiesta in attesa; accepted: la segue. |

### Post

Rappresentazione di lettura comune a feed, dettaglio e ownership. postAnnounce è il contesto selezionato dal feed, non la relazione personale; postDirectAnnounced identifica il rilancio manuale. Messaggi di conversazioni private sempre esclusi. Contenuto testuale senza nuovo rendering HTML. Campi opzionali vuoti omessi secondo lo schema e la risorsa.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `postId` | string; uuid | Sì | No | Identificativo nell'istanza; id è non null nel DB. |
| `postUri` | string; uri | Sì | No | Identificativo canonico. Anche se uri è nullable nel DB, per i locali il Core lo calcola. |
| `postAuthor` | PersonReference | Sì | No | Autore, con personType person/application/feed/group nel dettaglio Person. actor_id è non null. Il riferimento compatto conserva i campi già definiti. |
| `postName` | string; max 255 | No | No | Titolo. Campo omesso se non valorizzato; non si restituisce null. |
| `postContent` | string | Sì | No | Testo non vuoto, con gli stessi vincoli di NewPost. |
| `postCW` | boolean | Sì | No | Indica il Content Warning effettivo della UI. Campo derivato, non copia nullable di content_warning. |
| `postCWText` | string | No | Sì | Eventuale testo del Content Warning. content_warning è nullable; eventuali avvisi applicati dal Core vanno rispettati. La risorsa omette il campo se il testo non è valorizzato. |
| `postLanguage` | string; max 255 | No | Sì | Lingua fornita dal Core; nessuna normalizzazione aggiuntiva nello sprint. |
| `postVisibility` | string; enum public, unlisted, followers, direct | Sì | No | Nuova visibilità; se omessa resta quella corrente. |
| `postPublishedAt` | string; date-time | Sì | No | Data di pubblicazione; published_at è non null. |
| `postEditedAt` | string; date-time | No | Sì | edited_at è nullable; assente o null se non modificato. |
| `postSourceUri` | string; max 768 | No | No | Fonte originale, soprattutto per RSS/Atom. Campo omesso se non valorizzato; non si restituisce null.  |
| `postCustomEmojis` | object | No | No | Mappa delle emoji. Campo omesso se non valorizzato o privo di elementi; non si restituisce null.  |
| `postAttachments` | array di Media | No | No | Relazione ordinata: [] è una raccolta vuota, non null. Struttura Media definita nella relativa sottosezione. |
| `postAttributes` | object | Sì | No | Contenitore degli attributi aggiuntivi; oggetto della rappresentazione, non colonna DB. |
| `postAttributes.postLocation` |  | Sì | No | Relazione opzionale. Null se manca la località associata. |
| `postCounters` | object | Sì | No | Raggruppamento dei contatori esistenti. |
| `postCounters.postLikesCount` | integer | Sì | No | likes_count: intero unsigned non null, default 0. |
| `postCounters.postCommentsCount` | integer | Sì | No | comments_count: intero unsigned non null, default 0. |
| `postCounters.postAnnouncesCount` | integer | Sì | No | announces_count: intero unsigned non null, default 0. |
| `postMyInteractions` | object | Sì | No | Nome concordato per le interazioni già compiute dall'utente autenticato. |
| `postMyInteractions.postLiked` | boolean | Sì | No | Mi piace dell'utente corrente. Calcolato con il flusso Web esistente; false se non presente. |
| `postMyInteractions.postAnnounced` | boolean | Sì | No | Presenza di una condivisione attribuita all'utente corrente, anche automatica. |
| `postMyInteractions.postDirectAnnounced` | boolean | Sì | No | Condivisione manuale tramite Condividi, distinta da quelle automatiche collegate per esempio alle citazioni. Non indica una condivisione privata. |
| `postTimelineAt` | string; date-time | No | No | Data di ordinamento: rilancio selezionato oppure pubblicazione originale; nei Group sempre pubblicazione. Assente in Mondo e nei dettagli. |
| `postAnnounce` | object | No | No | Contesto del rilancio selezionato dal feed, facoltativo per entrambi gli engine; omesso se il contesto non esiste. Nella timeline è l’ultimo rilancio pertinente. |
| `postAnnounce.announceAuthor` | PersonReference | Sì | No | Autore del rilancio, anche gruppo o autore originale del post. |
| `postAnnounce.announcePublishedAt` | string; date-time | Sì | No | Data del rilancio selezionato. |

### NewPost

Contiene soltanto campi accettati in creazione, senza ereditare gli obbligatori della risposta. postContent e postVisibility sono obbligatori; future estensioni di creazione dovranno essere opzionali.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `postContent` | string | Sì | No | Testo non vuoto, con gli stessi vincoli di NewPost. |
| `postVisibility` | string; enum public, unlisted, followers | Sì | No | Nuova visibilità; se omessa resta quella corrente. |

### PatchPost

Stessi nomi degli input NewPost, tutti opzionali ma almeno uno presente. Campi sconosciuti e null non ammessi. Solo propri post locali semplici.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `postContent` | string | No | No | Testo non vuoto, con gli stessi vincoli di NewPost. |
| `postVisibility` | string; enum public, unlisted, followers | No | No | Nuova visibilità; se omessa resta quella corrente. |

### PatchFollowRequest

Documento di accettazione di una richiesta ricevuta: esclusivamente followStatus=accepted. Il rifiuto usa DELETE. Non introduce una nuova entità di risposta: l’elenco restituisce PersonReference.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `followStatus` | string; enum accepted | Sì | No | Stato del follow dell'utente autenticato verso la Person richiesta. none: non seguita e nessuna richiesta pendente; pending: richiesta in attesa; accepted: la segue. |

### Location

Oggetto geografico riutilizzabile nei post e in futuro negli eventi. I campi sono presenti con null per valori mancanti. Estensioni di indirizzo e identificativo autonomo restano rinviate.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `locationName` | string; max 500 | Sì | Sì | Nome del luogo. post_locations lo richiede non null e massimo 200; event_locations ammette null e massimo 500. Il tipo generico proposto copre entrambi. |
| `locationRegion` | string; max 200 | Sì | Sì | Area amministrativa: admin1_name nei post e region negli eventi; entrambe nullable. |
| `locationCountryCode` | string; max 8 | Sì | Sì | Codice del paese. Nei post è char(2), negli eventi string(8);  |
| `locationCountryName` | string; max 200 | Sì | Sì | Nome del paese; nullable in entrambe le tabelle. |
| `locationLatitude` | number | Sì | Sì | Coordinata; decimal(10,7) nullable nel DB, esposta come numero JSON. Nessun vincolo numerico aggiuntivo introdotto nello sprint. |
| `locationLongitude` | number | Sì | Sì | Coordinata; decimal(10,7) nullable nel DB, esposta come numero JSON. Nessun vincolo numerico aggiuntivo introdotto nello sprint. |

### Media

Rappresentazione incorporata degli allegati già leggibili. Non costituisce ancora un contratto di upload o elaborazione asincrona.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `mediaId` | string; uuid | Sì | No | Identificativo del media. |
| `mediaUrl` | string; uri | Sì | No | URL del contenuto, tramite il metodo esistente. |
| `mediaPreviewUrl` | string; uri | Sì | No | URL di anteprima fornito dal Core; può coincidere con mediaUrl. |
| `mediaMimeType` | string | Sì | No | Tipo MIME. |
| `mediaWidth` | integer | Sì | Sì | Larghezza in pixel, se nota. |
| `mediaHeight` | integer | Sì | Sì | Altezza in pixel, se nota. |
| `mediaAltText` | string | Sì | Sì | Testo alternativo, se disponibile. |

## 4. Letture, selezione e paginazione

| Vista | Riuso | Navigazione e contesto |
| --- | --- | --- |
| Person, Me | PersonQuery, resolver/refresh conteggi esistenti per il dettaglio remoto | Per Me nessun nuovo fetch; Person non importa i post. |
| Follower/seguiti | PersonFollowQuery sopra FollowListQuery | Solo relazioni accettate, inclusa cache di membri remoti noti; filtri di disponibilità e membership privata. after/personId, limit 20/100, asc/desc. |
| World | FeedQuery::world | Engine predefinito, after/postId, limit 20/100, pubblicazione discendente. |
| Timeline | FeedQuery::forActor | after + afterAt, limit 20/100, postTimelineAt. Include rilanci pertinenti. |
| Post del profilo | FeedQuery::forProfile, RemoteOutboxFetcher e FeedImporter | Propri post più rilanci, TTL del Web, pagina del Web senza parametro limit. after + afterAt. Nei Group la data di ordine è la pubblicazione. |
| Me/posts | Actor::posts, scope Post, paginatePublishedQuery | Solo ownership, no rilanci altrui. after/postId, limit 20/100. |
| Richieste ricevute | Modello Follow pending verso il Bearer e PersonQuery::available | PersonReference dei richiedenti disponibili; after/personId, limit 20/100, asc/desc. Nessun fetch. |

Link next standard completo, senza cursori server e senza page/cursor opachi.
Per World e Me/posts un’ancora non più disponibile nella raccolta dà 422;
per timeline/profilo la coppia data/ID resta un confine anche se il post sparisce.
I limiti per ID sono deterministici, con spareggio per ID a parità di data;
la navigazione non è uno snapshot. Il filtro ownership è applicato anche alle
ancore di Me/posts. Dati federati potenzialmente parziali, mai una vista globale.

Il dettaglio Post usa RemoteRepliesFetcher con TTL e fallisce verso i dati
disponibili in cache; il profilo usa i resolver esistenti. La disponibilità
viene riletta dopo il refresh prima di esporre dati o 304. I fetch riusano
SafeHttpClient, controlli SSRF e limitazioni del Core. Non si introducono query
o resolver federati paralleli.

## 5. Scritture e confini di autorizzazione

PostComposer crea i post testuali e modifica quelli semplici; validazione
REST limita gli input ai campi supportati. Modifica/rimozione usano ownership
prima di PostPolicy: anche un admin non può agire su post di altri o su copie
remote. Esclusi conversazioni, stato non pubblicato e, per PATCH, media,
posizione, sondaggi, community, citazioni e direct. I campi omessi di titolo,
CW e lingua sono conservati dal mapping al servizio. LocalContentDeleter
gestisce eliminazione e federazione. DELETE di un post già cancellato dà 404.

ReactionManager e AnnounceManager gestiscono interazioni, notifiche, contatori
e Like/Announce/Undo. PUT Mi piace/rilancio restituisce 201 alla prima attivazione
e 204 se già attivo; DELETE 204 anche se assente su una risorsa disponibile.
PUT rilancio richiede public/unlisted fuori da community private: il serializer
del Core produce attività pubbliche e non basta poter leggere l’originale.
DELETE può rimuovere un rilancio preesistente diventato invisibile; dopo la
rimozione un originale ancora invisibile dà 404. Citazioni e condivisioni
implicite rimangono intatte, con postAnnounced eventualmente ancora true.

FollowManager::statusMapFor/follow/unfollow fornisce none/pending/accepted.
Locali aperti/Feed accettati subito, locali protetti e remoti non Feed pending
finché confermati. PUT crea con 201 o aggiorna/riusa con 200 e restituisce Follow;
può ritentare la consegna pending o sanare un locale diventato aperto.
DELETE 204 ritira anche una richiesta. Self-follow e vincoli di dominio sono
422; nuove interazioni verso remoti sospesi sono 403. Le rimozioni non applicano
il blocco delle nuove interazioni. Identità escluse da PersonQuery danno 404
per tutti i metodi, anche con vecchie relazioni. I Group locali usano le policy
join/leave e CommunityMembershipService, evitando bypass della membership.

Richieste ricevute: solo following_id del Bearer, status pending e richiedente
disponibile. PATCH accetta PatchFollowRequest e invoca FollowManager::accept;
DELETE invoca reject. Successo 204, richiesta non pendente/assente/altrui 404.
Non rimuove relazioni accepted, non gestisce richieste di Group e non aggiunge
delega staff. Le decisioni restano possibili se nel frattempo il profilo è aperto.

Le mutazioni REST che richiedono precondizioni acquisiscono lock locali su
Post, Actor o richiesta prima dei servizi. Sono protezioni fra quelle richieste
REST, non una nuova garanzia per scritture Web o federate che non acquisiscono
gli stessi lock. Successo locale non significa consegna remota completata.

## 6. OAuth e gestione accessi

Passport, OAuth 2 Authorization Code/PKCE S256, scope api. Registrazione pubblica
RFC 7591, metadata RFC 8414; niente OIDC, grant password, credenziali utente al
client o polling. Client pubblici senza secret, registrati una volta per istanza;
registrazione non deduplicata per nome. Callback HTTPS, HTTP loopback o schema
privato con dominio invertito, senza frammenti/credenziali e con confronto del
redirect gestito dal protocollo. State/PKCE affidati alle librerie client.

Login e consenso riusano la sessione Web e CSRF. Bearer JWT validato e revocabile
lato server; account/Actor attivi in autorizzazione, emissione, refresh e REST.
Solo grant authorization_code/refresh_token esposti. OPENBOOK_OAUTH_TOKEN_DURATION
in giorni, default implicito 365 se assente/non valido; accesso e refresh con
la stessa durata, token già emessi invariati.

Coppia RSA OAuth generata una volta al primo utilizzo e conservata in
system_settings come singolo valore cifrato con APP_KEY. OAuthSigningKeys usa
il generatore RSA esistente e putIfAbsent; dopo un inserimento concorrente
rilegge la coppia vincente. Valori presenti ma non decifrabili, malformati o
non corrispondenti producono un errore senza rigenerazione. La coppia viene
caricata in memoria soltanto prima della risoluzione dei server Passport: niente
accesso DB al boot generale o durante config:cache. Nessun file OAuth o variabile
PEM richiesto, nessun percorso di importazione delle vecchie chiavi nel prodotto.

ConnectedApplications elenca accessi dell’utente e revoca accesso/refresh senza
chiudere altri accessi o sessione Web. Lookup sempre per proprietario, revoca
e rinnovo con ordine coerente dei lock. Se la rotazione è già avvenuta,
l’accesso precedente non chiude il sostituto: messaggio di elenco cambiato e
ricaricamento, senza affermare una chiusura avvenuta. Nessuna promessa di
lineage persistente delle sessioni, IP/GeoIP, revoca su logout Web o nuova API
di gestione delle app in questo sprint.

## 7. HTTP, errori e sicurezza

Cache privata/no-cache con ETag sul corpo e Link, Vary Authorization,
If-None-Match/304 dopo autenticazione e autorizzazione. Non elimina il costo
di interrogazione/refresh server. Scritture/errori no-store. Last-Modified e
If-Modified-Since rinviati. Problem Details condivisi per REST e validazione;
OAuth conserva i propri errori. Stati HTTP autorevoli, nessun header custom.
CORS per client Bearer senza cookie, esposizione dei soli header standard
ETag/Link/Location/Retry-After/WWW-Authenticate. Throttle distinti per indirizzo:
60 REST, 30 token, 10 registrazioni al minuto.

Review di sicurezza dell’intero ramo:
- isolamento Bearer da sessione, scopi e account attivi prima dei dati/304;
- IDOR su post, follow, richieste e chiusura accessi; nessuna delega admin;
- selezione whitelist delle risorse, nessun token, email o chiave esposta;
- visibilità followers/direct e membership privata nei feed/dettagli, esclusione
  delle conversazioni e ricontrollo dopo refresh;
- consenso CSRF, redirect e PKCE, revoca/refresh, fallback dati federati;
- escaping dei nomi app nella UI e JSON come dato, senza HTML eseguito;
- assenza di credenziali, token di prova, screenshot o dump DB nei file versionati.

## 8. Integrazioni e impatto sul progetto

Nuovi controller/risorse REST e adapter di protocollo, senza nuove regole nel
dominio o nei serializer federati. Riuso dei servizi e scope elencati sopra.
Le sole integrazioni condivise già introdotte dal ramo sono: trait/contratto
Passport su User; estrazione dei selettori equivalenti di FollowListQuery
per evitare duplicazione Web/REST; registrazione route/provider e guard,
impostazioni e UI per gli accessi. Non vengono cambiate le regole Web del
grafo o dei post. Le nuove tabelle sono quelle OAuth di Passport, con UUID
compatibili con gli account; nessuna migrazione del modello sociale.

Restano shared hosting e i normali flussi di consegna, senza nuovi obblighi di
Redis, worker permanenti o servizi esterni. Le normali migrazioni installano le
tabelle OAuth; la coppia di firma viene inizializzata automaticamente. Backup
del database e APP_KEY devono essere conservati insieme, come per gli altri
segreti cifrati dell’istanza.

## 9. Verifiche e riproducibilità

La suite PHPUnit copre risorse, filtri e ancore, riservatezza, proprietà dei post,
richieste ricevute, interazioni/federazione, OAuth/PKCE, chiusura accessi e UI.
Esecuzione con configurazione di test isolata, per non usare il MySQL locale:

```sh
APP_CONFIG_CACHE=/tmp/openbook-rest-tests-config.php php vendor/bin/phpunit
php vendor/bin/pint --dirty
```

OpenAPI validato con esempi e confronto route/README; schemi errore condivisi
senza cambiamento del contratto. Il test SDK reale descritto in
[clients.md](../docs/api/clients.md) usa SQLite e loopback, PKCE/state, login
con CSRF, scambio, REST 200/304/401 e chiusura/revoca di entrambi i token.
Non è un test grafico o su device iOS/Android e va eseguito separatamente dai
test che rimuovono il marker installato.

Verifiche MySQL eseguite sul ramo con dati temporanei in rollback: lookup e
paginazione dei post, tutti i rami delle query condivise di liste/feed,
interazioni e richieste, token/client e accessi. I piani effettivi usano PK
e indici del grafo; nelle richieste ricevute l’indice following/status limita
la coda prima dell’ordinamento temporaneo per personId. Nessuna pretesa che
gli esiti SQLite provino da soli migrazioni o prestazioni MySQL. Il test finale
e gli eventuali limiti rimasti sono registrati nell’esito seguente.

### Esito della review dello sprint

Review rispetto a upstream/main aggiornato, base comune 2ca1788: nessun problema
critico o bloccante individuato. Consolidate le risposte 422 nei soli controller
REST; estratti tre schemi Problem Details identici nell'OpenAPI senza variazione
del contratto. La lista personale di richieste non rappresenta un elenco di membri di
community; le risposte 404 dovute solo al precedente filtro di ambiente sono
state rimosse dalla specifica di questa lista e del dettaglio Instance. Confronto semantico degli
schemi e delle operazioni precedenti superato. Nessuna nuova logica del Core.

| Verifica | Esito finale |
| --- | --- |
| Suite completa PHPUnit, codice finale | 2254 test, 17135 asserzioni, 130 skipped, nessun errore/fallimento. |
| REST, accessi collegati e notifiche push | 171 test, 2033 asserzioni, inclusi i flussi con ambiente production e i controlli sulle chiavi di firma. |
| Autorizzazioni | Coperti admin senza delega, richieste di community escluse, payload rigorosi, richieste già gestite, credenziali assenti, PKCE obbligatorio e CSRF sulla revoca in produzione. |
| OpenAPI e inventario | Specifica valida, 402 esempi validi, tutte le 22 operazioni REST presenti nelle guide italiana e inglese. |
| SDK OAuth reale | Registrazione, PKCE/state, login/consenso con CSRF, callback, scambio, REST 200/304/401, revoca accesso/refresh, sessione Web conservata e generazione automatica delle chiavi cifrate nel DB: superati anche con APP_ENV=production e SQLite/loopback isolati. |
| MySQL richieste ricevute | 32 fixture in rollback, GET 200 e PATCH/DELETE 204; indice following/status per la coda, unico follower/following e PK per decisioni/identità. Consegne simulate, nessun fetch remoto. |
| Chiavi OAuth | Inizializzazione atomica concorrente, cifratura, riuso e rifiuto di valori invalidi o cifrati con un altro APP_KEY senza rotazione; config:cache production non contiene PEM. Coppia locale preesistente importata una tantum fuori dal prodotto: identica e verificata su due record di accesso esistenti, senza modificarli. |
| Controlli statici | Composer validate, Pint, diff --check, link relativi delle guide e ricerca di segreti nei file modificati: superati. |

Il bootstrap con APP_ENV=production e cache route separata registra tutte le
22 operazioni REST, gli endpoint OAuth e la revoca accessi. I vecchi test di
inaccessibilità sono sostituiti da test dei flussi autenticati; nessun guard
su installazione, Bearer, scope, account o CSRF è stato rimosso. La fixture SDK
verifica che il bootstrap produzione forzi HTTPS, poi adatta soltanto URL e
cookie al trasporto HTTP del proprio server loopback. Non è una prova di TLS
del deploy e non modifica il comportamento HTTPS dell'applicazione.

Le 130 esclusioni restano visibili nel risultato PHPUnit: non si dichiara che
ogni test condizionale sia stato eseguito. Nessuna istanza MariaDB dedicata o
prova su dispositivo iOS/Android in questa sessione. Le verifiche SDK via HTTP
non sono un test grafico del browser. Questi limiti non introducono restrizioni
di ambiente per il supporto autenticato.

## 10. Roadmap successiva e questioni aperte

| Area | Percorsi/decisioni successive |
| --- | --- |
| Comment | /posts/{postId}/comments e /events/{eventId}/comments, con dettaglio figlio. Definire Comment, NewComment, risposte annidate e autorizzazioni. |
| Community | Catalogo/dettaglio, posts/followers, follow e /me/communities (ownership, non moderazione). |
| Event | Catalogo/dettaglio, /me/events; follow «mi interessa» separato da participation «parteciperò», followers e participants. Definire Event e Location estesa. |
| Notification | /me/notifications, stato lettura e riferimenti ai contenuti. |
| Hashtag | Catalogo/dettaglio/posts e follow, normalizzazione del tag. |
| Media e post complessi | Upload indipendente /media, elaborazione, NewPost/PatchPost estesi, sondaggi/citazioni e altri oggetti. |
| Ricerca/impostazioni | Contratti ancora da discutere; nessuna nuova API implicita in questo sprint. |

Le liste generali future useranno relationship con valori multipli in OR e
filtri diversi in AND; sort e direction separati. /me raccoglie ownership e
contesto esclusivo del Bearer, non tutto ciò che segue. Cataloghi includeranno
anche risorse proprie. Community/Event non diventano sottotipi di Post, e non
si introducono alias /me/following o /me/followers. La gestione delle richieste
di partecipazione dell’organizzatore dovrà essere delimitata rispetto allo staff.

Per le evoluzioni successive: politica di versionamento e compatibilità e
ulteriori test operativi.
La seconda PR estenderà le entità mancanti sopra; questo sprint non le dichiara
già supportate né promette parità funzionale completa con il Web.
