<?php

namespace App\Domain\Accounts;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferenze personali e impostazioni di privacy dell'account locale.
 *
 * @property string $id
 * @property string $user_id
 * @property string $locale
 * @property string $timezone
 * @property bool $manually_approves_followers
 * @property string $default_post_visibility
 * @property bool $discoverable
 * @property string $direct_message_policy
 */
class UserSetting extends Model
{
    use HasUuids;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_UNLISTED = 'unlisted';

    public const VISIBILITY_FOLLOWERS = 'followers';

    public const VISIBILITY_DIRECT = 'direct';

    public const DM_POLICY_EVERYONE = 'everyone';

    public const DM_POLICY_FOLLOWERS = 'followers';

    public const DM_POLICY_NOBODY = 'nobody';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'locale',
        'timezone',
        'manually_approves_followers',
        'default_post_visibility',
        'discoverable',
        'direct_message_policy',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manually_approves_followers' => 'boolean',
            'discoverable' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
