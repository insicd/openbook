# API REST per applicazioni terze

Apertura: 25 ottobre 2026 · Revisione: 26 ottobre 2026  
Ramo: `rest_service` · Commit di partenza: `2ca1788`

Stato: canovaccio delle regole generali e prototipo locale di lettura Person e liste PersonReference.
Le sezioni 1–8 raccolgono decisioni, orientamenti e rappresentazioni in corso
di definizione; la sezione 9 raccoglie le scelte generali aperte e la sezione 10
descrive gli agganci della prima implementazione; la sezione 11 documenta gli elenchi.
La sezione 12 descrive OAuth 2 standard con Passport; la sezione 13 descrive
la gestione degli accessi nelle impostazioni. Tutte le risorse REST richiedono Bearer; le note sul prototipo anonimo sono storiche.
L'appendice conserva i percorsi già discussi; l'inventario e il perimetro della
prima release non sono ancora completi.

## 1. Obiettivo e perimetro

Esporre i servizi Core di OpenBook ad applicazioni indipendenti, anche create
da terzi: app mobile, frontend specializzati, cataloghi eventi e altre
integrazioni. Ogni client può usare solo una parte del Core. La UI nativa
continua a essere un'interfaccia del prodotto.

**La parola chiave è semplicità:** entità chiare e durevoli, metodi standard,
path eloquenti, poche convenzioni uniformi e simmetria prevedibile. I concetti
equivalenti usano gli stessi nomi e metodi, mantenendo le regole del dominio.
La lungimiranza riguarda i confini delle risorse, non funzionalità ipotetiche.

Le API espongono esclusivamente interazioni utente. Amministrazione,
moderazione e funzioni staff rimangono nella UI nativa, anche quando
l'account autenticato possiede quei privilegi. Inviare una segnalazione è
un'interazione utente; gestirla come staff è fuori perimetro.

Per ora restano nella UI nativa anche cambio password, cambio email,
eliminazione dell'account e gestione delle app collegate e delle sessioni
esterne. Il logout della sessione corrente del client verrà definito insieme
al protocollo di login.

Mastodon è stato un confronto, senza obiettivi di compatibilità con le sue API.

## 2. Risorse, rappresentazioni e percorsi

L'API usa un percorso pubblico versionato, con `/rest/v1/*` come riferimento.
I nomi plurali in inglese sono la convenzione proposta. I path descrivono il
dominio, senza riprodurre schermate, tabelle o servizi interni.

È concordato usare oggetti REST propri e semplici, senza adottare la struttura
ActivityPub. Quando il significato coincide, i nomi di campi e attributi
riprendono la nomenclatura ActivityPub/ActivityStreams: nessuna rinominazione
gratuita. Questa coerenza non impone il formato della federazione.

È concordata una regola generale aggiuntiva: i campi referenziano il tipo
dell'entità mediante prefisso, per esempio `personId`, `personUri`, `personName`.
La nomenclatura ActivityPub resta un riferimento per il termine dopo il
prefisso, senza impedire i nomi esplicitamente scelti per il contratto REST.

### Casing degli enum

Nomi dei campi e valori enum REST seguono la stessa convenzione
**lowerCamelCase**: prima lettera minuscola, iniziali delle parole successive
maiuscole, senza underscore. I valori sono case-sensitive; non si accettano
varianti di capitalizzazione.
Esempi: `person`, `application`, `active`, `pendingApproval` (quest'ultimo
illustra solo la convenzione, non introduce un nuovo stato).

I nomi delle entità/schema restano PascalCase (`Person`, `Community`);
questo non cambia la convenzione dei campi (`personType`, `personStatus`)
e dei valori enum.

### Entità confermate

| Entità | Ruolo e dipendenza |
| --- | --- |
| Person | Account di persone o software, locali e remoti, e relativi profili. |
| Post | Pubblicazioni. |
| Event | Eventi, distinti dai post. |
| Community | Community, distinte dalle persone. |
| Notification | Notifiche appartenenti esclusivamente all'utente corrente, sotto `/me`. |
| Hashtag | Hashtag e collegamenti ai contenuti. |
| Comment | Unica entità Comment, sotto il post o l'evento a cui appartiene. Nessun percorso autonomo `/comments/{commentId}`. |
| Media | Risorse indipendenti collegabili a più entità, anziché figli esclusivi di un contenuto. |

La struttura comune di Comment non impone un'unica tabella o operazioni
identiche per post ed eventi. Le risposte annidate restano da rappresentare.
I collegamenti molti-a-molti di Media descrivono il contratto desiderato,
non lo schema database attuale; riuso e accesso devono rispettare i contenuti
associati.

### Cataloghi, ownership e contesto personale

- I cataloghi generali includono tutte le risorse consultabili, anche le proprie.
- `/me/posts`, `/me/events` e `/me/communities` indicano rispettivamente post
  pubblicati, eventi creati e community create dall'utente corrente.
- Le risorse seguite o partecipate si selezionano sui cataloghi generali
  mediante filtri sulle relazioni, non tramite raccolte di seguiti sotto `/me`.
- `/me` contiene anche ciò che appartiene esclusivamente al contesto personale,
  come notifiche e richieste di follow ricevute. La risposta principale ha
  un nodo `person` e i campi esclusivi dell'account; le raccolte sono separate.

Una risorsa mantiene la stessa identità nei diversi elenchi. Un proprio post
può comparire sia in `/posts` sia in `/me/posts`: non sono copie o insiemi
disgiunti. La presenza in un catalogo dipende sempre dalla sua visibilità.

Le community locali e remote conosciute dall'istanza condividono il catalogo
`/communities`. Un attributo ne indica l'origine, informazione secondaria per
l'utente. Nome del campo ed eventuale filtro per origine restano da definire.

## 3. Operazioni e relazioni

| Metodo | Significato generale |
| --- | --- |
| GET | Leggere una risorsa o collezione. |
| POST | Creare una risorsa o richiedere un'operazione di dominio. |
| PUT | Sostituire lo stato definito dal contratto; per le relazioni concordate, attivarle senza duplicazioni. |
| PATCH | Applicare una modifica parziale. |
| DELETE | Rimuovere una risorsa o relazione. |

Ogni risorsa espone solo le operazioni sensate e autorizzate. La simmetria
non introduce capacità che il Core non possiede.

### Stati HTTP ed errori

È concordato usare sempre lo stato HTTP appropriato come esito autorevole
della richiesta; il body lo dettaglia senza sostituirlo o contraddirlo.
Nessun errore applicativo mascherato da `200`.

Successi con `200`, `201` per creazioni, `202` per accettazione di lavoro
ancora pendente e `204` per successi senza body, quando pertinenti. Errori
del client con il relativo `4xx`, errori server con il relativo `5xx`;
`304` rimane riservato alla rivalidazione. Non è una mappatura completa:
i codici specifici saranno fissati nella specifica delle operazioni.

Gli errori prodotti dall'API usano Problem Details (RFC 9457), con
`Content-Type: application/problem+json`. Se presente, `status` nel body
coincide con quello HTTP. I membri standard descrivono il problema; `errors`
è l'estensione concordata per gli errori di validazione dei campi, il cui
dettaglio rimane da specificare. Nessuna esposizione di segreti o stack trace.
Le risposte che per semantica HTTP non ammettono un body restano senza body.

### Relazioni dell'utente corrente

La relazione dell'utente corrente con una risorsa è leggibile esplicitamente:
il client non deve dedurla dall'assenza in una pagina di risultati.

- `/follow` usa GET/PUT/DELETE per stato, attivazione e rimozione del follow
  di persone, community e hashtag; negli eventi significa «mi interessa».
- `/participation` negli eventi usa GET/PUT/DELETE per stato, partecipazione
  o richiesta e ritiro. È indipendente dall'interesse.
- Le richieste di follow ricevute sono sotto `/me/follow-requests`, con PATCH
  per accettare e DELETE per rifiutare.

Seguire una persona distingue non seguita, richiesta pendente e seguita.
Gli hashtag non richiedono approvazione. I dettagli degli stati delle community
e delle partecipazioni seguono il Core e saranno specificati successivamente.
Gli elenchi followers/following delle persone includono relazioni accettate;
participants degli eventi include soltanto partecipanti confermati.

