<?php

// Definita come variabile (anziche' riletta con config('openbook.version')
// piu' sotto, che durante il caricamento di *questo stesso* file
// restituirebbe ancora un valore vuoto) cosi' da comparire in modo coerente
// sia nel documento NodeInfo sia nello User-Agent delle richieste in uscita:
// due software del Fediverso che si scambiano segnali di versione diversi
// per la stessa istanza sono un sintomo classico di misconfigurazione.
$version = '0.8.0';

return [

    /*
    |--------------------------------------------------------------------------
    | Versione del software
    |--------------------------------------------------------------------------
    |
    | Riportata nel documento NodeInfo pubblico, nel footer e nello
    | User-Agent delle richieste in uscita. Aggiornata manualmente a ogni
    | fase della roadmap.
    */
    'version' => $version,

    /*
    |--------------------------------------------------------------------------
    | Sito ufficiale del software
    |--------------------------------------------------------------------------
    |
    | Non e' il dominio di *questa istanza* (vedi "domain" qui sotto) ma la
    | pagina del progetto Openbook stesso: usata nel footer (il nome
    | "Openbook" linka sempre qui, a prescindere da come l'amministratore ha
    | chiamato la propria istanza) e come "software.homepage" nel documento
    | NodeInfo, cosi' chi guarda un'istanza sconosciuta puo' risalire
    | facilmente al software che la fa funzionare.
    */
    'homepage' => 'https://about.openb.app',

    /*
    |--------------------------------------------------------------------------
    | Dominio federato dell'istanza
    |--------------------------------------------------------------------------
    |
    | Deve corrispondere all'host pubblico dell'istanza e viene utilizzato
    | per comporre gli indirizzi federati (utente@dominio), gli identificatori
    | ActivityPub e le risposte WebFinger. Non deve cambiare dopo l'avvio.
    |
    */
    'domain' => env('OPENBOOK_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),

    /*
    |--------------------------------------------------------------------------
    | Stato di installazione
    |--------------------------------------------------------------------------
    |
    | Determina se l'installer guidato deve essere ancora eseguito. Viene
    | impostato a true dall'installer stesso al termine della procedura e
    | non deve essere modificato manualmente in produzione.
    */
    'installed' => (bool) env('OPENBOOK_INSTALLED', false),

    /*
    |--------------------------------------------------------------------------
    | Cron via web (opzionale)
    |--------------------------------------------------------------------------
    |
    | Su hosting privi di cron job reali o accesso CLI, i processi periodici
    | possono essere richiamati tramite una richiesta HTTP autenticata da un
    | token segreto: /cron/run?token=...
    */
    'web_cron' => [
        'enabled' => (bool) env('OPENBOOK_WEB_CRON_ENABLED', false),
        'token' => env('OPENBOOK_WEB_CRON_TOKEN'),
        'min_interval_seconds' => (int) env('OPENBOOK_WEB_CRON_MIN_INTERVAL', 55),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registrazioni
    |--------------------------------------------------------------------------
    */
    'registration' => [
        'open' => (bool) env('OPENBOOK_REGISTRATION_OPEN', true),
        'requires_approval' => (bool) env('OPENBOOK_REGISTRATION_REQUIRES_APPROVAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lingue dell'interfaccia
    |--------------------------------------------------------------------------
    |
    | Lingue tra cui ogni utente puo' scegliere nelle impostazioni account
    | (vedi "user_settings.locale"): devono corrispondere a una cartella
    | presente in "lang/".
    */
    'locales' => [
        'it' => 'Italiano',
        'en' => 'English',
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */
    'media' => [
        'max_size_kb' => (int) env('OPENBOOK_MEDIA_MAX_SIZE_KB', 8192),
        'max_attachments_per_post' => (int) env('OPENBOOK_MEDIA_MAX_ATTACHMENTS', 4),
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contenuti
    |--------------------------------------------------------------------------
    */
    'posts' => [
        'max_length' => (int) env('OPENBOOK_POST_MAX_LENGTH', 5000),
    ],

    'comments' => [
        'max_length' => (int) env('OPENBOOK_COMMENT_MAX_LENGTH', 2000),
        'max_visible_depth' => (int) env('OPENBOOK_COMMENT_MAX_DEPTH', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feed
    |--------------------------------------------------------------------------
    */
    'feed' => [
        'per_page' => (int) env('OPENBOOK_FEED_PER_PAGE', 20),
        // Caratteri di testo grezzo (spazi inclusi) mostrati nei feed prima di "Altro...".
        'body_excerpt_length' => (int) env('OPENBOOK_FEED_BODY_EXCERPT', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ricerca locale
    |--------------------------------------------------------------------------
    |
    | Limiti della ricerca per parole chiave su contenuti di questa istanza
    | (persone, post, commenti, hashtag). La risoluzione federata
    | utente@dominio non usa questi valori.
    */
    'search' => [
        'min_length' => (int) env('OPENBOOK_SEARCH_MIN_LENGTH', 2),
        'per_section' => (int) env('OPENBOOK_SEARCH_PER_SECTION', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chiavi crittografiche degli Actor
    |--------------------------------------------------------------------------
    |
    | Dimensione (in bit) delle coppie di chiavi RSA generate per ogni nuovo
    | Actor locale (utenti e, in futuro, community). 2048 bit e' considerato
    | il minimo accettabile per l'interoperabilita' con il resto del Fediverso.
    */
    'actor_key_bits' => (int) env('OPENBOOK_ACTOR_KEY_BITS', 2048),

    /*
    |--------------------------------------------------------------------------
    | Coda federativa (usata dalle fasi successive)
    |--------------------------------------------------------------------------
    |
    | Intervalli di retry con backoff esponenziale per le consegne federate
    | fallite, espressi in minuti. L'ultimo valore rappresenta anche il tetto
    | massimo per i tentativi successivi.
    */
    'delivery' => [
        'retry_intervals_minutes' => [1, 5, 15, 60, 360, 1440],
        'max_attempts' => (int) env('OPENBOOK_DELIVERY_MAX_ATTEMPTS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Federazione ActivityPub
    |--------------------------------------------------------------------------
    |
    | Parametri comuni a firme HTTP, recupero di risorse remote (Actor) e
    | ricezione delle attivita' nell'inbox. Valori prudenti per default,
    | adatti a shared hosting: nessun processo permanente, timeout brevi,
    | corpi di risposta limitati.
    */
    'federation' => [
        // Intestazione User-Agent usata per le richieste HTTP in uscita verso
        // altri server del Fediverso (recupero Actor remoti, consegna delle
        // attivita'): riporta la vera versione del software (vedi sopra),
        // non un valore fisso scollegato da essa.
        'user_agent' => sprintf('Openbook/%s (+%s)', $version, env('APP_URL', 'http://localhost')),

        'http_signature' => [
            // Scarto massimo (in secondi) tollerato tra l'header "Date" di una
            // richiesta firmata e l'orario locale, oltre il quale la firma
            // viene rifiutata anche se crittograficamente valida.
            'max_clock_skew_seconds' => (int) env('OPENBOOK_SIGNATURE_MAX_SKEW', 300),
        ],

        'fetch' => [
            // Numero massimo di ridirezioni HTTP seguite durante il recupero
            // di una risorsa remota (Actor). Ogni destinazione viene
            // rivalidata contro la protezione SSRF.
            'max_redirects' => (int) env('OPENBOOK_FETCH_MAX_REDIRECTS', 3),
            'timeout_seconds' => (int) env('OPENBOOK_FETCH_TIMEOUT', 10),
            'connect_timeout_seconds' => (int) env('OPENBOOK_FETCH_CONNECT_TIMEOUT', 5),
            'max_response_bytes' => (int) env('OPENBOOK_FETCH_MAX_BYTES', 1_000_000),
            // Consente lo schema "http://" soltanto in ambienti non di
            // produzione (test locali con server del Fediverso finti):
            // in produzione e' sempre richiesto HTTPS.
            'allow_insecure' => (bool) env('OPENBOOK_FETCH_ALLOW_INSECURE', false),
            // Firma i GET ActivityPub con la chiave di un Actor locale
            // (authorized fetch). Necessario verso istanze che rifiutano
            // richieste anonime (401). WebFinger resta non firmato.
            'signed' => (bool) env('OPENBOOK_FETCH_SIGNED', true),
        ],

        // Dopo quante ore un Actor remoto gia' in cache viene considerato
        // scaduto e ri-recuperato alla prossima occasione utile.
        'actor_cache_ttl_hours' => (int) env('OPENBOOK_ACTOR_CACHE_TTL_HOURS', 24),

        // Dopo quante ore, visitando la pagina profilo di un Actor remoto,
        // viene ritentato il recupero dei suoi post pubblici piu' recenti
        // dal suo outbox (vedi RemoteOutboxFetcher). Piu' breve della cache
        // dell'Actor stesso perche' i post cambiano piu' spesso del profilo.
        'posts_cache_ttl_hours' => (int) env('OPENBOOK_POSTS_CACHE_TTL_HOURS', 6),

        // Dopo quante ore, aprendo un post remoto, viene ritentato il
        // recupero della collection "replies" della Note originale
        // (vedi RemoteRepliesFetcher).
        'replies_cache_ttl_hours' => (int) env('OPENBOOK_REPLIES_CACHE_TTL_HOURS', 6),

        'inbox' => [
            // Limite applicativo aggiuntivo (difesa in profondita') rispetto
            // a "post_max_size"/"client_max_body_size" del server web, che
            // restano la protezione principale e vanno configurati a livello
            // di hosting (vedi README).
            'max_body_bytes' => (int) env('OPENBOOK_INBOX_MAX_BODY_BYTES', 200_000),
            'max_json_depth' => (int) env('OPENBOOK_INBOX_MAX_JSON_DEPTH', 32),
        ],
    ],
];
