<?php

namespace App\Application\Services;

use App\Domain\Events\EventComment;
use App\Infrastructure\Media\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

            $isRemote = $item->isRemote();
            Media::query()->whereKey($item->id)->delete();

            if (! $isRemote) {
                foreach ($paths as [$disk, $path]) {
                    Storage::disk($disk)->delete($path);
                }
            }
        }

        return true;
    }
}
