# Elenchi delle community: tre schede e caricamento progressivo

Analisi per il ramo `comunities_list`, creato da `search_improvements`.
Questo documento definisce il lavoro sull'indice `/community`; il muro dei
post di una singola community e i profili remoti restano nel TODO di
`analysis/LAZY_HOME.md` e `analysis/TODO.md`.

**Stato:** sprint 1 implementato. Le tre schede condividono query e riga,
mostrano gli stati di iscrizione e conservano temporaneamente la paginazione
manuale. Il caricamento a frammenti e le azioni inline restano agli sprint 2
e 3.

## Problema osservato

L'indice presenta oggi due schede, **Locali** e **Remote**. La scheda Remote
contiene due elenchi con scopi diversi: fino a dieci Group remoti suggeriti,
seguiti da almeno un utente locale, e le community remote a cui il visitatore
è iscritto. Queste ultime usano `paginate(20)` e i link di paginazione Laravel.
Una pagina successiva ricarica l'intera vista e ricalcola anche i suggerimenti.
La scheda Locali usa a sua volta `paginate(20)` e navigazione manuale.

Non è lo stesso difetto dello scroll del muro o dei profili: in `/community`
il browser usa davvero la pagina completa, anziché scaricarla per estrarne
solo il centro. Vogliamo però un'interfaccia coerente con Home e Mondo:
cornice disponibile subito, una lista per scheda, frammenti HTML e scroll
infinito. Le query di una scheda non devono essere eseguite per le altre.

Riferimenti attuali: `CommunityController::index()`,
`SuggestedRemoteCommunitiesQuery`, `communities/index.blade.php`,
`communities/_remote_group_list.blade.php`, `public/assets/js/infinite-scroll.js`,
`FollowManager`, `CommunityMembershipService` e `CommunityPolicy`.
I primi due riferimenti ai suggerimenti descrivono il punto di partenza:
lo sprint 1 li sostituisce con `CommunityDirectoryQuery` e
`communities/_directory_list.blade.php`.

## Decisioni funzionali concordate

Tre schede, nell'ordine:

| Scheda | Contenuto | Visibilità dell'iscrizione |
| --- | --- | --- |
| **Le tue community** | Tutti i Group locali e remoti seguiti dall'Actor del visitatore con `Follow` accettato. | Ogni riga resta visibile anche nelle altre schede. |
| **Community locali** | Le community locali visibili al visitatore, incluse quelle a cui è già iscritto. | Pulsante o stato a destra. |
| **Community remote** | I Group remoti conosciuti e mostrabili dall'istanza, inclusi quelli a cui il visitatore è già iscritto. | Pulsante o stato a destra. |

- Nessuna scheda esclude i già iscritti dalle altre. La scheda «Le tue
  community» comprende locali e remote senza duplicati.
- Ogni elenco è ordinato alfabeticamente per handle `@nome`, cioè
  `preferred_username`, poi dominio e identificativo come discriminanti
  stabili; il nome visualizzato non determina l'ordine. Venti righe per
  blocco, scroll infinito e stato vuoto dedicato.
- La cornice HTML contiene titolo e tre schede. La richiesta del contenuto
  restituisce solo l'elenco della scheda attiva e l'informazione necessaria
  per caricare il blocco successivo. Riutilizzare il caricatore e il pattern
  di frammenti esistenti; non creare tre implementazioni JavaScript.
- Su una riga l'utente autenticato può iscriversi o disiscriversi. Per una
  richiesta ancora in attesa si mostra «Richiesta in attesa» con l'eventuale
  azione di annullamento; il proprietario di una community locale vede
  «Creata da te» e non può disiscriversi. I form e le autorizzazioni esistenti
  restano la fonte delle regole di dominio.
- La proposta è simile ai pulsanti della pagina Hashtag per posizione e
  chiarezza dell'azione, non implica riutilizzarne la logica di persistenza:
  l'iscrizione a una community è un `Follow` di un Actor Group.

## Revisione critica dei requisiti

