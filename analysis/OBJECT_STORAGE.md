# Object storage opzionale per i media

Stato al 2 novembre 2026: funzionalità implementata e collaudata dall'utente,
con revisione complessiva del ramo `codex/object-storage-analysis`.
L'attività si chiude al perimetro descritto qui. Lo **Sprint 5 originariamente
ipotizzato non è previsto per l'implementazione al momento**: trasferimento
dell'archivio e prova completa di ripristino restano gap noti, accettati
esplicitamente dall'utente. Le attività residue della sezione 6 non sono avviate.

## 1. Obiettivo e perimetro consegnato

Offrire un'alternativa opzionale al disco locale per i nuovi media permanenti,
attraverso un'unica integrazione S3 compatibile con più provider. Il caso reale
iniziale è Cloudflare R2 per Punk Kitchen, senza dipendenze specifiche da
Cloudflare nel flusso applicativo.

L'obiettivo iniziale di disaster recovery è soddisfatto solo parzialmente:
i file effettivamente registrati sul bucket non dipendono dal disco pubblico
locale; file preesistenti, staging e ripristino completo richiedono ancora
attenzione operativa. L'object storage non è un backup con versioni: le
cancellazioni applicative vengono propagate al bucket.

Principi confermati:

- KISS e shared hosting: nessun Redis, worker permanente o servizio esterno
  obbligatorio per il funzionamento ordinario di OpenBook.
- I due controlli sono destinazione dei nuovi upload e distribuzione diretta.
  L'attivazione non trasferisce l'archivio e non riscrive i riferimenti esistenti.
- Elaborazione locale tramite i servizi attuali, poi scrittura sul disco scelto.
  Nessun upload diretto dal browser o seconda pipeline di elaborazione.
- Accessibilità dei file coerente con quella preesistente. Autorizzazione e
  visibilità dei contenuti che li contengono non vengono modificate.
- Nessun fallback silenzioso su disco locale se una scrittura S3 fallisce.
- Una sola destinazione S3 configurata; nessun registro di bucket storici,
  sincronizzazione bidirezionale, sistema ACL o motore di backup interno.

## 2. Configurazione e provider

Pagina dedicata **Admin → Object storage**, `/admin/object-storage`, protetta
con le autorizzazioni admin e CSRF esistenti. Configurazione autorevole in
`system_settings`, una voce `object_storage_*` per parametro; nessuna seconda
configurazione AWS nel `.env`.

| Parametro | Significato |
| --- | --- |
| `enabled` | Sceglie il bucket per i nuovi upload |
| `direct` | Distribuisce gli oggetti tramite URL pubblico, anziché OpenBook |
| `provider` | Preset compatibile S3 |
| `account_id` | Account Cloudflare R2 |
| `bucket`, `region`, `endpoint` | Destinazione e parametri API pertinenti al preset |
| `access_key`, `secret` | Credenziali API; il campo del modulo per la secret è `password` |
| `path_style` | Bucket nel percorso dell'endpoint, per il preset generico |
| `public_url` | URL pubblico base del bucket o della CDN, distinto dall'endpoint API |

Solo `object_storage_secret`, fra le nuove impostazioni, è cifrata con
`APP_KEY`; le altre restano in chiaro. Il segreto non viene restituito dal
modulo né inserito nei dati di validazione riproposti all'utente. Lasciare
vuota la password conserva quella salvata. Una secret non decifrabile blocca
l'uso del bucket senza rigenerare chiavi o cambiare destinazione.

| Preset | Dati specifici | Configurazione derivata |
| --- | --- | --- |
| Cloudflare R2 | Account ID | Endpoint dell'account, regione `auto` |
| Amazon S3 | Regione | Endpoint standard del client S3 |
| DigitalOcean Spaces | Regione Spaces | Endpoint regionale; regione SDK `us-east-1` |
| OVHcloud Object Storage S3 | Regione ed endpoint HTTPS del pannello | Parametri espliciti, senza mappe di offerte o regioni |
| Altro compatibile S3 | Regione, endpoint HTTPS, eventuale path-style | Parametri espliciti |

