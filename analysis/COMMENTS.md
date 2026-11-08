# Commenti: modifica Web, Update ActivityPub e risorsa REST

Data: 8 novembre 2026. Ramo: `comments`.
Stato: implementato e verificato. Contratto e perimetro approvati.

## 1. Obiettivo e perimetro

Permettere all'autore di modificare i propri commenti dalla UI, pubblicare il
corrispondente Update ActivityPub e introdurre lettura, creazione, modifica e
cancellazione REST sotto `/rest/v1/posts/{postId}/comments`.

Riferimento: `analysis/REST_SERVICE.md`, in particolare entità, scritture,
paginazione, autorizzazioni e roadmap. La roadmap conferma raccolte figlie di
Post e, in futuro, Event: non esiste ancora un contratto Comment dettagliato.
Questo documento definisce il contratto implementato nel ramo.

Perimetro confermato dall'utente: solo commenti ai post, modifica del solo
testo con conservazione degli allegati esistenti e creazione REST inizialmente
testuale. Modifica Web dei commenti agli eventi e gestione degli allegati sono
escluse. REST degli eventi, like dei commenti e upload indipendente non sono
inclusi nella richiesta CRUD dei commenti ai post.

Sono presenti modifiche locali preesistenti a changelog, federazione, profilo
attore e Debugbar. Non appartengono a questo lavoro e non sono state modificate.

## 2. Stato iniziale rilevato durante l'esplorazione

| Area | Stato attuale e conseguenza |
| --- | --- |
| Modello | `Domain/Comments/Comment` contiene post radice, padre facoltativo, autore, testo, lingua, emoji, allegati, contatori e `edited_at`. Quest'ultimo ha già cast datetime, ma non è fillable: il servizio dovrà salvarlo esplicitamente. |
| Scrittura | `CommentComposer::compose` crea commenti/risposte, allegati, menzioni, notifiche e contatori. Non esiste un metodo di modifica. |
| Autorizzazione | `CommentPolicy::update` controlla soltanto l'utente dell'autore; va completata con località e stato pubblicato. Nessuna delega di modifica allo staff. La cancellazione Web consente oggi anche l'admin: REST non erediterà questa delega. |
| Web | `CommentController` ha show/store/destroy; mancano edit/update e relative route e pagina. Il menu è condiviso da dettaglio post e thread focalizzato mediante `comments/_comment.blade.php`. |
| Composer | `composer/form.blade.php` è condiviso, ma riconosce l'editing soltanto per Post. Anche il bypass del Gate di creazione è legato a editingPost: adattarlo per consentire modifica di contenuti esistenti anche senza verifica email, come previsto dal Core. |
| ActivityPub in uscita | `NoteSerializer::forComment` emette già `updated`, mantiene `inReplyTo` e costruisce audience dal post e dalla community. `ActivitySerializer::update` accetta già Comment. `ActivityDelivery::deliverContent` gestisce commenti, community private, destinatari diretti e relay; i job sono afterCommit. |
| ActivityPub in ingresso | `InboxActivityProcessor::handleUpdate` passa dalle normali Note a `RemoteNoteUpserter::upsertComment`. Il percorso di aggiornamento esiste già; `edited_at` remoto usa l'ora locale di aggiornamento e i tag sono collegati solo alla prima creazione. Sono caratteristiche preesistenti, non una ragione per riscrivere l'inbox in questo sprint. |
| Cancellazione | Riutilizzare `LocalContentDeleter`, che mantiene soft-delete, contatori e Delete federato. Non introdurre un secondo percorso REST. |
| REST | Nessuna route o risorsa Comment esiste. `Rest/V1/PostController` fornisce il modello per JSON rigoroso, ownership, risposte, cache e Location. |
| Visibilità | I commenti ereditano quella del post. Riutilizzare `Post::visibleTo`, esclusione delle conversazioni e stato pubblicato; verificare separatamente appartenenza del commento. |
| Indici | La migrazione iniziale contiene `(post_id, created_at)` e `parent_comment_id`. Non dedurre da questo che i piani della nuova paginazione siano già adeguati. |

## 3. Modifica condivisa e UI

