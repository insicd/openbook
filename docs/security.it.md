> Documentazione: [Italiano](README.it.md) · [English](README.md)

## Sicurezza e privacy

- Password protette con hashing bcrypt.
- Autenticazione con rate limiting sui tentativi di login e sul reinvio delle email
  di verifica.
- Chiavi private degli Actor cifrate a riposo, mai esposte da API/log/errori.
- Cookie di sessione `HttpOnly` e `secure` in produzione, protezione CSRF su tutte le
  form.
- Nessun analytics di terze parti, tracker, pixel pubblicitari, CDN o font remoti
  obbligatori: l'interfaccia usa solo CSS e asset serviti localmente.
- L'installer non mostra mai segreti (password, token) dopo il completamento della
  procedura e si blocca permanentemente al termine.

Per segnalare vulnerabilita' vedi [`SECURITY.md`](../SECURITY.md).

## API REST sperimentale

Il primo sprint REST/OAuth è disponibile soltanto in local/testing. Le risorse
richiedono Bearer valido, scope api e account/attore attivi; le credenziali Web
non autorizzano l’API. OAuth usa Authorization Code con PKCE S256 e consenso
con CSRF. Gli accessi sono revocabili dalle impostazioni insieme ai refresh
token. L’API rispetta visibilità, ownership e confini delle community private;
non espone amministrazione o moderazione. Le cache sono private e il 304 viene
valutato dopo i controlli di accesso. Nomi, contenuti e URL ricevuti restano dati
non fidati per il rendering del client. Limiti e preparazione delle chiavi
sono descritti nella [guida API](api/README.it.md).
