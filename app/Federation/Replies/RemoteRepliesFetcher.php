<?php

namespace App\Federation\Replies;

use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Inbox\RemoteContentSanitizer;
use App\Federation\Inbox\RemoteNoteUpserter;
use App\Federation\Outbox\RemoteOutboxFetcher;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Illuminate\Support\Carbon;

/**
 * Recupera le risposte pubbliche dalla collection "replies" di una Note
 * remota gia' in cache locale. Necessario perche' i commenti di terzi su
 * un post seguito arrivano in inbox solo se indirizzati a questa istanza:
 * aprendo il post si interroga esplicitamente il server di origine, sullo
 * stesso modello di {@see RemoteOutboxFetcher} per i profili remoti.
 */
final class RemoteRepliesFetcher
{
    private const MAX_ITEMS = 40;

    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly RemoteNoteUpserter $noteUpserter,
        private readonly RemoteActorResolver $remoteActorResolver,
    ) {}

    public function fetchReplies(Post $post): void
    {
        if (! $post->isRemote() || blank($post->uri) || ! $post->isPublished()) {
            return;
        }

        $ttlHours = (int) config('openbook.federation.replies_cache_ttl_hours', 6);

        if ($post->replies_fetched_at !== null && $post->replies_fetched_at->gt(Carbon::now()->subHours($ttlHours))) {
            return;
        }

        // Aggiornato subito, prima della richiesta: un server remoto
        // irraggiungibile non deve rallentare ogni successivo caricamento.
        $post->forceFill(['replies_fetched_at' => now()])->saveQuietly();

        $note = $this->fetchDocument($post->uri);

        if ($note === null || ($note['type'] ?? null) !== 'Note') {
            return;
        }

        $items = $this->resolveRepliesItems($note['replies'] ?? null);

        foreach ($items as $item) {
            $this->ingestItem($item, $post);
        }
    }

    /**
     * @return list<array<string, mixed>|string>
     */
    private function resolveRepliesItems(mixed $replies): array
    {
        if (is_string($replies) && $replies !== '') {
            return $this->collectionItems($this->fetchDocument($replies));
        }

        if (! is_array($replies)) {
            return [];
        }

        $direct = $this->orderedOrPlainItems($replies);

        if ($direct !== []) {
            return $direct;
        }

        $first = $replies['first'] ?? null;

        if (is_array($first)) {
            return $this->orderedOrPlainItems($first);
        }

        if (is_string($first) && $first !== '') {
            return $this->collectionItems($this->fetchDocument($first));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @return list<array<string, mixed>|string>
     */
    private function collectionItems(?array $document): array
    {
        if ($document === null) {
            return [];
        }

        $direct = $this->orderedOrPlainItems($document);

        if ($direct !== []) {
            return $direct;
        }

        $first = $document['first'] ?? null;

        if (is_array($first)) {
            return $this->orderedOrPlainItems($first);
        }

        if (is_string($first) && $first !== '') {
            return $this->orderedOrPlainItems($this->fetchDocument($first) ?? []);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>|string>
     */
    private function orderedOrPlainItems(array $document): array
    {
        $items = $document['orderedItems'] ?? $document['items'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $filtered = array_values(array_filter(
            $items,
            fn ($item) => is_array($item) || (is_string($item) && $item !== ''),
        ));

        return array_slice($filtered, 0, self::MAX_ITEMS);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDocument(string $url): ?array
    {
        try {
            $response = $this->httpClient->get($url, ['Accept' => 'application/activity+json']);
        } catch (SsrfViolationException) {
            return null;
        }

        return $response->successful() ? $response->json() : null;
    }

    /**
     * @param  array<string, mixed>|string  $item
     */
    private function ingestItem(array|string $item, Post $post): void
    {
        $note = is_string($item) ? $this->fetchDocument($item) : $item;

        if ($note === null) {
            return;
        }

        if (($note['type'] ?? null) === 'Create' && is_array($note['object'] ?? null)) {
            $note = $note['object'];
        }

        if (($note['type'] ?? null) !== 'Note') {
            return;
        }

        $noteUri = $note['id'] ?? null;
        $inReplyTo = $note['inReplyTo'] ?? null;
        $attributedTo = $note['attributedTo'] ?? null;

        if (! is_string($noteUri) || $noteUri === '') {
            return;
        }

        if (! is_string($inReplyTo) || $inReplyTo === '') {
            return;
        }

        if (! is_string($attributedTo) || $attributedTo === '') {
            return;
        }

        [$parentPost, $parentComment] = $this->resolveParents($inReplyTo, $post);

        if ($parentPost === null && $parentComment === null) {
            return;
        }

        $visibility = $this->noteUpserter->visibilityFromAudience($note);

        if (! in_array($visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_UNLISTED], true)) {
            return;
        }

        $actor = $this->remoteActorResolver->resolveByUri($attributedTo);

        if ($actor === null || $actor->isLocal()) {
            // Autore locale: il commento dovrebbe gia' esistere sul DB;
            // non si crea una seconda copia remota.
            return;
        }

        $body = RemoteContentSanitizer::toPlainText((string) ($note['content'] ?? ''));

        // notifyMentions: false — backfill di thread gia' esistente, non un
        // evento "appena successo": niente notifiche per menzioni/risposte vecchie.
        $this->noteUpserter->upsertComment(
            $note,
            $noteUri,
            $actor,
            $body,
            $parentPost,
            $parentComment,
            notifyMentions: false,
        );
    }

    /**
     * @return array{0: ?Post, 1: ?Comment}
     */
    private function resolveParents(string $inReplyTo, Post $post): array
    {
        if ($inReplyTo === $post->uri) {
            return [$post, null];
        }

        $parentComment = Comment::query()
            ->where('post_id', $post->id)
            ->where('uri', $inReplyTo)
            ->first();

        if ($parentComment !== null) {
            return [null, $parentComment];
        }

        return [null, null];
    }
}
