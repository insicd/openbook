> Documentazione: [Italiano](README.it.md) · [English](README.md)

## Sicurezza e privacy

- Password con hashing moderno tramite i meccanismi nativi di Laravel (bcrypt),
  nessun algoritmo custom.
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
