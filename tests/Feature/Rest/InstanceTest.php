<?php

namespace Tests\Feature\Rest;

use App\Application\Services\InstanceSettings;
use App\Application\Services\InstanceUploadLimits;
use App\Domain\Accounts\User;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->phpUploadSettings();
        config(['openbook.media.max_size_kb' => 8192, 'openbook.media.max_attachments_per_post' => 4,
            'openbook.video.enabled' => false, 'openbook.video.max_upload_mb' => 40]);
    }

    private function phpUploadSettings(array $overrides = []): void
    {
        $ini = array_merge(['file_uploads' => '1', 'max_file_uploads' => '20',
            'upload_max_filesize' => '64M', 'post_max_size' => '128M'], $overrides);
        $limits = $this->getMockBuilder(InstanceUploadLimits::class)->onlyMethods(['phpSetting'])->getMock();
        $limits->method('phpSetting')->willReturnCallback(fn (string $key): string => $ini[$key]);
        $this->app->instance(InstanceUploadLimits::class, $limits);
    }

    private function defaultUploadLimits(): array
    {
        return ['maxAttachmentsPerPost' => 4, 'maxAttachmentBytes' => 8388608,
            'maxVideoAttachmentBytes' => null, 'maxRequestBytes' => 134217728];
    }

    #[DataProvider('uploadLimitCases')]
    public function test_upload_limits_combine_the_instance_and_php_settings(array $ini, bool $videoEnabled, array $expected): void
    {
        $this->phpUploadSettings($ini);
        config(['openbook.video.enabled' => $videoEnabled]);
        $this->getJson('/rest/v1/instance')->assertOk()->assertJsonPath('instanceUploadLimits', $expected);
    }

    public static function uploadLimitCases(): array
    {
        return [
            'application limits win' => [[], true, ['maxAttachmentsPerPost' => 4, 'maxAttachmentBytes' => 8388608,
                'maxVideoAttachmentBytes' => 41943040, 'maxRequestBytes' => 134217728]],
            'PHP limits win' => [['max_file_uploads' => '2', 'upload_max_filesize' => '2M', 'post_max_size' => '6M'], true,
                ['maxAttachmentsPerPost' => 2, 'maxAttachmentBytes' => 2097152,
                    'maxVideoAttachmentBytes' => 2097152, 'maxRequestBytes' => 6291456]],
            'video is disabled' => [[], false, ['maxAttachmentsPerPost' => 4, 'maxAttachmentBytes' => 8388608,
                'maxVideoAttachmentBytes' => null, 'maxRequestBytes' => 134217728]],
            'unlimited PHP byte limits' => [['upload_max_filesize' => '0', 'post_max_size' => '0'], true,
                ['maxAttachmentsPerPost' => 4, 'maxAttachmentBytes' => 8388608,
                    'maxVideoAttachmentBytes' => 41943040, 'maxRequestBytes' => null]],
            'PHP units and separate request limit' => [['upload_max_filesize' => '1G', 'post_max_size' => '512K'], true,
                ['maxAttachmentsPerPost' => 4, 'maxAttachmentBytes' => 8388608,
                    'maxVideoAttachmentBytes' => 41943040, 'maxRequestBytes' => 524288]],
            'PHP uploads disabled' => [['file_uploads' => 'Off'], true,
                ['maxAttachmentsPerPost' => 0, 'maxAttachmentBytes' => 0,
                    'maxVideoAttachmentBytes' => 0, 'maxRequestBytes' => 134217728]],
            'zero file count disables attachments' => [['max_file_uploads' => '0'], false,
                ['maxAttachmentsPerPost' => 0, 'maxAttachmentBytes' => 8388608,
                    'maxVideoAttachmentBytes' => null, 'maxRequestBytes' => 134217728]],
        ];
    }

    public function test_administrator_overrides_and_php_limit_changes_invalidate_the_etag(): void
    {
        $first = $this->getJson('/rest/v1/instance')->assertOk();
        $etag = $first->headers->get('ETag');
        foreach ([InstanceSettings::KEY_MEDIA_MAX_ATTACHMENTS => '2', InstanceSettings::KEY_MEDIA_MAX_SIZE_KB => '1024',
            InstanceSettings::KEY_VIDEO_ENABLED => '1', InstanceSettings::KEY_VIDEO_MAX_UPLOAD_MB => '16'] as $key => $value) {
            SystemSetting::put($key, $value);
            $changed = $this->withHeader('If-None-Match', $etag)->getJson('/rest/v1/instance')->assertOk();
            $this->assertNotSame($etag, $changed->headers->get('ETag'));
            $etag = $changed->headers->get('ETag');
        }
        $changed->assertJsonPath('instanceUploadLimits.maxAttachmentsPerPost', 2)
            ->assertJsonPath('instanceUploadLimits.maxAttachmentBytes', 1048576)
            ->assertJsonPath('instanceUploadLimits.maxVideoAttachmentBytes', 16777216);
        $this->phpUploadSettings(['post_max_size' => '32M']);
        $this->withHeader('If-None-Match', $etag)->getJson('/rest/v1/instance')->assertOk()
            ->assertJsonPath('instanceUploadLimits.maxRequestBytes', 33554432);
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
            'instanceUploadLimits' => $this->defaultUploadLimits(),
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
            'instanceUploadLimits' => $this->defaultUploadLimits(),
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

    public function test_authenticated_instance_metadata_is_available_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->getJson('/rest/v1/instance')->assertOk()->assertJsonStructure(['instanceName', 'instanceDomain', 'instanceUrl']);
    }
}
