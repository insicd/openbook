> Documentazione: [Italiano](README.it.md) · [English](architecture.md)

# Usare Openbook

Openbook permette di pubblicare, seguire persone, conversare e partecipare a
community ed eventi. Un account può interagire sia con persone della stessa
istanza sia con account di altri servizi compatibili del Fediverso.

## Registrazione e lingua dell’interfaccia

Durante la registrazione puoi scrivere lo username anche con lettere maiuscole:
viene salvato in minuscolo. Il nome visualizzato del profilo è personalizzabile.

L'icona della lingua nella barra superiore apre le lingue disponibili.
Puoi scegliere Italiano, English o Suomi (finlandese).
Prima dell'accesso Openbook usa la lingua del browser; una scelta esplicita
viene ricordata sullo stesso browser per un anno e ha la precedenza.
Il nuovo account eredita la lingua usata durante la registrazione.

Dopo l'accesso viene usata la preferenza dell'account. Cambiarla dalla barra
aggiorna anche **Impostazioni → Account e privacy → Lingua**, e vale per gli accessi
dagli altri dispositivi. Il cambio ricarica la pagina corrente. Su mobile
il selettore degli utenti autenticati si trova nel menu delle altre opzioni.

## Pubblicare e leggere i post

Scrivi nella casella di composizione. Le icone nella barra inferiore aprono i
controlli avanzati, come titolo, avviso sul contenuto, allegati, visibilità e
sondaggio. Aprire un altro pannello conserva i valori già inseriti.

Il testo supporta Markdown, link, hashtag e menzioni. Puoi allegare immagini e
audio; i video locali sono disponibili quando l’amministratore li abilita.
I video richiedono elaborazione: la pubblicazione avviene quando questa termina.
Gli avvisi sui contenuti permettono al lettore di scegliere quando mostrare
il testo e gli allegati. Clicca un’immagine per aprirla a schermo intero;
usa le frecce per scorrere e Esc per chiudere.

Le icone sotto il post permettono di mettere Mi piace, commentare e condividere.
Il menu a tre puntini contiene le azioni disponibili sul contenuto, fra cui
modifica ed eliminazione dei propri post. La condivisione mantiene il
collegamento al post originale e compare nella cronologia di chi condivide.

Nelle liste, l’intestazione della card apre il dettaglio locale. Il nome apre
il profilo; la data di un post remoto apre l’originale sul server di provenienza.
Il pulsante Indietro nel dettaglio riporta alla pagina precedente quando il post
è stato aperto dalla stessa istanza.

## Visibilità

Scegli la visibilità prima della pubblicazione:

| Visibilità | Chi può leggere |
| --- | --- |
| Pubblica | Chiunque; il post può comparire negli elenchi pubblici. |
| Non elencata | Chi possiede il link; il post resta fuori dagli elenchi pubblici. |
| Solo follower | L’autore e i follower autorizzati, secondo i permessi correnti. |
| Diretta | L’autore e i destinatari menzionati; per conversare usa la sezione Messaggi. |

Le community private applicano i propri permessi di accesso. Condividere un link
o citare un contenuto riservato mantiene tali permessi. Una copia già consegnata
a un altro server dipende anche dalle regole e dalla gestione di quel server.

## Sondaggi

L’icona del sondaggio apre due campi iniziali. Aggiungi le opzioni una alla volta
fino a sei, con massimo 50 caratteri per risposta. Il testo del post è la domanda.
Puoi consentire una o più risposte e scegliere una durata da cinque minuti a
sette giorni; il valore iniziale è un giorno.

Dopo la pubblicazione opzioni, modalità, scadenza e visibilità restano fisse.
Il testo del post può essere corretto. La durata parte dalla pubblicazione
effettiva, anche quando il post contiene un video da elaborare.

**Vota** conferma la scelta definitiva. **Anteprima risultati** permette di
consultare gli aggregati prima del voto; **Torna alle opzioni** ripristina la
selezione. Dopo il voto trovi la tua risposta e «Hai votato il …», con data e
ora, accanto al totale dei partecipanti. Alla scadenza il sondaggio si chiude.
Nella scelta multipla una persona può contribuire a più risposte: la somma delle
percentuali può superare il 100%, mentre i partecipanti sono contati una volta.

L’autore può aprire **Dettaglio risposte** e consultare i votanti per opzione,
locali e remoti, con paginazione. Gli altri utenti vedono gli aggregati e le
proprie scelte. L’icona informazioni accanto alle azioni spiega questi aspetti.
I sondaggi non sono anonimi verso l’autore. Le risposte degli account cancellati
rimangono nei conteggi, con l’identità scollegata.

