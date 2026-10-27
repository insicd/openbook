<?php

namespace App\Http\Controllers\Rest\V1;

use App\Application\Services\AnnounceManager;
use App\Domain\Posts\Post;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class PostAnnounceController extends Controller
{
    public function store(Request $request, string $postId, AnnounceManager $announces): Response
    {
        $created = DB::transaction(function () use ($request, $postId, $announces): bool {
            $post = $this->publishedPost($postId);
            $actor = $request->user()->actor;
            abort_unless(Post::query()->whereKey($post->id)->visibleTo($actor)->exists(), 404);
            abort_unless(in_array($post->visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED], true)
                && ! $post->isInPrivateCommunity(), 403);
            $alreadyDirect = $announces->hasDirectAnnounced($actor, $post);
            $announces->announce($actor, $post, direct: true);

            return ! $alreadyDirect;
        });

        return $created
            ? response('', 201, ['Location' => $request->url(), 'Cache-Control' => 'no-store'])
            : response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $postId, AnnounceManager $announces): Response
    {
        DB::transaction(function () use ($request, $postId, $announces): void {
            $post = $this->publishedPost($postId);
            $actor = $request->user()->actor;
            // An existing manual share can be removed even if the original is no longer visible.
            abort_unless($announces->hasDirectAnnounced($actor, $post)
                || Post::query()->whereKey($post->id)->visibleTo($actor)->exists(), 404);
            $announces->unannounce($actor, $post);
        });

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function publishedPost(string $postId): Post
    {
        abort_unless(Str::isUuid($postId), 404);
        $post = Post::query()->whereKey($postId)->excludingPrivateMessages()
            ->where('status', Post::STATUS_PUBLISHED)->lockForUpdate()->first();
        abort_if($post === null, 404);

        return $post;
    }
}
