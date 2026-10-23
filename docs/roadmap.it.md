> Documentazione: [Italiano](README.it.md) · [English](roadmap.md)

# Stato del prodotto e limitazioni

Versione corrente: **26.38.rc1**. Le modifiche per versione sono nel
[CHANGELOG](../CHANGELOG.md). Le versioni stabili usano `anno.settimana`;
le patch candidate della stessa settimana aggiungono `.rcN` e seguono la
relativa stabile. Il footer mostra la versione installata.

## Funzioni disponibili

- Installazione guidata, aggiornamenti e amministrazione compatibili con shared hosting.
- Profili, post, allegati, commenti, Mi piace, condivisioni e citazioni.
- Follow locali e remoti, ricerca, Home cronologica, hashtag seguiti e Mondo.
- Notifiche, conversazioni private e notifiche push quando abilitate.
- Community locali pubbliche/private e iscrizioni a community remote compatibili.
- Eventi locali e remoti, commenti e partecipazione secondo il supporto dell’origine.
- Visualizzazione e voto di sondaggi remoti; creazione, voto, chiusura programmata
  e dettaglio delle risposte per i sondaggi locali.
- Moderazione, blocchi di dominio, gestione delle code e manutenzione database.
- Statistiche dell’istanza per utenti autenticati, grafici asincroni e cache gestibile dall’admin.
- Immagini e audio; video locali opzionali con elaborazione configurata dall’amministratore.

L’interoperabilità viene affinata nel tempo: ogni servizio remoto può offrire
un insieme differente di contenuti e interazioni.

## Limitazioni note

- Durante la scrittura, le menzioni riconoscono account locali e remoti già
  conosciuti. Cerca prima un nuovo account remoto tramite `utente@dominio`.
- Le conversazioni private sono disponibili nella sezione Messaggi. I post con
  visibilità Diretta usano invece i destinatari menzionati nel testo.
- Mondo e ricerca lavorano sui contenuti conosciuti dall’istanza, senza esplorare
  o indicizzare tutto il Fediverso. La ricerca rispetta visibilità e consenso
  all’indicizzazione dei contenuti pubblici altrui. La ricerca per parole chiave
  di post e commenti riguarda i contenuti locali.
- Il testo remoto viene visualizzato in forma sicura, senza HTML arbitrario.
  Le immagini remote dipendono dalla disponibilità dei file presso l’origine.
- I sondaggi locali sono esclusi da conversazioni private, commenti, eventi e
  pubblicazione verso community remote. Opzioni, modalità, scadenza e visibilità
  restano fisse; la chiusura anticipata e la modifica della scadenza sono rinviate.
- La gestione di post fissati e profili commemorativi locali resta fuori dalle
  funzioni disponibili; le informazioni remote compatibili sono visualizzate.
- Il limite configurato di profondità dei commenti non viene ancora applicato
  nell’interfaccia: l’albero delle risposte viene mostrato nella pagina del post.
- Il pannello amministrativo non include ban IP o strumenti avanzati per il
  debug delle firme federate.

Per l’uso quotidiano vedi [Usare Openbook](architecture.it.md); per le differenze
fra server e la gestione delle consegne, vedi [Federazione](federation.it.md).
