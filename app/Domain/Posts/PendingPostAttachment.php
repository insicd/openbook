<?php

namespace App\Domain\Posts;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingPostAttachment extends Model
{
    use HasUuids;

    public const PROCESS_COPY = 'copy';

    public const PROCESS_TRANSCODE = 'transcode';

    protected $table = 'post_publication_queue_attachments';

    protected $fillable = [
        'publication_id',
        'position',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'byte_size',
        'media_type',
        'processing',
        'width',
        'height',
        'duration_ms',
        'frame_rate',
        'container',
        'video_codec',
        'audio_codec',
        'pixel_format',
        'alt_text',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'frame_rate' => 'float',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(PendingPostPublication::class, 'publication_id');
    }
}
