<?php

namespace Tests\Feature;

use App\Application\Services\CommunityRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ProfileAvatarLightboxTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public static function profiles(): iterable
    {
        foreach (['local', 'remote', 'community'] as $kind) {
            foreach ([true, false] as $hasAvatar) {
                yield $kind.($hasAvatar ? ' with image' : ' placeholder') => [$kind, $hasAvatar, 'public'];
            }
        }
        yield 'local object storage' => ['local', true, 'objectStorage'];
    }

    #[DataProvider('profiles')]
    public function test_profile_images_use_the_shared_lightbox_and_placeholders_do_not(string $kind, bool $hasAvatar, string $disk): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $viewer = $this->createFullAccount('avatarviewer');
        $path = $hasAvatar ? 'avatars/profile.jpg' : null;

        if ($kind === 'local') {
            $viewer->profile->update(['avatar_path' => $path, 'avatar_disk' => $disk]);
            $src = $viewer->profile->avatarUrl();
            $url = route('profile.show', $viewer->username);
        } elseif ($kind === 'remote') {
            $actor = $this->createRemoteActor('avatarremote', overrides: ['icon_url' => $hasAvatar ? 'https://images.example/avatar.jpg' : null]);
            $src = $actor->avatarUrl();
            $url = route('actors.show', $actor);
        } else {
            $community = app(CommunityRegistrar::class)->register($viewer, ['slug' => 'avatarcommunity', 'name' => 'Avatar community']);
            $community->update(['avatar_path' => $path]);
            $src = $community->avatarUrl();
            $url = route('communities.show', $community);
        }

        $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $buttons = $xpath->query('//button[contains(@class, "ob-profile-avatar--zoom")]');
        if (! $hasAvatar) {
            $this->assertSame(0, $buttons->length);

            return;
        }
        $this->assertSame(1, $buttons->length);
        $button = $buttons->item(0);
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertTrue($button->hasAttribute('data-lightbox-trigger'));
        $this->assertSame($src, $button->getAttribute('data-full-src'));
        $this->assertSame(__('openbook.profile.enlarge_avatar'), $button->getAttribute('aria-label'));
        $this->assertSame(0, $xpath->query('ancestor::*[@aria-hidden="true" or @data-lightbox-group]', $button)->length);
        $this->assertSame($src, $xpath->query('.//img', $button)->item(0)->getAttribute('src'));
        $this->assertStringContainsString('id="ob-lightbox"', $html);
    }
}
