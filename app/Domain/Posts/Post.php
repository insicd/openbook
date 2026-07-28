<?php

namespace App\Domain\Posts;

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
 * @property string|null $uri
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
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'uri',
        'title',
        'content_warning',
        'body',
        'language',
        'visibility',
        'status',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'edited_at' => 'datetime',
            'likes_count' => 'integer',
            'comments_count' => 'integer',
            'announces_count' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
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
        return $this->belongsToMany(Hashtag::class, 'post_hashtags');
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
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeVisibleTo(Builder $query, ?Actor $viewer): Builder
    {
        return $query->where(function (Builder $query) use ($viewer) {
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
        });
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
}
