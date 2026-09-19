<?php

namespace App\Domain\Events;

use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Media;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventComment extends Model
{
    use HasUuids;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DELETED = 'deleted';

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'parent_event_comment_id',
        'actor_id',
        'uri',
        'body',
        'custom_emojis',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'custom_emojis' => 'array',
            'edited_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_comment_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_comment_id')->orderBy('created_at');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EventCommentAttachment::class)->orderBy('position');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'event_comment_attachments')
            ->withPivot('position')
            ->orderBy('event_comment_attachments.position');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
