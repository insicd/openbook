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
    }
}
