<?php

use App\Http\Controllers\Federation\FollowersController;
use App\Http\Controllers\Federation\FollowingController;
use App\Http\Controllers\Federation\InboxController;
use App\Http\Controllers\Federation\NodeInfoController;
use App\Http\Controllers\Federation\OutboxController;
use App\Http\Controllers\Federation\WebFingerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotte di federazione ActivityPub
|--------------------------------------------------------------------------
|
| Registrate senza il gruppo middleware "web": sono endpoint stateless (mai
| sessione, mai CSRF) consumati da altri server e non da browser, come
| previsto dal design (sezione 17). L'identificatore canonico degli Actor,
| dei post e dei commenti resta invece nelle rotte HTML di "routes/web.php",
| che negoziano il contenuto in base all'header Accept.
*/

Route::get('/.well-known/webfinger', [WebFingerController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('webfinger');

Route::get('/.well-known/nodeinfo', [NodeInfoController::class, 'discovery'])
    ->middleware('throttle:120,1')
    ->name('nodeinfo.discovery');
Route::get('/nodeinfo/2.1', [NodeInfoController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('nodeinfo.show');

Route::post('/users/{username}/inbox', [InboxController::class, 'forUser'])
    ->where('username', '[A-Za-z0-9_]+')
    ->middleware('throttle:60,1')
    ->name('inbox.user');
Route::post('/inbox', [InboxController::class, 'shared'])
    ->middleware('throttle:60,1')
    ->name('inbox.shared');

Route::get('/users/{username}/outbox', [OutboxController::class, 'show'])
    ->where('username', '[A-Za-z0-9_]+')
    ->name('outbox.show');

Route::get('/users/{username}/followers', [FollowersController::class, 'show'])
    ->where('username', '[A-Za-z0-9_]+')
    ->name('followers.show');

Route::get('/users/{username}/following', [FollowingController::class, 'show'])
    ->where('username', '[A-Za-z0-9_]+')
    ->name('following.show');
