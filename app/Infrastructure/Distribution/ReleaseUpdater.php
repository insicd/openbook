<?php

namespace App\Infrastructure\Distribution;

use App\Infrastructure\Installation\InstallationLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Scarica, verifica e applica un pacchetto di release Openbook preservando
 * .env, storage/ e altri path locali. Pensato per shared hosting senza SSH.
 */
final class ReleaseUpdater
{
    /**
     * Path relativi alla root dell'istanza che non devono mai essere
     * sovrascritti da un aggiornamento.
     *
     * @var list<string>
     */
    private const PRESERVE = [
        '.env',
        '.env.backup',
        '.env.production',
        'storage',
        'setup-openbook.php',
    ];

    public function __construct(
        private readonly ReleaseManifestClient $manifestClient,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{version: string, migrated: bool}
     */
    public function apply(array $manifest, string $basePath): array
    {
        $manifest = $this->manifestClient->validate($manifest);

        if (version_compare(PHP_VERSION, $manifest['min_php'], '<')) {
            throw new RuntimeException(
                "Questa release richiede PHP {$manifest['min_php']} (attuale: ".PHP_VERSION.').'
            );
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Estensione PHP ZipArchive mancante: necessaria per aggiornare.');
        }

        @set_time_limit(0);

        $workDir = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'storage'
            .DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'updates';
        $this->ensureDirectory($workDir);

        $zipPath = $workDir.DIRECTORY_SEPARATOR.'openbook-'.$manifest['version'].'.zip';
        $extractDir = $workDir.DIRECTORY_SEPARATOR.'extract-'.$manifest['version'];

        $this->download($manifest['download_url'], $zipPath);
        $this->assertChecksum($zipPath, $manifest['sha256']);

        $this->purgeDirectory($extractDir);
        $this->ensureDirectory($extractDir);
        $this->extractZip($zipPath, $extractDir);

        $payloadRoot = $this->resolvePayloadRoot($extractDir);
        if ($payloadRoot === null) {
            throw new RuntimeException('Archivio release non riconosciuto (manca artisan o public/index.php).');
        }

        Artisan::call('down', [
            '--retry' => 60,
            '--secret' => bin2hex(random_bytes(8)),
        ]);

        $migrated = false;

        try {
            $this->mirrorPayload($payloadRoot, $basePath);

            if ($manifest['requires_migration']) {
                Artisan::call('migrate', ['--force' => true]);
                $migrated = true;
            }

            @Artisan::call('optimize:clear');

            if (InstallationLock::isInstalled()) {
                InstallationLock::lock($manifest['version']);
            }
        } finally {
            try {
                Artisan::call('up');
            } catch (Throwable) {
                // Se "up" fallisce resta in maintenance: l'admin puo' ripristinare.
            }
            @unlink($zipPath);
            $this->purgeDirectory($extractDir);
        }

        return [
            'version' => $manifest['version'],
            'migrated' => $migrated,
        ];
    }

    private function download(string $url, string $destination): void
    {
        $timeout = (int) config('openbook.distribution.http_timeout', 120);

        $response = Http::timeout($timeout)
            ->withOptions(['sink' => $destination])
            ->withHeaders([
                'User-Agent' => 'Openbook/'.config('openbook.version').' (ReleaseUpdater)',
            ])
            ->get($url);

        if (! $response->successful() || ! is_file($destination)) {
            @unlink($destination);
            throw new RuntimeException(
                "Download release fallito (HTTP {$response->status()})."
            );
        }
    }

    private function assertChecksum(string $zipPath, string $expectedSha256): void
    {
        $actual = hash_file('sha256', $zipPath);

        if (! is_string($actual) || ! hash_equals($expectedSha256, strtolower($actual))) {
            @unlink($zipPath);
            throw new RuntimeException('Checksum SHA-256 non corrispondente: aggiornamento interrotto.');
        }
    }

    private function extractZip(string $zipPath, string $extractDir): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Impossibile aprire l\'archivio ZIP della release.');
        }

        try {
            if (! $zip->extractTo($extractDir)) {
                throw new RuntimeException('Estrazione dell\'archivio release fallita.');
            }
        } finally {
            $zip->close();
        }
    }

    private function resolvePayloadRoot(string $extractDir): ?string
    {
        if ($this->looksLikeAppRoot($extractDir)) {
            return $extractDir;
        }

        $entries = array_values(array_filter(scandir($extractDir) ?: [], fn (string $e) => $e !== '.' && $e !== '..'));

        if (count($entries) === 1) {
            $candidate = $extractDir.DIRECTORY_SEPARATOR.$entries[0];
            if (is_dir($candidate) && $this->looksLikeAppRoot($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function looksLikeAppRoot(string $path): bool
    {
        return is_file($path.DIRECTORY_SEPARATOR.'artisan')
            && is_file($path.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php');
    }

    private function mirrorPayload(string $from, string $to): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = ltrim(str_replace($from, '', $item->getPathname()), DIRECTORY_SEPARATOR);
            $relative = str_replace('\\', '/', $relative);

            if ($relative === '' || $this->shouldPreserve($relative)) {
                continue;
            }

            $target = $to.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if ($item->isDir()) {
                $this->ensureDirectory($target);

                continue;
            }

            $this->ensureDirectory(dirname($target));

            if (! @copy($item->getPathname(), $target)) {
                throw new RuntimeException("Copia fallita: {$relative}");
            }
        }
    }

    private function shouldPreserve(string $relative): bool
    {
        $relative = ltrim($relative, '/');

        foreach (self::PRESERVE as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Impossibile creare la cartella: {$path}");
        }
    }

    private function purgeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
