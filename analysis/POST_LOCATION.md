# Post location — analisi

Documento di analisi e memoria delle decisioni implementative. Le sezioni
iniziali descrivono la situazione precedente alla feature; lo stato realizzato
e' riepilogato in "Implementazione concordata".

## Obiettivo

Permettere all'utente di associare volontariamente una posizione a un nuovo
post e federarla tramite ActivityPub. La posizione non deve mai essere letta o
aggiunta automaticamente: il composer espone un'azione esplicita e il browser
chiede il normale consenso alla geolocalizzazione.

## Standard e interoperabilita'

ActivityStreams 2.0 ammette la proprieta' `location` su qualsiasi `Object`,
quindi anche su una `Note`. Il valore consigliato e' un oggetto `Place`, che
puo' avere soltanto un nome oppure includere `latitude`, `longitude`,
`accuracy`, `altitude`, `radius` e `units`.

Esempio minimo con coordinate:

```json
{
  "type": "Note",
  "content": "Foto dal porto",
  "location": {
    "type": "Place",
    "name": "Helsinki",
    "latitude": 60.1699,
    "longitude": 24.9384
  }
}
```

Pixelfed documenta e produce questa struttura per il geotagging dei propri
status, aggiungendo anche `country`. Mastodon non espone location nei parametri
di creazione, nell'entita' REST Status o nella documentazione di federazione:
la previsione prudente e' che accetti il post ma ignori il campo. Altri server
possono mostrarlo oppure ignorarlo senza invalidare la Note.

OpenBook contiene gia' nel proprio contesto JSON-LD i termini `Place`,
`location`, `latitude` e `longitude`, ma prima di questa implementazione non
conservava, serializzava, interpretava o mostrava la posizione dei post.

## Decisioni iniziali

- posizione sempre facoltativa;
- acquisizione soltanto dopo un click esplicito dell'utente;
- nessun tentativo automatico all'apertura del composer;
- supporto limitato ai post, non ai commenti;
- prima versione basata sul vocabolario ActivityStreams standard, evitando
  estensioni proprietarie necessarie al funzionamento;
- un solo luogo per post, anche se `location` nello standard puo' avere piu'
  valori;
- considerare le coordinate dato sensibile: UI chiara e rimozione semplice
  prima della pubblicazione.

### Vincolo privacy non negoziabile

OpenBook non deve mai salvare nel post, mostrare in UI o federare latitudine e
longitudine precise restituite dal dispositivo. Le coordinate del browser
servono esclusivamente, e in modo transitorio, a individuare la citta' piu'
vicina. Nel post vengono conservati e pubblicati soltanto nome, codice paese e
coordinate del centro della citta' scelta presenti nel catalogo GeoNames.

Le coordinate precise non devono comparire in query string, log applicativi,
job, sessione o tabelle temporanee. L'eventuale endpoint di lookup le riceve nel
body di una richiesta autenticata, le valida, esegue la ricerca in memoria e le
scarta immediatamente. Anche questo passaggio avviene solo dopo due azioni
esplicite: apertura del pannello location e click su "Posizione attuale", oltre
al consenso gestito dal browser.

Questo vincolo dovra' essere dichiarato chiaramente anche nel changelog e nella
documentazione pubblica della feature.

## Flusso del composer

Il composer aggiunge un toggle "Aggiungi posizione", coerente con gli altri
pannelli facoltativi. Il primo click mostra la sezione location ma non consulta
la Geolocation API.

Dentro il pannello l'utente puo' scegliere fra due percorsi:

1. cercare e scegliere un luogo del catalogo locale tramite autocomplete,
   utile anche per contenuti relativi a un posto diverso da quello in cui si
   trova (esempio: pubblicare da Helsinki una foto scattata a Bali);
2. premere "Posizione attuale": solo allora il browser chiede il consenso,
   ottiene le coordinate e OpenBook propone la citta' GeoNames piu' vicina.

La posizione proposta deve essere visibile prima del submit e deve poter essere
rimossa. Non esiste testo libero: sia la scelta manuale sia quella ricavata
dalla posizione corrente identificano una riga canonica del catalogo. Anche in
modifica il post permette di aggiungere, sostituire e rimuovere la location con
lo stesso autocomplete.

## Prima direzione consigliata

La direzione preferita e' ora una lookup locale delle citta', senza servizi di
reverse geocoding esterni:

- toggle esplicito che apre il pannello location senza leggere la posizione;
- ricerca manuale tramite autocomplete, limitata ai luoghi presenti nel
  catalogo locale;
- pulsante separato "Posizione attuale";
- coordinate e accuratezza ottenute dal browser solo dopo il consenso;
- proposta automatica della citta' piu' vicina usando il catalogo locale;
- location sostituibile tramite autocomplete o eliminabile dall'utente;
- nessuna mappa interattiva nella prima versione;
- nessuna coordinata comunicata a terzi durante la composizione.

Il payload ActivityStreams contiene nome, codice paese e coordinate del centro
GeoNames del luogo selezionato. Non contiene mai le coordinate precise del
dispositivo.

## Alternativa emersa: catalogo locale delle citta'