Il follow di un evento mantiene il significato dell'interesse esistente;
non introduce una nuova garanzia di notifiche sugli aggiornamenti.

## 4. Entità rappresentate

Questa sezione raccoglie la rappresentazione JSON delle entità, distinta dai
percorsi e dalle operazioni. I nomi campo referenziano il tipo mediante
prefisso. Ogni sottosezione descrive significato, struttura e campi progressivamente
concordati; tipi, formati e nullabilità non ancora decisi restano espliciti.
Person è la prima entità in approfondimento. Le altre conservano un breve
riferimento al ruolo già condiviso, senza anticiparne una specifica.

### Person

#### Introduzione

Person rappresenta account di persone o software, locali e remoti, tramite
`/persons` e nel nodo `person` di `/me`. La fonte interna è il modello Actor
nella tabella `actors`, con il relativo profilo quando presente. Actor è
il nome del modello del Core, non un'ulteriore entità REST da specificare.

Il modello interno può rappresentare anche altri tipi di attore: non tutte
le righe di `actors` sono quindi automaticamente Person. Communities resta
un'entità REST distinta. Questo approfondimento non accorpa le risorse.

La fonte di ogni campo va definita coerentemente con il Core: per le persone
locali i dati di presentazione possono provenire dal profilo associato;
per i remoti sono conservati nell'Actor. La forma restituita al client è la
stessa. Non si esportano automaticamente relazioni interne, credenziali o
endpoint di federazione.

Sono stati esaminati modello Actor, serializer ActivityPub, Profile, resolver
remoto e flussi di aggiornamento di profilo e preferenze.

#### Specifica corrente dei campi

Un'unica tabella usa percorsi puntati per i campi annidati e descrive la
**risposta di lettura**, non campi accettati in scrittura né autorizzazioni
a modificarli. Gli enum e le revisioni concordate sono fissati; le proposte
residue sono indicate nelle descrizioni e riepilogate nella sezione 9.
La presenza di una scelta nella bozza non la rende immutabile.

`docs/api/openapi.json` è la traduzione pubblica corrente: OpenAPI 3.1.1,
schema Person con strutture annidate inline, schema PersonReference e operazioni
`GET /persons/{personId}`, `/followers` e `/following`. Vincoli obbligatorio/nullable ed enum sono
allineati alla tabella. L'operazione ha ora un prototipo locale descritto
nella sezione 10; autenticazione e completamento restano separati. Descrizioni pubbliche ed esempi sintetici sono separati
dalle valutazioni interne di questo documento.

- **Obbligatorio**: la proprietà deve essere presente nella risposta; per
  i campi annidati, se il rispettivo oggetto padre è presente e non nullo.
- **Nullable**: la proprietà può avere valore JSON `null`.
- Le raccolte note come vuote sono `[]`; le mappe vuote sono `{}`. `null`
  indica un dato non disponibile, non un conteggio zero.
- I campi descrittivi, le immagini e gli attributi non essenziali possono
  essere omessi come indicato in tabella. Obbligatorietà e nullabilità restano
  distinte: un campo opzionale può essere assente; solo un campo nullable
  può contenere esplicitamente null. Conteggi e ruoli rimangono opzionali.

I gruppi sono `personProfile` e `personAttributes`; i prefissi `person`
rimangono anche nei campi annidati. Identità, stato e personPublishedAt
restano al primo livello.
I conteggi restano al primo livello, come negli esempi. Esclusi `personUrl`, `personLocal`, `personRemoteSuspended`,
`personCreatedAt` e `personUpdatedAt`.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| `personId` | string, UUID | Sì | No | Identificatore nell'istanza OpenBook, corrispondente all'Actor interno. |
| `personUri` | string, URI assoluto | Sì | No | Identificatore ActivityPub canonico. |
| `personType` | string, enum `person`, `application` | Sì | No | Natura dell'account: person per una persona, application per un account software. Valori case-sensitive. Group è rappresentato da Community. Gli account software seguono gli stessi criteri di rappresentazione e accessibilità della UI, senza introdurre una categoria o selezione REST separata. |
| `personUsername` | string | Sì | No | Username conservato dal Core; non include il dominio. |
| `personDomain` | string | Sì | No | Dominio dell'identità federata. |
| `personStatus` | string, enum `active`, `inactive` | Sì | No | Stato pubblico uniforme per locali e remoti. active indica un account attivo; inactive include account bloccati, disabilitati o sospesi. Le persone cancellate non sono rappresentate: il dettaglio restituisce 404. |
| `personPublishedAt` | string, date-time | Sì | Sì | Data di iscrizione mostrata nella UI: iscrizione a OpenBook per i locali, data dichiarata dall'origine per i remoti. Null se non disponibile. |
| `personProfile` | object | Sì | No | Gruppo dei dati di presentazione, con le fonti locali o remote appropriate. |
| `personProfile.personName` | string | Sì | No | Nome visualizzato, con fallback allo username come nella UI. |
| `personProfile.personSummary` | string | No | Sì | Testo sorgente della biografia o descrizione, senza rendering HTML; il client ne cura la visualizzazione. È il testo disponibile nel Core, non necessariamente il documento originale remoto. Può contenere Markdown o essere una stringa vuota. |
| `personProfile.personAvatar` | string, URI assoluto HTTP(S) | No | Sì | URL dell'avatar, quando disponibile; proprietà opzionale, con null ammesso. |
| `personProfile.personCoverImage` | string, URI assoluto HTTP(S) | No | Sì | URL della copertina, quando disponibile; proprietà opzionale, con null ammesso. |
| `personProfile.personFields` | array di object | No | No | Campi aggiuntivi, in ordine; proprietà opzionale, con `[]` ammesso se presente e vuota. I valori possono essere testo oppure URL. |
| `personProfile.personFields[].label` | string | Sì | No | Etichetta del campo aggiuntivo, per esempio Lavoro o Website. La coppia usa i nomi label/value già presenti negli esempi. |
| `personProfile.personFields[].value` | string | Sì | No | Valore del campo, per esempio Operaio o un URL; non vincolato al formato URI. |
| `personAttributes` | object | Sì | No | Gruppo degli attributi pubblici e dello stato di migrazione e sospensione. |
| `personAttributes.personDiscoverable` | boolean | Sì | No | Preferenza di reperibilità nei suggerimenti, secondo i valori effettivi del Core. |
| `personAttributes.personIndexable` | boolean | Sì | No | Preferenza di indicizzazione. |
| `personAttributes.personFollowApproval` | boolean | Sì | No | Indica se il follow richiede approvazione; true significa richiesta da approvare. |
| `personAttributes.personCustomEmojis` | object, mappa string → URI HTTP(S) | No | No | Proposta coerente con il Core: shortcode come `:nome:` associato all'URL dell'immagine; `{}` se non ci sono emoji. |
| `personAttributes.personAlsoKnownAs` | array di string, URI assoluti | No | No | Alias dichiarati; `[]` se assenti. |
| `personAttributes.personMovedTo` | object, riferimento Person | No | Sì | Destinazione di migrazione come riferimento minimo personId/personUri, senza incorporare ricorsivamente la persona completa. Null se non è presente una destinazione. |
| `personAttributes.personMovedTo.personId` | string, UUID | Sì | No | Identificatore della persona di destinazione nota all'istanza, quando il riferimento è presente. |
| `personAttributes.personMovedTo.personUri` | string, URI assoluto | Sì | No | Identificatore ActivityPub canonico della destinazione. |
| `personAttributes.personMemorial` | boolean | Sì | No | Indicazione di account commemorativo. |
| `personAttributes.personSuspended` | boolean | Sì | No | Sospensione effettiva, locale oppure dichiarata dall'origine, senza distinguerle per il client. |
| `personFollowersCount` | integer, minimo 0 | No | Sì | Conteggio follower mostrato dalla UI, con le stesse regole. Null se non disponibile, mai zero per rappresentare un dato mancante. |
| `personFollowingCount` | integer, minimo 0 | No | Sì | Conteggio seguiti mostrato dalla UI, con le stesse regole. Null se non disponibile. |
| `personRoles` | array di string, enum `admin`, `moderator` | No | No | Ruoli descrittivi dell'account, distinti dal tipo. Può contenere entrambi; [] per nessuno dei due, omesso se non disponibile. Non attribuisce funzioni staff alle API. |

