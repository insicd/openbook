<?php

namespace App\Infrastructure\Media;

use App\Application\Services\InstanceSettings;
use App\Domain\Communities\Community;
use App\Domain\Profiles\Profile;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Concerns\NormalizesPublicDiskPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class AssetStorage
{
    use NormalizesPublicDiskPermissions;

    public function url(string $disk, string $path, string $kind, ?string $id, string $slot): string
    {
        if ($disk !== MediaStorage::OBJECT_DISK) {
            return Storage::disk($disk)->url($path);
        }

        return app(MediaStorage::class)->publicUrl($path) ?? route('assets.files.show', [
            'kind' => $kind, 'id' => $id, 'slot' => $slot, 'version' => sha1($path),
        ]);
    }

    /** @param array<string, string> $files */
    public function store(string $disk, array $files): void
    {
        app(MediaStorage::class)->storeFiles($disk, array_keys($files), function () use ($disk, $files): void {
            foreach ($files as $path => $contents) {
                if (! Storage::disk($disk)->put($path, $contents)) {
                    throw new \RuntimeException;
                }
                if ($disk === 'public') {
                    $this->ensurePublicDirectoryIsTraversable(dirname($path));
                    $this->ensurePublicFileIsReadable($path);
                }
            }
        });
    }

    /** @param list<string> $paths */
    public function removeAfterCommit(string $disk, array $paths): void
    {
        if ($paths !== []) {
            DB::afterCommit(fn () => app(MediaStorage::class)->cleanup($disk, $paths));
        }
    }

    public function respond(Request $request, string $kind, string $id, string $slot, string $version): Response
    {
        $disk = $path = null;
        if ($kind === 'profile' && in_array($slot, ['avatar', 'cover'], true)) {
            $profile = Profile::query()->findOrFail($id);
            $path = $profile->{$slot.'_path'};
            $disk = $profile->{$slot.'_disk'};
        } elseif ($kind === 'community' && in_array($slot, ['avatar', 'cover'], true)) {
            $community = Community::query()->findOrFail($id);
            abort_unless($community->actor?->isLocal(), 404);
            $path = $community->{$slot.'_path'};
            $disk = $community->{$slot.'_disk'};
        } elseif ($kind === 'actor' && in_array($slot, ['icon', 'image'], true)) {
            // Preserve previously published actor URLs after moving references to communities.
            $actor = Actor::query()->findOrFail($id);
            abort_unless($actor->isLocal(), 404);
            $community = $actor->community;
            $slot = $slot === 'icon' ? 'avatar' : 'cover';
            $path = $community?->{$slot.'_path'};
            $disk = $community?->{$slot.'_disk'};
        } elseif ($kind === 'instance' && $id === 'site') {
            $settings = app(InstanceSettings::class);
            if ($slot === 'background' && $settings->homeBackgroundDirectory() !== null) {
                $path = $settings->homeBackgroundDirectory().'/'.HomeBackgroundUploader::FILENAME;
                $disk = $settings->homeBackgroundDisk();
            } elseif (in_array($slot, InstanceIconUploader::filenames(), true) && $settings->iconDirectory() !== null) {
                $path = $settings->iconDirectory().'/'.$slot;
                $disk = $settings->iconDisk();
            }
        }
        abort_unless($disk === MediaStorage::OBJECT_DISK && filled($path) && hash_equals(sha1($path), $version), 404);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
            default => null,
        };
        abort_if($mime === null, 404);

        return app(MediaFileResponder::class)->imageResponse($request, $disk, $path, $mime, $kind.':'.$id);
    }
}
