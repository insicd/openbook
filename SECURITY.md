# Politica di sicurezza

Openbook gestisce dati personali e chiavi crittografiche degli utenti (chiavi private
degli Actor ActivityPub, sessioni, password). Prendiamo sul serio qualsiasi
segnalazione di vulnerabilita'.

## Versioni supportate

Il progetto e' attualmente al Milestone 1 (Fase 1 della roadmap) e non ha ancora una
release stabile numerata. Fino alla prima release 1.0, solo il branch principale
riceve correzioni di sicurezza.

## Come segnalare una vulnerabilita'

**Non aprire una issue pubblica per vulnerabilita' di sicurezza.**

Invece:

1. Invia una email a `security@openbook.example.org` (sostituire con l'indirizzo
   reale del progetto quando pubblicato) descrivendo:
   - il tipo di vulnerabilita' (es. SSRF, XSS, bypass di autenticazione, ecc.);
   - i passi per riprodurla;
   - l'impatto potenziale;
   - eventuale codice di prova (proof of concept).
2. Riceverai una conferma di ricezione entro 5 giorni lavorativi.
3. Lavoreremo con te per validare e correggere il problema prima di qualsiasi
   divulgazione pubblica (coordinated disclosure).

## Ambiti di particolare attenzione

Data la natura federata di Openbook, le aree piu' sensibili sono:

- **SSRF**: qualunque codice che effettua richieste HTTP verso URL forniti da server
  remoti (recupero di Actor, allegati, WebFinger) deve rispettare le protezioni
  documentate nel design (blocco di reti private, limiti su redirect/dimensione/
  tempo, risoluzione DNS pre e post redirect).
- **Firme HTTP**: la verifica delle firme in ingresso e la gestione delle chiavi
  private locali (mai loggate, mai restituite da API, cifrate a riposo).
- **Sanitizzazione HTML**: qualunque contenuto HTML proveniente da server remoti deve
  passare attraverso un allowlist rigorosa prima di essere memorizzato o mostrato.
- **Upload di media**: validazione del tipo effettivo del file (non della sola
  estensione), rimozione di metadati sensibili, blocco di file eseguibili o SVG non
  sanitizzati.
- **Isolamento degli account**: nessun endpoint deve permettere di leggere o
  modificare dati di un altro utente senza autorizzazione esplicita.

## Buone pratiche per chi gestisce un'istanza

- Mantieni PHP, le dipendenze Composer e il database aggiornati.
- Usa sempre HTTPS in produzione.
- Non disabilitare la verifica delle firme HTTP in ingresso al di fuori
  dell'ambiente di sviluppo locale.
- Se abiliti il cron via web (`OPENBOOK_WEB_CRON_ENABLED`), usa un token lungo e
  casuale e monitora i log per tentativi di accesso non autorizzati.