I preset configurano lo stesso adattatore; non costituiscono certificazione
di tutti i provider. Google Cloud Storage non ha un preset dedicato: chiavi
HMAC e interoperabilità XML/S3 richiederebbero verifiche specifiche.
Il modulo resta utilizzabile senza JavaScript, con tutti i campi e i relativi
aiuti visibili. Con JavaScript mostra i campi pertinenti al preset.

`ObjectStorageSettings` costruisce il disco Laravel `objectStorage` tramite
`league/flysystem-aws-s3-v3`. La configurazione viene letta su richiesta e il
disco già risolto viene invalidato al salvataggio. Anche il processo video
ricarica il collegamento prima di ogni pubblicazione, così le modifiche
amministrative valgono per i lavori successivi. Restano necessari i normali
riavvii dei processi per caricare nuovo codice dopo un deploy.

Nessuna ACL impostata sugli oggetti: il middleware SDK rimuove quella aggiunta
automaticamente da Flysystem. Accesso pubblico e CDN dipendono dalle policy
impostate dall'amministratore presso il provider. Le scritture usano singoli
PUT, anche con stream, entro i limiti applicativi esistenti, evitando sessioni
multipart abbandonate. Timeout: connessione 5 secondi, operazioni 300, lettura
30; nessun retry automatico SDK.

**Verifica connessione** usa solo i parametri già salvati: scrive un oggetto
casuale in `openbook-connection-test/`, rilegge e confronta il contenuto, poi
lo cancella. Tenta la pulizia anche dopo un fallimento; indica solo la fase
fallita e l'eventuale pulizia non riuscita, senza esporre richieste firmate,
credenziali o messaggi SDK. Timeout dell'operazione limitato a 15 secondi.
Non verifica la distribuzione tramite URL pubblico.

L'adattatore aggiunge il requisito PHP `simplexml`, incluso in controlli
installer/bootstrap e guide di installazione, anche con storage disabilitato.

## 3. Destinazione e distribuzione

| Nuovi upload su S3 | Distribuzione diretta | Nuovi file | Oggetti S3 già registrati |
| --- | --- | --- | --- |
| No | Conservata | Disco locale | Continuano a usare bucket e distribuzione corrente |
| Sì | No | Bucket | Streaming attraverso OpenBook |
| Sì | Sì | Bucket | URL pubblico del bucket/CDN |

Il disco registrato sul singolo file resta autorevole per lettura,
sostituzione e cancellazione. Spegnere i nuovi upload non disabilita la
lettura degli oggetti esistenti; configurazione e credenziali vanno conservate.
La distribuzione diretta non cambia gli URL dei file locali e spegnerla non
revoca URL pubblici già diffusi, policy del bucket o copie nelle cache.

URL diretti stabili: base configurata più chiave relativa con segmenti
codificati. Non si usano URL firmati a scadenza nelle rappresentazioni
ActivityPub persistenti. La modalità intermediata trasferisce i byte,
senza redirect al bucket e senza caricare l'intero video in memoria.

- Allegati: `/media-files/{media}/{variant?}`, UUID e variante `thumbnail`.
- Asset: `/asset-files/{kind}/{id}/{slot}/{version}`, proprietario, slot
  consentito e versione derivata dal percorso corrente. Non accetta percorsi
  filesystem dal client. La compatibilità degli URL asset basati sull'actor
  risolve i riferimenti della comunità senza generare nuovi URL di quel tipo.
- Le route servono soltanto oggetti gestiti con disco `objectStorage`;
  non diventano proxy generici per file locali o URL federati remoti.
- MIME esplicito, `nosniff`, cache pubblica di un'ora; per gli asset il nuovo
  percorso cambia anche la versione dell'URL, evitando riuso della vecchia cache.
- Audio/video supportano `HEAD`, singoli intervalli byte, 206 e 416. Range
  multipli o malformati e `If-Range` senza validator confrontabile producono
  la risposta completa. `HEAD` audio/video non scarica il corpo. Gli stream
  vengono chiusi anche in caso di interruzione del client o del provider.
