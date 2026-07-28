<?php

namespace App\Application\Services;

use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Like;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gestisce l'unica reazione federabile prevista per questo milestone: il
 * "Mi piace" (attivita' ActivityPub {@code Like}). Si applica
 * indifferentemente a post e commenti grazie alla relazione polimorfica
 * "likeable". Il vincolo di unicita' a livello di database previene i
 * duplicati anche in caso di doppio invio concorrente. Quando l'autore del
 * contenuto e' un Actor remoto, il "Mi piace" viene anche consegnato alla
 * sua inbox; quando invece e' il contenuto stesso a essere remoto in cache
 * (ricevuto da un altro follower), il conteggio locale non e' autoritativo e
 * la reazione resta puramente locale (nessuna consegna).
 */
final class ReactionManager
{
    public function __construct(
        private readonly NotificationCreator $notificationCreator,
        private readonly ActivityDelivery $delivery,
    ) {}

    /**
     * @param  Model&object{likes_count?: int, likes: MorphMany, actor: Actor}  $target
     */
    public function like(Actor $actor, Model $target): Like
    {
        if (! method_exists($target, 'likes')) {
            throw new InvalidArgumentException('Il contenuto indicato non supporta i Mi piace.');
        }

        $like = DB::transaction(function () use ($actor, $target) {
            $existing = Like::query()
                ->where('actor_id', $actor->id)
                ->where('likeable_type', $target->getMorphClass())
                ->where('likeable_id', $target->getKey())
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $like = Like::query()->create([
                'actor_id' => $actor->id,
                'likeable_type' => $target->getMorphClass(),
                'likeable_id' => $target->getKey(),
            ]);

            $target->increment('likes_count');

            $this->notificationCreator->notify($target->actor, Notification::TYPE_LIKE, $actor, $target);

            return $like;
        });

        if ($like->wasRecentlyCreated && ! $target->actor->isLocal() && ($target instanceof Post || $target instanceof Comment)) {
            $this->delivery->deliverTo($actor, $target->actor, ActivitySerializer::like($like, $target));
        }

        return $like;
    }

    public function unlike(Actor $actor, Model $target): void
    {
        $like = Like::query()
            ->where('actor_id', $actor->id)
            ->where('likeable_type', $target->getMorphClass())
            ->where('likeable_id', $target->getKey())
            ->first();

        if ($like === null) {
            return;
        }

        DB::transaction(function () use ($like, $target) {
            $like->delete();
            $target->decrement('likes_count');
        });

        if (! $target->actor->isLocal() && ($target instanceof Post || $target instanceof Comment)) {
            $this->delivery->deliverTo($actor, $target->actor, ActivitySerializer::undoLike($like, $target));
        }
    }

    public function hasLiked(Actor $actor, Model $target): bool
    {
        return Like::query()
            ->where('actor_id', $actor->id)
            ->where('likeable_type', $target->getMorphClass())
            ->where('likeable_id', $target->getKey())
            ->exists();
    }
}
