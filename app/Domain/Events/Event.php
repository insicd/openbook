<?php

namespace App\Domain\Events;

use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Mention;
use App\Domain\Reactions\Like;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Evento ActivityStreams locale o remoto, distinto dai post della timeline.
 *
 * @property string $id
 * @property string|null $actor_id Actor della Create, se ricevuta
 * @property string $uri
 * @property string|null $url
 * @property string $name
 * @property string|null $summary
 * @property string|null $content
 * @property array<string, string>|null $custom_emojis
 * @property string|null $language
 * @property string $visibility
 * @property string $status
 * @property string|null $join_mode
 * @property bool $sensitive
 * @property bool $is_online
 * @property string|null $external_participation_url
 * @property string|null $category
 * @property Carbon $start_at
 * @property Carbon|null $end_at
 * @property string|null $timezone
 * @property int|null $utc_offset_minutes
 * @property string|null $series_uri
 * @property int|null $participant_count
 * @property int|null $likes_count
 * @property Carbon|null $remote_counts_fetched_at
 * @property Carbon|null $published_at
 * @property Carbon|null $remote_updated_at
 * @property Carbon|null $deleted_at
 */
class Event extends Model
{
    use HasUuids;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_UNLISTED = 'unlisted';

    public const VISIBILITY_FOLLOWERS = 'followers';

    public const VISIBILITY_DIRECT = 'direct';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TENTATIVE = 'tentative';

    public const STATUS_POSTPONED = 'postponed';

    public const STATUS_DELETED = 'deleted';

    /** @var list<string> */
    protected $fillable = [
        'actor_id',
        'uri',
        'url',
        'name',
        'summary',
        'content',
        'custom_emojis',
        'language',
        'visibility',
        'status',
        'join_mode',
        'sensitive',
        'is_online',
        'external_participation_url',
        'category',
        'start_at',
        'end_at',
        'timezone',
        'utc_offset_minutes',
        'series_uri',
        'participant_count',
        'likes_count',
        'remote_counts_fetched_at',
        'published_at',
        'remote_updated_at',
        'deleted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'custom_emojis' => 'array',
            'sensitive' => 'boolean',
            'is_online' => 'boolean',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'utc_offset_minutes' => 'integer',
            'participant_count' => 'integer',
            'likes_count' => 'integer',
            'remote_counts_fetched_at' => 'datetime',
            'published_at' => 'datetime',
            'remote_updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function location(): HasOne
    {
        return $this->hasOne(EventLocation::class);
    }

    public function attributions(): BelongsToMany
    {
        return $this->belongsToMany(Actor::class, 'event_attributions')
            ->withPivot('position')
            ->orderBy('event_attributions.position');
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(Actor::class, 'event_recipients');
    }

    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'event_hashtags');
    }

    public function mentions(): MorphMany
    {
        return $this->morphMany(Mention::class, 'mentionable');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EventAttachment::class)->orderBy('position');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'event_attachments')
            ->withPivot('position')
            ->orderBy('event_attachments.position');
    }

    public function links(): HasMany
    {
        return $this->hasMany(EventLink::class)->orderBy('position');
    }

    public function announces(): HasMany
    {
        return $this->hasMany(EventAnnounce::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(EventParticipation::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(EventComment::class)->orderBy('created_at');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function isDeleted(): bool
    {
        return $this->status === self::STATUS_DELETED;
    }

    public function isRemote(): bool
    {
        $this->loadMissing('actor');

        return $this->actor !== null && ! $this->actor->isLocal();
    }

    public function isOpenForInteractions(): bool
    {
        if (! in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_TENTATIVE, self::STATUS_POSTPONED], true)) {
            return false;
        }

        $defaultHours = max(1, (int) config('openbook.events.default_duration_hours', 12));

        return $this->end_at !== null
            ? $this->end_at->isFuture()
            : $this->start_at->copy()->addHours($defaultHours)->isFuture();
    }

    public function distinctSummary(): ?string
    {
        $summary = trim((string) $this->summary);

        if ($summary === '') {
            return null;
        }

        if (filled($this->content)) {
            $summary = trim(Str::replaceFirst((string) $this->content, '', $summary));
        }

        return $summary !== '' ? $summary : null;
    }

    public function scopeVisibleTo(Builder $query, ?Actor $viewer): Builder
    {
        return $query->where(function (Builder $query) use ($viewer): void {
            $query->whereIn('visibility', [self::VISIBILITY_PUBLIC, self::VISIBILITY_UNLISTED]);

            if ($viewer === null) {
                return;
            }

            $query->orWhere('actor_id', $viewer->id)
                ->orWhereExists(function ($subquery) use ($viewer): void {
                    $subquery->selectRaw('1')
                        ->from('event_recipients')
                        ->whereColumn('event_recipients.event_id', 'events.id')
                        ->where('event_recipients.actor_id', $viewer->id);
                });
        });
    }
}
