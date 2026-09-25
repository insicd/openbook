# Supporto relay ActivityPub — analisi iniziale

Documento di analisi e memoria dell'implementazione. Le sezioni sullo stato
iniziale di OpenBook e sulle lacune descrivono il codice prima del supporto
relay; il piano registra poi gli esiti delle macroattivita'. Il lavoro del
ramo `relay_support` e' confluito in `main`; i riferimenti al ramo nei singoli
sprint descrivono il momento in cui quelle verifiche furono eseguite.

## Obiettivo

Permettere a un amministratore OpenBook di collegare l'istanza a uno o più
relay ActivityPub per:

1. sottoscriversi e ricevere contenuti pubblici che altrimenti non
   raggiungerebbero l'istanza;
2. inviare ai relay le attività pubbliche prodotte dagli Actor locali;
3. mantenere moderazione, blocchi di dominio, autenticazione e limiti di
   risorse già applicati dalla federazione ordinaria.

Il supporto deve essere opt-in e amministrativo. Un relay può aumentare molto
il volume di post, Actor, media remoti, richieste HTTP e righe nelle code.

## Stato dello standard

I relay non fanno parte del protocollo ActivityPub W3C come funzionalità
specializzata. Usano primitive ActivityPub (`Follow`, `Accept`, `Reject`,
`Undo`, `Announce`, inbox e HTTP Signature), ma il comportamento interoperabile
è una convenzione de facto.

La fonte tecnica principale è **FEP-ae0c: Fediverse Relay Protocols: Mastodon
and LitePub**, finalizzata il 14 marzo 2025. È una FEP informativa: documenta
due famiglie esistenti, non le trasforma in un protocollo normativo.

Riferimenti:

- FEP-ae0c: https://helge.codeberg.page/fep/fep/ae0c/
- ActivityPub W3C: https://www.w3.org/TR/activitypub/
- Mastodon `Relay`: https://github.com/mastodon/mastodon/blob/main/app/models/relay.rb
- Mastodon `StatusReachFinder`: https://github.com/mastodon/mastodon/blob/main/app/lib/status_reach_finder.rb
- Activity-Relay: https://github.com/yukimochi/Activity-Relay
- pub-relay: https://github.com/noellabo/pub-relay
- Fedify relay implementation notes: https://github.com/fedify-dev/fedify/blob/main/docs/manual/relay.md
- GoToSocial relay subscriptions: https://docs.gotosocial.org/en/latest/admin/relay_subscriptions/
- GoToSocial relay pushes: https://docs.gotosocial.org/en/latest/user_guide/relay_pushes/

## Due protocolli distinti

### Mastodon-style

#### Sottoscrizione

- L'amministratore configura normalmente l'**inbox URL** del relay, per
  esempio `https://relay.example/inbox`, non necessariamente l'Actor URL.
- Il client invia all'inbox del relay un `Follow` firmato via HTTP.
- `Follow.actor` è un Actor locale rappresentativo dell'istanza.
- `Follow.object` è precisamente
  `https://www.w3.org/ns/activitystreams#Public`, non l'Actor del relay.
- Il relay risponde con `Accept` o `Reject`; l'approvazione può essere
  asincrona o manuale.
- Mastodon conserva almeno `inbox_url`, stato
  `idle|pending|accepted|rejected` e URI del `Follow` originale.

#### Disiscrizione

- Il client invia `Undo(Follow)` alla stessa inbox.
- L'oggetto può essere l'URI del Follow o il Follow incorporato.
- Normalmente non arriva una risposta specifica all'Undo.

#### Pubblicazione

- Quando il relay è accettato, Mastodon aggiunge la sua inbox alle
  destinazioni di ogni attività **public**.
- Non invia al relay contenuti unlisted, followers-only o direct.
- Il payload è inviato direttamente, firmato via HTTP dall'Actor locale e con
  una Linked Data Signature `RsaSignature2017` nel documento.
- Il relay inoltra tipicamente lo stesso documento, ma firma il trasporto HTTP
  con la propria chiave. La LD Signature permette al destinatario di
  autenticare l'Actor originale nonostante il forwarder HTTP sia il relay.
- La FEP indica come insieme Mastodon tipico `Create`, `Update`, `Delete` e
  `Move`; alcuni relay accettano anche `Announce` e altri tipi.

#### Ricezione

- Il relay consegna le attività all'inbox dell'Actor client/rappresentativo.
- La HTTP Signature identifica sempre il relay. Il payload può conservare
  `activity.actor` dell'autore originario oppure, come osservato con
  `relay.mastodon.nu`, essere un `Announce` creato e firmato direttamente
  dall'Actor relay che punta all'oggetto remoto.
- Il destinatario deve verificare la LD Signature oppure recuperare
  l'attività/oggetto dall'origine e verificarne id/Actor. Non è sicuro
  attribuire al relay la paternità del contenuto o fidarsi del solo relay per
  azioni distruttive.

### LitePub-style (Pleroma/Akkoma e compatibili)

#### Sottoscrizione

- Il client usa un Actor locale di tipo `Application`, tradizionalmente con
  URI terminante in `/relay`.
- Invia `Follow` all'Actor del relay, normalmente
  `https://relay.example/actor`.
- `Follow.object` è l'URI dell'Actor relay, non la Public collection.
- Dopo `Accept`, il relay invia spesso un `Follow` reciproco al client; il
  client deve rispondere con `Accept`/`Reject`.

#### Pubblicazione e ricezione

- Il client pubblica normalmente un `Announce` che punta all'URI dell'oggetto
  pubblico.
- Il relay ritrasmette attività racchiuse in un `Announce` firmato dal relay.
- Il destinatario dereferenzia l'oggetto dall'origine e non attribuisce al
  relay la paternità del contenuto.
- Questo modello evita la dipendenza dalle vecchie LD Signature, ma richiede
  di riconoscere il wrapper e autenticare sempre l'oggetto interno tramite la
  sua origine.

### Conseguenza progettuale

Un campo generico “URL relay” non basta per implementare implicitamente ogni
variante. Servono almeno:

