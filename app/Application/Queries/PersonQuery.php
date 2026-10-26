<?php

namespace App\Application\Queries;

use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Builder;

final class PersonQuery
{
    /** @return array{actor: Actor, followersCount: int, followingCount: int}|null */
    public function find(string $personId): ?array
    {
        $actor = $this->findActor($personId);
        if ($actor === null) {
            return null;
        }

        $followersCount = $actor->isLocal() ? null : $actor->followers_count;
        $followingCount = $actor->isLocal() ? null : $actor->following_count;

        $followersCount ??= Follow::query()
            ->where('following_id', $actor->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->count();
        $followingCount ??= Follow::query()
            ->where('follower_id', $actor->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->count();

        return compact('actor', 'followersCount', 'followingCount');
    }

    public function findActor(string $personId): ?Actor
    {
        return $this->available()
            ->with(['user.profile', 'user.settings', 'movedTo'])
            ->find($personId);
    }

    /** @return Builder<Actor> */
    public function available(): Builder
    {
        // The prototype excludes redacted local profiles and local technical actors.
        return Actor::query()
            ->whereIn('actors.type', [Actor::TYPE_PERSON, Actor::TYPE_APPLICATION, Actor::TYPE_FEED, Actor::TYPE_GROUP])
            ->where('actors.status', '!=', Actor::STATUS_DELETED)
            ->where(static function (Builder $query): void {
                $query->where('actors.is_local', false)
                    ->orWhere(static function (Builder $local): void {
                        $local->where('actors.is_local', true)
                            ->where('actors.status', Actor::STATUS_ACTIVE)
                            ->where(static function (Builder $identity): void {
                                $identity->where(fn (Builder $person) => $person->where('actors.type', Actor::TYPE_PERSON)->whereHas('user'))
                                    ->orWhere(fn (Builder $group) => $group->where('actors.type', Actor::TYPE_GROUP)->whereHas('community'))
                                    ->orWhere(fn (Builder $feed) => $feed->where('actors.type', Actor::TYPE_FEED)->whereHas('feedSource'));
                            });
                    });
            });
    }
}