`personType` descrive la natura dell'account; `personRoles` indica i ruoli
admin/moderator. Il client non deve ricavarne capacità amministrative REST.
I campi aggiuntivi usano coppie label/value; personMovedTo è un riferimento
personId/personUri. Queste strutture sono già quelle dell'OpenAPI e non
richiedono la definizione di ulteriori entità.

Per date, nome visualizzato e conteggi vale la parità con la UI. Quali tabelle
o query usare per ottenere gli stessi valori è un dettaglio implementativo,
non una decisione del contratto da sottoporre nuovamente a discussione.

Le normalizzazioni di raccolte vuote e nome visualizzato sono espresse nella
tabella e nell'OpenAPI. I JSON estratti dal DB durante il confronto erano
esempi esplorativi e non costituiscono il contratto corrente; gli esempi
sintetici dell'OpenAPI sono invece coerenti con i relativi schemi.

`personStatus` descrive lo stato generale e `personSuspended` la sospensione.
La corrispondenza concordata è:

- Attore attivo e non sospeso all'origine: `active`, personSuspended false.
- Attore bloccato o account disabilitato: `inactive`; personSuspended riflette
  l'eventuale sospensione concomitante, senza essere impostato dal solo blocco.
- Sospensione locale oppure dichiarata dall'origine: `inactive`, personSuspended true.
- Persona cancellata: nessuna rappresentazione Person; il dettaglio restituisce
  HTTP 404 e la persona non compare nelle collezioni di Person.

Quindi personSuspended true implica sempre personStatus inactive, mentre
inactive non implica necessariamente sospensione. Lo stato active non
sostituisce le autorizzazioni del Core per le singole operazioni.

Verifica del Core: modello Actor, migrazione
`0001_01_01_000058_add_deleted_status_to_actors` e definizione della colonna
nel DB locale ammettono active, suspended, blocked e deleted. StaffUserManager
traduce la disabilitazione dell'account in blocked; remote_suspended conserva
separatamente la dichiarazione del server di origine. Questi dettagli interni
sono normalizzati nel contratto pubblico, senza esporne la provenienza.

#### Dati non inclusi automaticamente e dettagli residui

`user_id` resta un riferimento interno; `is_local` è escluso dal contratto.
Le date interne personCreatedAt e personUpdatedAt sono escluse dalla
rappresentazione pubblica: inserimento e aggiornamento locali non sono
informazioni utili al client. La cache usa i validatori HTTP concordati.
Non si espongono automaticamente `deleted_at`, date tecniche dei fetch o
riferimenti dei contenuti in evidenza. La gestione delle persone cancellate
è definita sopra. Gli altri casi di mancata accessibilità e l'eventuale
utilità di ulteriori metadati restano da specificare.
Le chiavi e gli endpoint della federazione sono relazioni separate.

Gli ulteriori dettagli saranno consolidati aggiornando insieme questa
sezione e la bozza OpenAPI. Nessun nuovo campo interno viene esposto
implicitamente.

### PersonReference

Rappresentazione compatta di Person per gli elenchi di persone. Il nome
`PersonReference` distingue il riferimento dal profilo completo senza introdurre
un diverso tipo di account. Struttura piatta, uguale per locali e remoti;
`personId` permette di ottenere il dettaglio con `GET /persons/{personId}`.
Non contiene conteggi, attributi o altri sottooggetti del profilo completo.

| Nome campo | Tipo campo | Obbligatorio | Nullable | Descrizione |
| --- | --- | --- | --- | --- |
| personId | string (UUID) | Sì | No | Identificativo della Person nell'istanza. |
| personUsername | string | Sì | No | Username senza dominio. |
| personDomain | string | Sì | No | Dominio dell'account. |
| personName | string | Sì | No | Nome visualizzato; in assenza, username. |
| personAvatar | string (URI) | No | No | URL dell'avatar; omesso se assente. |

Le prime operazioni che lo restituiscono sono
`GET /persons/{personId}/followers` e `GET /persons/{personId}/following`:
array diretto di PersonReference riferito alla persona nel percorso, anche
quando diversa dall'utente corrente. Le relazioni pending non compaiono.
Per i remoti l'elenco può essere parziale: include solo persone già note.

### Post

Pubblicazione. Rappresentazione e campi da definire; i post mantengono la stessa
identità nei cataloghi generali, personali e delle community.

### Event

Evento distinto da Post. Rappresentazione e campi da definire; interesse e
partecipazione sono relazioni indipendenti, non tipi diversi di evento.

### Community

Community distinta da Person. Campi da definire;
locali e remote condividono il catalogo, con un attributo di origine.

### Notification

Notifica dell'utente corrente, sotto `/me/notifications`. Campi e stato di
lettura da definire.

### Hashtag

Hashtag e riferimenti ai contenuti associati. Campi, normalizzazione e
codifica dell'identificatore da definire.

### Comment

Rappresentazione comune per commenti a post ed eventi, esposti soltanto sotto
il contenuto padre. Campi e rappresentazione delle risposte annidate da definire.

### Media

Risorsa multimediale indipendente, collegabile ad altre entità. Campi, stato
di elaborazione, proprietà e regole di riuso da definire.

## 5. Consultazione degli elenchi

### Filtri

Ogni criterio usa un parametro esplicito. `relationship` seleziona le risorse
in relazione con l'utente corrente, ad esempio:

```text
/events?relationship=following,participating
/communities?relationship=following
```

- Valori multipli separati da virgola nello stesso criterio sono in **OR**.
- Criteri diversi sono in **AND**.
- Ogni risorsa compare una sola volta, anche se soddisfa più relazioni.
- I filtri personali si riferiscono all’utente autenticato della richiesta REST.
- Sono validi solo valori pertinenti alla risorsa. Un valore non supportato
  produce un errore di validazione, senza essere ignorato silenziosamente.

`following` indica follow di persone, iscrizione a community, hashtag seguiti
e interesse per eventi; `participating` riguarda gli eventi. Gli eventi seguiti
o partecipati possono quindi formare un'unica raccolta senza duplicati.
L'inclusione delle richieste pendenti e gli altri filtri restano da definire.

Non sono previsti un filtro universale `filter` né booleani alternativi
`following=true` e `participating=true`.

### Ordinamento

`sort` indica il criterio. La direzione usa un parametro opzionale separato,
con `direction=asc|desc` come proposta di nome e valori. Nessun prefisso `-`
nel campo. Ogni elenco dovrà documentare criteri ammessi e valori predefiniti.
Nomi dei campi, ordinamento multicampo e dettagli sono ancora aperti.

### Paginazione per ID di riferimento, senza stato server

È concordata una navigazione a cursore semplice: il client prende l'ID
dell'ultimo elemento della risposta precedente e lo passa alla richiesta
successiva. Nessun cursore persistito sul server, token di paginazione
personalizzato o numero di pagina.

Il parametro concordato è `after`, inteso come «dopo questo elemento
nell'ordine della lista», non come confronto numerico o cronologico fra ID:

```text
/posts?sort=published&direction=desc&limit=20
/posts?sort=published&direction=desc&limit=20&after={ultimoPostId}
```

La prima richiesta omette il riferimento. Le successive mantengono filtri,
criterio e direzione e restituiscono gli elementi successivi, escludendo
quello di riferimento. Il client usa l'ID dell'ultimo elemento, non un ID
della risposta o del pacchetto. La stessa regola vale in ordine crescente
e decrescente.

Si assume un ordinamento deterministico: criterio scelto e ID come spareggio.
Il server ricava dall'elemento di riferimento i valori necessari a continuare
in quell'ordine, senza stato di navigazione persistito. Il confronto riguarda
la coppia ordinata, non due condizioni indipendenti in AND: in ordine crescente
per data equivale a `data > dataRiferimento OR (data = dataRiferimento AND
id > idRiferimento)`; in ordine decrescente si invertono i confronti.
È una nota di correttezza per l'implementazione, non un nuovo meccanismo API.