- modalità/protocollo (`mastodon`, `litepub`, forse `auto` solo se davvero
  affidabile);
- Actor URI remoto e inbox URI effettiva;
- URI del Follow inviato;
- stato della sottoscrizione;
- direzione abilitata: ricezione, pubblicazione o entrambe;
- policy sui tipi di contenuto.

Non esiste un meccanismo standard con cui il relay dichiari quale protocollo
supporta. L'autodetection totale rischia quindi di essere fragile. Una UI può
accettare l'URL indicato dal relay, risolvere ciò che è possibile e chiedere
all'amministratore di scegliere la compatibilità quando ambiguo.

## Comportamento dei software osservati

### Mastodon

- Ha una pagina amministrativa dedicata ai relay.
- Chiede l'inbox URL e tenta subito la sottoscrizione.
- Usa un account locale “rappresentativo” per firmare il Follow e le consegne.
- Mostra stati inattivo, in attesa, accettato e rifiutato.
- Aggiunge automaticamente le inbox dei relay accettati al fan-out dei post
  pubblici.
- I post ricevuti dal relay alimentano la timeline federata, non la home di
  ogni utente.
- Richiede di fatto LD Signature per il forwarding diretto Mastodon-style.

### Pleroma/Akkoma e famiglia LitePub

- Espongono/impiegano un Actor `Application` dedicato (`/relay`).
- Seguono l'Actor del relay, gestiscono il follow reciproco e usano `Announce`
  come contenitore.
- Sono il riferimento naturale per la modalità LitePub.

### Misskey e fork

- Relay server diffusi come Activity-Relay documentano Misskey insieme a
  Mastodon come client della modalità `/inbox`.
- Va validato su payload reali prima di dichiarare compatibilità piena: il
  fatto che il relay accetti la sottoscrizione non garantisce che ogni tipo
  di oggetto OpenBook venga interpretato allo stesso modo.

### GoToSocial

- Distingue esplicitamente **relay subscription** (ricevere) e **relay push**
  (pubblicare); le due direzioni possono essere configurate separatamente.
- Usa un service Actor di istanza.
- Offre filtri per public/unlisted, sensibili, media, reply e matcher per
  keyword/hashtag.
- È un buon riferimento per la modellazione, ma OpenBook dovrebbe partire
  con policy più semplici: solo public, entrambe le direzioni configurabili,
  filtri avanzati rinviati.

### Friendica

- Dispone di gestione relay tramite console e supporta collegamenti ad Actor
  relay; la compatibilità concreta sembra più vicina al modello Actor/LitePub.
- Esistono segnalazioni di incompatibilità con relay Gancio, quindi non va
  assunto che qualunque Actor chiamato “relay” implementi FEP-ae0c.

### Pixelfed

- La documentazione pubblica e il repository esaminati non mostrano un flusso
  amministrativo relay maturo equivalente a Mastodon.
- Pixelfed resta importante come destinatario dei contenuti diffusi da un
  relay, ma non deve essere usato come riferimento primario del handshake.

### Software per eventi

- Mobilizon espone un Actor tecnico interno `relay@istanza`. Le altre istanze
  possono seguirlo per ricevere, tramite `Announce`, i contenuti pubblici
  creati su quella specifica istanza.
- La sottoscrizione Mobilizon è unidirezionale: se A segue il relay di B, A
  riceve i contenuti di B, ma B non riceve automaticamente quelli di A. Per
  ottenere entrambe le direzioni servono due relazioni di Follow.
- Mobilizon conserva le condivisioni effettuate dall'Actor relay così da
  propagare correttamente anche aggiornamenti e cancellazioni. Per gli
  oggetti inoltrati non usa LD Signature: quando necessario li recupera
  dall'origine.
- Questo comportamento usa la grammatica Actor + Follow + Announce tipica
  della famiglia LitePub, ma non è un hub generalista: rappresenta il flusso
  pubblico di una singola istanza.
- Gancio espone analogamente un singolo Actor `Application` (oggi tipicamente
  `events@istanza`, in passato `relay@istanza`) che pubblica eventi. Seguirlo
  significa seguire una sorgente eventi, non sottoscriversi a un relay
  Mastodon multi-istanza.
- Un Actor con nome o path `relay` non va quindi classificato in base al nome:
  il protocollo deve essere scelto/configurato esplicitamente.
- Se un vero relay inoltra un oggetto `Event`, la pipeline relay non dovrebbe
  filtrarlo a priori: deve passarlo al normale ingester eventi, applicando la
  stessa autenticazione e le policy public.

### Tre concetti da non confondere

1. **Relay Mastodon:** molte istanze inviano attività pubbliche a un hub
   comune; l'hub le inoltra ai suoi sottoscrittori senza diventare l'autore.
2. **Relay LitePub:** è un Actor federato seguito dal client e distribuisce
   riferimenti ai contenuti mediante `Announce`, spesso con Follow reciproco.
3. **Sottoscrizione d'istanza Mobilizon/Gancio:** usa Actor e `Announce` come
   LitePub, ma l'Actor rappresenta soltanto i contenuti pubblici prodotti da
   una determinata istanza.

L'Actor tecnico locale di OpenBook può supportare sia LitePub sia le
sottoscrizioni d'istanza per eventi. Non è necessario creare identità tecniche
diverse, purché handshake e policy siano distinti nel codice.

## Stato iniziale di OpenBook

### Componenti già riutilizzabili

OpenBook ha già quasi tutti i mattoni di trasporto necessari:

- shared inbox `/inbox` e inbox per Actor;
- verifica HTTP Signature e risoluzione delle chiavi remote;
- code database `inbox` e `delivery`;
- deduplicazione per URI attività;
- blocchi di dominio e client HTTP protetto da SSRF;
- generazione e verifica `RsaSignature2017` compatibile Mastodon;
- autenticazione delle attività inoltrate tramite LD Signature, con fallback
  al refetch same-origin dell'attività;
