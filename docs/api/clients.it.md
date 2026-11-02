# Integrazione client OAuth

> [English](clients.md) · [Panoramica API](README.it.md)

Usare Authorization Code con PKCE S256, client pubblico, callback e scope unico
`api`. La libreria genera verifier, challenge e state. Non serve polling;
l'utente vede soltanto login OpenBook e conferma dell'app.

| Piattaforma | Libreria | Configurazione dell'app |
| --- | --- | --- |
| iOS | [AppAuth-iOS](https://github.com/openid/AppAuth-iOS) | Endpoint e callback gestita da ASWebAuthenticationSession; gestione dello stato di autenticazione restituito. |
| Android | [AppAuth-Android](https://github.com/openid/AppAuth-Android) | Endpoint e callback tramite Custom Tabs/App Links o schema URI privato. |
| React / JavaScript browser | [oidc-client-ts](https://github.com/authts/oidc-client-ts) | Registrazione con fetch, configurazione endpoint OAuth e chiamate alla libreria dal login e dalla rotta callback. |

AppAuth supporta la registrazione RFC 7591: impostare token_endpoint_auth_method
su `none` e usare il registration_endpoint dei metadata OAuth. In alternativa
si può fornire un client_id già registrato sull'istanza. Conservare e riusare
la registrazione; registrazioni indipendenti producono client_id indipendenti.
Per i token nativi usare gli strumenti di archiviazione sicura della piattaforma.

## Esempio browser

Leggere `/.well-known/oauth-authorization-server`, registrarsi una volta tramite
registration_endpoint e conservare client_id. L'esempio usa soltanto OAuth:
non richiede discovery OpenID Connect, ID token o userinfo.

```js
import { OidcClient } from 'oidc-client-ts';

const client = new OidcClient({
  authority: instanceUrl,
  metadata,                 // RFC 8414 document fetched from OpenBook
  client_id: savedClientId,
  redirect_uri: callbackUrl,
  response_type: 'code',
  scope: 'api',
  loadUserInfo: false,
});

// Sign-in button:
const request = await client.createSigninRequest({});
window.location.assign(request.url);

// In the callback route, using the same configuration and browser origin:
const result = await client.processSigninResponse(window.location.href);
// result.access_token is the Bearer credential for subsequent API calls.
```

La conservazione del client_id pubblico è distinta da quella dei token.
L'applicazione sceglie durata/storage del proprio stato client; non inserire
il token negli URL o nei log. Dopo il ritorno rimuovere la query della callback
dalla cronologia del browser. Anche nelle app native lasciare che AppAuth
esegua PKCE e scambio token, senza riscriverli.

## Verifica SDK riproducibile

Il repository contiene una prova con oidc-client-ts reale, SQLite isolato e un
server PHP su loopback. Login e consenso browser sono simulati tramite HTTP,
cookie jar e controlli CSRF reali; l'SDK genera PKCE/state, verifica la callback
e scambia il codice. Poi legge `/me` con la Person autenticata, verifica
200/304/401 e chiude l’accesso dalla pagina delle impostazioni con CSRF reale. Verifica che Bearer
e rinnovo siano rifiutati e che il sito resti aperto. Le chiavi sono generate
automaticamente durante il flusso: il test verifica che siano cifrate nel database,
senza predisporre file di chiavi. I dati
temporanei vengono eliminati; non viene usato il database OpenBook locale.
Non è una prova di rendering React o su dispositivi iOS/Android.

```sh
npm install --prefix /tmp/openbook-oauth-sdk oidc-client-ts@^3 --ignore-scripts --no-audit --no-fund
NODE_PATH=/tmp/openbook-oauth-sdk/node_modules node tests/Client/oauth.cjs
```

Serve il marker dell'istanza locale installata, come per gli altri test.
Eseguire questa prova separatamente dai test installer, che rimuovono il marker
temporaneamente. Servono una porta loopback e Node.js con fetch/Web Crypto.

## Verifica con ambiente production

La stessa prova è eseguibile con bootstrap in produzione:

```sh
APP_ENV=production OPENBOOK_OAUTH_TEST_ENV=production NODE_PATH=/tmp/openbook-oauth-sdk/node_modules node tests/Client/oauth.cjs
```

SQLite, chiavi, sessioni e server loopback restano isolati. La fixture verifica
che la produzione forzi HTTPS, poi usa URL HTTP e cookie non Secure soltanto
nel trasporto loopback isolato. CSRF, PKCE/state e revoca sono reali; non è una
verifica del deploy TLS. Nel deploy usare HTTPS e cookie Secure.
