<?php

use App\Http\Controllers\Rest\V1\MeController;
use App\Http\Controllers\Rest\V1\PersonController;
use App\Http\Controllers\Rest\V1\PostController;
use Illuminate\Support\Facades\Route;

Route::get('/me', [MeController::class, 'show'])->name('rest.v1.me.show');
Route::get('/posts', [PostController::class, 'index'])->name('rest.v1.posts.index');
Route::get('/persons/{personId}', [PersonController::class, 'show'])->name('rest.v1.persons.show');
Route::get('/persons/{personId}/followers', [PersonController::class, 'followers'])->name('rest.v1.persons.followers');
Route::get('/persons/{personId}/following', [PersonController::class, 'following'])->name('rest.v1.persons.following');
