<?php

namespace App\Application\Queries;

use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Support\Collection;

/**
 * Hashtag piu' usati sui post in cache su questa istanza (locali e remoti),
 * con visibilita' pubblica o non elencata, nella finestra di giorni
 * configurata (`openbook.hashtags.trending_days`, default 7). Usato dalla
 * sidebar "In tendenza" e dalla pagina elenco completo.
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

        return Hashtag::query()
            ->select('hashtags.*')
            ->selectRaw('count(*) as usage_count')
            ->join('post_hashtags', 'post_hashtags.hashtag_id', '=', 'hashtags.id')
            ->join('posts', 'posts.id', '=', 'post_hashtags.post_id')
            ->where('hashtags.name', '!=', '')
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->whereIn('posts.visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED])
            ->where('posts.published_at', '>=', now()->subDays($days))
            ->groupBy('hashtags.id', 'hashtags.name', 'hashtags.created_at', 'hashtags.updated_at')
            ->orderByDesc('usage_count')
            ->orderBy('hashtags.name')
            ->limit($limit)
            ->get();
    }
}
