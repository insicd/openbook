<?php

namespace App\Domain\Events;

use App\Infrastructure\Media\Media;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttachment extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['event_id', 'media_id', 'position'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
