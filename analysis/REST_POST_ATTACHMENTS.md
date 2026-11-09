# Allegati dei post REST e pubblicazione differita

Stato: proposta, 9 novembre 2026. Nessuna implementazione avviata.

## Obiettivo e perimetro

Inviare il post e tutti gli allegati in una sola richiesta, riutilizzando il
flusso web. Conservare la creazione testuale JSON e aggiungere il multipart.
Il client riceve il post pubblicato oppure il riferimento a una pubblicazione
in elaborazione. Può consultarne lo stato e annullarla quando il core lo consente.

Le definizioni di `NewPostAttachment` e `PostPublication` e l'inventario degli
endpoint proposti sono nell'[analisi REST principale](REST_SERVICE.md#estensione-proposta-allegati-e-postpublication).
Questo documento descrive il flusso e il piano, senza duplicare il catalogo delle
entità. Non introduce upload indipendenti, nuove code o infrastrutture obbligatorie.

## Base esistente verificata

- Il web riceve testo e allegati insieme. Senza video usa `PostComposer`; con
  qualsiasi video usa `PostPublicationStager` per l'intero post, anche misto.
- `MediaUploader` tratta immagini e audio nel salvataggio ordinario. Genera
  miniature delle immagini; non ridimensiona sistematicamente il file principale.
  I video vengono preparati nel flusso differito esistente.
- `PendingPostPublication` ha già gli stati `pending`, `processing`, `failed`,
  `published` e il riferimento al post finale. Conserva ordine e testi alternativi.
- La validazione web limita già `alt_texts.*` a 1.000 caratteri:
  `app/Http/Requests/Posts/StorePostRequest.php`, metodo `rules()`.
  Riprendere questo limite evita divergenze tra REST e web: non è un nuovo limite
  ricavato dallo schema di risposta o dal database.
- Il processo backend `openbook:process-videos` esegue il lavoro. Se fallisce,
  `PostPublicationQueue::fail()` lo riporta a `pending` finché restano tentativi;
  altrimenti lo marca `failed`. Sono tentativi del backend, non nuove richieste
  HTTP del client. La REST espone solo lo stato risultante.
- Al successo `PendingPostFinalizer` conserva il record con stato `published` e
  `post_id`, eliminando soltanto la cartella dei file temporanei.
  `DatabaseMaintenanceService` elimina successivamente i record `published` e
  `failed` secondo la retention configurata: default sette giorni dall'ultimo
  aggiornamento. Il record resta quindi consultabile dopo il successo, ma non
  per sempre. La sua pulizia non elimina il post finale.
- Il web consente già l'annullamento delle proprie pubblicazioni `pending` o
  `failed`. Lo impedisce durante `processing` e non annulla quelle `published`.
  Il controllo avviene sotto lock in `PostController::destroyPending()`.

## Contratto di creazione

### POST /rest/v1/posts

Autenticazione Bearer, scope `api`, account e attore attivi, email verificata:
restano i requisiti attuali. Le visibilità restano `public`, `unlisted`, `followers`.

**`application/json`:** contratto attuale invariato, solo testo. Campi sconosciuti
rifiutati, compreso `postAttachments`.

**`multipart/form-data`:** una parte chiamata `post` contiene il JSON di `NewPost`;
le altre contengono i file. La parte `post` usa `Content-Type: application/json`
senza `filename`, così PHP la riceve come valore del form. L'ordine fisico delle
parti non conta; l'array `postAttachments` determina l'ordine di presentazione.

Esempio della parte `post`:

```json
{
  "postContent": "A walk by the lake.",
  "postVisibility": "public",
  "postAttachments": [
    {
      "filePart": "attachment1",
      "mediaAltText": "The lake surrounded by mountains."
    },
    {
      "filePart": "attachment2",
      "mediaAltText": "Small waves reaching the shore."
    }
  ]
}
```

Le parti file si chiamano `attachment1` e `attachment2`. Il nome del file non
serve per associarlo alla descrizione. `filePart` non è un URL o un media ID.
Ogni riferimento deve corrispondere a un file distinto della richiesta; rifiutare
riferimenti ripetuti, file mancanti e file non referenziati. Usare nomi semplici,
senza array annidati: lettere ASCII e cifre, con una lettera iniziale, massimo
64 caratteri; `post` è riservato. Il contratto si applica ai campi ricevuti dal
parser PHP: non si aggiunge un parser per recuperare header delle singole parti
o nomi duplicati che PHP ha già normalizzato. Descrizione omessa o null significa nessun testo alternativo.

Immagini, audio e video hanno lo stesso contratto. Tipo effettivo, quantità,
dimensioni, abilitazione video e relativi vincoli si verificano con le regole
esistenti del core. Il client non sceglie il percorso di elaborazione.

`postAttachments` è facoltativo; assente o `[]` significa nessun allegato.
`postContent` e `postVisibility` restano obbligatori e non nullable. Nel multipart
si consente `postContent: ""` con almeno un allegato valido; senza allegati serve
testo significativo. Il JSON conserva la validazione attuale. Verificare che il
percorso condiviso conservi la stringa vuota, senza modificare il form web.

### Risposte

| Esito | Codice | Body | Header `Location` |
| --- | --- | --- | --- |
| Post pubblicato localmente | `201 Created` | `Post` completo | URL REST del post |
| Pubblicazione salvata per elaborazione differita | `202 Accepted` | `PostPublication` minima | URL REST della pubblicazione |

