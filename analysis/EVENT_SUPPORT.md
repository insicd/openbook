# Supporto eventi — analisi

Documento di analisi e memoria dell'implementazione. Le proposte e le
questioni aperte nelle prime sezioni fotografano la fase iniziale; gli esiti
sono riportati negli stati dei singoli sprint.

## Obiettivo generale

Introdurre in OpenBook il supporto agli eventi federati (concerti, serate,
incontri e simili), partendo dagli oggetti ActivityStreams `Event` realmente
ricevuti nell'inbox e mantenendo interoperabilità con implementazioni diverse.

L'obiettivo finale comprende sia la ricezione sia la creazione e pubblicazione
ActivityPub di eventi locali. L'implementazione sarà però progressiva e divisa
in due macrofasi indipendenti.

## Decisioni di prodotto già prese

1. Gli eventi avranno un'entità e una tabella dedicate (`events`, nome da
   confermare), senza essere degradati a righe della tabella `posts`.
2. Ogni evento sarà legato a un `Actor`, non direttamente a uno `User`, così
   da rappresentare nello stesso modo autori locali e remoti.
3. Sarà aggiunta una sezione **Eventi** dedicata nel menu.
4. Nel primo macroset gli eventi non entreranno nella timeline principale.
   L'eventuale integrazione con il feed sarà valutata separatamente più avanti.
5. **Macrofase 1 — ricezione:** riconoscere gli eventi remoti, salvarli nel
   database e mostrarli nella sezione dedicata.
6. **Macrofase 2 — pubblicazione:** permettere agli utenti locali di creare,
   modificare e federare eventi.
7. Ogni macrofase verrà ulteriormente suddivisa in sprint soltanto dopo averne
   chiarito modello, compatibilità e comportamento.

## Macrofase 1 — esperienza utente concordata

### Navigazione e lista

- Nuova voce **Eventi** nel menu laterale, sotto **Community** e prima di
  **Tendenze**.
- La sezione mostra in forma riassuntiva i prossimi eventi in arrivo.
- Layout indicativo desktop: tre card per riga, adattivo sugli schermi più
  piccoli.
- Ogni card mostra almeno:
  - immagine di copertina, se disponibile;
  - titolo;
  - location.
- Cliccando la card si apre il dettaglio locale `/eventi/{uuid}`.
- Nella parte superiore della landing `/eventi`, gli utenti autenticati vedono
  un box **I tuoi eventi** dedicato agli eventi futuri personali:
  - eventi pubblici/unlisted/followers ai quali hanno messo like;
  - eventi direct dei quali il loro Actor locale è destinatario.
- La sezione e la voce di menu sono visibili anche ai visitatori anonimi.
- Gli anonimi vedono esclusivamente eventi `public`.
- Gli autenticati vedono anche gli eventi `followers` per i quali risultano
  autorizzati; `unlisted` resta fuori dalla griglia ma accessibile dal link.
- `direct` resta visibile soltanto ai destinatari locali.
- Box personale, like, partecipazione e condivisione tramite messaggistica
  richiedono autenticazione.
- La Web Share API resta disponibile agli anonimi sul dettaglio di un evento
  pubblico, quando supportata dal browser.

### Dettaglio evento

- Mostra i dettagli disponibili dell'evento senza introdurre mappe o altre
  integrazioni geografiche nella prima versione.
- L'utente può mettere e rimuovere like con un'interazione analoga ai post.
- L'utente può inoltre dichiarare **Parteciperò**. Like e partecipazione sono
  azioni separate e possono coesistere.
- Il dettaglio permette di condividere l'evento:
  - tramite messaggistica interna;
  - tramite Web Share API, dove disponibile.
- La condivisione non amplia mai l'audience originale:
  - eventi public: Web Share e messaggistica interna;
  - eventi unlisted: Web Share e messaggistica interna;
  - eventi followers: sola messaggistica verso utenti già autorizzati;
  - eventi direct: nessuna ricondivisione.
- La regola followers è deliberatamente isolata nella policy e potrà essere
  semplificata o corretta se l'osservazione dei payload reali lo richiederà.
- `summary` viene conservato come sintesi descrittiva separata e può essere
  mostrato come introduzione quando è realmente più breve di `content`.
- `summary` non viene interpretato automaticamente come content warning:
  negli eventi reali osservati ha una semantica diversa da quella delle Note.
- Se l'evento dichiara `sensitive: true`, contenuto e media vengono nascosti
  dietro un avviso generico finché l'utente non sceglie di mostrarli, salvo la
  presenza futura di un campo esplicito e affidabile per il testo dell'avviso.

### Media e copertine

- Gli eventi riusano la tabella `media` tramite una relazione dedicata
  `event_attachments`, analoga a quelle di post e commenti.
- I media remoti restano riferimenti HTTPS e non vengono scaricati sul server
  locale, coerentemente col comportamento attuale dei post remoti.
- Tutte le immagini valide vengono conservate e ordinate.
- La prima immagine è la cover della card.
- Le card usano un contenitore di dimensioni/rapporto uniforme e crop soltanto
  visuale (`object-fit: cover`), senza modificare il file o deformarne le
  proporzioni.
- Nel dettaglio evento le immagini vengono mostrate complete, nel loro rapporto
  originale, riusando per quanto possibile la galleria esistente.
- Gli attachment ActivityPub di tipo `Link` non entrano nella galleria media;
  possono essere conservati separatamente come collegamenti dell'evento quando
  hanno una semantica utile.

### Categorie, lingua e hashtag

- `category` è un valore aperto, non un enum e non una tassonomia locale
  obbligatoria.
- Quando presente viene mostrata come tag/badge; valori noti possono essere
  tradotti o umanizzati e quelli sconosciuti vengono ripuliti senza scartare
  l'evento.
- `inLanguage` viene conservato per ricerca e sviluppi futuri, senza introdurre
  filtri linguistici nella prima versione.
- Gli hashtag ActivityPub restano distinti dalla categoria e riusano la tabella
  `hashtags` tramite una relazione `event_hashtags` dedicata.

### Autore e distribuzione

- `events.actor_id` identifica l'Actor presente in `Create.actor`, considerato
  il creator/autore originario dell'evento.
- L'eventuale `Event.attributedTo` viene risolto e conservato separatamente
  tramite `event_attributions(event_id, actor_id, position)`. Non è soltanto
  un'indicazione editoriale: in sistemi come Mobilizon può rappresentare il
  gruppo sotto la cui identità l'evento viene gestito.
- Quando i due Actor differiscono, la UI mostra l'autore principale e una nota
  **Distribuito da …** collegata all'Actor attribuito.
- Nel caso Mobilizon osservato, l'evento viene quindi mostrato come creato da
  `@strkai` e distribuito dalla community `@locandine`.
- L'eventuale `Announce` resta una terza relazione distinta in
  `event_announces`: non cambia automaticamente autore o distributore.
- `attributedTo` può essere singolo o multiplo: la relazione conserva tutti
  gli Actor risolvibili e il loro ordine.
- La pivot usa unicità su `(event_id, actor_id)`, indice su `actor_id` e FK con
  cascade. In questo modo la UI può caricare nome, avatar e profilo senza URI
  opachi in JSON e senza query N+1.
- Gli Actor non risolvibili non impediscono il salvataggio dell'evento; vengono
  semplicemente omessi dalla nota **Distribuito da**.
- Alla prima `Create`, l'insieme degli Actor autorizzati a gestire l'evento è
  formato da `Create.actor` e dagli Actor iniziali risolti da
  `Event.attributedTo`.
- `Update(Event)` e `Delete(Event)` sono accettati soltanto se firmati da uno
  di questi Actor già autorizzati. La verifica avviene prima di applicare
  eventuali cambiamenti ad `attributedTo`, così un singolo Update non può
  autoattribuire diritti a un nuovo Actor.
- Aver effettuato soltanto un `Announce(Event)` non conferisce alcun diritto di
  modifica o cancellazione sull'evento.

### Eventi fisici, online e ibridi