- File mancanti: 404. Errori di accesso/configurazione S3: 503, senza errori
  grezzi del provider. Un'interruzione a risposta iniziata chiude lo stream;
  non aggiunge pagine di errore al contenuto multimediale.

## 4. Inventario e riferimenti persistenti

| Categoria | Scrittura e metadati | URL e consumatori |
| --- | --- | --- |
| Immagini di post/commenti/eventi/commenti evento | `MediaUploader`, `media`, pivot dei contenuti | `Media::url`, `thumbnailUrl`, `displayUrl`; web, REST, ActivityPub |
| Miniature e poster | `MediaUploader`, `media_variants` | Getter comuni dei media e player |
| Audio e video preparati | `MediaUploader`, `VideoPreparer`, media e varianti | Player web, REST e ActivityPub |
| Avatar e copertine di persone | `ProfileImageUploader`, `profiles.avatar_path/avatar_disk`, `cover_path/cover_disk` | `Profile`, getter dell'actor e serializer |
| Avatar e copertine di comunità | `ProfileImageUploader`, `CommunityUpdater`, stessi campi in `communities` | `Community`, getter del Group locale, web/REST/ActivityPub |
| Favicon, icone PWA e maskable | `InstanceIconUploader`, directory e disco in `system_settings` | `InstanceSettings`, site-icons e manifest |
| Sfondo della home | `HomeBackgroundUploader`, directory e disco in `system_settings` | `InstanceSettings::homeBackgroundUrl` e home ospite |

Nessun prefisso configurabile: gli allegati mantengono `media/YYYY/MM/`,
le immagini di profilo `avatars/` e `covers/`, le icone `instance-icons/`,
lo sfondo `home-backgrounds/`, con nomi casuali come nei flussi locali.
Le sei varianti note delle icone vengono eliminate per nome, senza listing S3.

Percorso e disco delle immagini delle comunità risiedono in `communities`,
con nomi simmetrici a `profiles`. Gli attori Group locali delegano ai getter
della comunità; `actors.icon_url/image_url` rimangono per immagini remote
o URL storici non riconducibili allo storage corrente. Ricaricare uno slot
popola il suo percorso/disco e svuota il relativo URL storico. Un riferimento
gestito ha la precedenza anche se sull'actor resta un URL obsoleto.

Le migrazioni conservano percorsi locali, trasferiscono i riferimenti già
registrati e riconoscono solo gli URL del disco public dell'istanza, senza
interpretare un generico `/storage/` esterno come file locale. Non copiano
né cancellano file. Per un database copiato dalla produzione, URL di un altro
host e file assenti in sviluppo restano quindi utilizzabili come URL storici.
I quattro metadati di storage intermedi sull'actor sono trasferiti e rimossi;
il rollback ripristina riferimenti e URL precedenti. Le due migrazioni restano
separate per compatibilità con database dove la prima era già applicata.

Su MySQL/MariaDB l'ordine è `avatar_disk`, `avatar_path`, `cover_disk`,
`cover_path`. SQLite ignora il posizionamento `AFTER` e verifica i comportamenti,
non quell'ordine fisico. Migrazione a blocchi di 100 ordinati per primary key,
lookup/update degli attori per primary key; EXPLAIN MySQL verificato senza
nuovi indici o filesort.

## 5. Elaborazione, consistenza e cancellazioni

Le immagini seguono validazione, ricodifica con GD quando disponibile,
rimozione metadati e generazione delle varianti previste dai flussi esistenti.
Avatar e copertine conservano i rispettivi limiti di ridimensionamento.
Audio, video preparati e poster vengono copiati da stream locali: nessuna
copia pubblica permanente è richiesta per i file registrati sul bucket.

Database e storage non partecipano alla stessa transazione. `MediaStorage`
condivide la scrittura compensata con `MediaUploader` e `AssetStorage`:

- controlla le scritture e rimuove i nuovi oggetti se la scrittura o la
  persistenza falliscono, con errori di validazione privi di dettagli SDK;
- registra la pulizia sui livelli di transazione già aperti, perché i callback
  del solo savepoint non sopravvivono al suo commit in Laravel;
