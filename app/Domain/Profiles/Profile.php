<?php

namespace App\Domain\Profiles;

use App\Domain\Accounts\User;
use App\Infrastructure\Media\Media;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Stessa logica di {@see Media::url()}: l'URL
     * viene composto dal disco "public" ("filesystems.disks.public.url",
     * basato su APP_URL), non dall'helper asset(), cosi' da restare coerente
     * con lo schema/host configurati anche dietro proxy o reverse proxy che
     * non riportano correttamente lo schema originale della richiesta.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null;
    }
}
