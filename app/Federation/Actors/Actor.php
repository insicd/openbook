<?php

namespace App\Federation\Actors;

use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Http\Controllers\ActorProfileController;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Rappresentazione unificata di un Actor ActivityPub.
 *
 * Copre sia gli attori locali (oggi solo "person" per gli utenti registrati,
 * in futuro "group" per le community) sia la cache degli attori remoti
 * scoperti tramite federazione. Il campo {@see self::$is_local} distingue
 * senza ambiguita' le due categorie, come richiesto dall'architettura di
 * Openbook: qui vive soltanto la rappresentazione ActivityPub, mai la logica
 * di dominio applicativo (che resta in App\Domain\*).
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $type
 * @property bool $is_local
 * @property string $preferred_username
 * @property string $domain
 * @property string $uri
 * @property string|null $name
 * @property string|null $summary
 * @property string|null $icon_url
 * @property string|null $image_url
 * @property bool $manually_approves_followers
 * @property string $status
 * @property Carbon|null $last_fetched_at
 * @property Carbon|null $posts_fetched_at
 */
class Actor extends Model
{
    use HasUuids;

    public const TYPE_PERSON = 'person';

    public const TYPE_GROUP = 'group';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'is_local',
        'preferred_username',
        'domain',
        'uri',
        'name',
        'summary',
        'icon_url',
        'image_url',
        'manually_approves_followers',
        'status',
        'last_fetched_at',
        'posts_fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_local' => 'boolean',
            'manually_approves_followers' => 'boolean',
            'last_fetched_at' => 'datetime',
            'posts_fetched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function key(): HasOne
    {
        return $this->hasOne(ActorKey::class);
    }

    public function endpoints(): HasOne
    {
        return $this->hasOne(ActorEndpoint::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Relazioni di follow in cui questo attore e' il follower (chi segue).
     */
    public function followedRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    /**
     * Relazioni di follow in cui questo attore e' seguito (i suoi follower).
     */
    public function followerRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'following_id');
    }

    public function isLocal(): bool
    {
        return $this->is_local;
    }

    public function isPerson(): bool
    {
        return $this->type === self::TYPE_PERSON;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Indirizzo federato "username@dominio" (formato WebFinger senza "acct:").
     */
    public function handle(): string
    {
        return sprintf('%s@%s', $this->preferred_username, $this->domain);
    }

    /**
     * Nome visualizzato uniforme sia per attori locali (profilo utente) sia
     * per attori remoti (campo "name" del documento Actor, aggiornato a ogni
     * fetch da {@see RemoteActorResolver}): permette
     * alle viste di renderizzare autori di post/commenti senza distinguere
     * esplicitamente i due casi.
     */
    public function displayName(): string
    {
        if ($this->isLocal()) {
            $displayName = $this->user?->profile?->display_name;

            return filled($displayName) ? $displayName : $this->preferred_username;
        }

        return filled($this->name) ? $this->name : $this->preferred_username;
    }

    /**
     * Vedi {@see self::displayName()}: stessa uniformita' per l'avatar.
     */
    public function avatarUrl(): ?string
    {
        if ($this->isLocal()) {
            return $this->user?->profile?->avatarUrl();
        }

        return $this->icon_url;
    }

    /**
     * Vedi {@see self::displayName()}: stessa uniformita' per l'immagine di
     * copertina (campo "image" del documento Actor per i profili remoti).
     */
    public function coverUrl(): ?string
    {
        if ($this->isLocal()) {
            return $this->user?->profile?->coverUrl();
        }

        return $this->image_url;
    }

    /**
     * URL della pagina profilo da usare nelle viste HTML: l'identificatore
     * canonico "/@{username}" per gli attori locali, la pagina di comodo
     * "/attori/{id}" per la cache locale di un attore remoto (vedi
     * {@see ActorProfileController}).
     */
    public function profileUrl(): string
    {
        if ($this->isLocal()) {
            return route('profile.show', $this->preferred_username);
        }

        return route('actors.show', $this);
    }
}