- fan-out in uscita già centralizzato in `ActivityDelivery`;
- parser per `Create`, `Update`, `Delete`, `Announce` e, sul ramo eventi,
  oggetti `Event` e relative interazioni.

Questo rende realisticamente possibile supportare anche Mastodon-style senza
introdurre una nuova libreria JSON-LD.

### Lacune reali

1. **Manca un Actor di istanza dedicato.** Gli Actor locali attuali sono
   Person, Group o Feed e le route locali assumono `/users/{username}` oppure
   community. Un relay interoperabile beneficia di un Actor `Application`
   stabile, per esempio `https://istanza/relay` o `/actor`.
2. `actors.type` non rappresenta ancora `Application`/`Service`; modello,
   serializer, resolver e route andrebbero estesi con attenzione.
3. Manca una tabella per configurazione e stato dei relay.
4. Il normale `FollowManager` assume un Follow Actor-to-Actor. Il
   Mastodon-style usa invece Public come object, quindi non può essere
   riutilizzato senza una specializzazione.
5. L'ingester attuale scarta un `Create` pubblico se l'autore non è seguito,
   non risponde a un contenuto noto e non menziona Actor locali. È corretto
   per la shared inbox normale, ma annullerebbe lo scopo di un relay.
6. Per `Announce` sconosciuti l'ingester accetta il contenuto solo se l'Actor
   che annuncia è seguito localmente. Questo può funzionare con un relay
   LitePub modellato come Actor seguito dal service Actor, ma è preferibile
   rendere esplicita la provenienza relay invece di affidarsi a un effetto
   collaterale dei follow.
7. Dopo l'autenticazione forwarded, `InboxItem.actor_uri` contiene l'autore
   originario; la chiave HTTP del relay resta soltanto in
   `signature_key_id`. Poiché ricezione HTTP e parsing sono asincroni, serve
   conservare esplicitamente quale relay autorizzato ha consegnato il
   messaggio: altrimenti il processore successivo non saprebbe perché un
   contenuto di un Actor sconosciuto è stato ammesso.
8. `ActivityDelivery` firma già i payload con LD Signature e accoda consegne,
   ma non aggiunge destinazioni relay.

## Strategia architetturale proposta

### 1. Actor di servizio locale

Creare un unico Actor `Application` locale, generato automaticamente e stabile
per l'istanza, con:

- URI canonico dedicato, da definire (`/relay` è il candidato più
  interoperabile LitePub; `/actor` è comune lato relay server);
- inbox dedicata o shared inbox esistente;
- outbox/collection minime se richieste;
- coppia RSA propria;
- WebFinger, probabilmente `relay@dominio`;
- `endpoints.sharedInbox` verso `/inbox`.

Non usare l'amministratore o “il primo utente” come identità tecnica: lega il
relay alla vita di un account umano, espone il suo profilo e rende ambiguo il
comportamento dopo cancellazioni o cambi amministrativi.

Quest'Actor non deve apparire nei suggerimenti, nelle ricerche normali o nelle
timeline come una persona.

### 2. Tabella `relays`

Prima ipotesi, campi separati e indicizzabili:

- `id` UUID;
- `protocol`: `mastodon` oppure `litepub`;
- `actor_uri` nullable durante la risoluzione Mastodon-style;
- `inbox_url` obbligatorio dopo la risoluzione;
- `follow_activity_uri`;
- `state`: `idle`, `pending`, `accepted`, `rejected`, `failed`;
- `receive_enabled` boolean;
- `publish_enabled` boolean;
- `last_error` breve e sanificato;
- `accepted_at`, `last_success_at`, `last_failure_at`;
- timestamp.

Vincoli/indici:

- unique normalizzato su `inbox_url`;
- eventuale unique su `actor_uri` quando presente;
- indice su `(state, publish_enabled)` per il fan-out;
- indice su `(state, receive_enabled)` per riconoscere rapidamente il signer.

I filtri avanzati possono essere colonne successive o una tabella separata;
non vanno anticipati nel primo sprint.

### 3. UI amministrativa

Nuova sezione amministrativa **Relay**, non `.env` e non configurazione per
utente:

- elenco relay con protocollo, dominio, direzioni e stato;
- aggiunta tramite URL indicato dal gestore del relay;
- selezione iniziale `Mastodon-compatible` / `LitePub-compatible`;
- opzioni semplici **Ricevi** e **Pubblica**;
- modifica delle due direzioni senza dover rimuovere o ricreare la
  sottoscrizione;
- azioni sottoscrivi, ritenta, disattiva/rimuovi;
- messaggio esplicito sul possibile aumento di database, banda e moderazione;
- ultimo errore sintetico e data ultimo successo.

In futuro si potranno aggiungere filtri GoToSocial-style (hashtag, niente
media, niente sensitive, niente reply), ma non sono necessari per dimostrare
interoperabilità.

### 4. Handshake

Servizio dedicato, non una forzatura di `FollowManager`:

- Mastodon: `Follow(serviceActor, Public)` inviato all'inbox configurata;
- LitePub: risoluzione dell'Actor relay, `Follow(serviceActor, relayActor)` e
  gestione dell'eventuale Follow reciproco;
- `Accept`/`Reject` correlati tramite URI del Follow e relay/signer atteso;
- `Undo` idempotente in rimozione/disattivazione;
- tutte le consegne passano per la coda `delivery` e le normali firme HTTP.

### 5. Ricezione relay

La controller inbox deve distinguere una consegna forwarded generica da una
consegna effettuata da un relay configurato e accettato:

- autenticare sempre la HTTP Signature del relay;
- Mastodon direct-forward: verificare LD Signature dell'autore o refetch
  same-origin, come già fa `ForwardedActivityAuthenticator`;
- LitePub `Announce`: non fidarsi dell'oggetto incorporato; dereferenziare
  dall'origine o usare un oggetto già autenticato in cache;
- salvare su `inbox_items` un `relay_id` nullable, oppure una relazione
  equivalente, per audit e policy;
- usare `relay_id` esclusivamente come provenienza tecnica/autorizzativa, non
  come autore, distributore visibile o proprietario del contenuto;