È concordato anche `limit`, opzionale, con valore predefinito e massimo
stabiliti dal server. Il valore `20` negli esempi è soltanto illustrativo.

Restano da definire i valori numerici e il comportamento se l'elemento
di riferimento è cancellato, non accessibile o non risolvibile. Non introdurre
un fallback silenzioso alla prima pagina che possa generare duplicati.
La navigazione inversa, se necessaria, sarà valutata senza cambiare questo
modello di base.

### Forma delle risposte e navigazione tramite Link

È concordato restituire direttamente l'oggetto per un dettaglio e un array
di oggetti per una collezione, anche vuoto, senza contenitori `data` o
`pagination`. I totali dell'intero elenco non sono obbligatori.

La continuazione è indicata dall'header HTTP standard `Link`, con relazione
`next` e URL già completo di `after`, `limit`, filtri e ordinamento:

```http
Link: <https://example.org/rest/v1/posts?sort=published&direction=desc&limit=20&after=ultimoId>; rel="next"
```

Il client può seguire quell'URL anziché ricostruirlo. Quando non ci sono altri
risultati, la risposta non include un link `next`. Eventuali altre relazioni
di navigazione restano da valutare; non sono richieste per il modello di base.

## 6. Accesso, login e sessioni

**Decisione aggiornata: tutte le risorse REST richiedono autenticazione**, anche
per la sola lettura di contenuti pubblici sul Web. Nessun dettaglio, elenco o
risposta condizionale di contenuto viene restituito a un ospite. Questa decisione
sostituisce la precedente parità con l'accesso anonimo della UI.
Gli endpoint del protocollo di login devono naturalmente consentire l'avvio
dell'autenticazione; non sono API di consultazione dei contenuti.

Dopo l'autenticazione restano le regole del Core sulla visibilità della risorsa:
essere autenticati non consente di leggere contenuti privati altrui o usare
funzioni staff tramite REST. Leggibilità tramite link non significa presenza
in elenchi o ricerca.

Il requisito è applicato nel primo giro Passport descritto nella sezione 12.

Le autorizzazioni restano sul server. Si verificano identità, sessione,
account e permessi correnti a ogni richiesta, anche prima di un `304`.
I controlli coprono dettagli, elenchi, ricerca, contatori, media e oggetti
annidati, rispettando visibilità, destinatari, membership, blocchi e sospensioni.

**Requisito confermato:** credenziale durevole, revocabile, associata a un
accesso persistito sul server. La soluzione approvata usa i JWT di Passport,
con revoca verificata sul database e durata predefinita di 365 giorni,
configurabile (sezione 12). Nessun JWT autosufficiente
come fonte dei permessi. Restringere l’accesso o revocare la sessione non
richiede di attendere la scadenza del token. La revoca blocca anche ogni eventuale rinnovo.

Il login deve essere semplice, senza API key manuali o scelta obbligatoria
di scope tecnici. Il client può specializzarsi sugli eventi senza chiedere
all'utente un permesso «solo eventi». Le eventuali restrizioni per app sono
applicate dal server; ampliare il perimetro autorizzato richiede nuovo consenso.

Accessi distinti della stessa app devono essere riconoscibili e revocabili
separatamente. Utente e staff autorizzato li gestiscono dalla UI nativa.
Una revoca non annulla operazioni già autorizzate né copie già scaricate.

La soluzione è OAuth 2 Authorization Code con PKCE S256 tramite Passport,
con registrazione dei client e rinnovo standard: dettagli nella sezione 12.
Il flusso fa accedere l'utente presso OpenBook senza consegnare la password
all'app. Gestione degli accessi nella UI descritta nella sezione 13; inattività e
conseguenze di logout e cambio password restano da raffinare. Nessun registro centrale o servizio
esterno è presupposto.

## 7. Cache e HTTP standard

La cache locale è un requisito del contratto. Usare header standard;
header custom solo quando assolutamente necessari e motivati.

| Meccanismo | Regola condivisa |
| --- | --- |
| `Cache-Control` | Politica esplicita; cache condivise escluse per dati personali. |
| `ETag` / `If-None-Match` | Rivalidazione di dettagli e collezioni; `304` per GET/HEAD invariati e ancora autorizzati. |
| `Last-Modified` / `If-Modified-Since` | Quando esiste una data affidabile della rappresentazione; `If-None-Match` ha precedenza se presente. |
| `Vary: Authorization` | Separazione delle varianti dipendenti dal token, senza header custom con UUID dell'utente. |

I validatori riflettono l'intera rappresentazione, comprese dipendenze,
variazioni negli elenchi e contesto autorizzativo. Il solo `updated_at` della
riga principale può non bastare; la precisione delle date non deve produrre
falsi `304`. Nessuna rivalidazione può aggirare una revoca di accesso.

Per le risposte personali, `private, no-cache` è la base proposta; `no-store`
è disponibile dove la conservazione HTTP va vietata. Durate ed eccezioni
restano da fissare. Cache applicative isolate per istanza, account e contesto
autorizzativo; nessun riuso di dati privati fra account o dopo logout.

CORS dovrà consentire l'uso degli header necessari ai frontend terzi.
In particolare dovranno poter leggere `Link` e i validatori di cache.
Il costo server dei validatori va verificato: meno traffico non implica
meno query. Il cambio di token può creare una nuova variante di cache.

## 8. Riuso del Core, federazione e sostenibilità

Web e REST riusano servizi applicativi, query e regole del Core. Controller
sottili: validazione, autorizzazione, invocazione e rappresentazione. Vanno
verificati anche i controlli oggi presenti nei controller e middleware.

Le app operano tramite l'account locale; ActivityPub resta il flusso fra
server. Successo locale e accettazione o consegna remota sono distinti.
I dati remoti dipendono da ciò che l'istanza conosce e conserva, senza promesse
di ricerca, conteggi o cronologie globali complete. Risoluzioni remote e
interazioni riusano i controlli esistenti.

Restano shared hosting, assenza di nuovi servizi esterni, Redis o worker
permanenti obbligatori e limiti sostenibili per richieste, elenchi e upload.
Streaming, push native e sincronizzazione offline non sono prerequisiti.

Base esaminata: `CONTRIBUTING.md`, `development/architecture.it.md`,
`app/Application/Services/`, `app/Application/Queries/`, `app/Policies/`,
`routes/web.php`, `bootstrap/app.php`. Interesse e partecipazione agli eventi
sono già distinti in `EventInteractionController`: rispettivamente reazioni
like/unlike e `EventParticipationManager` join/leave. La REST API non deve
trasformare questi concetti interni in un unico tipo di relazione federata.

## 9. Decisioni generali ancora aperte

Il canovaccio copre obiettivo, confini, entità, percorsi, relazioni, filtri,
ordinamento, accesso, sessioni e cache. Mancano alcune convenzioni trasversali.
Le voci seguenti sono temi da discutere, non scelte già approvate.

| Tema | Decisione generale da prendere |
| --- | --- |
| Paginazione: dettagli residui | `after`, `limit`, ordine deterministico e `Link` con `rel="next"` concordati; valori predefiniti/massimi e riferimento non più disponibile da specificare. |
| Contenuto delle rappresentazioni | Oggetto o array diretti già concordati; riferimenti rispetto a oggetti incorporati ancora da decidere. |
| Convenzioni dei dati | Prefissi e casing enum fissati, obbligatorietà/nullabilità di Person definite in tabella; restano ambito degli identificatori, formati delle date e convenzioni residue. |
| Errori: dettagli residui | Stati HTTP autorevoli e Problem Details concordati; dettaglio di `errors` e mappatura dei casi, compresa la riservatezza delle risorse non accessibili, da specificare. |
| Scritture | Campi modificabili, comportamento dei retry e duplicati, conflitti fra modifiche concorrenti e significato delle cancellazioni. |
| Operazioni differite | Come rappresentare elaborazione media, successo locale e lavoro ancora pendente; nessun obbligo di streaming. |
| Evoluzione e documentazione | Cambi compatibili/incompatibili, dismissioni, descrizione del contratto e capacità/limiti dell'istanza rilevabili dai client. |
| Gestione degli accessi | OAuth 2/PKCE con Passport, registrazione app, rinnovo, limiti e CORS definiti nella sezione 12. Gestione delle revoche nella UI descritta nella sezione 13; restano politiche di inattività, logout e cambio password. |

