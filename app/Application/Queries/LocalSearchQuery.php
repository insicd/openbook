<?php

namespace App\Application\Queries;

use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Http\Controllers\SearchController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Ricerca locale per parole chiave: posta, commenti, hashtag ed
 * eventi conservati dall'istanza. Post e commenti restano limitati ai
 * contenuti locali indicizzabili; gli eventi includono anche quelli remoti
 * ricevuti via ActivityPub, perche' costituiscono il catalogo eventi locale.
 *
 * Volutamente basata su LIKE case-insensitive, senza Elasticsearch ne'
 * indici FULLTEXT: i vincoli di shared hosting di Openbook escludono un
 * motore di ricerca dedicato, e le tabelle tipiche di un'istanza di
 * questa scala restano gestibili con query semplici. I caratteri jolly
 * LIKE (`%`, `_`) digitati dall'utente vengono escapati (con clausola
 * ESCAPE su un carattere neutro, portabile fra MySQL/MariaDB e SQLite).
 *
 * Le query usano JOIN al posto di whereHas annidati dove possibile, per
 * ridurre il carico sul database: su hosting con limite di nuove
 * connessioni/secondo, query lunghe che fanno cadere la connessione
 * vengono poi ripresentate come `SQLSTATE 2002 Operation not permitted`.
 *
 * La ricerca federata per indirizzo `utente@dominio` resta fuori da questa
 * classe: la gestisce direttamente {@see SearchController}. I post e i
 * commenti pubblici di un autore locale comparono solo se l'Actor ha
 * `indexable` (FEP-5feb); l'autore trova comunque i propri contenuti.
 */
final class LocalSearchQuery
{
    /**
     * @return array{
     *     posts: Collection<int, Post>,
     *     comments: Collection<int, Comment>,
     *     hashtags: Collection<int, Hashtag>,
     *     events: Collection<int, Event>
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
            'posts' => $this->posts($pattern, $viewer, $limit),
            'comments' => $this->comments($pattern, $viewer, $limit),
            'hashtags' => $this->hashtags(Hashtag::normalize($term), $limit),
            'events' => $this->events($pattern, $viewer, $limit),
        ];
    }

    /**
     * Suggerimenti hashtag mentre si digita nella ricerca.
     *
     * @return Collection<int, Hashtag>
     */
    public function suggestHashtags(string $term, int $limit): Collection
    {
        $term = trim($term);
        $minLength = (int) config(
            'openbook.search.suggest_min_length',
            (int) config('openbook.search.min_length', 2),
        );
        if ($term === '' || mb_strlen(ltrim($term, '#')) < $minLength) {
            return collect();
        }

        $normalizedHashtag = Hashtag::normalize($term);

        return $this->hashtags($normalizedHashtag, $limit);
    }

    /**
     * @return array{
     *     posts: Collection<int, Post>,
     *     comments: Collection<int, Comment>,
     *     hashtags: Collection<int, Hashtag>,
     *     events: Collection<int, Event>
     * }
     */
    private function empty(): array
    {
        return [
            'posts' => collect(),
            'comments' => collect(),
            'hashtags' => collect(),
            'events' => collect(),
        ];
    }

