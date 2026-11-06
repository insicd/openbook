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

Il primo sprint REST/OAuth è disponibile anche in produzione. Le risorse
richiedono Bearer valido, scope api e account/attore attivi; le credenziali Web
non autorizzano l’API. OAuth usa Authorization Code con PKCE S256 e consenso
con CSRF. Gli accessi sono revocabili dalle impostazioni insieme ai refresh
token. L’API rispetta visibilità, ownership e confini delle community private;
non espone amministrazione o moderazione. Le cache sono private e il 304 viene
valutato dopo i controlli di accesso. Nomi, contenuti e URL ricevuti restano dati
non fidati per il rendering del client. Le chiavi di firma OAuth sono generate
automaticamente e conservate nel database cifrate con APP_KEY. Mantenere entrambi
nei backup e nei deploy; chiavi esistenti non leggibili producono un errore senza
sostituzione automatica. Limiti e istruzioni operative sono descritti nella
[guida API](api/README.it.md).

## Verifica email e pubblicazione

Prima della verifica email gli account locali possono navigare, personalizzare
il profilo, seguire utenti, mettere mi piace e ricondividere post esistenti.
Creare post (incluse citazioni e sondaggi), commenti, eventi, messaggi privati e
community richiede la verifica. Anche la pubblicazione video attende la conferma.
Il vincolo vale per sito e REST API, inclusi gli account esistenti non verificati.

Dall'avviso si accede alla pagina di verifica per richiedere una nuova email.
Confermando il link firmato si abilita la pubblicazione senza un nuovo login;
i token esistenti restano utilizzabili. Modifica e cancellazione di contenuti
esistenti conservano i permessi attuali. La verifica non sostituisce i controlli
di visibilità, proprietà e moderazione.