### Stato della rappresentazione Person

Il formato di personSummary è concordato: testo sorgente, senza rendering
HTML lato API. Può contenere Markdown e il rendering è responsabilità del
client, secondo i propri vincoli tecnologici. Per i remoti si usa il testo
disponibile nel Core dopo i normali flussi di importazione, senza promettere
l'HTML originale del server di origine o introdurre nuovi fetch.

Date, conteggi, ruoli, account software e riferimenti annidati seguono le
regole definite nella sezione 4; non sono decisioni da riaprire.

### Ownership e operazioni dell'autore

Restano due punti da chiarire senza cambiare le decisioni già prese:

- `/me/communities` significa «create da me», non «che modero». L'eventuale
  differenza fra creatore e proprietario attuale richiederà una definizione.
  Il percorso di lettura non autorizza creazione o gestione di community se
  il Core riserva quelle operazioni allo staff.
- L'accettazione delle richieste di partecipazione da parte dell'organizzatore
  di un evento va classificata: funzione ordinaria dell'autore oppure funzione
  esclusa dal perimetro. Non equipararla implicitamente alla moderazione staff.

### Concetti di dominio ancora da collocare

Impostazioni e campi privati non esclusi dal perimetro; ruoli nelle community;
reazioni e condivisioni; sondaggi, voti e risultati; conversazioni e messaggi;
segnalazioni utente; informazioni dell'istanza; feed e ricerca. Questi ultimi
sono viste sulle risorse, senza presumere un ciclo CRUD proprio.

Anche stati pendenti, risposte annidate dei commenti e dettagli dei media
andranno precisati nella specifica, senza anticiparne ora schema o algoritmi.

### Passaggi successivi

È concordato passare dal canovaccio a una prima fetta concreta, usando Person
come entità di partenza:

1. Definire significato, campi e rappresentazione JSON di Person.
2. Consolidare la bozza OpenAPI JSON già presente, aggiungendo le prime
   operazioni scelte per rendere Person consultabile.
3. Realizzare una prima implementazione di base coerente con quel contratto.

I campi e le operazioni devono essere prima concordati; questa sequenza non
autorizza a riempire in anticipo tutte le questioni aperte né ad avviare ora
l'implementazione. Le convenzioni generali residue saranno affrontate quando
servono per questa prima entità, senza prolungare il catalogo astratto.
Registrazione utenti e integrazioni automatiche non sono ancora incluse.

La verifica operativa riguarderà riuso del Core, autorizzazioni, query,
test e documentazione, secondo le regole del progetto.

## 10. Ricognizione: GET /persons/{personId}

### Prototipo locale implementato

Il primo giro realizzato espone GET non autenticati anche per i remoti,
esclusivamente in ambienti local/testing. È uno stato tecnico provvisorio,
precedente alla decisione di richiedere autenticazione per tutte le API REST. La route non viene
registrata negli altri ambienti e il middleware ricontrolla l'ambiente anche
in presenza di una cache route già generata. Nessuna modifica a schema,
modelli, servizi di scrittura o query web esistenti.

File aggiunti: routes/rest.php, Rest/V1/PersonController, PersonQuery,
Rest/V1/PersonResource e RestPersonRequest. Il caricamento per UUID e i due
conteggi separati riprendono le forme di query verificate; non è stato
introdotto withCount né estratta logica dai controller web.

Il prototipo restituisce la rappresentazione corrente, conteggi come la UI,
ruoli locali, dati remoti già noti e Problem Details per gli errori. Usa
ETag del JSON completo, Cache-Control private/no-cache e Vary Authorization,
con 304 se invariato e ancora disponibile. Throttle limitato alla route:
60 richieste al minuto per client. Non effettua richieste HTTP remote.

**Limiti espliciti di questo giro:**

- Autenticazione e guard Bearer rinviati; l'apertura dei remoti è una deroga
  locale temporanea, da chiudere per tutte le risorse REST secondo la sezione 6.
- CORS dedicato non implementato: la prova di base è via HTTP diretto.
- Last-Modified non emesso; If-Modified-Since non produce 304 senza una data
  completa affidabile. If-None-Match ed ETag sono operativi.
- Locali sospesi non restituiti (404), senza provare a risolvere qui il
  conflitto fra dati oscurati dalla UI e campi obbligatori della rappresentazione.
  Locali blocked, cancellati, Group, Feed e Application infrastrutturali locali
  sono esclusi. Application remote leggibili come nella UI sono rappresentate.

Le sezioni seguenti conservano la ricognizione e i criteri di completamento;
non descrivono tutti componenti già implementati.

### Verifiche del primo giro

- 33 test e 252 asserzioni superati: Rest/PersonTest, ProfileTest e ActorProfileTest.
- Pint e git diff --check superati.
- OpenAPI valido; JSON reali dei due profili skeyby@punk.kitchen e
  Skeybu@mastodon.uno validati sullo schema Person.
- Richieste attraverso il kernel HTTP con cache route attiva: 200 e successivo
  304 per entrambi i profili, senza fetch remoti.
- Cache route locale rigenerata per includere il nuovo GET. La configurazione
  attiva non è stata cancellata: i test usano APP_CONFIG_CACHE su un percorso
  temporaneo per evitare la configurazione MySQL in cache. Il primo tentativo
  dei test era stato fermato dalla protezione SQLite del TestCase; dopo tale
  tentativo il marker installed.lock risultava assente ed è stato ripristinato
  per l'istanza locale configurata prima della verifica HTTP.
- Suite completa non eseguita in questo giro; nessuna PR proposta.

### Obiettivo e contratto

Primo endpoint descritto in `docs/api/openapi.json`: GET su
`/rest/v1/persons/{personId}`, con UUID dell'Actor corrispondente e risposta
Person diretta. La bozza documenta 200, 304, 404, 429, 500 e 503, richieste condizionali
e Problem Details. Questo primo checkpoint era privo di autenticazione;
la sezione 12 descrive la successiva protezione OAuth di tutte le risorse REST.

**Accesso verificato nella UI:** `/@{username}` è pubblico; le route
`/attori/{actor}` sono invece nel gruppo auth in `routes/web.php`. È una
constatazione sul Web, non la regola di autenticazione REST: le API richiederanno
sempre login. L'OpenAPI corrente descrive lo schema OAuth implementato
nel successivo giro della sezione 12.
Un profilo non esistente, cancellato o estraneo alla risorsa Person dà 404.

### Flusso e componenti del prototipo

```text
bootstrap/app.php → routes/rest.php → Rest/V1/PersonController
                                      │
                                      ├─ caricamento Actor e relazioni esistenti
                                      ├─ accesso secondo le regole del profilo
                                      └─ Rest/V1/PersonResource → JSON + cache HTTP
```

I nuovi file sotto sono implementati; nessuna nuova logica di modellazione
è stata introdotta. Il caricamento e la rappresentazione rimangono separati.

| Punto di aggancio | Riuso e intervento |
| --- | --- |
| `bootstrap/app.php` | Registrare un file route REST separato con prefisso rest/v1. Conservare route web e ActivityPub. |
| `routes/rest.php` — nuovo | Dichiarare solo la prima route, con personId come UUID; non usare il binding User, che identifica per username. |
| `app/Http/Controllers/Rest/V1/PersonController.php` — nuovo | Coordinare caricamento, accesso, rappresentazione e risposta, senza chiamare i controller HTML. |
| `app/Federation/Actors/Actor.php` | Riusare chiave primaria, relazioni user/profile/settings/movedTo e helper activityPubId, displayName, avatarUrl, coverUrl, isSuspended, isRemotelySuspended. |
| `app/Http/Resources/Rest/V1/PersonResource.php` — nuovo | Mappare esplicitamente i campi del contratto. JsonResource con wrapping disabilitato solo per questa risorsa, non globalmente; nessun toArray indiscriminato dei modelli. |
| `app/Http/Middleware/RestPersonRequest.php` — nuovo | Limite local/testing, verifica InstallationLock ed errori Problem Details senza redirect HTML. Throttling del framework sulla route; CORS dedicato rinviato. |
| `app/Application/Queries/PersonQuery.php` — nuovo | Caricamento per UUID e conteggi come nei controller UI, senza cambiarli. |

