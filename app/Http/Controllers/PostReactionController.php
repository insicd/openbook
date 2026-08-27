<?php

namespace App\Http\Controllers;

use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Domain\Reactions\Like;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Elenchi di chi ha messo Mi piace o ha condiviso un post: JSON per il
 * dropdown sulla card, HTML per chi non ha JavaScript.
 */
class PostReactionController extends Controller
{
    private const LIST_LIMIT = 40;

    public function likes(Request $request, Post $post): View|JsonResponse
    {
        $this->assertVisible($post);

        $actors = $post->likes()
            ->with(['actor.user.profile'])
            ->latest()
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Like $like) => $like->actor)
            ->filter()
            ->values();

        return $this->respond(
            $request,
            $post,
            __('openbook.actions.likes_list_title'),
            (int) $post->likes_count,
            $actors,
        );
    }

    public function announces(Request $request, Post $post): View|JsonResponse
    {
        $this->assertVisible($post);

        $actors = $post->announces()
            ->with(['actor.user.profile'])
            ->latest()
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Announce $announce) => $announce->actor)
            ->filter()
            ->values();

        return $this->respond(
            $request,
            $post,
            __('openbook.actions.announces_list_title'),
            (int) $post->announces_count,
            $actors,
        );
    }

    private function assertVisible(Post $post): void
    {
        $viewer = auth()->user()?->actor;

        abort_unless(
            Post::query()->whereKey($post->id)->visibleTo($viewer)->exists(),
            404,
        );
    }

    /**
     * @param  Collection<int, Actor>  $actors
     */
    private function respond(
        Request $request,
        Post $post,
        string $title,
        int $total,
        Collection $actors,
    ): View|JsonResponse {
        $remaining = max(0, $total - $actors->count());

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'title' => $title,
                'total' => $total,
                'remaining' => $remaining,
                'actors' => $actors->map(fn (Actor $actor) => [
                    'name' => $actor->displayName(),
                    'handle' => '@'.$actor->handle(),
                    'url' => $actor->profileUrl(),
                    'avatar_url' => $actor->avatarUrl(),
                ])->all(),
            ]);
        }

        return view('posts.reactions', [
            'post' => $post,
            'title' => $title,
            'total' => $total,
            'remaining' => $remaining,
            'actors' => $actors,
        ]);
    }
}