1. **«Tutte» non significa l'intero Fediverso.** La scheda Remote può
   elencare soltanto Group già conosciuti nella cache locale. L'attuale box
   «popolari» ne mostra al massimo dieci e richiede un iscritto locale:
   non è una query riutilizzabile così com'è per il nuovo elenco completo.
   Definire la nuova popolazione come Group remoti attivi e scopribili,
   applicando i blocchi di dominio/Actor già previsti dal progetto. Un Group
   non scopribile ma già seguito dal visitatore deve restare visibile anche
   nella sua scheda Remote, senza diventare visibile agli altri. Così le
   iscrizioni non spariscono dagli elenchi corrispondenti. Non esporre
   contenuti privati: l'elenco contiene solo metadati di Actor che il
   visitatore può già conoscere.
2. **Community locali private.** L'indice attuale mostra ai visitatori le
   pubbliche e, per le private, solo quelle del proprietario o dello staff;
   un membro accettato può vedere il muro privato tramite link diretto ma
   non compare necessariamente nell'indice. «Le tue community» deve
   includere le private cui il visitatore è iscritto. Nella scheda Locali
   si può mostrare una privata al suo membro accettato, oltre a proprietario
   e staff, senza renderla pubblica. Gli ospiti non devono scoprirla.
3. **Stati distinti.** `Follow` ha stati `pending` e `accepted`. «Le tue»
   include solo `accepted`; nelle altre due schede un pending non va
   scambiato per un'iscrizione compiuta. L'owner locale è iscritto alla
   creazione ma `CommunityMembershipService` e la policy impediscono di
   abbandonare la propria community. La UI deve riflettere queste regole,
   senza aggirarle tramite un nuovo endpoint.
4. **Ospiti e scheda iniziale.** Per l'utente autenticato la prima scheda è
   «Le tue community». Per l'ospite è «Community locali»; i tre pulsanti
   restano visibili e «Le tue» mostra un invito al login, senza eseguire
   query di iscrizioni senza Actor. Locali e Remote restano consultabili
   dagli ospiti, con invito all'accesso per le azioni.
5. **Azioni durante lo scroll.** I form attuali inviano POST/DELETE e fanno
   redirect alla pagina corrente. Su un elenco già srotolato questo perde
   la posizione e i blocchi caricati. Prevedere aggiornamento della singola
   riga senza ricostruire la lista, con form HTML funzionante anche se lo
   script non intercetta l'invio. Il server deve sempre riautorizzare l'azione.
6. **Ordinamento e paginazione stabili.** Per `@nome` identici su domini
   diversi servono dominio e ID come tie-breaker. Verificare l'ordinamento
   senza distinzione di maiuscole tra MySQL/MariaDB e SQLite. Preferire un
   cursore stabile a un offset se evita duplicati/salti quando arrivano
   Group o cambiano iscrizioni durante lo scroll; preservare comunque
   venti righe per blocco e URL navigabili direttamente.
7. **Costo delle query.** Il vecchio elenco Remote interroga solo i Group
   seguiti dal visitatore; il nuovo enumera potenzialmente tutti i Group
   remoti noti. Verificare con dati rappresentativi l'accesso a `actors`,
   `follows` e `communities`, l'ordinamento e ogni eventuale `UNION ALL`.
   Un indice va aggiunto solo dopo `EXPLAIN` MySQL/MariaDB sul piano reale;
   la suite SQLite da sola non basta. Evitare conteggi globali necessari
   soltanto per disegnare una paginazione numerica che non useremo.

## Analisi implementativa

### Query e modello di vista

Introdurre una query applicativa dedicata ai tre scope, invece di far crescere
`CommunityController` con tre catene Eloquent. Può restituire righe basate
sull'Actor Group e i dati locali essenziali della `Community` quando esiste.
Usare lo stesso partial per avatar, nome, handle, descrizione e azione; la
query deve fornire la pagina di 20 elementi più l'indicazione di prosecuzione.

- **Mine:** partire dai `follows` accettati del viewer verso Actor Group;
  includere Group locali e remoti, anche una community locale privata seguita.
- **Locali:** partire da `communities` con Actor locale, rispettando
  l'accesso ai metadati delle private; nessuna esclusione dei già iscritti.
- **Remote:** partire dagli Actor Group remoti attivi, filtrare la
  moderazione e includere quelli scopribili oppure già seguiti dal viewer;
  non richiedere che abbiano già iscritti locali e non escludere i già
  iscritti.

