<?php

namespace App\Application\Queries;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Support\Collection;

final class ActorFeaturedPostsQuery
{
    /** @return Collection<int, Post> */
    public function forActor(Actor $actor, ?Actor $viewer): Collection
    {
        $ids = $actor->featured_post_ids ?? [];
        if ($ids === []) {
            return collect();
        }

        $posts = Post::query()->with(Post::CARD_RELATIONS)
            ->whereKey($ids)
            ->where('actor_id', $actor->id)
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED])
            ->excludingPrivateMessages()
            ->visibleTo($viewer)->get()->keyBy('id');

        $ordered = collect($ids)->map(fn (string $id) => $posts->get($id))->filter()->values();
        Post::annotateViewerState($ordered, $viewer);

        return $ordered;
    }
}
