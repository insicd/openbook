# Primi passi dei nuovi utenti — issue #103

Stato al 16 ottobre 2026: sprint 1 completato, verificato localmente con
l'utente e approvato per il commit dopo la review tecnica. Analisi iniziale
congelata in `b2291fc`; gli sprint successivi restano in analisi.

Issue: https://github.com/insicd/openbook/issues/103
Discussione: https://github.com/insicd/openbook/issues/103#issuecomment-5877611768
Ramo: `issue_103`, creato da `main` al commit `8caa6d4`, allineato a
`origin/main` dopo fetch. Il remote `origin` punta a `skeyby/openbook`;
l'issue appartiene a `insicd/openbook`, remote `upstream`.

## Obiettivo

Rendere più agevoli la registrazione e i primi passi: scegliere la lingua,
capire come popolare il feed e trovare le informazioni dell'istanza nella
propria lingua. Procedere per incrementi piccoli e verificabili, iniziando
dagli interventi più semplici, senza subordinare questi ultimi alla definizione
di un intero percorso guidato.

## Macrovoci della issue

| Voce | Comportamento richiesto | Complessità iniziale | Stato |
| --- | --- | --- | --- |
| Lingua nella homepage pubblica | Conservare il rilevamento dal browser e aggiungere una scelta esplicita, per esempio con il globo in alto a destra. | Contenuta, con decisioni sulla persistenza | Da analizzare nel dettaglio |
| Username nella registrazione | Convertire `PettiRosso` in `pettirosso`, mantenendo gli identificatori locali minuscoli. | Piccola | Completato nello sprint 1 |
| Testo del feed vuoto | Citare persone da seguire, argomenti tramite hashtag e pubblicazione del primo post. | Piccola | Completato nello sprint 1 |
| Collegamenti del feed vuoto | Mostrare community e «Argomenti in tendenza» prima delle persone suggerite, con «Scopri altre persone» in fondo. | Piccola | Completato nello sprint 1 |
| Privacy e Regole multilingua | Mostrare il testo nella lingua dell'utente, con ripiego sulla lingua principale se manca la traduzione. | Strutturale | Decisioni di contenuto e amministrazione aperte |
| Pagina Welcome | Aggiungere una pagina generale di benvenuto, amministrabile e multilingua come Privacy e Regole. | Media, dipendente dalla voce precedente | Collocazione e comportamento da decidere |

Il commento del manutentore aggiunge due proposte da discutere:

- Sezioni linguistiche nello stesso testo, delimitate da tag come `[it]` e
  `[en]`, come possibile formato per le pagine amministrabili.
- Percorso a step suggeriti dall'istanza, con completamento delle attività
  (esempi: impostare la lingua, seguire cinque persone, scrivere il primo post).

Queste proposte sono parte dell'analisi, ma non sono ancora decisioni sul
formato di salvataggio o sui requisiti del percorso guidato. La soglia di cinque
persone è un esempio del commento, non un requisito già approvato.

## Ricognizione iniziale del codice (prima dello sprint 1)

