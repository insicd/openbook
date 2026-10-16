# Fuso orario personale

Stato al 16 ottobre 2026: analisi revisionata, decisioni funzionali concordate
e impostazione tecnica verificata sulla documentazione Laravel 12 e Carbon.
Nessuna implementazione avviata. Ramo `codex/user-timezone`, creato sopra
`codex/finnish-language` al commit `e0b8049`.

## Obiettivo

Consentire a ogni utente di scegliere il proprio fuso orario indipendentemente
da quello dell'istanza e rendere coerenti gli orari mostrati nell'interfaccia.
La preferenza è personale: non modifica gli istanti di pubblicazione,
le scadenze, la federazione o il fuso dichiarato da un evento.

## Situazione attuale

- `user_settings.timezone` esiste già, è una stringa di 64 caratteri con
  default di schema `UTC`; `UserSetting` la include tra i campi assegnabili.
- `AccountRegistrar` inizializza il campo con `config('app.timezone', 'UTC')`:
  il valore effettivo dei nuovi account può quindi differire dal default SQL.
- `config/app.php` legge `APP_TIMEZONE`. Il riferimento attuale dei dati è
  quello dell'applicazione, non necessariamente UTC su ogni installazione.
- `UpdateAccountRequest`, `AccountPreferencesUpdater` e la pagina delle
  impostazioni non espongono la preferenza del fuso orario.
- Le date assolute sono formattate direttamente in varie view e presenter.
  I tempi relativi usano principalmente `diffForHumans()`.
- Gli eventi hanno già un proprio fuso IANA oppure un offset remoto;
  il composer interpreta gli input in quel fuso e converte gli istanti nel
  riferimento dell'applicazione. Per i nuovi eventi il browser suggerisce
  già un fuso, senza sovrascrivere i valori di un evento in modifica.

## Decisioni confermate

1. **Automatico o personalizzato:** `user_settings.timezone = NULL` significa
   «Automatico»: il fuso segue il dispositivo anche negli accessi successivi
   e durante i viaggi. Il valore rilevato viene validato lato server e usato
   per la visualizzazione, senza salvarlo come preferenza esplicita nel profilo.
   Un identificatore salvato, per esempio `Europe/Rome`, significa invece
   «Fuso personalizzato» e prevale sempre sul dispositivo. Dalle impostazioni
   l'utente può scegliere un fuso oppure tornare ad Automatico.
   Il rilevamento usa l'API nativa
   `Intl.DateTimeFormat().resolvedOptions().timeZone`, senza geolocalizzazione
   né richieste di permesso.
2. **Schema e account esistenti:** una nuova migration rende nullable il campo
   esistente, con default `NULL`, e porta a `NULL` i valori precedentemente
   assegnati dal server. Finora la UI non consentiva una scelta personale.
   I nuovi account, anche da installer e CLI, partono in Automatico.
   Non serve un campo aggiuntivo per distinguere una scelta esplicita.
   La migration deve funzionare su MySQL/MariaDB e SQLite; il rollback deve
   ricondurre i NULL a un valore valido prima di ripristinare il vincolo.
3. **Ospiti e ripiego:** anche gli ospiti usano il fuso rilevato dal browser.
   Se il rilevamento manca o restituisce un valore non valido, si usa
   `config('app.timezone', 'UTC')`. Lo stesso ripiego si applica agli account
   in Automatico, senza trasformare NULL in una scelta esplicita. Una preferenza
   personale valida continua a prevalere sul browser e sul ripiego.
   Il trasporto del fuso rilevato verso il server e la gestione della prima
   pagina prima del rilevamento restano dettagli da definire nell'implementazione.
4. **Eventi in lettura:** l'orario principale resta quello dell'evento, con
   il relativo fuso. Quando il fuso del visitatore differisce, affiancare
   oppure mostrare sotto, secondo lo spazio della pagina, l'orario convertito
   con la dicitura «… nel tuo fuso», in grigio e con carattere più piccolo.
   Applicare il criterio a inizio e fine, dove presenti, nelle schede,
   nel dettaglio e nei rendering condivisi delle citazioni. Se la conversione
   attraversa la mezzanotte, indicare anche il giorno per evitare ambiguità.
   Per gli eventi remoti conservare la gestione esistente di fuso, offset
   e ripiego, senza dedurre un fuso geografico da un semplice offset.
