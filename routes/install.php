<?php

use App\Http\Controllers\Install\InstallController;
use App\Http\Middleware\RedirectIfInstalled;
use Illuminate\Support\Facades\Route;

Route::middleware(RedirectIfInstalled::class)->prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'requirements'])->name('requirements');

    Route::get('/database', [InstallController::class, 'showDatabase'])->name('database');
    Route::post('/database', [InstallController::class, 'storeDatabase'])->name('database.store');

    Route::get('/instance', [InstallController::class, 'showInstance'])->name('instance');
    Route::post('/instance', [InstallController::class, 'storeInstance'])->name('instance.store');
});
