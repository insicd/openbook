<?php

namespace App\Http\Controllers;

use App\Application\Services\ReactionManager;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use Illuminate\Http\RedirectResponse;

class LikeController extends Controller
{
    public function __construct(
        private readonly ReactionManager $reactions,
    ) {}

    public function likePost(Post $post): RedirectResponse
    {
        $this->reactions->like(auth()->user()->actor, $post);

        return back();
    }

    public function unlikePost(Post $post): RedirectResponse
    {
        $this->reactions->unlike(auth()->user()->actor, $post);

        return back();
    }

    public function likeComment(Comment $comment): RedirectResponse
    {
        $this->reactions->like(auth()->user()->actor, $comment);

        return back();
    }

    public function unlikeComment(Comment $comment): RedirectResponse
    {
        $this->reactions->unlike(auth()->user()->actor, $comment);

        return back();
    }
}
