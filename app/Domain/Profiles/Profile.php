<?php

namespace App\Domain\Profiles;

use App\Domain\Accounts\User;
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
 * @property string|null $avatar_path
 * @property string|null $cover_path
 * @property array<int, array{label: string, url: string}>|null $links
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
        'cover_path',
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

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? asset('storage/'.$this->cover_path) : null;
    }
}