- in fase di processing ricontrollare che il relay sia ancora accepted e con
  ricezione abilitata, così una disattivazione amministrativa ha effetto anche
  sugli item ancora in coda;
- accettare tramite relay soltanto contenuti public;
- bypassare il normale criterio “autore seguito/menzione locale” soltanto se
  `relay_id` identifica una sottoscrizione accepted con ricezione abilitata;
- mantenere deduplicazione globale sull'URI originario: lo stesso post
  ricevuto direttamente e da più relay deve produrre una sola entità;
- applicare comunque domain block, limiti payload/media, validazione Actor e
  tipi oggetto supportati.

Un relay non autorizza `Delete`, `Undo`, `Update` o altre mutazioni a nome di
terzi: valgono sempre LD Signature/refetch e ownership già previsti.

### 6. Pubblicazione verso relay

Estendere il punto centralizzato di delivery:

- soltanto attività di contenuti **public**;
- mai unlisted, followers-only, direct o community private;
- destinazioni relay accepted con `publish_enabled = true`;
- deduplicazione per inbox URL insieme alle altre destinazioni;
- stesso `DeliverActivityJob`, retry e failure tracking;
- payload originario con LD Signature per Mastodon-style;
- per LitePub valutare un `Announce` del service Actor verso l'Actor relay,
  senza sostituire il normale payload consegnato ai follower.

Nel caso Mastodon-style non si crea una copia del post e non si genera un
wrapper speciale. Il relay diventa una destinazione aggiuntiva della stessa
attività pubblica:

1. OpenBook genera normalmente `Create`, `Update` o `Delete` per l'Actor
   locale;
2. il calcolo dei destinatari aggiunge le inbox dei relay accepted con
   pubblicazione abilitata;
3. la consegna passa per la normale coda ed è firmata HTTP dall'Actor autore,
   come le altre consegne dello stesso contenuto;
4. il payload conserva Actor originale e LD Signature dell'autore;
5. il relay inoltra lo stesso payload ai propri sottoscrittori.

Per LitePub e per le sottoscrizioni d'istanza, invece, il service Actor
pubblica un `Announce` che punta all'oggetto originale. Questo richiede una
gestione separata per aggiornamenti, cancellazioni, loop e condivisioni già
effettuate.

Tipi coperti dalla prima implementazione:

- `Create`, `Update`, `Delete` di post ed eventi pubblici;
- `Create`, `Update`, `Delete` dei relativi commenti/reply pubblici;
- rinviare `Like`, follow e attività amministrative;
- valutare `Announce` solo dopo test reali, per evitare loop relay → OpenBook
  → relay.

### 7. Timeline e prodotto

- I contenuti relay public possono comparire nella sezione **Mondo**,
  coerentemente con Mastodon “Federated timeline”.
- La provenienza relay, da sola, non è un criterio per inserire un contenuto
  nella Home personale.
- Una volta importato, il contenuto partecipa però senza eccezioni alle regole
  Home già esistenti: deve quindi apparire se l'utente segue l'Actor, segue un
  hashtag presente nel contenuto, segue la community o soddisfa un altro
  criterio di interesse già previsto da OpenBook.
- Il relay amplia ciò che OpenBook conosce; non introduce una seconda logica
  di selezione della Home.
- Gli eventi public ricevuti da relay entrano nella sezione Eventi secondo le
  normali regole temporali.
- Actor e post importati dal relay restano normali entità remote; la
  provenienza relay è metadato di trasporto/audit, non autore o boost visibile.

## Sicurezza, abuso e capacità

- Un relay è una sorgente ad alto volume scelta dall'amministratore, non una
  fonte implicitamente fidata.
- SSRF e domain block devono essere applicati sia al relay sia agli URI degli
  autori/oggetti inoltrati.
- Decisione implementata: il blocco viene verificato nuovamente durante il
  processing semantico, non soltanto sull'HTTP sender. `Create`, `Update` e
  `Announce` controllano firmatario, URI dell'oggetto e Actor dichiarati in
  `actor`/`attributedTo`; anche le fetch successive rifiutano URL bloccati e
  la risoluzione degli Actor applica il controllo prima di consultare la cache.
  Un Actor conosciuto prima del blocco non può quindi introdurre nuovi post,
  commenti o eventi, direttamente o tramite relay Mastodon/Actor-based. Il
  blocco non cancella retroattivamente i contenuti già importati.
- Bloccare un dominio deve prevalere sulla sottoscrizione relay.
- Limitare dimensione payload, tipi attività, redirect e download come nella
  federazione diretta.
- Non scaricare automaticamente media remoti se OpenBook normalmente salva
  solo URL; questo riduce l'impatto storage.
- Prevedere metriche minime: ricevuti, importati, ignorati, falliti,
  duplicati, ultimo contatto.
- Proteggere dai loop e dai duplicati tra più relay con URI attività/oggetto,
  non con il solo relay sender.
- La rimozione di un relay deve interrompere fan-out e bypass di rilevanza
  immediatamente, anche se l'Undo remoto fallisce.
- La UI deve avvertire che molti relay pubblicano l'elenco delle istanze
  collegate.

## Piano implementativo per macroattività tecnologiche

Decisione del 20 settembre 2026: lo sviluppo parte dal branch
`relay_support`, creato intenzionalmente sulla punta di `event_support`.
Post, hashtag ed eventi sono quindi dipendenze esplicite della funzionalità,
in particolare per la futura sottoscrizione agli Actor relay Mobilizon/Gancio.
Prima della Pull Request verso upstream il branch doveva essere riallineato
alla versione di `main` che aveva assorbito le dipendenze precedenti; il
riallineamento e il merge sono stati completati.

Le attività saranno organizzate per famiglia tecnologica, non come un unico
blocco relay. Ogni macroattività può essere consegnata e collaudata
separatamente, ma le fondamenta della prima devono già evitare incompatibilità
con quelle successive.

### Macroattività 0 — Fondazioni comuni

Obiettivo: introdurre modello, identità tecnica e amministrazione senza
attivare ancora traffico federato.

