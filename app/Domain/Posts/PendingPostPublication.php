<?php

namespace App\Domain\Posts;

use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PendingPostPublication extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'post_publication_queue';

    protected $fillable = [
        'actor_id',
        'payload',
        'status',
        'attempts',
        'claim_token',
        'claimed_at',
        'last_error',
        'post_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PendingPostAttachment::class, 'publication_id')->orderBy('position');
    }
}
