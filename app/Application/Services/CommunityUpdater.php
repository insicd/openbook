<?php

namespace App\Application\Services;

use App\Domain\Communities\Community;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Infrastructure\Media\ProfileImageUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Aggiorna i dati pubblici di una community locale (nome, descrizione,
 * avatar, copertina) sull'Actor Group: i Group non hanno una riga
 * {@see \App\Domain\Profiles\Profile}, quindi icon/image vivono su
 * {@code actors.icon_url}/{@code image_url}. Ogni modifica viene notificata
 * ai follower remoti del Group con un "Update".
 */
final class CommunityUpdater
{
    public function __construct(
        private readonly ProfileImageUploader $imageUploader,
        private readonly ActivityDelivery $delivery,
    ) {}

    /**
     * @param  array{name: string, summary?: string|null}  $data
     */
    public function update(Community $community, array $data, ?UploadedFile $avatar, ?UploadedFile $cover): void
    {
        $actor = $community->actor;
        $updates = [
            'name' => $data['name'],
            'summary' => $data['summary'] ?? null,
        ];

        if ($avatar !== null) {
            $path = $this->imageUploader->storeAvatar(
                $avatar,
                $this->storagePathFromPublicUrl($actor->icon_url),
            );
            $updates['icon_url'] = Storage::disk('public')->url($path);
        }

        if ($cover !== null) {
            $path = $this->imageUploader->storeCover(
                $cover,
                $this->storagePathFromPublicUrl($actor->image_url),
            );
            $updates['image_url'] = Storage::disk('public')->url($path);
        }

        $actor->update($updates);
        $actor->refresh();

        $this->delivery->deliverToFollowers($actor, ActivitySerializer::updateActor($actor));
    }

    /**
     * Estrae il percorso relativo sul disco "public" da un URL assoluto
     * generato da Storage, cosi' {@see ProfileImageUploader} puo' cancellare
     * il file precedente. URL remoti (non nostri) vengono ignorati.
     */
    private function storagePathFromPublicUrl(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }

        $base = Storage::disk('public')->url('');

        if (str_starts_with($url, $base)) {
            return ltrim(substr($url, strlen($base)), '/');
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $prefix = '/storage/';

        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return null;
    }
}