- evita ripetizioni della pulizia dopo un successo e permette di ritentare
  quando una pulizia precedente è fallita;
- conserva i vecchi asset fino al commit del nuovo riferimento. Fallire sulla
  seconda immagine o su una variante delle icone non elimina quelle precedenti.

La consegna federata di un evento avviene dopo la transazione di salvataggio;
un suo fallimento non deve cancellare media già pubblicati. Il compositore
riusa la compensazione comune, senza una seconda lista di upload da eliminare.

Sostituzioni e rimozioni seguono il disco registrato, comprese varianti e
passaggi locale↔S3. Per gli allegati di eventi/commenti evento, errori di
cancellazione mantengono il record orfano con i percorsi per una pulizia
successiva; non viene dichiarato un successo di cancellazione dei byte.
Le semantiche preesistenti dei soft-delete di post/commenti e della rimozione
account non vengono ampliate con operazioni nuove. Gli errori di pulizia
sono registrati con disco e chiavi, senza credenziali né messaggi grezzi SDK.
Non c'è un sistema automatico aggiuntivo di retry/riparazione; per asset
sostituiti un fallimento di cancellazione può lasciare file non più referenziati.

Staging `post-publication/` e preparazione video restano locali e temporanei.
Dopo pubblicazione riuscita la directory viene rimossa; in caso di errore si
rimuovono i derivati temporanei e si conservano gli originali per il retry.
La perdita del disco prima della pubblicazione può quindi perdere il lavoro
in attesa. Cache, log, lock, cataloghi rigenerabili e pacchetti di aggiornamento
non fanno parte dei media permanenti spostati sul bucket.

## 6. Gap noto: Sprint 5 non pianificato per l'implementazione

**Decisione dell'utente: fermare qui il lavoro. Le attività seguenti sono
conservate come gap consapevole, senza impegno o data di implementazione.**
Il supporto consegnato riguarda nuovi file e gestione degli oggetti già
registrati; non fornisce migrazione dell'archivio né una procedura completa di DR.

Attività originariamente ipotizzate:

1. Comando esplicito di trasferimento dell'archivio locale, a blocchi e
   riprendibile: copia e verifica prima di cambiare ogni riferimento; preservare
   le copie sorgenti e rendere le riesecuzioni prive di duplicati.
2. Includere allegati, varianti, profili, comunità e asset dell'istanza;
   segnalare i file mancanti, senza marcarli trasferiti o scaricare e
   riclassificare automaticamente immagini federate esterne.
3. Mantenere accessibili i vecchi URL `/storage/...` già distribuiti quando
   i byte sono soltanto sul bucket. Oggi continuano a richiedere i file locali:
   non eliminare manualmente l'archivio dopo una semplice copia sul bucket.
4. Provare il ripristino su un ambiente separato con database, `APP_KEY` e
   bucket, directory pubblica media vuota, tutte le categorie accessibili,
   vecchi URL funzionanti, nuovi upload, sostituzioni e cancellazioni.
5. Completare le istruzioni operative di trasferimento, cambio provider e
   ripristino sulla base di quella prova; verificare interruzione/ripresa ed
   errori di copia, senza conteggiare come successi i trasferimenti incompleti.

Un cambio di bucket/provider oggi richiede un trasferimento esterno mantenendo
le chiavi, prima di cambiare la configurazione unica. Non esiste risoluzione
per bucket storici. Database e `APP_KEY` vanno preservati insieme; versioning,
backup indipendenti del bucket, dominio pubblico e invalidazione CDN rimangono
responsabilità dell'amministratore/provider. Non sono implementati da OpenBook.

L'unificazione futura di avatar/copertine su `Media` è un'attività distinta,
annotata in [TODO.md](TODO.md), non necessaria alla chiusura di questa funzionalità.

## 7. Verifiche e limiti dell'evidenza

Il collaudo reale su Cloudflare R2 è stato eseguito dall'utente: caricamenti
locali/S3, video elaborato asincronamente, distribuzione diretta e successiva
verifica delle modifiche a profili/comunità e asset. L'utente riferisce esito
positivo. Le prove automatiche non usano credenziali o bucket reali.

