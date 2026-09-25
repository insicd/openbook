# Linked Data / inbox fixes — analisi

Documento di analisi. Raccoglie le evidenze e le decisioni emerse durante il
lavoro su inbox e Delete degli Actor remoti. Le descrizioni dello "stato
attuale" riportano la situazione osservata all'avvio dell'analisi; le sezioni
successive registrano le scelte e gli interventi implementati.

## Contesto

Nei log di produzione compaiono tre famiglie principali:

1. `federation.ld_signature.rejected` con `normalize_failed`;
2. successivo `federation.inbox.forward_rejected` con
   `origin_fetch_failed`, soprattutto per attivita' `Delete` Mastodon;
3. `federation.inbox.rejected` per impossibilita' di recuperare la chiave
   pubblica del firmatario.

Una `ld_signature.rejected` isolata non implica necessariamente il rifiuto
dell'intera richiesta: dopo il fallimento LD Openbook tenta un refetch
same-origin. Il rifiuto finale avviene quando falliscono entrambi i metodi e
`InboxController` restituisce HTTP 401.

## Evidenze sul forwarding

- Molti activity id terminano in `#delete` o `#updates/<timestamp>`.
- I fragment `#...` non vengono trasmessi in una richiesta HTTP. Un GET di
  `https://host/status/1#delete` raggiunge quindi `/status/1`.
- Dopo un Delete la risorsa puo' gia' rispondere 404/410; se risponde con la
  Note originale, id e tipo non coincidono comunque con l'attivita' Delete.
- Il fallback di refetch e' quindi strutturalmente debole per Delete e Update
  identificati tramite fragment. Non va compensato accettando attivita' non
  autenticate.
- Un payload Mastodon Delete minimale con i soli contesti ActivityStreams e
  security/v1 viene normalizzato correttamente dalla libreria locale. Per
  capire i `normalize_failed` serve almeno un payload reale: il tipo Delete
  da solo non riproduce il problema.
- All'avvio dell'analisi il logging non distingueva normalizzazione delle
  signature options e normalizzazione del documento, non registrava la forma
  del `@context` e riduceva status HTTP, JSON non valido, timeout ed eccezioni
  a `origin_fetch_failed`.

## Delete di contenuti e Actor

All'avvio dell'analisi `InboxActivityProcessor::handleDelete()` gestiva
soltanto post e commenti tramite `ObjectResolver::resolvePostOrComment()`.
Una Delete valida il cui object era l'URI di un Actor veniva ignorata. I
Delete dei post invece funzionavano quando superavano l'autenticazione: post
e commenti venivano marcati come deleted.

Conseguenza iniziale: gli Actor remoti cancellati potevano restare nella cache
locale come profili fantasma anche quando il loro Delete era stato autenticato.

### Vincoli di autenticazione per Delete Actor

- Il normale ingresso deve aver gia' autenticato direttamente l'Actor via
  firma HTTP oppure autenticato il forwarding via LD Signature/refetch.
- Il target del Delete deve coincidere, dopo una normalizzazione URI prudente,
  con l'URI dell'Actor autenticato.
- Vanno supportate almeno la forma stringa e la forma oggetto/Tombstone con
  `id`; un Actor non deve poter cancellare un Actor diverso.
- La correzione semantica non deve indebolire l'attuale risposta 401 quando
  nessun metodo di autenticazione riesce.

### Chiave cached e idempotenza al trasporto

- Se l'Actor esiste localmente, la Delete produce effetti reali e deve essere
  verificata crittograficamente con la sua chiave pubblica cached.
- Per una self-Delete Actor non si tenta il refresh della chiave: il documento
  remoto puo' essere gia' 404/410. Se la firma non verifica con la chiave
  cached, oppure la chiave manca, la richiesta viene rifiutata con 401.
- La chiave locale non rende la Delete automaticamente attendibile: viene
  sempre usata per una vera verifica della firma.
- Le Delete inoltrate con LD Signature usano gia' direttamente la chiave
  cached dell'Actor dichiarato quando presente.
