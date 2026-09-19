<?php

namespace App\Domain\Events;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventLink extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['event_id', 'position', 'url', 'name', 'media_type'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