Le suite mirate coprono autorizzazioni admin, cifratura e mancata esposizione
del segreto, preset e trasporto AWS simulato senza ACL, errori di connessione,
lettura in streaming, range/HEAD, chiusura degli stream, scritture parziali,
rollback annidati, sostituzioni fra dischi, manifest e rimozioni. Il test HTTP
delle comunità verifica HTML, accesso tramite actor, REST e documento ActivityPub,
compresi vecchi URL reinseriti appositamente sull'actor dopo nuovi upload.

Review complessiva rispetto a `main`: configurazione/credenziali, integrazione
SDK, percorsi di upload e cancellazione, staging, streaming e range, asset,
migrazioni, autorizzazioni e consumatori HTML/REST/ActivityPub. Rimosso il
tracciamento duplicato degli upload in EventComposer: la compensazione comune
copre anche il rollback dell'allegato dell'evento, evitando DELETE ripetuti e
una query aggiuntiva per recuperare la miniatura solo per quella lista.
Due test aggiunti provano pulizia una sola volta in caso di rollback e
conservazione di evento/file dopo un errore della sola consegna federata.
Nessun altro problema bloccante emerso dalla review.

Verifiche finali, PHP 8.5.12:

- Suite mirata immagini/eventi: 79 test, 429 asserzioni, passati.
- Suite completa: **2.330 test, 17.944 asserzioni, nessun errore o fallimento;
  131 saltati** (130 della baseline più il test MySQL opzionale).
- Comando: `APP_CONFIG_CACHE=/tmp/openbook-object-storage-test-config.php php vendor/bin/phpunit`.
- Pint dei PHP interessati, sintassi JavaScript, `git diff --check`,
  `composer validate --no-check-publish` e `composer check-platform-reqs --no-dev` passati.
- Analisi, guide italiana/inglese e changelog consolidati come singola
  funzionalità, senza note incrementali di sprint o promesse di sviluppo.

Comportamento delle migrazioni verificato su SQLite; up/down e ordine delle
colonne verificati su MySQL locale mediante sole tabelle temporanee della
connessione, nella review delle migrazioni rimaste invariate. Nessuna modifica
alle tabelle persistenti durante quelle prove. EXPLAIN: PRIMARY per scansioni
ordinate dei blocchi e lookup attori. MySQL dedicato non disponibile; il suo
test opzionale è saltato. MariaDB non collaudato direttamente. I preset diversi
da R2 non sono stati verificati su servizi reali.

Browser con fixture isolate: desktop/mobile e fallback senza JavaScript del
pannello, player MP4/WAV con avanzamento a quattro secondi, HEAD e richieste
parziali. Queste prove non sostituiscono il collaudo dei provider reali.
Il diff di Composer aggiunge l'adattatore S3 e le sue dipendenze, senza
aggiornare o rimuovere pacchetti preesistenti. Nella verifica iniziale era
presente l'avviso su `symfony/mime` v7.4.19 (PKSA-wtxr-p26d-nn42), invariato
rispetto a main: nessun aggiornamento estraneo o nuovo audit online in questa
revisione; non si dichiara risolto quell'avviso.

## 8. Fonti tecniche

Fonti della ricognizione del 2 novembre 2026, precedenti al collaudo dell'utente:

- [Laravel — File Storage](https://laravel.com/docs/12.x/filesystem).
- [R2 — S3 API compatibility](https://developers.cloudflare.com/r2/api/s3/api/).
- [R2 — Public buckets](https://developers.cloudflare.com/r2/buckets/public-buckets/).
- [R2 — Presigned URLs](https://developers.cloudflare.com/r2/api/s3/presigned-urls/).
- [DigitalOcean — AWS SDKs](https://docs.digitalocean.com/products/spaces/reference/aws-sdks/).
- [OVHcloud — Getting started](https://docs.ovhcloud.com/en/guides/storage-and-backup/object-storage/s3-getting-started-with-object-storage).