### Dati: riuso effettivo

| Dati richiesti | Punto esistente da riusare |
| --- | --- |
| Identità, dominio, tipo e stato | Actor; esclusione di Group, Feed e attori infrastrutturali. InstanceRelayActor rappresenta il relay tecnico: non invocare getOrCreate durante una lettura. |
| Nome e immagini | Helper Actor già uniformi fra locale e remoto; per i locali usano il Profile associato. |
| Biografia | Profile.bio locale; testo dell'Actor remoto normalizzato con RemoteContentSanitizer::toPlainText come nella UI, senza PostBodyRenderer e senza HTML renderizzato. |
| Campi aggiuntivi | Profile.links locale oppure Actor.links remoto; ProfileFields::value gestisce anche il precedente formato url dei campi locali. |
| Data di iscrizione | User.created_at per i locali e Actor.published_at per i remoti, come nelle viste dei profili. |
| Preferenze pubbliche | Lettura dei valori correnti con gli stessi criteri di ActorSerializer; user.settings dove pertinente, Actor per i remoti. Non invocare servizi di aggiornamento. |
| Alias, migrazione, emoji, memorial e sospensione | Attributi e relazione movedTo già presenti. Normalizzazione di stato e sospensione soltanto nella rappresentazione REST. |
| Ruoli | User.is_admin e is_moderator per i locali; non inventare ruoli per i remoti. Nessun dato dell'account viene serializzato oltre i campi selezionati. |
| Conteggi | Stessi conteggi accepted della UI per i locali; valori Actor remoti con il fallback locale che già usa ActorProfileController. |

ActorSerializer è un riferimento per le fonti, non il serializer da esporre:
produce ActivityPub, carica chiavi ed endpoint e aggiunge campi esclusi dal
contratto. FollowListQuery restituisce elenchi paginati: non va invocata per
ottenere due numeri. QuotedActorResolver riguarda la condivisione privata
fra utenti e ammette solo Person attive; non è un resolver pubblico generico.

### Query e modifiche al Core: evidenza esplicita

**Non è emersa la necessità di cambiare modelli, schema, indici o SQL esistente
per leggere Person. Non esiste però un servizio che restituisca già il profilo
completo per UUID:** composizione e conteggi sono nei controller web.

Il nuovo ingresso REST usa un caricamento Eloquent per chiave
primaria, con eager loading delle relazioni necessarie, e gli stessi due
conteggi già eseguiti dalla UI. È una nuova chiamata di lettura, non una
riscrittura delle query del Core. Non si possono riusare direttamente i
resolver locali per username o il controller remoto con tutte le sue
operazioni collaterali.

Per tenere il controller sottile, il caricamento sta in PersonQuery,
un piccolo adattatore di lettura, senza nuovo modello di dominio. Un'eventuale
estrazione condivisa dei conteggi dai controller web sarebbe un refactor
aggiuntivo: va segnalato prima e non è necessario per avviare l'endpoint.

EXPLAIN FORMAT=TRADITIONAL eseguito sul MySQL locale per le forme di query
esistenti, con un Actor locale reale:

- Lookup Actor per ID: PRIMARY, accesso const, stima 1 riga.
- Count follower accepted: follows_following_id_status_index, accesso ref,
  Using index, stima 92 righe per il campione.
- Count following accepted: follows_export_index, accesso ref, Using index,
  stima 437 righe per il campione.

Non sono misure di latenza né una validazione di tutte le cardinalità.
I piani della query effettivamente implementata andranno ricontrollati se
cambia la forma di caricamento; nessun hint o indice nuovo è proposto qui.

### Agganci e vincoli da non nascondere

- **Autenticazione:** il guard Bearer/sessione REST non esiste ancora. Il
  guard sarà obbligatorio anche per il dettaglio locale pubblico, gli elenchi
  e le risposte 304; l'apertura anonima del prototipo deve essere chiusa.
  L'eventuale persistenza del login è un tema distinto dalla lettura Person
  e non è inclusa nella conclusione «nessuna modifica schema» sopra.
- **Profili oscurati:** LocalActorResolver esclude i locali blocked e ammette
  suspended; la vista locale sospesa nasconde bio, fields, conteggi e data.
  Il JSON non deve reintrodurre campi oscurati: quelli opzionali possono
  essere omessi, personPublishedAt può essere null nel contesto non visibile.
  La resa dei campi obbligatori va verificata prima dell'implementazione.
- **Lettura remota:** ActorProfileController avvia refresh del profilo,
  outbox, collezioni e contenuti in evidenza. Non richiamarlo dall'API.
  Per questa prima implementazione si propone di leggere i dati già noti,
  senza fetch o import impliciti; il refresh non è necessario per formare Person.
- **Cache:** ETag derivato dalla rappresentazione effettiva, dopo i controlli,
  usando le risposte condizionali del framework. Actor.updated_at da solo
  non copre profilo, ruoli e conteggi; Last-Modified va emesso solo se è
  disponibile una data affidabile per la risposta completa. Nessuna tabella
  di revisioni o scrittura obbligatoria è proposta per calcolare il validatore.

La prima implementazione dovrà verificare con test mirati forma JSON,
locali/remoti, rifiuto delle richieste anonime e accesso autenticato, cancellati e attori tecnici,
profili sospesi/oscurati, conteggi, assenza di dati privati, ETag e 304 dopo
revoca dell'accesso. L'OpenAPI aggiornato rimane documentazione del contratto,
non sostituisce i test del prototipo e dei successivi completamenti.

## 11. Primo giro degli elenchi followers/following

Gli endpoint restituiscono PersonReference, senza wrapper o totale. Il primo
ordinamento supportato è `sort=personId`, con `direction=asc` predefinita o
`desc`. `limit` vale 20 se omesso, minimo 1 e massimo 100; query non valide
restituiscono 422 con Problem Details e messaggi per parametro.
`after` è un UUID esclusivo confrontato secondo la direzione: non richiede
che la persona di riferimento sia ancora nell'elenco. Le altre convenzioni
di ordinamento generali restano da specificare per le altre risorse.

Il server carica al massimo `limit + 1` profili ammissibili, restituisce fino a
`limit` elementi e aggiunge `Link` con `rel="next"` soltanto se esiste un'altra
pagina. Filtri di disponibilità e deduplicazione precedono il limite.
ETag copre JSON e Link: anche un cambiamento della navigazione invalida la
risposta precedente. Disponibilità del titolare verificata prima di ogni 304.

**Selezione condivisa con il Web:** `FollowListQuery` costruisce le query dei
Follow accettati in entrambe le direzioni e dei membri risolti della cache
remota. Queste basi alimentano gli elenchi Web, la preview remota e il ramo
Web che mescola Actor e hashtag. Un metodo della stessa classe compone gli
identificativi dei membri noti, deduplicando grafo e cache per i remoti.

`PersonFollowQuery` è soltanto l'adattatore di consultazione REST: usa questi
identificativi, applica la disponibilità Person e la paginazione per ID. Non
conosce colonne o stati di Follow e RemoteCollectionMember. Il Web conserva
pagine numerate, ordinamento cronologico e preview separata; il REST conserva
elenco unico, `after`, `limit` e ordinamento per ID. I tipi Actor ammessi nelle
liste Web restano più ampi di Person; l'aggiornamento remoto resta nel flusso
Web e non viene invocato dalle query condivise.

Questa estrazione sostituisce la prima selezione duplicata del prototipo.
Una modifica ai predicati condivisi delle relazioni si applica a entrambi i
canali; modifiche di presentazione o ai filtri specifici Person restano
separate. Nessuna modifica a servizi di scrittura, schema o migrazioni.
Autenticazione, CORS e Last-Modified conservano i limiti del prototipo locale.

