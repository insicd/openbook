<?php

use App\Http\Controllers\Rest\V1\PersonController;
use Illuminate\Support\Facades\Route;

Route::get('/persons/{personId}', [PersonController::class, 'show'])->name('rest.v1.persons.show');
Route::get('/persons/{personId}/followers', [PersonController::class, 'followers'])->name('rest.v1.persons.followers');
Route::get('/persons/{personId}/following', [PersonController::class, 'following'])->name('rest.v1.persons.following');
