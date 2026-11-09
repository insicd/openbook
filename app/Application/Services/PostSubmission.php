<?php

namespace App\Application\Services;

use App\Domain\Posts\PendingPostPublication;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;

final class PostSubmission
{
    public function __construct(
        private readonly PostComposer $composer,
        private readonly PostPublicationStager $stager,
    ) {}

    public function submit(Actor $author, array $data): Post|PendingPostPublication
    {
        $videoMimes = (array) config('openbook.video.allowed_mime_types');
        foreach ($data['images'] ?? [] as $file) {
            if (in_array(strtolower((string) $file->getMimeType()), $videoMimes, true)) {
                return $this->stager->stage($author, $data);
            }
        }

        return $this->composer->compose($author, $data);
    }
}
