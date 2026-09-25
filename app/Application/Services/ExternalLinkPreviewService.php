<?php

namespace App\Application\Services;

use App\Domain\Posts\ExternalLinkPreview;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostBodyUrlFinder;
use App\Domain\Posts\VideoEmbedFinder;
use App\Infrastructure\Security\Http\SafeHttpClient;
use App\Infrastructure\Security\Http\SsrfViolationException;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class ExternalLinkPreviewService
{
    public function __construct(private readonly SafeHttpClient $httpClient) {}

    public function eligibleUrl(Post $post): ?string
    {
        if ($post->status !== Post::STATUS_PUBLISHED || $post->visibility !== Post::VISIBILITY_PUBLIC) {
            return null;
        }

        $urls = PostBodyUrlFinder::previewCandidates($post->body);

        if (count($urls) !== 1 || VideoEmbedFinder::first($post->body) !== null) {
            return null;
        }

        $hasImage = $post->relationLoaded('media')
            ? $post->media->contains(fn ($media) => str_starts_with($media->mime_type, 'image/'))
            : $post->media()->where('mime_type', 'like', 'image/%')->exists();

        return $hasImage ? null : $urls[0];
    }

    /** @return array{available: true, url: string, title: string, description: ?string, site_name: ?string, image_url: ?string}|null */
    public function preview(string $url): ?array
    {
        $fetchUrl = preg_replace('/#.*$/s', '', $url) ?? $url;
        $hash = hash('sha256', $fetchUrl);
        $cached = ExternalLinkPreview::query()->find($hash);

        if ($cached !== null && $this->fresh($cached)) {
            return $this->result($cached, $url);
        }

        try {
            return Cache::lock('external-link-preview:'.$hash, 10)->block(5, function () use ($hash, $fetchUrl, $url): ?array {
                $cached = ExternalLinkPreview::query()->find($hash);

                if ($cached !== null && $this->fresh($cached)) {
                    return $this->result($cached, $url);
                }

                $metadata = $this->fetch($fetchUrl);
                $preview = ExternalLinkPreview::query()->updateOrCreate(['url_hash' => $hash], [
                    'url' => $fetchUrl,
                    'available' => $metadata !== null,
                    'title' => $metadata['title'] ?? null,
                    'description' => $metadata['description'] ?? null,
                    'site_name' => $metadata['site_name'] ?? null,
                    'image_url' => $metadata['image_url'] ?? null,
                    'fetched_at' => now(),
                ]);

                return $this->result($preview, $url);
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    private function fresh(ExternalLinkPreview $preview): bool
    {
        $ttl = (int) config($preview->available
            ? 'openbook.link_preview.success_ttl_seconds'
            : 'openbook.link_preview.failure_ttl_seconds');

        return $preview->fetched_at->greaterThan(now()->subSeconds(max(1, $ttl)));
    }

    /** @return array{available: true, url: string, title: string, description: ?string, site_name: ?string, image_url: ?string}|null */
    private function result(ExternalLinkPreview $preview, string $originalUrl): ?array
    {
        if (! $preview->available) {
            return null;
        }

        return [
            'available' => true,
            'url' => $originalUrl,
            'title' => $preview->title,
            'description' => $this->cleanDescription($preview->description),
            'site_name' => $preview->site_name,
            'image_url' => $preview->image_url,
        ];
    }

    /** @return array{title: string, description: ?string, site_name: ?string, image_url: ?string}|null */
    private function fetch(string $url): ?array
    {
        try {
            $response = $this->httpClient->getWithin(
                $url,
                ['Accept' => 'text/html, application/xhtml+xml;q=0.9'],
                (int) config('openbook.link_preview.fetch_timeout_seconds', 5),
                (int) config('openbook.link_preview.max_response_bytes', 262144),
            );
        } catch (SsrfViolationException) {
            return null;
        }

        if (! $response->successful() || ! preg_match('#^(text/html|application/xhtml\+xml)(?:\s*;|$)#i', (string) $response->header('Content-Type'))) {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML('<?xml encoding="UTF-8">'.$response->body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $metadata = [];

        foreach ((new DOMXPath($document))->query('//head/meta[@property]') as $meta) {
            $property = strtolower(trim($meta->getAttribute('property')));

            if (in_array($property, ['og:title', 'og:description', 'og:site_name', 'og:image'], true)
                && ! isset($metadata[$property])) {
                $metadata[$property] = trim($meta->getAttribute('content'));
            }
        }

        $title = trim($metadata['og:title'] ?? '');

        if ($title === '') {
            return null;
        }

        $image = $metadata['og:image'] ?? null;

        if ($image !== null && (strlen($image) > 2048 || filter_var($image, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($image, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $image = null;
        }

        return [
            'title' => mb_substr($title, 0, 300),
            'description' => ($description = $this->cleanDescription($metadata['og:description'] ?? null)) === null ? null : mb_substr($description, 0, 1000),
            'site_name' => ($siteName = trim($metadata['og:site_name'] ?? '')) === '' ? null : mb_substr($siteName, 0, 200),
            'image_url' => $image,
        ];
    }

    private function cleanDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        // Keep the prose, not social tags embedded in an OG description.
        $description = preg_replace('/(?<!\S)#[\p{L}\p{N}_]+/u', '', $description) ?? $description;
        $description = trim(preg_replace('/[\s\p{Z}\x{200B}]+/u', ' ', $description) ?? $description);

        return $description === '' ? null : $description;
    }
}