- Un evento senza luogo fisico ma dichiarato online mostra **Online** come
  location nella card e un badge **Evento online** nel dettaglio.
- `externalParticipationUrl`, se presente e validato come URL HTTPS, viene
  mostrato come pulsante **Partecipa online**.
- Un evento ibrido mostra sia luogo fisico sia indicazione/link online.
- Coordinate ricevute dal server remoto possono essere conservate come parte
  del `Place`, ma non vengono mostrate come numeri grezzi e non alimentano
  mappe nella prima versione.
- URL del sito e altri link utili vengono mostrati separatamente dai media e
  aperti come risorse esterne.
- Ogni evento supporta una sola location fisica, oltre all'eventuale componente
  online. Se il payload contiene più luoghi, viene usato il primo `Place`
  valido; il multi-location richiederebbe una UX dedicata fuori scope.
- La location usa una tabella uno-a-uno `event_locations`, separata da
  `post_locations`, con campi aperti e nullable per rappresentare sia Balotta
  sia Mobilizon:
  - URI e URL remoti;
  - nome e indirizzo testuale;
  - via, località, regione, CAP, paese/codice paese;
  - latitudine e longitudine;
  - eventuale `geo_city_id` per la futura creazione locale;
  - origine locale/remota.
- La UI compone una label leggibile usando soltanto i campi disponibili.

### Refresh dei dati remoti e contatori

- Gli eventi remoti conservano almeno `participant_count`, `likes_count` e
  `remote_counts_fetched_at` (nomi definitivi da stabilire nello schema).
- Aprendo il dettaglio, se la cache è scaduta, OpenBook esegue un GET firmato
  all'URI canonico e aggiorna sia i dati dell'evento sia i contatori.
- Il TTL predefinito è 4 ore ed è configurabile via `.env`:
  `OPENBOOK_EVENT_CACHE_TTL_HOURS=4`.
- Il totale partecipanti viene letto da `participantCount` o da una collection
  partecipanti con `totalItems`, senza scaricarne gli elementi.
- Il totale like viene letto da `likes.totalItems`, quando disponibile.
- Se il server non espone un totale autorevole, la UI mostra soltanto lo stato
  personale e non un conteggio locale parziale potenzialmente fuorviante.
- La griglia non scatena un GET per ogni card: usa i dati in cache. Il refresh
  opportunistico avviene nel dettaglio e gli `Update` inbox mantengono comunque
  i dati aggiornati.
- Nella prima macrofase non viene mostrato l'elenco nominativo dei partecipanti.

### Notifiche interne

- OpenBook notifica l'utente locale quando:
  - un proprio `Join` passa da `pending` ad `accepted`;
  - un proprio `Join` viene rifiutato;
  - un evento presente in **I tuoi eventi** viene annullato tramite `Update`
    oppure trasformato in tombstone da `Delete`;
  - riceve un evento `direct` di cui è destinatario esplicito.
- Non vengono generate notifiche per modifiche ordinarie a titolo,
  descrizione, cover o contatori, né per ogni evento pubblico transitato
  dall'inbox.
- Notifiche per cambi importanti di data o luogo sono rinviate: richiedono un
  confronto semantico e rischiano di produrre rumore nella prima versione.

### Fuori scope della prima macrofase

- Inserimento degli eventi nella timeline principale.
- Mappe, indicazioni stradali o provider geografici esterni.
- Segnalazione/moderazione locale degli eventi. Verrà affrontata come feature
  successiva al completamento anche della macrofase 2 di creazione e
  pubblicazione degli eventi, estendendo allora il flusso già disponibile per
  post e commenti.
- Creazione e pubblicazione di eventi locali, poi implementate nella
  macrofase 2.
- Creazione locale e invio federato di commenti/Note sugli eventi, poi
  implementate nella macrofase 2. La prima macrofase includeva soltanto la
  ricezione.
- Semantica federata di boost/Announce e relativo contatore: sarà valutata
  separatamente quando si affronterà lo sharing federato.
- Like e partecipazione non vanno confusi:
  - l'interesse genera `Like(Event)` e la rimozione il relativo `Undo`;
  - **Parteciperò** genera `Join(Event)`; la cancellazione usa `Undo(Join)` per
    una richiesta pendente e `Leave(Event)` per una partecipazione accettata.
- La partecipazione non è necessariamente immediatamente accettata: sistemi
  come Mobilizon supportano `Accept(Join)` e `Reject(Join)`. Il dominio deve
  quindi prevedere almeno gli stati pending/accepted/rejected, anche se alcuni
  eventi accettano automaticamente.
- Flusso UI concordato:
  - al click su **Parteciperò** si salva `pending`, si invia `Join(Event)` e
    l'evento entra subito in **I tuoi eventi**;
  - `Accept(Join)` porta allo stato `accepted` e la UI mostra **Parteciperai**;
  - `Reject(Join)` porta a `rejected`; l'evento esce da **I tuoi eventi** salvo
    che l'utente vi abbia anche messo like;
  - richiesta pending e partecipazione accettata devono poter essere
    annullate.
- OpenBook comprende sia `Leave(Event)` sia `Undo(Join)` in modo idempotente.
- In uscita usa entrambe le forme in momenti distinti, senza duplicare la
  medesima cancellazione:
  - `Undo(Join)` annulla una richiesta ancora `pending`;
  - `Leave(Event)` abbandona una partecipazione `accepted`.

### Acquisizione e visibilità concordate

- OpenBook salva tutti gli oggetti `Event` ricevuti tramite attività valide e
  autenticate dall'inbox, senza limitarli preventivamente agli Actor seguiti.
- La rilevanza, lo spam e l'eventuale volume eccessivo sono problemi di
  moderazione/ordinamento, non ragioni per alterare l'audience dichiarata.
- La visibilità viene derivata da `to`/`cc` con semantica analoga ai post:
  - `Public` in `to` → `public`, visibile a tutti e nella lista generale;
  - `Public` soltanto in `cc` → `unlisted`, accessibile tramite link ma fuori
    dalla lista generale;
  - nessun `Public`, ma collection `/followers` → `followers`;
  - nessun `Public` né followers, soltanto Actor specifici → `direct`.
- La classificazione segue questa precedenza: la presenza di `Public` rende
  l'oggetto pubblico/unlisted anche se `cc` contiene inoltre followers o Actor
  locali specifici.
- `to`/`cc` svolgono contemporaneamente due ruoli distinti:
  - dichiarano la visibilità dell'oggetto;
  - indicano al server sorgente dove consegnare l'attività.
  Un destinatario specifico in `cc` non restringe un oggetto già pubblico.
- Esempio verificato Balotta: l'oggetto contiene `Public` in `to`, mentre
  `cc` include i followers di `@agenda`; l'attività consegnata include anche
  l'Actor locale `skeyby`. L'evento resta **pubblico per tutti**: followers e
  destinatario locale descrivono la distribuzione, non una restrizione.
- La proposta di schema prevede `events.visibility` e una relazione dedicata
  `event_recipients(event_id, actor_id)` per autorizzare eventi direct e
  followers, senza trasformarli in conversazioni o messaggi.
- Per gli eventi non pubblici si conservano i destinatari locali effettivi
  della consegna:
  - nell'inbox personale si usa `inbox_items.target_actor_id`;
  - nella shared inbox si usano le relazioni locali di follow coerenti con il
    creator/distributore che ha consegnato l'attività.
- Non viene introdotto un modello generale delle audience né un
  `followers_actor_id`: è il server sorgente a espandere la propria collection
  followers e OpenBook registra chi, localmente, ha ricevuto l'evento.
- Questa è deliberatamente una prima regola KISS; payload reali non coperti
  potranno motivare un affinamento successivo.
- Gli eventi direct futuri compaiono nel box personale **I tuoi eventi**.
- Anche gli eventi futuri ai quali l'utente ha messo like o per i quali ha
  richiesto/confermato la partecipazione compaiono in **I tuoi eventi**.
- Requisiti minimi di ingestione:
  - `id` HTTPS valido;
  - creator risolvibile;
  - `name` non vuoto;
  - `startTime` valido;
  - audience interpretabile.
