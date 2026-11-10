<?php

namespace App\Application\Services;

class InstanceUploadLimits
{
    /** @return array{maxAttachmentsPerPost: int, maxAttachmentBytes: int, maxVideoAttachmentBytes: ?int, maxRequestBytes: ?int} */
    public function forInstance(InstanceSettings $settings): array
    {
        $uploadsEnabled = filter_var($this->phpSetting('file_uploads'), FILTER_VALIDATE_BOOL);
        $phpFileBytes = $this->positiveBytes('upload_max_filesize');
        $attachmentBytes = max(0, $settings->mediaMaxSizeKb()) * 1024;
        $videoBytes = max(0, $settings->videoLimits()['max_upload_mb']) * 1024 * 1024;

        return [
            'maxAttachmentsPerPost' => $uploadsEnabled
                ? min(max(0, $settings->mediaMaxAttachments()), max(0, (int) $this->phpSetting('max_file_uploads')))
                : 0,
            'maxAttachmentBytes' => $uploadsEnabled ? min($attachmentBytes, $phpFileBytes ?? $attachmentBytes) : 0,
            'maxVideoAttachmentBytes' => $settings->videoEnabled()
                ? ($uploadsEnabled ? min($videoBytes, $phpFileBytes ?? $videoBytes) : 0)
                : null,
            // The whole multipart body includes JSON and boundaries, not just file bytes.
            'maxRequestBytes' => $this->positiveBytes('post_max_size'),
        ];
    }

    private function positiveBytes(string $setting): ?int
    {
        $bytes = ini_parse_quantity($this->phpSetting($setting));

        return $bytes > 0 ? $bytes : null;
    }

    protected function phpSetting(string $name): string
    {
        return (string) ini_get($name);
    }
}
