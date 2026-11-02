<?php

namespace App\Application\Services;

use App\Domain\Communities\Community;
use App\Federation\Delivery\ActivityDelivery;
use App\Federation\Serialization\ActivitySerializer;
use App\Infrastructure\Media\MediaStorage;
use App\Infrastructure\Media\ProfileImageUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Aggiorna la presentazione della comunità locale. I riferimenti ai file
 * sono sulla comunità; nome e descrizione sull'actor Group federato.
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
        $actor = DB::transaction(function () use ($community, $data, $avatar, $cover) {
            $actor = $community->actor;
            $disk = app(MediaStorage::class)->uploadDisk();
            $updates = ['name' => $data['name'], 'summary' => $data['summary'] ?? null];
            if ($avatar !== null) {
                $community->avatar_path = $this->imageUploader->storeAvatar($avatar,
                    $community->avatar_path ?? $this->storagePathFromPublicUrl($actor->icon_url),
                    $community->avatar_disk ?? 'public', $disk);
                $community->avatar_disk = $disk;
                $updates['icon_url'] = null;
            }
            if ($cover !== null) {
                $community->cover_path = $this->imageUploader->storeCover($cover,
                    $community->cover_path ?? $this->storagePathFromPublicUrl($actor->image_url),
                    $community->cover_disk ?? 'public', $disk);
                $community->cover_disk = $disk;
                $updates['image_url'] = null;
            }
            $community->save();
            $actor->update($updates);

            return $actor->refresh()->setRelation('community', $community);
        });

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

        $base = rtrim(Storage::disk('public')->url(''), '/').'/';
        if (! str_starts_with($url, $base)) {
            return null;
        }
        $path = substr($url, strlen($base));

        return preg_match('~^(avatars|covers)/[a-zA-Z0-9._-]+$~', $path) ? $path : null;
    }
}