- Se `type` e' `Delete`, object coincide con `activity.actor` e quell'URI non
  identifica alcun Actor remoto locale, l'operazione e' un no-op sicuro:
  Openbook risponde 202 senza richiedere una firma, senza creare un InboxItem
  e senza creare una tombstone.
- La stessa semantica vale per la Delete di un post/commento che non esiste
  piu' localmente: il lookup e' soltanto sul database locale, non dereferenzia
  l'object remoto; se non trova nulla risponde 202 senza autenticazione e senza
  accodamento. Se il contenuto esiste, l'autenticazione resta obbligatoria.
- 202, non 404/410, comunica l'accettazione idempotente ed evita che il remoto
  interpreti la consegna come fallita e continui a ritentarla.
- Un Actor presente ma privo di chiave non rientra nel no-op: potrebbe avere
  contenuti locali da rimuovere e la Delete deve quindi restare autenticata.

## Scope della Delete Actor

La correzione riguarda esclusivamente Actor remoti (`is_local = false`). Una
Delete federata non deve mai avviare la cancellazione di un Actor locale,
neppure se formalmente valida: il ciclo di vita locale coinvolge `users`,
autenticazione, sessioni, profilo, file e consegne federate in uscita ed e'
un problema applicativo distinto.

Per gli account locali vale, fuori dallo scope corrente, la politica
"nickname usato = nickname bruciato": l'account puo' essere disabilitato, ma
User e Actor restano presenti e l'identita' federata non viene riutilizzata.

Gli Actor remoti non possiedono file locali da eliminare:

- avatar e copertina sono URL in `actors.icon_url` e `actors.image_url`;
- gli allegati federati usano `media.remote_url` e non vengono scaricati;
- `media.path` e' soltanto un segnaposto per i media remoti.

L'eventuale implementazione deve quindi essere esplicitamente dedicata agli
Actor remoti (per esempio `RemoteActorDeletionService`) e non deve occuparsi
del filesystem.

## Perche' non usare l'hard delete remoto

Le foreign key cancellerebbero in cascata gran parte del grafo:

- chiave ed endpoint Actor;
- post e commenti dell'Actor;
- media e varianti come righe DB;
- follow, mention, like e announce;
- conversazioni che lo includono;
- cache delle collection remote.

Alcuni riferimenti sono `nullOnDelete` (es. `notifications.actor_id`,
`posts.quoted_actor_id`, membri risolti delle collection).

Una semplice `Actor::delete()` non e' pero' uno spazzolone completo e sicuro:

- le relazioni polimorfiche senza FK possono lasciare notifiche/report o altri
  riferimenti orfani;
- la rimozione di like e announce via cascade lascia troppo alti
  `likes_count` e `announces_count` sui post/commenti sopravvissuti;
- la rimozione di un follow accettato verso una community locale lascia troppo
  alto `communities.members_count`;
- la rimozione di un post remoto pubblicato in una community locale puo'
  lasciare troppo alto `communities.posts_count`;
- la rimozione fisica di un commento remoto non aggiorna `comments_count` del
  post o `replies_count` del genitore;
- soprattutto, `comments.parent_comment_id` usa `cascadeOnDelete`: cancellare
  fisicamente un commento dell'Actor puo' eliminare anche l'intero sottoalbero
  di risposte scritto da altri Actor, mentre la normale Delete di un commento
  in inbox usa intenzionalmente il soft-delete;
- sparisce la memoria persistente che l'URI remoto e' stato cancellato; un
  fetch successivo potrebbe ricrearlo come nuovo Actor;
- callback/servizi applicativi di cancellazione vengono aggirati.

## Semantica scelta per Delete Actor remoto

- La riga Actor viene conservata come tombstone interna con stato `deleted` e
  `deleted_at`, cosi' le foreign key e gli alberi dei commenti restano validi.
- L'identita' federata originale viene pero' liberata: URI, username e dominio
  sono sostituiti da identificatori interni univoci basati sull'UUID della
  riga. Vengono cosi' liberati sia il vincolo unico su `actors.uri`, sia quello
  su `(preferred_username, domain)`.