| Area | Punti esistenti | Conseguenza |
| --- | --- | --- |
| Lingua della richiesta | `app/Http/Middleware/SetUserLocale.php`, `config/openbook.php`, `tests/Feature/GuestLocaleTest.php` | Gli autenticati usano la preferenza salvata. Gli ospiti usano `Accept-Language`: italiano se preferito, inglese negli altri casi; senza header resta il default dell'istanza. Oggi manca una scelta esplicita per l'ospite. |
| Homepage pubblica | `HomeController`, `resources/views/home.blade.php`, `home/_intro.blade.php`, layout condiviso | Supporta sia la presentazione semplice sia il feed pubblico opzionale. Il selettore deve restare accessibile in entrambe le modalità e su schermi piccoli. |
| Registrazione | `RegisterRequest`, `RegisterController`, `AccountRegistrar`, `tests/Feature/Auth/RegistrationTest.php` | La regex accetta già solo minuscole; il controller converte dopo la validazione, troppo tardi per accettare le maiuscole. La normalizzazione deve precedere anche i controlli di unicità su utenti e Actor locali. |
| Lingua del nuovo account | `AccountRegistrar` | La preferenza iniziale è `config('app.locale')`, non necessariamente la lingua della richiesta. Va deciso se trasferire al nuovo account la scelta fatta prima della registrazione. |
| Feed vuoto | `resources/views/feed/_welcome.blade.php`, `lang/it/openbook.php`, `lang/en/openbook.php`, `tests/Feature/FeedWelcomeTest.php` | Esiste un welcome kit con suggerimenti e azioni per persone/community. Il testo cita già persone e primo post, ma non gli hashtag; manca il collegamento alle tendenze. |
| Tendenze | Route `hashtags.index`, `/tendenze` | Riutilizzare la destinazione e i permessi esistenti, senza introdurre una pagina o un flusso di scoperta parallelo. |
| Contenuti amministrabili | `InstanceSettings`, `SystemSetting`, controller e view di impostazioni amministrative | Privacy e Regole sono due stringhe Markdown singole, ciascuna con limite di 20.000 caratteri nel form. Non esiste una pagina Welcome equivalente. |
| Pagine pubbliche | `PrivacyPolicyController`, `InstanceRulesController`, view `instance.*`, `tests/Feature/Admin/AdminSettingsTest.php` | Le route `/privacy` e `/regole` esistono già; il rendering rimuove HTML grezzo e link pericolosi. L'amministrazione deve poter modificare il contenuto integrale, distinto dalla versione selezionata per la lettura pubblica. |

La ricognizione non ha modificato dati applicativi o configurazioni. Prima di
ogni incremento verranno letti nel dettaglio i relativi flussi e test: questa
tabella individua i punti di ingresso, non sostituisce la verifica dello sprint.

## Vincoli e indirizzi

- Riutilizzare servizi, layout, rotte, stile e traduzioni esistenti; mantenere
  controller sottili e compatibilità con shared hosting.
- Nessun nuovo requisito di servizi esterni, worker permanenti o polling.
- Scelta della lingua utilizzabile con tastiera e su mobile, con etichetta
  comprensibile anche se rappresentata da un'icona. Evitare bandiere per
  rappresentare le lingue.
- Non modificare username esistenti, URI ActivityPub o nomi visualizzati;
  normalizzare l'input della registrazione senza ampliare implicitamente i
  caratteri ammessi. Verificare anche il comportamento del form nel browser.
- Mantenere testi e interfaccia in italiano e inglese allineati. Usare testi
  chiari, positivi e azioni coerenti con il resto dell'interfaccia.
- Preservare i contenuti Privacy/Regole già salvati, le route pubbliche e la
  protezione del rendering Markdown. L'assenza di una traduzione deve avere
  un comportamento deterministico e comprensibile per l'amministratore.
- Il welcome kit nel feed e la futura pagina Welcome sono elementi distinti:
  il primo propone azioni e persone, la seconda contiene testo dell'istanza.
  Evitare messaggi ripetuti o due sistemi che si contendono il primo accesso.
- Un eventuale percorso guidato deve riutilizzare i normali flussi di follow,
  impostazioni e pubblicazione. Non introdurre nuove regole di visibilità o
  alterare il feed per completare una checklist.

## Ordine di lavoro proposto e verifiche

Ogni sprint termina con review completa del relativo diff, test pertinenti,
documentazione/changelog quando dovuti e indicazioni per una prova locale.
Il commit chiude lo sprint secondo il metodo concordato; l'analisi viene
aggiornata con decisioni ed esiti. Suite completa prima della PR.

### Sprint 1 — Username e primi passi nel feed

Stato: implementazione approvata dopo le prove locali; review tecnica finale
completata. Nessuna decisione aperta per questo sprint.

#### Username nella registrazione

- Gli username vengono sempre salvati in minuscolo (case enforced):
  `PettiRosso` diventa `pettirosso`, senza conservare la grafia originale.
- Convertire prima della validazione e dei controlli di unicità su utenti
  e Actor locali: `PettiRosso` collide con un `pettirosso` già presente.
