<?php

namespace App\Application\Services;

use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Support\Carbon;

/**
 * Condivide automaticamente i nuovi post pubblici dei contatti per cui il
 * follower locale ha attivato la "condivisione diretta" automatica. Pensato
 * per il cron: non ripete i post gia' condivisi e non notifica l'autore.
 */
final class AutoAnnounceFanout
{
    public function __construct(
        private readonly AnnounceManager $announceManager,
    ) {}

    /**
     * @return int Numero di Announce creati
     */
    public function process(int $followLimit, int $perFollow, float $deadline): int
    {
        $follows = Follow::query()
            ->where('auto_announce', true)
            ->where('status', Follow::STATUS_ACCEPTED)
            ->whereNotNull('auto_announce_since')
            ->whereHas('follower', function ($query): void {
                $query->where('is_local', true)
                    ->where('type', Actor::TYPE_PERSON)
                    ->where('status', Actor::STATUS_ACTIVE);
            })
            ->whereHas('following', function ($query): void {
                $query->where('status', Actor::STATUS_ACTIVE);
            })
            ->with(['follower', 'following.community'])
            ->orderBy('id')
            ->limit(max(1, $followLimit))
            ->get();

        $created = 0;

        foreach ($follows as $follow) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $posts = $this->eligiblePosts($follow, $perFollow);

            foreach ($posts as $post) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }

                $this->announceManager->announce(
                    $follow->follower,
                    $post,
                    notify: false,
                    direct: true,
                );
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Post>
     */
    private function eligiblePosts(Follow $follow, int $limit)
    {
        $target = $follow->following;
        $since = $follow->auto_announce_since;

        if ($target === null || $since === null) {
            return collect();
        }

        $query = Post::query()
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('visibility', Post::VISIBILITY_PUBLIC)
            ->whereNull('conversation_id')
            ->where('actor_id', '!=', $follow->follower_id)
            ->where('created_at', '>=', $since)
            ->whereDoesntHave('announces', function ($announces) use ($follow): void {
                $announces->where('actor_id', $follow->follower_id);
            });

        if ($target->isGroup()) {
            if ($target->community?->is_private) {
                return collect();
            }

            if ($target->community !== null) {
                $query->where('community_id', $target->community->id);
            } else {
                $query->where('actor_id', $target->id);
            }
        } else {
            $query->where('actor_id', $target->id)
                ->where(function ($visibility) {
                    $visibility->whereNull('community_id')
                        ->orWhereHas('community', fn ($community) => $community->where('is_private', false));
                });

            if (! $target->isFeed()) {
                $query->where('published_at', '>=', Carbon::parse($since)->subHours(6));
            }
        }

        return $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }
}
