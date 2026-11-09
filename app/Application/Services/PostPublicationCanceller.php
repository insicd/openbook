<?php

namespace App\Application\Services;

use App\Domain\Posts\PendingPostPublication;
use App\Federation\Actors\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PostPublicationCanceller
{
    public function cancel(Actor $author, string $publicationId): string
    {
        return DB::transaction(function () use ($author, $publicationId): string {
            $publication = PendingPostPublication::query()->where('actor_id', $author->id)
                ->whereKey($publicationId)->lockForUpdate()->first();
            if ($publication === null || $publication->status === PendingPostPublication::STATUS_PUBLISHED) {
                return 'missing';
            }
            if ($publication->status === PendingPostPublication::STATUS_PROCESSING) {
                return 'processing';
            }

            $disk = Storage::disk('local');
            $directory = 'post-publication/'.$publication->id;
            if ($disk->directoryExists($directory) && ! $disk->deleteDirectory($directory)) {
                throw new \RuntimeException('Unable to remove staged publication files.');
            }
            $publication->delete();

            return 'deleted';
        });
    }
}
