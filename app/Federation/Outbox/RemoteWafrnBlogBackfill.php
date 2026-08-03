<?php

namespace App\Federation\Outbox;

use App\Federation\Actors\Actor;
use App\Federation\Inbox\RemoteNoteDocumentFetcher;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;

/**
 * Recupero post recenti quando l'outbox ActivityPub e' uno stub vuoto tipico
 * di Wafrn (`GET …/outbox` risponde 200 senza corpo JSON / senza
 * orderedItems). Usa l'API pubblica dell'istanza
 * `{origin}/api/v2/blog?id={username}` e, per ogni post pubblico, preferisce
 * il documento ActivityPub `{origin}/fediverse/post/{id}` (authorized fetch);
 * se non e' disponibile, sintetizza una Note dai campi dell'API.
 */
final class RemoteWafrnBlogBackfill
{
    private const PRIVACY_PUBLIC = 0;

    private const PRIVACY_UNLISTED = 3;

    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly RemoteNoteDocumentFetcher $noteDocumentFetcher,
    ) {}

    /**
     * @return list<array<string, mixed>> Note gia' materializzate
     */
    public function fetchNotes(Actor $actor, ?Actor $signingActor, int $limit = 20): array
    {
        $slug = $this->blogSlug($actor);
        $origin = $this->origin($actor);

        if ($slug === null || $origin === null) {
            return [];
        }

        $apiUrl = $origin.'/api/v2/blog?id='.rawurlencode($slug);

        try {
            $response = $this->httpClient->get(
                $apiUrl,
                ['Accept' => 'application/json'],
                $signingActor,
            );
        } catch (SsrfViolationException) {
            return [];
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $payload = $response->json();

        if (! is_array($payload) || ! is_array($payload['posts'] ?? null)) {
            return [];
        }

        /** @var list<array<string, mixed>> $posts */
        $posts = array_values(array_filter($payload['posts'], 'is_array'));
        usort($posts, static function (array $a, array $b): int {
            return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
        });

        $notes = [];

        foreach ($posts as $post) {
            if (count($notes) >= $limit) {
                break;
            }

            $note = $this->noteFromBlogPost($post, $actor, $origin, $signingActor);

            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>|null
     */
    private function noteFromBlogPost(array $post, Actor $actor, string $origin, ?Actor $signingActor): ?array
    {
        $id = $post['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        if (($post['isDeleted'] ?? false) === true
            || ($post['isReply'] ?? false) === true
            || ($post['isReblog'] ?? false) === true
        ) {
            return null;
        }

        $privacy = $post['privacy'] ?? null;

        if (! in_array($privacy, [self::PRIVACY_PUBLIC, self::PRIVACY_UNLISTED], true)) {
            return null;
        }

        // Post di terze parti solo in cache sull'istanza Wafrn: non sono
        // dell'Actor che stiamo visitando.
        if (is_string($post['remotePostId'] ?? null) && $post['remotePostId'] !== '') {
            return null;
        }

        $noteUri = $origin.'/fediverse/post/'.$id;
        $fetched = $this->noteDocumentFetcher->fetch($noteUri, $signingActor);

        if ($fetched !== null) {
            return $fetched;
        }

        $content = $post['content'] ?? '';
        $markdown = $post['markdownContent'] ?? '';
        $bodyHtml = is_string($content) && trim($content) !== ''
            ? $content
            : (is_string($markdown) ? $markdown : '');

        $warning = $post['content_warning'] ?? null;
        $title = $post['title'] ?? null;
        $published = $post['createdAt'] ?? null;
        $displayUrl = $post['displayUrl'] ?? null;

        $note = [
            'id' => $noteUri,
            'type' => 'Note',
            'attributedTo' => $actor->uri,
            'content' => $bodyHtml,
            'published' => is_string($published) && $published !== '' ? $published : now()->toAtomString(),
            'url' => is_string($displayUrl) && $displayUrl !== '' ? $displayUrl : $noteUri,
            'to' => $privacy === self::PRIVACY_UNLISTED
                ? []
                : ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => $privacy === self::PRIVACY_UNLISTED
                ? ['https://www.w3.org/ns/activitystreams#Public']
                : [],
        ];

        if (is_string($title) && trim($title) !== '') {
            $note['name'] = trim($title);
        }

        if (is_string($warning) && trim($warning) !== '') {
            $note['summary'] = trim($warning);
            $note['sensitive'] = true;
        }

        return $note;
    }

    private function blogSlug(Actor $actor): ?string
    {
        $path = parse_url($actor->uri, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        if (preg_match('#^/fediverse/blog/([^/]+)/?$#i', $path, $matches) !== 1) {
            return null;
        }

        $slug = rawurldecode($matches[1]);

        return $slug === '' ? null : $slug;
    }

    private function origin(Actor $actor): ?string
    {
        $parts = parse_url($actor->uri);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }
}
