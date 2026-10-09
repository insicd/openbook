# TODO

## Ordinamento degli aggiornamenti dei commenti federati

- `RemoteNoteUpserter::upsertComment()` non conserva un timestamp della versione
  remota e non scarta aggiornamenti obsoleti, a differenza dell'upsert dei post.
  Valutare una protezione condivisa fra inbox e recupero delle risposte, per
  evitare che una versione precedente sovrascriva testo e metadati più recenti.
  Definire anche il comportamento con timestamp assenti o uguali e aggiungere
  test di arrivo fuori ordine. Intervento separato dal salvataggio della lingua.

## Notifiche e messaggi non letti nelle chat

1. **Evitare una notifica generale per ogni messaggio ricevuto.** Se arrivano
   molti messaggi nella stessa conversazione, l'elenco delle notifiche non deve
   accumulare una voce separata per ciascuno. Il destinatario deve comunque
   vedere che ci sono messaggi non letti e poter aprire la chat corretta.
2. **Segnalare i nuovi messaggi delle altre conversazioni.** Se l'utente ha
   aperta una chat e riceve un messaggio in un'altra, il contatore "Messaggi"
   deve aggiornarsi e la lista delle chat deve rendere riconoscibile la
   conversazione con messaggi non letti. La lettura della chat aperta non deve
   segnare come letti i messaggi arrivati nelle altre conversazioni; tornando
   alla lista, queste devono ancora risultare da leggere.

## Worker video

- Fare in modo che `openbook:process-videos` osservi il segnale Laravel di
  `queue:restart` e termini ordinatamente dopo il lavoro corrente, lasciando
  che systemd/Supervisor lo riavvii con il codice aggiornato dopo un deploy.

## Scroll infinito: pagine complete scaricate per estrarre il solo elenco

**Stato:** lavoro sospeso dopo gli interventi su Home, Mondo, `/mondo/scopri`
ed elenchi `/eventi` e `/eventi/passati` nel ramo `lazy_home`. Il contesto e le
decisioni sono in [`LAZY_HOME.md`](LAZY_HOME.md). Non avviare altri interventi
senza una nuova verifica dei tempi sulle pagine interessate.

Elenchi ancora da esaminare, uno alla volta:

1. **Profili locali e remoti:** tab Post, Foto, Attività ed Eventi. Le pagine
   successive ricostruiscono il profilo completo. Nei profili remoti il
   percorso ripete anche i controlli di aggiornamento dei contenuti e delle
   collection federate: valutare questo caso per primo, misurando quanto
   lavoro viene davvero svolto a ogni richiesta.
2. **Pagine hashtag:** lo scroll dei post ricostruisce la pagina e ricarica
   anche l'anteprima degli eventi associati all'hashtag.
3. **Community:** lo scroll del muro ricostruisce la pagina, inclusi stato di
   iscrizione, permessi e dati di gestione della community.
4. **Follower, seguiti e membri delle community:** le pagine successive
   renderizzano l'intera vista dell'elenco. Per gli Actor remoti verificare
   anche i controlli sulle collection e l'anteprima dei membri remoti.
5. **Notifiche:** lo scroll renderizza l'intera pagina e richiama la logica
   che segna le notifiche come lette.

Per ciascun caso, confrontare il tempo della risposta e le query prima e dopo
un eventuale intervento. Se utile, restituire alle richieste dello scroll
solo il frammento necessario, mantenendo la pagina completa per la navigazione
diretta, le regole di visibilità e la paginazione esistente. Non cambiare le
query o gli algoritmi dei dati solo per eliminare il rendering superfluo.
