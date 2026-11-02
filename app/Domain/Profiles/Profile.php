<?php

namespace App\Domain\Profiles;

use App\Domain\Accounts\User;
use App\Infrastructure\Media\AssetStorage;
use App\Infrastructure\Media\Media;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dati di presentazione pubblica di un account locale.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $display_name
 * @property string|null $bio
 * @property string $avatar_disk
 * @property string $cover_disk
 * @property string|null $avatar_path
 * @property string|null $cover_path
 * @property array<int, array{label: string, value?: string, url?: string}>|null $links
 */
class Profile extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'display_name',
        'bio',
        'avatar_path',
        'avatar_disk',
        'cover_path',
        'cover_disk',
        'links',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'links' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** URL coerenti con Media e con il disco registrato sul singolo asset. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? app(AssetStorage::class)->url($this->avatar_disk ?? 'public', $this->avatar_path, 'profile', $this->id, 'avatar') : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? app(AssetStorage::class)->url($this->cover_disk ?? 'public', $this->cover_path, 'profile', $this->id, 'cover') : null;
    }
}