- Nel browser rimuovere il vincolo che respinge le maiuscole, mantenendo
  gli altri controlli. Nessun errore o conferma aggiuntiva per la conversione;
  una breve indicazione nel form chiarisce il salvataggio in minuscolo.
- Conservare gli altri limiti sui caratteri ammessi. Nessuna modifica ad
  account esistenti, identificatori federati o nomi visualizzati dei profili.

#### Benvenuto nel feed e collegamenti

Testo italiano approvato:

> Il tuo feed prende forma con ciò che ti interessa: segui persone e argomenti
> tramite hashtag, oppure pubblica il tuo primo post.

Aggiungere il pulsante «Argomenti in tendenza» verso la pagina esistente
`/tendenze`, accanto all'azione per esplorare community. Dopo la prova locale,
concordato di mostrare questi due pulsanti dopo lo staff e prima delle persone
suggerite, per renderli raggiungibili senza scorrere l'intera lista.
«Scopri altre persone» resta in fondo, come prosecuzione dei suggerimenti.
Aggiornare anche la versione inglese con lo stesso significato. L'intervento
riguarda testo e collegamenti, riutilizzando le funzioni esistenti.

Ampliamento approvato durante la verifica locale: il welcome kit resta visibile
finché la somma di persone seguite e hashtag seguiti è inferiore a 10, anche
quando il feed contiene già post. Compare sopra i post soltanto nella prima
pagina, senza ripetersi durante lo scorrimento; a 10 scompare. Contare follow
accettati verso Person locali/remoti e preferenze hashtag del solo utente;
community e richieste in attesa non contribuiscono alla soglia. Gli account
già seguiti restano esclusi dai suggerimenti. La condizione segue il conteggio
attuale: scendendo nuovamente sotto 10 il riquadro torna visibile.

Ulteriore raffinamento concordato: la sezione «Dal fediverso» è visibile
soltanto a zero persone/hashtag seguiti. Da 1 a 9 restano staff, azioni
community/tendenze e persone locali; «Scopri altre persone» resta disponibile
anche quando i suggerimenti remoti sono nascosti, se ci sono account da
proporre. In questo modo i primi post diventano più facilmente raggiungibili.

#### Implementazione e verifiche

- Normalizzare lo username prima della validazione; rivedere il punto di
  conversione già esistente per evitare duplicazioni inutili.
- Aggiornare il messaggio del welcome kit in entrambe le lingue.
- Aggiungere l'azione verso le tendenze accanto alle community, prima della
  lista di persone e dopo lo staff; mantenere «Scopri altre persone» in fondo.
- Test mirati: username misto salvato minuscolo in utente/Actor; collisione
  con username già presente anche nell'Actor locale; input non ammessi ancora
  respinti; welcome kit con testo/link corretti, prima dei post sotto soglia,
  nascosto a 10 follow complessivi e nelle pagine successive del feed.
- Prova locale: nuovo account con username misto, poi verifica del feed vuoto
  e dei tre collegamenti. Nessuna migration prevista per questi interventi.

#### Esito e review finale

- Username normalizzato con `strtolower` prima della validazione e dei
  controlli di unicità; rimossa la conversione successiva nel controller.
  Form e anteprima dell'indirizzo coerenti con il salvataggio in minuscolo.
- Review: protetto il rendering del form dopo input username malformato,
  verificando anche la pagina successiva al redirect di validazione.
- Welcome kit gestito da `FeedWelcomeQuery`, con controller sottile e
  conteggi limitati alla soglia. Riutilizzate le query di suggerimento e
  lo scorrimento esistenti; nessun nuovo flusso di follow o esposizione delle
  preferenze hashtag altrui.
- Recepiti i riscontri locali dell'utente: anteprima minuscola, azioni
  community/tendenze sopra le persone, visibilità fino a 9 seguiti e lista
  remota solo a zero. Il 16 ottobre l'utente ha autorizzato la chiusura e il
  commit dello sprint dopo la review.
- Changelog e guide utente italiana/inglese allineati al comportamento finale.
  Il problema preesistente dei messaggi in lingue miste resta nello sprint 2.
