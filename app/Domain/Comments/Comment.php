<?php

namespace App\Domain\Comments;

use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Like;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Media;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Commento locale. Concettualmente e' una Note con "inReplyTo" (vedi il
 * design generale di Openbook); la serializzazione ActivityStreams arrivera'
 * in Fase 3.
 *
 * @property string $id
 * @property string $post_id
 * @property string|null $parent_comment_id
 * @property string $actor_id
 * @property string|null $uri
 * @property string $body
 * @property string $status
 * @property int $likes_count
 * @property int $replies_count
 * @property Carbon|null $edited_at
 */
class Comment extends Model
{
    use HasUuids;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DELETED = 'deleted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'parent_comment_id',
        'actor_id',
        'uri',
        'body',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'likes_count' => 'integer',
            'replies_count' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_comment_id')->orderBy('created_at');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function mentions(): MorphMany
    {
        return $this->morphMany(Mention::class, 'mentionable');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CommentAttachment::class)->orderBy('position');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'comment_attachments')
            ->withPivot('position')
            ->orderBy('comment_attachments.position');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Vedi {@see Post::isRemote()}: stessa convenzione per i commenti
     * ricevuti da un Actor remoto tramite l'inbox.
     */
    public function isRemote(): bool
    {
        return $this->uri !== null;
    }

    public function isReply(): bool
    {
        return $this->parent_comment_id !== null;
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /**
     * Vedi {@see Post::annotateViewerState()}: stessa
     * logica, applicata ai commenti per evitare N+1 nel rendering del thread.
     *
     * @param  iterable<int, Comment>  $comments
     */
    public static function annotateViewerState(iterable $comments, ?Actor $viewer): void
    {
        $comments = collect($comments);

        if ($viewer === null || $comments->isEmpty()) {
            foreach ($comments as $comment) {
                $comment->setAttribute('liked_by_viewer', false);
            }

            return;
        }

        $commentIds = $comments->pluck('id');

        $likedIds = Like::query()
            ->where('actor_id', $viewer->id)
            ->where('likeable_type', (new self)->getMorphClass())
            ->whereIn('likeable_id', $commentIds)
            ->pluck('likeable_id')
            ->all();

        foreach ($comments as $comment) {
            $comment->setAttribute('liked_by_viewer', in_array($comment->id, $likedIds, true));
        }
    }
}
