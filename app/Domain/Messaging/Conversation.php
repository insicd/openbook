<?php

namespace App\Domain\Messaging;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Thread di messaggistica privata 1:1 tra due Actor (locali o remoti).
 *
 * @property string $id
 * @property string $participant_low_id
 * @property string $participant_high_id
 * @property Carbon|null $last_message_at
 */
class Conversation extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'participant_low_id',
        'participant_high_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function participantLow(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'participant_low_id');
    }

    public function participantHigh(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'participant_high_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Post::class)
            ->where('visibility', Post::VISIBILITY_DIRECT)
            ->where('status', Post::STATUS_PUBLISHED)
            ->orderBy('published_at');
    }

    public function otherParticipant(Actor $viewer): Actor
    {
        if ($this->participant_low_id === $viewer->id) {
            return $this->participantHigh;
        }

        return $this->participantLow;
    }

    public function involves(Actor $actor): bool
    {
        return $this->participant_low_id === $actor->id
            || $this->participant_high_id === $actor->id;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function orderParticipantIds(string $aId, string $bId): array
    {
        return strcmp($aId, $bId) < 0 ? [$aId, $bId] : [$bId, $aId];
    }
}
