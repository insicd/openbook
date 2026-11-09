<?php

use App\Http\Controllers\Rest\V1\FollowRequestController;
use App\Http\Controllers\Rest\V1\InstanceController;
use App\Http\Controllers\Rest\V1\MeController;
use App\Http\Controllers\Rest\V1\MePostController;
use App\Http\Controllers\Rest\V1\PersonController;
use App\Http\Controllers\Rest\V1\PersonFollowController;
use App\Http\Controllers\Rest\V1\PersonPostController;
use App\Http\Controllers\Rest\V1\PostAnnounceController;
use App\Http\Controllers\Rest\V1\PostCommentController;
use App\Http\Controllers\Rest\V1\PostController;
use App\Http\Controllers\Rest\V1\PostPublicationController;
use Illuminate\Support\Facades\Route;

Route::get('/instance', [InstanceController::class, 'show'])->name('rest.v1.instance.show');
Route::get('/me', [MeController::class, 'show'])->name('rest.v1.me.show');
Route::get('/me/posts', [MePostController::class, 'index'])->name('rest.v1.me.posts');
Route::get('/posts', [PostController::class, 'index'])->name('rest.v1.posts.index');
Route::post('/posts', [PostController::class, 'store'])->name('rest.v1.posts.store');
Route::get('/posts/{postId}', [PostController::class, 'show'])->name('rest.v1.posts.show');
Route::patch('/posts/{postId}', [PostController::class, 'update'])->name('rest.v1.posts.update');
Route::delete('/posts/{postId}', [PostController::class, 'destroy'])->name('rest.v1.posts.destroy');
Route::put('/posts/{postId}/like', [PostController::class, 'like'])->name('rest.v1.posts.like');
Route::delete('/posts/{postId}/like', [PostController::class, 'unlike'])->name('rest.v1.posts.unlike');
Route::get('/persons/{personId}', [PersonController::class, 'show'])->name('rest.v1.persons.show');
Route::get('/persons/{personId}/follow', [PersonFollowController::class, 'show'])->name('rest.v1.persons.follow.show');
Route::put('/persons/{personId}/follow', [PersonFollowController::class, 'store'])->name('rest.v1.persons.follow.store');
Route::delete('/persons/{personId}/follow', [PersonFollowController::class, 'destroy'])->name('rest.v1.persons.follow.destroy');
Route::get('/persons/{personId}/posts', [PersonPostController::class, 'index'])->name('rest.v1.persons.posts');
Route::get('/persons/{personId}/followers', [PersonController::class, 'followers'])->name('rest.v1.persons.followers');
Route::get('/persons/{personId}/following', [PersonController::class, 'following'])->name('rest.v1.persons.following');

Route::put('/posts/{postId}/announce', [PostAnnounceController::class, 'store'])->name('rest.v1.posts.announce');
Route::delete('/posts/{postId}/announce', [PostAnnounceController::class, 'destroy'])->name('rest.v1.posts.unannounce');

Route::get('/me/follow-requests', [FollowRequestController::class, 'index'])->name('rest.v1.me.follow-requests.index');
Route::patch('/me/follow-requests/{personId}', [FollowRequestController::class, 'update'])->name('rest.v1.me.follow-requests.update');
Route::delete('/me/follow-requests/{personId}', [FollowRequestController::class, 'destroy'])->name('rest.v1.me.follow-requests.destroy');

Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->name('rest.v1.comments.index');
Route::post('/posts/{postId}/comments', [PostCommentController::class, 'store'])->name('rest.v1.comments.store');
Route::get('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'show'])->name('rest.v1.comments.show');
Route::patch('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'update'])->name('rest.v1.comments.update');
Route::delete('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'destroy'])->name('rest.v1.comments.destroy');

Route::get('/postPublication/{postPublicationId}', [PostPublicationController::class, 'show'])->name('rest.v1.postPublication.show');
Route::delete('/postPublication/{postPublicationId}', [PostPublicationController::class, 'destroy'])->name('rest.v1.postPublication.destroy');