- Descrizione, summary, cover, location e URL web sono opzionali.
- `endTime`, se presente, dovrebbe essere valido e successivo all'inizio. Se è
  malformato o incoerente, viene ignorato con un log diagnostico minimale senza
  scartare l'intero evento.
- La mancanza di ID, creator, titolo o inizio rende invece l'oggetto non
  utilizzabile e porta a ignorarlo senza errore applicativo.

### Rielaborazione dell'inbox ignorato

- Viene introdotto un comando generale `openbook:reprocess-inbox`, non
  specifico per gli eventi.
- Il comando seleziona tutte le righe `inbox_items` con stato `ignored` ancora
  presenti nel database, le riporta a `pending` e ridispatcha il normale
  `ProcessInboxActivityJob` sulla coda `inbox`.
- Non duplica la logica del processor e non filtra per activity/object type:
  dopo un aggiornamento può recuperare qualsiasi attività precedentemente
  ignorata che sia diventata supportata.
- Le attività ancora non applicabili torneranno semplicemente `ignored`.
- La retention dell'inbox limita naturalmente la quantità di storico
  recuperabile; le righe già eliminate non possono essere ricostruite.
- Il comando è idempotente rispetto agli handler di dominio e comunica
  chiaramente quante righe sono state rimesse in coda. Con queue asincrona il
  normale worker/cron completerà poi l'elaborazione.

### Announce degli eventi

- Gli `Announce(Event)` ricevuti vengono persistiti fin dalla macrofase 1,
  anche se gli eventi non entrano ancora nella timeline principale.
- Un `Announce(Event)` può precedere la relativa `Create` o essere l'unica
  attività ricevuta. In questo caso OpenBook importa comunque l'oggetto Event
  incorporato e lo deduplica tramite il suo URI canonico.
- Se manca la `Create`, l'Actor risolto da `Event.attributedTo` viene mostrato
  come autore/organizzatore noto, senza attribuire automaticamente la creazione
  personale all'Actor che ha effettuato l'Announce.
- L'esempio Mobilizon osservato include anche `Event.actor: @strkai`
  nell'oggetto incorporato dall'Announce, mentre `Event.attributedTo` e
  `Announce.actor` indicano `@locandine`. Il parser usa quindi, nell'ordine,
  `Event.actor`, l'Actor della Create e infine `attributedTo` come fallback:
  `Event.actor` è utile ma non viene assunto come obbligatorio per tutti i
  server.
- Se la `Create` arriva successivamente, aggiorna la stessa riga Event con il
  creator effettivo e completa le informazioni mancanti, senza duplicare
  l'evento.
- La UI usa formule aderenti ai dati realmente disponibili: non mostra
  **Creato da** quando è noto soltanto l'organizzatore/Actor attribuito.
- Si usa una tabella dedicata `event_announces`, collegata ad `actors` ed
  `events`, con unicità della coppia attore/evento e identificativo remoto
  quando disponibile.
- `Undo(Announce)` rimuove in modo idempotente la condivisione senza eliminare
  l'evento sottostante.
- Non si modifica la tabella `announces`: schema, model, manager e query della
  timeline sono fortemente specializzati su `posts`, e renderli polimorfici
  allargherebbe inutilmente il rischio della prima implementazione.
- La futura integrazione degli eventi nella timeline potrà leggere
  `event_announces` esplicitamente, senza alterare oggi il feed dei post.

### Like e partecipazioni

- I like degli eventi riusano la tabella polimorfica `likes`; non è necessaria
  una nuova tabella. `ReactionManager` e serializer dovranno essere estesi al
  model `Event`.
- Le partecipazioni usano `event_participations` con almeno:
  - UUID;
  - `event_id` e `actor_id`;
  - stato `pending`, `accepted` o `rejected`;
  - URI dell'attività `Join` corrente;
  - data dell'eventuale risposta;
  - timestamp applicativi.
- La coppia `(event_id, actor_id)` è unica e indicizzata per le query di
  **I tuoi eventi**.
- Le righe rifiutate restano memorizzate per idempotenza e stato federativo.
  Un nuovo tentativo genera un nuovo `Join` e riporta la relazione a pending.
- `actor_id` non è limitato agli utenti locali: nella macrofase 2 la stessa
  tabella potrà rappresentare Actor remoti che partecipano a eventi locali.
- Il pulsante **Parteciperò** dipende da `joinMode`:
  - `free` e `restricted`: invio di `Join(Event)`;
  - `external`: solo pulsante verso `externalParticipationUrl`;
  - `none` e `invite`: nessun Join spontaneo;
  - valore assente o sconosciuto: solo like, senza tentativi RSVP best effort.

### Eventi passati e ricerca

- La navigazione principale privilegia esclusivamente gli eventi futuri/in
  corso; gli eventi conclusi non devono mescolarsi alla griglia ordinaria.
- Gli eventi passati restano conservati e sono consultabili in un archivio
  cronologico inverso dedicato (`/eventi/passati`), oltre che dalla ricerca.
- Un evento diventa passato soltanto per effetto delle date: non viene
  alleggerito né eliminato. Titolo, descrizione, media, luogo, commenti e
  interazioni restano integralmente disponibili senza retention automatica.
- La riduzione ai soli dati minimi riguarda esclusivamente una vera
  `Delete(Event)` trasformata in tombstone, non il normale trascorrere del
  tempo.
- La ricerca globale raggiungibile dalla lente deve includere gli eventi
  visibili all'utente: futuri, in corso, passati e `CANCELLED`, ma non le
  tombstone prodotte da `Delete`.
- La ricerca copre titolo, descrizione, summary, location, categoria e hashtag.
- I risultati hanno badge **Evento**, data e stato; a rilevanza testuale
  equivalente, gli eventi futuri precedono quelli passati.
- Query e indici devono estendere la ricerca senza appesantire le sezioni
  esistenti.
- **I tuoi eventi** mostra al massimo i prossimi 6 elementi, ordinati per
  `startTime` crescente, con collegamento all'elenco personale completo.
- La griglia generale ordina prima gli eventi in corso e poi quelli futuri per
  `startTime` crescente; a parità di data usa il titolo come criterio stabile.
- Lista generale e archivio adottano paginazione/infinite scroll coerente con
  le altre sezioni OpenBook.
- `/eventi/passati` usa ordine cronologico decrescente, mostrando prima gli
  eventi conclusi più recentemente.
- Gli indici temporali e di visibilità devono essere progettati sulle query
  della lista generale, del box personale e dell'archivio, verificandoli sia
  su MySQL sia su SQLite.

### Date e timezone

- Ordinamento e confronti usano istanti normalizzati in UTC.
- Viene conservata anche la timezone IANA dichiarata dall'evento, quando
  disponibile (per esempio `Europe/Rome`).
- Se manca una timezone IANA, viene conservato almeno l'offset presente nel
  timestamp remoto.
- Per gli eventi fisici la UI mostra l'ora locale dell'evento, non la converte
  automaticamente nella timezone del browser: un concerto indicato alle
  20:30 a Bologna resta alle 20:30 anche se consultato da Helsinki.
- La timezone viene mostrata esplicitamente almeno nel dettaglio evento. Sulle
  card può essere omessa quando renderebbe la presentazione troppo affollata.
- Se il payload fornisce soltanto un timestamp UTC (`Z`) e nessuna timezone,
  la timezone dell'istanza viene usata come fallback di visualizzazione.
- La conversione aggiuntiva nella timezone dell'utente per gli eventi online
  è rinviata a un eventuale affinamento successivo.
- Per classificare un evento privo di `endTime`, OpenBook applica una durata
  virtuale predefinita di 12 ore a partire da `startTime`.
- La fine virtuale serve esclusivamente a decidere se l'evento è in corso o
  passato e non viene salvata/federata come se provenisse dal server remoto.
- Il database conserva quindi `end_at = null`; query e indici devono gestire
  separatamente eventi con fine reale ed eventi senza fine, evitando
  espressioni che rendano inutilizzabili gli indici temporali.
