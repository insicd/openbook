# Visibilità del sito per visitatori non autenticati

Decisioni concordate il 4 novembre 2026. Ramo `ui_visibility`.

## Obiettivo e configurazione

Nuovo tab Visibilità pubblica in Questa istanza, dopo Contenuti. Una modalità
esclusiva: Tutto visibile, Solo contenuti locali, Tutto chiuso. Nel primo caso
un checkbox abilita l'esplorazione pubblica per hashtag (pagine tag e Tendenze).
Il controllo esistente del feed ospiti viene spostato qui da Aspetto.

In assenza delle nuove chiavi, il comportamento rimane quello attuale;
nessuna scrittura automatica al boot. Al salvataggio vengono persistite in
system_settings modalità, opzione hashtag e feed ospiti. Non serve migrazione.
Le opzioni non trasformano in pubbliche pagine già riservate agli autenticati.

## Regole

- discoverable e indexable conservano significato e applicazione attuali:
  suggerimenti/ricerche e consenso all'indicizzazione, senza nuovi divieti di
  accesso diretto o modifiche dei valori predefiniti.
- Le regole esistenti di visibilità, membership, sospensione e moderazione
  continuano a valere. Il nuovo controllo può solo restringere l'accesso web
  anonimo, mai allargare i permessi del contenuto.
- Locale significa pubblicato da un autore/organizzatore locale, non un post
  remoto presente nel database o ricondiviso da un utente dell'istanza.
- I commenti e i commenti agli eventi seguono la radice della discussione:
  un commento locale sotto un post remoto non rende pubblica la discussione
  in modalità Solo contenuti locali. Nessun filtro separato sui singoli autori
  delle risposte di una discussione ammessa.
- Profili locali e community locali possono essere consultati in Solo locali;
  le loro liste devono filtrare i contenuti remoti, comprese ricondivisioni,
  eventi e citazioni incorporate. Gli allegati non cambiano accessibilità.
- Con Solo locali e Tutto chiuso, le pagine di tag e Tendenze richiedono accesso.
- La home conserva la presentazione dell'istanza e rispetta le opzioni esistenti;
  l'eventuale feed mostra i contenuti ammessi, oppure scompare con Tutto chiuso.
  Accesso, registrazione se abilitata, regole e privacy restano raggiungibili.
- La federazione e le rappresentazioni ActivityPub restano invariate. Le
  eccezioni alla restrizione web valgono soltanto sugli endpoint che servono
  effettivamente ActivityPub, non in base a un Accept arbitrario su ogni URL.
- La pagina di un contenuto richiedente autenticazione non deve esporre testo,
  anteprime o metadati nei frammenti AJAX, nelle citazioni o nei link accessori.

## Agganci e verifiche

Servizio centrale per decisioni e filtri di sola consultazione web, separato
dagli scope condivisi con ActivityPub e REST. Controlli prima dei fetch remoti,
protezione delle pagine dirette e applicazione dei filtri prima della paginazione.
Riusare controller, query e impostazioni attuali; nessun nuovo framework.

Adeguare i test esistenti e aggiungere solo copertura mirata per matrice delle
modalità, configurazione implicita, salvataggio isolato, pagine dirette,
frammenti, citazioni, commenti, home e mantenimento dei confini AP/REST/media.
Verificare su MySQL i piani dei filtri effettivamente modificati. Eseguire
suite mirate e suite completa. Lasciare tutte le modifiche non committate per
la prova locale dell'utente.

## Implementazione

`PublicWebVisibility` legge `public_web_visibility` e `public_web_hashtags`
con valori impliciti `all` e `true`, senza inserimenti automatici; un valore
modalità non riconosciuto chiude la consultazione anonima. La preferenza
`guest_home_feed` mantiene chiave e valore già esistenti. I tre campi vengono
salvati insieme soltanto dal nuovo tab, con validazione e audit delle
impostazioni; Aspetto gestisce ora soltanto il CSS.

Un middleware web controlla esplicitamente le pagine di consultazione, prima
dei fetch remoti. Le risposte HTML riservate portano al login; richieste AJAX
oppure JSON ricevono 401. I permessi originari continuano a produrre 404 per
contenuti non accessibili. Le risposte web coinvolte, compresa la home,
evitano la cache condivisa e variano per Accept/Cookie. Gli identificatori
canonici che negoziano ActivityPub conservano la rappresentazione federata.

I filtri sugli autori locali sono applicati prima dei cursori o della
paginazione. Le query condivise accettano un parametro esplicito facoltativo,
con default invariato, evitando dipendenze implicite dal contesto web nei
consumatori REST. Le card e le citazioni rispettano il controllo anche quando
incorporate in contenuti ammessi. Le discussioni ammesse mantengono le risposte
remote. L’autore locale è identificato da `actors.is_local`, non dalla presenza
del post nel database o dalla località della community o della ricondivisione;
gli attori dei feed importati sono remoti e quindi esclusi da Solo locali.

## Verifiche effettuate

- Suite completa: 2.379 test, 18.619 asserzioni, 131 saltati; nessun fallimento.
  Aggiunti sei test mirati con scenari raggruppati, adeguando quelli esistenti
  per il trasferimento della preferenza del feed e i confini REST/allegati.
- Ultimo controllo mirato dopo la revisione: 63 test e 651 asserzioni,
  inclusi REST autenticata e lettura anonima degli allegati con Tutto chiuso.
  Pint e controllo del diff superati.
- Controllo visivo su anteprima amministrativa con database SQLite isolato:
  tab, modalità e scomparsa dell’opzione hashtag in Solo locali.
- EXPLAIN delle query reali su MySQL locale: 50.106 post, 33.145 attori e
  153 eventi. Feed home: `actors_is_local_index` e
  `posts_popular_remote_actor_index`; profilo: indice autore/data e indici
  univoci delle condivisioni; community: `posts_community_feed_timeline_index`
  con scansione inversa. Query eventi: indice autore/data e PK delle
  attribuzioni. I rami di archivio possono scegliere una scansione sui circa
  150 eventi stimati con ordinamento, mentre il conteggio usa l’indice
  autore/data. Nessun nuovo indice introdotto sulla base di questo campione:
  non è una prova di prestazioni su grandi archivi eventi.
- Documentazione di configurazione inglese/italiana e changelog aggiornati;
  traduzioni italiano, inglese e finlandese allineate.

Implementazione completata e sottoposta alla revisione finale dell’intero ramo,
compresa la riorganizzazione amministrativa precedente. Riallineati anche i
riferimenti al feed ospiti nelle guide di architettura inglese e italiana.

Verifica conclusiva del ramo: 187 test mirati e 1.684 asserzioni superati
(admin, visibilità, statistiche, avatar e confini REST/allegati); confermati
Pint, sintassi JavaScript e controllo del diff. Nessun campo del form originale
è andato perso nella separazione delle schermate. Nessun problema bloccante
rimasto dalla review. La suite completa riportata sopra resta valida: nella
review finale sono stati modificati soltanto riferimenti documentali.
