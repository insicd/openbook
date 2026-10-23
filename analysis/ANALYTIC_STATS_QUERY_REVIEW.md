# Stats — review delle query dopo le misure in produzione

23 ottobre 2026. Esaminati tutti gli 11 dataset dei 13 grafici: query SQL,
campi restituiti, utilizzo nei riepiloghi/tooltip, cache e condivisione fra card.
Eseguito EXPLAIN ANALYZE su MySQL locale con i filtri CW/hashtag presenti nei
piani forniti da Punk.Kitchen. Il campione locale contiene circa 49 mila post
pubblici nel periodo esaminato: i tempi locali non stimano quelli di produzione.

## Problemi confermati e risolti

- Geografia: eliminati i conteggi del corpus usati soltanto per la percentuale.
  Misura produzione comunicata dall'amministratore: da circa 40,8 s a 324 ms.
- Sondaggi: conteggio assoluto settimanale, INNER JOIN ai soli sondaggi.
  Misura produzione comunicata dall'amministratore: da circa 42,5 s a 34 ms.

## Esito per dataset

| Dataset / grafici | Esito della review | Priorità |
| --- | --- | --- |
| `media` / allegati e andamento | Il piano originario filtra circa 49 mila post prima di cercare gli allegati. Applicato JOIN_PREFIX(att) su MySQL dopo verifica: 1,03 s → 272 ms nella misura comunicata dall'amministratore. I tre conteggi DISTINCT servono a riepiloghi/tooltip attuali. | Corretto, piano verificato |
| `weekly` / pubblicazioni e persone attive | Legge il corpus perché entrambi i grafici lo richiedono. Il conteggio degli autori distinti aggiunge lavoro; il dataset è condiviso, non viene eseguita una query per card. Applicato l’indice temporale su MySQL dopo la misura comunicata dall’amministratore: 700 → 356 ms. | Corretto, misurato in produzione |
| `languages` / lingue | Legge molti post per trovare quelli con una lingua valorizzata. La normalizzazione LOWER/TRIM non consente di eliminare tutti gli accessi con un semplice filtro su indice. Nessun denominatore aggiuntivo. Misura produzione: 292 ms; lasciata invariata. | Verificato |
| `communityActivity` / community | Tre rami UNION ALL e due COUNT DISTINCT, necessari alla deduplicazione delle community e dei post. Nel piano locale parte da community/Group/Announce pertinenti, non da tutto il corpus per un denominatore. Misura produzione: 139 ms; lasciata invariata. | Verificato |
| `hashtags` / argomenti | Aggregazione per mese/tag e più ordinamenti per i ranking. Costo strutturale già noto; nessuna modifica in questa revisione. | Alta, già nota |
| `rhythm` / ultimi 15 giorni | Range scan sull'indice visibilità/stato/data, limitato a 15 giorni. Nessun conteggio dell'intero biennio. | Bassa |
| `domains` / istanze | Legge soltanto gli attori. Totali e percentuali si ottengono dagli stessi aggregati per dominio, senza una seconda scansione dei post. | Bassa |
| `eventCalendar` / calendario | Legge eventi e autori, senza accessi ai post. | Bassa |
| `acquisition` / sorgenti | Legge bucket giornalieri e sorgenti, senza join a post, eventi o attori. Il lookup dell'inizio raccolta usa la chiave della metrica. | Bassa |
| `geography` / paesi | Dopo la correzione restano soltanto i due rami con location. | Risolto, misurato in produzione |
| `polls` / sondaggi | Dopo la correzione parte dai sondaggi nel piano locale. | Risolto, misurato in produzione |

Tutti gli aggregati rimasti sono utilizzati dalla UI. Non sono emersi altri
denominatori estranei al dato mostrato, come quelli eliminati dalla geografia
e dai sondaggi. Anche le due card degli allegati condividono query e cache.

## Varianti diagnostiche locali

Tre esecuzioni per variante, alternando l'ordine per ridurre l'effetto del
riscaldamento della cache MySQL. I valori seguenti sono mediane del tempo
EXPLAIN ANALYZE; non costituiscono una previsione per Punk.Kitchen.