`CommentComposer::update` implementa la modifica senza duplicarla nei controller.
Il servizio controlla autore, contenuto locale e pubblicato, acquisisce il lock
del commento e ripete i controlli sul record corrente nella transazione.
Aggiorna testo ed `edited_at`, sincronizza le menzioni e conserva autore,
identificativo, post radice, padre, data originale, lingua, emoji e allegati.
Non modifica contatori o struttura delle risposte. Un salvataggio con testo
identico può restituire il commento senza nuovo timestamp o Update.

La sincronizzazione delle menzioni deve rimuovere quelle non più presenti,
aggiungere le nuove e notificare soltanto nuove menzioni locali; non ripetere
notifiche di commento/risposta. Riutilizzare parser e convenzioni dei post,
evitando un refactor generale dei servizi per questa sola estensione.

La UI mostra **Modifica** immediatamente prima di **Elimina**, solo se la
policy lo consente, anche nel thread focalizzato. È presente una pagina di modifica
analoga a quella dei post, con testo precompilato, Salva e Annulla/ritorno al
commento. Riusare il composer esistente con un contesto di editing esplicito
per Comment, evitando di spacciarlo per Post. Il testo modificato mostra
l'indicazione di modifica, usando le traduzioni esistenti quando appropriate.

Route Web: `GET /commenti/{comment}/modifica` e
`PUT /commenti/{comment}`. Autorizzazione e visibilità anche sulle richieste
dirette, non soltanto sul menu. Validazione della lunghezza configurata e di
testo non vuoto; nessun input permette di cambiare padre o post.

La verifica email resta necessaria per nuove creazioni, non per modifica o
cancellazione di contenuti esistenti. Un commento locale su post remoto resta
modificabile dal suo autore. Nessuna modifica di copie remote.

## 4. Federazione dell'aggiornamento

Invocare `ActivitySerializer::update` e `ActivityDelivery::deliverContent`
attraverso il servizio, con autore del commento/post a cui si risponde come
destinatario aggiuntivo, seguendo `compose`. Restano stabili URI della Note,
autore, `published` e `inReplyTo`; cambia il contenuto e compare `updated`.
L'audience segue le regole correnti del post, senza parametro REST autonomo
per la visibilità del commento.

Verificare public, unlisted, followers, community private e relay. Nessun
invio ai relay per contenuti non pubblici, nessun fan-out ai follower personali
per community private. La consegna segue il normale commit e i normali retry;
successo HTTP non promette ricezione remota già completata.

La consegna di menzioni e gruppi deve seguire il comportamento del Core:
non ampliare globalmente i destinatari di tutti i contenuti in questo lavoro.
Eventuali problemi preesistenti di audience emersi dai test vanno valutati
prima di estendere il cambiamento.

## 5. Contratto REST

Tutti i percorsi seguenti hanno prefisso `/rest/v1`; Bearer e scope `api`,
account/Actor attivi, nessuna delega amministrativa. Oggetti e array diretti,
senza wrapper `data`. Testi disponibili nel Core, senza nuovo rendering HTML.

| Metodo e percorso | Esito |
| --- | --- |
| `GET /posts/{postId}/comments` | 200, lista paginata di commenti pubblicati, incluse risposte di qualsiasi livello. |
| `POST /posts/{postId}/comments` | 201, Comment e Location del dettaglio. |
| `GET /posts/{postId}/comments/{commentId}` | 200, singolo Comment. |
| `PATCH /posts/{postId}/comments/{commentId}` | 200, Comment aggiornato; solo proprio commento locale pubblicato. |
| `DELETE /posts/{postId}/comments/{commentId}` | 204; solo proprio commento locale pubblicato. Una successiva richiesta restituisce 404, coerentemente con Post. |

### Comment