- Verifica finale mirata: 55 test e 200 assertion superati (Auth,
  FeedWelcomeTest, GuestLocaleTest, HomeGuestFeedTest). Casi 0/1/9/10 con
  persone locali/remote e hashtag; follow pendenti/Group esclusi, suggerimenti
  già seguiti esclusi, post conservati, kit assente nelle pagine successive.
  Pint sui sei file PHP coinvolti e controllo whitespace superati.
- Suite completa: 1.728 test superati, 109 saltati, 2 fallimenti preesistenti
  (7.798 assertion). Riprodotti entrambi sulla base `b2291fc` in una copia
  temporanea senza modifiche applicative: `VisibilityFromAudienceTest` manca
  della tabella `actors`; il test del testo lungo in `FeedTest` presuppone
  un estratto di 150 caratteri, mentre l'ambiente locale configura
  `OPENBOOK_FEED_BODY_EXCERPT=400`. Il secondo passa con il valore predefinito.
  Nessuna correzione estranea allo sprint applicata a questi due casi.
- Verifica MySQL in schema temporaneo popolato con 1.200 Actor e hashtag:
  soglia mista 9/10 corretta. EXPLAIN delle query effettive usa l'indice
  `follows_follower_id_following_id_unique` per i follow (`ref`), la PK Actor
  per l'identità seguita (`eq_ref`) e la PK di `hashtag_follows` (`ref`,
  covering). Nessuna scansione completa o nuovo indice necessario. Schema
  temporaneo eliminato e server di test spento al termine.
- Nessuna migration, build o modifica ai dati dell'istanza locale.

### Sprint 2 — Lingua prima della registrazione

#### Decisioni concordate

- Decisione approvata: ricordare la scelta esplicita anche nelle visite
  successive sullo stesso browser, tramite cookie persistente. La durata
  concreta sarà definita nell'implementazione.
- Il globo/selettore deve essere disponibile nel login e nella homepage
  guest introdotta dopo l'apertura dell'issue, comprese entrambe le sue
  modalità (presentazione semplice e feed pubblico).
- La scelta esplicita dell'ospite prevale sul rilevamento del browser;
  dopo l'accesso continua a prevalere la preferenza salvata dell'account.
- Ricognizione: esiste già `config('openbook.locales')`, oggi Italiano e
  English, usata dalle impostazioni account e dalla validazione della lingua.
  Decisione approvata: riutilizzare questa lista anche nel selettore ospiti, senza
  dedurre automaticamente le lingue dalla sola presenza di cartelle Laravel.

#### Decisioni ancora aperte

- Oltre a login e homepage guest, mostrare il selettore anche su
  registrazione e pagine informative?
- Il nuovo account eredita la lingua scelta prima dell'iscrizione? Come
  trattare la lingua dedotta automaticamente dal browser?

Conservare il rilevamento attuale quando manca una scelta esplicita; accettare
soltanto lingue supportate e mantenere eventuali redirect all'interno del sito.

#### Implementazione e verifiche

- Chiudere le decisioni sulla collocazione aggiuntiva e sulla lingua del nuovo account.
- Aggiungere il selettore riusando il middleware e i controlli disponibili.
- Correggere la coerenza linguistica di registrazione, riepilogo errori e
  messaggi di validazione. Nella prova locale del 16 ottobre l'utente ha
  osservato il form inglese con «Controlla i dati inseriti:» in italiano:
  il titolo è scritto direttamente nel layout condiviso `layouts/app`.
  Anche `RegisterRequest` contiene messaggi e nomi dei campi in italiano
  fisso; le traduzioni delle regole password esistono già nelle due lingue.
  Verificare GET, POST invalido e pagina dopo il redirect, con errori nella
  stessa lingua dell'interfaccia; includere username occupato e password debole.
- Test mirati: scelta esplicita contro browser, fallback preesistente, lingua
  non supportata, passaggi fra homepage/signup/login, prevalenza della preferenza
  dell'account; sicurezza dei redirect se previsti.