I sondaggi sono disponibili nei post personali e nelle community locali, anche
private. Sono esclusi conversazioni private, commenti, eventi e pubblicazione
verso community remote. La chiusura è programmata: data e ora restano quelle
scelte alla pubblicazione. Per i sondaggi di altri server, vedi
[sondaggi remoti](federation.it.md#sondaggi-remoti).

## Home, hashtag e ricerca

La Home mostra in ordine cronologico inverso i tuoi post, quelli degli account
seguiti e le loro condivisioni. Seguendo un hashtag entrano nella Home anche
post pubblici pertinenti già conosciuti dall’istanza. Gli hashtag seguiti sono
una preferenza privata del tuo account.

Finché segui meno di 10 persone e hashtag complessivamente, un riquadro di
benvenuto sopra i post suggerisce come popolare il feed. Contano le persone
locali e remote con follow accettato e gli hashtag; community e richieste
in attesa sono escluse. A 10 il riquadro scompare; tornando sotto 10 riappare.
Il riquadro compare solo all'inizio del feed. Le azioni per esplorare community e
argomenti in tendenza compaiono dopo lo staff e prima delle persone suggerite;
«Scopri altre persone» si trova in fondo ai suggerimenti.
La lista «Dal fediverso» compare soltanto finché segui zero persone e hashtag;
dopo il primo follow puoi continuare la scoperta dal pulsante in fondo.

La ricerca trova persone, hashtag ed eventi visibili; per post e commenti
la ricerca per parole chiave riguarda i contenuti locali. Per trovare un account
remoto usa l’indirizzo completo `utente@dominio`. Le menzioni durante la scrittura
riconoscono account locali e remoti già conosciuti: cerca prima un indirizzo
remoto nuovo. L’inclusione dei post pubblici altrui nella ricerca dipende anche
dal consenso all’indicizzazione dell’autore.

Il riquadro delle tendenze mostra hashtag usati di recente nei contenuti locali
pubblici o non elencati. La Home e Mondo richiedono JavaScript per caricare i feed.
Profili, community e pagine degli hashtag offrono anche link di paginazione
quando JavaScript è disabilitato.

## Stats

Il link **Stats** nei footer apre una pagina riservata agli utenti autenticati,
senza requisiti di ruolo. La prima versione mostra i post pubblici conservati
nelle ultime 360 ore complete (UTC) e le lingue di post ed eventi pubblici
conservati negli ultimi due anni, sommati per lingua. I contenuti senza lingua
dichiarata sono esclusi dal grafico delle lingue e dai relativi totali.

Sono disponibili anche gli account per istanza, sommati senza distinzione di tipo,
il volume dei post e gli autori distinti per settimana negli ultimi due anni.
Mostriamo le dieci istanze e le dieci lingue principali; le lingue restanti
sono raggruppate in «Altre».
Volume e autori attivi mostrano ciascuno una sola serie totale settimanale,
senza filtri per istanza né distinzione tra locali e remoti. I conteggi
degli autori non sono sommabili tra settimane.

Il grafico hashtag include i top 10 di ciascuno degli ultimi 24 mesi di
calendario (mese corrente incluso, parziale) e i top 10 dell’intero periodo.
Ogni mese mostra al massimo dieci hashtag: fuori dalla top 10 la serie è
interrotta, mantenendo identità e colore quando un hashtag rientra. La legenda contiene solo
i dieci più usati nel periodo: passaggio del mouse o focus evidenziano la
serie; i tooltip identificano anche gli hashtag fuori legenda. I range restano
in UTC; la data di elaborazione usa il fuso del visitatore.

La geografia mostra i paesi associati ai luoghi dei contenuti, sommando
post ed eventi senza selettori. Paesi non indicati e gruppi piccoli sono aggregati;
non vengono mostrate coordinate o posizioni degli autori. Il calendario
raggruppa per mese di inizio gli eventi pubblicati negli ultimi due anni,
compresi quelli con inizio futuro. Le community mostrano quante community
pubbliche ammissibili hanno pubblicato o rilanciato post conservati, inclusi
i Group remoti. Le settimane seguono la pubblicazione originale del post; ogni
community e ogni post contano una volta per settimana, anche con più rilanci.

Gli allegati mostrano immagini e video dei post: totali dei file e andamento
settimanale, con un unico dataset condiviso dai due grafici. Un post può avere
più file o entrambi i tipi; audio, documenti ed embed esterni sono esclusi.
I sondaggi mostrano la quota settimanale dei post pubblici con sondaggio,
aperto o chiuso, senza consultare voti o partecipanti. Il denominatore include
tutti i post pubblici ammessi della settimana; una settimana senza post non
ha percentuale e interrompe la linea.

Ogni grafico carica i dati quando ti avvicini alla sua posizione nella pagina;
un errore può essere ritentato senza ricaricare gli altri. In questa fase non
è attiva una cache dei risultati. Le statistiche rispettano le esclusioni
di visibilità e moderazione, inclusi account sospesi o eliminati e domini
bloccati. Tutte le statistiche aggregate includono anche gli account con
`discoverable=false` e `indexable=false`. Descrivono il corpus ancora
conservato, non tutto il Fediverso: retention e cancellazioni possono cambiare
le serie e uno zero non prova l'assenza storica di pubblicazioni.

## Profilo e impostazioni

Apri **Impostazioni** dal menu utente o **Modifica profilo** sul tuo profilo.
Puoi aggiornare nome, biografia, avatar, copertina e fino a otto campi, condivisi
fra Link e Informazioni aggiuntive. Le immagini vengono mostrate in anteprima
prima del salvataggio. Le modifiche del profilo sono comunicate ai follower remoti.

Le preferenze permettono di scegliere lingua dell’interfaccia, fuso orario e
visibilità iniziale dei nuovi post. Con **Account protetto** approvi le richieste di follow.
Puoi scegliere se comparire nei suggerimenti e se consentire l’indicizzazione
dei tuoi post pubblici. Disattivare i suggerimenti lascia raggiungibile il profilo
tramite il suo indirizzo esatto.

In **Impostazioni → Account e privacy → Fuso orario**, **Automatico — dal dispositivo**
segue il fuso del browser, anche quando viaggi o cambi dispositivo. Puoi
scegliere un fuso specifico per usare sempre quello e tornare ad Automatico
in qualsiasi momento. I nuovi account e quelli aggiornati partono in Automatico.
La lingua dell'interfaccia è indipendente dal fuso scelto.

Messaggi, scadenze dei sondaggi e date dei profili seguono il tuo fuso.
Anche gli ospiti vedono gli orari nel fuso del proprio browser. Il rilevamento
non richiede permessi di geolocalizzazione; in Automatico, senza JavaScript,
cookie o un fuso valido si usa quello dell'istanza. Il cambio del fuso non
modifica le scadenze o gli istanti di pubblicazione.

## Notifiche e messaggi

La campanella apre le notifiche recenti; la pagina Notifiche contiene l’elenco
completo. I badge si aggiornano mentre la scheda è visibile. Le notifiche push
sono disponibili quando abilitate e autorizzate nel browser.

La sezione **Messaggi** raccoglie le conversazioni private. Nuovi messaggi nella
stessa conversazione aggiornano la relativa notifica. Aprire la conversazione
la segna come letta; leggere le sole notifiche generali mantiene distinto lo
stato di lettura dei messaggi. Il thread segue l’ordine di arrivo sull’istanza,
mentre la data mostrata sul messaggio resta quella della pubblicazione originale.

## Mondo, community ed eventi

**Mondo** mostra contenuti pubblici remoti già conosciuti dall’istanza. **Scopri**
propone account remoti da seguire. Queste pagine dipendono dai contenuti ricevuti
o recuperati dall’istanza, senza rappresentare un catalogo completo del Fediverso.

La sezione **Community** distingue le tue iscrizioni, le community locali e
quelle remote. **Eventi** mostra appuntamenti in arrivo e passati e permette
agli utenti autenticati di crearne. Per iscrizioni, campanelle, partecipazione
e interazioni con altri server, vedi la [guida alla federazione](federation.it.md).

## Gestire un’istanza

Gli amministratori gestiscono impostazioni, account, moderazione, domini
bloccati, code e manutenzione dal pannello di amministrazione. I moderatori
hanno accesso alle funzioni assegnate al loro ruolo; il ruolo di staff non dà
accesso al dettaglio delle risposte ai sondaggi di altri autori. In **Aspetto**,
un flusso pubblico opzionale sulla home per gli ospiti (disattivo di default)
sposta le informazioni sull’istanza a sinistra e al centro mostra i post
pubblici già visibili all’istanza.

Per installazione e aggiornamenti segui la [guida dedicata](install.it.md).
Per cron, manutenzione, retention e video consulta la
[configurazione](configuration.it.md); per i limiti correnti la
[roadmap](roadmap.it.md).
