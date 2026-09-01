<?php

namespace App\Domain\Accounts;

use App\Domain\Notifications\PushSubscription;
use App\Domain\Profiles\Profile;
use App\Federation\Actors\Actor;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Identita' di autenticazione di un account locale.
 *
 * I dati di presentazione pubblica vivono in {@see Profile}, le preferenze
 * in {@see UserSetting} e la rappresentazione ActivityPub in {@see Actor}.
 * Questa separazione riflette la distinzione architetturale tra dominio
 * applicativo e federazione richiesta per Openbook.
 *
 * @property string $id
 * @property string $username
 * @property string $email
 * @property string $password
 * @property bool $is_admin
 * @property bool $is_moderator
 * @property string $status
 * @property Carbon|null $last_login_at
 * @property int $notifications_revision
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'is_admin',
        'is_moderator',
        'status',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'notifications_revision' => 'integer',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_moderator' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'username';
    }

    /**
     * Il namespace del modello (App\Domain\Accounts) non corrisponde alla
     * convenzione predefinita usata da Laravel per individuare la factory,
     * quindi va indicata esplicitamente.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function actor(): HasOne
    {
        return $this->hasOne(Actor::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Staff di istanza: puo' aprire il pannello di controllo.
     */
    public function isStaff(): bool
    {
        return $this->is_admin || $this->is_moderator;
    }

    /**
     * Moderazione (segnalazioni, utenti): admin oppure moderatore.
     */
    public function canModerate(): bool
    {
        return $this->isStaff();
    }

    /**
     * Amministrazione completa dell'istanza (impostazioni, promozione mod).
     */
    public function canAdminister(): bool
    {
        return $this->is_admin;
    }

    /**
     * Indirizzo federato "username@dominio" mostrato nell'interfaccia.
     */
    public function federatedHandle(): string
    {
        return sprintf('%s@%s', $this->username, config('openbook.domain'));
    }

    /**
     * Segnala ai client in polling che badge/elenco notifiche sono cambiati
     * (nuova notifica o passaggio a "lette").
     */
    public function bumpNotificationsRevision(): void
    {
        static::query()->whereKey($this->id)->increment('notifications_revision');
    }
}
