<?php

namespace App\Infrastructure\Installation;

/**
 * Segna in modo permanente il completamento dell'installazione guidata.
 *
 * Usa un semplice file di lock sul filesystem locale invece di una riga a
 * database, cosi' il controllo puo' avvenire anche prima che le migration
 * siano state eseguite (nessuna dipendenza dal database).
 */
final class InstallationLock
{
    public static function path(): string
    {
        return storage_path('installed.lock');
    }

    public static function isInstalled(): bool
    {
        return is_file(self::path());
    }

    public static function lock(string $version): void
    {
        file_put_contents(
            self::path(),
            json_encode([
                'installed_at' => now()->toIso8601String(),
                'version' => $version,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }
}
