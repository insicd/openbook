<?php

namespace App\Infrastructure\Distribution;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client del manifesto di release pubblicato su about.openb.app.
 *
 * @phpstan-type Manifest array{
 *     schema_version: int,
 *     version: string,
 *     min_php: string,
 *     released_at?: string,
 *     download_url: string,
 *     sha256: string,
 *     changelog_url?: string,
 *     requires_migration?: bool,
 *     notes?: string
 * }
 */
final class ReleaseManifestClient
{
    /**
     * @return Manifest
     */
    public function fetch(?string $manifestUrl = null): array
    {
        $url = $manifestUrl ?: (string) config('openbook.distribution.manifest_url');
        $timeout = (int) config('openbook.distribution.http_timeout', 120);

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'Openbook/'.config('openbook.version').' (ReleaseManifestClient)',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Impossibile scaricare il manifesto release (HTTP {$response->status()})."
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Il manifesto release non e\' un documento JSON valido.');
        }

        return $this->validate($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Manifest
     */
    public function validate(array $data): array
    {
        foreach (['schema_version', 'version', 'min_php', 'download_url', 'sha256'] as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                throw new RuntimeException("Manifesto release incompleto: manca \"{$key}\".");
            }
        }

        if ((int) $data['schema_version'] !== 1) {
            throw new RuntimeException('Schema del manifesto release non supportato.');
        }

        $version = (string) $data['version'];
        $sha = strtolower((string) $data['sha256']);
        $downloadUrl = (string) $data['download_url'];

        if (! preg_match('/^\d+\.\d+\.\d+/', $version)) {
            throw new RuntimeException('Versione release non valida.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new RuntimeException('Checksum SHA-256 del manifesto non valido.');
        }

        if (! str_starts_with($downloadUrl, 'https://')) {
            throw new RuntimeException('L\'URL di download deve usare HTTPS.');
        }

        return [
            'schema_version' => 1,
            'version' => $version,
            'min_php' => (string) $data['min_php'],
            'released_at' => isset($data['released_at']) ? (string) $data['released_at'] : null,
            'download_url' => $downloadUrl,
            'sha256' => $sha,
            'changelog_url' => isset($data['changelog_url']) ? (string) $data['changelog_url'] : null,
            'requires_migration' => (bool) ($data['requires_migration'] ?? true),
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
        ];
    }

    public function isNewerThan(string $remoteVersion, ?string $currentVersion = null): bool
    {
        $current = $currentVersion ?? (string) config('openbook.version');

        return version_compare($this->normalize($remoteVersion), $this->normalize($current), '>');
    }

    private function normalize(string $version): string
    {
        return preg_replace('/[^0-9.].*$/', '', $version) ?: '0.0.0';
    }
}
