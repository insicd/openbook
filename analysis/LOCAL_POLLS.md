# Sondaggi locali — issue #121

Stato al 15 ottobre 2026: analisi congelata nel commit `4452763`; sprint 1
implementato e in verifica prima della chiusura. Sprint successivi da avviare.
Issue: https://github.com/insicd/openbook/issues/121
Base: ramo `issue_121`, creato dalla punta di `issue_120`, commit `f21f385`.
Dipendenza: [sondaggi remoti — #120](REMOTE_POLLS.md).

## Obiettivo e perimetro dell'issue

Consentire agli utenti Openbook di allegare un sondaggio a un post, indicando
opzioni, scelta singola o multipla e durata. Raccogliere sia i voti degli utenti
locali sia quelli degli Actor remoti autorizzati a vedere il post. Openbook è
l'origine del sondaggio e quindi l'autorità sui risultati.

L'issue fissa già questi comportamenti:

- Voto definitivo, senza modifica o annullamento.
- Opzioni, modalità e scadenza immutabili dopo la pubblicazione; il testo del
  post rimane correggibile.
- Risultati visibili dopo il voto o alla chiusura, con possibilità di
  consultarli anche senza votare.
- Pubblicazione, aggiornamento dei risultati e chiusura comunicati alle
  istanze compatibili attraverso la federazione esistente.
- Rispetto di visibilità, nascondimento e blocchi del sistema.
- Sondaggi nei post; commenti ed eventi esclusi.

Limiti, inclusione dei messaggi diretti e compatibilità sono esplicitamente
affidati all'analisi. Le sezioni successive riportano le decisioni concordate
e gli indirizzi tecnici da completare negli sprint.

### Perimetro intenzionalmente escluso e da dichiarare nella PR

Decisioni concordate con l'utente:

- Creazione e pubblicazione di sondaggi nelle conversazioni private: rinviate
  a un intervento successivo.
- Pubblicazione di sondaggi verso community remote (Group remoti): esclusa
  dalla #121; richiede una verifica dedicata del supporto e della distribuzione
  da parte dei server destinatari.
- Modifica della data di chiusura e chiusura manuale anticipata: rinviate a
  un intervento successivo; nella #121 si usa la scadenza programmata.

Queste esclusioni sono scelte intenzionali di perimetro, da riportare
esplicitamente nella descrizione della PR. Non riducono il supporto della #120
alla visualizzazione e al voto dei sondaggi remoti. Le community locali
pubbliche e private sono invece incluse, con i normali permessi del post.
Commenti ed eventi restano esclusi come già previsto dall'issue.

## Continuità con Openbook e con la #120

L'operazione dell'utente termina con il salvataggio durevole e la registrazione
del lavoro federato necessario, senza attendere la consegna remota. Nessun
worker permanente, Redis, servizio esterno o polling dedicato.

Per un sondaggio locale i voti accettati aggiornano subito i conteggi locali:
qui Openbook conosce il risultato perché lo produce. Per un sondaggio remoto
resta valida la #120: evidenziare la scelta personale e usare esclusivamente i
conteggi ricevuti dall'origine. Non sommare i due modelli di conteggio.

Decisione concordata: gli altri utenti vedono soltanto i risultati aggregati
e ciascun votante vede le proprie scelte. L'autore del sondaggio può consultare
anche il dettaglio delle risposte, con i votanti per opzione, locali e remoti.
Nessun elenco pubblico dei votanti, notifica ordinaria per ogni risposta o
commento generato da una Note di voto. Non promettere anonimato verso
l'autore, l'istanza che raccoglie il voto o le istanze coinvolte nella consegna.

## Ricognizione del codice esistente

| Punto | Comportamento attuale e conseguenza |
| --- | --- |
| `PostComposer::compose/update` | Crea e modifica post, menzioni, community e allegati, poi usa `ActivityDelivery`. Integrare qui la creazione del sondaggio nella transazione del post. |
| `StorePostRequest`, `UpdatePostRequest` | Validano i composer. Oggi la modifica consente anche visibilità e allegati: applicare ai sondaggi l'immutabilità concordata della visibilità, preservando i normali controlli sugli allegati. |
| `PostPublicationStager`, `PendingPostFinalizer` | I video possono ritardare la pubblicazione; lo snapshot attuale non conserva dati del sondaggio. Preservare la definizione e far partire la durata dalla pubblicazione effettiva. |
| `NoteSerializer::forPost`, `ActivitySerializer::create/update` | Producono Note condivise da pubblicazione e outbox. La Question deve essere la stessa in tutti i percorsi, incluso il GET ActivityPub canonico. |
| `InboxActivityProcessor::handleCreateOrUpdate` | Scarta attualmente tutte le Note di voto prima del flusso commenti/DM. Inserire la raccolta dei voti verso Question locali in questo punto, dopo le necessarie verifiche di origine e autore. |
| `InboxItem::received_at` | Esiste già una data locale di ricezione, distinta da `processed_at` e dalle date dichiarate dal mittente. È utilizzabile per la decisione sulla scadenza. |
| `Post::visibleTo/visibleToActorId` | Riceve un Actor anche remoto; riusare audience e membership, senza tradurre arbitrariamente un votante remoto in un utente locale. |
| `Poll`, `RemotePollVoter`, `RemotePollInteractionPolicy` | Sono intenzionalmente specifici dell'origine remota. La policy richiede post remoto e votante locale: non applicarla direttamente alla raccolta dei voti locali/federati. |
| `posts._poll`, `PostPolicy::vote`, `RemotePollVoteController` | Oggi dipendono da `remotePoll`. Condividere il rendering dove utile, mantenendo esplicita la scelta del servizio di voto in base all'origine. |
| `ActivityDelivery`, `DeliverActivityJob`, `local_delivery` | Riutilizzare firma, coda database, retry e deduplicazione delle inbox. L'accodamento diretto transazionale della #120 non risolve da solo il fan-out di una Question. |
| `CronCommand` | Coordina inbox e consegna con esecuzioni limitate; inserire manutenzione dei sondaggi entro il flusso esistente, senza richiedere una nuova configurazione cron. |

La ricognizione è stata sul codice, sull'issue e sulle fonti di protocollo.
Non sono state eseguite migration, modifiche ai dati applicativi o consegne
federate. Il campione inbox documentato nella #120 contiene Question remote,
non dimostra la raccolta di voti per sondaggi originati qui. Servono fixture di
voto realistiche e test isolati, senza dipendere dal DB personale.

## Protocollo e limite del voto definitivo

La [documentazione Mastodon sui sondaggi](https://docs.joinmastodon.org/spec/activitypub/#polls)
descrive Question incorporate in Create, opzioni in `oneOf`/`anyOf` e conteggi
per opzione in `replies.totalItems`. Le risposte sono Create/Note con `name`
esattamente uguale all'opzione e `inReplyTo` uguale all'URI della Question.
Nella scelta multipla ogni opzione può arrivare in un'attività distinta.

**Vincolo di protocollo:** una risposta remota non contiene necessariamente
l'intera scheda né un segnale di fine.
Bloccare tutte le scelte dopo la prima Note perderebbe voti multipli validi.
Non esiste una finestra temporale affidabile per ricostruire un unico invio.

Decisione concordata con l'utente:

- Voto locale: salvare l'intera selezione in una transazione e renderla subito
  definitiva, come nella #120.
- Voto remoto singolo: la prima scelta valida elaborata è definitiva;
  altre scelte dello stesso Actor sono ignorate.
- Voto remoto multiplo: accettare ogni coppia Actor/opzione una volta, fino
  alla scadenza; nessuna sostituzione o rimozione. Il protocollo non consente
  di distinguere altre opzioni dello stesso invio da aggiunte successive.
- Contare un votante una volta, indipendentemente dal numero di opzioni.
- Update/Delete/Undo del voto non cambiano la scelta già accettata.

La decisione conserva l'irrevocabilità delle singole scelte e accetta il
limite della definitività della scheda remota: ulteriori opzioni distinte
possono essere ricevute fino alla scadenza. L'interfaccia Mastodon rende la
selezione definitiva dopo la conferma; il protocollo non comunica questo
confine al server che raccoglie le Note di voto.
È coerente con la ricezione per opzione descritta da Mastodon; il suo
[codice di raccolta](https://github.com/mastodon/mastodon/blob/main/app/lib/activitypub/activity/create.rb)
conserva voti per scelta e incrementa i votanti alla prima risposta.

La [specifica ActivityStreams](https://www.w3.org/TR/activitystreams-vocabulary/#dfn-question)
definisce il vocabolario; per l'interoperabilità implementare il formato
effettivo sopra, senza assumere che il solo tipo Question garantisca supporto
universale. Mastodon è il primo obiettivo. Compatibilità con altre piattaforme
da documentare sulla base di fixture/fonti e prove, senza dichiararla già acquisita.

## Esperienza utente e limiti concordati

| Aspetto | Comportamento | Stato |
| --- | --- | --- |
| Definizione | Un sondaggio per post; domanda nel testo, opzioni in testo semplice. | Impostazione coerente con il composer discusso e il perimetro dell'issue |
| Numero di opzioni | Da 2 a 6; il composer parte con due campi e «+ Aggiungi opzione» aggiunge un campo alla volta fino al massimo. Le opzioni aggiunte si possono rimuovere mantenendone almeno due prima della pubblicazione. | Numero massimo e aggiunta progressiva concordati; rimozione come normale controllo del composer |
| Testo delle opzioni | Massimo 50 caratteri Unicode ciascuna, distinte dopo trim; confronto esatto e case-sensitive dei nomi federati. | Limite di lunghezza concordato, distinto dal limite difensivo di 1.000 caratteri della #120 |
| Durata | Obbligatoria, da 5 minuti a 7 giorni, default 1 giorno. Menu nel composer: 5 minuti, 30 minuti, 1 ora, 6 ore, 1 giorno, 3 giorni, 7 giorni. | Concordato |
| Risultati | All'apertura mostrare le opzioni; dopo il voto o alla chiusura mostrare i risultati. «Mostra risultati» permette di consultarli prima del voto e di tornare alla selezione per votare finché il sondaggio è aperto. | Concordato |
| Dettaglio per l'autore | Popup o pagina separata, con un tab per opzione e i votanti per quell'opzione, locali e remoti. Elenchi paginati; gli altri utenti vedono solo aggregati e proprie scelte. | Concordato; scelta popup/pagina lasciata all'implementazione |
| Autore | Può votare, con le stesse regole degli altri. | Concordato |
| Media/citazioni | Consentire la composizione con immagini, audio, video e citazioni già supportati; una citazione visualizza il sondaggio originale in sola lettura. La durata parte dalla pubblicazione effettiva del post, anche dopo l'elaborazione di un video. | Concordato; coprire anche lo staging video |
| Audience | Pubblico, non elencato e follower, con i normali permessi del post. | Concordato con l'esclusione dei messaggi diretti |
| Messaggi diretti | Sondaggi nelle conversazioni private esclusi dalla #121 e rinviati a un intervento successivo. Richiedono integrazione con composer e policy DM, non solo un valore `direct` nel form. | Concordato |
| Community locali | Consentire i sondaggi nelle community locali pubbliche e private, rispettando i normali permessi di visibilità e interazione del post. | Concordato |
| Community remote | Pubblicazione verso Group remoti esclusa dalla #121; supporto e distribuzione da verificare in un intervento dedicato. Resta il supporto della #120 alla visualizzazione e al voto dei sondaggi remoti. | Concordato; esclusione intenzionale da dichiarare nella PR |
| Modifica del post | Correggere testo, titolo, avviso e lingua; mantenere la visibilità fissata alla pubblicazione. I permessi correnti di accesso e interazione restano verificati, comprese variazioni di follow e membership. | Visibilità immutabile concordata |
| Chiusura | Chiusura automatica alla scadenza fissata alla pubblicazione, senza chiusura manuale anticipata o proroga nella #121. Eventuali modifiche alla data di chiusura sono rinviate a un intervento successivo. | Concordato; limite intenzionale da dichiarare nella PR |

Il comando «Mostra risultati» è una preferenza di presentazione. I risultati
aggregati sono consultabili da chi può vedere il post e vengono federati;
non promettere segretezza dei conteggi prima del voto. Evitare di introdurre
una preferenza persistente in DB per la sola apertura dei risultati.

Riusare testi positivi, «Sondaggio aperto», «Sondaggio concluso», «Voto
registrato» e «La tua risposta», traduzioni EN/IT e controlli nativi accessibili.
Il normale form deve funzionare senza JavaScript; eventuale aggiornamento
della card soltanto su azione dell'utente, senza timer di refresh.

### Dettaglio dei voti riservato all'autore

Includere già nella #121 la consultazione richiesta dall'utente: un accesso
dedicato dalla card o dal dettaglio del proprio sondaggio apre un popup o una
pagina con un tab per opzione. Ogni tab elenca i profili dei votanti di quella
risposta, locali e remoti, con paginazione e ordinamento stabile. Nel multiplo
una persona compare in ciascuna opzione scelta. Non caricare tutti i votanti
nel feed né renderizzare anticipatamente tutti i tab.

Autorizzare sul server ogni richiesta di elenco/pagina: solo l'utente
proprietario dell'Actor autore, con accesso corrente al post e account attivo.
La gestione della community o il ruolo di moderatore non concedono da soli
accesso a questo dettaglio. Validare che l'opzione appartenga al sondaggio;
nessuna esposizione tramite HTML nascosto, JSON delle card, ActivityPub o
cache condivise. Risposte personalizzate con cache privata, senza memorizzazione.

Il contributo degli account cancellati resta nei conteggi concordati, senza
identità consultabile: se utile mostrare il numero aggregato di queste risposte
nel tab, senza profili o identificativi recuperabili. Verificare gli indici e
il piano MySQL della query paginata con un campione di almeno 200 votanti.
Prima del voto comunicare che l'autore può vedere le risposte individuali.
Per i votanti remoti non possiamo imporre il testo mostrato dal loro client.
Riportare il comportamento anche nella documentazione e nella PR, senza
descrivere il sondaggio come anonimo.

## Scadenza e ricezione differita

Per il voto locale controllare l'ora del server sotto lock alla conferma.
Alla scadenza il voto è chiuso anche se il cron non ha ancora materializzato
la chiusura o distribuito l'Update. UTC in persistenza e protocollo.

Decisione concordata: per il voto remoto usare il `received_at` dell'inbox autenticata:
un voto ricevuto prima della scadenza rimane ammissibile se il cron lo elabora
dopo. La data `published` dichiarata dal mittente non concede retrodatazioni;
un'attività arrivata dopo la scadenza è tardiva anche se inviata prima.

Questa è una decisione di prodotto, con conseguenze tecniche: dopo la chiusura
potrebbero arrivare ai risultati ulteriori voti già ricevuti in tempo e ancora
in coda. In quel caso mantenere la chiusura e inviare un Update dei conteggi.
I controlli correnti di permesso/moderazione restano necessari al processamento;
il timestamp non conserva un diritto di accesso revocato nel frattempo.

La latenza del cron non esclude voti ricevuti in tempo. Non introdurre una
tolleranza arbitraria né fidarsi dell'orologio remoto.

Conservare la ricezione originale nei retry e nel replay. Quando il voto è
incorporato, valutare il payload autenticato conservato nell'inbox; se è
disponibile solo tramite URI, il fetch deve verificarne identità e autore.
Non attribuire automaticamente a una versione modificata dopo la scadenza
la data della prima ricezione: risolvere questo caso nello sprint 2 con una
regola conservativa e una fixture dedicata, senza accettare nuove scelte tardive.

## Persistenza e integrità: indirizzo architetturale proposto

Decisione rivista e concordata dopo lo sprint 1: modello e persistenza unici,
come per Post. `Poll`, `PollOption`, `PollVote`, `PollVoteChoice` collegano
post, opzioni e Actor con un'unica relazione `Post::poll`; l'origine deriva
dal post, senza duplicarla in un flag. Autorità locale e cache remota restano
distinte nei servizi e nelle regole di aggiornamento, non nelle tabelle.

La migration `2026_10_15_000003_unify_poll_tables` rinomina prima le quattro
tabelle della #120 e poi aggiunge i campi necessari ai sondaggi locali. Le
precedenti migration applicate restano invariate. La creazione parallela di
tabelle locali dello sprint 1 era ancora Pending nel DB dell'utente ed è
stata sostituita, senza una migration intermedia inutile. Preservare UUID,
snapshot, valori sconosciuti e attività accodate; evitare un framework
generico di provider dei sondaggi.

Vincoli minimi:

- Un sondaggio per post, opzioni ordinate con identità stabile e immutabile.
- Un voto per coppia sondaggio/Actor; una scelta per coppia voto/opzione.
- Identità del voto federato conservata e protetta dal riuso per un'altra
  scelta; deduplicare anche quando lo stesso voto arriva con nuovi ID attività.
  Per URI lunghi valutare hash univoco e testo originale, compatibile con gli
  indici MySQL senza affidarsi alla collation del nome opzione.
- Votanti e conteggi per opzione aggiornati nella stessa transazione delle
  scelte. Incrementare solo per un inserimento nuovo; retry e replay invarianti.
- Lock nello stesso ordine per voto, chiusura e modifica: Post, sondaggio,
  opzioni; vincoli univoci come difesa aggiuntiva contro la concorrenza.
- Conteggi iniziali noti e pari a zero. Distinguere numero di persone e numero
  di scelte; nessuna somma delle scelte come sostituto dei votanti multipli.
- Nessun dato personale delle scelte nel documento Question, nelle collection
  replies pubbliche o nelle risposte JSON condivise/cache.

La pubblicazione è quella del Post; la scadenza appartiene al sondaggio.
Non aggiungere timestamp automatici duplicati né un generico `observed_at`.
Date operative per chiusura, revisioni e pianificazione vanno giustificate dal
flusso che le usa; i voti mantengono la data dell'azione/ricezione pertinente.

Decisione concordata sulla cancellazione del votante: conservare il voto e le
scelte nei conteggi, scollegandoli dall'account eliminato. Indirizzo tecnico:
`actor_id` nullable su `poll_votes`, foreign key `ON DELETE SET NULL`
(`nullOnDelete()`), senza cascade dal votante verso voto/scelte.

Il vincolo copre la cancellazione fisica dell'Actor. Nel modello attuale gli
Actor possono invece restare con stato `deleted`, e la cancellazione dello
User azzera il loro `user_id`: questi percorsi richiedono lo scollegamento
esplicito del voto nel servizio pertinente. Rimuovere anche gli identificativi
federati del voto che mantengono un collegamento personale; il solo `actor_id`
nullo non basta a garantire lo scollegamento se rimangono URI riconducibili
all'Actor. Coprire nello sprint 1 tutti i percorsi effettivi di cancellazione.
Preservare la deduplicazione dopo lo scollegamento: il replay dell'inbox o la
ricomparsa di un URI già cancellato non devono contare nuovamente il voto.
Scegliere il meccanismo tecnico minimo compatibile con la gestione esistente
degli Actor eliminati, senza conservare URI personali nel dettaglio dei voti.
Questo scollegamento riguarda il dominio dei sondaggi; non promette la
cancellazione dei payload grezzi già conservati nell'inbox, che seguono la
manutenzione e la conservazione esistenti.

Decisione concordata: sospensione o blocco del partecipante successivi al voto
conservano il voto già accettato e i conteggi. I controlli correnti restano
applicati alle nuove scelte e ai voti ancora da elaborare.
Eliminare scelte e opzioni con il post, comprese le cancellazioni logiche che
lasciano una tombstone: conservare in questo caso soltanto i metadati del
sondaggio necessari a riconoscere `formerType: Question`. La cancellazione
fisica del post elimina anche questi; una foreign key copre solo quest'ultima.
Fermare anche il lavoro pendente di aggiornamento/chiusura e impedire che job
già accodati ripubblichino una Question eliminata. Verificare questo ciclo di
vita insieme alle relazioni, ai contatori e alla concorrenza negli sprint 1/3.

## Autorizzazioni e confini di federazione

Usare un servizio di voto locale condiviso dalle chiamate web e dall'ingresso
federato; gli adattatori validano la forma dell'input e la sua provenienza.
Il servizio verifica stato pubblicato, autore e votante attivi, visibilità,
membership pertinente, blocchi applicabili, scadenza e opzioni sotto lock.
L'accesso in lettura da solo non prova l'autorizzazione all'interazione.

Per il voto remoto verificare inoltre attività Create, Actor autenticato,
`attributedTo` coerente, origine dell'ID Note, target canonico locale e
destinatario previsto. Un'inbox pubblica o l'URI noto del post non concedono
accesso ai follower-only o alle community private. Evitare fetch di nuovi
contenuti a partire dal target del voto: cercare soltanto sondaggi locali noti.

Il riconoscimento deve avvenire prima della trasformazione in commento/DM.
Voti scaduti, invalidi o diretti a Question sconosciute continuano a essere
scartati, non trasformati in contenuti ordinari. Gli Update del voto non
devono finire nel percorso di aggiornamento di un post/commento.

Gli aggregati ereditano integralmente la visibilità del post, anche nel GET
ActivityPub, outbox, featured, Announce e citazioni. La distribuzione dei
risultati usa l'autore del sondaggio come firmatario e il pubblico autorizzato;
non distribuisce le Note di voto. Oltre ai destinatari ordinari, includere i
votanti remoti ancora autorizzati: un votante di un post pubblico può non
seguire l'autore. Nessun invio a relay per contenuti riservati.
Nelle community private i destinatari ordinari sono i membri autorizzati del
Group secondo il flusso esistente, non i follower personali dell'autore.
Le correzioni del testo non devono ampliare implicitamente il pubblico:
validare anche menzioni e destinazioni effettive, oltre al valore di visibilità.
La membership corrente continua a determinare l'accesso, come concordato.

## Aggiornamenti, chiusura e affidabilità

Pubblicare Create(Question) tramite il flusso dei post. Il documento canonico
espone sempre i conteggi correnti e `endTime`; a scadenza espone `closed`
anche prima del passaggio del cron. `updated` della Question deve riflettere
anche le revisioni dei risultati, senza marcare il testo del Post come modificato
a ogni voto. Una correzione del testo resta un Update della stessa Question.

Decisione concordata: raggruppare gli aggiornamenti dei risultati al passaggio
del cron generale `openbook:cron`. Un comando interno, per esempio
`openbook:process-polls`, prepara gli aggiornamenti pendenti e le chiusure
scadute, accodandoli nella normale coda federata. Non richiedere un nuovo cron
all'operatore. Firma, invio e retry restano nel processo di consegna esistente.

Registrare nella transazione del post il lavoro federato necessario alla
pubblicazione e, nella transazione del voto, la revisione e la necessità di
distribuire i risultati. Il comando accoda uno snapshot coerente e recuperabile;
la chiusura ha priorità e non aspetta un altro voto o una visita alla pagina.
I voti in ingresso restano nel normale processo inbox; i conteggi locali si
aggiornano quando i voti vengono accettati, senza attendere questo comando.

La cadenza dipende dal cron generale, normalmente ogni minuto, e non è una
garanzia di consegna: cron in ritardo e retry possono posticiparla. Nessun
polling remoto o richiesta periodica aggiunta alle schede browser.

Prima di implementare il fan-out dimostrare il contratto di recupero:

- Un crash fra commit del voto e accodamento non perde l'aggiornamento.
- Accodare e riconoscere una revisione non cancella un voto concorrente più
  recente; riconoscere soltanto la revisione effettivamente accodata.
- Snapshot, ID attività e revisione restano stabili nei retry; gli ID basati
  solo sui millisecondi non bastano per identificare ogni revisione concorrente.
- Il crash durante la preparazione dei destinatari o un fan-out parziale è
  recuperabile. Non considerare completo il lavoro perché `deliverContent`
  è stato chiamato: oggi accoda dopo commit e può saltare destinazioni.
- Usare il backend durevole `local_delivery` e gli stessi job di consegna;
  nessun HTTP/DNS sotto lock. Definire nello sprint 3 come rendere durevole
  il fan-out e come venga consumato dal comando esistente anche con driver
  globale `sync` o diverso dal database del dominio.
- Chiusura idempotente anche con cron concorrenti, nessun nuovo Update vuoto
  a ogni passaggio. Eventuali voti ricevuti in tempo correggono i conteggi
  senza riaprire il sondaggio.
- Query a batch limitati e recupero dell'arretrato senza affamare inbox e
  consegne; controllare EXPLAIN delle selezioni scadute e delle revisioni pendenti.

Il dettaglio tra revisione pendente e job di distribuzione è progettazione
tecnica dello sprint 3: scegliere la soluzione minima che provi questi
requisiti, senza introdurre un secondo sistema generale di outbox.

## Sprint di implementazione e verifiche lungo la strada

### Sprint 1 — Fondamenta e pubblicazione della Question

Fissare modello, limiti e ciclo di vita; creare migration e fixture. Integrare
la definizione del sondaggio nella transazione del PostComposer e nella
serializzazione condivisa. Opzioni/modalità/scadenza immutabili anche con
richieste costruite manualmente. Coprire lo staging video e predisporre il lavoro
federato durevole richiesto dagli sprint successivi. Includere ciclo di vita di
post/votanti e conservazione dei contributi dopo la cancellazione dell'account.

Verifica: PHPUnit su validazione, rollback e immutabilità; migration/rollback
SQLite e MySQL, vincoli e confronto nomi Unicode/case-sensitive; GET ActivityPub,
Create e outbox con lo stesso URI e audience. In locale si potrà ispezionare
una Question creata tramite servizio/fixture, prima del composer completo.
Controllare cancellazione fisica/logica e aggiornamenti del testo senza
modifica di definizione, scadenza, visibilità o pubblico effettivo.

### Sprint 2 — Raccolta dei voti locali e federati

Servizio transazionale comune, ingresso inbox prima di commenti e DM,
deduplicazione e conteggi autoritativi. Applicare la decisione sulla ricezione
prima della scadenza e il limite del voto multiplo federato.

Verifica: fixture realistiche singole/multiple, duplicate e fuori ordine;
Actor falsificato, audience riservata, dominio bloccato, post rimosso,
scadenza al confine, inbox arretrata. Concorrenza reale MySQL per due invii
dello stesso Actor e per Actor diversi. Nessun commento/notifica/DM generato.
In locale confrontare le righe di voto e i conteggi dopo un replay nel DB di test.

### Sprint 3 — Distribuzione dei risultati e chiusura

Raggruppamento delle revisioni, accodamento recuperabile, destinatari dei
risultati e chiusura tramite comando interno richiamato da `openbook:cron`,
prima della normale consegna quando il budget lo consente. Nessun nuovo cron
richiesto all'hosting; firma, invio e retry restano nel flusso esistente.

Verifica: orologio controllato, cron ripetuto/concorrente, errori di accodamento,
crash e recupero, aggiornamento durante il fan-out, voto ricevuto in tempo ma
processato dopo la chiusura. Controllare firma, snapshot e audience dei job,
consumo dal comando esistente, EXPLAIN MySQL e budget su arretrati realistici.
Primo confronto con una seconda istanza di test, senza destinatari reali.

### Sprint 4 — Composer e interfaccia completa

Campi opzioni/modalità/durata, validazione conservando gli input, voto nel
feed/dettaglio, «Mostra risultati» e scelta personale. Riusare l'esperienza
remota senza cambiare il suo contratto sui conteggi. Gestire editor, avvisi,
citazioni e audience delle community incluse nel perimetro. Comunicare prima
del voto che l'autore può consultare le risposte individuali.

Verifica: HTTP/Blade, privacy fra utenti, HTML senza JavaScript, tastiera,
mobile, scelta multipla, doppio invio e ricaricamento; scadenza mentre il form
è aperto. Prova manuale con due utenti locali e un votante su istanza di test.

### Sprint 5 — Dettaglio dei voti per l'autore

Popup o pagina separata con tab per opzione, profili locali/remoti e paginazione.
Accesso riservato all'autore del sondaggio; conservare separati dettaglio
personale e risposte aggregate delle card e della federazione.

Verifica: accesso autorizzato dell'autore, rifiuto degli accessi diretti di altri
utenti/moderatori, opzione appartenente a un altro sondaggio, identità cancellate,
risposte multiple e assenza di dati personali nelle viste condivise. Query e
EXPLAIN MySQL con almeno 200 partecipanti; browser desktop/mobile per tab,
paginazione e tastiera. In locale l'autore potrà consultare i votanti per risposta.

### Sprint 6 — Revisione complessiva e interoperabilità

Revisione del diff della sola #121 rispetto a `issue_120`, regressioni della
#120 e flussi ordinari di post/commenti/DM/community. Suite completa, verifica
MySQL e MariaDB quando disponibile, prove su Mastodon e su una seconda
Openbook di test; altre piattaforme soltanto se esercitate. Aggiornare docs
EN/IT, changelog secondo la regola del progetto e analisi con gli esiti.
Riportare nella PR il perimetro intenzionalmente escluso descritto sopra.

La suite della #120 aveva due fallimenti preesistenti documentati nella sua
revisione conclusiva: ricontrollare la baseline prima di attribuire fallimenti
alla #121. In presenza di configurazione locale in cache usare per i test
`APP_CONFIG_CACHE` verso un percorso inesistente, come descritto in
`docs/development.it.md`, senza toccare il DB applicativo.

## Stato delle decisioni di analisi

1. Completato: convivenza con media/citazioni, numero e lunghezza delle
   opzioni, durata e default sono concordati nella tabella sopra.
2. Completato: visibilità fissata alla pubblicazione, voto dell'autore,
   esclusione di DM e pubblicazione verso Group remoti, inclusione delle
   community locali e consultazione dei risultati sono concordati.
3. Completato: definitività delle scelte multiple federate e uso della data
   di ricezione concordati nelle rispettive sezioni.
4. Completato: cancellazione del votante conserva conteggi e scelte,
   scollegando l'identità; sospensioni e blocchi successivi conservano i voti
   già accettati. Verificare i percorsi di cancellazione prima dello schema definitivo.
5. Completato: raggruppamento degli Update e gestione delle chiusure tramite
   comando interno richiamato dal cron generale, senza nuovo cron da configurare.
6. Completato: dettaglio dei votanti per opzione riservato all'autore, con
   paginazione; risultati aggregati per gli altri utenti e proprie scelte per
   ciascun partecipante. Comunicazione esplicita prima del voto.

Le scelte di schema puntuali, gli indici verificati con EXPLAIN, l'estrazione
di rendering condiviso e la meccanica di raggruppamento sono decisioni di
implementazione. Vanno risolte nello sprint pertinente e annotate qui, senza
riaprire le decisioni di prodotto già concordate. Il commit congela l'analisi;
l'avvio dell'implementazione sarà concordato separatamente.

## Esito della revisione architetturale

Revisione completa del documento prima del commit di analisi: nessuna nuova
domanda di prodotto bloccante. Allineati i riferimenti alle decisioni chiuse,
separato il dettaglio dell'autore in uno sprint dedicato e chiariti i confini
fra ricezione, conteggio e distribuzione dei risultati.

Precisati i casi tecnici da verificare negli sprint: ricezione originale nei
retry, payload risolti tramite URI, deduplicazione dopo la cancellazione del
votante, tombstone del post e job pendenti, pubblico effettivo delle modifiche
e distribuzione nelle community private. Sono requisiti di correttezza del
comportamento concordato, non approvazioni pendenti.

La sessione di congelamento modificava soltanto l'analisi, senza migration o
modifiche applicative; documento completo, diff e formattazione verificati.
Gli esiti dell'implementazione e dei test sono riportati negli sprint sotto.

## Esito sprint 1 — Fondamenta e pubblicazione

Implementati `Poll`, opzioni, voti e scelte nelle quattro tabelle comuni
`polls`, `poll_options`, `poll_votes`, `poll_vote_choices`. Il parametro
`poll` contiene `options`, `multiple` e
`duration_seconds`; `LocalPollDefinition` centralizza validazione e limiti,
`LocalPollPublisher` integra creazione e revisioni nella transazione del post.
Definizione e visibilità sono immutabili; le modifiche del testo non aggiungono
nuovi destinatari. Lo staging conserva la definizione e calcola la scadenza
alla pubblicazione effettiva, anche dopo un'elaborazione video lunga.

`LocalPollSerializer` estende il documento esistente con Question,
oneOf/anyOf, endTime, conteggi e contesto votersCount. Restano testo, media,
citazioni e audience ordinari, inclusi i Group delle community private.
Create, Update, GET canonico e outbox usano lo stesso documento. La scadenza
chiude la rappresentazione anche prima del cron. Le correzioni del testo
mantengono la stessa identità e rendono durevole una nuova revisione.

La pubblicazione continua nel flusso federato esistente; `publication_pending`,
`needs_delivery`, `revision` e `distributed_revision` conservano il lavoro
per il processore dello sprint 3. In questo incremento la conferma del
salvataggio non dipende dalla consegna; il successivo processore dovrà
recuperare il lavoro e gestire duplicati con ID stabili, anche quando un
Create è già stato accodato dal percorso ordinario.

Per i votanti `actor_id` è nullable con `ON DELETE SET NULL`; cancellazione
logica dell'Actor e cancellazione dello User scollegano esplicitamente il voto.
Il contributo resta nei conteggi; sospensione/blocco lasciano i voti accettati.
Le identità di deduplicazione usano HMAC con `APP_KEY`; non si conservano URI
personali di voto in chiaro. La rotazione di `APP_KEY` richiede considerare
anche queste identità, oltre alle chiavi Actor cifrate già esistenti.

`PollLifecycle` elimina scelte/opzioni e disattiva il lavoro quando il
post diventa tombstone, conservando soltanto i metadati di tipo; la
cancellazione fisica elimina tutto tramite FK. I percorsi web di eliminazione
sono transazionali. `DeliverActivityJob` conserva l'ID del post al momento
dell'accodamento e verifica che la Question sia ancora pubblicata prima
dell'invio, anche se cambia APP_URL; i job precedenti restano compatibili.

Il composer visuale, il servizio di voto e la distribuzione periodica non
sono ancora implementati. Questo sprint è verificabile attraverso i test e
il servizio di pubblicazione; applicare la migration rinomina ed estende le
tabelle esistenti senza creare post o inviare attività. Docs EN/IT e changelog aggiornati con
questo stato incrementale, senza promettere la funzionalità completa.

Verifiche prima dell'unificazione: suite completa con 1.616 test passati, 58 saltati, 7.156
assertion e i due fallimenti
preesistenti della #120 (VisibilityFromAudienceTest e FeedTest/Altro...).
Verifica isolata MySQL: 53 test passati, 274 assertion, includendo regressioni
della #120, rollback/reapplicazione e piani delle query su 1.000 sondaggi;
ulteriori tre casi community/menzioni/consegna passati con 10 assertion.
Ultimi controlli su interoperabilità e consegna: due test MySQL passati con
20 assertion. EXPLAIN finale: un test passato con 29 assertion, comprendendo
anche le DELETE effettive e la scelta degli indici composti di lavoro/scadenza,
senza accettare una scansione dell'indice primario come verifica sufficiente.
Controlli mirati SQLite/post/editor/moderazione: 49 test passati; Pint e
`git diff --check` passati.
MariaDB non eseguito. Fixture e orologio controllato, HTTP simulato; nessuna
migration sul DB applicativo o consegna reale verso server remoti.

### Verifica locale prima della chiusura dello sprint

Applicare `php artisan migrate`: le quattro tabelle `remote_poll*` diventano
`polls`, `poll_options`, `poll_votes`, `poll_vote_choices`, conservando record
e UUID dei sondaggi remoti. Non vengono creati nuovi post. Eseguire quindi:

```bash
APP_CONFIG_CACHE=/private/tmp/openbook-poll-test-config.php php artisan test --compact tests/Feature/Polls/LocalPollFoundationsTest.php tests/Feature/Posts/PostPublicationStagerTest.php
```

I test usano SQLite in memoria e preservano la configurazione in cache del
DB locale. La verifica visuale della creazione e del voto arriverà nello
sprint 4; applicare la migration non aggiunge ancora controlli al composer.

## Correzione dello sprint 1 — Modello unico e rename dello schema

Su richiesta dell'utente è stata eliminata la separazione fisica fra sondaggi
locali e remoti. Il modello comune segue l'impostazione già usata per Post:
`Poll`, `PollOption`, `PollVote`, `PollVoteChoice`, relazione `Post::poll` e
caricamento comune `poll.options`, anche per feed, citazioni e outbox.
Restano distinti i servizi di pubblicazione locale, importazione remota e voto
remoto. Il ciclo di vita comune è gestito da `PollLifecycle`.

La migration nuova esegue prima queste rename:

| Tabella della #120 | Tabella comune |
| --- | --- |
| `remote_polls` | `polls` |
| `remote_poll_options` | `poll_options` |
| `remote_poll_votes` | `poll_votes` |
| `remote_poll_vote_choices` | `poll_vote_choices` |

Successivamente aggiunge lavoro/revisioni locali, identità di deduplicazione,
Actor nullable e collegamento opzionale fra scelta e opzione. Il testo e la
posizione della scelta restano snapshot obbligatori; la rimozione remota di
un'opzione azzera il suo collegamento senza perdere la risposta personale.
Le opzioni restano di tipo text e i conteggi nullable per rappresentare tutti
i sondaggi remoti supportati; i limiti locali e i conteggi iniziali pari a zero
sono applicati dal servizio locale. I record remoti non diventano lavoro
locale pendente. Gli indici esistenti vengono rinominati coerentemente.

Le tre migration della #120 non sono state riscritte. La migration locale
separata, ancora Pending nel DB applicativo verificato in sola lettura, è
stata sostituita prima dell'applicazione. Nessuna migration è stata eseguita
sul DB dell'utente in questa sessione. Il rollback rifiuta di eliminare dati
che lo schema precedente non può rappresentare (sondaggi locali o votanti
scollegati), prima di eseguire qualsiasi modifica.

Su SQLite la migration disabilita le foreign key prima di aprire la propria
transazione e le ripristina dopo: la ricostruzione delle tabelle parent non
deve cancellare in cascade le scelte esistenti. I test storici delle migration
SQLite vengono eseguiti senza una transazione di test esterna, per verificare
il percorso reale. MySQL mantiene le normali operazioni DDL e i propri vincoli.

Aggiornati tutti i riferimenti applicativi, Blade, query, serializer, guard,
test e documentazione. I vecchi nomi rimangono esclusivamente come riferimenti
storici nelle migration e nei test di upgrade. Nessun alias dei vecchi modelli
o doppia relazione Post/sondaggio. La UI locale rimane prevista nello sprint 4;
la UI remota funziona già con la relazione unificata.

Verifiche dell'unificazione: 58 test MySQL passati (327 assertion), inclusi
conservazione riga per riga delle quattro tabelle e invio simulato di un job
di voto persistito prima della migration. EXPLAIN dei nuovi accessi, cleanup
e query di backfill: test aggiuntivo passato con 32 assertion su un campione
di 1.000 sondaggi. Suite completa: 1.618 passati, 60 saltati, 7.183 assertion,
con i soli due fallimenti preesistenti già documentati. Controllo mirato finale
su sondaggi, importazione e staging video: 105 passati, 58 saltati,
498 assertion. Pint e diff check passati; MariaDB non eseguito.
