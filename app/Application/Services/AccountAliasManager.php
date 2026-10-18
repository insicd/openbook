<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Support\ActivityPubUri;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountAliasManager
{
    public const MAX_ALIASES = 10;

    public function __construct(
        private readonly RemoteActorResolver $actors,
        private readonly DomainBlockManager $blocks,
        private readonly ActivityDelivery $delivery,
    ) {}

    public function add(User $user, string $address): void
    {
        $this->assertEditable($user, $user->actor);
        $address = trim($address);
        $source = null;
        if (strlen($address) <= 2048) {
            if (filter_var($address, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($address, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $source = $this->actors->resolveByUri($address);
            } elseif (preg_match('~^(?:acct:)?@?[^@\s/:?#]+@[^@\s/?#]+$~uD', $address) === 1) {
                $source = $this->actors->resolveByHandle($address);
            }
        }

        if ($source === null || $source->isLocal() || ! $source->isActive()
            || $source->isRemotelySuspended() || (! $source->isPerson() && ! $source->isApplication())
            || strlen($source->uri) > 2048 || $this->blocks->isBlockedUrl($source->uri)
            || ActivityPubUri::same($source->uri, $user->actor->activityPubId())) {
            throw ValidationException::withMessages(['alias' => __('openbook.settings.alias_invalid')]);
        }

        $this->change($user, $source->uri, adding: true);
    }

    public function remove(User $user, string $uri): void
    {
        // Removing an alias must remain possible when its server is unavailable.
        $this->change($user, trim($uri), adding: false);
    }

    private function change(User $user, string $uri, bool $adding): void
    {
        $changed = DB::transaction(function () use ($user, $uri, $adding): ?Actor {
            $actor = $user->actor()->lockForUpdate()->first();
            $this->assertEditable($user->fresh(), $actor);
            $aliases = $actor->also_known_as ?? [];
            $exists = collect($aliases)->contains(fn (string $alias): bool => ActivityPubUri::same($alias, $uri));
            if ($exists === $adding) {
                return null;
            }
            if ($adding && count($aliases) >= self::MAX_ALIASES) {
                throw ValidationException::withMessages(['alias' => __('openbook.settings.alias_limit', ['max' => self::MAX_ALIASES])]);
            }
            $aliases = $adding ? [...$aliases, $uri] : array_values(array_filter(
                $aliases, fn (string $alias): bool => ! ActivityPubUri::same($alias, $uri),
            ));
            $actor->forceFill(['also_known_as' => $aliases])->save();

            return $actor;
        });

        if ($changed !== null) {
            $user->setRelation('actor', $changed);
            $this->delivery->deliverToFollowers($changed, ActivitySerializer::updateActor($changed));
        }
    }

    private function assertEditable(?User $user, ?Actor $actor): void
    {
        if ($user === null || ! $user->isActive() || $actor === null || ! $actor->isLocal()
            || ! $actor->isPerson() || ! $actor->isActive() || $actor->user_id !== $user->id) {
            throw new AuthorizationException;
        }
        if ($actor->moved_to_actor_id !== null) {
            throw ValidationException::withMessages(['alias' => __('openbook.settings.alias_account_moved')]);
        }
    }
}
