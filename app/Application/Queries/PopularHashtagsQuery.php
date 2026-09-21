<?php

namespace App\Application\Queries;

use App\Domain\Events\Event;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hashtag piu' usati sui post e sugli eventi in cache su questa istanza
 * (locali e remoti), con visibilita' pubblica o non elencata, nella finestra
 * di giorni configurata (`openbook.hashtags.trending_days`, default 7).
 */
final class PopularHashtagsQuery
{
    public const SIDEBAR_LIMIT = 5;

    /**
     * @return Collection<int, Hashtag>
     */
    public function top(int $limit = self::SIDEBAR_LIMIT): Collection
    {
        $days = max(1, (int) config('openbook.hashtags.trending_days', 7));
        $threshold = now()->subDays($days);
        $postUses = DB::table('post_hashtags')
            ->select('post_hashtags.hashtag_id')
            ->join('posts', 'posts.id', '=', 'post_hashtags.post_id')
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->whereIn('posts.visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED])
            ->where('posts.published_at', '>=', $threshold);
        $eventUses = DB::table('event_hashtags')
            ->select('event_hashtags.hashtag_id')
            ->join('events', 'events.id', '=', 'event_hashtags.event_id')
            ->whereIn('events.status', [Event::STATUS_SCHEDULED, Event::STATUS_TENTATIVE, Event::STATUS_POSTPONED])
            ->whereIn('events.visibility', [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_UNLISTED])
            ->where('events.published_at', '>=', $threshold);

        $query = Hashtag::query()
            ->select('hashtags.*')
            ->selectRaw('count(*) as usage_count')
            ->joinSub($postUses->unionAll($eventUses), 'hashtag_uses', 'hashtag_uses.hashtag_id', '=', 'hashtags.id')
            ->where('hashtags.name', '!=', '');

        if ((bool) config('openbook.moderation.hide_content_warnings_from_world', false)) {
            $forcedHashtags = config('openbook.moderation.forced_content_warning_hashtags', []);

            if (is_array($forcedHashtags) && $forcedHashtags !== []) {
                $query->whereNotIn('hashtags.name', $forcedHashtags);
            }
        }

        return $query
            ->groupBy('hashtags.id', 'hashtags.name', 'hashtags.created_at', 'hashtags.updated_at')
            ->orderByDesc('usage_count')
            ->orderBy('hashtags.name')
            ->limit($limit)
            ->get();
    }
}