`202` viene restituito dopo aver salvato richiesta completa e file. Attualmente
qualsiasi video produce una pubblicazione differita; immagini e audio seguono
il percorso immediato. Il client distingue i due casi dal codice di risposta.
La pubblicazione locale non garantisce che la consegna federata sia già terminata.

Usare le risposte di errore REST esistenti: `400` per JSON malformato, `415` per
formato non supportato, `422` per input o allegati non validi, oltre ai normali
errori di autenticazione, autorizzazione e frequenza. Una richiesta non valida
non deve creare un post parziale. Verificare la pulizia dei file in caso di errore.

Nota operativa: il server web può rifiutare un file troppo grande prima di
passare la richiesta a PHP, oppure PHP può scartare dati oltre i propri limiti.
L'applicazione deve gestire gli errori di upload che riceve; una risposta generata
direttamente dal server web può avere un formato diverso dagli errori JSON REST.
Non richiede un nuovo meccanismo applicativo.

Ripetere il POST può creare un duplicato, come oggi. Non introdurre chiavi di
idempotenza o nuovi invii automatici del client in questo lavoro.

## Consultazione e annullamento

### GET /rest/v1/postPublication/{postPublicationId}

Restituisce `200` e la `PostPublication` minima definita nell'analisi REST:

```json
{
  "postPublicationId": "5146b1f5-0e95-4be9-a194-25cb5d1d8081",
  "postPublicationStatus": "pending",
  "postId": null
}
```

Il client interroga lo stesso URL finché ottiene `published` con `postId`, oppure
`failed`. Con `published` recupera il post tramite `GET /rest/v1/posts/{postId}`.
Non servono percentuali, date di aggiornamento, testo, visibilità o dettagli dei
tentativi interni. `pending` significa in attesa, `processing` in elaborazione.

Accesso riservato all'autore con i controlli REST esistenti; nessuna delega allo
staff. Rispondere `404` per risorse assenti o di altri autori. Il record resta
leggibile dopo `published` fino alla pulizia esistente; successivamente dà `404`.
Se il post è stato eliminato, non restituire riferimenti a contenuti non più
disponibili: lo stato storico resta `published` e `postId` è null.
La lettura non richiede email verificata e resta disponibile se i video vengono
disabilitati. Usare `Cache-Control: no-store`.

### DELETE /rest/v1/postPublication/{postPublicationId}

Riprendere l'annullamento web, riservato all'autore e verificato sotto lock:

| Stato o condizione | Risposta |
| --- | --- |
| `pending` o `failed` | Annullamento e pulizia dei file temporanei, `204 No Content` |
| `processing` | `409 Conflict`, nessuna modifica |
| `published`, assente o appartenente ad altri | `404 Not Found`, nessuna modifica |

Non elimina un post già pubblicato: per quello esiste `DELETE /rest/v1/posts/{postId}`.
Dopo l'annullamento il monitoraggio restituisce `404`. Non richiedere email
verificata per annullare. Condividere il comportamento con il web, senza duplicare
le regole di concorrenza o lasciare file temporanei fuori dalla pulizia esistente.

Si mantiene il prefisso corrente `/rest/v1`: il riferimento a `/api/v1` nella
discussione non viene interpretato come richiesta di migrare tutta l'API.
I nuovi endpoint usano il segmento concordato `postPublication`, senza trattini.
Non aggiungere per ora liste filtrate, paginazione o endpoint di nuovo tentativo.

## Piano di implementazione

1. **Validazione e invio multipart.** Verificare con una richiesta HTTP reale come
   PHP/Laravel riceve parte JSON e file. Usare il parser del framework, senza
   introdurne uno personalizzato. Aggiungere validazione REST e mappatura verso
   gli input esistenti di file/testi alternativi. Condividere la decisione
   immediata/differita col web, mantenendo i controller sottili.
2. **Risposte e monitoraggio.** Aggiungere la rappresentazione minima descritta
   nell'analisi REST, risposte `201`/`202`, header `Location` e GET per autore.
   Riutilizzare stati, finalizzatore e retention esistenti, senza nuove tabelle.
3. **Annullamento.** Esporre DELETE condividendo con il web controllo di proprietà,
   lock, divieto durante l'elaborazione e pulizia dei temporanei.
4. **Verifiche e documentazione.** Test mirati per compatibilità JSON, multipart,
   ordine, alt text, limiti, soli media, allegati misti, `201`/`202`, autorizzazioni,
   monitoraggio, successo, fallimento e annullamento concorrente all'elaborazione.
   Verificare che i file restino privati fino alla pubblicazione e che non partano
   notifiche/federazione prima del completamento. Verificare cleanup sugli errori.
   Eseguire suite completa prima della PR, segnalando controlli non disponibili.
   Aggiornare OpenAPI e guide inglese/italiana solo con il contratto pubblico;
   aggiornare il changelog secondo `.cursor/rules/changelog.mdc` a implementazione
   avvenuta. Revisionare il diff completo prima di commit o PR.

La review iniziale ha corretto la descrizione della visibilità obbligatoria di
`NewPost` nell’analisi principale e precisato i nomi delle parti e il confine del
parser PHP. L’aggiornamento OpenAPI fa parte obbligatoria dell’implementazione.

Non sono necessarie decisioni aggiuntive dell'utente per procedere con questa
proposta. Il comportamento del parser e la pulizia sui fallimenti sono verifiche
implementative, non nuove funzionalità da progettare.
