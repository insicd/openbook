<?php

namespace App\Http\Requests\Posts;

use Closure;
use Illuminate\Http\UploadedFile;

final class AttachmentRules
{
    public static function file(): array
    {
        $allowed = (array) config('openbook.media.allowed_mime_types');
        if (config('openbook.video.enabled', false)) {
            $allowed = array_merge($allowed, (array) config('openbook.video.allowed_mime_types'));
        }

        return ['file', 'mimetypes:'.implode(',', $allowed), function (string $attribute, mixed $file, Closure $fail): void {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return;
            }
            $isVideo = in_array(strtolower((string) $file->getMimeType()), (array) config('openbook.video.allowed_mime_types'), true);
            $maxKb = $isVideo
                ? (int) config('openbook.video.max_upload_mb') * 1024
                : (int) config('openbook.media.max_size_kb');
            if ($file->getSize() === false || $file->getSize() > $maxKb * 1024) {
                $fail(__('validation.max.file', ['attribute' => $attribute, 'max' => $maxKb]));
            }
        }];
    }
}
