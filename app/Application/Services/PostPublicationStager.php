<?php

namespace App\Application\Services;

use App\Domain\Posts\PendingPostAttachment;
use App\Domain\Posts\PendingPostPublication;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\VideoProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Salva un post con video nel deposito privato in attesa di pubblicazione. */
final class PostPublicationStager
{
    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function __construct(private readonly VideoProbe $videoProbe) {}

    /**
     * @param  array{title?: ?string, content_warning?: ?string, body: string, visibility?: string, language?: ?string, quoted_post_id?: ?string, community_id?: ?string, addressed_group_actor_id?: ?string, images: array<int, UploadedFile>, alt_texts?: array<int, ?string>}  $data
     */
    public function stage(Actor $author, array $data): PendingPostPublication
    {
        if (! config('openbook.video.enabled', false)) {
            throw new InvalidArgumentException('Il supporto video non e\' abilitato.');
        }

        $files = array_values($data['images'] ?? []);
        $maxAttachments = (int) config('openbook.media.max_attachments_per_post');

        if ($files === [] || count($files) > $maxAttachments) {
            throw new InvalidArgumentException("Puoi allegare da 1 a {$maxAttachments} file per post in elaborazione.");
        }

        $publication = new PendingPostPublication;
        $publication->id = (string) Str::uuid();
        $directory = 'post-publication/'.$publication->id;

        try {
            DB::transaction(function () use ($publication, $author, $data, $files, $directory): void {
                $publication->fill([
                    'actor_id' => $author->id,
                    'payload' => $this->snapshot($data),
                    'status' => PendingPostPublication::STATUS_PENDING,
                ])->save();

                $containsVideo = false;
                $altTexts = array_values($data['alt_texts'] ?? []);

                foreach ($files as $position => $file) {
                    if (! $file instanceof UploadedFile || ! $file->isValid()) {
                        throw new InvalidArgumentException('Uno degli allegati non e\' un upload valido.');
                    }

                    $mimeType = strtolower((string) $file->getMimeType());
                    $mediaType = $this->mediaType($mimeType);
                    $containsVideo = $containsVideo || $mediaType === 'video';
                    $byteSize = $file->getSize();

                    if ($byteSize === false) {
                        throw new InvalidArgumentException('Impossibile determinare la dimensione di un allegato.');
                    }

                    $this->validateSize($mediaType, $byteSize);
                    $extension = $this->extension($mimeType);
                    $path = $directory.'/'.Str::uuid().'.'.$extension;
                    $stored = Storage::disk('local')->putFileAs($directory, $file, basename($path));

                    if ($stored === false) {
                        throw new \RuntimeException('Impossibile salvare un allegato nel deposito temporaneo.');
                    }

                    $metadata = $mediaType === 'video'
                        ? $this->videoMetadata(Storage::disk('local')->path($path), $mimeType, $byteSize)
                        : $this->nonVideoMetadata($file, $mediaType);

                    PendingPostAttachment::query()->create(array_merge($metadata, [
                        'publication_id' => $publication->id,
                        'position' => $position,
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'mime_type' => $mimeType,
                        'byte_size' => $byteSize,
                        'media_type' => $mediaType,
                        'alt_text' => $altTexts[$position] ?? null,
                    ]));
                }

                if (! $containsVideo) {
                    throw new InvalidArgumentException('Lo staging e\' riservato ai post che contengono almeno un video.');
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->deleteDirectory($directory);

            throw $exception;
        }

        return $publication->load('attachments');
    }

    /** @return array<string, mixed> */
    private function snapshot(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'title',
            'content_warning',
            'body',
            'visibility',
            'language',
            'quoted_post_id',
            'community_id',
            'addressed_group_actor_id',
        ]));
    }

    private function mediaType(string $mimeType): string
    {
        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'audio/') && in_array($mimeType, (array) config('openbook.media.allowed_mime_types'), true)) {
            return 'audio';
        }

        if (in_array($mimeType, (array) config('openbook.video.allowed_mime_types'), true)) {
            return 'video';
        }

        throw new InvalidArgumentException("Tipo di file non consentito: {$mimeType}.");
    }

    private function validateSize(string $mediaType, int $byteSize): void
    {
        $maxBytes = $mediaType === 'video'
            ? (int) config('openbook.video.max_upload_mb') * 1024 * 1024
            : (int) config('openbook.media.max_size_kb') * 1024;

        if ($byteSize <= 0 || $byteSize > $maxBytes) {
            throw new InvalidArgumentException('Il file supera la dimensione massima consentita.');
        }
    }

    private function extension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/flac' => 'flac',
            'audio/webm' => 'webm',
            'audio/aac' => 'aac',
            'video/mp4', 'application/mp4', 'video/x-m4v' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/ogg' => 'ogv',
            default => 'bin',
        };
    }

    /** @return array<string, mixed> */
    private function nonVideoMetadata(UploadedFile $file, string $mediaType): array
    {
        $width = null;
        $height = null;

        if ($mediaType === 'image') {
            $dimensions = @getimagesize($file->getRealPath());

            if ($dimensions === false) {
                throw new InvalidArgumentException('Il file non e\' un\'immagine valida.');
            }

            [$width, $height] = $dimensions;
        }

        return [
            'processing' => PendingPostAttachment::PROCESS_COPY,
            'width' => $width,
            'height' => $height,
        ];
    }

    /** @return array<string, mixed> */
    private function videoMetadata(string $path, string $mimeType, int $byteSize): array
    {
        $metadata = $this->videoProbe->inspect($path);

        if ($metadata['duration_ms'] > (int) config('openbook.video.max_duration_seconds') * 1000) {
            throw new InvalidArgumentException('Il video supera la durata massima consentita.');
        }

        $containers = array_map('trim', explode(',', $metadata['container']));
        $compatibleContainer = count(array_intersect($containers, ['mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2'])) > 0;
        $compatibleMime = in_array($mimeType, ['video/mp4', 'application/mp4', 'video/x-m4v'], true);
        $requiresTranscode = ! $compatibleContainer
            || ! $compatibleMime
            || $metadata['video_codec'] !== 'h264'
            || ($metadata['audio_codec'] !== null && $metadata['audio_codec'] !== 'aac')
            || $metadata['pixel_format'] !== 'yuv420p'
            || $metadata['width'] % 2 !== 0
            || $metadata['height'] % 2 !== 0
            || max($metadata['width'], $metadata['height']) > (int) config('openbook.video.max_dimension')
            || $metadata['frame_rate'] > (float) config('openbook.video.max_frame_rate')
            || $byteSize > (int) config('openbook.video.passthrough_max_mb') * 1024 * 1024;

        return array_merge($metadata, [
            'processing' => $requiresTranscode
                ? PendingPostAttachment::PROCESS_TRANSCODE
                : PendingPostAttachment::PROCESS_COPY,
        ]);
    }
}
