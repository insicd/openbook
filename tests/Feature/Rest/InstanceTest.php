<?php

namespace Tests\Feature\Rest;

use App\Application\Services\InstanceSettings;
use App\Domain\Accounts\User;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class InstanceTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    private const ICON_DIRECTORY = 'instance-icons/00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('instanceviewer');
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
        config(['app.url' => 'https://social.example/', 'openbook.domain' => 'social.example']);
    }

    public function test_configured_metadata_uses_instance_settings_and_does_not_reflect_request_host(): void
    {
        config(['app.name' => 'Bootstrap name']);
        SystemSetting::put(InstanceSettings::KEY_SITE_NAME, 'Example Social');
        SystemSetting::put(InstanceSettings::KEY_SITE_DESCRIPTION, '  A friendly instance.  ');
        SystemSetting::put(InstanceSettings::KEY_INSTANCE_ICON_DIR, self::ICON_DIRECTORY);
        config(['filesystems.disks.public.url' => '/storage']);
        Storage::forgetDisk('public');
        $this->withHeader('Host', 'untrusted.example')->getJson('/rest/v1/instance')->assertOk()->assertExactJson([
            'instanceName' => 'Example Social', 'instanceDomain' => 'social.example',
            'instanceUrl' => 'https://social.example', 'instanceDescription' => 'A friendly instance.',
            'instanceIcon' => 'https://social.example/storage/'.self::ICON_DIRECTORY.'/icon-512.png',
        ])->assertHeader('Content-Type', 'application/json');
        Http::assertNothingSent();
    }

    public function test_defaults_and_missing_icon_are_explicit_without_statistics_or_private_settings(): void
    {
        config(['app.name' => 'Fallback Social']);
        SystemSetting::put(InstanceSettings::KEY_CONTACT_EMAIL, 'contact@example.test');
        SystemSetting::put('cron_token', 'not-a-real-secret');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->getJson('/rest/v1/instance')->assertOk()->assertExactJson([
            'instanceName' => 'Fallback Social', 'instanceDomain' => 'social.example',
            'instanceUrl' => 'https://social.example', 'instanceDescription' => '',
        ]);
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(count|sum|avg)\s*\(/i', $sql);
        }
        Http::assertNothingSent();
    }

    public function test_an_absolute_icon_storage_url_is_preserved(): void
    {
        SystemSetting::put(InstanceSettings::KEY_INSTANCE_ICON_DIR, self::ICON_DIRECTORY);
        config(['filesystems.disks.public.url' => 'https://cdn.example/assets']);
        Storage::forgetDisk('public');
        $this->getJson('/rest/v1/instance')->assertOk()
            ->assertJsonPath('instanceIcon', 'https://cdn.example/assets/'.self::ICON_DIRECTORY.'/icon-512.png');
    }

    public function test_private_cache_revalidation_tracks_changes_to_every_exposed_setting(): void
    {
        $first = $this->getJson('/rest/v1/instance')->assertOk()->assertHeader('Vary', 'Authorization');
        $this->assertTrue($first->headers->hasCacheControlDirective('private'));
        $this->assertTrue($first->headers->hasCacheControlDirective('no-cache'));
        $first->assertHeaderMissing('Last-Modified');
        $etag = $first->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson('/rest/v1/instance')->assertStatus(304)->assertContent('');
        foreach ([InstanceSettings::KEY_SITE_NAME => 'New name', InstanceSettings::KEY_SITE_DESCRIPTION => 'New description',
            InstanceSettings::KEY_INSTANCE_ICON_DIR => self::ICON_DIRECTORY] as $key => $value) {
            SystemSetting::put($key, $value);
            $changed = $this->withHeader('If-None-Match', $etag)->getJson('/rest/v1/instance')->assertOk();
            $this->assertNotSame($etag, $changed->headers->get('ETag'));
            $etag = $changed->headers->get('ETag');
        }
        SystemSetting::put(InstanceSettings::KEY_INSTANCE_ICON_DIR, null);
        $withoutIcon = $this->withHeader('If-None-Match', $etag)->getJson('/rest/v1/instance')->assertOk()->assertJsonMissingPath('instanceIcon');
        $etag = $withoutIcon->headers->get('ETag');
        foreach (['app.url' => 'https://new.example/openbook/', 'openbook.domain' => 'new.example'] as $key => $value) {
            config([$key => $value]);
            $changed = $this->withHeader('If-None-Match', $etag)->getJson('/rest/v1/instance')->assertOk();
            $this->assertNotSame($etag, $changed->headers->get('ETag'));
            $etag = $changed->headers->get('ETag');
        }
    }

    public function test_authentication_and_scope_checks_precede_304(): void
    {
        $etag = $this->getJson('/rest/v1/instance')->assertOk()->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->withoutHeader('Authorization')
            ->getJson('/rest/v1/instance')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        $this->withToken('test-token');
        Passport::actingAs($this->viewer, [], 'rest');
        $this->getJson('/rest/v1/instance')->assertForbidden();
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->viewer->actor->update(['status' => 'suspended']);
        $this->getJson('/rest/v1/instance')->assertForbidden()->assertJsonPath('status', 403);
    }

    public function test_the_endpoint_is_not_available_outside_local_and_testing(): void
    {
        $this->app->instance('env', 'production');
        $this->getJson('/rest/v1/instance')->assertNotFound()->assertJsonPath('status', 404);
    }
}
