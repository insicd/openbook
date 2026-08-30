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
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public const TYPE_FOLLOW_REJECTED = 'follow_rejected';

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

    public function pushNotification(): HasOne
    {
        return $this->hasOne(PushNotification::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Testo localizzato della notifica. I follow verso un Group locale usano
     * chiavi dedicate (iscrizione / richiesta di iscrizione), non "ti segue".
     */
    public function message(?string $locale = null): string
    {
        return __('openbook.notifications.messages.'.$this->messageKey(), $this->messageReplacements($locale), $locale);
    }

    /**
     * @return array{name: string, community?: string}
     */
    public function messageReplacements(?string $locale = null): array
    {
        $replacements = [
            'name' => $this->actor?->displayName() ?: __('openbook.notifications.someone', [], $locale),
        ];

        if ($this->messageNeedsCommunity()) {
            $replacements['community'] = $this->communityDisplayName($locale);
        }

        return $replacements;
    }

    /**
     * Come {@see message()}, con il nome di chi ha causato la notifica come
     * link al suo profilo Openbook (Person locale o Actor remoto in cache)
     * e, se c'e', il nome della community come link alla sua pagina.
     */
    public function messageHtml(): string
    {
        $replacements = $this->messageReplacements();
        $nameToken = '%%NAME%%';
        $communityToken = '%%COMMUNITY%%';

        $params = ['name' => $nameToken];

        if (isset($replacements['community'])) {
            $params['community'] = $communityToken;
        }

        $html = e(__('openbook.notifications.messages.'.$this->messageKey(), $params));
        $html = str_replace(e($nameToken), $this->actorNameHtml($replacements['name']), $html);

        if (isset($replacements['community'])) {
            $html = str_replace(e($communityToken), $this->communityNameHtml($replacements['community']), $html);
        }

        return $html;
    }

    public function actorProfileUrl(): ?string
    {
        return $this->actor?->profileUrl();
    }

    private function actorNameHtml(string $name): string
    {
        $escaped = e($name);
        $url = $this->actorProfileUrl();

        if ($url === null) {
            return $escaped;
        }

        return '<a href="'.e($url).'" class="ob-notification__actor-name">'.$escaped.'</a>';
    }

    private function communityNameHtml(string $name): string
    {
        $escaped = e($name);
        $url = $this->communityActor()?->profileUrl();

        if ($url === null) {
            return $escaped;
        }

        return '<a href="'.e($url).'" class="ob-notification__community-name">'.$escaped.'</a>';
    }

    private function messageKey(): string
    {
        if ($this->followedGroupActor() !== null) {
            return match ($this->type) {
                self::TYPE_NEW_FOLLOWER => 'community_join',
                self::TYPE_FOLLOW_REQUEST => 'community_join_request',
                default => $this->type,
            };
        }

        return $this->type;
    }

    private function messageNeedsCommunity(): bool
    {
        return in_array($this->messageKey(), [
            self::TYPE_COMMUNITY_POST,
            'community_join',
            'community_join_request',
        ], true);
    }

    private function communityDisplayName(?string $locale = null): string
    {
        $actor = $this->communityActor();
        $name = $actor?->displayName() ?: $actor?->preferred_username;

        if (filled($name)) {
            return (string) $name;
        }

        return __('openbook.notifications.a_community', [], $locale);
    }

    private function communityActor(): ?Actor
    {
        return $this->followedGroupActor() ?? $this->postCommunityActor();
    }

    private function followedGroupActor(): ?Actor
    {
        $target = $this->notifiable;

        if (! $target instanceof Follow) {
            return null;
        }

        $target->loadMissing('following');
        $following = $target->following;

        if ($following === null || ! $following->isGroup()) {
            return null;
        }

        return $following;
    }

    private function postCommunityActor(): ?Actor
    {
        $target = $this->notifiable;

        if (! $target instanceof Post) {
            return null;
        }

        $target->loadMissing('community.actor');

        return $target->community?->actor;
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
            $profileActor = in_array($this->type, [self::TYPE_FOLLOW_ACCEPTED, self::TYPE_FOLLOW_REJECTED], true)
                ? $target->following
                : $target->follower;

            return $profileActor?->profileUrl();
        }

        if ($target instanceof Actor && $this->type === self::TYPE_FOLLOW_REJECTED) {
            return $target->profileUrl();
        }

        return null;
    }
}
