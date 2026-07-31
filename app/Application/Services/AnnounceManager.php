<?php

namespace App\Application\Services;

use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gestisce la condivisione di un post (attivita' ActivityPub {@code Announce}).
 * Non duplica mai il contenuto: crea solo un riferimento "attore ha
 * condiviso questo post", visibile nel feed di chi segue l'attore che
 * condivide. Quando chi condivide e' un Actor locale, l'Announce viene anche
 * consegnato ai suoi follower remoti (e direttamente all'autore originale,
 * se remoto e distinto dal condivisore) indipendentemente dal fatto che il
 * post condiviso sia opera di questa istanza o di un'altra: e' cosi' che le
 * condivisioni appaiono nel Fediverso.
 */
final class AnnounceManager
{
    public function __construct(
        private readonly NotificationCreator $notificationCreator,
        private readonly ActivityDelivery $delivery,
    ) {}

    /**
     * @param  Carbon|null  $occurredAt  Timestamp dell'Announce (es. published
     *                                    del post in un backfill outbox); default now().
     */
    public function announce(Actor $actor, Post $post, bool $notify = true, ?Carbon $occurredAt = null): Announce
    {
        $announce = DB::transaction(function () use ($actor, $post, $notify, $occurredAt) {
            $existing = Announce::query()
                ->where('actor_id', $actor->id)
                ->where('post_id', $post->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $announce = Announce::query()->create([
                'actor_id' => $actor->id,
                'post_id' => $post->id,
            ]);

            if ($occurredAt !== null) {
                $announce->forceFill([
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ])->saveQuietly();
            }

            $post->increment('announces_count');

            if ($notify) {
                $this->notificationCreator->notify($post->actor, Notification::TYPE_SHARE, $actor, $post);
            }

            return $announce;
        });

        if ($announce->wasRecentlyCreated && $actor->isLocal()) {
            $this->delivery->deliverAnnounce($actor, $post->actor, ActivitySerializer::announce($announce, $post));
        }

        return $announce;
    }

    public function unannounce(Actor $actor, Post $post): void
    {
        $announce = Announce::query()
            ->where('actor_id', $actor->id)
            ->where('post_id', $post->id)
            ->first();

        if ($announce === null) {
            return;
        }

        DB::transaction(function () use ($announce, $post) {
            $announce->delete();
            $post->decrement('announces_count');
        });

        if ($actor->isLocal()) {
            $this->delivery->deliverAnnounce($actor, $post->actor, ActivitySerializer::undoAnnounce($announce, $post));
        }
    }

    public function hasAnnounced(Actor $actor, Post $post): bool
    {
        return Announce::query()
            ->where('actor_id', $actor->id)
            ->where('post_id', $post->id)
            ->exists();
    }
}
