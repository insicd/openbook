<?php

namespace App\Federation\Actors;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Domain\Reactions\Like;
use App\Domain\SocialGraph\Follow;
use App\Infrastructure\Media\Media;
use Illuminate\Support\Facades\DB;

/**
 * Invalida un Actor remoto cancellato senza spezzare gli alberi dei commenti.
 * La vecchia identita' federata viene liberata, mentre la riga Actor e i
 * contenuti svuotati restano come tombstone interne per le foreign key.
 */
final class RemoteActorDeletionService
{
    public function delete(Actor $actor): bool
    {
        if ($actor->isLocal()) {
            return false;
        }

        return DB::transaction(function () use ($actor): bool {
            $lockedActor = Actor::query()->lockForUpdate()->find($actor->id);

            if ($lockedActor === null || $lockedActor->isLocal() || $lockedActor->isDeleted()) {
                return false;
            }

            $authoredPostIds = Post::query()
                ->select('id')
                ->where('actor_id', $lockedActor->id);
            $authoredCommentIds = Comment::query()
                ->select('id')
                ->where('actor_id', $lockedActor->id);

            DB::table('post_hashtags')->whereIn('post_id', clone $authoredPostIds)->delete();
            Mention::query()
                ->where('mentionable_type', (new Post)->getMorphClass())
                ->whereIn('mentionable_id', clone $authoredPostIds)
                ->delete();
            Mention::query()
                ->where('mentionable_type', (new Comment)->getMorphClass())
                ->whereIn('mentionable_id', clone $authoredCommentIds)
                ->delete();

            Post::query()->where('actor_id', $lockedActor->id)->update([
                'uri' => null,
                'title' => null,
                'content_warning' => null,
                'body' => '',
                'language' => null,
                'status' => Post::STATUS_DELETED,
            ]);

            Comment::query()->where('actor_id', $lockedActor->id)->update([
                'uri' => null,
                'body' => '',
                'status' => Comment::STATUS_DELETED,
            ]);

            Media::query()->where('actor_id', $lockedActor->id)->delete();
            Like::query()->where('actor_id', $lockedActor->id)->delete();
            Announce::query()->where('actor_id', $lockedActor->id)->delete();
            Follow::query()
                ->where('follower_id', $lockedActor->id)
                ->orWhere('following_id', $lockedActor->id)
                ->delete();
            DB::table('remote_collection_members')
                ->where('actor_id', $lockedActor->id)
                ->orWhere('member_actor_id', $lockedActor->id)
                ->delete();

            $lockedActor->key()->delete();
            $lockedActor->endpoints()->delete();

            $tombstoneId = $lockedActor->id;
            $lockedActor->forceFill([
                'preferred_username' => 'deleted-'.$tombstoneId,
                'domain' => 'deleted.invalid',
                'uri' => 'urn:openbook:deleted-actor:'.$tombstoneId,
                'name' => null,
                'summary' => null,
                'icon_url' => null,
                'image_url' => null,
                'manually_approves_followers' => false,
                'discoverable' => false,
                'indexable' => false,
                'status' => Actor::STATUS_DELETED,
                'last_fetched_at' => null,
                'posts_fetched_at' => null,
                'published_at' => null,
                'followers_count' => null,
                'following_count' => null,
                'collections_fetched_at' => null,
                'deleted_at' => now(),
            ])->save();

            return true;
        });
    }
}