| Dataset | Query originale | Variante diagnostica | Mediana variante |
| --- | ---: | --- | ---: |
| Allegati | 850 ms | `JOIN_PREFIX(att)`: inizio dagli allegati | 309 ms |
| Pubblicazioni/autori | 900 ms | `FORCE INDEX(posts_visibility_status_published_at_index)` | 265 ms |
| Lingue | 207 ms | Stesso indice temporale | 161 ms |

Per allegati e pubblicazioni/autori è stata confrontata anche l'intera risposta
SQL: risultati uguali fra query attuale e variante. Applicati al codice
`JOIN_PREFIX(att)` per gli allegati e l’indice temporale per pubblicazioni/autori,
su connessioni MySQL che non siano MariaDB. SQLite e MariaDB mantengono le query
senza hint. La variante dell’indice per le lingue resta solo diagnostica.

L'amministratore ha misurato la query allegati senza hint in 1,03 s, quella
con hint in 272 ms. Il piano successivo riportato impiega circa 331 ms e
conferma il covering index scan su `post_attachments_post_id_media_id_unique`,
seguito dai lookup sulle PK di post, attori e media. I valori riportati
dall'amministratore e quelli dell'EXPLAIN sono esecuzioni diverse.

Verificata anche la semplice riscrittura `FROM post_attachments att JOIN posts p
JOIN actors a`, senza hint: risultati identici, ma MySQL locale continua a
partire da `posts_popular_remote_actor_index`. Tre prove alternate hanno dato
mediane di 743 ms per l'originale, 726 ms per il solo riordino testuale e 275 ms
con `JOIN_PREFIX(att)`. L'ordine testuale degli INNER JOIN non vincola il piano;
questa sola riscrittura non sostituisce l'hint nel caso verificato. Anche la
prova dell’amministratore della versione senza hint ha richiesto circa 900 ms.

Nel piano di produzione originario, il percorso attori → post effettuava circa
76 mila ricerche per autore e dominava il tempo totale. L'indice esistente su
attore/data non è necessariamente il percorso più conveniente per statistiche
globali su due anni. Non occorre aggiungere un indice prima di verificare quelli
già disponibili e realmente scelti.

## Concorrenza durante il ricalcolo

Ulteriore rischio nel wrapper cache: il lock dura 120 secondi, ma le richieste
concorrenti attendono 3 secondi e, in caso di timeout, eseguono la query senza
cache. Un ricalcolo lungo può quindi essere ripetuto contemporaneamente.
Il frontend ha inoltre un timeout di 20 secondi, che non garantisce la
cancellazione della query sul server. Quest'aspetto richiede una decisione
separata su attesa/risposta temporanea/dati precedenti; nessun comportamento
della cache è stato cambiato durante questa review.

## Esito della verifica in produzione

Misurati `media`, `weekly`, `languages` e `communityActivity`: applicate solo
le due varianti che hanno mostrato un beneficio concreto, mantenendo invariati
intervallo e filtri. Lingue e community restano invariate. Le soglie e i filtri di visibilità/moderazione non sono stati
modificati. Nessun nuovo indice o aggregato precalcolato è stato introdotto.

## Verifica finale prima del commit

- Review completa di SQL, dataset, frontend, traduzioni e documentazione.
  Corretto anche il messaggio vuoto dei sondaggi, che prima si riferiva ai post.
- PHPUnit mirato Stats/cache: 30 test, 573 asserzioni.
- PHPUnit completo: 2006 test, 14267 asserzioni, nessun errore o fallimento;
  111 integrazioni opzionali saltate come nelle esecuzioni precedenti.
- MySQL locale: EXPLAIN ANALYZE delle quattro query definitive; range scan
  sull'indice temporale per `weekly`, partenza dagli allegati per `media`,
  soli due rami con location per `geography`, soli sondaggi per `polls`.
  Confrontate integralmente le risposte di `weekly` e `media` con e senza hint:
  risultati uguali.
- Grafici JavaScript: controllo sintattico e verifica di conteggi, zeri e
  compatibilità con i payload precedenti ancora presenti in cache.
- Pint e controllo whitespace superati. Nessuna modifica a TTL o invalidazione.

Le prove reali dei piani sono su MySQL, non su un server MariaDB: per MariaDB
non vengono applicati i due hint; SQLite resta coperto dalla suite completa.
