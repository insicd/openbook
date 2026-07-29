<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | I documenti ActivityPub (Actor, Note, collezioni, WebFinger, NodeInfo)
    | sono pensati per essere pubblici e leggibili da chiunque, incluso da
    | JavaScript eseguito su un dominio diverso (verificatori del software
    | federato, anteprime di profilo, debugger ActivityPub): senza
    | un'intestazione CORS esplicita il browser bloccherebbe comunque quella
    | lettura cross-origin lato client, anche se il documento e' comunque
    | pubblicamente raggiungibile da qualunque server (e' una restrizione
    | imposta solo dai browser, mai dagli altri server del Fediverso, che
    | infatti non ne risentono). Stessa logica di "robots.txt"/"sitemap.xml":
    | e' un documento pubblico, sempre, indipendentemente da chi lo richiede.
    |
    | "users/*" copre outbox/followers/following; "@*" copre il profilo
    | canonico di un Actor locale (content negotiation su Accept) e i suoi
    | elenchi follower/seguiti; "posts/*" e "comments/*" coprono
    | l'identificatore canonico di post e commenti (idem).
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        '.well-known/*',
        'nodeinfo/*',
        'users/*',
        '@*',
        'posts/*',
        'comments/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
