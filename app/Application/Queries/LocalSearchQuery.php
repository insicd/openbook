<?php

namespace App\Application\Queries;

use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Http\Controllers\SearchController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Ricerca locale per parole chiave: posta, commenti, persone e hashtag
 * dell'*istanza corrente* (mai contenuti remoti in cache).
 *
 * Volutamente basata su LIKE case-insensitive, senza Elasticsearch ne'
 * indici FULLTEXT: i vincoli di shared hosting di Openbook escludono un
 * motore di ricerca dedicato, e le tabelle tipiche di un'istanza di
 * questa scala restano gestibili con query semplici. I caratteri jolly
 * LIKE (`%`, `_`) digitati dall'utente vengono escapati (con clausola
 * ESCAPE, portabile fra MySQL/MariaDB e SQLite usato nei test), cosi' una
 * ricerca di "100%" non diventa un pattern "qualsiasi cosa".
 *
 * La ricerca federata per indirizzo `utente@dominio` resta fuori da questa
 * classe: la gestisce direttamente {@see SearchController}.
 */
final class LocalSearchQuery
{
    /**
     * @return array{
     *     people: Collection<int, User>,
     *     posts: Collection<int, Post>,
     *     comments: Collection<int, Comment>,
     *     hashtags: Collection<int, Hashtag>
     * }
     */
    public function search(string $term, ?Actor $viewer, int $limit = 0): array
    {
        $term = trim($term);
        $limit = $limit > 0 ? $limit : (int) config('openbook.search.per_section', 10);

        if ($term === '' || mb_strlen($term) < (int) config('openbook.search.min_length', 2)) {
            return $this->empty();
        }

        $pattern = $this->likePattern($term);

        return [
            'people' => $this->people($pattern, $limit),
            'posts' => $this->posts($pattern, $viewer, $limit),
            'comments' => $this->comments($pattern, $viewer, $limit),
            'hashtags' => $this->hashtags($this->likePattern(ltrim($term, '#')), $limit),
        ];
    }

    /**
     * @return array{
     *     people: Collection<int, User>,
     *     posts: Collection<int, Post>,
     *     comments: Collection<int, Comment>,
     *     hashtags: Collection<int, Hashtag>
     * }
     */
    private function empty(): array
    {
        return [
            'people' => collect(),
            'posts' => collect(),
            'comments' => collect(),
            'hashtags' => collect(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function people(string $pattern, int $limit): Collection
    {
        return User::query()
            ->with(['profile', 'actor', 'settings'])
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('settings', fn ($query) => $query->where('discoverable', true))
            ->where(function ($query) use ($pattern) {
                $this->whereContains($query, 'username', $pattern);
                $query->orWhereHas('profile', function ($query) use ($pattern) {
                    $this->whereContains($query, 'display_name', $pattern);
                    $query->orWhere(function ($query) use ($pattern) {
                        $this->whereContains($query, 'bio', $pattern);
                    });
                });
            })
            ->orderBy('username')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Post>
     */
    private function posts(string $pattern, ?Actor $viewer, int $limit): Collection
    {
        $posts = Post::query()
            ->with(['actor.user.profile', 'media.thumbnail', 'hashtags'])
            ->where('status', Post::STATUS_PUBLISHED)
            ->whereNull('uri')
            ->whereHas('actor', fn ($query) => $query->where('is_local', true))
            ->where(function ($query) use ($pattern) {
                $this->whereContains($query, 'body', $pattern);
                $query->orWhere(function ($query) use ($pattern) {
                    $this->whereContains($query, 'title', $pattern);
                });
                $query->orWhere(function ($query) use ($pattern) {
                    $this->whereContains($query, 'content_warning', $pattern);
                });
            })
            ->visibleTo($viewer)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        Post::annotateViewerState($posts, $viewer);

        return $posts;
    }

    /**
     * @return Collection<int, Comment>
     */
    private function comments(string $pattern, ?Actor $viewer, int $limit): Collection
    {
        return Comment::query()
            ->with(['actor.user.profile', 'post.actor.user.profile'])
            ->where('status', Comment::STATUS_PUBLISHED)
            ->whereNull('uri')
            ->whereHas('actor', fn ($query) => $query->where('is_local', true))
            ->where(function ($query) use ($pattern) {
                $this->whereContains($query, 'body', $pattern);
            })
            ->whereHas('post', function ($query) use ($viewer) {
                $query->where('status', Post::STATUS_PUBLISHED)
                    ->visibleTo($viewer);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Hashtag>
     */
    private function hashtags(string $pattern, int $limit): Collection
    {
        return Hashtag::query()
            ->where(function ($query) use ($pattern) {
                $this->whereContains($query, 'name', $pattern);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * LIKE con ESCAPE esplicito: necessario per trattare letteralmente
     * `%` e `_` digitati dall'utente sia su MySQL/MariaDB sia su SQLite
     * (nei test), dove il backslash non e' l'escape di default.
     *
     * @param  Builder<Model>  $query
     */
    private function whereContains(Builder $query, string $column, string $pattern): void
    {
        $query->whereRaw("{$query->getGrammar()->wrap($column)} LIKE ? ESCAPE '\\'", [$pattern]);
    }

    private function likePattern(string $term): string
    {
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\%', '\_'],
            $term
        );

        return '%'.$escaped.'%';
    }
}
