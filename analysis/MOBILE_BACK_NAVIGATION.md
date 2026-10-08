# Navigazione di ritorno e punto di lettura — issue #105

Analisi del 6 ottobre 2026 sul checkout `86c87f1`. Stato: implementazione sul ramo `issue_105`;
verifiche Chromium e PHPUnit completate;
prova su iPhone reale ancora da effettuare. Issue: https://github.com/insicd/openbook/issues/105
(aperta, senza commenti al momento della lettura).

## Problema e risultato atteso

La issue segnala che Openbook aggiunto alla schermata Home di iOS si apre
senza i comandi di navigazione del browser. Aprendo i commenti di un post
dalla Home, da una community o da un hashtag manca un modo evidente per
tornare alla lista. Usare il menu apre nuovamente la lista dall'inizio e può
mostrare risultati diversi.

La soluzione concordata rende disponibile il ritorno tramite cronologia.
Il browser recupera la lista, comprese le pagine aggiunte dallo scroll
infinito, e il punto di lettura quando conserva il documento. Se invece
ricarica la pagina, accettiamo il risultato senza un recupero manuale.

## Riscontri nel codice prima dell'intervento

- `public/assets/css/app.css` è il foglio principale (4.083 righe), scritto
  a mano, con variabili `--ob-*` e classi `ob-*`. Non c'è una pipeline
  Vite/webpack o un framework CSS da introdurre o modificare.
- `layouts.app`, `layouts.admin`, `layouts.install` e la pagina di attesa DB
  caricano il medesimo asset con `App\Support\Assets::url()`. Le regole nuove
  devono essere circoscritte per non coinvolgere involontariamente gli altri
  layout. `partials.custom-css` carica dopo il foglio principale le
  personalizzazioni dell'istanza; queste vanno considerate nelle prove.
- `.ob-header` è già `sticky`, con `top: 0` e `z-index: 30`.
  `.ob-header__start` contiene menu mobile e marchio; l'area centrale ospita
  il comando nuovo post su desktop. `.ob-icon-btn` offre stile, hover e focus
  coerenti, ma il suo bersaglio attuale è 40 × 40 px.
- Sotto 768 px il marchio ha `max-width: 46vw`, ellissi e font ridotto; menu,
  notifiche e altre opzioni restano presenti. La sidebar sinistra diventa un
  pannello sovrapposto, la destra è già nascosta sotto 1024 px e il comando
  nuovo post diventa un FAB in basso a destra. Aggiungere una freccia richiede
  verificare lo spazio disponibile, soprattutto con nomi istanza lunghi.
- Nel foglio non risultano regole `display-mode: standalone` o `safe-area`.
  Il viewport attuale non usa `viewport-fit=cover`: non modificarlo solo per
  questa issue. Un controllo nel normale flusso dell'header evita di
  introdurre un altro pulsante fisso vicino al FAB.
- `posts/show.blade.php` non espone un comando di ritorno. Le card collegano
  i commenti a `posts.show#commenta` o `#commenti`: un comando solo all'inizio
  del contenuto potrebbe trovarsi fuori dalla viewport dopo il salto.
- Esistono già link di destinazione in commenti, reazioni, modifica post,
  follower/seguiti, modifica community, Discover ed eventi. Sono utili come
  navigazione gerarchica, ma non recuperano necessariamente la provenienza.
- `posts/_feed.blade.php` espone `[data-infinite-scroll]`, `data-next-url` e
  ID `post-{id}`. Home e Mondo caricano anche il primo blocco via AJAX;
  community e hashtag nel checkout analizzato renderizzano il primo blocco
  nel documento. Fa fede questo codice anche dove i commenti del loader
  descrivono diversamente la community.
- `infinite-scroll.js` mantiene `nextUrl`, `initialLoad` e `loading` nella
  closure. Dopo un caricamento non aggiorna `data-next-url` del contenitore:
  leggere solo quell'attributo non restituisce lo stato corrente. Non
  registra le pagine caricate né implementa il recupero dello scroll.
- `composer.js` gestisce già `pageshow` con `event.persisted` per il ripristino
  da back-forward cache. Le conversazioni hanno una propria gestione della
  cronologia in `messages-live.js` e restano fuori da questo intervento.
- `partials/site-icons` emette manifest e meta di installazione quando è
  configurata una favicon personalizzata. `SiteManifestController` dichiara
  `display: standalone`. Il problema è quindi coerente con una modalità
  prevista dal progetto; va provata nell'installazione effettiva segnalata.

L'assenza del controllo e del recupero esplicito è verificata nel codice.
Il comportamento della cache di Safari e i difetti visivi sul dispositivo
segnalato restano da riprodurre: questa analisi non è una prova su iPhone.

