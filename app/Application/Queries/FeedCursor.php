<?php

namespace App\Application\Queries;

use App\Domain\Posts\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Cursore per lo scorrimento infinito dei feed: ancorato all'ultimo post
 * mostrato (data di ordinamento + id) invece che al numero di pagina, cosi'
 * i post pubblicati mentre si scrolla non spostano l'OFFSET e non creano
 * duplicati tra una richiesta e la successiva.
 */
final class FeedCursor
{
    public function __construct(
        public readonly string $sortAt,
        public readonly string $postId,
    ) {}

    public static function fromRequest(Request $request): ?self
    {
        $encoded = $request->query('cursor');

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        return self::decode($encoded);
    }

    public static function fromPost(Post $post, bool $useShareSort): self
    {
        $sortAt = $useShareSort
            ? ($post->shared_at ?? $post->published_at)
            : $post->published_at;

        if ($sortAt instanceof Carbon) {
            $sortAt = $sortAt->toDateTimeString();
        }

        return new self((string) $sortAt, $post->id);
    }

    public function encode(): string
    {
        $payload = json_encode([
            't' => $this->sortAt,
            'i' => $this->postId,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): ?self
    {
        try {
            $normalized = strtr($encoded, '-_', '+/');
            $padding = strlen($normalized) % 4;

            if ($padding > 0) {
                $normalized .= str_repeat('=', 4 - $padding);
            }

            $json = base64_decode($normalized, true);

            if ($json === false) {
                return null;
            }

            $data = json_decode($json, true, 2, JSON_THROW_ON_ERROR);

            if (! is_array($data) || ! is_string($data['t'] ?? null) || ! is_string($data['i'] ?? null)) {
                return null;
            }

            if ($data['t'] === '' || $data['i'] === '') {
                return null;
            }

            return new self($data['t'], $data['i']);
        } catch (\Throwable) {
            return null;
        }
    }
}