**Stato:** implementata sul branch `relay_support`. L'Actor tecnico viene
creato silenziosamente al primo accesso alla pagina Relay o al relativo
endpoint federato. Le configurazioni salvate restano nello stato `idle` e non
producono Follow, job di consegna o traffico verso il relay.

Sottoattività:

1. Actor `Application` di istanza con chiave RSA, URI stabile, route,
   WebFinger e inbox.
2. Migrazione `relays` con:
   - `protocol` stabile;
   - ricezione e pubblicazione indipendenti;
   - endpoint e Actor URI;
   - stato della relazione e diagnostica essenziale.
3. Service comuni per risoluzione, normalizzazione e validazione endpoint.
4. Pannello amministrativo per aggiungere, ispezionare, disattivare e
   rimuovere una configurazione.
5. Tendina **Tecnologia** predisposta fin dall'inizio. Nel primo rilascio
   espone soltanto **Relay Mastodon**, pur usando internamente valori pensati
   per accogliere in seguito:
   - `mastodon` — relay hub Mastodon-compatible;
   - `instance_actor` — sottoscrizione a un'istanza Mobilizon/Gancio;
   - `litepub` — relay hub LitePub-compatible.

Le tecnologie non implementate non devono comparire come selezionabili né
essere accettate forzando una richiesta HTTP. La predisposizione riguarda il
modello e la struttura della UI, non funzionalità di cartone.

### Macroattività 1 — Relay Mastodon-compatible

È il primo rilascio funzionale e copre relay come `relay.mastodon.nu`.

#### Sprint 1.1 — Handshake

**Stato:** completato e collaudato end-to-end su staging con
`relay.mastodon.nu`. Il collaudo automatico copre anche `Accept`/`Reject`
autenticati, revoca e diagnostica del worker.

- `Follow(serviceActor, Public)` verso l'inbox configurata;
- correlazione sicura di `Accept` e `Reject`;
- `Undo(Follow)` idempotente;
- stati pending/accepted/rejected/failed e ritentativo manuale;
- test iniziali con un relay controllabile.

Dettagli implementativi:

- il pannello Relay espone **Sottoscrivi**, **Riprova** e **Disattiva** in
  funzione dello stato corrente;
- il `Follow` viene firmato dall'Actor tecnico `/relay` e consegnato dalla
  stessa coda `delivery` usata dal resto della federazione;
- la correlazione della risposta usa l'URI stabile del `Follow` e accetta
  soltanto un Actor remoto coerente con l'Actor già noto o, al primo giro,
  con l'host dell'inbox configurata;
- un `Accept` porta il relay in `accepted`, un `Reject` in `rejected`;
- la disattivazione è local-first: azzera subito la relazione locale e mette
  in coda `Undo(Follow)` best effort;
- successo e fallimento definitivo della consegna aggiornano i campi
  diagnostici del relay; un `Follow` diventa `failed` soltanto quando il
  worker ha esaurito i tentativi, non al primo errore transitorio;
- **Riprova** genera un nuovo URI attività per evitare che il relay deduplichi
  il tentativo come un vecchio `Follow`; l'URI corrente viene conservato e
  usato per correlare `Accept`/`Reject` e costruire l'eventuale `Undo`.
- `relay.mastodon.nu` accetta il `Follow(serviceActor, Public)` ma, nella
  risposta, normalizza il Follow incorporato usando il proprio Actor come
  `object`. La correlazione accetta entrambe le forme soltanto dopo aver
  verificato URI del Follow, Actor locale e firmatario remoto.

#### Sprint 1.2 — Ricezione

**Stato:** completato e collaudato con traffico reale da
`relay.mastodon.nu`; `relay_id` viene conservato e i contenuti pubblici
supportati vengono importati correttamente.

- riconoscimento del relay tramite firma HTTP e configurazione accepted;
- persistenza `inbox_items.relay_id` attraverso la coda asincrona;
- LD Signature/refetch dell'attività originaria e verifica ownership;
- bypass controllato del solo filtro di rilevanza locale;
- deduplicazione fra consegna diretta, più relay e retry;
- import di post ed eventi public con blocchi di dominio invariati;
- verifica di Mondo, Home per Actor/hashtag seguito e sezione Eventi.

Dettagli implementativi:

- il relay viene riconosciuto soltanto sulla shared inbox, quando il
  firmatario HTTP coincide con l'Actor registrato da un handshake accepted e
  la ricezione è abilitata;
- il riconoscimento non dipende dal fatto che firmatario e `activity.actor`
  siano diversi: copre sia il forwarding trasparente sia gli `Announce`
  creati e firmati direttamente dal relay;
- il trasportatore resta in `relay_id`, mentre `actor_uri` continua a
  rappresentare l'autore originale autenticato: il processor non confonde
  mai relay e autore;
- l'autenticazione dell'origine riusa senza varianti il percorso esistente:
  LD Signature `RsaSignature2017`, oppure refetch same-origin dell'attività;
- la deduplicazione resta il vincolo univoco su `remote_activity_uri`, quindi
  consegna diretta, relay multipli e retry non creano copie;
- il bypass riguarda soltanto la rilevanza locale e soltanto oggetti
  realmente `Public`; unlisted, followers-only e direct ricevuti dal relay
  vengono ignorati;
- i post pubblici importati compaiono in Mondo e arrivano nella Home soltanto
  attraverso le regole normali, per esempio Actor o hashtag seguito;
- gli eventi pubblici passano nella pipeline eventi esistente;
- le reply isolate annunciate dal relay vengono intenzionalmente ignorate se
  OpenBook non possiede il contesto a cui agganciarle: non vengono trasformate
  in post autonomi e non si recuperano ricorsivamente thread casuali;
- lo stato accepted/receive-enabled viene ricontrollato dal worker: una
  disattivazione impedisce immediatamente il bypass anche agli item già in
  coda;
- `inbox_items` ha una FK nullable verso `relays` e un indice composto
  `(relay_id, status)`; una ricezione autenticata aggiorna l'ultimo contatto
  riuscito del relay.

