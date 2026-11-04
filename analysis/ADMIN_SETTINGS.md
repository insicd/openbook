# Riorganizzazione del pannello amministrativo

Ramo: `ui_visibility`, creato da `main` aggiornato a `97208d4`.

Decisioni approvate il 4 novembre 2026. Intervento di navigazione e separazione
form: chiavi system_settings, configurazione, policy e permessi restano quelli
esistenti. Non sono richieste migrazioni.

- Questa istanza: tab Generali (identità, contatti, icone, sfondo e staff),
  Registrazioni e policy (apertura, regole, privacy), Contenuti (limiti e tendenze).
- Video: tutti i controlli e i limiti video, inclusa la verifica dei binari.
- Moderazione: tab Contenuti (CW e hashtag) e Domini bloccati. Le operazioni
  esistenti sui domini restano disponibili e il vecchio URL viene reindirizzato.
- Code e processi: tab In attesa, Falliti e Inbox federazione, con contatori
  sempre visibili; azioni esistenti e ritorno sul tab di partenza.
- Segnalazioni: gli attuali filtri di stato diventano tab dello stesso stile,
  preservando query, paginazione e accesso dei moderatori.
- Menu laterale con sottili divisori: Dashboard; Questa istanza, Aspetto,
  Utenti, Moderazione, Segnalazioni; Video, Object Storage, Database,
  Code e processi, Registro azioni, Aggiornamenti; Relay. Nessun sottomenu.

Ogni form valida e salva soltanto i propri campi; un checkbox assente può
azzerare solo l'opzione appartenente al form inviato. Gli upload delle immagini
dell'istanza restano nel flusso transazionale esistente. Audit e configurazione
runtime sono aggiornati dal servizio InstanceSettings anche per salvataggi
parziali, senza riscrivere le opzioni delle altre schermate.

Verifiche: adeguare i test admin esistenti per percorsi, form parziali,
permessi, redirect/tab e azioni. Aggiungere solo copertura mirata per isolamento
e navigazione; eseguire suite admin, controlli di stile e suite completa.

## Esito

Implementata la struttura approvata. Il servizio InstanceSettings applica
aggiornamenti parziali con chiavi consentite, normalizzazione, configurazione
runtime e audit; i controller validano solo la sezione inviata. Gli upload delle
immagini dell’istanza mantengono transazione e cleanup precedenti.

Il componente admin-tabs condivide lo stile dei tab Database. Le pagine di
moderazione, code e segnalazioni mantengono operazioni, filtri e autorizzazioni;
le liste delle code vengono caricate solo per il tab attivo. Il precedente URL
dei domini viene reindirizzato al tab canonico.

- Adeguati i test esistenti di impostazioni, accessi, domini, code e segnalazioni.
  Aggiunto **un solo test** per l’isolamento dei salvataggi fra schermate.
- Suite admin e traduzioni: **147 test, 6.660 asserzioni**, tutti passati.
- Suite completa finale: **2.373 test, 18.370 asserzioni, 131 saltati**, nessun
  errore o fallimento (PHP 8.5.12, 4 novembre 2026).
- Pint e `git diff --check` passati. Anteprima desktop verificata con dati
  fittizi per menu laterale, tab Contenuti, Domini bloccati e Code e processi.
- Nessuna nuova query di filtro, aggregazione o ordinamento: riutilizzati i
  lookup e gli elenchi precedenti. Nessuna migrazione o nuova chiave settings.
- Cache locali delle route e delle viste pulite per caricare la nuova struttura.
