# Linee guida tecniche emerse dal lavoro su Openbook

Documento incrementale condiviso. Raccoglie lezioni trasversali utili per le
funzionalita' future.

## Funzionalita' opzionali: dipendenze realmente lazy

Una funzionalita' dichiarata opt-in deve restare assente non soltanto dal flusso
logico quando e' disabilitata, ma anche dal grafo delle dipendenze risolte dai
percorsi generali dell'applicazione.

### Regola

- Non iniettare nel costruttore di un controller condiviso un servizio usato
  soltanto da una singola azione opzionale: Laravel lo risolverebbe per tutte le
  azioni del controller.
- Non iniettare nei parametri di un metodo un servizio opzionale se il metodo
  deve prima verificare che la feature sia abilitata: il container risolve i
  parametri prima di entrare nel corpo del metodo.
- Eseguire prima il controllo della feature flag e risolvere la dipendenza solo
  dentro il ramo che la usa. In questo progetto `app(Service::class)` e' una
  soluzione pragmatica e coerente con lo stile adottato upstream.
- Le pagine e i comandi non pertinenti devono continuare a funzionare anche
  durante un aggiornamento incrementale, quando autoload, OPcache o processi
  residenti potrebbero non essere ancora allineati al nuovo codice.

### Esempio

```php
if (! $settings->featureEnabled()) {
    return;
}

$capability = app(OptionalCapability::class);
$capability->inspect();
```

### Checklist per nuove feature opt-in

1. Elencare controller, comandi e job condivisi che vengono caricati anche con
   la feature spenta.
2. Verificare che nessun loro costruttore renda obbligatori servizi esclusivi
   della feature.
3. Posizionare il controllo della feature flag prima della risoluzione di tali
   servizi e prima di ogni side effect.
4. Testare esplicitamente i percorsi disabilitati sostituendo nel container il
   servizio opzionale con una factory che fallisce se viene risolta.
5. Includere negli smoke test almeno navigazione ordinaria, pannello
   amministrativo e avvio dei comandi con feature disabilitata.

### Origine della linea guida

Nella prima implementazione del supporto video, `PostPublicationStager` era una
dipendenza del costruttore di `PostController` e `VideoCapability` veniva
iniettata nel pannello amministrativo e nel worker prima del controllo
`videoEnabled()`. Upstream ha reso queste risoluzioni lazy, evitando che una
feature disabilitata potesse rompere visualizzazione dei post, impostazioni o
avvio del comando durante il deployment.
