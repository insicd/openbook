<?php

use App\Http\Controllers\ActorProfileController;
use App\Http\Controllers\AnnounceController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\HashtagController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

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

    Route::post('/@{user:username}/segui', [FollowController::class, 'store'])->name('follow.store');
    Route::delete('/@{user:username}/segui', [FollowController::class, 'destroy'])->name('follow.destroy');
    Route::post('/richieste-di-follow/{follow}/accetta', [FollowController::class, 'accept'])->name('follow.accept');
    Route::post('/richieste-di-follow/{follow}/rifiuta', [FollowController::class, 'reject'])->name('follow.reject');

    Route::post('/attori/{actor}/segui', [FollowController::class, 'storeForActor'])->name('actors.follow');
    Route::delete('/attori/{actor}/segui', [FollowController::class, 'destroyForActor'])->name('actors.unfollow');

    Route::get('/cerca', [SearchController::class, 'create'])->name('search.create');
    Route::post('/cerca', [SearchController::class, 'search'])
        ->middleware('throttle:20,1')
        ->name('search.perform');

    Route::get('/notifiche', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifiche/segna-lette', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    Route::get('/impostazioni', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/impostazioni/profilo', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');
    Route::put('/impostazioni/account', [SettingsController::class, 'updateAccount'])->name('settings.account.update');
});

// Identificatore canonico di un post: HTML oppure, tramite content
// negotiation, l'oggetto ActivityStreams "Note"/"Tombstone".
Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');

// Identificatore canonico di un commento (redirect al permalink sul post per
// i browser, oggetto "Note" con "inReplyTo" per i client ActivityPub).
Route::get('/comments/{comment}', [CommentController::class, 'show'])->name('comments.show');

Route::get('/tag/{name}', [HashtagController::class, 'show'])->name('hashtags.show');

// Identificatore canonico dell'Actor locale (HTML e, in futuro, ActivityPub).
Route::get('/@{user:username}', [ProfileController::class, 'show'])->name('profile.show');

// Elenchi follower/seguiti di un profilo locale: stessa visibilita' pubblica
// della pagina profilo (vedi sopra).
Route::get('/@{user:username}/follower', [ProfileController::class, 'followers'])->name('profile.followers');
Route::get('/@{user:username}/seguiti', [ProfileController::class, 'following'])->name('profile.following');

// Pagina profilo di comodo per un Actor remoto in cache locale (mai un
// identificatore ActivityPub: reindirizza al profilo locale se l'id
// corrisponde in realta' a un attore di questa istanza).
Route::middleware('auth')->group(function () {
    Route::get('/attori/{actor}', [ActorProfileController::class, 'show'])->name('actors.show');
    Route::get('/attori/{actor}/follower', [ActorProfileController::class, 'followers'])->name('actors.followers');
    Route::get('/attori/{actor}/seguiti', [ActorProfileController::class, 'following'])->name('actors.following');
});

// URL alternativo, non canonico: redirect permanente verso "/@{username}".
Route::get('/users/{username}', [ProfileController::class, 'redirectLegacy'])
    ->where('username', '[A-Za-z0-9_]+')
    ->name('profile.redirect');