#### Sprint 1.3 — Pubblicazione

**Stato:** completato e collaudato end-to-end. Un post pubblico locale con
hashtag univoco è stato consegnato al relay con HTTP 202 e ritrovato tramite
lo stesso hashtag su un'istanza prima sconosciuta collegata al relay.

- aggiunta dei relay accepted/publish-enabled al fan-out public;
- `Create`, `Update` e `Delete` di post, commenti, eventi e commenti agli
  eventi;
- esclusione esplicita di unlisted, followers-only, direct e community
  private;
- riuso della normale delivery queue, firme e retry;
- protezione da loop e destinazioni duplicate.

Dettagli implementativi:

- soltanto contenuti locali con visibilità `Public` entrano nel fan-out
  relay; i commenti ereditano questa decisione dal post o evento padre,
  coerentemente con il loro modello dati e con il comportamento di Mastodon;
- relay non accepted, con pubblicazione disabilitata o bloccati a livello di
  dominio non ricevono consegne;
- ogni consegna continua a usare la coda `delivery`, la LD Signature
  dell'autore locale, la firma HTTP, i retry e la diagnostica già introdotta;
- follower, menzioni, destinatari diretti e relay vengono deduplicati per
  endpoint: se il relay coincide con una shared inbox già destinataria parte
  una sola richiesta;
- unlisted, followers-only, direct e community private non vengono
  pubblicati verso relay;
- gli oggetti remoti non vengono mai rimandati al relay, evitando loop sulla
  pipeline di ricezione;
- nelle modifiche di visibilità, `Public -> non Public` invia al relay un
  `Delete`, mentre `non Public -> Public` invia un nuovo `Create`: fuori
  dall'istanza non resta quindi una vecchia copia pubblica e un oggetto mai
  annunciato non riceve un `Update` orfano.

#### Sprint 1.4 — Hardening della prima release

**Stato:** completato e collaudato su staging con un relay Mastodon pubblico.

- la pagina amministrativa mostra ultimo successo, ultimo fallimento e il
  messaggio sintetico dell'ultimo errore;
- ricezione e pubblicazione possono essere abilitate o disabilitate in modo
  indipendente anche dopo la creazione della configurazione;
- un job di contenuto già accodato ricontrolla al momento dell'esecuzione che
  il relay esista ancora, sia accepted, abbia la pubblicazione abilitata e
  non sia stato bloccato a livello di dominio;
- i job diventati non più autorizzati vengono scartati senza contattare il
  relay e senza retry inutili;
- `Follow` e `Undo Follow` restano eseguibili durante le transizioni di stato,
  salvo il blocco esplicito del dominio;
- retry e backoff restano quelli della delivery ActivityPub generale: la
  prima release non introduce code o limiti paralleli specifici per relay;
- test automatici coprono revoca, pubblicazione disabilitata, rimozione della
  configurazione e blocco dominio con consegne già in coda.

Collaudo reale completato per handshake, ricezione di post e pubblicazione di
un post pubblico. Commenti ed eventi condividono lo stesso fan-out e restano
coperti dai test automatici; una verifica reale specifica è utile ma non
bloccante per chiudere la macrofase.

Nota operativa: il cron generale assegna di default circa cinque secondi per
giro alla coda `inbox`. Con un relay trafficato è raccomandato un worker
residente dedicato (`queue:work --queue=inbox --sleep=1`), gestito dal service
manager e riavviato a ogni deploy. Può convivere con `openbook:cron`, perché il
claim atomico della coda database impedisce la doppia presa in carico.

### Macroattività 2 — Actor relay / sottoscrizioni d'istanza

Obiettivo: seguire il flusso pubblico di una specifica istanza e consentire
ad altre istanze di seguire quello di OpenBook. Non è un relay hub
multi-istanza.

#### Revisione dopo la macrofase Mastodon

La prima macrofase ha gia' realizzato la maggior parte dell'infrastruttura
condivisa:

- Actor tecnico locale `Application`, chiave, WebFinger, inbox, shared inbox
  e outbox stabile su `/relay`;
- tabella `relays`, stato amministrativo, direzioni indipendenti, audit e UI;
- consegna firmata su coda con retry, errori e blocchi di dominio;
- correlazione robusta di `Accept`/`Reject` al Follow originale;
- serializer di `Follow`/`Undo`, gia' predisposto in ricezione ad accettare
  come oggetto sia la collection Public sia l'Actor remoto;
- riconoscimento del trasportatore autorizzato e provenienza in
  `inbox_items.relay_id`;
- dereference degli oggetti racchiusi in `Announce`, import di post ed eventi
  public, deduplicazione per URI originale e ingresso nelle normali query;
- distinzione fra `Announce` tecnico e boost sociale: il primo non crea
  `announces`/`event_announces` e non altera i contatori.

Restano specifiche della modalità Actor relay:

- configurare l'identità federata dell'Actor (per esempio
  `@relay@istanza.example`) o il suo **Actor URI**, anziché una semplice inbox,
  e risolverne in modo sicuro tipo, chiave ed endpoint tramite WebFinger quando
  necessario; non si presume automaticamente il nome `relay` partendo dal solo
  dominio, perché non è uno standard condiviso fra le implementazioni;
- preservare il tipo remoto `Application`/`Service`: oggi il resolver accetta
  questi documenti ma li normalizza ancora come `person`, mentre l'enum
  `application` introdotto nella prima macrofase puo' rappresentarli senza una
  nuova migration;
- usare l'Actor remoto come `Follow.object`;
- riconoscere come trasportatore il suo Actor configurato;
- gestire il Follow inverso verso `/relay` come relazione tecnica;
- pubblicare `Announce` firmati dall'Actor di istanza invece di inviare
  direttamente le attività originali;
- esporre nell'outbox tecnica le attività necessarie al discovery/backfill.

La tabella `relays` resta quindi il modello giusto: non serve crearne una
seconda. Va aggiunto un protocollo esplicito (es. `actor`) e vanno adattati
configurazione, handshake e delivery in base al protocollo.