## Scelte implementate

### Controllo di ritorno

Markup limitato alla route `posts.show` nel layout condiviso e reso in una
riga dedicata sotto `.ob-header__inner`, dentro
l'header sticky. Posizione concordata dopo l'esame dello screenshot mobile:
non aggiungere icone alla riga già occupata da menu, marchio e notifiche.
Copertura concordata: solo `/posts/{post}` (`posts.show`), incluse le sue
sezioni commenti raggiunte tramite anchor. Nessuna estensione ad altre pagine
in questo intervento.

Usare un pulsante JavaScript che torna tramite la cronologia. Se manca una
provenienza interna, non mostrare la riga: marchio e menu restano disponibili.
Nessuna destinazione di riserva automatica. Aggiungere un'icona SVG
`arrow-left` nel componente esistente: oggi non è presente. Etichetta
accessibile «Indietro» e traduzioni italiane/inglesi.

Mostrare la riga sulle pagine abilitate a tutte le larghezze, sia su desktop
sia su mobile, nel browser ordinario e nella modalità installata. Dopo la
preview desktop l'utente ha approvato la rimozione del vincolo iniziale a
767 px. La visibilità dipende soltanto dal contesto di ritorno disponibile,
senza rilevazione specifica di iOS o breakpoint del layout.
Lasciare i link gerarchici
esistenti: «Torna al post» e «Indietro» possono avere destinazioni diverse.

Impostazione visiva concordata: riga con sfondo `--ob-color-bg`, leggermente
più scuro della testata bianca, bordo sottile `--ob-color-border` e link
«← Indietro» a circa 0.875rem con colore `--ob-color-text`. Testo compatto
ma area cliccabile alta almeno 44 px; variabili esistenti e focus visibile.
Questa riga deve apparire come navigazione secondaria, senza imitare un
avviso o un titolo di sezione. Non cambiare globalmente pulsanti o z-index.
Verificare la presenza della riga anche alle larghezze desktop.

### Flusso concordato: contesto limitato alla UI

Priorità esplicita: KISS. Non introdurre un gestore generale della cronologia,
nuovi endpoint AJAX per commenti o ricostruzione delle liste. L'invio e la
validazione dei dati restano nei form/controller attuali.

Riscontro: commenti e risposte usano il submit normale, anche con immagini.
`CommentController::store()` redirige a `posts.show#commento-ID`; il percorso
AJAX in `composer.js` è abilitato solo per la creazione dei post. Il documento
viene quindi ricaricato. Una fixture HTTP in Chromium conferma un passo
per ogni POST seguito da
redirect, anche verso lo stesso URL/anchor; il refresh non aggiunge passi.
La verifica su iOS reale resta da effettuare.

Usare un piccolo contesto in `sessionStorage`, circoscritto al dettaglio
corrente, con profondità positiva (`depth`) e identificatore del dettaglio.
Non usare `localStorage`. Un eventuale URL memorizzato serve solo come
riferimento: il ritorno esegue `history.go(-depth)`, non una nuova navigazione
all'URL. Non conservare contenuti, form o token.

Flusso desiderato:

1. Apertura di un post da una lista interna (Home, community, hashtag,
   profilo): creare un contesto nuovo con `depth = 1`, sovrascrivendo quello
   precedente anche se il post è lo stesso. Non ereditare valori residui.
2. Invio di un commento/risposta: agganciarsi al submit del form, non al click
   del pulsante. Segnare un invio in corso; al ritorno riconoscere il passaggio
   e incrementare la profondità per la nuova entrata del redirect. Non contare
   le semplici visualizzazioni della pagina.
3. Refresh del dettaglio: mantenere il contesto senza incrementarlo.
4. Indietro: leggere la profondità in una variabile, eliminare il contesto e
   chiamare `history.go(-depthSalvata)`.
5. Navigazione esplicita tramite marchio/menu verso Home o altre liste:
   eliminare il contesto. Una nuova apertura dalla lista lo reinizializza
   comunque, anche riaprendo lo stesso post.
6. Accesso diretto o provenienza esterna: nessun contesto nuovo e nessun
   pulsante. Come criterio iniziale usare referrer della stessa origine;
   il referrer non certifica da solo l'entrata precedente della cronologia.