- La durata virtuale è configurabile tramite `.env`, con default 12 ore:
  `OPENBOOK_EVENT_DEFAULT_DURATION_HOURS=12`. Per ora non viene aggiunta al
  pannello `system_settings`, trattandosi di una regola tecnica poco variabile.
- Eventi ricorrenti:
  - ogni oggetto con proprio `id` e `startTime` viene importato come evento;
  - OpenBook non espande localmente regole di ricorrenza;
  - l'eventuale URI della serie/parent può essere conservato;
  - vengono mostrate soltanto le occorrenze effettivamente ricevute, senza
    generare autonomamente date future.

### Commenti federati

- L'ultimo sprint della macrofase 1 riconosce le `Note` remote collegate a un
  `Event` e le mostra come commenti nel dettaglio evento.
- In questa fase i commenti sono in sola lettura dal punto di vista locale.
- Composer, risposte locali e consegna ActivityPub delle nuove `Note` vengono
  rinviati alla macrofase 2, insieme alla pubblicazione locale degli eventi.
- Le Note vengono conservate in `event_comments`, non nella tabella
  `comments`: quest'ultima richiede un `post_id` e le query dei thread sono
  fortemente specializzate sui post.
- `event_comments` conserva almeno evento, parent comment opzionale, Actor,
  URI remoto, body, custom emoji, stato e timestamp federati.
- Gli allegati usano una pivot dedicata verso `media`, mantenendo separato lo
  schema ma riusando ingester e rendering esistenti.
- Thread e componenti visuali possono condividere logica con i commenti dei
  post, senza rendere nullable o polimorfica la relazione esistente.

### Update, Delete e tombstone

- `Update(Event)` aggiorna i dati dell'evento in modo idempotente, preservando
  like, destinatari e stati di partecipazione locali.
- `Delete(Event)` non produce un 404 anonimo per chi poteva vedere l'evento:
  la riga viene trasformata in una tombstone e il dettaglio comunica che
  l'evento non è più disponibile/è stato annullato.
- La tombstone conserva soltanto identificativi, visibilità e dati minimi
  necessari ad autorizzare la pagina; descrizione, location, media, commenti e
  altre informazioni non più necessarie devono essere rimossi.
- Un visitatore che non aveva accesso all'evento (per esempio un evento direct
  destinato ad altri) riceve sempre il normale `404`, senza rivelarne neppure
  l'esistenza.
- Un UUID locale mai esistito continua a restituire `404`.
- La tombstone impedisce che un successivo fetch accidentale faccia risorgere
  silenziosamente l'oggetto eliminato. L'eventuale politica di ripristino deve
  richiedere una decisione esplicita e verificata.
- Va mantenuta distinta la cancellazione dell'oggetto (`Delete`) dallo stato
  applicativo di un evento ancora esistente ma cancellato/posticipato, che può
  essere comunicato da `eventStatus` o `ical:status`.
- Un evento con stato remoto `CANCELLED` conserva dettagli e interazioni:
  - mostra chiaramente **Evento annullato** nella card e nel dettaglio;
  - non compare nella normale griglia dei prossimi eventi;
  - resta in **I tuoi eventi** per chi aveva espresso interesse o
    partecipazione, così l'annullamento rimane visibile;
  - resta consultabile nell'archivio;
  - non accetta nuove richieste di partecipazione.
- Stati come `TENTATIVE` o `POSTPONED` restano nella navigazione ordinaria con
  un badge esplicito.
- Quando viene cancellato l'Actor remoto creator:
  - tutti gli eventi di cui è `actor_id` diventano tombstone;
  - descrizioni, media, location e commenti vengono rimossi;
  - restano soltanto dati minimi e grant necessari a mostrare **Evento non più
    disponibile** a chi aveva accesso.
- Se l'Actor cancellato era soltanto distributore di eventi creati da altri,
  gli eventi restano: vengono eliminate le relative `event_attributions` e gli
  `event_announces` prodotti da quell'Actor.

## Evidenze dal database locale

- La riga indicata inizialmente (`01a0b0aa-886b-7095-b73b-2c79c0b3ae5c`) non
  è più presente. L'inbox grezzo è soggetto a pulizia periodica.
- Sono state trovate altre 11 attività contenenti un oggetto `Event`.
- Le fonti osservate sono almeno due: `balotta.org` e `mobilizon.it`.
- Tutte le attività trovate hanno stato `ignored`, non `failed`.
- Sono presenti sia `Create(Event)` sia `Announce(Event)` con oggetto inline.

### Perché venivano ignorate prima del supporto Event

`InboxActivityProcessor` inoltra `Create` e `Update` al normale flusso dei
post, ma `RemotePostObject::isPostable()` riconosce soltanto:

- `Note`
- `Page`
- `Article`
- `Video`
- `Image`

`Event` non era incluso e veniva quindi ignorato intenzionalmente prima
dell'upsert. Anche l'importazione da outbox e la risoluzione degli Announce
usavano lo stesso controllo: il supporto non poteva limitarsi al solo handler
dell'inbox, ma doveva passare da un'astrazione condivisa.

## Struttura comune osservata

I payload Balotta e Mobilizon condividono un nucleo ActivityStreams utile:

- `type: Event`
- `id`: URI federato stabile dell'evento
- `url`: pagina web leggibile dell'evento
- `name`: titolo
- `content`: descrizione, testo o HTML
- `summary`: presente ma semanticamente non uniforme
- `startTime`: data e ora di inizio ISO 8601
- `endTime`: opzionale, ISO 8601
- `published` e `updated`
- `attributedTo`
- audience `to` / `cc`
- `location`: oggetto `Place`
- `attachment`: normalmente almeno un'immagine di copertina
- `tag`: eventuali hashtag

Questo rende possibile riusare varie parti del flusso dei post (attore,
audience, sanitizzazione HTML, attachment, hashtag, timestamp), ma un evento
non dovrebbe essere degradato a semplice `Post`: date, stato e luogo hanno
semantica propria e devono restare interrogabili.

## Differenze già emerse

### Balotta

- `location.name` e `location.address` sono stringhe direttamente leggibili.
- Alcune location includono `latitude` e `longitude`, altre no.
- La copertina è un attachment `Document` con `mediaType: image/jpeg`.
- Gli hashtag sono normali tag ActivityStreams.
- `summary` può contenere una copia molto estesa del contenuto, non un breve
  avviso o content warning: non può essere interpretato automaticamente con
  la stessa semantica usata oggi per le Note sensibili.

### Mobilizon

- `location` è sempre un `Place`, ma l'indirizzo è spesso un oggetto
  `PostalAddress` (`streetAddress`, `postalCode`, `addressLocality`,
  `addressRegion`, `addressCountry`). `location.name` può essere `null`.
- Espone ulteriori metadati: `timezone`, `isOnline`, `status` / `ical:status`,
  `category`, `joinMode`, capacità, numero partecipanti, lingua, URL di
  partecipazione esterna e flag commenti.
- `attachment` può mescolare immagini (`Document`) e collegamenti (`Link`):
  il parser media non deve trattare ogni attachment come file visuale.
- Nel caso osservato, `Create.actor` e `object.attributedTo` non coincidono;
  l'evento è creato da un actor e attribuito a un gruppo. Esiste inoltre un
  `Announce` dello stesso evento. Autore, mittente firmatario e soggetto che
  rilancia l'evento non sono quindi sempre la stessa entità.

## Prime implicazioni architetturali

1. **Tipo di dominio dedicato.** È prevista una tabella/model `events`
   collegata ad `actors`. Un evento ha ciclo di vita, date e query future
   differenti da un post.
2. **Integrazione condivisa.** Ricezione da inbox, refresh/update, outbox
   remota, Announce e Delete dovranno riconoscere lo stesso tipo senza
   duplicare parser e regole.
3. **Identità federata.** L'URI `Event.id` deve essere univoco e guidare
   upsert, update e delete idempotenti.
4. **Date con timezone.** Occorre conservare gli istanti normalizzati senza
   perdere l'informazione necessaria a mostrarli correttamente. Va deciso se
   sia utile conservare anche la timezone dichiarata separatamente.
