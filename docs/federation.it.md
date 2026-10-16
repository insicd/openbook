> Documentazione: [Italiano](README.it.md) · [English](federation.md)

# Federazione

La federazione permette a un account Openbook di seguire e interagire con
account di altri server compatibili con ActivityPub. Ogni server conserva le
proprie regole, tempi di elaborazione e funzioni disponibili.

## Trovare e seguire un account remoto

Cerca l’indirizzo completo, per esempio `nome@dominio.example`. Dal profilo
remoto puoi seguire l’account e leggere i contenuti disponibili. Un account
protetto può richiedere l’approvazione del follow; fino alla risposta la
richiesta resta in attesa.

Openbook recupera i profili e una parte dei contenuti remoti quando servono,
e ne conserva una copia locale. Il recupero può richiedere tempo o fallire se
il server di origine è temporaneamente irraggiungibile. Il cron gestisce gli
aggiornamenti e i tentativi di consegna pendenti.

Una conferma nell’interfaccia indica che l’azione è stata registrata localmente
e il lavoro federato è stato accodato. La ricezione da parte dell’altro server
può avvenire più tardi. Seguire, mettere Mi piace, commentare, condividere e
votare mantengono questo comportamento.

## Contenuti e aggiornamenti

L’istanza riceve contenuti pertinenti: account seguiti, risposte a contenuti
conosciuti, menzioni locali e attività delle community. La visita di un profilo
o di un post può recuperare ulteriori contenuti. **Mondo** mostra i post
pubblici remoti già conosciuti, non tutti quelli esistenti nel Fediverso.

Modifiche ed eliminazioni vengono comunicate fra i server. I tempi di
aggiornamento dipendono dal cron e dalla disponibilità delle istanze coinvolte.
Un server remoto può applicare regole differenti sulla conservazione delle copie.
Gli avvisi sui contenuti e i permessi di lettura valgono anche per i post importati.

Oltre ai normali post sono riconosciuti articoli, immagini, video, sondaggi ed
eventi compatibili. Il testo remoto viene reso in forma sicura, conservando link
e allegati supportati. La lingua dichiarata dall’autore compare vicino alla
data quando è disponibile; Openbook non deduce la lingua analizzando il testo.

Le immagini remote sono caricate dal server di origine. Se il file viene
rimosso o l’origine ne impedisce l’accesso, l’immagine può diventare indisponibile.
I contenuti di alcuni servizi dipendono dalle loro modalità di federazione:
per esempio Threads rende disponibili i post ricevuti dopo il follow quando
l’account ha abilitato la condivisione sul Fediverso.

## Profili remoti

I campi del profilo, la biografia e le immagini sono aggiornati dalle informazioni
pubblicate dall’origine. I link e i testi restano soggetti alle protezioni di
visualizzazione di Openbook. La scheda **Post fissati** compare quando esistono
contenuti fissati visibili; i fissati restano distinti dalla timeline ordinaria.
Errori di recupero conservano l’ultimo elenco valido.

Un account sospeso dal proprio server mantiene i contenuti già importati,
ma le nuove interazioni vengono fermate. Una successiva riattivazione può
ripristinarle dopo l’aggiornamento del profilo. La moderazione locale rimane
separata dalle decisioni del server di origine.

Quando un account remoto comunica uno spostamento verificabile, Openbook
notifica i follower locali. La notifica apre la destinazione, dove ogni utente
può scegliere Segui; i follow esistenti restano invariati. Il vecchio
profilo indica la destinazione; i contenuti precedenti restano associati al
loro autore originale. I profili dichiarati commemorativi dall’origine vengono
segnalati come tali. La gestione locale di profili commemorativi e post fissati
resta fuori dalle funzioni attuali.

## Community

Le community locali si trovano nella sezione **Community**. Puoi crearle,
iscriverti e pubblicare secondo i permessi assegnati. Quelle private richiedono
approvazione e limitano l’accesso ai membri autorizzati. La gestione può essere
condivisa con moderatori della community.

Per una community remota cerca `nome@dominio`, iscriviti e apri il suo profilo.
Il composer è disponibile ai membri secondo il supporto del server remoto.
La campanella permette di ricevere notifiche in-app per i post ritrasmessi
dalla community. Il comportamento delle notifiche sul client di un altro
servizio dipende anche dalle sue preferenze.

L’elenco distingue **Le tue community**, **Community locali** e **Community
remote**. Puoi annullare una richiesta di iscrizione ancora in attesa.
I sondaggi locali sono supportati nelle community locali; la loro pubblicazione
verso community remote è intenzionalmente esclusa.

## Sondaggi locali

