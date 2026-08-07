<?php

namespace App\Domain\Posts;

use App\Application\Queries\FeedQuery;
use App\Domain\Communities\Community;
use App\Domain\Messaging\Conversation;
use App\Domain\Reactions\Announce;
use App\Domain\Reactions\Like;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Post locale (dominio applicativo). La rappresentazione ActivityStreams
 * (Note, con eventuali allegati Image) e' generata da classi dedicate in
 * App\Federation\Serialization quando la federazione sara' attiva: qui vive
 * solo lo stato applicativo.
 *
 * @property string $id
 * @property string $actor_id
 * @property string|null $conversation_id
 * @property string|null $uri
 * @property string|null $quoted_post_id
 * @property string|null $title
 * @property string|null $content_warning
 * @property string $body
 * @property string|null $language
 * @property string $visibility
 * @property string $status
 * @property int $likes_count
 * @property int $comments_count
 * @property int $announces_count
 * @property Carbon $published_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $replies_fetched_at ultimo tentativo di recupero della collection replies (post remoti)
 * @property string|null $shared_by_actor_id solo quando la riga proviene da {@see FeedQuery}, che lo valorizza con una subquery su "announces"
 * @property Carbon|null $shared_at vedi $shared_by_actor_id
 * @property-read Actor|null $sharedBy vedi {@see self::attachSharedBy()}
 * @property-read Post|null $quotedPost
 */
class Post extends Model
{
    use HasUuids;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_UNLISTED = 'unlisted';

    public const VISIBILITY_FOLLOWERS = 'followers';

    public const VISIBILITY_DIRECT = 'direct';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DELETED = 'deleted';

