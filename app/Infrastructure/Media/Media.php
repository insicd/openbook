<?php

namespace App\Infrastructure\Media;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * File immagine legato a un post o commento: locale (caricato su disco)
 * oppure remoto federato ({@see self::$remote_url}, senza download).
 *
 * Per i file locali "path" e' sempre un nome generato casualmente: non deve
 * mai essere costruito a partire da input dell'utente (path traversal).
 *
 * @property string $id
 * @property string $actor_id
 * @property string $disk
 * @property string $path
 * @property string|null $remote_url
 * @property string|null $original_name
 * @property string $mime_type
 * @property int $byte_size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 */
class Media extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'disk',
        'path',
        'remote_url',
        'original_name',
        'mime_type',
        'byte_size',
        'width',
        'height',
        'alt_text',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_attachments');
    }

    public function comments(): BelongsToMany
    {
        return $this->belongsToMany(Comment::class, 'comment_attachments');
    }

    public function thumbnail(): HasOne
    {
        return $this->hasOne(MediaVariant::class)->where('type', MediaVariant::TYPE_THUMBNAIL);
    }

    public function isRemote(): bool
    {
        return filled($this->remote_url);
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    public function url(): string
    {
        if ($this->isRemote()) {
            return (string) $this->remote_url;
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    public function thumbnailUrl(): string
    {
        if ($this->isRemote()) {
            return (string) $this->remote_url;
        }

        $thumbnail = $this->relationLoaded('thumbnail') ? $this->thumbnail : $this->thumbnail()->first();

        if ($thumbnail === null) {
            return $this->url();
        }

        return Storage::disk($thumbnail->disk)->url($thumbnail->path);
    }

    /**
     * URL da usare nel feed: le GIF restano animate (niente miniatura statica).
     */
    public function displayUrl(): string
    {
        if ($this->mime_type === 'image/gif' || $this->isVideo()) {
            return $this->url();
        }

        return $this->thumbnailUrl();
    }
}