### Verifiche degli elenchi

- Dopo l’estrazione condivisa: 70 test e 452 asserzioni superati su Rest,
  FollowListTest, ProfileTest, ActorProfileTest, RemoteFollowCollectionsFetcherTest
  e HashtagFollowTest; include i precedenti test del dettaglio Person.
- Verificati array compatti, titolare diverso dall'utente autenticato,
  relazioni accettate, unione e deduplicazione della cache remota, assenza
  di fetch, esclusioni prima del limite, pagine asc/desc, riferimento rimosso,
  limite predefinito/massimo, 422, ETag e Link anche su 304.
- EXPLAIN sul MySQL locale per followers e following, sia di skeyby locale
  sia di Skeybu remoto: segue follows_following_id_status_index per followers
  e follows_export_index per following; nei due rami della UNION remota la
  cache usa remote_collection_members_actor_id_collection_member_uri_unique
  sul prefisso actor_id/collection. I profili sono raggiunti tramite PRIMARY.
  Il piano ordina i membri della persona, senza scansione globale degli Actor;
  per la persona locale le stime dei rami Follow sono 92 e 437 righe.
  La UNION materializza il risultato deduplicato. Questi sono piani sul campione,
  non una misura di latenza o una prova su tutte le cardinalità.
- OpenAPI e relativo esempio PersonReference validati. Quattro risposte reali
  ottenute attraverso il kernel HTTP locale (followers/following dei due
  account) validate sullo schema PersonReference; cache route rigenerata.
- Verificato il confronto tra liste Web e REST per le stesse relazioni remote:
  la preview Web esclude i duplicati e conserva i Group; il REST restituisce
  il corrispondente sottoinsieme Person. Il ramo misto Web continua a escludere
  i Follow pending anche in presenza di hashtag.
- Confrontato l'SQL Web generato prima e dopo l'estrazione: identico per
  followers, following, following con hashtag e preview nelle due direzioni,
  sia sul campione locale sia remoto. EXPLAIN ricontrollato su query effettive
  Web, entrambi i rami della UNION ALL con hashtag e query REST con after in
  asc/desc. Nessun nuovo indice: i rami hashtag usano PRIMARY su actor_id;
  le preview remote usano l'indice actor_id/collection/position. Per after il
  piano può scegliere range su follows_export_index o PRIMARY degli Actor,
  in funzione del campione; i rami della UNION REST restano indicizzati.
- Pint e git diff --check superati. Test eseguiti con APP_CONFIG_CACHE su
  percorso temporaneo, senza utilizzare il database locale per le fixture.

## 12. OAuth 2 standard con Passport: decisione corrente

Si riparte dal checkpoint pulito `939c1e8`. Sono approvati OAuth 2,
Authorization Code con PKCE S256, registrazione dei client e callback.
Il protocollo temporaneo con polling è scartato. Nessun ulteriore tentativo
Sanctum o protocollo alternativo: priorità alla compatibilità delle librerie.

### Perimetro del primo giro

- Laravel Passport standard per emissione/scambio/rinnovo/verifica dei token,
  codici monouso e controlli client/redirect. Guard REST sul modello User esistente.
- Client pubblici per mobile e SPA: nessun secret statico distribuito. Login
  Web esistente e semplice conferma dell'app; callback con codice, mai Bearer.
- PKCE S256 obbligatorio nelle richieste di autorizzazione. Lo generano le
  librerie client, senza passaggi manuali per l'utente o polling.
- Client registrabili con comando Passport e con endpoint pubblico RFC 7591,
  limitato ai client pubblici Authorization Code/refresh. Il pacchetto non offre
  questo endpoint di serie: piccolo adattatore di validazione/persistenza sui
  suoi modelli, senza implementare un altro motore di autenticazione.
- Metadata OAuth RFC 8414: endpoint, capacità e registrazione pubblicati.
  Non si dichiara supporto OpenID Connect o ID token non implementati.
- CORS per le API Bearer e gli endpoint pubblici necessari ai client browser;
  nessun accesso REST tramite cookie o credenziali browser condivise.
- Tutte le risorse REST richiedono Bearer e account attivo prima anche dei 304.
  Le autorizzazioni correnti del Core restano sul server. Nessuna funzione staff.
- Durata di access token e refresh token configurabile con
  OPENBOOK_OAUTH_TOKEN_DURATION in giorni interi positivi; assente, vuota o
  non valida usa 365 giorni. Si applica solo ai nuovi token, inclusi quelli
  emessi al rinnovo; quelli esistenti mantengono la scadenza. Configurazione
  letta tramite config/openbook.php, compatibile con config:cache.
  Unico scope fisso api, nessun selettore di scope granulari. Gestione degli accessi
  nel profilo descritta nella sezione 13;
  revoca sul DB già verificata dal pacchetto. Logout Web distinto da OAuth.
- Migration standard OAuth con riferimenti User e owner UUID compatibili con
  OpenBook. Nessuna modifica a tabelle dei contenuti. Chiavi OAuth distinte da
  ActivityPub; setup locale con comandi standard. Local/testing finché il giro
  di prova non è completato; integrazione installer/updater produzione rinviata.

### Registrazione e client

Un client_id identifica una registrazione, non un account utente o un telefono.
Può essere condiviso da più installazioni se lo sviluppatore lo distribuisce;
la registrazione dinamica indipendente crea invece un client per registrazione.
Non si promette deduplicazione automatica per nome o URL: non identificano
in modo affidabile la stessa applicazione. Le app conservano il client_id e
lo riusano; non si registrano a ogni login. Endpoint di registrazione con rate
limit, redirect validati, nessun fetch di URL o metadati remoti.

### Librerie e criterio di verifica

AppAuth-iOS e AppAuth-Android gestiscono Authorization Code/PKCE e registrazione
dinamica. oidc-client-ts supporta OAuth 2 senza obbligo di OIDC; per registrare
il client React serve prima un normale POST. Configurare endpoint OAuth e scope
api, senza richiedere openid o ID token. La prova con oidc-client-ts ha mostrato
che la libreria richiede uno scope non vuoto; api indica l’accesso ordinario
alle API, senza una nuova scelta o suddivisione dei permessi per l’utente.
Il flusso effettivo è verificato con questa libreria JavaScript, oltre ai test HTTP.
Non si dichiara test su dispositivo iOS/Android se non eseguito.

Test: login esistente, consenso/annullamento, PKCE, callback/state, codice
monouso, refresh, credenziali invalide/scadute/revocate, account non attivo,
protezione dei 304, registrazione RFC 7591, metadata, CORS e UUID/indici MySQL.

### Verifiche eseguite

- Suite completa: 2.116 test, 15.547 asserzioni, nessun fallimento; 130 test
  saltati. Dopo l'aggiunta del controllo sull'ordine dei middleware, suite REST
  mirata: 28 test e 419 asserzioni superati.
- Flusso HTTP completo con oidc-client-ts 3.5.0: registrazione dinamica,
  PKCE/state generati dalla libreria, login e consenso con CSRF, callback,
  scambio tramite SDK e letture REST 200/304/401. Browser simulato tramite
  HTTP, non test grafico o su dispositivo iOS/Android.
- Migration OAuth applicate sul MySQL locale; UUID verificati. EXPLAIN dei
  lookup di token/client/utente/codice usa le chiavi primarie; la ricerca dei
  token autorizzati per utente/client usa oauth_access_tokens_user_id_index.
  Prova HTTP MySQL 200/304/401 con dati OAuth temporanei in rollback.
- OpenAPI validato, Composer validate, Pint e git diff --check superati.
  Nessun aggiornamento di dipendenze preesistenti: aggiunti Passport e le sue
  dipendenze. Chiavi locali generate senza sovrascrivere chiavi esistenti;
  cache configurazione e route aggiornate.

## Appendice A. Percorsi già concordati