    /**
     * Relazioni da eager-loadare insieme a ogni elenco di post, cosi' le
     * citazioni annidate non generano N+1 sul feed / profilo / ricerca.
     *
     * @var list<string>
     */
    public const CARD_RELATIONS = [
        'actor.user.profile',
        'community.actor',
        'media.thumbnail',
        'hashtags',
        'quotedPost.actor.user.profile',
        'quotedPost.media.thumbnail',
        'quotedPost.hashtags',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'community_id',
        'conversation_id',
        'uri',
        'quoted_post_id',
        'title',
        'content_warning',
        'body',
        'language',
        'visibility',
        'status',
        'published_at',
        'replies_fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'edited_at' => 'datetime',
            'replies_fetched_at' => 'datetime',
            'likes_count' => 'integer',
            'comments_count' => 'integer',
            'announces_count' => 'integer',
            'shared_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isDirectMessage(): bool
    {
        return $this->visibility === self::VISIBILITY_DIRECT && $this->conversation_id !== null;
    }

    /**
     * Post citato (quote): presente solo sulle citazioni create localmente.
     * nullOnDelete in migration: se l'originale sparisce, la citazione resta
     * come post autonomo senza card annidata.
     */
    public function quotedPost(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quoted_post_id');
    }

    public function isQuote(): bool
    {
        return $this->quoted_post_id !== null;
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PostAttachment::class)->orderBy('position');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'post_attachments')
            ->withPivot('position')
            ->orderBy('post_attachments.position');
    }

    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'post_hashtags')
            ->where('hashtags.name', '!=', '');
    }

    public function mentions(): MorphMany
    {
        return $this->morphMany(Mention::class, 'mentionable');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Un post "remoto" e' una copia in cache locale di una Note creata da un
     * Actor remoto (arrivata via inbox): riconoscibile perche' possiede un
     * "uri" ActivityPub esplicito, a differenza dei post locali il cui
     * identificatore canonico e' sempre derivato da "/posts/{id}".
     */
    public function isRemote(): bool
    {
        return $this->uri !== null;
    }

    public function hasContentWarning(): bool
    {
        return filled($this->content_warning);
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /**
     * Applica le regole di visibilita': pubblica e non elencata sono sempre
     * visibili; solo-follower richiede un follow accettato; i post diretti
     * sono visibili solo all'autore e agli attori esplicitamente menzionati
     * (in assenza, per questo milestone, di un vero elenco di destinatari).
     *
     * I post in una community *privata* restano visibili solo all'autore e
     * ai membri accettati del Group, indipendentemente dalla visibility
     * salvata (cosi' non compaiono su profilo/feed di chi non e' iscritto).
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeVisibleTo(Builder $query, ?Actor $viewer): Builder
    {
        return $query
            ->where(function (Builder $query) use ($viewer) {
                $query->where('visibility', self::VISIBILITY_PUBLIC)
                    ->orWhere('visibility', self::VISIBILITY_UNLISTED);

                if ($viewer === null) {
                    return;
                }

                $query->orWhere('actor_id', $viewer->id);

                $query->orWhere(function (Builder $query) use ($viewer) {
                    $query->where('visibility', self::VISIBILITY_FOLLOWERS)
                        ->whereIn('actor_id', function ($sub) use ($viewer) {
                            $sub->select('following_id')
                                ->from('follows')
                                ->where('follower_id', $viewer->id)
                                ->where('status', 'accepted');
                        });
                });

                $query->orWhere(function (Builder $query) use ($viewer) {
                    $query->where('visibility', self::VISIBILITY_DIRECT)
                        ->whereExists(function ($sub) use ($viewer) {
                            $sub->selectRaw('1')
                                ->from('mentions')
                                ->whereColumn('mentions.mentionable_id', 'posts.id')
                                ->where('mentions.mentionable_type', 'post')
                                ->where('mentions.actor_id', $viewer->id);
                        });
                });
            })
            ->where(function (Builder $query) use ($viewer) {
                $query->whereDoesntHave('community', fn (Builder $community) => $community->where('is_private', true));

                if ($viewer === null) {
                    return;
                }

                $query->orWhere('actor_id', $viewer->id)
                    ->orWhereHas('community', function (Builder $community) use ($viewer) {
                        $community->where('is_private', true)
                            ->whereIn('actor_id', function ($sub) use ($viewer) {
                                $sub->select('following_id')
                                    ->from('follows')
                                    ->where('follower_id', $viewer->id)
                                    ->where('status', 'accepted');
                            });
                    });
            });
    }

    public function isInPrivateCommunity(): bool
    {
        $this->loadMissing('community');

        return $this->community !== null && $this->community->is_private;
    }

    /**
     * Annota una collezione di post con lo stato "mi piace/condiviso da chi
     * sta guardando", con due sole query aggiuntive indipendentemente dal
     * numero di post (evita l'N+1 nel rendering del feed). Gli attributi
     * "liked_by_viewer" e "announced_by_viewer" non corrispondono a colonne
     * reali: vivono solo in memoria per la durata della richiesta.
     *
     * @param  iterable<int, Post>  $posts
     */
    public static function annotateViewerState(iterable $posts, ?Actor $viewer): void
    {
        $posts = collect($posts);

        if ($viewer === null || $posts->isEmpty()) {
            foreach ($posts as $post) {
                $post->setAttribute('liked_by_viewer', false);
                $post->setAttribute('announced_by_viewer', false);
            }

            return;
        }

        $postIds = $posts->pluck('id');

        $likedIds = Like::query()
            ->where('actor_id', $viewer->id)
            ->where('likeable_type', (new self)->getMorphClass())
            ->whereIn('likeable_id', $postIds)
            ->pluck('likeable_id')
            ->all();

        $announcedIds = Announce::query()
            ->where('actor_id', $viewer->id)
            ->whereIn('post_id', $postIds)
            ->pluck('post_id')
            ->all();

        foreach ($posts as $post) {
            $post->setAttribute('liked_by_viewer', in_array($post->id, $likedIds, true));
            $post->setAttribute('announced_by_viewer', in_array($post->id, $announcedIds, true));
        }
    }

    /**
     * Trasforma "shared_by_actor_id" (colonna virtuale valorizzata da
     * {@see FeedQuery} con una subquery su
     * "announces", presente solo su post che compaiono in un feed/profilo
     * *perche'* qualcuno li ha condivisi) nella relazione "sharedBy" usata
     * dalla card per mostrare "X ha condiviso", con un'unica query
     * indipendentemente dal numero di post (stesso approccio di
     * {@see self::annotateViewerState()}). Una condivisione del proprio
     * stesso post non genera l'indicazione: sarebbe ridondante, il post
     * compare gia' come proprio.
     *
     * @param  iterable<int, Post>  $posts
     */
    public static function attachSharedBy(iterable $posts): void
    {
        $posts = collect($posts);

        $actorIds = $posts
            ->filter(fn (self $post) => $post->shared_by_actor_id !== null && $post->shared_by_actor_id !== $post->actor_id)
            ->pluck('shared_by_actor_id')
            ->unique()
            ->values();

        $actors = $actorIds->isEmpty()
            ? collect()
            : Actor::query()->with('user.profile')->whereIn('id', $actorIds)->get()->keyBy('id');

        foreach ($posts as $post) {
            $sharerId = $post->shared_by_actor_id;
            $post->setRelation('sharedBy', ($sharerId !== null && $sharerId !== $post->actor_id) ? $actors->get($sharerId) : null);
        }
    }
}