La coppia URL/ID–depth non elimina da sola i valori obsoleti: l'inizializzazione
all'apertura dalla lista e la pulizia quando si cambia percorso sono necessarie.
Non introdurre un contatore globale della navigazione. Usare soltanto
`history.state.obPostBack` sulla singola entrata del post, preservando altre
proprietà, per distinguere refresh, back/forward e nuovi passaggi tramite
anchor. È necessario perché il solo valore in sessionStorage non distingue
le vecchie entrate del post dopo un ritorno nativo. I link ad anchor diversi
sulla stessa pagina possono aggiungere un passo e vanno conteggiati.
Lo script è caricato nel layout condiviso per pulire il contesto quando
si torna alle liste, anche tramite back-forward cache; non aggiunge controlli
né traccia navigazioni nelle altre pagine.

### Errori e limite del recupero

Gestire soltanto i casi concreti del flusso: validazione browser/server,
richiesta fallita, doppio submit, refresh e storage indisponibile. Una
richiesta interrotta o annullata non incrementa prima del ritorno. Un refresh
della pagina originaria consuma il flag senza incrementare.
Il submit non incrementa subito: segna un passaggio in corso. Sul nuovo
documento dopo un redirect interno allo stesso post si incrementa di uno.
Anche un errore di validazione con redirect aggiunge un'entrata: conta quel
passaggio, non il successo della pubblicazione. Refresh e ritorni nativi
leggono la profondità dell'entrata corrente senza incrementarla.

Affidare il recupero delle card e della posizione al browser. La back-forward
cache può conservare documento, stato JS e scroll, ma non è garantita: un
ritorno può ricaricare la lista. In questa fase non modificare
`infinite-scroll.js` per salvare blocchi, cursori, HTML o coordinate e non
implementare replay o snapshot. Decisione concordata: un ricaricamento quando
la cache non è disponibile è un comportamento accettato; il recupero della
posizione non è una condizione di completamento di questo intervento.

### Ambito iniziale

Implementare e provare lista → `/posts/{post}` → invio commenti/risposte →
ritorno. I pulsanti commenti delle card aprono `#commenta` o `#commenti` sulla
pagina del post; dopo un nuovo commento il controller redirige alla stessa
pagina con `#commento-ID`.

Esiste anche `/comments/{comment}`, una pagina distinta con thread centrato
sul commento, raggiunta tramite il permalink/timestamp e il collegamento al
commento padre. Questo percorso esiste ma resta esplicitamente fuori ambito,
come reazioni, eventi e profili. Non modificare quei link per ricondurli al
post e non applicare loro il contatore del dettaglio del post.

## Stadi di intervento

1. **Prova della cronologia.** Riprodurre lista → post → uno/due commenti,
   refresh ed errore di validazione. Verificare numero di entrate aggiunte e
   ritorno alla lista. Prova Chromium completata; conferma Safari/iOS reale
   ancora da effettuare.
2. **UI e script circoscritto.** Riga nell'header, icona, traduzioni e piccolo
   script versionato tramite `Assets::url()`. Contesto di ritorno e aggancio
   ai submit attuali, senza cambiare la digestione dei dati.
3. **Verifica e documentazione.** Test focalizzati e suite completa prima
   della PR. Aggiornare le pagine architettura inglese/italiana e il changelog
   nella sessione di implementazione secondo `.cursor/rules/changelog.mdc`.

## Verifiche richieste

- Browser: profondità dopo uno/due commenti, refresh, errori, doppio invio;
  ritorno con e senza back-forward cache su una lista con più blocchi.
- Pulizia: ritorno tramite pulsante, marchio/menu, riapertura dello stesso
  post, accesso diretto/esterno, nuova scheda, logout e storage indisponibile.
- UI: larghezze 320/375/390/767/768 px, landscape, nome istanza lungo e CSS
  personalizzato; pulsante accessibile e riga presente anche su desktop.
- PHPUnit: markup delle pagine abilitate/escluse e regole di accesso invariate.
  I test server non dimostrano il recupero dello scroll o la cronologia.

## Fonti e questioni da chiudere nelle prove

- Issue: https://github.com/insicd/openbook/issues/105
- Cronologia: https://developer.mozilla.org/en-US/docs/Web/API/History/back
- Ciclo di ritorno: https://developer.mozilla.org/en-US/docs/Web/API/Window/pageshow_event
- Cache del documento: https://web.dev/articles/bfcache

Verificato in Chromium con fixture HTTP che usa JS e CSS di produzione:
due commenti, redirect di validazione, refresh, back nativo, troncamento della
cronologia avanti, anchor, pulizia e riapertura, breakpoint 767/768, accessi
diretti/esterni, nuova scheda e storage negato. Test ripetibile:
`node tests/Browser/post-back.cjs` con Playwright e browser disponibili;
`BROWSER_TYPE` e `BROWSER_EXECUTABLE_PATH` permettono di scegliere il browser.
Non è una prova end-to-end dell'istanza o su iPhone. La prova con il WebKit
locale non è stata completata (runtime/browser installati non allineati).
Da verificare sul dispositivo: versione iOS e ritorno dopo i redirect. Il piano
non promette una fotografia immutabile del feed o il recupero dello scroll
quando il browser scarta la pagina. Nessuna dipendenza nuova, migrazione,
worker o modifica alla federazione è proposta.

