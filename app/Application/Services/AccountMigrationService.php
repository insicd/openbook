<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Federation\Actors\AccountMoveTargetResolver;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Support\ActivityPubUri;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountMigrationService
{
    public function __construct(
        private readonly RemoteActorResolver $actors,
        private readonly AccountMoveTargetResolver $targets,
        private readonly AccountMoveNotifier $notifications,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function verify(User $user, string $address): Actor
    {
        $this->assertOwner($user, $user->actor);
        $target = $this->actors->resolveAddress($address, forceRefresh: true);
        if ($target === null || $target->isLocal()
            || ! $this->targets->isValid($target, $user->actor->activityPubId(), $target->uri)) {
            throw ValidationException::withMessages(['destination' => __('openbook.settings.migration_invalid')]);
        }

        return $target;
    }

    public function start(User $user, string $address): Actor
    {
        // The destination is verified again on confirmation, outside the DB lock.
        $target = $this->verify($user, $address);
        [$source, $created] = DB::transaction(function () use ($user, $target): array {
            $source = $user->actor()->lockForUpdate()->first();
            $this->assertOwner($user->fresh(), $source);
            $current = $source->movedTo;
            if ($current !== null) {
                if (! ActivityPubUri::same($current->uri, $target->uri)) {
                    throw ValidationException::withMessages(['destination' => __('openbook.settings.migration_already_set')]);
                }

                return [$source, false];
            }
            $source->forceFill(['moved_to_actor_id' => $target->id])->save();
            $source->setRelation('movedTo', $target);
            $this->notifications->notifyFollowers($source, $target, deduplicate: false);

            return [$source, true];
        });
        $user->setRelation('actor', $source);

        if ($created) {
            $this->delivery->deliverToFollowers($source, ActivitySerializer::updateActor($source));
            $this->delivery->deliverToFollowers($source, ActivitySerializer::move($source, $target));
        }

        return $source;
    }

    public function removeIndication(User $user): void
    {
        $source = DB::transaction(function () use ($user): ?Actor {
            $source = $user->actor()->lockForUpdate()->first();
            $this->assertOwner($user->fresh(), $source);
            if ($source->moved_to_actor_id === null) {
                return null;
            }
            $source->forceFill(['moved_to_actor_id' => null])->save();
            $source->setRelation('movedTo', null);

            return $source;
        });
        if ($source !== null) {
            $user->setRelation('actor', $source);
            $this->delivery->deliverToFollowers($source, ActivitySerializer::updateActor($source));
        }
    }

    private function assertOwner(?User $user, ?Actor $actor): void
    {
        if ($user === null || ! $user->isActive() || $actor === null || ! $actor->isLocal()
            || ! $actor->isPerson() || ! $actor->isActive() || $actor->user_id !== $user->id) {
            throw new AuthorizationException;
        }
    }
}
