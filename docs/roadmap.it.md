> Documentazione: [Italiano](README.it.md) · [English](README.md)

## Roadmap e stato del progetto

Versione corrente: **26.38.rc1**. Il dettaglio delle modifiche per versione e' in
[`CHANGELOG.md`](../CHANGELOG.md).

Da 26.34 in poi una stable usa `YY.settimana` (questa e' `26.34`, nome in
codice Lovable Pancake). Le patch candidate della stessa settimana sono
`26.34.rc1`, `26.34.rc2`, … e arrivano *dopo* la stable `26.34` (non sono
pre-release precedenti). Le `0.x` nel changelog restano lo storico
pre-stable. NodeInfo e User-Agent usano la versione tecnica (`26.38.rc1`); il
footer mostra `26.38.rc1` (le release candidate non hanno nome in codice).

- ✅ **Fase 1 — Struttura e installazione**: progetto, configurazione, installer,
  database, autenticazione, account amministratore, profili locali.
- ✅ **Fase 2 — Dominio sociale locale**: post, immagini, commenti annidati, Mi
  piace, condivisioni, follow locali, feed, notifiche.
- ✅ **Fase 3 — Identita' federata**: Actor `Person`, WebFinger, NodeInfo, content
  negotiation, inbox/outbox, firme HTTP.
- ✅ **Fase 4 — Federazione sociale**: ricerca remota,
  `Follow`/`Accept`/`Reject`, `Create`/`Update`/`Delete`, `Like`, `Announce`, `Undo`,
  coda MySQL, retry, cron; poi (0.5.x–0.6.x) profilo/impostazioni, Mondo, outbox e
  replies on-demand, pannello admin, signed fetch, notifiche live.
- ✅ **Fase 5 — Community** (0.7.x): Actor `Group` locali e remoti, iscrizione,
  wall, Announce FEP-1b12, Lemmy/Friendica, moderatori, elenco Locali/Remote.
  Eventuali rifiniture (elenco membri dedicato, avatar/copertina community) restano
  possibili senza bloccare la Fase 6.
- 🚧 **Fase 6 — Sicurezza e interoperabilita'** (0.8.x, in corso): tipi
  `Article`/`Video`/`Image`, media remoti in galleria, URI `/users/…`, Accept Lemmy,
  fallback Atom Pixelfed, API blog Wafrn, rullino Foto sul profilo,
  Mondo → `/mondo/scopri`. Ancora da rafforzare: NodeBB e altri edge-case;
  eventuale download locale dei media remoti; destinatari dedicati per i
  messaggi diretti.

Non si passa a una fase successiva finche' i test della fase precedente non sono
verdi.

### Limitazioni note

- Le menzioni in *scrittura* risolvono Actor **locali** e remoti **gia' in cache**
  (`@utente` / `@utente@dominio`); un handle remoto sconosciuto non viene risolto
  al volo via WebFinger in fase di compose. In *ricezione*, una menzione a un Actor
  locale genera correttamente una notifica.
- I messaggi "diretti" (visibilita' `direct`) non hanno un elenco destinatari
  dedicato: sono visibili all'autore e a chi e' menzionato nel testo. Una UI di
  conversazione e' rimandata.
- Il contenuto remoto viene ridotto a testo semplice (niente HTML arbitrario):
  restano pero' i link etichettati (`[testo](url)` da `<a href>`) e le immagini in
  `attachment` come URL remoti. E' una scelta di sicurezza esplicita.
- Un contenuto remoto in inbox e' in cache solo se rilevante (autore seguito,
  risposta a qualcosa di noto, menzione locale). In piu': profilo remoto
  (`RemoteOutboxFetcher`, con fallback Atom) e replies del post remoto
  (`RemoteRepliesFetcher`). Non e' un indice completo del fediverso.
  La ricerca per parole chiave (`LocalSearchQuery`) copre i contenuti *locali*;
  per i remoti resta la risoluzione `utente@dominio`.
- Le immagini remote usano `media.remote_url` (hotlink): se l'origine blocca o
  rimuove il file, la galleria puo' risultare vuota. Gli allegati locali restano
  su `storage/app/public` → `public/storage`, senza CDN.
- Il limite `OPENBOOK_COMMENT_MAX_DEPTH` e' in configurazione ma non ancora
  applicato in UI: l'intero albero commenti di un post viene caricato in una
  pagina.
- Il pannello di controllo copre moderazione, domini bloccati, coda e
  impostazioni; restano fuori ban IP e un tool di debug firme HTTP. Promozione
  staff: UI e CLI (`openbook:make-admin` / `openbook:make-moderator`).
