<?php

return [

    'app' => [
        'tagline' => 'Il social network federato, semplice e libero.',
    ],

    'nav' => [
        'home' => 'Home',
        'profile' => 'Profilo',
        'notifications' => 'Notifiche',
        'search' => 'Cerca',
        'login' => 'Accedi',
        'register' => 'Registrati',
        'logout' => 'Esci',
    ],

    'home' => [
        'hero_title' => 'Benvenuto su :app',
        'hero_subtitle' => 'Un social network generalista, federato con il Fediverso: parla con chi vuoi, ovunque si trovi.',
        'cta_register' => 'Crea un account',
        'cta_login' => 'Ho gia un account',
        'instance_about_title' => 'Questa istanza',
    ],

    'auth' => [
        'register_title' => 'Crea il tuo account',
        'login_title' => 'Accedi a :app',
        'username' => 'Nome utente',
        'username_help' => 'Solo lettere minuscole, numeri e underscore. Sara visibile come :handle',
        'email' => 'Indirizzo email',
        'password' => 'Password',
        'password_confirmation' => 'Conferma password',
        'login_identifier' => 'Nome utente o email',
        'remember_me' => 'Resta connesso',
        'submit_register' => 'Registrati',
        'submit_login' => 'Accedi',
        'already_registered' => 'Hai gia un account?',
        'not_registered' => 'Non hai ancora un account?',
    ],

    'verify_email' => [
        'title' => 'Verifica il tuo indirizzo email',
        'body' => 'Prima di continuare, controlla la tua casella di posta: ti abbiamo inviato un link di verifica.',
        'resend' => 'Non hai ricevuto nulla? Invia di nuovo l\'email',
        'sent' => 'Una nuova email di verifica e stata inviata.',
    ],

    'profile' => [
        'followers' => 'Follower',
        'following' => 'Seguiti',
        'communities' => 'Community',
        'joined_on' => 'Iscritto dal :date',
        'protected' => 'Account protetto',
        'pinned_posts' => 'Post fissati',
        'no_posts_yet' => 'Non ci sono ancora post da mostrare. Questa funzione arrivera nella prossima fase di sviluppo.',
    ],

    'feed' => [
        'welcome_title' => 'Sei connesso, :name',
        'welcome_body' => 'Il feed con i post delle persone e delle community che segui arrivera nella prossima fase di sviluppo di Openbook. Per ora puoi visitare il tuo profilo pubblico.',
        'view_profile' => 'Vai al tuo profilo',
        'empty' => 'Non ci sono ancora post da mostrare. Segui altre persone o pubblica il tuo primo post.',
    ],

    'composer' => [
        'body_label' => 'Cosa vuoi condividere?',
        'placeholder' => 'Scrivi qualcosa...',
        'title_label' => 'Titolo (facoltativo)',
        'cw_label' => 'Avviso sul contenuto (facoltativo)',
        'cw_placeholder' => 'Es. spoiler, argomento sensibile...',
        'images_label' => 'Immagini',
        'images_help' => 'Fino a :count immagini per post (JPEG, PNG, WebP o GIF).',
        'alt_label' => 'Testo alternativo per la prima immagine',
        'alt_help' => 'Descrivi il contenuto dell\'immagine per chi usa uno screen reader.',
        'visibility_label' => 'Visibilita',
        'submit' => 'Pubblica',
    ],

    'visibility' => [
        'public' => 'Pubblica',
        'unlisted' => 'Non elencata',
        'followers' => 'Solo follower',
        'direct' => 'Diretta (solo persone menzionate)',
    ],

    'posts' => [
        'page_title' => 'Post di :name',
        'edited' => 'modificato',
        'deleted' => 'Questo post e stato eliminato.',
        'content_warning_label' => 'Avviso sul contenuto',
        'confirm_delete' => 'Vuoi davvero eliminare questo post?',
    ],

    'actions' => [
        'like' => 'Mi piace (:count)',
        'liked' => 'Ti piace (:count)',
        'comment' => 'Commenta (:count)',
        'comment_submit' => 'Pubblica commento',
        'announce' => 'Condividi (:count)',
        'announced' => 'Condiviso (:count)',
        'reply' => 'Rispondi',
        'delete' => 'Elimina',
    ],

    'comments' => [
        'title' => 'Commenti (:count)',
        'new_label' => 'Scrivi un commento',
        'empty' => 'Nessun commento. Sii il primo a commentare.',
        'deleted' => 'Questo commento e stato eliminato.',
        'confirm_delete' => 'Vuoi davvero eliminare questo commento?',
        'reply_to' => 'Rispondi a :name',
        'login_to_comment' => 'Accedi per lasciare un commento.',
    ],

    'follow' => [
        'follow' => 'Segui',
        'unfollow' => 'Smetti di seguire',
        'cancel_request' => 'Annulla richiesta',
        'pending_requests' => 'Richieste di follow in attesa',
        'accept' => 'Accetta',
        'reject' => 'Rifiuta',
        'requested' => 'Richiesta inviata',
    ],

    'search' => [
        'title' => 'Cerca sul Fediverso',
        'placeholder' => 'utente@dominio o URL del profilo',
        'help' => 'Inserisci l\'indirizzo federato di una persona (es. persona@altraistanza.social) per trovarla, vedere il suo profilo e seguirla, anche se non fa parte di questa istanza.',
        'submit' => 'Cerca',
    ],

    'actors' => [
        'remote_notice' => 'Profilo remoto: i dati mostrati sono quelli ricevuti dal server di origine e potrebbero non essere aggiornati in tempo reale.',
    ],

    'notifications' => [
        'title' => 'Notifiche',
        'empty' => 'Non hai ancora notifiche.',
        'view' => 'Vedi',
        'someone' => 'Qualcuno',
        'messages' => [
            'new_follower' => ':name ha iniziato a seguirti.',
            'follow_request' => ':name ha richiesto di seguirti.',
            'follow_accepted' => ':name ha accettato la tua richiesta di follow.',
            'like' => 'A :name piace un tuo contenuto.',
            'comment' => ':name ha commentato il tuo post.',
            'reply' => ':name ha risposto al tuo commento.',
            'mention' => ':name ti ha menzionato.',
            'share' => ':name ha condiviso il tuo post.',
        ],
    ],

    'hashtags' => [
        'empty' => 'Nessun post pubblico con questo hashtag, per ora.',
    ],

    'sidebar' => [
        'instance_title' => 'Questa istanza',
        'members_count' => '{0} Nessun membro iscritto|{1} :count membro iscritto|[2,*] :count membri iscritti',
        'people_to_follow' => 'Persone da seguire',
    ],

];
