<?php

namespace Tests\Feature\Federation;

use App\Application\Services\InstanceSettings;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class InstanceInfoTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_public_v1_instance_info_uses_configured_metadata_and_real_limits(): void
    {
        SystemSetting::put(InstanceSettings::KEY_SITE_NAME, 'Punk Kitchen');
        SystemSetting::put(InstanceSettings::KEY_SITE_DESCRIPTION, 'Musica & incontri');
        SystemSetting::put(InstanceSettings::KEY_CONTACT_EMAIL, 'abuse@example.test');
        SystemSetting::putBool(InstanceSettings::KEY_REGISTRATION_OPEN, false);
        SystemSetting::put(InstanceSettings::KEY_POST_MAX_LENGTH, '1234');
        SystemSetting::put(InstanceSettings::KEY_MEDIA_MAX_ATTACHMENTS, '6');
        SystemSetting::put(InstanceSettings::KEY_MEDIA_MAX_SIZE_KB, '5120');

        $response = $this->get('/api/v1/instance', ['Origin' => 'https://example.org']);

        $response
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertJsonPath('uri', config('openbook.domain'))
            ->assertJsonPath('title', 'Punk Kitchen')
            ->assertJsonPath('short_description', 'Musica & incontri')
            ->assertJsonPath('description', 'Musica &amp; incontri')
            ->assertJsonPath('email', 'abuse@example.test')
            ->assertJsonPath('version', 'OpenBook '.config('openbook.version'))
            ->assertJsonPath('languages', [(string) config('app.locale')])
            ->assertJsonPath('registrations', false)
            ->assertJsonPath('approval_required', false)
            ->assertJsonPath('invites_enabled', false)
            ->assertJsonPath('configuration.statuses.max_characters', 1234)
            ->assertJsonPath('configuration.statuses.max_media_attachments', 6)
            ->assertJsonPath('configuration.media_attachments.image_size_limit', 5120 * 1024)
            ->assertJsonPath('configuration.media_attachments.supported_mime_types', config('openbook.media.allowed_mime_types'))
            ->assertJsonPath('thumbnail', null)
            ->assertJsonPath('contact_account', null);

        $this->assertArrayNotHasKey('video_size_limit', $response->json('configuration.media_attachments'));
    }

    public function test_v1_instance_info_exposes_video_upload_limits_only_when_video_is_enabled(): void
    {
        SystemSetting::putBool(InstanceSettings::KEY_VIDEO_ENABLED, true);
        SystemSetting::put(InstanceSettings::KEY_VIDEO_MAX_UPLOAD_MB, '160');

        $this->get('/api/v1/instance')
            ->assertOk()
            ->assertJsonPath('configuration.media_attachments.video_size_limit', 160 * 1024 * 1024)
            ->assertJsonPath('configuration.media_attachments.supported_mime_types', array_values(array_unique(array_merge(
                config('openbook.media.allowed_mime_types'),
                config('openbook.video.allowed_mime_types'),
            ))));
    }

    public function test_v1_contact_account_is_public_only_while_selected_administrator_is_active(): void
    {
        $admin = $this->createFullAccount('instancecontact');
        $admin->forceFill(['is_admin' => true])->save();
        $admin->profile->update(['display_name' => 'Instance Contact', 'bio' => 'Hello **world**']);
        foreach ([Post::VISIBILITY_PUBLIC, Post::VISIBILITY_DIRECT] as $visibility) {
            Post::query()->create([
                'actor_id' => $admin->actor->id,
                'body' => 'Contact post',
                'visibility' => $visibility,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        }
        SystemSetting::put(InstanceSettings::KEY_CONTACT_ACCOUNT_ID, $admin->id);
        SystemSetting::putBool(InstanceSettings::KEY_SHOW_HOME_STAFF, false);

        $this->get('/api/v1/instance')
            ->assertOk()
            ->assertJsonPath('contact_account.id', $admin->id)
            ->assertJsonPath('contact_account.username', 'instancecontact')
            ->assertJsonPath('contact_account.display_name', 'Instance Contact')
            ->assertJsonPath('contact_account.url', route('profile.show', 'instancecontact'))
            ->assertJsonPath('contact_account.statuses_count', 1)
            ->assertJsonPath('contact_account.followers_count', 0)
            ->assertJsonPath('contact_account.following_count', 0);

        $admin->forceFill(['is_admin' => false])->save();
        $this->get('/api/v1/instance')->assertJsonPath('contact_account', null);
    }

    public function test_v1_statistics_count_local_content_and_use_the_configured_cache(): void
    {
        $local = $this->createFullAccount('localuser');
        $disabled = $this->createFullAccount('disableduser');
        $disabled->forceFill(['status' => User::STATUS_DISABLED])->save();

        $remote = Actor::query()->create([
            'type' => Actor::TYPE_PERSON,
            'is_local' => false,
            'preferred_username' => 'remoteuser',
            'domain' => 'remote.example',
            'uri' => 'https://remote.example/users/remoteuser',
            'status' => Actor::STATUS_ACTIVE,
        ]);

        foreach ([$local->actor, $remote] as $actor) {
            Post::query()->create([
                'actor_id' => $actor->id,
                'body' => 'A post',
                'visibility' => Post::VISIBILITY_PUBLIC,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        }

        $response = $this->get('/api/v1/instance');

        $response->assertOk()
            ->assertJsonPath('stats.user_count', 1)
            ->assertJsonPath('stats.status_count', 1)
            ->assertJsonPath('stats.domain_count', 1);

        $this->createFullAccount('newuser');
        $this->get('/api/v1/instance')->assertJsonPath('stats.user_count', 1);

        $this->travel(901)->seconds();
        $this->get('/api/v1/instance')->assertJsonPath('stats.user_count', 2);
    }

    public function test_public_v2_instance_info_uses_real_settings_and_contact_account(): void
    {
        $admin = $this->createFullAccount('v2contact');
        $admin->forceFill(['is_admin' => true])->save();
        SystemSetting::put(InstanceSettings::KEY_SITE_NAME, 'Punk Kitchen');
        SystemSetting::put(InstanceSettings::KEY_SITE_DESCRIPTION, 'Music & friends');
        SystemSetting::put(InstanceSettings::KEY_CONTACT_EMAIL, 'contact@example.test');
        SystemSetting::put(InstanceSettings::KEY_CONTACT_ACCOUNT_ID, $admin->id);
        SystemSetting::putBool(InstanceSettings::KEY_REGISTRATION_OPEN, false);
        SystemSetting::put(InstanceSettings::KEY_POST_MAX_LENGTH, '1234');
        SystemSetting::put(InstanceSettings::KEY_MEDIA_MAX_SIZE_KB, '5120');

        $this->get('/api/v2/instance', ['Origin' => 'https://example.org'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertJsonPath('domain', config('openbook.domain'))
            ->assertJsonPath('title', 'Punk Kitchen')
            ->assertJsonPath('description', 'Music & friends')
            ->assertJsonPath('source_url', 'https://github.com/openbook-social/openbook')
            ->assertJsonPath('thumbnail', null)
            ->assertJsonPath('icon', [])
            ->assertJsonPath('configuration.statuses.max_characters', 1234)
            ->assertJsonPath('configuration.media_attachments.image_size_limit', 5120 * 1024)
            ->assertJsonPath('configuration.urls.privacy_policy', route('instance.privacy'))
            ->assertJsonPath('registrations.enabled', false)
            ->assertJsonPath('registrations.approval_required', false)
            ->assertJsonPath('contact.email', 'contact@example.test')
            ->assertJsonPath('contact.account.username', 'v2contact')
            ->assertJsonPath('rules', []);
    }

    public function test_v2_active_month_counts_recent_logins_and_caches_the_result(): void
    {
        $recent = $this->createFullAccount('recentlogin');
        $recent->forceFill(['last_login_at' => now()->subDays(5)])->save();
        $old = $this->createFullAccount('oldlogin');
        $old->forceFill(['last_login_at' => now()->subDays(40)])->save();
        $disabled = $this->createFullAccount('disabledlogin');
        $disabled->forceFill(['status' => User::STATUS_DISABLED, 'last_login_at' => now()])->save();

        $this->get('/api/v2/instance')->assertJsonPath('usage.users.active_month', 1);

        $old->forceFill(['last_login_at' => now()])->save();
        $this->get('/api/v2/instance')->assertJsonPath('usage.users.active_month', 1);

        $this->travel(21601)->seconds();
        $this->get('/api/v2/instance')->assertJsonPath('usage.users.active_month', 2);
    }

    public function test_public_peers_lists_distinct_known_remote_domains_with_a_short_cache(): void
    {
        $this->createFullAccount('localpeer');

        foreach (['z.example', 'a.example', 'z.example'] as $index => $domain) {
            Actor::query()->create([
                'type' => Actor::TYPE_PERSON,
                'is_local' => false,
                'preferred_username' => 'remote'.$index,
                'domain' => $domain,
                'uri' => "https://{$domain}/users/remote{$index}",
                'status' => Actor::STATUS_ACTIVE,
            ]);
        }

        $this->get('/api/v1/instance/peers', ['Origin' => 'https://example.org'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertExactJson(['a.example', 'z.example']);

        Actor::query()->create([
            'type' => Actor::TYPE_PERSON,
            'is_local' => false,
            'preferred_username' => 'newremote',
            'domain' => 'new.example',
            'uri' => 'https://new.example/users/newremote',
            'status' => Actor::STATUS_ACTIVE,
        ]);

        $this->get('/api/v1/instance/peers')->assertExactJson(['a.example', 'z.example']);

        $this->travel(21601)->seconds();
        $this->get('/api/v1/instance/peers')->assertExactJson(['a.example', 'new.example', 'z.example']);
    }
}