| Campo | Tipo | Obbligatorio | Nullable | Significato |
| --- | --- | --- | --- | --- |
| `commentId` | string UUID | Sì | No | Identificativo nell'istanza. |
| `commentUri` | string URI | Sì | No | URI canonico del commento. |
| `commentPostId` | string UUID | Sì | No | Post radice della discussione. |
| `commentParentId` | string UUID | Sì | Sì | Commento padre; null per primo livello. Può riferire un commento successivamente cancellato. |
| `commentAuthor` | PersonReference | Sì | No | Autore, usando la risorsa compatta esistente. |
| `commentContent` | string | Sì | No | Testo disponibile nel Core; preservare la rappresentazione locale/remota. |
| `commentPublishedAt` | string date-time | Sì | No | Pubblicazione originale. |
| `commentEditedAt` | string date-time | No | Sì | Ultima modifica nota; omesso se assente, come Post. |
| `commentLanguage` | string | No | Sì | Lingua quando disponibile; omissione se assente. |
| `commentCustomEmojis` | object | No | No | Mappa delle emoji, omessa se vuota. |
| `commentAttachments` | array di Media | No | No | Allegati in ordine, leggibili anche senza upload REST. |
| `commentCounters.commentLikesCount` | integer | Sì | No | Conteggio Mi piace disponibile. |
| `commentCounters.commentRepliesCount` | integer | Sì | No | Conteggio risposte disponibile. |
| `commentMyInteractions.commentLiked` | boolean | Sì | No | Mi piace del Bearer, annotato senza query per singolo elemento. |

I contenitori `commentCounters` e `commentMyInteractions` sono obbligatori e
non nullable. Non esportare stato interno, timestamp tecnici o modelli completi.
I contatori sono quelli disponibili nel Core; la raccolta federata può essere
parziale e non promette conteggi globali esatti.

### NewComment e PatchComment

`NewComment` è indipendente dalla risposta: richiede soltanto
`commentContent` (stringa non vuota, massimo configurato per i commenti).
`commentParentId` è facoltativo e nullable: omesso/null crea un commento al post,
UUID crea una risposta a un commento pubblicato dello stesso post.

`PatchComment` accetta soltanto `commentContent`, obbligatorio e non nullable.
Corpo JSON oggetto, proprietà sconosciute rifiutate, nessun cambiamento di padre,
post, autore, data o visibilità. Nessun upload o sostituzione allegati in questa
versione concordata; i commenti con allegati restano modificabili nel testo.

### Lista, cancellati e refresh remoto

Lista piatta, non albero ricorsivo senza limiti; il client ricostruisce
le relazioni con `commentParentId`. I cancellati non vengono restituiti, ma le
risposte pubblicate restano disponibili anche quando il padre è cancellato.
Il dettaglio del padre cancellato dà 404; non esporre testo o allegati cancellati.

`limit` predefinito 20, massimo 100; `sort=commentPublishedAt`,
`direction=asc` predefinito oppure `desc`, spareggio per ID nella stessa
direzione. `after` indica il commentId dell'ultima voce; deve appartenere alla
raccolta visibile e pubblicata, altrimenti 422, come le raccolte semplici REST.
Link next standard completo; niente `page` o `cursor`. La modifica del testo
non sposta la voce. Non promettere uno snapshot immutabile.

Per post remoto riusare `RemoteRepliesFetcher` e TTL come il dettaglio Post,
senza un nuovo crawler; ricontrollare disponibilità dopo il refresh e prima
del 304. Errori remoti mantengono i dati disponibili in cache. L'eventuale
refresh non garantisce completezza né aggiornamento di ogni commento remoto.

## 6. Controller, autorizzazioni ed errori

`Rest/V1/PostCommentController` espone index/show/store/update/destroy con
`CommentResource`. La raccolta usa una query limitata a un singolo post, secondo
lo stesso approccio dei controller REST esistenti.
Controller sottili: validazione/mapping, autorizzazione, servizi condivisi,
risposta HTTP. Riutilizzare helper REST di errori e risposte condizionali.

Ogni metodo risolve prima il post leggibile e pubblicato, escludendo le
conversazioni; poi cerca il commento nel perimetro di quel post. UUID invalidi,
risorse assenti/invisibili, commenti sotto un post diverso e ownership non
corrispondente danno 404. Nessuna ricerca globale del commento seguita da
serializzazione prima del controllo del padre.

