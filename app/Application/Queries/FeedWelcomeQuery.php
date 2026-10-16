<?php

namespace App\Application\Queries;

use App\Domain\Accounts\User;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FeedWelcomeQuery
{
    public const FOLLOW_THRESHOLD = 10;

    public function __construct(
        private readonly InstanceStaffQuery $staffQuery,
        private readonly SuggestedLocalActorsQuery $localSuggestions,
        private readonly PopularRemoteActorsQuery $remoteSuggestions,
    ) {}

    /**
     * @return array{staff: Collection<int, User>, local: Collection<int, Actor>, remote: Collection<int, Actor>, showRemoteSuggestions: bool}|null
     */
    public function forViewer(Actor $viewer, string $viewerUserId): ?array
    {
        $people = Follow::query()
            ->where('follower_id', $viewer->id)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->whereHas('following', fn ($query) => $query->where('type', Actor::TYPE_PERSON))
            ->limit(self::FOLLOW_THRESHOLD)
            ->get(['following_id'])
            ->count();

        if ($people >= self::FOLLOW_THRESHOLD) {
            return null;
        }

        $remaining = self::FOLLOW_THRESHOLD - $people;
        $hashtags = DB::table('hashtag_follows')
            ->where('actor_id', $viewer->id)
            ->limit($remaining)
            ->get(['hashtag_id'])
            ->count();

        return $hashtags >= $remaining
            ? null
            : $this->build($viewer, $viewerUserId, $people === 0 && $hashtags === 0);
    }

    /**
     * Kit di benvenuto per i primi passi: staff, persone locali e
     * account remoti gia' noti all'istanza, senza duplicati.
     *
     * @return array{
     *     staff: Collection<int, User>,
     *     local: Collection<int, Actor>,
     *     remote: Collection<int, Actor>,
     *     showRemoteSuggestions: bool,
     * }
     */
    private function build(Actor $viewer, string $viewerUserId, bool $showRemoteSuggestions): array
    {
        $followedIds = Follow::query()
            ->where('follower_id', $viewer->id)
            ->pluck('following_id');

        $staff = $this->staffQuery->all()
            ->loadMissing(['actor', 'profile'])
            ->filter(function ($member) use ($viewerUserId, $followedIds): bool {
                if ($member->id === $viewerUserId || $member->actor === null) {
                    return false;
                }

                return ! $followedIds->contains($member->actor->id);
            })
            ->values();

        $staffActorIds = $staff->pluck('actor.id')->filter()->all();

        $local = $this->localSuggestions->forViewer(
            $viewer,
            SuggestedLocalActorsQuery::WELCOME_LIMIT,
            $staffActorIds,
        );

        $remote = $this->remoteSuggestions->forViewer($viewer, PopularRemoteActorsQuery::PREVIEW_LIMIT);

        return [
            'staff' => $staff,
            'local' => $local,
            'remote' => $remote,
            'showRemoteSuggestions' => $showRemoteSuggestions,
        ];
    }
}