Per ottenere una label a granularita' cittadina senza comunicare coordinate a
servizi esterni, e' possibile importare una selezione del dump GeoNames in una
tabella locale e cercare il centro abitato piu' vicino.

Non serve un sistema GIS completo. Una prima versione database-agnostic puo':

1. filtrare per un piccolo bounding box attorno a latitudine/longitudine;
2. caricare poche citta' candidate grazie a un indice geografico semplice;
3. calcolare in PHP la distanza Haversine e scegliere la piu' vicina;
4. espandere il bounding box soltanto se non trova candidati.

Questo evita funzioni spaziali diverse tra MySQL e SQLite. Per rendere il
primo filtro efficiente si possono salvare anche bucket interi di latitudine e
longitudine con indice composto, interrogando i bucket adiacenti. Non e'
necessario introdurre MySQL Spatial, SQLite RTree o PostGIS.

GeoNames pubblica dump mondiali sotto licenza CC BY e richiede attribuzione.
Il numero nel nome del dataset indica principalmente la soglia minima di
popolazione, non l'importanza del luogo, la distanza di ricerca o la precisione
geografica. GeoNames aggiunge inoltre alcune sedi amministrative anche sotto
soglia. In particolare:

- `cities15000`: centri sopra 15.000 abitanti oppure capitali;
- `cities5000`: centri sopra 5.000 abitanti oppure sedi amministrative PPLA;
- `cities1000`: centri sopra 1.000 abitanti oppure sedi amministrative fino a
  PPLA3;
- `cities500`: centri sopra 500 abitanti oppure sedi amministrative fino a
  PPLA4.

Al momento dell'analisi gli archivi compressi occupano indicativamente da 3 MB
per `cities15000` a 13 MB per `cities500`; `cities1000` e' circa 10 MB. La
distanza massima entro cui proporre una citta' resta invece una nostra regola
applicativa indipendente dal dataset.

Il dump completo `allCountries.zip` (circa 398 MB compresso e milioni di
luoghi) sarebbe invece sproporzionato. Anche il pacchetto mondiale dei nomi
alternativi (circa 190 MB compresso) va escluso dalla prima versione.

La soluzione sembra quindi tecnicamente ragionevole, ma va deciso come
distribuire i dati. Inserire decine di migliaia di righe in una migration PHP
renderebbe repository, installazione e test inutilmente pesanti. Opzioni:

- dataset ridotto e versionato come file compresso, importato da un comando;
- download/import esplicito tramite comando amministrativo;
- pacchetto separato opzionale.

Dato che non ammettiamo testo libero, `cities500` e' il dataset predefinito: offre
la copertura migliore con una differenza operativa ancora contenuta rispetto a
`cities1000`. La prima versione rappresenta i centri abitati del catalogo; una
ricerca come "Bali" puo' trovare Denpasar e mostrarla con la gerarchia
"Denpasar, Bali, Indonesia". "Citta' piu' vicina" non equivale sempre al luogo
che l'utente intende descrivere, soprattutto in aree rurali, isole o confini.

Le righe `cities500` contengono codice paese e codici amministrativi, non sempre
i relativi nomi estesi. Per costruire label affidabili come quella sopra,
l'import predefinito comprende anche i piccoli dizionari ufficiali
`admin1CodesASCII.txt` e `countryInfo.txt`. Non bisogna dedurre la gerarchia dal
campo dei nomi alternativi della citta'.

### MySQL Spatial e fallback SQLite

Non e' necessario biforcare subito la ricerca. Su MySQL sarebbe possibile
aggiungere `POINT`, SRID 4326, indice `SPATIAL` e `ST_Distance_Sphere()`, mentre
SQLite standard non offre le stesse primitive senza SpatiaLite/RTree.

La biforcazione sarebbe tecnicamente contenuta se isolata dietro un servizio
`NearestCityFinder`, ma introdurrebbe due schemi, due import path e due famiglie
di test. Inoltre anche MySQL beneficia di un bounding box preliminare: ordinare
l'intera tabella per `ST_Distance_Sphere()` non garantisce da solo l'uso
efficiente dell'indice spaziale.

Per il caso attuale — una lookup per click su un catalogo relativamente piccolo
— si parte con bucket/bounding box e Haversine PHP sia su MySQL sia su SQLite.
Il servizio resta isolato, quindi una strategia MySQL Spatial potra' essere
aggiunta senza cambiare controller o frontend se in futuro compariranno feed di
prossimita', filtri per raggio o dataset molto piu' grandi.

## Aggiornamento del catalogo citta'

Requisito: comando Artisan dedicato, indicativamente:

```console
php artisan openbook:update-cities
php artisan openbook:update-cities /percorso/cities500.zip
php artisan openbook:update-cities /percorso/cities500.txt
```

Comportamento previsto:

- senza argomento scarica via HTTPS il dump ufficiale GeoNames selezionato;
- con un percorso usa il file locale e non accede alla rete;
- accetta sia l'archivio `.zip` ufficiale sia il TSV gia' estratto;
- valida formato, intestazione implicita/numero di colonne, coordinate,
  feature class e identificativi prima di modificare dati esistenti;
