<?php

namespace App\Infrastructure\Media;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * File caricato da un attore locale (oggi solo immagini per i post). "path"
 * e' sempre un nome generato casualmente: non deve mai essere costruito a
 * partire da input dell'utente per evitare path traversal.
 *
 * @property string $id
 * @property string $actor_id
 * @property string $disk
 * @property string $path
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

    public function thumbnail(): HasOne
    {
        return $this->hasOne(MediaVariant::class)->where('type', MediaVariant::TYPE_THUMBNAIL);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function thumbnailUrl(): string
    {
        $thumbnail = $this->relationLoaded('thumbnail') ? $this->thumbnail : $this->thumbnail()->first();

        if ($thumbnail === null) {
            return $this->url();
        }

        return Storage::disk($thumbnail->disk)->url($thumbnail->path);
    }
}