    /**
     * @return Collection<int, Post>
     */
    private function posts(string $pattern, ?Actor $viewer, int $limit): Collection
    {
        $posts = Post::query()
            ->select('posts.*')
            ->with(Post::CARD_RELATIONS)
            ->excludingPrivateMessages()
            ->join('actors', 'actors.id', '=', 'posts.actor_id')
            ->where('actors.is_local', true)
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->whereNull('posts.uri')
            ->where(function ($query) use ($pattern) {
                $this->whereContains($query, 'posts.body', $pattern);
                $query->orWhere(function ($query) use ($pattern) {
                    $this->whereContains($query, 'posts.title', $pattern);
                });
                $query->orWhere(function ($query) use ($pattern) {
                    $this->whereContains($query, 'posts.content_warning', $pattern);
                });
            })
            ->visibleTo($viewer)
            ->where(function ($query) use ($viewer) {
                $query->where(function ($query) {
                    $query->where('actors.indexable', true)
                        ->where('posts.visibility', Post::VISIBILITY_PUBLIC);
                });

                if ($viewer !== null) {
                    $query->orWhere('posts.actor_id', $viewer->id);
                }
            })
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id')
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
            ->select('comments.*')
            ->with(['actor.user.profile', 'post.actor.user.profile'])
            ->join('actors', 'actors.id', '=', 'comments.actor_id')
            ->join('posts', 'posts.id', '=', 'comments.post_id')
            ->where('actors.is_local', true)
            ->where('comments.status', Comment::STATUS_PUBLISHED)
            ->whereNull('comments.uri')
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->where(function ($query) use ($pattern) {
                $this->whereContains($query, 'comments.body', $pattern);
            })
            ->where(function ($query) use ($viewer) {
                $query->where(function ($query) {
                    $query->where('actors.indexable', true)
                        ->where('posts.visibility', Post::VISIBILITY_PUBLIC);
                });

                if ($viewer !== null) {
                    $query->orWhere('comments.actor_id', $viewer->id);
                }
            })
            ->where(function ($query) use ($viewer) {
                // Stesse regole di Post::visibleTo, ma con colonne
                // qualificate: qui "posts" e' in JOIN, non la tabella base.
                $query->whereIn('posts.visibility', [
                    Post::VISIBILITY_PUBLIC,
                    Post::VISIBILITY_UNLISTED,
                ]);

                if ($viewer === null) {
                    return;
                }

                $query->orWhere('posts.actor_id', $viewer->id)
                    ->orWhere(function ($query) use ($viewer) {
                        $query->where('posts.visibility', Post::VISIBILITY_FOLLOWERS)
                            ->whereIn('posts.actor_id', function ($sub) use ($viewer) {
                                $sub->select('following_id')
                                    ->from('follows')
                                    ->where('follower_id', $viewer->id)
                                    ->where('status', 'accepted');
                            });
                    })
                    ->orWhere(function ($query) use ($viewer) {
                        $query->where('posts.visibility', Post::VISIBILITY_DIRECT)
                            ->whereExists(function ($sub) use ($viewer) {
                                $sub->selectRaw('1')
                                    ->from('mentions')
                                    ->whereColumn('mentions.mentionable_id', 'posts.id')
                                    ->where('mentions.mentionable_type', 'post')
                                    ->where('mentions.actor_id', $viewer->id);
                            });
                    });
            })
            ->where(function ($query) use ($viewer) {
                $query->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('communities')
                        ->whereColumn('communities.id', 'posts.community_id')
                        ->where('communities.is_private', true);
                });

                if ($viewer === null) {
                    return;
                }

                $query->orWhere('posts.actor_id', $viewer->id)
                    ->orWhereExists(function ($sub) use ($viewer) {
                        $sub->selectRaw('1')
                            ->from('communities')
                            ->join('follows', 'follows.following_id', '=', 'communities.actor_id')
                            ->whereColumn('communities.id', 'posts.community_id')
                            ->where('communities.is_private', true)
                            ->where('follows.follower_id', $viewer->id)
                            ->where('follows.status', 'accepted');
                    });
            })
            ->orderByDesc('comments.created_at')
            ->orderByDesc('comments.id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Hashtag>
     */
    private function hashtags(string $normalizedTerm, int $limit): Collection
    {
        if ($normalizedTerm === '') {
            return collect();
        }

        $pattern = $this->likePattern($normalizedTerm);

        return Hashtag::query()
            ->where(function ($query) use ($pattern) {
                $this->whereContains($query, 'name', $pattern);
            })
            // Match esatto in cima quando si cerca "#tag" o "tag".
            ->orderByRaw('case when name = ? then 0 else 1 end', [$normalizedTerm])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Event> */
    private function events(string $pattern, ?Actor $viewer, int $limit): Collection
    {
        $now = now();

        return Event::query()
            ->with(['actor.user.profile', 'location', 'media.thumbnail', 'attributions.user.profile'])
            ->visibleTo($viewer)
            ->whereIn('status', [
                Event::STATUS_SCHEDULED,
                Event::STATUS_CANCELLED,
                Event::STATUS_TENTATIVE,
                Event::STATUS_POSTPONED,
            ])
            ->where(function (Builder $query) use ($pattern): void {
                $this->whereContains($query, 'events.name', $pattern);
                $query->orWhere(fn (Builder $query) => $this->whereContains($query, 'events.summary', $pattern));
                $query->orWhere(fn (Builder $query) => $this->whereContains($query, 'events.content', $pattern));
                $query->orWhere(fn (Builder $query) => $this->whereContains($query, 'events.category', $pattern));
                $query->orWhereHas('location', function (Builder $query) use ($pattern): void {
                    $this->whereContains($query, 'event_locations.name', $pattern);
                    $query->orWhere(fn (Builder $query) => $this->whereContains($query, 'event_locations.address', $pattern));
                    $query->orWhere(fn (Builder $query) => $this->whereContains($query, 'event_locations.locality', $pattern));
                    $query->orWhere(fn (Builder $query) => $this->whereContains($query, 'event_locations.region', $pattern));
                    $query->orWhere(fn (Builder $query) => $this->whereContains($query, 'event_locations.country_name', $pattern));
                });
                $query->orWhereHas('hashtags', fn (Builder $query) => $this->whereContains($query, 'hashtags.name', $pattern));
            })
            ->orderByRaw('case when coalesce(end_at, start_at) >= ? then 0 else 1 end', [$now])
            ->orderByRaw('case when coalesce(end_at, start_at) >= ? then start_at end asc', [$now])
            ->orderByRaw('case when coalesce(end_at, start_at) < ? then start_at end desc', [$now])
            ->orderBy('events.name')
            ->limit($limit)
            ->get();
    }

    /**
     * LIKE con ESCAPE esplicito su un carattere neutro (`!`), non sul
     * backslash: su MySQL/MariaDB `ESCAPE '\'` e' sintassi invalida perche'
     * il backslash escapa la virgoletta di chiusura della stringa, e su
     * SQLite (usato nei test) il backslash non e' comunque l'escape di
     * default di LIKE. Con `!` restiamo portabili su entrambi i driver.
     *
     * @param  Builder<Model>  $query
     */
    private function whereContains(Builder $query, string $column, string $pattern): void
    {
        $query->whereRaw("{$query->getGrammar()->wrap($column)} LIKE ? ESCAPE '!'", [$pattern]);
    }

    private function likePattern(string $term): string
    {
        $escaped = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $term
        );

        return '%'.$escaped.'%';
    }
}