Per creare e votare segui la [guida ai sondaggi](architecture.it.md#sondaggi).
Il formato federato segue quello dei sondaggi ActivityPub utilizzato da Mastodon;
il supporto effettivo delle altre piattaforme può variare.

Openbook calcola i risultati dei propri sondaggi. Le risposte singole sono
definitive; per la scelta multipla un client remoto può inviare ogni opzione
separatamente. Ogni coppia partecipante/opzione viene contata una sola volta e
una persona conta come un partecipante, anche quando sceglie più risposte.

I voti remoti incorporati ricevuti entro la scadenza possono essere elaborati
in seguito. Per le risposte fornite soltanto tramite un indirizzo remoto,
anche il recupero deve avvenire entro la scadenza. Per questo i conteggi di un
sondaggio già chiuso possono ancora aggiornarsi quando viene elaborata l’inbox.
I permessi e i blocchi correnti continuano a essere verificati.

Il cron pubblica il sondaggio e distribuisce correzioni, risultati e chiusura
ai destinatari autorizzati. I votanti remoti possono ricevere gli aggiornamenti
anche senza seguire l’autore. I sondaggi riservati restano nel proprio pubblico;
i relay ricevono soltanto contenuti pubblici ammessi alla distribuzione.
Le Note di voto non compaiono come commenti o messaggi.

Il dettaglio delle identità per opzione è riservato all’autore. I risultati
condivisi contengono soltanto gli aggregati. Le risposte degli account cancellati
restano nei conteggi con l’identità scollegata.

## Sondaggi remoti

I sondaggi ricevuti da altri server compaiono nelle card e nel dettaglio dei post.
Puoi votare quando il sondaggio è aperto e hai i permessi necessari. La scelta
viene confermata dopo il salvataggio locale e l’accodamento della consegna
all’autore remoto; l’interfaccia conserva la tua risposta e la data del voto.

Conteggi e percentuali provengono dal server di origine. Openbook evidenzia
la tua scelta senza sommarla autonomamente ai risultati remoti. Un aggiornamento
può quindi arrivare più tardi. Aprire il dettaglio del post può recuperare lo
stato corrente; non viene eseguito un aggiornamento continuo dei risultati.

Quando la scadenza è nota appare accanto a **Sondaggio**. **Sondaggio aperto**
indica una scadenza sconosciuta; **Sondaggio concluso** segnala la chiusura.
Conteggi mancanti appaiono come **Risultati in aggiornamento**. I dati incoerenti
restano visibili, con le percentuali omesse. Se l’origine modifica o elimina
un’opzione scelta, la tua risposta originale resta consultabile.

Le citazioni mostrano il sondaggio in sola lettura. Restano validi gli avvisi
sui contenuti, i permessi del post e i blocchi applicati dall’amministratore.

## Eventi

Gli orari principali sono quelli dell'evento, con il relativo fuso. Se il tuo
orario è diverso, una nota più piccola mostra «… nel tuo fuso», anche con il
giorno quando necessario. Creando un evento viene proposto il tuo fuso personale:
puoi cambiarlo se l'evento si svolge altrove. Modificando un evento si conserva
il fuso già assegnato.

La sezione **Eventi** contiene appuntamenti in arrivo e passati. Gli utenti
autenticati possono creare eventi pubblici o non elencati, con descrizione,
copertina, fuso orario, luogo fisico/online/ibrido e partecipazione aperta,
moderata o esterna. Gli organizzatori possono modificare, annullare o eliminare
i propri eventi e gestire le richieste di partecipazione moderate.

Per gli eventi remoti sono disponibili interesse, commenti e partecipazione
quando supportati dall’origine. I conteggi e gli aggiornamenti dipendono dal
server organizzatore. Condividere un evento in una conversazione privata
mantiene i permessi di accesso già previsti per il destinatario. Gli avvisi
sui contenuti proteggono anche descrizione e media degli eventi.

## Relay e amministrazione

Da **Amministrazione → Relay** puoi gestire la ricezione e la pubblicazione
attraverso relay compatibili Mastodon, ActivityPub o LitePub. Il pannello mostra
lo stato della sottoscrizione, compresa l’attesa di un follow reciproco.
Abilita le direzioni necessarie secondo il protocollo e la policy dell’istanza.

Disabilitare la pubblicazione o rimuovere un relay ferma anche le consegne
pendenti verso quel relay. Contenuti riservati, non elencati, remoti o di
community private sono esclusi dalla pubblicazione pubblica verso i relay.
I blocchi di dominio restano applicati.

La federazione usa richieste firmate e controlli sugli indirizzi contattati.
L’istanza deve essere raggiungibile in HTTPS e avere l’orologio corretto.
Controlla stato delle code, errori e domini bloccati nel pannello amministrativo
quando un’interazione resta in attesa. Per parametri di rete, cron e manutenzione,
vedi la [configurazione](configuration.it.md).

Dopo un aggiornamento segui la [procedura di aggiornamento](install.it.md#aggiornamento-di-unistanza-esistente).
Il cron generale comprende già sondaggi ed eventi: non serve aggiungere cron o
worker permanenti per queste funzioni. Per una preparazione manuale dei sondaggi:

```bash
php artisan openbook:process-polls --max-time=5
php artisan openbook:deliver --max-time=5
```

Il primo comando registra le chiusure e prepara le consegne; il secondo tenta
gli invii. Anche le istanze con un backend di coda separato devono consumare
la coda interna `local_delivery`, oppure usare `openbook:deliver`.

### Recuperare attività ignorate dopo un aggiornamento

Se una versione introduce il supporto a contenuti precedentemente ignorati,
un amministratore può riprocessare le attività ancora conservate:

```bash
php artisan openbook:reprocess-inbox
```

Il comando accoda gli elementi `ignored` e `pending`, senza includere quelli
`processed` o `failed`. Con la coda database, esegui poi
`php artisan openbook:process-inbox`; con `QUEUE_CONNECTION=sync` l’elaborazione
avviene subito. Il comando modifica la coda e non offre un’anteprima: usarlo
come recupero manuale. Può accodare job già presenti; quelli già elaborati
vengono riconosciuti e saltati.