Percorsi relativi a `/rest/v1`; non sono un inventario completo. Gli elenchi
rispettano sempre visibilità e limiti dei dati conosciuti dall'istanza.
Tutti i percorsi REST di contenuto richiedono login, inclusi cataloghi e stato
delle relazioni. I nomi definitivi dei campi restano da definire.

| Area | Percorsi e metodi |
| --- | --- |
| Contesto personale | `GET /me`, con nodo `person`; `GET /me/notifications`, array di Notification con la paginazione comune. |
| Ownership | `GET /me/posts`, `GET /me/events`, `GET /me/communities`: pubblicati/creati dall'utente. |
| Commenti | Sotto `/posts/{postId}/comments` e `/events/{eventId}/comments`, con dettaglio `/{commentId}`; metodi specifici da completare. |
| Media | Risorsa indipendente `/media/{mediaId}`; operazioni da completare. |
| Persone | `/persons/{personId}`; GET su `/persons/{personId}/followers` e `/following`, collezioni di PersonReference. |
| Follow persone | GET/PUT/DELETE su `/persons/{personId}/follow`: stato, follow/richiesta e rimozione/ritiro. |
| Richieste ricevute | `GET /me/follow-requests`; PATCH/DELETE su `/me/follow-requests/{personId}` per accettare/rifiutare. |
| Community | GET su `/communities`, `/communities/{communityId}`, `/communities/{communityId}/posts` e `/communities/{communityId}/followers`; iscritti come Person. |
| Follow community | GET/PUT/DELETE su `/communities/{communityId}/follow`: stato, iscrizione/richiesta e rimozione/ritiro. |
| Eventi | Catalogo `/events` e dettaglio `/events/{eventId}`, operazioni da completare. |
| Interesse eventi | GET/PUT/DELETE su `/events/{eventId}/follow`: stato, «mi interessa», rimozione. |
| Partecipazione eventi | GET/PUT/DELETE su `/events/{eventId}/participation`: stato, «parteciperò» o richiesta, ritiro. |
| Persone degli eventi | GET su `/events/{eventId}/followers` per gli interessati e `/participants` per i confermati, entrambi collezioni di Person. |
| Hashtag | GET su `/hashtags`, `/hashtags/{tag}` e `/hashtags/{tag}/posts`; GET/PUT/DELETE su `/hashtags/{tag}/follow`. |

Le raccolte di seguiti si ottengono tramite `relationship` sui cataloghi.
Non sono previsti `/me/hashtags`, `/me/following` o `/me/followers` come alias.
Un follow non esistente ha uno stato leggibile esplicitamente. PUT restituisce
lo stato risultante, senza implicare approvazione immediata. Payload ed esiti
nei casi già gestiti o concorrenti saranno specificati successivamente.

## Appendice B. Riferimenti tecnici

Fonti di approfondimento, senza obbligo di adottare ogni meccanismo:

- [HTTP Semantics — RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html),
  [PATCH — RFC 5789](https://www.rfc-editor.org/rfc/rfc5789.html),
  [HTTP Caching — RFC 9111](https://www.rfc-editor.org/rfc/rfc9111.html).
- [Web Linking — RFC 8288](https://www.rfc-editor.org/rfc/rfc8288.html).
- [Problem Details — RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html).
- [OAuth 2.0 — RFC 6749](https://www.rfc-editor.org/rfc/rfc6749.html),
  [Native Apps — RFC 8252](https://www.rfc-editor.org/rfc/rfc8252.html),
  [Security Best Practice — RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html).
- [Token Revocation — RFC 7009](https://www.rfc-editor.org/rfc/rfc7009.html),
  [Token Introspection — RFC 7662](https://www.rfc-editor.org/rfc/rfc7662.html),
  [OpenID Connect Core](https://openid.net/specs/openid-connect-core-1_0.html).

## 13. Applicazioni collegate nelle impostazioni

Intervento approvato: una scheda «Applicazioni collegate» nelle impostazioni
personali, con accessi aperti e comando «Chiudi questo accesso». Nessun token,
client_id, scope o altro dettaglio di protocollo nell'interfaccia.

Si riusano i modelli Passport, senza nuove tabelle o modifiche alle query dei
contenuti. Ogni coppia corrente access token/refresh token è un accesso distinto;
il rinnovo standard sostituisce la coppia precedente. Si mostrano nome dell'app,
data di creazione dell'accesso corrente e scadenza, nel fuso dell'utente. Non si
inventano dispositivo o ultimo utilizzo: non sono registrati dal prototipo.

Sono inclusi accessi utilizzabili o ancora rinnovabili; quelli chiusi, scaduti
o appartenenti a client revocati sono esclusi. Chiusura limitata all'utente
corrente e alla singola coppia, con revoca di entrambi i token nella stessa
transazione. Il rinnovo standard viene eseguito in transazione e blocca la
coppia corrente nello stesso ordine della chiusura, prima della verifica del
refresh: una chiusura concorrente non deve lasciar creare nuovi token. Non si
modifica il protocollo o la logica di emissione Passport. Se nel frattempo il rinnovo ha sostituito l'accesso visualizzato,
si aggiorna l'elenco e si chiede di ricontrollarlo: non si dichiara chiusa una
coppia differente. Gli altri accessi e la sessione Web rimangono indipendenti.

La scheda segue il perimetro local/testing di OAuth: non deve interrogare
le tabelle OAuth in produzione. Form Web con CSRF, paginazione e nomi app
sottoposti a escaping. Nessuna cancellazione di registrazioni o dati utente.

Verifiche previste: isolamento tra utenti, più accessi della stessa app,
revoca di accesso e rinnovo, esclusione delle coppie non più valide,
paginazione, date, ambiente di produzione e piani MySQL reali.

### Esito delle verifiche della sezione 13

- Suite completa: 2.127 test, 15.624 asserzioni, nessun fallimento; 130 test
  saltati. Suite mirata con impostazioni, gestione accessi, migrazione account,
  import/export e REST: 66 test e 700 asserzioni superati.
- Prova SDK con HTTP e CSRF reali estesa alla pagina: chiusura accesso,
  Bearer rifiutato, refresh rifiutato e sessione Web ancora aperta.
- MySQL: lista, revoca di entrambe le credenziali e gestione della ripetizione
  verificate con dati in rollback. EXPLAIN usa user_id per limitare gli accessi,
  access_token_id per i refresh associati e le PK per i lock; sui due client
  della fixture l'optimizer sceglie una scansione della minuscola tabella client.
  Nessun nuovo indice giustificato su questo access pattern per utente.
- La suite completa ha rilevato il riuso della barra delle schede nelle pagine
  di migrazione/import: corretto il passaggio della nuova scheda affinché non
  richieda variabili aggiuntive a quelle pagine. Test di regressione superati.
- Pint e git diff --check superati. Cache delle route locali aggiornata.
  Nessuna nuova migration per la gestione degli accessi. Accesso Paw esistente
  lasciato invariato. Nessun test grafico o su dispositivo nativo eseguito.

### Durata configurabile dei token

OPENBOOK_OAUTH_TOKEN_DURATION controlla in giorni la durata dei nuovi access
e refresh token. Default implicito: 365; valori vuoti, non interi o non
positivi usano il default. Nessuna modifica alle scadenze già persistite.
Verificati emissione, rinnovo e fallback con 45 test REST/gestione accessi
(572 asserzioni), oltre al caricamento effettivo da ENV con config:cache e
valore personalizzato di 7 giorni. Cache locale rigenerata con default 365.

### Review prima del commit

Riviste tutte le modifiche del giro OAuth e della gestione accessi. Corrette
la validazione delle liste JSON nella registrazione e la gestione dei valori
booleani nella durata configurata; allineati testi e traduzioni del consenso.
Nessuna raccolta IP o GeoIP introdotta.

Suite completa: 2.137 test e 15.733 asserzioni, nessun fallimento, 130 saltati.
Superati SDK OAuth reale con chiusura accesso/CSRF, controlli MySQL con rollback
e EXPLAIN, validazione OpenAPI/Composer, Pint e git diff --check. Le chiavi e
le credenziali di prova rimangono fuori dal repository. Il perimetro rimane
local/testing; integrazione con installer/updater di produzione ancora esclusa.
