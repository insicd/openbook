<?php

namespace App\Application\Services;

use App\Domain\Communities\Community;
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
 *
 * Per i Group locali (community) aggiorna anche members_count e notifica il
 * proprietario della community.
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
                $existing->setRelation('follower', $follower);
                $existing->setRelation('following', $target);

                // Heal: Follow pending verso Actor locale aperto (es. Group
                // pubblico). Senza questo, un Accept perso o un flag errato
                // lascia la join remota bloccata per sempre.
                if ($existing->status === Follow::STATUS_PENDING
                    && $target->isLocal()
                    && ! $target->manually_approves_followers
                ) {
                    $existing->update([
                        'status' => Follow::STATUS_ACCEPTED,
                        'accepted_at' => now(),
                    ]);
                    $existing->refresh();
                    $existing->setRelation('follower', $follower);
                    $existing->setRelation('following', $target);
                    $this->incrementLocalGroupMembers($target);
                }

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

            $this->notifyFollowTarget($target, $follower, $follow, $requiresApproval);

            if (! $requiresApproval) {
                $this->incrementLocalGroupMembers($target);
            }

            return $follow;
        });

        // Nuova richiesta, oppure ancora in attesa (es. consegna precedente
        // fallita / Accept perso): ritenta il Follow verso il server remoto.
        if (! $target->isLocal()
            && ($follow->wasRecentlyCreated || $follow->status === Follow::STATUS_PENDING)) {
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

        $wasAccepted = $follow->status === Follow::STATUS_ACCEPTED;

        $follow->setRelation('follower', $follower);
        $follow->setRelation('following', $target);
        $follow->delete();

        if ($wasAccepted) {
            $this->decrementLocalGroupMembers($target);
        }

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

        $this->incrementLocalGroupMembers($target);

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

    private function notifyFollowTarget(Actor $target, Actor $follower, Follow $follow, bool $requiresApproval): void
    {
        $type = $requiresApproval ? Notification::TYPE_FOLLOW_REQUEST : Notification::TYPE_NEW_FOLLOWER;

        if ($target->isLocal() && $target->isGroup()) {
            $ownerActor = Community::query()
                ->where('actor_id', $target->id)
                ->with('owner.actor')
                ->first()
                ?->owner
                ?->actor;

            if ($ownerActor !== null) {
                $this->notificationCreator->notify($ownerActor, $type, $follower, $follow);
            }

            return;
        }

        $this->notificationCreator->notify($target, $type, $follower, $follow);
    }

    private function incrementLocalGroupMembers(Actor $target): void
    {
        if (! $target->isLocal() || ! $target->isGroup()) {
            return;
        }

        Community::query()->where('actor_id', $target->id)->increment('members_count');
    }

    private function decrementLocalGroupMembers(Actor $target): void
    {
        if (! $target->isLocal() || ! $target->isGroup()) {
            return;
        }

        Community::query()
            ->where('actor_id', $target->id)
            ->where('members_count', '>', 0)
            ->decrement('members_count');
    }
}