5. **Location flessibile.** Il `Place` remoto non va forzato nel catalogo
   GeoNames locale. Deve supportare almeno nome, indirizzo testuale o
   strutturato, URL e coordinate opzionali.
6. **Coordinate remote.** A differenza della posizione scelta per un post
   locale, qui le coordinate fanno parte dell'oggetto pubblico originale.
   Va comunque decisa esplicitamente la politica di conservazione e output.
7. **Attachment filtrati.** Le immagini possono probabilmente riusare
   l'ingester esistente; gli attachment `Link` richiedono un trattamento
   separato o devono essere ignorati in modo sicuro.
8. **Audience e feed.** Va deciso quando un evento ricevuto merita storage e
   in quali feed compare, evitando di importare indiscriminatamente ogni
   evento visto in federazione.
9. **Announce.** Uno stesso evento può arrivare sia con `Create` sia con
   `Announce`; serve deduplicazione per URI e una rappresentazione distinta
   dell'eventuale condivisione.

## Questioni aperte al termine della prima ricognizione

Le questioni iniziali su navigazione, acquisizione, audience, campi, date,
stati, autore/distribuzione, Announce, interazioni e commenti sono state
risolte nelle sezioni precedenti. Restavano da chiudere soprattutto i dettagli
di autorizzazione federata, moderazione, retention e la suddivisione finale in
sprint della macrofase 1.

## Stato dell'analisi

La fattibilità della ricezione di base è buona: i payload contengono un nucleo
coerente e OpenBook possiede già componenti riutilizzabili per actor, HTML,
audience, hashtag, media e location. La modifica non è però riducibile ad
aggiungere `Event` a `POSTABLE_TYPES`: farlo trasformerebbe implicitamente gli
eventi in post, perdendo semantica e rischiando comportamenti errati su
summary, location, Announce, Update e Delete.

La compatibilità dell'outbox deve essere studiata sul minimo comune tra la
specifica ActivityStreams e le implementazioni federate più diffuse; Mobilizon
è un riferimento importante ma non va assunto come unico dialetto.

## Piano implementativo — macrofase 1 (ricezione)

### Sprint 1 — Modello dati e fondamenta di dominio

- Migrazioni, model, relazioni, cast e factory per eventi, location,
  attribuzioni/gestori, destinatari, hashtag, media, Announce e
  partecipazioni.
- Configurazioni tecniche per durata virtuale e TTL del refresh remoto.
- Vincoli, foreign key e indici progettati sulle query già definite, con test
  su SQLite e verifica dei piani principali su MySQL.
- Nessuna UI e nessuna modifica al comportamento dell'inbox: al termine si
  verificano schema e test del dominio senza esporre una feature incompleta.

**Stato:** implementato sul ramo `event_support`, poi confluito in `main`. La
migration e i model sono pronti; i test mirati passano su SQLite e il DDL
MySQL è stato verificato in modalità `--pretend` senza modificare il database
locale.

### Sprint 2 — Ingestione di Create e Announce

- Parser comune dell'oggetto `Event` per i dialetti Balotta/Mobilizon e il
  nucleo ActivityStreams standard.
- Import idempotente di `Create(Event)` e `Announce(Event)`, deduplicato per URI
  canonico, con audience, destinatari locali, autore/organizzatori, date,
  stato, luogo, link, categoria, lingua, hashtag e media.
- Gestione dell'Announce ricevuto prima o senza Create e completamento
  successivo del creator quando la Create arriva.
- `Undo(Announce)` e comando generale `openbook:reprocess-inbox` per recuperare
  tutte le righe `ignored` ancora disponibili.
- Verifica su copie dei payload reali e ispezione delle righe generate nel
  database; nessuna pagina pubblica ancora necessaria.

**Stato:** implementato sul ramo `event_support`, poi confluito in `main`.

### Sprint 3 — Ciclo di vita e autorizzazioni federate

- `Update(Event)` e `Delete(Event)` con controllo sugli Actor gestori
  preesistenti.
- Stati `CANCELLED`, `TENTATIVE` e `POSTPONED`, tombstone e idempotenza.
- Effetti della Delete di un Actor creator o soltanto
  organizzatore/distributore.
- Pulizia delle relazioni dipendenti e protezione dalla resurrezione
  accidentale.
- Test mirati a spoofing, duplicati, ordine invertito delle attività e
  cancellazioni ripetute.

**Stato:** implementato sul ramo `event_support`, poi confluito in `main`.
`Update(Event)` accetta creator e organizzatori già registrati, ignora update
più vecchi della versione conservata e preserva grant/interazioni locali.
`Delete(Event)` produce una tombstone idempotente, elimina contenuti e
relazioni non necessarie e impedisce la resurrezione tramite Create tardive.
La cancellazione del creator remoto invalida i suoi eventi; la cancellazione
di un semplice distributore rimuove soltanto attribuzioni e announce.

### Sprint 4 — Consultazione web e refresh remoto

- Voce menu, landing `/eventi`, box **I tuoi eventi**, archivio dei passati e
  dettaglio locale.
- Card responsive con cover uniforme; dettaglio con media completi, location,
  timezone, stato, organizzatori e link online.
- Policy completa per public, unlisted, followers e direct, inclusi anonimi e
  tombstone.
- Refresh opportunistico firmato dal dettaglio con cache di quattro ore e
  aggiornamento dei contatori senza richieste N+1 dalla griglia.
- Verifica manuale desktop/mobile e test di autorizzazione/query.

**Stato:** implementato sul ramo `event_support`, poi confluito in `main`.
Sono disponibili landing `/eventi`, archivio `/eventi/passati` e dettaglio
`/eventi/{uuid}`. La griglia non effettua fetch remoti; il dettaglio aggiorna
opportunisticamente l'oggetto con TTL predefinito di quattro ore. Le query
rispettano `public`, `unlisted`, `followers` e `direct`, comprese le tombstone,
e usano gli indici temporali e gli indici inversi di destinatari e
partecipazioni predisposti nello sprint 1.
Le menzioni abbreviate (`@utente`) nei testi di eventi remoti vengono risolte
in sola visualizzazione rispetto al dominio dell'URI originale dell'evento;
il testo memorizzato resta invariato e URL o handle già completi non vengono
riscritti.

### Sprint 5 — Interesse e partecipazione federata

- `Like(Event)` e `Undo(Like)` riusando i like polimorfici.
- `Join(Event)`, `Undo(Join)` e `Leave(Event)`, con ricezione idempotente di
  `Accept` e `Reject` e rispetto di `joinMode`.
- Aggiornamento di **I tuoi eventi**, stati dei pulsanti e notifiche interne
  già concordate.
- Test delle attività in uscita contro fixture compatibili con Mobilizon e
  verifica che like e RSVP rimangano semanticamente distinti.

**Stato:** implementato sul ramo `event_support`, poi confluito in `main`.
L'interesse riusa i like polimorfici e produce `Like(Event)` / `Undo(Like)`;
la RSVP conserva invece uno stato distinto e genera `Join(Event)`,
`Undo(Join)` per una richiesta pending oppure `Leave(Event)` per una
partecipazione accettata. `Accept(Join)` e `Reject(Join)` sono correlati
all'attività locale e accettati soltanto da creator/organizzatori già noti.
La UI impedisce nuove interazioni su eventi conclusi, cancellati o eliminati,
rispetta `joinMode` e mantiene gli eventi interessanti o partecipati nel box
personale. I test mirati verificano payload, idempotenza e notifiche locali.

### Sprint 6 — Ricerca e condivisione interna

- Estensione della ricerca globale a eventi visibili, passati e cancellati ma
  non alle tombstone.
- Condivisione via Web Share secondo visibilità.
- Condivisione in messaggistica tramite `posts.quoted_event_id` e card evento,
  senza ampliare l'audience originaria e senza trasformarla in Announce.
- Indici e piani delle query verificati su MySQL e compatibili con SQLite.

