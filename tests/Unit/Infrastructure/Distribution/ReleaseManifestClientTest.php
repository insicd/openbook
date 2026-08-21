<?php

namespace Tests\Unit\Infrastructure\Distribution;

use App\Infrastructure\Distribution\ReleaseManifestClient;
use RuntimeException;
use Tests\TestCase;

class ReleaseManifestClientTest extends TestCase
{
    public function test_it_validates_a_well_formed_manifest(): void
    {
        $client = new ReleaseManifestClient;
        $manifest = $client->validate([
            'schema_version' => 1,
            'version' => '0.8.11',
            'min_php' => '8.2.0',
            'download_url' => 'https://about.openb.app/releases/openbook-0.8.11.zip',
            'sha256' => str_repeat('ab', 32),
            'requires_migration' => true,
        ]);

        $this->assertSame('0.8.11', $manifest['version']);
        $this->assertSame(str_repeat('ab', 32), $manifest['sha256']);
    }

    public function test_it_rejects_non_https_download_urls(): void
    {
        $this->expectException(RuntimeException::class);

        (new ReleaseManifestClient)->validate([
            'schema_version' => 1,
            'version' => '0.8.11',
            'min_php' => '8.2.0',
            'download_url' => 'http://evil.example/openbook.zip',
            'sha256' => str_repeat('ab', 32),
        ]);
    }

    public function test_it_detects_newer_versions(): void
    {
        $client = new ReleaseManifestClient;

        $this->assertTrue($client->isNewerThan('0.8.11', '0.8.10'));
        $this->assertFalse($client->isNewerThan('0.8.10', '0.8.10'));
        $this->assertFalse($client->isNewerThan('0.8.9', '0.8.10'));
        $this->assertTrue($client->isNewerThan('26.34', '0.9.2'));
        $this->assertTrue($client->isNewerThan('26.34.rc1', '26.34'));
        $this->assertFalse($client->isNewerThan('26.34', '26.34.rc1'));
        $this->assertTrue($client->isNewerThan('26.35', '26.34.rc2'));
    }

    public function test_it_accepts_calendar_and_legacy_version_strings(): void
    {
        $client = new ReleaseManifestClient;
        $sha = str_repeat('ab', 32);

        $this->assertSame('26.34', $client->validate([
            'schema_version' => 1,
            'version' => '26.34',
            'min_php' => '8.2.0',
            'download_url' => 'https://about.openb.app/releases/openbook-26.34.zip',
            'sha256' => $sha,
        ])['version']);

        $this->assertSame('26.34.rc1', $client->validate([
            'schema_version' => 1,
            'version' => '26.34.rc1',
            'min_php' => '8.2.0',
            'download_url' => 'https://about.openb.app/releases/openbook-26.34.rc1.zip',
            'sha256' => $sha,
        ])['version']);
    }
}
