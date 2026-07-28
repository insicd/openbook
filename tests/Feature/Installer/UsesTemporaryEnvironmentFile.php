<?php

namespace Tests\Feature\Installer;

use App\Infrastructure\Installation\EnvironmentFileWriter;

/**
 * L'installer scrive nel file .env reale dell'applicazione: nei test questo
 * comportamento viene reindirizzato verso una copia temporanea, per non
 * corrompere la configurazione dell'ambiente in cui gira la suite.
 */
trait UsesTemporaryEnvironmentFile
{
    private ?string $temporaryEnvPath = null;

    protected function bindTemporaryEnvironmentFile(): void
    {
        $this->temporaryEnvPath = tempnam(sys_get_temp_dir(), 'openbook_env_');
        copy(base_path('.env.example'), $this->temporaryEnvPath);

        $this->app->bind(EnvironmentFileWriter::class, fn () => new EnvironmentFileWriter($this->temporaryEnvPath));
    }

    protected function cleanupTemporaryEnvironmentFile(): void
    {
        if ($this->temporaryEnvPath !== null && is_file($this->temporaryEnvPath)) {
            unlink($this->temporaryEnvPath);
        }
    }
}