## Esito delle verifiche di implementazione

- PHPUnit mirato (PostControllerTest e CommentTest): 36 test passati,
  174 asserzioni.
- Suite completa: 1.129 test, 4.755 asserzioni, nessun fallimento e due test
  MySQL saltati perché non è disponibile il server richiesto.
- La configurazione locale in cache usa MySQL: per i test è stato selezionato
  un percorso di cache temporaneo inesistente, senza modificare la cache
  dell'istanza. Il limite locale degli estratti è 400 caratteri; il test del
  feed assume il default 150. Il comando finale usa il default soltanto per
  il processo di test, oltre a un limite memoria PHP di 512 MB:

  ```sh
  APP_CONFIG_CACHE=/private/tmp/openbook-issue105-uncached-config.php OPENBOOK_FEED_BODY_EXCERPT=150 php -d memory_limit=512M vendor/bin/phpunit --colors=never
  ```

- Fixture browser Chromium: tutti i casi descritti passano, compreso ritorno
  nativo attraverso un anchor e sostituzione delle entrate avanti dopo un
  nuovo submit. Rendering della riga verificato sulla fixture mobile con
  CSS di produzione; non è uno screenshot dell'istanza reale.
- Pint sul test PHP modificato, controllo sintassi JS e `git diff --check`
  passati. Review finale del diff completata. Commit finale richiesto dopo la review complessiva; nessuna PR creata.

## Estensione concordata: intestazione della card cliccabile

Dopo la prova utente del pulsante Indietro, il perimetro include anche lo
sfondo dell'intestazione della card condivisa `posts/_card.blade.php`.
Si tratta di un link HTML nativo sovrapposto soltanto all'intestazione:
apre `/posts/{post}` senza anchor. I link dell'autore e dell'orario, insieme
al menu, restano sopra il link e mantengono il proprio comportamento.
Nessun listener JS o endpoint nuovo è necessario. Il link ha un'etichetta
accessibile e funziona anche da tastiera e senza JavaScript.

L'attivazione riusa `linkToPost` e richiede una card non incorporata: il
collegamento di sfondo non è presente nel dettaglio già aperto né nelle
citazioni. La riga «ha condiviso questo post» resta fuori dall'area cliccabile.
La modifica non introduce nuove rappresentazioni o regole di visibilità.

Verifiche aggiunte: test PHPUnit sulla card nelle liste, link senza fragment,
etichetta e assenza di link/controlli annidati, esclusione del dettaglio e
delle citazioni; test browser sui click dello sfondo, autore, data e menu,
attivazione da tastiera e integrazione con il contesto di ritorno. I test
mirati (PostCardActionsTest e PostControllerTest) passano: 24 test e 126
asserzioni. La fixture browser Chromium passa anche con questi casi.

Suite completa dopo l'estensione: 1.130 test, 4.765 asserzioni, nessun
fallimento e gli stessi due test MySQL saltati. Pint sul test PHP modificato,
sintassi della fixture JS e verifica del diff passati.

## Review finale prima del commit

Review dell'intero diff e dei file nuovi completata: nessun problema da
correggere rilevato su navigazione, pulizia dello stato, sovrapposizione dei
link della card, accessibilità o confini di visibilità. La suite completa
più recente resta quella successiva all'estensione (1.130 test, nessun
fallimento, due test MySQL saltati); nessuna modifica funzionale successiva.
L'utente ha riferito una prova positiva del pulsante Indietro. Dispositivo
e versione browser non sono stati specificati, quindi questa segnalazione
non sostituisce una verifica automatizzata Safari/iOS.

## Visibilità desktop approvata dopo la preview

Il controllo ora compare a tutte le larghezze quando esiste il contesto di
ritorno. Aggiornate documentazione, changelog e aspettative della fixture
browser (768 e 1280 px). Dopo l'approvazione della preview sono stati eseguiti
i test mirati `PostControllerTest` e `PostCardActionsTest`: 24 test e 126
asserzioni, tutti superati. Anche la fixture Chromium è passata, verificando
la visibilità a 390, 767, 768 e 1280 px e i flussi di navigazione esistenti.
La suite completa non è stata ripetuta per questa piccola modifica CSS,
come richiesto dall'utente; il risultato completo precedente resta riportato
sopra.
