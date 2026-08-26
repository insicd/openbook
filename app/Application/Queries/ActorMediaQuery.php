<?php

namespace App\Application\Queries;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Media;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Galleria fotografica di un Actor: immagini allegate ai suoi post
 * pubblicati e visibili al visitatore (locali su disco o remote via URL).
 */
final class ActorMediaQuery
{
    public function forActor(Actor $actor, ?Actor $viewer, int $perPage = 0): LengthAwarePaginator
    {
        $perPage = $perPage > 0 ? $perPage : max(1, (int) config('openbook.profile.photos_per_page', 36));

        return Media::query()
            ->with([
                'thumbnail',
                'posts' => fn ($query) => $query
                    ->where('posts.actor_id', $actor->id)
                    ->where('posts.status', Post::STATUS_PUBLISHED)
                    ->visibleTo($viewer)
                    ->orderByDesc('posts.published_at'),
            ])
            ->whereHas('posts', function ($query) use ($actor, $viewer) {
                $query->where('actor_id', $actor->id)
                    ->where('status', Post::STATUS_PUBLISHED)
                    ->excludingPrivateMessages()
                    ->visibleTo($viewer);
            })
            ->where('mime_type', 'not like', 'audio/%')
            ->orderByDesc(
                Post::query()
                    ->select('posts.published_at')
                    ->join('post_attachments', 'post_attachments.post_id', '=', 'posts.id')
                    ->whereColumn('post_attachments.media_id', 'media.id')
                    ->where('posts.actor_id', $actor->id)
                    ->where('posts.status', Post::STATUS_PUBLISHED)
                    ->excludingPrivateMessages()
                    ->orderByDesc('posts.published_at')
                    ->limit(1)
            )
            ->paginate($perPage)
            ->withQueryString();
    }
}