- importa a chunk per limitare memoria e dimensione delle query;
- usa `geonameid` come identificatore stabile e fa upsert idempotente;
- elimina le righe non piu' presenti soltanto dopo un import completato con
  successo, evitando di lasciare il catalogo vuoto in caso di download o parse
  fallito;
- mostra origine, numero di righe inserite/aggiornate/eliminate e durata;
- un lock impedisce due aggiornamenti simultanei.

Il catalogo principale e' un solo archivio: `cities500.zip` contiene
`cities500.txt`. Ogni riga include nome, nomi alternativi in forma compatta,
coordinate, codice paese, codici delle divisioni amministrative e popolazione.
Non serve `alternateNamesV2.zip` nella prima versione. Il download predefinito
acquisisce inoltre `admin1CodesASCII.txt` e `countryInfo.txt`, necessari per
trasformare i codici in label leggibili. L'import da file deve restare offline:
puo' usare gli eventuali dizionari gia' installati e, se assenti, degradare a
nome della citta' e codice paese senza inventare nomi.

Il download remoto deve avere timeout, dimensione massima, file temporaneo e
messaggi d'errore chiari. L'URL ufficiale puo' essere un default di config, non
un'opzione CLI arbitraria; il percorso esplicito copre installazioni offline e
test riproducibili.

L'aggiornamento non deve essere inserito nel cron generale: il dataset cambia
lentamente e l'amministratore puo' lanciarlo durante installazione e poi quando
desidera. Un aggiornamento pianificato resta possibile richiamando lo stesso
comando da cron.

## Rilettura architetturale

La feature resta separabile in quattro responsabilita': catalogo/import,
risoluzione della citta', persistenza sul post, UI/federazione. Non richiede un
provider esterno, una mappa, estensioni geografiche del database o modifiche al
protocollo ActivityPub.

I principali rischi non sono computazionali ma di prodotto e dati:

- una citta' geometricamente vicina puo' non essere la label piu' sensata;
- un catalogo troppo selettivo penalizza aree rurali, uno troppo ampio aumenta
  spazio e ambiguita';
- le coordinate precise del dispositivo devono restare input transitorio e non
  devono essere confuse con quelle pubblicabili del centro cittadino;
- GeoNames richiede attribuzione CC BY;
- senza catalogo importato il pulsante deve degradare in modo comprensibile,
  non produrre un errore generico.

La struttura proposta non appare una spremuta di sangue. Il comando importer e
la ricerca sono lavori circoscritti; la parte che merita piu' cautela e' il
contratto esatto del dato salvato e federato.

## Punti ancora da definire

- persistenza con colonne nullable sul post oppure tabella uno-a-uno dedicata;
- visualizzazione locale: nome ed eventuale codice paese, senza mostrare le
  coordinate;
- import e visualizzazione delle location ricevute da attori remoti;
- comportamento se il catalogo non e' ancora importato o non trova una citta'
  entro una distanza ragionevole;
- attribuzione GeoNames nell'interfaccia o nella documentazione richiesta
  dalla licenza CC BY.

## Implementazione concordata

- `cities500` e' il catalogo predefinito; il comando
  `openbook:update-cities` scarica anche i dizionari GeoNames di paesi e
  regioni, oppure importa offline uno ZIP/TSV fornito dall'amministratore;
- `geo_cities` usa indici sui prefissi testuali e bucket geografici compatibili
  con MySQL e SQLite; il piano MySQL dell'autocomplete e' stato verificato con
  `EXPLAIN` sul catalogo completo;
- `post_locations` conserva uno snapshot uno-a-uno. I post locali mantengono
  il riferimento GeoNames, quelli remoti hanno `geo_city_id` nullo e
  `source=remote`;
- autocomplete e lookup della citta' piu' vicina sono endpoint autenticati;
  le coordinate del browser attraversano soltanto il body della singola
  richiesta e non vengono persistite;
- creazione e modifica consentono aggiunta, sostituzione e rimozione; anche il
  percorso differito del worker video conserva la scelta;
- le Note locali federano un oggetto `Place`; le Note remote vengono validate
  e conservate fedelmente senza riconciliazione col catalogo locale;
- la UI mostra esclusivamente la label testuale con icona, mai le coordinate;
- la cancellazione logica del post rimuove la relativa location in tutti i
  percorsi applicativi noti;
- attribuzione GeoNames visibile nel composer e documentata nei README.
- l'import riuscito valorizza `locations_catalog_ready` in `system_settings`;
  fino a quel momento pannello e pulsante location restano nascosti, mentre la
  ricezione e visualizzazione dei `Place` remoti non dipendono dal catalogo;

## Fonti

- ActivityStreams 2.0 Vocabulary:
  https://www.w3.org/TR/activitystreams-vocabulary/
- Pixelfed ActivityPub, Location (Geo-tagging):
  https://pixelfed.github.io/docs-next/spec/ActivityPub.html#location-geo-tagging
- GeoNames data export e licenza:
  https://www.geonames.org/export/
- GeoNames dump:
  https://download.geonames.org/export/dump/