**Stato:** implementato sul ramo `event_support`, poi confluito in `main`.
La ricerca globale include eventi locali e remoti visibili, compresi eventi
passati e annullati ma non tombstone, e cerca in titolo, summary, content,
categoria, luogo e hashtag. I risultati mostrano card riconoscibili e
privilegiano gli eventi futuri rispetto a quelli passati. Il dettaglio offre
Web Share/copia link soltanto per audience pubbliche e condivisione tramite
messaggio per gli utenti autenticati. I messaggi conservano
`posts.quoted_event_id`, mostrano una card dedicata e federano il permalink
come fallback; un evento ristretto può essere condiviso solo con un Actor che
appartiene già alla sua audience, senza concedere nuovi accessi.
La pagina dedicata a un hashtag mostra inoltre, in una sezione separata dal
feed dei post, fino a sei prossimi eventi pubblici o visibili ai follower:
questa separazione evita di complicare il cursore e la query del feed.

### Sprint 7 — Commenti federati in sola lettura

- Ricezione delle `Note` remote collegate a un Event in `event_comments`, con
  thread, Actor, custom emoji e allegati.
- Rendering nel dettaglio riusando i componenti dei commenti dove sensato.
- Nessun composer locale e nessun invio di commenti in questa macrofase.
- Regressione finale dell'intera ricezione Event e aggiornamento della
  documentazione prima del commit/PR della macrofase.

**Stato:** implementato sul ramo `event_support`, poi confluito in `main`.
Le `Note` pubbliche o non elencate che rispondono a un Event, oppure a
un'altra Note gia' collegata allo stesso Event, vengono salvate in
`event_comments` con thread, Actor, custom emoji, allegati remoti e timestamp.
Create e Update sono idempotenti per URI; Delete svuota il commento senza
spezzare le risposte. Il dettaglio evento mostra il thread in sola lettura e
non espone composer o azioni locali. Le Note dirette vengono ignorate: non
avendo una audience separata per ciascun commento, ereditarne quella
dell'evento potrebbe mostrare un messaggio privato ad altri destinatari.
La cancellazione dell'evento o dell'Actor autore rimuove i contenuti e i media
remoti non piu' referenziati.

### Attività successive alle due macrofasi

- Aggiungere nel profilo di ogni Actor un tab **Eventi** dedicato ai suoi
  eventi, cogliendo l'occasione per riorganizzare in modo coerente i tab già
  presenti nella pagina Actor.
- Studiare la discovery dello storico eventi di un Actor remoto quando viene
  cercato per la prima volta. WebFinger consente di risolvere l'Actor ma non
  garantisce direttamente una collection Event: va verificato sui documenti
  Actor/outbox reali di Mobilizon, Balotta e altre implementazioni se esista
  una collection interoperabile o se sia possibile soltanto ricevere i nuovi
  eventi consegnati all'inbox da quel momento in avanti.

**Stato tab Actor:** implementato. I tab sono ordinati come **Post**, **Foto e
video**, **Eventi**, **Attività**. Il tab Eventi è disponibile per Actor locali
e remoti, mostra per default gli appuntamenti futuri e permette di passare
all'archivio dei passati; include sia gli eventi creati dall'Actor sia quelli
che gli sono attribuiti come organizzatore/distributore, rispettando sempre
l'audience dell'evento. Le due viste usano card e paginazione infinita già
adottate dalla sezione Eventi generale.

**Esito discovery eventi remoti:** la normale `outbox` ActivityPub e' un
possibile fallback per un'importazione opportunistica; gli Actor Group
Mobilizon possono inoltre dichiarare una collection `events` dedicata. La
verifica sui documenti reali ha mostrato che:

- `@agenda@balotta.org` espone nella prima pagina della propria outbox normali
  attivita' `Create` con `object.type = Event` e oggetto inline;
- il gruppo Mobilizon `@locandine@mobilizon.it` espone nello stesso modo gli
  eventi organizzati dal gruppo, anche quando il `Create.actor` e' il profilo
  personale che ha materialmente pubblicato l'evento;
- il profilo personale Mobilizon `@strkai@mobilizon.it` ha invece una outbox
  vuota: aprire quel solo Actor non consente di risalire agli eventi pubblicati
  nei gruppi. Gli eventi diventano comunque visibili anche nel suo tab dopo
  essere stati scoperti tramite l'outbox del gruppo, grazie al legame con
  creator e attribuzioni gia' conservato da OpenBook.
- `@milano@ticketzon.it` e' un Group Mobilizon gia' presente localmente senza
  eventi importati. Il suo documento Actor dichiara sia `events` sia
  `endpoints.events`, entrambi diretti a `https://ticketzon.it/@milano/events`.
  La collection contiene oggetti `Event` inline completi e pubblici; anche la
  normale outbox contiene i corrispondenti `Create(Event)`.
- Ticketzon ordina pero' entrambe le collection dal contenuto piu' vecchio:
  `page=1` contiene eventi di marzo 2024, mentre gli elementi recenti sono in
  fondo a circa 597 pagine. Limitarsi alla prima pagina, come fa oggi il
  backfill dei Post, non scoprirebbe quindi gli eventi utili. La collection
  dichiara `totalItems` e una prima pagina con `next`, ma non un collegamento
  `last`.

L'implementazione proposta deve quindi conservare, tra gli endpoint
dell'Actor, anche la collection `events` quando dichiarata direttamente o in
`endpoints.events`, e preferirla all'outbox generica. Per le collection
Mobilizon prive di `last`, se `totalItems`, dimensione della prima pagina e un
`next` con parametro numerico `page` sono coerenti, OpenBook puo' calcolare e
recuperare soltanto l'ultima pagina; se questi presupposti non valgono non deve
tentare una scansione integrale. In assenza della collection dedicata puo'
usare come fallback la prima pagina dell'outbox e interpretare i
`Create(Event)` trovati.

Il recupero resta best effort, silenzioso e limitato a un piccolo numero di
elementi, con TTL, senza bloccare la pagina in caso di server irraggiungibile.
Ogni elemento deve continuare a passare dal parser e dall'ingester Event
esistenti: l'Actor del `Create` viene risolto separatamente dall'Actor
proprietario dell'outbox, caso necessario per le outbox dei gruppi Mobilizon.
Non devono essere importati da questa discovery contenuti followers-only o
direct, poiche' un fetch pubblico della collection non dimostra che il
visitatore locale ne sia destinatario.

Non e' possibile promettere uno storico completo: alcuni software non
espongono l'outbox, la espongono vuota o non vi pubblicano gli Event. In questi
casi OpenBook conserva il comportamento passivo attuale e apprende gli eventi
soltanto dalle attivita' consegnate alla propria inbox.

**Stato discovery remota:** implementata. `actor_endpoints` conserva ora la
collection `events` e ogni Actor ha un timestamp separato per il relativo TTL.
La pagina Actor tenta il recupero opportunistico per qualsiasi tab; gli Actor
Group gia' in cache vengono aggiornati una volta per scoprire l'endpoint.
L'euristica Mobilizon per l'ultima pagina viene applicata soltanto quando i
metadati di paginazione sono internamente coerenti, confronta le date della
prima e dell'ultima pagina e non attraversa la collection. La prova reale su
`@milano@ticketzon.it` ha salvato l'endpoint dichiarato e importato 12 eventi
recenti (dal 2027 al 2028) partendo da zero, con accesso limitato alle ultime
due pagine. Il fallback `Create(Event)` dalla normale outbox resta disponibile
per implementazioni come Balotta.

**Ricerca diretta per URL:** implementata anche per gli Event. La ricerca
recupera una sola volta il documento ActivityPub e lo interpreta nell'ordine
Actor, Event pubblico/unlisted, feed RSS/Atom. Un Event riconosciuto viene
importato tramite lo stesso ingester usato dall'inbox e apre il dettaglio
locale; Event followers-only/direct non vengono scoperti conoscendone l'URL.
Verifica reale completata con
`https://montreal.askapunk.net/event/varning-saturday-market`, risolto come
evento di `@shows@montreal.askapunk.net` senza creare un Actor feed.

