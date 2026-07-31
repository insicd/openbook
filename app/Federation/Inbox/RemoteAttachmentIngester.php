<?php

namespace App\Federation\Inbox;

use App\Domain\Posts\Post;
use App\Domain\Posts\PostAttachment;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Media;
use Illuminate\Support\Facades\DB;

/**
 * Collega a un post remoto le immagini dichiarate in "attachment" (e
 * anteprime icon/image) senza scaricarle: salva solo l'URL https in
 * {@see Media::$remote_url}, riusabile dalla stessa galleria dei post locali.
 */
final class RemoteAttachmentIngester
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function sync(Post $post, Actor $author, array $document): void
    {
        if ($author->isLocal()) {
            return;
        }

        $descriptors = array_slice(
            RemotePostObject::imageAttachments($document),
            0,
            max(0, (int) config('openbook.media.max_attachments_per_post')),
        );

        DB::transaction(function () use ($post, $author, $descriptors): void {
            $existingRemote = $post->media()
                ->whereNotNull('media.remote_url')
                ->get();

            if ($existingRemote->isNotEmpty()) {
                $post->media()->detach($existingRemote->pluck('id'));
                Media::query()
                    ->whereIn('id', $existingRemote->pluck('id'))
                    ->whereNotNull('remote_url')
                    ->whereDoesntHave('posts')
                    ->delete();
            }

            foreach ($descriptors as $position => $descriptor) {
                $media = Media::query()->firstOrCreate(
                    [
                        'actor_id' => $author->id,
                        'remote_url' => $descriptor['url'],
                    ],
                    [
                        'disk' => 'remote',
                        'path' => 'remote/'.sha1($descriptor['url']),
                        'mime_type' => $descriptor['mime'] ?? 'image/jpeg',
                        'byte_size' => 0,
                        'alt_text' => $descriptor['alt'],
                    ],
                );

                if ($media->alt_text !== $descriptor['alt'] || $media->mime_type !== ($descriptor['mime'] ?? $media->mime_type)) {
                    $media->forceFill([
                        'alt_text' => $descriptor['alt'] ?? $media->alt_text,
                        'mime_type' => $descriptor['mime'] ?? $media->mime_type,
                    ])->save();
                }

                PostAttachment::query()->updateOrCreate(
                    [
                        'post_id' => $post->id,
                        'media_id' => $media->id,
                    ],
                    [
                        'position' => $position,
                    ],
                );
            }
        });
    }
}
