<?php

namespace App\Console\Commands;

use App\Infrastructure\Locations\GeoNamesCityImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

class UpdateCitiesCommand extends Command
{
    protected $signature = 'openbook:update-cities
        {file? : Percorso a cities500.zip o al TSV GeoNames già estratto}';

    protected $description = 'Scarica o importa il catalogo locale delle città GeoNames.';

    public function handle(GeoNamesCityImporter $importer): int
    {
        $lock = Cache::lock('openbook:update-cities', 900);

        if (! $lock->get()) {
            $this->warn('Un aggiornamento del catalogo delle città è già in corso.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $temporaryDirectory = null;

        try {
            $file = $this->argument('file');

            if (is_string($file) && $file !== '') {
                $source = realpath($file);

                if ($source === false || ! is_file($source)) {
                    throw new RuntimeException("File GeoNames non trovato: {$file}");
                }

                $directory = dirname($source);
                $admin1 = $this->firstExisting($directory.'/admin1CodesASCII.txt');
                $countries = $this->firstExisting($directory.'/countryInfo.txt');
                $this->line("Importazione da {$source}");
            } else {
                $temporaryDirectory = $this->makeTemporaryDirectory();
                $source = $temporaryDirectory.'/cities500.zip';
                $admin1 = $temporaryDirectory.'/admin1CodesASCII.txt';
                $countries = $temporaryDirectory.'/countryInfo.txt';

                $this->download((string) config('openbook.locations.cities_url'), $source);
                $this->download((string) config('openbook.locations.admin1_url'), $admin1);
                $this->download((string) config('openbook.locations.countries_url'), $countries);
            }

            if (str_ends_with(strtolower($source), '.zip')) {
                $temporaryDirectory ??= $this->makeTemporaryDirectory();
                $cities = $this->extractCities($source, $temporaryDirectory);
            } else {
                $cities = $source;
            }
            $result = $importer->import($cities, $admin1, $countries);
            $duration = number_format(microtime(true) - $startedAt, 2, ',', '.');

            $this->info("Catalogo aggiornato: {$result['imported']} città importate, {$result['deleted']} rimosse, {$duration} secondi.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($temporaryDirectory !== null) {
                $this->removeTemporaryDirectory($temporaryDirectory);
            }

            $lock->release();
        }
    }

    private function download(string $url, string $destination): void
    {
        $this->line("Download di {$url}");
        $response = Http::timeout((int) config('openbook.locations.download_timeout_seconds', 120))
            ->withOptions(['sink' => $destination])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Download GeoNames fallito con HTTP {$response->status()}.");
        }

        $size = filesize($destination);

        if ($size === false || $size === 0 || $size > (int) config('openbook.locations.max_download_bytes')) {
            throw new RuntimeException('Il file GeoNames scaricato ha una dimensione non valida.');
        }
    }

    private function extractCities(string $archive, string $directory): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException("L'estensione PHP zip è necessaria per importare archivi GeoNames; usare in alternativa il TSV estratto.");
        }

        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Archivio GeoNames non valido.');
        }

        try {
            $entry = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if (is_string($name) && preg_match('/cities\d+\.txt$/', $name) === 1) {
                    $entry = $name;
                    break;
                }
            }

            if ($entry === null) {
                throw new RuntimeException('L\'archivio non contiene un file cities*.txt.');
            }

            $stream = $zip->getStream($entry);
            $destination = $directory.'/'.basename($entry);
            $output = fopen($destination, 'wb');

            if ($stream === false || $output === false) {
                throw new RuntimeException('Impossibile estrarre il catalogo GeoNames.');
            }

            try {
                stream_copy_to_stream($stream, $output);
            } finally {
                fclose($stream);
                fclose($output);
            }

            return $destination;
        } finally {
            $zip->close();
        }
    }

    private function makeTemporaryDirectory(): string
    {
        $directory = storage_path('app/private/geonames-'.bin2hex(random_bytes(8)));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Impossibile creare la directory temporanea GeoNames.');
        }

        return $directory;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private function firstExisting(string $path): ?string
    {
        return is_file($path) ? $path : null;
    }
}