Le location remote con coordinate valide ma prive di localita' o dati del
Paese vengono completate best effort tramite il catalogo locale `geo_cities`.
I valori dichiarati dal server remoto hanno sempre precedenza; la citta' piu'
vicina riempie soltanto i campi mancanti e valorizza `geo_city_id`. Se il
catalogo non e' stato importato, il lookup viene saltato senza query inutili.

Le card presentano la location su livelli distinti: nome del luogo, localita'
e infine `Regione (Nome Paese)`. Quando il nome esteso del Paese non e'
disponibile viene usato il country code; ogni riga priva di dati viene omessa.

- Moderazione/segnalazione locale degli eventi, deliberatamente rinviata a
  dopo il supporto alla creazione e pubblicazione locale.
- Eventuale integrazione nella timeline e semantica completa di boost.
- Mappe, provider geografici, attendee nominativi e politiche amministrative
  di retention, soltanto se motivate dall'uso reale.

## Ricerca sull'interoperabilità in uscita

### Livello 1: ActivityStreams 2.0 (W3C)

`Event` non è un'invenzione Mobilizon: è un tipo dell'ActivityStreams 2.0
Vocabulary. Anche `startTime`, `endTime`, `location`, `Place`, `name`,
`content`, `summary`, `attachment`, `attributedTo`, `published`, `updated`,
`url`, `to`, `cc` e `tag` appartengono al vocabolario standard.

Il nucleo più portabile dell'outbox può quindi usare soltanto proprietà
ActivityStreams standard:

- `type: Event`
- `id` e `url`
- `attributedTo`
- `name` e `content`
- `startTime` e `endTime` opzionale
- `location` come `Place`
- immagine/copertina standard (`attachment` e/o `image`, da verificare sulle
  implementazioni reali)
- `published`, `updated`, audience e hashtag

`Place` è intenzionalmente minimale e flessibile: il W3C contempla sia una
semplice location per nome sia coordinate e altre proprietà. Questo spiega
perché le implementazioni aggiungono estensioni per indirizzi strutturati.

### Livello 2: FEP-8a8e

Esiste una Fediverse Enhancement Proposal specifica, **FEP-8a8e — A common
approach to using the Event object type**, che cerca di uniformare proprio le
divergenze tra implementazioni. Al momento della ricerca (settembre 2026) è
ancora marcata `DRAFT`.

Il lavoro è attivo e finanziato dal progetto **Interoperability of Events in
the Fediverse** di NLnet. Coinvolge il consolidamento della FEP, Event Bridge
per WordPress e l'allineamento con altri progetti. Gancio dichiara già supporto
alla FEP-8a8e.

Conseguenza: FEP-8a8e è oggi la migliore guida pratica per un nuovo producer,
ma non va implementata in modo rigido assumendo che tutti i receiver ne
supportino ogni dettaglio.

### Livello 3: implementazioni reali

- **Mobilizon** usa l'oggetto standard `Event` e lo estende con Schema.org,
  iCalendar e un proprio namespace. Supporta `Create`, `Update`, `Delete`,
  `Announce`, `Join` e `Leave` in relazione agli eventi.
- **Gancio** dichiara interoperabilità diretta con Mobilizon e supporto alla
  FEP-8a8e. Pubblica gli eventi tramite un Actor `Application` di istanza.
- **Friendica** supporta eventi e calendario, ma la piena interoperabilità
  delle azioni RSVP non è scontata.
- **Event Bridge for ActivityPub / WordPress** dichiara consegna degli eventi
  a Mobilizon, Gancio, Friendica, Hubzilla e Pleroma, con fallback leggibile
  anche sulle piattaforme che non comprendono pienamente `Event`.
- **Mastodon** non offre una gestione strutturata degli eventi paragonabile a
  Mobilizon: la compatibilità utile consiste soprattutto nel mostrare una
  rappresentazione testuale/media comprensibile.

Mobilizon e Gancio risultano quindi i riferimenti operativi più maturi, ma il
percorso più promettente per compatibilità ampia è il profilo comune FEP-8a8e,
non l'intero insieme delle estensioni proprietarie Mobilizon.

## Composer locale: decisioni iniziali

La creazione di eventi resta separata dal composer dei post. Un utente locale
puo' raggiungerla dalla pagina generale Eventi oppure dal tab Eventi del
proprio profilo; il form vive su una pagina dedicata e sara' riutilizzato in
seguito anche per la modifica.

Prima iterazione concordata:

- singola pagina, senza wizard o modal;
- sole visibilita' `public` e `unlisted`;
- titolo, descrizione Markdown, inizio obbligatorio, fine facoltativa e fuso
  orario IANA esplicito;
- una cover immagine con testo alternativo, senza video o galleria;
- evento in presenza, online o ibrido; per il luogo fisico si riusa il picker
  `geo_cities`, affiancato da nome del luogo e indirizzo;
- partecipazione aperta a tutti, soggetta ad approvazione oppure con
  registrazione su un sito esterno; la categoria viene omessa per non
  appesantire il primo composer;
- visibilita' espressa come scelta di includere o meno l'evento negli elenchi
  e nella ricerca (`public` oppure `unlisted`), senza suggerire erroneamente
  che un evento unlisted sia privato;
- niente ricorrenze, capienza, prezzi, ticket o lista pubblica nominativa dei
  partecipanti nella prima versione.

Il prototipo e' diventato il composer locale effettivo: il submit valida e
salva l'evento, mentre serializzazione ActivityPub e delivery restano
deliberatamente separate nello sprint successivo.

## Macrofase 2 — Sprint di creazione e pubblicazione locale

### Sprint 1 — Backend del composer e persistenza locale

