<?php

return [

    'app' => [
        'tagline' => 'Il social network federato, semplice e libero.',
    ],

    'lightbox' => [
        'label' => 'Immagine ingrandita',
        'close' => 'Chiudi',
        'previous' => 'Immagine precedente',
        'next' => 'Immagine successiva',
    ],

    'infinite_scroll' => [
        'loading' => 'Caricamento altri post...',
        'end' => 'Non ci sono altri post da mostrare.',
        'error' => 'Impossibile caricare altri post. Riprova ricaricando la pagina.',
    ],

    'nav' => [
        'home' => 'Home',
        'world' => 'Mondo',
        'profile' => 'Profilo',
        'notifications' => 'Notifiche',
        'search' => 'Cerca',
        'settings' => 'Impostazioni',
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
        'no_posts_yet' => 'Non ci sono ancora post da mostrare.',
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
        'menu' => 'Altre azioni sul post',
        'open_original' => 'Apri il post originale',
    ],

    'actions' => [
        'like' => 'Mi piace (:count)',
        'liked' => 'Ti piace (:count)',
        'comment' => 'Commenta (:count)',
        'comment_submit' => 'Pubblica commento',
        'announce' => 'Condividi (:count)',
        'announced' => 'Condiviso (:count)',
        'shared_this' => 'ha condiviso questo post',
        'reply' => 'Rispondi',
        'delete' => 'Elimina',
    ],

    'comments' => [
        'title' => 'Commenti (:count)',
        'new_label' => 'Scrivi un commento',
        'empty' => 'Nessun commento. Sii il primo a commentare.',
        'deleted' => 'Questo commento e stato eliminato.',
        'confirm_delete' => 'Vuoi davvero eliminare questo commento?',
        'menu' => 'Altre azioni sul commento',
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
        'title' => 'Cerca',
        'placeholder' => 'Parola chiave, oppure utente@dominio',
        'help' => 'Cerca tra i contenuti di questa istanza (persone, post, commenti, hashtag), oppure inserisci un indirizzo federato (es. persona@altraistanza.social) per trovare qualcuno sul Fediverso.',
        'submit' => 'Cerca',
        'people' => 'Persone',
        'posts' => 'Post',
        'comments' => 'Commenti',
        'hashtags' => 'Hashtag',
        'empty' => 'Nessun risultato locale per ":query".',
        'view_in_post' => 'Vedi nel post',
        'errors' => [
            'too_short' => 'Inserisci almeno :min caratteri.',
            'local_not_found' => 'Nessun account locale trovato con questo indirizzo.',
            'remote_not_found' => 'Nessun account trovato a questo indirizzo, o il server remoto non risponde.',
        ],
    ],

    'actors' => [
        'remote_notice' => 'Profilo remoto: i dati mostrati sono quelli ricevuti dal server di origine e potrebbero non essere aggiornati in tempo reale.',
    ],

    'settings' => [
        'title' => 'Impostazioni',
        'edit_profile' => 'Modifica profilo',
        'save' => 'Salva',
        'profile_updated' => 'Profilo aggiornato.',
        'account_updated' => 'Preferenze aggiornate.',
        'profile_section_title' => 'Profilo pubblico',
        'avatar_label' => 'Immagine del profilo',
        'image_preview_help' => 'Dopo aver scelto un file ne vedrai subito un\'anteprima, anche prima di salvare.',
        'cover_label' => 'Immagine di copertina',
        'display_name_label' => 'Nome visualizzato',
        'bio_label' => 'Biografia',
        'links_label' => 'Link',
        'link_label_placeholder' => 'Etichetta (es. Sito web)',
        'links_help' => 'Fino a 4 link mostrati sul tuo profilo pubblico.',
        'account_section_title' => 'Account e privacy',
        'locale_label' => 'Lingua dell\'interfaccia',
        'default_visibility_label' => 'Visibilita predefinita dei nuovi post',
        'protected_account_label' => 'Account protetto',
        'protected_account_help' => 'Se attivo, le nuove richieste di follow restano in attesa finche non le accetti manualmente.',
        'discoverable_label' => 'Includi il mio account nei suggerimenti e nelle ricerche',
        'discoverable_help' => 'Se disattivato, non comparirai tra "Persone da seguire" ne nei risultati di ricerca di questa istanza (resti comunque raggiungibile tramite il tuo indirizzo diretto).',
    ],

    'follows' => [
        'followers_title' => 'Follower di :name',
        'following_title' => 'Seguiti da :name',
        'back_to_profile' => 'Torna al profilo',
        'empty_followers' => 'Nessun follower per ora.',
        'empty_following' => 'Non sta ancora seguendo nessuno.',
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
        'hashtag_uses' => '{1} :count post|[2,*] :count post',
        'no_popular_hashtags' => 'Ancora nessun tag usato su questa istanza.',
        'people_to_follow' => 'Persone da seguire',
    ],

    'world' => [
        'title' => 'Mondo',
        'subtitle' => 'Post pubblici arrivati da altre istanze del fediverso verso questa piattaforma: solo cio\' che e\' gia\' rilevante qui (autori seguiti, risposte, menzioni), non un indice completo del fediverso.',
        'suggested_title' => 'Da scoprire nel fediverso',
        'empty' => 'Ancora nessun post dal resto del fediverso. Segui qualcuno su un\'altra istanza per iniziare a vederne i contenuti qui.',
    ],

    'footer' => [
        'license' => 'software libero sotto licenza AGPL-3.0-or-later',
    ],

];
