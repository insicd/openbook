<?php

namespace App\Application\Services;

use App\Domain\Events\EventComment;
use App\Infrastructure\Media\MediaStorage;
use Illuminate\Support\Facades\DB;

final class EventCommentSoftDeleter
{
    public function delete(EventComment $comment): bool
    {
        if (! $comment->isPublished()) {
            return false;
        }

        $media = $comment->media()->with('thumbnail')->get();

        DB::transaction(function () use ($comment): void {
            $comment->attachments()->delete();
            $comment->mentions()->delete();
            $comment->likes()->delete();
            $comment->forceFill([
                'body' => '',
                'custom_emojis' => null,
                'status' => EventComment::STATUS_DELETED,
                'likes_count' => 0,
                'edited_at' => now(),
            ])->save();
        });

        foreach ($media as $item) {
            if ($item->posts()->exists() || $item->comments()->exists() || $item->events()->exists() || $item->eventComments()->exists()) {
                continue;
            }

            $paths = [[$item->disk, $item->path]];

            if ($item->thumbnail !== null) {
                $paths[] = [$item->thumbnail->disk, $item->thumbnail->path];
            }

            if ($item->isRemote()) {
                $item->delete();
            } else {
                DB::afterCommit(function () use ($item, $paths): void {
                    foreach ($paths as [$disk, $path]) {
                        if (! app(MediaStorage::class)->cleanup($disk, [$path])) {
                            return;
                        }
                    }
                    $item->delete();
                });
            }
        }

        return true;
    }
}