- `FormRequest` con validazione coerente tra campi condizionali (date, luogo,
  online, registrazione esterna, cover e visibilita');
- servizio applicativo dedicato per creare un `Event` appartenente all'Actor
  locale, con URI/permalink canonici;
- salvataggio atomico di descrizione, hashtag e menzioni, cover con alt text,
  location da `geo_cities` e modalita' online/ibrida;
- attivazione del submit e redirect al dettaglio locale;
- test di autorizzazione, validazione, rollback media e rendering. In questo
  sprint l'evento esiste localmente ma non viene ancora consegnato fuori
  dall'istanza.

**Stato:** implementato. Il composer crea eventi locali `public` o `unlisted`,
normalizza date e timezone senza dipendere dal fuso del server, collega cover,
location, hashtag e menzioni e reindirizza al dettaglio. Le opzioni fisiche,
online e ibride condividono un solo link di partecipazione/registrazione. Il
pulsante di partecipazione sugli eventi locali resta nascosto fino allo sprint
4, per non creare richieste che non possono ancora essere gestite.

### Sprint 2 — Oggetto ActivityPub e prima pubblicazione

- serializer dell'oggetto `Event` con nucleo ActivityStreams portabile e i
  soli campi realmente supportati;
- content negotiation sul permalink locale;
- `Create(Event)` e delivery ai destinatari coerenti con `public`/`unlisted`;
- esposizione degli eventi locali nelle collection Actor/outbox necessarie
  alla discovery remota;
- test dei payload e prime prove reali con Mobilizon, Gancio/Friendica e un
  software microblog che non gestisce nativamente gli Event.

**Stato:** implementato localmente, in attesa delle prove federate reali. Gli
eventi locali espongono tramite content negotiation un oggetto `Event` con
titolo, descrizione HTML, date, timezone, audience, cover, luogo, hashtag,
menzioni e link di partecipazione. Alla creazione viene accodato un
`Create(Event)` verso i follower remoti e gli Actor remoti menzionati,
riusando firma e coda `delivery` esistenti. L'outbox dell'Actor unisce Post ed
Event pubblici/non elencati con una `UNION ALL` ordinata e paginata, senza
caricare l'intera cronologia in memoria. Il payload e la query sono stati
verificati sia su SQLite sia sul MySQL locale; resta da provarne la resa su
Mobilizon/Friendica e su un consumer non specializzato.

Il documento ActivityPub degli Actor locali dichiara inoltre una collection
dedicata `events` sia al primo livello sia dentro `endpoints`, seguendo la
forma osservata su Mobilizon. WebFinger continua a fare il proprio lavoro
standard — risolvere l'Actor — e il server remoto scopre poi questa collection
nel documento Actor. L'endpoint resta stabile anche quando vuoto ed espone,
in pagine ordinate, gli oggetti `Event` pubblici e non elencati; l'outbox
generale continua parallelamente a includerli per i consumer che ignorano
l'estensione dedicata.

### Sprint 3 — Modifica, annullamento e cancellazione

- riuso del composer per modificare soltanto eventi propri;
- aggiornamento di date, descrizione, cover, luogo e opzioni, con `Update`;
- distinzione UI e dominio tra evento annullato e definitivamente eliminato;
- `Delete`, tombstone e pulizia sicura dei media locali;
- controlli di ownership, idempotenza e consegna federata.

**Stato:** implementato. Soltanto il proprietario di un evento locale può
modificarlo, annullarlo o eliminarlo. La modifica riusa il composer e pubblica
un `Update(Event)`; l'annullamento conserva contenuto, permalink e cover,
imposta `EventCancelled` ed è idempotente. L'eliminazione mantiene la riga come
tombstone, rimuove relazioni e dati espositivi, elimina solo i media locali
rimasti senza riferimenti e invia `Delete(Event)` ai follower e agli Actor
remoti precedentemente menzionati. Il permalink negoziato come ActivityPub
restituisce quindi un oggetto `Tombstone`, mentre l'interfaccia mostra un
messaggio esplicito invece di un 404 anonimo.

### Sprint 4 — Partecipazioni verso eventi locali

- ricezione di `Join`, `Undo(Join)` e `Leave` diretti a eventi locali;
- accettazione automatica per gli eventi aperti a tutti;
- interfaccia dell'organizzatore per approvare o rifiutare le richieste quando
  previsto, con `Accept`/`Reject` federati;
- notifiche e conteggi locali senza esporre una lista pubblica nominativa dei
  partecipanti.

**Stato:** implementato. Gli eventi locali ricevono `Join` firmati da Actor
locali o remoti. Le partecipazioni aperte vengono accettate automaticamente
con un `Accept`; quelle soggette ad approvazione restano `pending`, notificano
l'organizzatore e compaiono solamente nel suo pannello privato nel dettaglio
dell'evento. L'organizzatore può inviare `Accept` o `Reject`; per due utenti
della stessa istanza la decisione resta locale e genera la corrispondente
notifica senza accodare consegne inutili. `Leave`, `Undo(Join)` e Undo per solo
riferimento rimuovono la partecipazione, mentre il contatore pubblico include
esclusivamente gli stati accettati. Replay, actor dichiarato diverso dal
firmatario e decisioni di utenti non proprietari sono gestiti in modo
idempotente o rifiutati senza alterare lo stato.
L'organizzatore riceve una notifica sia per ogni nuova partecipazione
accettata automaticamente sia per ogni richiesta che necessita approvazione.

### Sprint 5 — Commenti locali e conversazione federata

- composer dei commenti nel dettaglio evento;
- pubblicazione di `Note` con `inReplyTo` all'Event o a un altro commento;
- cancellazione autorizzata dei commenti locali, ricezione degli `Update` e
  delle cancellazioni remote e supporto ai Like federati;
- riuso, dove possibile, dell'infrastruttura commenti dei post senza unificare
  forzatamente i due modelli.

**Stato:** implementato. Gli utenti autenticati possono commentare un evento
ancora aperto, rispondere nel thread, allegare media e mettere o rimuovere un
Like. Ogni commento locale espone una `Note` ActivityPub con audience coerente
con l'evento e `inReplyTo` verso l'Event o il commento padre; `Create`,
`Delete`, `Like` e `Undo(Like)` vengono consegnati ai destinatari remoti
riusando la coda federata esistente. I commenti ricevuti continuano a
supportare `Create`, `Update` e `Delete`, ora generano le notifiche di
commento/risposta agli utenti locali e accettano Like remoti. La cancellazione
usa una tombstone, rimuove media, menzioni e reazioni ma conserva la riga e le
risposte figlie, come nei thread dei post. La modifica locale del testo non è
stata introdotta: OpenBook non offre oggi questa azione neppure sui commenti
dei post e aggiungerla soltanto agli eventi renderebbe i due flussi
incoerenti.

### Sprint 6 — Rifinitura e interoperabilita'

- revisione completa di query, indici, accessibilita', mobile e testi UI;
- valutare l'inclusione di `event_hashtags` nel calcolo delle tendenze: oggi
  `PopularHashtagsQuery` conta esclusivamente gli utilizzi nei Post;
- verifica di ricerca, profili, outbox, notifiche, condivisione e anteprime;
- matrice delle prove federate reali e correzioni conservative;
- aggiornamento di README, changelog e procedura di attivazione se dovessero
  emergere nuove dipendenze o impostazioni.

**Stato:** completato localmente. La revisione finale non ha introdotto nuove
dipendenze, worker o impostazioni di attivazione. Gli hashtag degli Event
pubblici/non elencati e non annullati partecipano ora alle tendenze mediante
una `UNION ALL` con gli utilizzi nei Post; la query usa gli indici su
visibilità, stato e data di pubblicazione di entrambe le tabelle e le chiavi
delle pivot. Sono stati verificati layout desktop/mobile, assenza di overflow
orizzontale ed errori JavaScript, parità delle traduzioni, sintassi PHP,
formattazione dei file modificati, payload di delivery reali e suite dedicate.
README e changelog descrivono creazione, lifecycle, partecipazioni, commenti e
collection ActivityPub. Restano necessariamente da svolgere su staging le
prove interoperabili reali con server remoti.

### Strategia proposta per l'outbox (da validare nella macrofase 2)

1. Produrre un `Create` con oggetto `Event` e un nucleo ActivityStreams 2.0
   autosufficiente.
2. Allineare forma e requisiti alla FEP-8a8e dove la proposta è stabile e
   supportata dalle implementazioni osservate.
3. Aggiungere poche estensioni Mobilizon/Schema.org soltanto quando veicolano
   dati realmente gestiti da OpenBook e migliorano l'interoperabilità.
4. Rendere `content` comprensibile anche senza UI eventi: titolo, descrizione
   e — se necessario — data e luogo devono produrre un fallback dignitoso sui
   software che trattano l'oggetto come contenuto generico.
5. Non dichiarare proprietà o funzionalità che OpenBook non implementa
   davvero (RSVP, capacità, moderazione partecipanti, ricorrenze, ecc.).
6. Preparare una matrice di prove reali almeno contro Mobilizon, Gancio,
   Friendica e un client microblog prima di considerare stabile l'outbox.

Questa strategia non promette compatibilità letterale con “qualsiasi” server:
ActivityPub consente ai receiver di ignorare tipi o estensioni sconosciuti.
Massimizza però la probabilità di ottenere una card evento completa sui server
specializzati e un contenuto almeno leggibile sugli altri.

### Fonti principali consultate

- W3C ActivityStreams 2.0 Vocabulary:
  https://www.w3.org/TR/activitystreams-vocabulary/
- W3C ActivityStreams 2.0 Core:
  https://www.w3.org/TR/activitystreams-core/
- Mobilizon, Federation with ActivityPub:
  https://docs.mobilizon.org/5.%20Interoperability/1.activity_pub/
- Mobilizon, interoperabilità con Gancio:
  https://docs.mobilizon.org/6.%20Fediverse/3.gancio/
- Gancio, Federation / ActivityPub:
  https://gancio.org/v2/dev/federation
- Indice FEP (stato di FEP-8a8e): https://fep.swf.pub/
- NLnet, Interoperability of Events in the Fediverse:
  https://nlnet.nl/project/Fediverse-event-interop/
- Discussione tecnica che ha portato a FEP-8a8e:
  https://socialhub.activitypub.rocks/t/events-interoperability-validation-minimum-requirements-common-extensions/3849
- Event Bridge for ActivityPub:
  https://wordpress.org/plugins/event-bridge-for-activitypub/
