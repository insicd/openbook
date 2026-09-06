<?php

namespace App\Application\Services;

use App\Domain\Posts\PendingPostPublication;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Infrastructure\Media\VideoPreparer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Pubblica una sola volta un post dopo la preparazione dei media in staging. */
final class PendingPostFinalizer
{
    public function __construct(
        private readonly VideoPreparer $videoPreparer,
        private readonly PostComposer $postComposer,
    ) {}

    public function finalize(PendingPostPublication $publication, ?string $claimToken = null): Post
    {
        $publication = PendingPostPublication::query()
            ->with(['actor', 'attachments'])
            ->findOrFail($publication->id);

        if ($publication->post_id !== null) {
            return Post::query()->findOrFail($publication->post_id);
        }

        if ($claimToken !== null && $publication->claim_token !== $claimToken) {
            throw new \RuntimeException('Il claim del post in attesa non e\' piu\' valido.');
        }

        $this->assertAuthorCanPublish($publication->actor);
        $preparedMedia = $publication->attachments
            ->map(fn ($attachment) => $this->videoPreparer->prepare($attachment))
            ->all();

        $post = DB::transaction(function () use ($publication, $preparedMedia, $claimToken): Post {
            $locked = PendingPostPublication::query()
                ->with('actor')
                ->lockForUpdate()
                ->findOrFail($publication->id);

            if ($locked->post_id !== null) {
                return Post::query()->findOrFail($locked->post_id);
            }

            if ($claimToken !== null && $locked->claim_token !== $claimToken) {
                throw new \RuntimeException('Il claim del post in attesa non e\' piu\' valido.');
            }

            $this->assertAuthorCanPublish($locked->actor);
            $payload = $locked->payload;
            $payload['prepared_media'] = $preparedMedia;
            $post = $this->postComposer->compose($locked->actor, $payload);

            $locked->forceFill([
                'status' => PendingPostPublication::STATUS_PUBLISHED,
                'post_id' => $post->id,
                'claimed_at' => null,
                'claim_token' => null,
                'last_error' => null,
            ])->save();

            return $post;
        });

        Storage::disk('local')->deleteDirectory('post-publication/'.$publication->id);

        return $post;
    }

    private function assertAuthorCanPublish(?Actor $actor): void
    {
        if ($actor === null || ! $actor->isLocal() || $actor->status !== Actor::STATUS_ACTIVE) {
            throw new \RuntimeException('L\'autore del post in attesa non puo\' piu\' pubblicare.');
        }
    }
}
