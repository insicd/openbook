# Contribuire a Openbook

Grazie per l'interesse a contribuire a Openbook! Questo documento riassume come
proporre modifiche in modo coerente con l'architettura e gli obiettivi del progetto.

## Principi generali

- Openbook segue una roadmap a fasi (vedi [`README.md`](README.md#roadmap-e-stato-del-progetto)):
  non si avviano funzionalita' di una fase successiva finche' i test della fase
  precedente non sono verdi.
- Il software deve restare **installabile su shared hosting**: evita di introdurre
  dipendenze obbligatorie da Redis, code/worker permanenti, WebSocket, Docker o
  servizi cloud esterni. Componenti opzionali avanzati sono benvenuti, ma devono
  degradare in modo elegante quando non disponibili.
- La federazione ActivityPub e' parte integrante dell'architettura, non un plugin:
  mantieni separati dominio applicativo locale, rappresentazione ActivityStreams,
  ricezione/consegna delle attivita' e interfaccia web (vedi la sezione
  [Architettura](README.md#architettura) del README).
- I controller non devono contenere logica di dominio o di federazione: devono
  validare, autorizzare, invocare un servizio applicativo e restituire la risposta.

## Requisiti di sviluppo

- PHP 8.2+, Composer.
- Un client MySQL/MariaDB e' opzionale per lo sviluppo quotidiano (la suite di test
  usa SQLite in memoria di default) ma e' necessario per esercitare i test di
  integrazione specifici sull'installer (vedi [README — Test](README.md#test)).

## Flusso di lavoro

1. Apri una issue descrivendo il problema o la funzionalita' proposta, se non ne
   esiste gia' una: aiuta a evitare lavoro duplicato e a discutere l'approccio prima
   di scrivere codice.
2. Crea un branch a partire da `main`.
3. Scrivi codice completo: niente pseudocodice, metodi vuoti o TODO generici. Ogni
   funzione deve gestire gli errori, usare transazioni dove serve e rispettare le
   autorizzazioni gia' presenti.
4. Aggiungi o aggiorna i test relativi (PHPUnit). Le fixture ActivityPub devono
   essere realistiche, non semplificate all'eccesso.
5. Esegui la suite completa prima di aprire la pull request:

   ```bash
   php artisan test
   ```

6. Aggiorna la documentazione (README, commenti dove utile) se la modifica cambia il
   comportamento osservabile o i requisiti di installazione.
7. Apri la pull request descrivendo cosa cambia e perche', collegando la issue
   correlata.

## Stile del codice

- Tipizza sempre parametri e valori di ritorno.
- Non duplicare logica gia' presente in un servizio applicativo esistente.
- Aggiungi commenti solo per spiegare *perche'* una scelta e' stata fatta (vincoli,
  compromessi), non *cosa* fa il codice riga per riga.
- Verifica che una funzione o un metodo del framework esista davvero prima di usarlo;
  non inventare API.

## Segnalazione di vulnerabilita'

Non usare le issue pubbliche per problemi di sicurezza: segui le istruzioni in
[`SECURITY.md`](SECURITY.md).

## Licenza dei contributi

Contribuendo a questo repository accetti che il tuo codice venga distribuito sotto
la stessa licenza del progetto, GNU Affero General Public License v3.0 o successiva
(vedi [`LICENSE`](LICENSE)).