- Prova locale: browser in inglese, scelta italiano, navigazione e registrazione;
  ripetere con homepage semplice e feed pubblico, mobile e tastiera.

### Sprint 3 — Privacy e Regole multilingua

#### Decisioni ancora aperte

1. Usare sezioni `[it]`/`[en]` nello stesso Markdown, come suggerito nel
   commento, oppure testi separati per lingua nel form amministrativo?
   Confrontare semplicità di editing, ambiguità con normali contenuti Markdown,
   validazione e compatibilità con i testi già presenti.
2. Cosa identifica la lingua principale: il default applicativo esistente
   oppure una preferenza amministrativa dedicata? Cosa accade quando anche
   il testo principale è vuoto, o una traduzione è presente ma vuota?
3. Quali lingue ammettere nei contenuti: solo quelle dell'interfaccia o anche
   altre? Come applicare il limite di lunghezza alle diverse versioni?

Indirizzo proposto: un unico meccanismo di selezione della lingua e rendering
per tutte e tre le pagine; preservare i testi monolingua già presenti senza
richiedere una riscrittura manuale. La rappresentazione di salvataggio resta
da decidere prima di progettare eventuali migration.

#### Implementazione e verifiche

- Chiudere formato, lingua principale, fallback e limiti dei contenuti.
- Introdurre gestione amministrativa e selezione pubblica condivise;
  preservare contenuti salvati e sanitizzazione Markdown.
- Test mirati: lettura e aggiornamento admin, traduzione disponibile/mancante,
  testo monolingua preesistente, contenuti vuoti, Markdown e contenuti pericolosi.
  Se necessario modificare lo schema, verificare migration e rollback su SQLite
  e MySQL/MariaDB, preservando i dati.
- Prova locale: inserire due versioni delle pagine e cambiare lingua; rimuovere
  una traduzione e verificare il ripiego, senza perdere il testo nell'editor.
- Documentare per l'amministratore come scrivere e aggiornare i contenuti,
  sincronizzando le guide italiane e inglesi.

### Sprint 4 — Pagina Welcome

#### Decisioni ancora aperte

Dove collegare Welcome e quando mostrarla: pagina consultabile, primo accesso
o entrambe? Deve esistere un testo predefinito quando l'amministratore non la
configura? Formato e fallback riutilizzano le decisioni dello sprint 3.

#### Implementazione e verifiche

- Chiudere collocazione, testo assente e rapporto con il primo accesso.
- Aggiungere la pagina usando il supporto del precedente sprint.
- Test mirati: amministrazione, accesso pubblico, lingua e fallback,
  collegamenti e comportamento concordato per il primo accesso.
- Prova locale: personalizzare il benvenuto, consultarlo nelle due lingue e
  verificare che non si sovrapponga al welcome kit del feed.

### Eventuale sprint 5 — Percorso a step

#### Aspetti da discutere

Da discutere dopo le parti precedenti: inclusione nella #103 o attività
successiva, posizione nell'interfaccia, attività suggerite e personalizzazione
da parte dell'istanza, possibilità di saltare/nascondere il percorso,
completamento derivato dai dati o registrato, comportamento per account già
esistenti e azioni annullate. Distinguere un'introduzione facoltativa da
eventuali obblighi: la issue non prescrive di bloccare l'uso del sito.

Non ancora pianificato per l'implementazione: prima concordare il perimetro.
Le verifiche dipenderanno dalle decisioni, includendo account nuovi/esistenti,
completamento delle attività, persistenza e possibilità di saltare il percorso.

## Perimetro e sequenza di chiusura

Lo sprint 1 comprende username e primi passi nel feed: nessuna migration,
modifica alla federazione, refactor estraneo o nuovo sistema di raccomandazione.
Le decisioni aperte degli sprint successivi non ne bloccano l'implementazione.

Sequenza concordata: commit dell'analisi congelata; implementazione e test
mirati dello sprint 1; verifica locale e parere dell'utente; review tecnica
finale e commit del codice dopo il riscontro. Non avviare lo sprint successivo
prima della chiusura del primo.