POST controlla anche appartenenza e stato del commento padre, prima del
servizio: UUID non ammissibile nel campo di input dà 422 senza dettagli di
contenuti altrui. `CommentComposer` mantiene ContentCreationGuard e i blocchi
sulle nuove interazioni con remoti sospesi. PATCH/DELETE controllano ownership
esplicita, policy, località e stato corrente nella transazione. La sospensione
remota non diventa automaticamente un divieto di gestione di contenuti esistenti.

Scelta concordata: POST su post direct restituisce 409. Le altre operazioni
restano subordinate alla visibilità del post; le conversazioni private sono
sempre escluse dalle API Comment.

Seguire gli errori esistenti: 400 JSON malformato, 401 autenticazione,
403 divieti di account/verifica email e interazioni, 404 risorsa non disponibile,
415 formato non JSON, 422 input invalido, 429 throttle. Letture con cache privata,
ETag, Vary Authorization e 304 solo dopo i controlli; scritture/errori no-store.

## 7. Stadi di implementazione concordati

1. Contratto approvato: solo post, solo testo con allegati conservati, POST
   direct escluso con 409. Analisi e rimando alla roadmap REST aggiornati.
2. Servizio condiviso di modifica, policy e test di dominio/federazione.
3. Menu, pagina di modifica e riuso composer; verifica nei due contesti Web,
   errori di validazione, annullamento e dimensioni mobile.
4. Risorsa, raccolta e cinque operazioni REST; test di contratto e autorizzazione.
5. OpenAPI inglese con Comment/NewComment/PatchComment, campi, vincoli,
   esempi sintetici e tutte le risposte. Guide API inglese/italiana e guide
   utente pertinenti aggiornate insieme; changelog dopo lettura della regola.
6. Piani MySQL effettivi, review completa e suite completa prima della proposta
   di PR. Il push non rientra nella consegna.

## 8. Verifiche previste e baseline

- Proprietà: autore, altro utente, admin, remoto, cancellato e commento sotto
  altro post; tentativi diretti oltre alla presenza del menu.
- Visibilità: public/unlisted/followers, community privata, post cancellato,
  conversazioni e disponibilità mutata dopo refresh/prima del 304.
- Creazione/risposte: verifica email, padre estraneo o cancellato, lunghezza,
  contatori/notifiche e blocchi dei remoti sospesi.
- Modifica: contatori e legami invariati, allegati conservati, menzioni
  aggiunte/rimosse, nessuna duplicazione reply/comment notification, salvataggio
  identico e concorrenza con cancellazione.
- Federazione: payload Update, URI e published stabili, updated/inReplyTo,
  destinatario padre, follower/community/relay, nessun job in rollback;
  verificare anche il percorso di ricezione già presente senza riscriverlo.
- REST: JSON rigoroso, status/Location, paginazione con timestamp uguali,
  ancore estranee/cancellate, ETag aggiornato dopo edit, cache e assenza N+1.
- MySQL: EXPLAIN per lista iniziale e dopo ancora in entrambe le direzioni,
  lookup padre e ownership; valutare indici sulla query effettiva e dati
  rappresentativi, senza assumere sufficiente l'indice già presente.
- Validazione OpenAPI, esempi, corrispondenza route/guide, traduzioni e review
  del diff; suite completa alla fine dell'implementazione.

Baseline eseguita durante l'esplorazione, senza modifiche applicative:

```sh
APP_CONFIG_CACHE=/tmp/openbook-comments-analysis-config.php php vendor/bin/phpunit tests/Feature/Comments tests/Feature/Rest/PostMutationTest.php tests/Feature/Federation/RemoteNoteUpserterTest.php
```

Esito: **51 test, 409 asserzioni, tutti superati**, PHP 8.5.12, PHPUnit 11.5.57.
Non è stata eseguita la suite completa, né EXPLAIN MySQL o interoperabilità live:
la nuova implementazione e le nuove query non esistono ancora.

## 9. Implementazione e verifiche del ramo

Implementati servizio condiviso, autorizzazione, route e pagina Web, menu e
indicazione di modifica; cinque operazioni REST con CommentResource,
paginazione, visibilità/ownership, ETag e validazione rigorosa. Aggiornati
OpenAPI (Comment/NewComment/PatchComment), guide API e utente inglese/italiana,
federazione, traduzioni inglese/italiana/finlandese e changelog.
Nessuna nuova migrazione o dipendenza applicativa.