#### Sprint 2.1 — Configurazione e Follow dell'Actor remoto

- aggiungere la modalità Actor relay alla tendina amministrativa;
- accettare l'identità federata o l'URI dell'Actor remoto (non la sua inbox),
  risolverlo con il resolver e il client SSRF-safe gia' esistenti e salvare
  `actor_uri` e inbox canonici;
- correggere la normalizzazione del resolver affinche' `Application` e
  `Service` remoti restino Actor tecnici (`application`) invece di diventare
  falsi profili Person;
- validare che sia un Actor remoto attivo, preferibilmente
  `Application`/`Service`, con endpoint utilizzabile;
- riusare l'Actor locale `/relay`, la coda delivery e gli stati attuali;
- serializzare `Follow.object = actor_uri` e il corrispondente `Undo`;
- riusare la correlazione `Accept`/`Reject`, che supporta gia' l'Actor remoto
  come oggetto del Follow;
- estendere il resolver di ingresso al nuovo protocollo: gli `Announce`
  autenticati dall'Actor configurato entrano nella pipeline relay gia'
  costruita;
- verificare con payload Mobilizon e Gancio che eventi e post public vengano
  importati una volta sola e senza generare boost tecnici.

Questo sprint riusa quasi integralmente ricezione, dereference, deduplica,
moderazione e ingestione della macrofase Mastodon. Il codice nuovo dovrebbe
concentrarsi su configurazione e variante del handshake.

#### Sprint 2.2 — OpenBook come sorgente d'istanza

**Stato:** implementato localmente sul branch `relay_support`; resta da
collaudare end-to-end con un Actor Mobilizon/Gancio reale.

- riconoscere i Follow indirizzati a `/relay` come sottoscrizioni tecniche,
  senza notifiche utente o semantica da follower sociale;
- accettare soltanto Actor remoti autenticati e non bloccati, definendo una
  policy semplice sui tipi ammessi;
- inviare ai sottoscrittori tecnici un `Announce` firmato dall'Actor `/relay`
  per post, commenti, eventi e commenti evento **public** prodotti localmente;
- non inviare contenuti unlisted, followers-only o direct;
- evitare loop e consegne doppie quando lo stesso endpoint e' gia' raggiunto
  da un'altra relazione relay;
- propagare in modo coerente aggiornamenti e cancellazioni, dopo aver
  verificato il comportamento reale di Mobilizon/Gancio;
- popolare `/relay/outbox` con una collection paginata utile al discovery e
  all'eventuale backfill.

Il bookkeeping tecnico non deve riusare le tabelle sociali `announces` ed
`event_announces`: produrrebbe boost e contatori falsi. Prima di implementare
lo sprint va scelto il meccanismo minimo fra URI deterministici ricostruibili
e una piccola coda/ledger tecnica dedicata, necessario solo se Update/Delete
e outbox non possono essere derivati in modo affidabile dagli oggetti locali.

Decisioni implementative:

- i Follow autenticati verso `/relay` vengono salvati nella relazione Actor ↔
  Actor già esistente, ma sono ammessi soltanto da Actor remoti
  `Application`/`Service`; l'Actor tecnico non ha uno User e quindi non genera
  notifiche, contatori o UI sociale;
- `Create` e `Update` di contenuti locali public producono un `Announce`
  firmato dall'Actor `/relay`, con `object` uguale all'URI canonico del
  contenuto; l'Announce del Create ha URI deterministico per oggetto, mentre
  quello dell'Update deriva dall'URI della specifica attività per non essere
  deduplicato come il Create;
- i `Delete` vengono consegnati direttamente e restano firmati dall'autore
  locale originale: dopo la cancellazione l'oggetto non è più
  dereferenziabile e il destinatario deve poter verificare l'ownership;
- non è stata aggiunta una tabella ledger: gli URI deterministici e lo stato
  corrente degli oggetti bastano per fan-out e discovery senza creare boost
  tecnici permanenti;
- `/relay/outbox` espone una `OrderedCollection` paginata derivata dai post,
  commenti, eventi e commenti evento locali public correnti, rappresentati
  come Announce tecnici; `/relay/followers` e `/relay/following` espongono le
  corrispondenti collection tecniche;
- contenuti unlisted/direct/followers-only, oggetti remoti e contenuti di
  community private sono esclusi; le inbox vengono deduplicate rispetto alle
  consegne normali e agli altri relay;
- una configurazione Actor relay con pubblicazione disabilitata prevale
  sull'eventuale Follow tecnico già accettato e interrompe il fan-out.

#### Ordine operativo rivisto

1. Implementare e provare lo sprint 2.1 contro un Actor Mobilizon/Gancio
   reale: e' un'estensione piccola e sfrutta quasi tutto il codice esistente.
2. Osservare payload di `Accept`, `Announce` e `Undo` prima di generalizzare.
3. Definire con payload reali la semantica in uscita di Update/Delete e solo
   allora implementare lo sprint 2.2 e l'outbox tecnica.

### Macroattività 3 — Relay LitePub-compatible

Obiettivo: partecipare a veri hub LitePub multi-istanza riutilizzando Actor,
Follow e `Announce` già consolidati nella macroattività precedente.

#### Sprint 3.1 — Ricezione e handshake LitePub

**Stato:** implementato localmente sul branch `relay_support`; da collaudare
contro un relay LitePub reale.

- Follow dell'Actor relay e Follow reciproco;
- riconoscimento esplicito della relazione come hub LitePub;
- ricezione degli `Announce` e dereference sicuro degli oggetti;
- deduplicazione con consegne dirette e relay Mastodon.

#### Sprint 3.2 — Pubblicazione LitePub

**Stato:** implementato localmente sul branch `relay_support`; da collaudare
end-to-end contro un hub LitePub reale.

- invio di `Announce` verso il relay hub;
- bookkeeping per Update/Delete;
- protezione dai loop relay → OpenBook → relay;

#### Sprint 3.3 — Hardening e collaudo simulato

