<?php

namespace App\Application\Queries;

use App\Application\Services\InstanceSettings;
use App\Domain\Posts\Post;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Selects post roots only. Deletion and database sanity belong to separate flows. */
final class RemotePostRetentionQuery
{
    public function __construct(
        private readonly InstanceSettings $settings,
        private readonly FeedQuery $feed,
    ) {}

    public function pertinent(int $limit, ?CarbonInterface $asOf = null): Builder
    {
        return $this->candidates(true, $this->settings->remotePostPertinentRetentionDays(), $limit, $asOf);
    }

    public function nonPertinent(int $limit, ?CarbonInterface $asOf = null): Builder
    {
        return $this->candidates(false, $this->settings->remotePostNonPertinentRetentionDays(), $limit, $asOf);
    }

    private function candidates(bool $pertinent, int $days, int $limit, ?CarbonInterface $asOf): Builder
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The retention batch size must be positive.');
        }

        $query = DB::table('posts as retention_posts')
            ->select('retention_posts.id', 'retention_posts.created_at')
            ->join('actors as retention_authors', 'retention_authors.id', '=', 'retention_posts.actor_id')
            ->where('retention_authors.is_local', false)
            ->whereNotNull('retention_posts.uri')
            ->whereNull('retention_posts.conversation_id')
            ->where('retention_posts.visibility', '!=', Post::VISIBILITY_DIRECT)
            ->whereNotExists(function (Builder $quotes): void {
                $quotes->selectRaw('1')->from('posts as retention_quotes')
                    ->join('actors as retention_quoters', 'retention_quoters.id', '=', 'retention_quotes.actor_id')
                    ->whereColumn('retention_quotes.quoted_post_id', 'retention_posts.id')
                    ->where('retention_quoters.is_local', true);
            })
            ->orderBy('retention_posts.created_at')->orderBy('retention_posts.id')
            ->limit($limit);

        if ($days === 0) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('retention_posts.created_at', '<', CarbonImmutable::instance($asOf ?? now())->subDays($days));

        // Negate the entire predicate so the two categories cannot overlap.
        return $query->where(function (Builder $relevance): void {
            $this->constrainPertinence($relevance);
        }, boolean: $pertinent ? 'and' : 'and not');
    }

    private function constrainPertinence(Builder $query): void
    {
        $query->whereExists(function (Builder $comments): void {
            $comments->selectRaw('1')->from('comments as retention_comments')
                ->join('actors as retention_commenters', 'retention_commenters.id', '=', 'retention_comments.actor_id')
                ->whereColumn('retention_comments.post_id', 'retention_posts.id')
                ->where('retention_commenters.is_local', true);
        })->orWhereExists(function (Builder $viewers): void {
            $viewers->selectRaw('1')->from('actors as retention_viewers')
                ->where('retention_viewers.is_local', true)
                ->whereNotNull('retention_viewers.user_id');

            $homePost = Post::query()->selectRaw('1')
                ->whereColumn('posts.id', 'retention_posts.id')
                ->where('posts.status', Post::STATUS_PUBLISHED)
                ->excludingPrivateMessages()
                ->visibleToActorId(DB::raw('retention_viewers.id'))
                ->where(function ($sources): void {
                    $sources->whereExists($this->followedActor('posts.actor_id'))
                        ->orWhereExists(function (Builder $communities): void {
                            $communities->selectRaw('1')->from('communities as retention_communities')
                                ->whereColumn('retention_communities.id', 'posts.community_id')
                                ->whereExists($this->followedActor('retention_communities.actor_id'));
                        })
                        ->orWhere(function ($hashtags): void {
                            $hashtags->where('posts.visibility', Post::VISIBILITY_PUBLIC)
                                ->whereExists(function (Builder $tags): void {
                                    $tags->selectRaw('1')->from('post_hashtags as retention_tags')
                                        ->join('hashtag_follows as retention_tag_follows', 'retention_tag_follows.hashtag_id', '=', 'retention_tags.hashtag_id')
                                        ->whereColumn('retention_tags.post_id', 'posts.id')
                                        ->whereColumn('retention_tag_follows.actor_id', 'retention_viewers.id');
                                });
                        })
                        ->orWhereExists(function (Builder $announces): void {
                            $announces->selectRaw('1')->from('announces as retention_announces')
                                ->whereColumn('retention_announces.post_id', 'posts.id');
                            $this->feed->constrainTimelineAnnounces($announces, 'retention_announces');
                            $announces->where(function (Builder $sharers): void {
                                $sharers->whereColumn('retention_announces.actor_id', 'retention_viewers.id')
                                    ->orWhereExists($this->followedActor('retention_announces.actor_id'));
                            });
                        });
                });

            $viewers->whereExists($homePost->toBase());
        });
    }

    private function followedActor(string $actorColumn): \Closure
    {
        return static function (Builder $follows) use ($actorColumn): void {
            $follows->selectRaw('1')->from('follows as retention_follows')
                ->whereColumn('retention_follows.follower_id', 'retention_viewers.id')
                ->whereColumn('retention_follows.following_id', $actorColumn)
                ->where('retention_follows.status', 'accepted');
        };
    }
}
