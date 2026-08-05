<?php

namespace App\Http\Controllers;

use App\Application\Services\ReactionManager;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function __construct(
        private readonly ReactionManager $reactions,
    ) {}

    public function likePost(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        $this->reactions->like(auth()->user()->actor, $post);

        return $this->respond($request, $post->fresh(), liked: true, fragment: 'post-'.$post->id);
    }

    public function unlikePost(Request $request, Post $post): RedirectResponse|JsonResponse
    {
        $this->reactions->unlike(auth()->user()->actor, $post);

        return $this->respond($request, $post->fresh(), liked: false, fragment: 'post-'.$post->id);
    }

    public function likeComment(Request $request, Comment $comment): RedirectResponse|JsonResponse
    {
        $this->reactions->like(auth()->user()->actor, $comment);

        return $this->respond($request, $comment->fresh(), liked: true, fragment: 'commento-'.$comment->id);
    }

    public function unlikeComment(Request $request, Comment $comment): RedirectResponse|JsonResponse
    {
        $this->reactions->unlike(auth()->user()->actor, $comment);

        return $this->respond($request, $comment->fresh(), liked: false, fragment: 'commento-'.$comment->id);
    }

    private function respond(Request $request, Post|Comment $target, bool $liked, string $fragment): RedirectResponse|JsonResponse
    {
        $count = (int) $target->likes_count;

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'liked' => $liked,
                'likes_count' => $count,
                'label' => __($liked ? 'openbook.actions.liked' : 'openbook.actions.like', [
                    'count' => $count,
                ]),
            ]);
        }

        return back()->withFragment($fragment);
    }
}
