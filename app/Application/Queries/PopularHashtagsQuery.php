<?php

namespace App\Application\Queries;

use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Support\Collection;

/**
 * Hashtag piu' usati "su questa istanza" (sidebar destra, sotto "Questa
 * istanza"): conta solo i tag su post pubblicati da Actor *locali*, con
 * visibilita' pubblica o non elencata, cosi' la classifica riflette
 * l'attivita' reale della community iscritta qui, non i contenuti remoti
 * semplicemente passati in cache (vedi la sezione "Mondo" per quelli) ne'
 * post riservati a follower/destinatari diretti.
 */
final class PopularHashtagsQuery
{
    /**
     * @return Collection<int, Hashtag>
     */
    public function top(int $limit = 6): Collection
    {
        return Hashtag::query()
            ->select('hashtags.*')
            ->selectRaw('count(*) as usage_count')
            ->join('post_hashtags', 'post_hashtags.hashtag_id', '=', 'hashtags.id')
            ->join('posts', 'posts.id', '=', 'post_hashtags.post_id')
            ->join('actors', 'actors.id', '=', 'posts.actor_id')
            ->where('actors.is_local', true)
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->whereIn('posts.visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED])
            ->groupBy('hashtags.id', 'hashtags.name', 'hashtags.created_at', 'hashtags.updated_at')
            ->orderByDesc('usage_count')
            ->orderBy('hashtags.name')
            ->limit($limit)
            ->get();
    }
}
