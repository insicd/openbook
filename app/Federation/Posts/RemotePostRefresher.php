<?php

namespace App\Federation\Posts;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Fetch\FederationFetchSigner;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Inbox\RemotePostObject;
use App\Federation\Replies\RemoteRepliesFetcher;
use App\Federation\Support\ActivityPubTimestamp;
use Illuminate\Support\Facades\Log;

/**
 * Aggiorna on-demand un post remoto gia' in cache: ri-scarica la Note
 * (corpo, CW, allegati) e forza il recupero delle replies pubbliche,
 * ignorando il TTL usato in apertura pagina.
 */
final class RemotePostRefresher
{
    public function __construct(
        private readonly RemoteNoteDocumentFetcher $noteFetcher,
        private readonly RemoteNoteUpserter $noteUpserter,
        private readonly RemoteActorResolver $remoteActorResolver,
        private readonly RemoteRepliesFetcher $repliesFetcher,
        private readonly FederationFetchSigner $fetchSigner,
    ) {}

    public function refresh(Post $post): bool
    {
        if (! $post->isRemote() || blank($post->uri) || ! $post->isPublished()) {
            return false;
        }

        $signingActor = $this->fetchSigner->resolve();
        $note = $this->noteFetcher->fetch($post->uri, $signingActor);

        if ($note !== null) {
            $this->refreshNoteDocument($post, $note);
        } else {
            Log::channel('single')->info('federation.post_refresh.note_unavailable', [
                'post_uri' => $post->uri,
            ]);
        }

        $this->repliesFetcher->fetchReplies($post->fresh() ?? $post, force: true);

        return true;
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function refreshNoteDocument(Post $post, array $note): void
    {
        $noteUri = is_string($note['id'] ?? null) ? $note['id'] : null;

        if ($noteUri === null || $noteUri === '' || $noteUri !== $post->uri) {
            return;
        }

        if (! RemotePostObject::isPostable($note['type'] ?? null)) {
            return;
        }

        $author = $this->resolveAuthor($note, $post);

        if ($author === null) {
            return;
        }

        $publishedAt = ActivityPubTimestamp::parse(
            isset($note['published']) && is_string($note['published']) ? $note['published'] : null,
            $post->published_at,
        );

        $this->noteUpserter->upsertPost(
            $note,
            $noteUri,
            $author,
            RemotePostObject::body($note),
            $publishedAt,
            notifyMentions: false,
        );
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function resolveAuthor(array $note, Post $post): ?Actor
    {
        $attributedTo = $note['attributedTo'] ?? null;

        if (is_array($attributedTo)) {
            $attributedTo = $attributedTo['id'] ?? null;
        }

        if (! is_string($attributedTo) || $attributedTo === '') {
            return $post->actor;
        }

        $post->loadMissing('actor');

        if ($post->actor !== null && $post->actor->uri === $attributedTo) {
            return $post->actor;
        }

        return $this->remoteActorResolver->resolveByUri($attributedTo) ?? $post->actor;
    }
}