- Nome, bio, avatar, copertina, chiave, endpoint e metadati del profilo vengono
  annullati.
- Post e commenti dell'Actor restano come tombstone, ma corpo, titolo, content
  warning, lingua e URI federato vengono annullati. Azzerare anche gli URI dei
  contenuti impedisce a un nuovo Actor di riattivare accidentalmente le vecchie
  righe riutilizzando gli stessi object id.
- Gli allegati remoti, le associazioni a hashtag/mention e le interazioni
  dell'Actor (like, announce, follow e cache delle collection) vengono
  eliminate.
- `parent_comment_id` non viene modificato: una risposta continua a puntare
  alla tombstone del vero commento padre, senza essere attribuita
  artificialmente al commento precedente.
- La redazione usa aggiornamenti set-based dentro una transazione: non esegue
  una query Laravel per ogni singolo contenuto.
- Una seconda Delete dello stesso URI, che ormai non identifica piu' una riga,
  e' un no-op idempotente. Se l'URI viene riutilizzato dal remoto, il resolver
  crea invece un Actor nuovo con un nuovo UUID.

Per la prima implementazione si accetta che la rimozione diretta delle
interazioni possa lasciare alcuni contatori denormalizzati troppo alti. Le
righe like/announce/follow non restano; l'eventuale incoerenza riguarda solo i
numeri cached e potra' essere corretta separatamente se diventa rilevante.

Questa semantica non si applica agli Actor locali.

## Altri punti candidati

- Normalizzazione implementata per il confronto tra firmatario HTTP e
  `activity.actor`: `InboxController` usa `ActivityPubUri::same()` invece
  dell'uguaglianza stretta iniziale.
- Correzione implementata conservando il comportamento veloce di
  `resolveByUri()` per la navigazione:
  un profilo cached resta utilizzabile anche se non possiede una chiave.
  Soltanto `resolveByKeyId()`, quando autentica una normale attivita' in
  ingresso, considera incompleta una cache priva di PEM e tenta il
  refetch senza attendere il TTL. Le self-Delete restano l'eccezione gia'
  decisa: usano esclusivamente una chiave cached e senza di essa tornano 401.
- Migliorare il log del refetch con status HTTP/categoria di errore.
- Migliorare il log LD con fase fallita, creator e forma del `@context`, senza
  registrare firma o intero payload.
- Aggiungere fixture reali Mastodon per Delete Actor, Delete Note e Update
  inoltrati; mantenere test negativi contro spoofing cross-Actor.

## Osservabilita' implementata

Senza registrare payload, firme o chiavi, i log esistenti sono stati arricchiti
con una tassonomia volutamente ridotta:

- `federation.ld_signature.rejected` con `reason=normalize_failed` indica ora
  `phase` (`options` o `document`), `category` (`jsonld` o `document`) e
  `context_shape` (`missing`, `string`, `list`, `object`, `other`);
- una signature value non decodificabile usa
  `reason=signature_value_invalid`;
- `federation.inbox.forward_rejected` con `reason=origin_fetch_failed` indica
  `category` (`blocked`, `network`, `http`, `invalid_json`) e aggiunge
  `http_status` quando disponibile;
- un documento recuperato ma privo dei campi minimi usa
  `reason=origin_document_invalid`.

Questi campi dovrebbero permettere di raggruppare i casi di produzione senza
conservare il payload. Un trace piu' dettagliato verra' valutato soltanto se le
categorie non saranno sufficienti.

## Ordine di lavoro provvisorio

1. Definire e implementare la semantica Delete Actor autenticata.
2. Migliorare osservabilita' LD/refetch senza cambiare la policy di sicurezza.
3. Acquisire un payload reale fallito e aggiungerlo come fixture sanitizzata.
4. Correggere la compatibilita' di normalizzazione dimostrata dalla fixture.
5. Valutare separatamente retry/risposte HTTP solo dopo aver distinto errori
   permanenti e transitori.
