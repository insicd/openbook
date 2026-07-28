<?php

use App\Http\Controllers\CronController;
use Illuminate\Support\Facades\Route;

// Nessun gruppo "web": e' un endpoint stateless richiamato da un client HTTP
// esterno (cron-job.org, wget, curl...), non da un browser autenticato.
Route::get('/cron/run', [CronController::class, 'run'])
    ->middleware('throttle:12,1')
    ->name('cron.run');