Caricare gli stati `accepted`/`pending` delle sole venti righe con una query
in blocco, come `FollowManager::statusMapFor()`: niente verifica `isMember()`
per ogni card. Per i Group locali usare `CommunityPolicy` e le route
`communities.join`/`communities.leave`; per quelli remoti le route
`actors.follow`/`actors.unfollow`. Non introdurre un secondo flusso di
federazione. Se si decide un aggiornamento inline, aggiungere una risposta
JSON ai controller esistenti mantenendo il redirect HTML, oppure una risposta
minima equivalente; il client deve aggiornare solo la card interessata.

### Risposte HTML e interazione

Usare `/community?scope=mine|local|remote` come URL di pagina e di frammento
AJAX, analogamente a `/home` e `/mondo`; `scope` mancante può rappresentare
la scheda predefinita del visitatore. La risposta normale è la cornice con
tab, un contenitore di caricamento e un messaggio `<noscript>` chiaro. La
risposta AJAX contiene soltanto il partial dell'elenco e l'URL del blocco
successivo. Il JavaScript di `infinite-scroll.js` gestisce primo blocco,
successivi, fine ed errore/riprova; estenderlo solo se il contratto esistente
non copre un caso verificato. I frammenti non devono richiamare il layout
completo né preparare query delle altre schede.

Le URL dirette con `scope` e cursore/pagina devono mantenere l'identità della
scheda e caricare il blocco richiesto. I pulsanti devono avere etichette
accessibili e mostrare lo stato dopo un'azione. Mantenere traduzioni italiane
e inglesi allineate. Aggiornare changelog e documentazione d'uso se il
comportamento finale cambia come previsto.

## Sprint verificabili

### Sprint 1 — Contratto delle tre schede e dati

- Introdurre gli scope Mine/Local/Remote, le query dedicate e un partial di
  riga condiviso. Rimuovere il doppio blocco della vecchia scheda Remote.
  In questa tappa la paginazione può rimanere manuale: consente di provare
  popolazioni, ordinamento e azioni prima dell'asincronia.
- Verificare: venti elementi per pagina, ordine per handle, nessuna esclusione
  dei già iscritti da Locali/Remote, private locali visibili soltanto ai
  soggetti autorizzati, ospiti, pending e owner. Controllare i piani MySQL
  delle tre query, adeguando gli indici solo dove serve. Prova manuale delle
  tre schede e dei pulsanti.

**Esito tecnico:** test funzionali della sezione Community verdi. Su MySQL
locale (22 Actor) `EXPLAIN` sceglie `actors_type_index` per Mine/Remote e la
chiave univoca `(follower_id, following_id)` per il controllo del Follow;
Locali parte da `communities_actor_id_unique` e risolve l'Actor per chiave
primaria. L'ordinamento usa filesort su questo campione ridotto: prima della
PR o di un'eventuale migrazione, ripetere la misura su dati rappresentativi
di produzione, senza assumere che il piano locale predica il costo reale.

### Sprint 2 — Cornice e scroll dei tre elenchi

- Rendere la pagina normale una cornice e servire con AJAX il primo blocco e
  i successivi tramite lo stesso partial. Riutilizzare `infinite-scroll.js`,
  mantenendo URL dirette e stato della scheda. Ogni richiesta esegue solo la
  query del suo scope; eliminare la paginazione numerica visibile.
- Verificare: tempi della cornice e del primo blocco separati; una risposta
  AJAX non contiene layout, suggerimenti o altre liste; scroll 20+20 senza
  duplicati, fine elenco, schede vuote, cambio scheda, reload diretto e
  errore/riprova. Test funzionali su SQLite e controllo delle query/piani
  MySQL per i blocchi successivi.

### Sprint 3 — Azioni senza perdere lo scroll e rifinitura

- Mantenere le route e le autorizzazioni esistenti; aggiornare inline la
  card dopo iscrizione, annullamento richiesta o uscita, lasciando il form
  HTML come fallback. Mostrare chiaramente pending, owner e accesso richiesto
  agli ospiti. Allineare testi, stati accessibili, documentazione e changelog.
- Verificare: azioni dopo il secondo blocco senza ritorno all'inizio,
  fallimenti mostrati senza alterare lo stato della card, private locali,
  stato remoto pending/accepted e protezione dell'owner. Rieseguire i test
  mirati e la suite completa prima della PR.

Ogni sprint si ferma a una prova visiva e funzionale prima del successivo.
Questo lavoro riguarda l'indice delle community; lo scroll del muro dei post
e dei profili resta un intervento distinto già annotato nel TODO.
