<?php

namespace App\Domain\Events;

use App\Infrastructure\Media\Media;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCommentAttachment extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['event_comment_id', 'media_id', 'position'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(EventComment::class, 'event_comment_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