Verifiche concluse:

- Nuovi test Web/REST/federazione: proprietà, preservazione degli allegati,
  notifiche e menzioni, contatori, risposte annidate, direct, email verificata,
  cookie senza Bearer, cache, paginazione e numero di query indipendente dal
  numero di commenti. Le consegne sono simulate, con verifica del payload e
  del flag afterCommit; non è una prova di interoperabilità live.
- Browser: menu Modifica prima di Elimina, testo precompilato, annullamento e
  salvataggio con CSRF/PUT, nessun upload nel modulo; 1280 e 375 pixel, con e
  senza JavaScript. Screenshot ispezionati; nessun overflow o errore JavaScript.
  La fixture usa viste, CSS e script reali con SQLite isolato; i POST del browser
  sono intercettati, mentre la persistenza è coperta dai test HTTP PHPUnit.
- OpenAPI valida e 502 esempi validi; tutte le cinque route corrispondono alla
  specifica. Gli schemi precedenti restano invariati.
- MySQL locale 26.10.0: test opt-in `PostCommentMysqlTest`, schema casuale creato,
  migrato e rimosso nel finally; 12000 commenti su 10 post. EXPLAIN delle query
  realmente eseguite dalle route conferma `comments_post_id_created_at_index`
  per liste e paginazione asc/desc, `PRIMARY` per lookup/ownership/padre;
  nessuna scansione completa o filesort per le liste. **1 test, 44 asserzioni**.
  Nessuna modifica agli indici necessaria. Nessuna istanza MariaDB separata.

Comandi riproducibili aggiuntivi:

```sh
APP_CONFIG_CACHE=/tmp/openbook-comments-tests-config.php php vendor/bin/phpunit tests/Feature/Comments/CommentUpdateTest.php tests/Feature/Rest/PostCommentTest.php
OPENBOOK_TEST_COMMENTS_MYSQL=1 APP_CONFIG_CACHE=/tmp/openbook-comments-mysql-config.php php vendor/bin/phpunit tests/Feature/Rest/PostCommentMysqlTest.php
node tests/Browser/comments.cjs
```

Il test MySQL legge OPENBOOK_TEST_MYSQL_HOST/PORT/USERNAME/PASSWORD e richiede
un account autorizzato a creare/rimuovere solo lo schema temporaneo. Il test
browser richiede Playwright disponibile e un browser compatibile; opzionale
BROWSER_EXECUTABLE_PATH, come gli altri test browser del progetto.

Esito finale: **2433 test, 19103 asserzioni, 132 skipped, nessun errore o
fallimento** nella suite completa. Nuovi test mirati: **19 test, 170 asserzioni**;
verifica MySQL separata: **1 test, 44 asserzioni**. Pint e diff --check superati.
Review completa del cambiamento senza problemi bloccanti individuati; modifiche
preesistenti conservate. Cache route locale ricostruita (era presente una cache
precedente senza le nuove route). Il push non rientra nella consegna.

Limiti: nessuna prova di consegna con un server federato reale; nessuna istanza
MariaDB separata. I 132 test condizionali saltati restano esclusi dal risultato
eseguito e non sono dichiarati verificati.

## 10. Review prima del commit

Rivisto l'intero insieme non committato, comprese le modifiche preesistenti a
emoji nei campi dei profili remoti e configurazione/test della Debugbar.
Controllati ownership senza delega admin nelle API, associazione post/commento,
visibilità prima di dati/304, transazioni e stato pubblicato, allegati e
notifiche, escaping del contenuto remoto e disattivazione Debugbar fuori da
local/debug. Nessun problema bloccante o refactor aggiuntivo necessario.

Confronto OpenAPI: tutti i percorsi e gli schemi precedenti sono invariati;
le aggiunte sono limitate alle due forme di percorso e ai tre schemi concordati.
La cache degli autori proposta durante l'analisi delle query duplicate non è
stata introdotta, secondo la decisione dell'utente: il caricamento corrente
raggruppa già gli autori e non esegue query per ciascun commento.
