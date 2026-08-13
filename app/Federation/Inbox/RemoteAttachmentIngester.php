<?php

namespace App\Federation\Inbox;

use App\Domain\Comments\Comment;
use App\Domain\Comments\CommentAttachment;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostAttachment;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\Media;
use Illuminate\Support\Facades\DB;

/**
 * Collega a un post o commento remoto le immagini dichiarate in "attachment"
 * (e anteprime icon/image) senza scaricarle: salva solo l'URL https in
 * {@see Media::$remote_url}, riusabile dalla stessa galleria dei contenuti locali.
 */
final class RemoteAttachmentIngester
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function sync(Post|Comment $content, Actor $author, array $document): void
    {
        if ($author->isLocal()) {
            return;
        }

        $descriptors = array_slice(
            RemotePostObject::mediaAttachments($document),
            0,
            max(0, (int) config('openbook.media.max_attachments_per_post')),
        );

        DB::transaction(function () use ($content, $author, $descriptors): void {
            $existingRemote = $content->media()
                ->whereNotNull('media.remote_url')
                ->get();

            if ($existingRemote->isNotEmpty()) {
                $content->media()->detach($existingRemote->pluck('id'));
                Media::query()
                    ->whereIn('id', $existingRemote->pluck('id'))
                    ->whereNotNull('remote_url')
                    ->whereDoesntHave('posts')
                    ->whereDoesntHave('comments')
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
                        'mime_type' => $descriptor['mime']
                            ?? self::guessRemoteMime($descriptor['url']),
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

                if ($content instanceof Post) {
                    PostAttachment::query()->updateOrCreate(
                        [
                            'post_id' => $content->id,
                            'media_id' => $media->id,
                        ],
                        [
                            'position' => $position,
                        ],
                    );
                } else {
                    CommentAttachment::query()->updateOrCreate(
                        [
                            'comment_id' => $content->id,
                            'media_id' => $media->id,
                        ],
                        [
                            'position' => $position,
                        ],
                    );
                }
            }
        });
    }

    private static function guessRemoteMime(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return match ($extension) {
            'webm' => 'video/webm',
            'mp4', 'm4v', 'mov' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            default => 'image/jpeg',
        };
    }
}
