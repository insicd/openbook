<?php

namespace App\Application\Services;

use App\Domain\Notifications\Notification;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gestisce le relazioni di follow tra Actor, locali o remoti: la relazione
 * e' gia' generica (Actor-to-Actor) fin dal Milestone 2, quindi la Fase 4 si
 * limita ad aggiungere la consegna federata attorno agli stessi metodi, senza
 * duplicarne la logica. Quando il "target" di un follow e' remoto, la
 * richiesta resta sempre "in attesa" finche' non arriva un Accept/Reject
 * dal server remoto: e' il comportamento piu' semplice e corretto secondo la
 * specifica ActivityPub, indipendentemente dal flag "manuallyApprovesFollowers"
 * dichiarato dall'attore remoto (che resta solo informativo).
 */
final class FollowManager
{
    public function __construct(
        private readonly NotificationCreator $notificationCreator,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function follow(Actor $follower, Actor $target): Follow
    {
        if ($follower->id === $target->id) {
            throw new InvalidArgumentException('Non puoi seguire te stesso.');
        }

        $follow = DB::transaction(function () use ($follower, $target) {
            $existing = Follow::query()
                ->where('follower_id', $follower->id)
                ->where('following_id', $target->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $requiresApproval = $target->isLocal() ? $target->manually_approves_followers : true;

            $follow = Follow::query()->create([
                'follower_id' => $follower->id,
                'following_id' => $target->id,
                'status' => $requiresApproval ? Follow::STATUS_PENDING : Follow::STATUS_ACCEPTED,
                'requested_at' => now(),
                'accepted_at' => $requiresApproval ? null : now(),
            ]);

            $follow->setRelation('follower', $follower);
            $follow->setRelation('following', $target);

            $this->notificationCreator->notify(
                $target,
                $requiresApproval ? Notification::TYPE_FOLLOW_REQUEST : Notification::TYPE_NEW_FOLLOWER,
                $follower,
                $follow,
            );

            return $follow;
        });

        if (! $target->isLocal() && $follow->wasRecentlyCreated) {
            $this->delivery->deliverTo($follower, $target, ActivitySerializer::follow($follow));
        }

        return $follow;
    }

    public function unfollow(Actor $follower, Actor $target): void
    {
        $follow = Follow::query()
            ->where('follower_id', $follower->id)
            ->where('following_id', $target->id)
            ->first();

        if ($follow === null) {
            return;
        }

        $follow->setRelation('follower', $follower);
        $follow->setRelation('following', $target);
        $follow->delete();

        if (! $target->isLocal()) {
            $this->delivery->deliverTo($follower, $target, ActivitySerializer::undoFollow($follow));
        }
    }

    public function accept(Actor $target, Actor $follower): Follow
    {
        $follow = Follow::query()
            ->where('follower_id', $follower->id)
            ->where('following_id', $target->id)
            ->where('status', Follow::STATUS_PENDING)
            ->firstOrFail();

        $follow->update([
            'status' => Follow::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);

        $follow->setRelation('follower', $follower);
        $follow->setRelation('following', $target);

        $this->notificationCreator->notify($follower, Notification::TYPE_FOLLOW_ACCEPTED, $target, $follow);

        if (! $follower->isLocal()) {
            $this->delivery->deliverTo($target, $follower, ActivitySerializer::accept($follow));
        }

        return $follow;
    }

    public function reject(Actor $target, Actor $follower): void
    {
        $follow = Follow::query()
            ->where('follower_id', $follower->id)
            ->where('following_id', $target->id)
            ->where('status', Follow::STATUS_PENDING)
            ->first();

        if ($follow === null) {
            return;
        }

        $follow->setRelation('follower', $follower);
        $follow->setRelation('following', $target);
        $follow->delete();

        if (! $follower->isLocal()) {
            $this->delivery->deliverTo($target, $follower, ActivitySerializer::reject($follow));
        }
    }

    public function isFollowing(Actor $follower, Actor $target): bool
    {
        return Follow::query()
            ->where('follower_id', $follower->id)
            ->where('following_id', $target->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->exists();
    }

    public function hasPendingRequest(Actor $follower, Actor $target): bool
    {
        return Follow::query()
            ->where('follower_id', $follower->id)
            ->where('following_id', $target->id)
            ->where('status', Follow::STATUS_PENDING)
            ->exists();
    }

    public function areMutualFollowers(Actor $a, Actor $b): bool
    {
        return $this->isFollowing($a, $b) && $this->isFollowing($b, $a);
    }

    /**
     * Stato del follow di {@see $viewer} verso ciascuno degli Actor forniti,
     * in un'unica query: usato dagli elenchi (follower/seguiti) per evitare
     * una coppia di query per riga.
     *
     * @param  iterable<Actor>  $targets
     * @return array<string, array{following: bool, pending: bool}>
     */
    public function statusMapFor(Actor $viewer, iterable $targets): array
    {
        $targetIds = collect($targets)->pluck('id')->filter(fn ($id) => $id !== $viewer->id)->values();

        if ($targetIds->isEmpty()) {
            return [];
        }

        $rows = Follow::query()
            ->where('follower_id', $viewer->id)
            ->whereIn('following_id', $targetIds)
            ->whereIn('status', [Follow::STATUS_ACCEPTED, Follow::STATUS_PENDING])
            ->get(['following_id', 'status']);

        $map = [];

        foreach ($rows as $row) {
            $map[$row->following_id] = [
                'following' => $row->status === Follow::STATUS_ACCEPTED,
                'pending' => $row->status === Follow::STATUS_PENDING,
            ];
        }

        return $map;
    }
}
