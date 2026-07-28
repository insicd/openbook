<?php

namespace App\Infrastructure\Installation;

use RuntimeException;

/**
 * Aggiorna in modo mirato le coppie chiave/valore del file .env, preservando
 * le righe esistenti (commenti compresi) e senza dipendere da pacchetti
 * esterni. Usato esclusivamente dall'installer guidato.
 */
final class EnvironmentFileWriter
{
    public function __construct(
        private readonly string $envPath = '',
    ) {}

    private function path(): string
    {
        return $this->envPath !== '' ? $this->envPath : base_path('.env');
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function update(array $values): void
    {
        $path = $this->path();

        if (! is_file($path)) {
            throw new RuntimeException('Il file .env non esiste o non e leggibile.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Impossibile leggere il file .env.');
        }

        foreach ($values as $key => $value) {
            $contents = $this->setKey($contents, $key, $value);
        }

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Impossibile scrivere il file .env. Verifica i permessi del file.');
        }
    }

    private function setKey(string $contents, string $key, ?string $value): string
    {
        $formatted = $this->formatValue($value);
        $line = "{$key}={$formatted}";
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return preg_replace($pattern, str_replace('\\', '\\\\', $line), $contents, 1);
        }

        return rtrim($contents)."\n".$line."\n";
    }

    private function formatValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.:\/\-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
