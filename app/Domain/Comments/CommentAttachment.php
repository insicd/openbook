<?php

namespace App\Domain\Comments;

use App\Infrastructure\Media\Media;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $comment_id
 * @property string $media_id
 * @property int $position
 */
class CommentAttachment extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'comment_id',
        'media_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
