<?php

namespace App\Domain\Notifications;

use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Notifica locale (non federata: il destinatario e' sempre un utente di
 * questa istanza). "notifiable" punta all'oggetto coinvolto (post, commento,
 * follow) cosi' l'interfaccia puo' generare un link diretto senza dover
 * interpretare il tipo a partire da una stringa libera.
 *
 * @property string $id
 * @property string $recipient_id
 * @property string|null $actor_id
 * @property string $type
 * @property string $notifiable_type
 * @property string $notifiable_id
 * @property Carbon|null $read_at
 */
class Notification extends Model
{
    use HasUuids;

    public const TYPE_NEW_FOLLOWER = 'new_follower';

    public const TYPE_FOLLOW_REQUEST = 'follow_request';

    public const TYPE_FOLLOW_ACCEPTED = 'follow_accepted';

    public const TYPE_LIKE = 'like';

    public const TYPE_COMMENT = 'comment';

    public const TYPE_REPLY = 'reply';

    public const TYPE_MENTION = 'mention';

    public const TYPE_SHARE = 'share';

    public const TYPE_QUOTE = 'quote';

    public const TYPE_COMMUNITY_POST = 'community_post';

    public const TYPE_DIRECT_MESSAGE = 'direct_message';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'recipient_id',
        'actor_id',
        'type',
        'notifiable_type',
        'notifiable_id',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Testo localizzato della notifica, con i placeholder tipici (:name e,
     * per i post in community, :community).
     */
    public function message(): string
    {
        return __('openbook.notifications.messages.'.$this->type, $this->messageReplacements());
    }

    /**
     * @return array{name: string, community?: string}
     */
    public function messageReplacements(): array
    {
        $replacements = [
            'name' => $this->actor?->displayName() ?: __('openbook.notifications.someone'),
        ];

        if ($this->type === self::TYPE_COMMUNITY_POST) {
            $replacements['community'] = $this->communityDisplayName();
        }

        return $replacements;
    }

    private function communityDisplayName(): string
    {
        $target = $this->notifiable;

        if ($target instanceof Post) {
            $target->loadMissing('community.actor');
            $name = $target->community?->actor?->displayName()
                ?: $target->community?->slug;

            if (filled($name)) {
                return (string) $name;
            }
        }

        return __('openbook.notifications.a_community');
    }

    /**
     * URL a cui portare l'utente quando clicca sulla notifica: il post
     * coinvolto (eventualmente ancorato al commento) oppure il profilo di chi
     * ha inviato/accettato una richiesta di follow.
     */
    public function targetUrl(): ?string
    {
        $target = $this->notifiable;

        if ($target instanceof Post) {
            if ($target->conversation_id !== null && $this->type === self::TYPE_DIRECT_MESSAGE) {
                return route('messages.show', $target->conversation_id);
            }

            return route('posts.show', $target);
        }

        if ($target instanceof Comment) {
            return route('posts.show', $target->post_id).'#commento-'.$target->id;
        }

        if ($target instanceof Follow) {
            $profileActor = $this->type === self::TYPE_FOLLOW_ACCEPTED ? $target->following : $target->follower;

            return $profileActor?->profileUrl();
        }

        return null;
    }
}
