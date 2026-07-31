<?php

use App\Http\Controllers\ActorProfileController;
use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DomainBlockController as AdminDomainBlockController;
use App\Http\Controllers\Admin\QueueController as AdminQueueController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AnnounceController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\HashtagController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InstanceRulesController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WorldController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/regole', [InstanceRulesController::class, 'show'])->name('instance.rules');

Route::middleware('guest')->group(function () {
    Route::get('/registrati', [RegisterController::class, 'create'])->name('register');
    Route::post('/registrati', [RegisterController::class, 'store']);

    Route::get('/accedi', [SessionController::class, 'create'])->name('login');
    Route::post('/accedi', [SessionController::class, 'store'])
        ->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/esci', [SessionController::class, 'destroy'])->name('logout');

    Route::get('/home', [FeedController::class, 'index'])->name('feed.index');
    Route::get('/mondo', [WorldController::class, 'index'])->name('world.index');

    Route::get('/email/verifica', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    Route::get('/email/verifica/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');

    Route::post('/email/verifica/invia', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::post('/pubblica', [PostController::class, 'store'])->name('posts.store');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

    Route::post('/posts/{post}/commenti', [CommentController::class, 'store'])->name('comments.store');
    Route::delete('/commenti/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

    Route::post('/posts/{post}/mi-piace', [LikeController::class, 'likePost'])->name('posts.like');
    Route::delete('/posts/{post}/mi-piace', [LikeController::class, 'unlikePost'])->name('posts.unlike');
    Route::post('/commenti/{comment}/mi-piace', [LikeController::class, 'likeComment'])->name('comments.like');
    Route::delete('/commenti/{comment}/mi-piace', [LikeController::class, 'unlikeComment'])->name('comments.unlike');

    Route::post('/posts/{post}/condividi', [AnnounceController::class, 'store'])->name('posts.announce');
    Route::delete('/posts/{post}/condividi', [AnnounceController::class, 'destroy'])->name('posts.unannounce');
    Route::get('/posts/{post}/cita', [PostController::class, 'quote'])->name('posts.quote');
    Route::get('/posts/{post}/segnala', [ReportController::class, 'create'])->name('posts.report.create');
    Route::post('/posts/{post}/segnala', [ReportController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('posts.report.store');
    Route::get('/commenti/{comment}/segnala', [ReportController::class, 'createForComment'])->name('comments.report.create');
    Route::post('/commenti/{comment}/segnala', [ReportController::class, 'storeForComment'])
        ->middleware('throttle:10,1')
        ->name('comments.report.store');

    Route::post('/@{user:username}/segui', [FollowController::class, 'store'])->name('follow.store');
    Route::delete('/@{user:username}/segui', [FollowController::class, 'destroy'])->name('follow.destroy');
    Route::post('/richieste-di-follow/{follow}/accetta', [FollowController::class, 'accept'])->name('follow.accept');
    Route::post('/richieste-di-follow/{follow}/rifiuta', [FollowController::class, 'reject'])->name('follow.reject');

    Route::post('/attori/{actor}/segui', [FollowController::class, 'storeForActor'])->name('actors.follow');
    Route::delete('/attori/{actor}/segui', [FollowController::class, 'destroyForActor'])->name('actors.unfollow');

    Route::get('/cerca', [SearchController::class, 'create'])
        ->middleware('throttle:30,1')
        ->name('search.create');

    Route::get('/notifiche', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifiche/feed', [NotificationController::class, 'feed'])
        ->middleware('throttle:60,1')
        ->name('notifications.feed');
    Route::post('/notifiche/segna-lette', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    Route::get('/impostazioni', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/impostazioni/profilo', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');
    Route::put('/impostazioni/account', [SettingsController::class, 'updateAccount'])->name('settings.account.update');

    Route::get('/community/nuova', [CommunityController::class, 'create'])->name('communities.create');
    Route::post('/community', [CommunityController::class, 'store'])->name('communities.store');
    Route::post('/c/{community:slug}/iscriviti', [CommunityController::class, 'join'])->name('communities.join');
    Route::delete('/c/{community:slug}/iscriviti', [CommunityController::class, 'leave'])->name('communities.leave');
    Route::post('/c/{community:slug}/richieste/{follow}/accetta', [CommunityController::class, 'accept'])->name('communities.accept');
    Route::post('/c/{community:slug}/richieste/{follow}/rifiuta', [CommunityController::class, 'reject'])->name('communities.reject');
    Route::post('/c/{community:slug}/moderatori', [CommunityController::class, 'storeModerator'])->name('communities.moderators.store');
    Route::delete('/c/{community:slug}/moderatori/{user}', [CommunityController::class, 'destroyModerator'])->name('communities.moderators.destroy');

    Route::middleware('staff')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('/segnalazioni', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('/segnalazioni/{report}', [AdminReportController::class, 'show'])->name('reports.show');
        Route::post('/segnalazioni/{report}/revisionata', [AdminReportController::class, 'review'])->name('reports.review');
        Route::post('/segnalazioni/{report}/archiviata', [AdminReportController::class, 'dismiss'])->name('reports.dismiss');
        Route::post('/segnalazioni/{report}/azione', [AdminReportController::class, 'action'])->name('reports.action');

        Route::get('/utenti', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/utenti/{user}/sospendi', [AdminUserController::class, 'suspend'])->name('users.suspend');
        Route::post('/utenti/{user}/riattiva', [AdminUserController::class, 'unsuspend'])->name('users.unsuspend');
        Route::post('/utenti/{user}/disabilita', [AdminUserController::class, 'disable'])->name('users.disable');

        Route::middleware('admin')->group(function () {
            Route::post('/utenti/{user}/promuovi-moderatore', [AdminUserController::class, 'promoteModerator'])->name('users.promote_moderator');
            Route::post('/utenti/{user}/rimuovi-moderatore', [AdminUserController::class, 'demoteModerator'])->name('users.demote_moderator');
            Route::post('/utenti/{user}/promuovi-admin', [AdminUserController::class, 'promoteAdmin'])->name('users.promote_admin');
            Route::post('/utenti/{user}/rimuovi-admin', [AdminUserController::class, 'demoteAdmin'])->name('users.demote_admin');

            Route::get('/impostazioni', [AdminSettingsController::class, 'edit'])->name('settings.edit');
            Route::put('/impostazioni', [AdminSettingsController::class, 'update'])->name('settings.update');

            Route::get('/domini', [AdminDomainBlockController::class, 'index'])->name('domain_blocks.index');
            Route::post('/domini', [AdminDomainBlockController::class, 'store'])->name('domain_blocks.store');
            Route::delete('/domini/{domainBlock}', [AdminDomainBlockController::class, 'destroy'])->name('domain_blocks.destroy');

            Route::get('/coda', [AdminQueueController::class, 'index'])->name('queue.index');
            Route::post('/coda/falliti/{uuid}/riprova', [AdminQueueController::class, 'retryFailed'])->name('queue.retry');
            Route::post('/coda/falliti/{uuid}/elimina', [AdminQueueController::class, 'forgetFailed'])->name('queue.forget');
            Route::post('/coda/falliti/riprova-tutti', [AdminQueueController::class, 'retryAllFailed'])->name('queue.retry_all');

            Route::get('/audit', [AdminAuditLogController::class, 'index'])->name('audit.index');
        });
    });
});

// Identificatore canonico di un post: HTML oppure, tramite content
// negotiation, l'oggetto ActivityStreams "Note"/"Tombstone".
Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');

// Identificatore canonico di un commento (redirect al permalink sul post per
// i browser, oggetto "Note" con "inReplyTo" per i client ActivityPub).
Route::get('/comments/{comment}', [CommentController::class, 'show'])->name('comments.show');

Route::get('/tendenze', [HashtagController::class, 'index'])->name('hashtags.index');
Route::get('/tag/{name}', [HashtagController::class, 'show'])->name('hashtags.show');

Route::get('/community', [CommunityController::class, 'index'])->name('communities.index');
Route::get('/c/{community:slug}', [CommunityController::class, 'show'])
    ->where('community', '[A-Za-z0-9_]+')
    ->name('communities.show');

// Identificatore canonico dell'Actor locale (Person HTML / Group redirect o AP).
Route::get('/@{username}', [ProfileController::class, 'show'])
    ->where('username', '[A-Za-z0-9_]+')
    ->name('profile.show');

// Elenchi follower/seguiti e rullino fotografico di un profilo locale Person.
Route::get('/@{user:username}/follower', [ProfileController::class, 'followers'])->name('profile.followers');
Route::get('/@{user:username}/seguiti', [ProfileController::class, 'following'])->name('profile.following');
Route::get('/@{username}/foto', [ProfileController::class, 'photos'])
    ->where('username', '[A-Za-z0-9_]+')
    ->name('profile.photos');

// Pagina profilo di comodo per un Actor remoto in cache locale (mai un
// identificatore ActivityPub: reindirizza al profilo locale se l'id
// corrisponde in realta' a un attore di questa istanza).
Route::middleware('auth')->group(function () {
    Route::get('/attori/{actor}', [ActorProfileController::class, 'show'])->name('actors.show');
    Route::get('/attori/{actor}/follower', [ActorProfileController::class, 'followers'])->name('actors.followers');
    Route::get('/attori/{actor}/seguiti', [ActorProfileController::class, 'following'])->name('actors.following');
    Route::get('/attori/{actor}/foto', [ActorProfileController::class, 'photos'])->name('actors.photos');
});

// URL alternativo, non canonico: redirect permanente verso "/@{username}".
Route::get('/users/{username}', [ProfileController::class, 'redirectLegacy'])
    ->where('username', '[A-Za-z0-9_]+')
    ->name('profile.redirect');
