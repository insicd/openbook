<?php

namespace App\Application\Services;

use App\Domain\Accounts\User;
use App\Federation\Serialization\ActorSerializer;
use App\Infrastructure\Media\ProfileImageUploader;
use Illuminate\Http\UploadedFile;

/**
 * Applica le modifiche al profilo pubblico di un account locale (nome
 * visualizzato, biografia, link, avatar, copertina). Il nome visualizzato
 * viene replicato anche sull'Actor ActivityPub ("name"), che e' il campo
 * effettivamente esposto ai server remoti da {@see ActorSerializer}
 * e sarebbe altrimenti rimasto bloccato allo username scelto in fase di
 * registrazione.
 */
final class ProfileUpdater
{
    public function __construct(
        private readonly ProfileImageUploader $imageUploader,
    ) {}

    /**
     * @param  array{display_name: string, bio?: string|null, links?: array<int, array{label?: string|null, url?: string|null}>|null}  $data
     */
    public function update(User $user, array $data, ?UploadedFile $avatar, ?UploadedFile $cover): void
    {
        $profile = $user->profile;

        $profile->display_name = $data['display_name'];
        $profile->bio = $data['bio'] ?? null;
        $profile->links = $this->normalizeLinks($data['links'] ?? []);

        if ($avatar !== null) {
            $profile->avatar_path = $this->imageUploader->storeAvatar($avatar, $profile->avatar_path);
        }

        if ($cover !== null) {
            $profile->cover_path = $this->imageUploader->storeCover($cover, $profile->cover_path);
        }

        $profile->save();

        $user->actor?->update(['name' => $data['display_name']]);
    }

    /**
     * @param  array<int, array{label?: string|null, url?: string|null}>  $links
     * @return array<int, array{label: string, url: string}>
     */
    private function normalizeLinks(array $links): array
    {
        return collect($links)
            ->filter(fn (array $link) => filled($link['label'] ?? null) && filled($link['url'] ?? null))
            ->map(fn (array $link) => ['label' => $link['label'], 'url' => $link['url']])
            ->values()
            ->all();
    }
}
