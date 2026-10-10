<?php

namespace App\Federation\Actors;

use App\Application\Services\NotificationCreator;
use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use App\Domain\SocialGraph\Follow;
use App\Federation\Inbox\InboxItem;
use App\Federation\Support\ActivityPubUri;
use Illuminate\Support\Facades\DB;

final class RemoteAccountMoveHandler
{
    public function __construct(
        private readonly RemoteActorResolver $actors,
        private readonly NotificationCreator $notifications,
    ) {}

    /** @param array<string, mixed> $activity */
    public function handle(array $activity, Actor $signer): string
    {
        $actorUri = $this->objectId($activity['actor'] ?? null);
        $objectUri = $this->objectId($activity['object'] ?? null);
        $targetUri = $this->objectId($activity['target'] ?? null);

        if ($signer->isLocal() || ! $signer->isActive() || $signer->isRemotelySuspended()
            || (! $signer->isPerson() && ! $signer->isApplication())
            || $actorUri === null || ! ActivityPubUri::same($actorUri, $signer->uri)
            || $objectUri === null || ! ActivityPubUri::same($objectUri, $signer->uri)
            || $targetUri === null
        ) {
            return InboxItem::STATUS_IGNORED;
        }

        // A fresh destination document is the proof of the alias; cached data
        // must not turn a failed fetch into a verified migration.
        $target = $this->actors->resolveMovedTo($signer->uri, $targetUri, forceRefresh: true);
        if ($target === null || ! collect($target->also_known_as)->contains(
            fn (string $alias): bool => ActivityPubUri::same($alias, $signer->uri)
        )
        ) {
            return InboxItem::STATUS_IGNORED;
        }

        return DB::transaction(function () use ($signer, $target): string {
            $source = Actor::query()->lockForUpdate()->findOrFail($signer->id);
            if ($source->moved_to_actor_id !== null && $source->moved_to_actor_id !== $target->id) {
                return InboxItem::STATUS_IGNORED;
            }

            // The profile may already have supplied movedTo without any Move.
            // Deduplicate notifications themselves, not the informational link.
            $notified = array_flip(Notification::query()
                ->where('actor_id', $source->id)
                ->where('type', Notification::TYPE_ACCOUNT_MOVED)
                ->where('notifiable_type', $target->getMorphClass())
                ->where('notifiable_id', $target->id)
                ->pluck('recipient_id')->all());

            $followers = Actor::query()
                ->select('actors.*')
                ->join('follows', 'follows.follower_id', '=', 'actors.id')
                ->join('users', 'users.id', '=', 'actors.user_id')
                ->where('follows.following_id', $source->id)
                ->where('follows.status', Follow::STATUS_ACCEPTED)
                ->where('actors.is_local', true)
                ->where('actors.type', Actor::TYPE_PERSON)
                ->where('actors.status', Actor::STATUS_ACTIVE)
                ->where('users.status', User::STATUS_ACTIVE)
                ->get();

            foreach ($followers as $follower) {
                if (isset($notified[$follower->user_id])) {
                    continue;
                }
                // The destination is the stable notification target. Following
                // it remains an explicit user action, preserving old follows.
                $this->notifications->notify($follower, Notification::TYPE_ACCOUNT_MOVED, $source, $target);
            }

            $source->forceFill(['moved_to_actor_id' => $target->id])->save();

            return InboxItem::STATUS_PROCESSED;
        });
    }

    private function objectId(mixed $value): ?string
    {
        $id = is_array($value) ? ($value['id'] ?? null) : $value;

        return is_string($id) && filter_var($id, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($id, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $id
            : null;
    }
}
