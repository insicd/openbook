<?php

namespace App\Http\Controllers\Federation;

use App\Application\Services\InstanceSettings;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\MastodonAccountSerializer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class InstanceInfoController extends Controller
{
    public function show(InstanceSettings $settings): JsonResponse
    {
        $description = $settings->siteDescription();

        return response()->json([
            'uri' => (string) config('openbook.domain'),
            'title' => $settings->siteName(),
            'short_description' => $description,
            'description' => e($description),
            'email' => $settings->contactEmail(),
            'version' => 'OpenBook '.config('openbook.version'),
            'urls' => (object) [],
            'stats' => Cache::remember('instance:api_v1:stats', 900, static fn (): array => [
                'user_count' => User::query()->where('status', User::STATUS_ACTIVE)->count(),
                'status_count' => Post::query()
                    ->where('status', Post::STATUS_PUBLISHED)
                    ->whereHas('actor', static fn ($actors) => $actors->where('is_local', true))
                    ->count(),
                'domain_count' => Actor::query()->where('is_local', false)
                    ->where('domain', '<>', '')
                    ->distinct()->count('domain'),
            ]),
            'thumbnail' => null,
            'languages' => [(string) config('app.locale')],
            'registrations' => $settings->registrationOpen(),
            'approval_required' => false,
            'invites_enabled' => false,
            'configuration' => [
                'statuses' => [
                    'max_characters' => $settings->postMaxLength(),
                    'max_media_attachments' => $settings->mediaMaxAttachments(),
                ],
                'media_attachments' => $this->mediaConfiguration($settings),
            ],
            'contact_account' => $this->contactAccount($settings),
            'rules' => [],
        ]);
    }

    public function showV2(InstanceSettings $settings): JsonResponse
    {
        $icons = [];

        foreach ([192, 512] as $size) {
            if ($url = $settings->androidIconUrl($size)) {
                $icons[] = ['src' => $url, 'size' => "{$size}x{$size}"];
            }
        }

        return response()->json([
            'domain' => (string) config('openbook.domain'),
            'title' => $settings->siteName(),
            'version' => 'OpenBook '.config('openbook.version'),
            'source_url' => 'https://github.com/openbook-social/openbook',
            'description' => $settings->siteDescription(),
            'usage' => [
                'users' => [
                    'active_month' => Cache::remember('instance:api_v2:active_month', 21600, static fn (): int => User::query()
                        ->where('status', User::STATUS_ACTIVE)
                        ->where('last_login_at', '>=', now()->subDays(30))
                        ->count()),
                ],
            ],
            'thumbnail' => null,
            'icon' => $icons,
            'languages' => [(string) config('app.locale')],
            'configuration' => [
                'urls' => [
                    'privacy_policy' => route('instance.privacy'),
                ],
                'statuses' => [
                    'max_characters' => $settings->postMaxLength(),
                    'max_media_attachments' => $settings->mediaMaxAttachments(),
                ],
                'media_attachments' => $this->mediaConfiguration($settings),
                'translation' => ['enabled' => false],
            ],
            'registrations' => [
                'enabled' => $settings->registrationOpen(),
                'approval_required' => false,
                'message' => null,
            ],
            'contact' => [
                'email' => $settings->contactEmail(),
                'account' => $this->contactAccount($settings),
            ],
            'rules' => [],
        ]);
    }

    public function peers(): JsonResponse
    {
        return response()->json(Cache::remember('instance:api_v1:peers', 21600, static fn (): array => Actor::query()
            ->where('is_local', false)
            ->whereNotNull('domain')
            ->where('domain', '<>', '')
            ->distinct()
            ->orderBy('domain')
            ->pluck('domain')
            ->all()));
    }

    /** @return array<string, mixed>|null */
    private function contactAccount(InstanceSettings $settings): ?array
    {
        $contact = $settings->contactAccount();

        return $contact === null ? null : MastodonAccountSerializer::serialize($contact);
    }

    /** @return array<string, mixed> */
    private function mediaConfiguration(InstanceSettings $settings): array
    {
        $supportedMimeTypes = (array) config('openbook.media.allowed_mime_types');
        $configuration = [
            'supported_mime_types' => $supportedMimeTypes,
            'image_size_limit' => $settings->mediaMaxSizeKb() * 1024,
        ];

        if ($settings->videoEnabled()) {
            $configuration['supported_mime_types'] = array_values(array_unique(array_merge(
                $supportedMimeTypes,
                (array) config('openbook.video.allowed_mime_types'),
            )));
            $configuration['video_size_limit'] = $settings->videoLimits()['max_upload_mb'] * 1024 * 1024;
        }

        return $configuration;
    }
}
