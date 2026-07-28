<?php

namespace App\Infrastructure\Media;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $media_id
 * @property string $type
 * @property string $disk
 * @property string $path
 * @property int|null $width
 * @property int|null $height
 */
class MediaVariant extends Model
{
    use HasUuids;

    public const TYPE_THUMBNAIL = 'thumbnail';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'media_id',
        'type',
        'disk',
        'path',
        'width',
        'height',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
