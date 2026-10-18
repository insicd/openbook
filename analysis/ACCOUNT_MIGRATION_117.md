# Migrazione degli account verso e da Openbook — issue #117

**Issue:** [#117](https://github.com/insicd/openbook/issues/117).
**Stato:** arrivo e partenza implementati; verifica tra istanze online da eseguire.
La portabilità dei seguiti costituisce lo stadio successivo.
**Analisi collegata:** [ACCOUNT_MOVE.md](ACCOUNT_MOVE.md), relativa alla #116.

## 1. Obiettivo

Consentire a un utente di trasferire la propria presenza tra Openbook e un altro
server del Fediverso, collegando il vecchio account al nuovo e annunciando il
trasferimento ai follower.

La migrazione riguarda l’identità e i follower. Il vecchio account conserva
contenuti, relazioni e impostazioni; post, allegati e messaggi rimangono sul
server di origine. L’elenco degli account seguiti si trasferisce tramite
esportazione e importazione, descritte separatamente.

Openbook mantiene il comportamento della #116: i follower locali ricevono una
notifica e scelgono se seguire il nuovo account. I server remoti gestiscono il
trasferimento dei propri follower secondo il loro supporto a Move.

## 2. Arrivo su Openbook

Nelle impostazioni, la scheda **Trasferimento account** riunisce arrivo e
partenza. La sezione **Arrivo da un’altra istanza** permette di
aggiungere il vecchio account tramite indirizzo completo (`nome@server`)
o URL Actor ActivityPub.

Openbook risolve l’indirizzo e salva l’URI canonico come alias del nuovo account.
Gli alias sono pubblicati nel campo ActivityPub `alsoKnownAs`. L’utente può
visualizzarli e rimuoverli; il limite attuale è di dieci alias.

Dopo aver aggiunto l’alias, l’utente avvia il trasferimento dal vecchio account
sul server di origine. Aggiungere un alias prepara la destinazione; è il Move
firmato dal vecchio account ad annunciare il trasferimento.

I server dei follower inviano normali richieste Follow al nuovo account Openbook.
Un account aperto le accetta; un account protetto richiede l’approvazione del
proprietario.

### Esempio: Anna arriva da Mastodon

Anna crea un account sulla nostra istanza Openbook e aggiunge il suo vecchio
account Mastodon come alias. Poi avvia il trasferimento da Mastodon.

Luca usa la stessa istanza Openbook e segue il vecchio account di Anna.
Quando Openbook riceve il Move, verifica l’alias sul nuovo account e notifica
Luca con un collegamento al nuovo profilo. Luca sceglie se seguirlo; il follow
al vecchio account rimane invariato.

Per verificare la destinazione Openbook legge l’account locale dal database.
Per una destinazione su un altro server recupera il profilo remoto aggiornato.

## 3. Partenza da Openbook

Nelle impostazioni, una sezione **Trasferisci il tuo account** permette di
indicare il nuovo account di destinazione.

Il flusso è il seguente:

1. L’utente prepara il nuovo account sul server di destinazione, aggiungendo
   il vecchio account Openbook tra gli alias.
2. Su Openbook inserisce l’indirizzo del nuovo account.
3. Openbook recupera il profilo aggiornato e verifica che `alsoKnownAs`
   contenga l’URI del vecchio account.
4. La pagina mostra la destinazione e spiega che verrà annunciato il
   trasferimento dei follower, mentre i contenuti resteranno su Openbook.
5. L’utente conferma con la password del proprio account Openbook.
6. Openbook registra il trasferimento, pubblica `movedTo` sul vecchio Actor,
   mostra il collegamento nel profilo e invia il Move ai follower remoti.

La verifica della destinazione deve riuscire prima della conferma definitiva.
Autorizzazione e controlli di moderazione seguono le regole esistenti.

Il Move è firmato dal vecchio account: `actor` e `object` identificano la
sorgente, `target` identifica il nuovo account. L’attività mantiene lo stesso
identificatore durante i normali tentativi di consegna.

La distribuzione usa `ActivityDelivery::deliverToFollowers()`, come per post
e aggiornamenti del profilo. Firma, selezione delle inbox, accodamento e retry
restano affidati ai componenti e al cron esistenti.

I follower locali della sorgente ricevono la notifica prevista dalla #116
e scelgono autonomamente se seguire la destinazione.

## 4. Utilizzo del vecchio account

Il vecchio account resta pienamente utilizzabile dopo il trasferimento.
L’utente può pubblicare, interagire, ricevere nuovi follow e gestire normalmente
contenuti e impostazioni. Il profilo mostra l’indicazione di trasferimento e
il collegamento al nuovo account.

Questo permette anche di pubblicare promemoria per chi segue ancora il vecchio
account. I follower già trasferiti da altri server ricevono i contenuti secondo
le relazioni di follow gestite da quei server.

### Rimuovi l’indicazione di trasferimento

Il proprietario può premere **Rimuovi l’indicazione di trasferimento**.
Openbook rimuove l’avviso e `movedTo`, quindi comunica il cambiamento con un
normale Update del profilo. I follow già trasferiti restano come sono.

L’account torna allo stato ordinario e può avviare un altro trasferimento,
seguendo la stessa procedura di verifica e conferma. Ogni nuovo trasferimento
ha un proprio identificatore Move.

## 5. Esportazione e importazione dei seguiti

Una sezione **Importa/esporta seguiti** permette di ricostruire l’elenco degli
account seguiti sul nuovo account. È utilizzabile anche indipendentemente
dalla migrazione.

L’esportazione produce un CSV degli account seguiti, con indirizzi completi e
formato compatibile con Mastodon (`Account address`).

L’importazione mostra un’anteprima e richiede conferma. Aggiunge gli account
indicati conservando quelli già seguiti. Le richieste passano attraverso
`FollowManager`, rispettando le normali approvazioni locali e remote.
Al termine l’utente vede un riepilogo degli account già seguiti, delle richieste
inviate e degli indirizzi che non è stato possibile importare.

File e risultati dell’importazione sono accessibili al proprietario dell’account.
Questa funzionalità costituisce uno stadio separato dalla partenza.

## 6. Integrazione con il codice esistente

| Componente | Responsabilità |
| --- | --- |
| `AccountAliasManager` e impostazioni | Gestione degli alias locali, già implementata. |
| `ActorSerializer` | Pubblica `alsoKnownAs` e `movedTo` nel profilo federato. |
| `RemoteActorResolver` e `AccountMoveTargetResolver` | Risoluzione degli account e verifica degli alias su destinazioni locali e remote. |
| `RemoteAccountMoveHandler` e `AccountMoveNotifier` | Gestione degli annunci e notifiche della #116, condivise con le partenze locali. |
| `FollowManager` e inbox | Normali richieste Follow e approvazioni dei follower in arrivo. |
| `AccountMigrationService` | Verifica, conferma, registrazione del trasferimento e rimozione dell’indicazione. |
| `ActivitySerializer` | Rappresentazione del Move in uscita. |
| `ActivityDelivery` e `DeliverActivityJob` | Consegna del Move attraverso il flusso federato ordinario. |
| Profilo locale | Avviso di trasferimento e collegamento al nuovo account. |

Gli alias sono conservati in `actors.also_known_as`; la destinazione del
trasferimento in `actors.moved_to_actor_id`. Profilo e documento Actor leggono
la destinazione dalla relazione `movedTo`. Rimuovere l’indicazione svuota
`moved_to_actor_id`. L’ID del Move viene generato quando si costruisce l’attività;
i normali job di consegna conservano il payload per i tentativi successivi.

I controller validano e autorizzano le richieste, delegando il comportamento
ai servizi applicativi. Risoluzione e consegna remota usano le protezioni HTTP
e le regole di moderazione esistenti.

## 7. Stadi di implementazione e verifiche

1. **Arrivo, completato:** alias locali, pubblicazione di `alsoKnownAs`,
   ricezione dei follower e Move verso account locali.
2. **Partenza, completata:** verifica e conferma della destinazione, Move in uscita,
   indicazione sul profilo e sua rimozione, notifiche ai follower locali.
3. **Portabilità dei seguiti:** esportazione e importazione CSV.

I test devono coprire i flussi di arrivo e partenza, le verifiche di proprietà
e destinazione, l’approvazione dei follower e il comportamento della #116.
Verificare inoltre che il vecchio account resti utilizzabile e che, dopo la
rimozione dell’indicazione, possa effettuare un nuovo trasferimento.

Per ogni stadio aggiornare documentazione e traduzioni, eseguire i test pertinenti
e la suite completa. Nuovi schemi e query seguono le verifiche SQLite e
MySQL/MariaDB previste dal progetto. Completare la verifica della federazione
con prove tra istanze Openbook e un server Mastodon quando è disponibile
un’istanza online raggiungibile dagli altri server. Questa verifica resta
da eseguire; i test automatici non attestano l’interoperabilità tra server reali.