5. **Composer degli eventi:** per un nuovo evento proporre il fuso personale
   effettivo: scelta esplicita dell'account oppure fuso del dispositivo in
   Automatico, con il ripiego concordato sul fuso dell'istanza. L'autore può
   scegliere un altro fuso nel form. In modifica conservare il fuso già
   assegnato all'evento; dopo un errore di validazione conservare la scelta
   inviata. Il rilevamento del browser non deve sovrascrivere il default
   derivato da una preferenza esplicita né le scelte già effettuate nel form.
6. **Date senza ora:** se la data deriva da un timestamp (per esempio
   l'iscrizione mostrata nel profilo), convertirlo nel fuso del visitatore
   prima di estrarre il giorno. Visitatori in fusi diversi possono vedere
   giorni differenti per lo stesso istante vicino alla mezzanotte.
   Le date di calendario prive di un istante associato non vengono convertite.
7. **Amministrazione:** gli orari leggibili nel pannello seguono il fuso
   personale effettivo dell'amministratore. Nelle pagine con orari assoluti
   indicare una sola volta il riferimento, per esempio «Orari in Europe/Rome»,
   per facilitarne il confronto con i log. I log del server e il cron
   mantengono il riferimento dell'applicazione.
8. **UI ordinaria:** mostrare soltanto date e orari nel fuso effettivo,
   senza etichette aggiuntive né tooltip sul fuso. Restano le indicazioni
   specifiche già concordate per eventi, amministrazione e selettore delle
   impostazioni.

## Linee di implementazione

Realizzare le decisioni concordate riutilizzando i flussi esistenti.

- Riutilizzare il campo esistente e il normale flusso delle preferenze.
- Salvare identificatori IANA, per esempio `Europe/Helsinki`, includendo UTC
  tra le scelte. Validare contro gli identificatori supportati dal runtime.
  Un offset fisso non rappresenta correttamente l'ora legale.
- Risolvere il fuso di visualizzazione una volta per richiesta, con ripiego
  controllato se l'impostazione manca o contiene un valore non valido.
- Centralizzare la conversione/formattazione per view e risposte Ajax,
  lavorando su copie delle date, senza mutare gli oggetti del dominio.
- Non cambiare globalmente `APP_TIMEZONE`, la timezone PHP o il riferimento
  della connessione SQL in base all'utente collegato.
- Lasciare invariati gli istanti dei timestamp macchina: HTML `datetime`,
  JSON e ActivityPub devono continuare a rappresentare lo stesso istante
  con un offset esplicito. Le etichette per la lettura seguono il fuso scelto.
- Nessuna migrazione dei timestamp storici. Non presumere che tutti siano
  stati salvati in UTC e non correggere discrepanze storiche in questo lavoro.
- Nessun servizio esterno, geolocalizzazione IP o worker aggiuntivo.

## Censimento dei punti impattati

| Area | Punti principali | Intervento o verifica |
| --- | --- | --- |
| Preferenza personale | `UserSetting`, `UpdateAccountRequest`, `AccountPreferencesUpdater`, `resources/views/settings/index.blade.php`, nuova migration | Campo nullable con default NULL, conversione degli account esistenti ad Automatico, selettore Automatico/personalizzato, validazione e salvataggio. Il solo cambio del fuso non deve generare Update federati dell'Actor. |
| Creazione account | `AccountRegistrar`, registrazione web, installer e comando di creazione amministratore | Inizializzare a NULL (Automatico), mantenendo operativo il percorso senza JavaScript e CLI. |
| Contesto della richiesta | Middleware web, layout e servizi di presentazione | Risolvere il fuso del visitatore senza modificare la semantica delle date del dominio; distinguere ospiti e autenticati. |
| Sondaggi | `posts/_poll.blade.php`, `composer/form.blade.php` | Scadenza e data del voto nel fuso personale, anche in anteprima/modifica. Durata e chiusura restano basate sugli stessi istanti. |
| Messaggi diretti | `messages/_bubble.blade.php`, `ConversationMessagePresenter` | Allineare rendering iniziale e aggiornamenti Ajax: oggi entrambi formattano direttamente `d/m/Y H:i`. |
| Date dei profili | `profile/show.blade.php`, `actors/show.blade.php` | Convertire i timestamp nel fuso del visitatore prima di mostrarne il giorno; non convertire date di calendario prive di un istante associato. |
| Tempi relativi | `posts/_card`, `comments/_comment`, `events/_comment`, `profile/_activity_item`, notifiche, ricerca, elenco conversazioni | Conservare la rappresentazione relativa e verificare che il medesimo istante produca una distanza coerente. Non convertire stringhe già formattate. |
| Notifiche Ajax | `NotificationController` | Il campo testuale `time` deve restare coerente con la view iniziale; gli istanti non cambiano. |
| Eventi: lettura | `events/_card.blade.php`, `events/show.blade.php`, citazioni nei messaggi | Orario dell'evento principale; orario personale secondario, più piccolo e grigio, quando il fuso differisce. Riutilizzare il rendering condiviso nelle citazioni. Gestire anche eventi remoti con solo offset o senza fuso. |
| Eventi: inserimento | `events/create.blade.php`, `EventController`, `StoreEventRequest`, `EventComposer` | Proporre il fuso personale effettivo nei nuovi eventi, lasciandolo modificabile. Conservare il fuso degli eventi in modifica e i valori inviati dopo errori. Interpretare gli input nel fuso dichiarato dal form. |
| Amministrazione | `admin/reports/show`, `admin/audit/index`, `admin/domain-blocks/index`, `admin/relays/index` | Usare il fuso personale effettivo; nelle pagine con orari assoluti indicarlo una volta. Log server e cron mantengono il riferimento dell'applicazione. |
| Confine tecnico | `ActivityPubTimestamp`, serializer ActivityPub/Mastodon, firme, job, scadenze e `openbook:cron` | Preservare il riferimento dell'applicazione e i formati di protocollo; nessuna preferenza personale in processi senza utente. |
| Lingue e manualistica | `lang/it`, `lang/en`, `lang/fi`; guide utente IT/EN in `docs/` | Tradurre il selettore e descrivere scelta e comportamento delle date. Dettagli tecnici e test in `development/`, non nei manuali utente. |

La ricognizione individua i punti di ingresso. Prima dell'implementazione
verificare anche formati `format()`/`translatedFormat()`/ISO e percorsi di
rendering indiretti nell'area toccata, evitando sostituzioni globali automatiche.

## Verifica del manuale Laravel e scelta tecnica

Fonti ufficiali consultate per la versione 12 usata dal progetto:

- [Date casting, serializzazione e timezone](https://laravel.com/docs/12.x/eloquent-mutators#date-casting-serialization-and-timezones):
  Laravel raccomanda UTC per archiviazione e serializzazione. I cast di data
  producono Carbon; cambiare il formato dei cast riguarda anche JSON, non
  risolve soltanto il rendering delle view. Qui si preserva il riferimento
  storico dell'istanza, senza convertirne il database.
- [Estensione di Blade](https://laravel.com/docs/12.x/blade#extending-blade):
  il manuale mostra una direttiva personalizzata per la formattazione delle
  date. È il punto di estensione adatto per centralizzare il comportamento.
- [Componenti Blade](https://laravel.com/docs/12.x/blade#anonymous-components):
  un componente condiviso è adatto per il markup degli orari degli eventi.
- [Regola timezone](https://laravel.com/docs/12.x/validation#rule-timezone):
  riutilizzare la validazione nativa degli identificatori, senza espressioni
  regolari o tabelle di fusi mantenute dal progetto.
- [Localizzazione Carbon](https://carbon.nesbot.com/guide/getting-started/localization.html):
  le conversioni appartengono alla presentazione, su istanze isolate.
  `setTimezone()` conserva l'istante; `shiftTimezone()` cambia il significato
  dell'orario e non è adatto a convertire timestamp già definiti.

Il manuale non prevede un interruttore che applichi automaticamente il fuso
del visitatore a tutte le chiamate `format()` nelle view. Questa è una scelta
di presentazione dell'applicazione, realizzata con gli strumenti documentati:

1. Un servizio di presentazione risolve il fuso dalla richiesta (preferenza,
   browser, ripiego), lo riusa nella stessa richiesta e formatta copie
   immutabili delle date. Non memorizza preferenze in stato globale statico.
2. Una direttiva Blade delega a quel servizio e produce testo escapato.
   Le view interessate cambiano soltanto il punto di formattazione; traduzioni,
   autorizzazioni e attributi ISO restano nei flussi esistenti. I presenter
   Ajax usano lo stesso servizio, senza passare per Blade.
3. Il componente degli eventi riusa le stesse conversioni per l'orario
   dell'evento e quello personale; niente duplicazione del relativo markup.
4. Le date relative restano relative: nessuna riscrittura generalizzata di
   `diffForHumans()`, nessun accessor/cast Eloquent dipendente dal visitatore,
   nessuna macro globale di Carbon né modifica a `serializeDate()`.

### Trasporto del fuso del browser

Il server non riceve un fuso orario negli header HTTP standard. Un piccolo
script condiviso legge `Intl` e aggiorna un cookie tecnico del dispositivo,
distinto dalla preferenza dell'account. Il cookie deve essere leggibile e
scrivibile da JavaScript, SameSite=Lax e Secure su HTTPS; è un input non
fidato e viene sempre validato. Non contiene una scelta personale persistita
nel profilo e viene aggiornato quando il dispositivo comunica un altro fuso.

Alla prima visita senza cookie, o dopo il cambio di fuso del dispositivo,
la pagina GET in Automatico può essere ricaricata una volta, prima di mostrare
il contenuto, per renderizzare subito gli orari corretti lato server. Lo script
vive nella testa del layout, prima dei form: nessun salvataggio/ricostruzione
del composer, nessuna ripetizione di POST e nessun ciclo di reload quando i
cookie sono bloccati. Senza JavaScript o cookie si usa il ripiego concordato.
Con fuso personale esplicito non serve alcun reload per il rilevamento.

## Decisioni aperte

Non restano decisioni funzionali aperte. La gestione specifica degli input
ambigui o inesistenti nel cambio dell'ora è fuori dal perimetro: il form degli
eventi mantiene il comportamento attuale, senza nuovi controlli o passaggi UI.

## Stadi proposti e verifiche

Sequenza di implementazione. Ogni stadio prevede test mirati e review;
la verifica manuale dell'utente precede il commit conclusivo del codice.

1. Preferenza e contesto di visualizzazione: salvataggio, identificatori validi,
   ripieghi, percorsi senza JavaScript e creazione account. Prova manuale con
   due utenti in fusi diversi, persistenza della scelta esplicita, Automatico
   che segue il dispositivo e ritorno dalla scelta esplicita ad Automatico.
2. Date dell'interfaccia: sondaggi, messaggi (pagina e Ajax), profili e aree
   concordate. Verificare lo stesso contenuto in due fusi, attraversamento di
   mezzanotte e transizioni dell'ora legale; nessun cambiamento dei dati salvati.
3. Eventi secondo le decisioni concordate, uniformità e documentazione.
   Verificare creazione/modifica, eventi remoti con fuso/offset e citazioni.
   Review completa, suite PHPUnit e verifica manuale prima del commit finale.

I test dovranno coprire una `APP_TIMEZONE` diversa da UTC, separazione tra
lingua e fuso (per esempio italiano con `Europe/Helsinki`), impostazioni
assenti/non valide, isolamento tra richieste di utenti diversi, invarianza
degli istanti serializzati e delle scadenze. Le conversioni non devono mutare
le istanze Carbon riutilizzate dai modelli. Se emergono modifiche a schema
o query, applicare anche le verifiche MySQL/MariaDB richieste dal progetto.

## Esclusioni iniziali

Non si affrontano una conversione del database a UTC, una modifica dei log
server, nuovi formati di protocollo, la timezone pubblica degli Actor, una
geolocalizzazione o la riscrittura dei formati data dell'intera applicazione.
La validazione dedicata degli orari ambigui/inesistenti nei form degli eventi
resta esclusa. La conversione ordinaria continua a usare le regole di ora
legale del runtime senza aggiungere nuovi controlli UI.