**Stato:** implementato localmente sul branch `relay_support`; resta
opportuno un collaudo contro un hub reale quando se ne individuerà uno.

- matrice locale dei flussi compatibili con Pleroma/Akkoma e Activity-Relay
  dual-mode, senza dipendere dalla disponibilità di un relay pubblico;
- verifica di handshake, Follow reciproco, ricezione, pubblicazione,
  deduplicazione e disiscrizione;
- ricontrollo della configurazione al momento della consegna: gli `Announce`
  già accodati vengono scartati se il relay è stato nel frattempo disabilitato
  o rimosso;
- nessun workaround specifico per una singola implementazione finché non
  saranno disponibili payload reali incompatibili con FEP-ae0c.

### Macroattività 4 — Evoluzioni trasversali opzionali

Da affrontare soltanto dopo aver misurato il comportamento reale delle prime
integrazioni:

- metriche aggregate per relay e tecnologia;
- limiti di volume e concorrenza più raffinati;
- cleanup e retention specifici;
- filtri per hashtag/keyword, media, sensitive e reply;
- strumenti di diagnostica avanzata e runbook amministrativo.

### Moderazione Mondo collegata ai contenuti ricevuti

**Stato:** implementata localmente sul branch `relay_support` dopo la chiusura
della macrofase LitePub.

- l'amministratore può escludere dalla timeline Mondo tutti i post dotati di
  content warning, senza cancellarli né nasconderli dalle altre sezioni;
- una lista normalizzata di hashtag può imporre localmente il content warning
  anche ai post che non lo dichiarano all'origine;
- i post che contengono uno degli hashtag configurati sono esclusi da Mondo
  soltanto quando è attiva l'opzione generale sui CW;
- la policy è retroattiva e calcolata sulle relazioni già indicizzate, quindi
  non richiede riscritture dei post o nuove migration;
- il CW derivato resta una policy esclusivamente locale: la rappresentazione
  ActivityPub in uscita continua a esporre soltanto il CW realmente salvato
  nel post.

### Moderazione per singolo Actor remoto — attività successiva

Il supporto relay rende più evidente un gap già esistente: Openbook permette
il blocco amministrativo di un intero dominio, ma non dispone ancora di una
policy completa per un singolo Actor remoto. Non va aggiunta incidentalmente
al flusso relay, perché richiede di distinguere almeno:

- **sospensione amministrativa d'istanza**, che impedisce all'Actor di
  introdurre nuovi contenuti o interazioni per qualunque utente, anche tramite
  relay;
- **blocco personale**, che modifica soltanto esperienza e relazioni
  dell'utente che lo applica;
- eventuale **silenzia personale**, meno invasivo e senza federare un `Block`.

L'enforcement appena introdotto per i domain block deve essere riusato come
base, ma prima dell'implementazione vanno definite esplicitamente:

- sorte di post, commenti, eventi e media già salvati;
- rimozione e possibile ripristino dei Follow;
- soppressione di notifiche, messaggi, menzioni e risultati di ricerca;
- reversibilità della sospensione e comportamento dopo lo sblocco;
- eventuale invio best effort di `Block`/`Undo(Block)`, senza affidarsi al
  server remoto per l'applicazione della policy locale.

Decisione corrente: rimandare questa funzione a una macroattività di
moderazione dedicata; non è necessaria per completare il supporto Actor relay.

## Strategia iniziale raccomandata

Il primo obiettivo concreto dovrebbe essere la compatibilità
**Mastodon-style**, perché:

- è il caso richiesto (`relay.mastodon.nu`);
- è amministrativamente semplice e molto diffuso;
- OpenBook possiede già LD Signature e forwarded authentication;
- il modello “relay inbox aggiunta al fan-out public” si innesta bene in
  `ActivityDelivery`.

L'Actor di servizio va però progettato fin dall'inizio in modo compatibile con
LitePub (`Application`, WebFinger e inbox stabile), così lo sprint successivo
non richiede di rifare il modello.

## Decisioni chiuse nella macrofase Mastodon

1. Actor tecnico locale `Application` con URI `/relay` e WebFinger
   `relay@dominio`.
2. Ricezione e pubblicazione configurabili indipendentemente nello stesso
   record.
3. Post, commenti, eventi e commenti evento pubblici; nessun contenuto con
   visibilità inferiore.
4. Nessun filtro anticipato per media/sensitive nella prima versione.
5. Provenienza conservata in `inbox_items.relay_id`; nessuna relazione
   permanente contenuto-relay finché non emerge un'esigenza concreta.
6. `Accept` assente: stato pending e ritentativo manuale con nuovo URI.
7. Protocollo scelto esplicitamente, senza autodetection fragile.
8. Primo riferimento reale verificato: `relay.mastodon.nu`.
9. Gli `Announce` ricevuti da un relay sono buste di trasporto: importano il
   post o l'evento originale, ma non creano righe `announces` o
   `event_announces` e non incrementano contatori sociali. L'identita' del
   trasportatore resta in `inbox_items.relay_id`.
10. NodeInfo espone come metadati di base nome, descrizione pubblica opzionale
    e icona configurata dell'istanza. La compatibilita' con
    `/api/v2/instance` e' stata implementata separatamente: e' un contratto
    API Mastodon, non un requisito del protocollo relay.
11. I boost pubblici locali e i relativi `Undo(Announce)` vengono consegnati
    anche ai relay Mastodon-like accettati e abilitati alla pubblicazione. Il
    fan-out non viene esteso agli Actor Relay/LitePub, per evitare `Announce`
    tecnici annidati e possibili loop senza un test d'interoperabilita' reale.

## Conclusione provvisoria

La funzionalità è fattibile senza sostituire la federazione esistente. Il
pezzo più delicato non è la firma: OpenBook la supporta già. È evitare che
“ricevuto da un relay” diventi una scorciatoia che aggira autenticazione,
moderazione o ownership, e tenere separati:

- identità tecnica dell'istanza;
- relazione amministrativa con il relay;
- autore originale del contenuto;
- relay che ha effettuato il trasporto;
- regole con cui il contenuto entra nelle timeline.
