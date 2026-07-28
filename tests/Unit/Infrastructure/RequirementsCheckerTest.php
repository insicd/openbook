<?php

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Installation\RequirementsChecker;
use Tests\TestCase;

class RequirementsCheckerTest extends TestCase
{
    public function test_it_reports_the_running_php_version(): void
    {
        $checks = (new RequirementsChecker)->check();

        $phpCheck = collect($checks)->firstWhere('label', 'Versione di PHP');

        $this->assertNotNull($phpCheck);
        $this->assertTrue($phpCheck['ok']);
        $this->assertStringContainsString(PHP_VERSION, $phpCheck['detail']);
    }

    public function test_it_reports_all_required_extensions_as_present_in_the_test_environment(): void
    {
        $checks = (new RequirementsChecker)->check();

        foreach (['curl', 'openssl', 'json', 'pdo', 'mbstring', 'fileinfo'] as $extension) {
            $check = collect($checks)->firstWhere('label', 'Estensione PHP: '.$extension);

            $this->assertNotNull($check, "Nessun controllo trovato per l'estensione {$extension}");
            $this->assertTrue($check['ok'], "L'estensione {$extension} dovrebbe essere disponibile nell'ambiente di test");
        }
    }

    public function test_it_reports_storage_directories_as_writable_in_the_test_environment(): void
    {
        $checks = (new RequirementsChecker)->check();

        $storageCheck = collect($checks)->firstWhere('label', 'Permessi di scrittura: storage/');

        $this->assertNotNull($storageCheck);
        $this->assertTrue($storageCheck['ok']);
    }

    public function test_required_extension_checks_are_marked_critical(): void
    {
        $checks = (new RequirementsChecker)->check();

        foreach (['curl', 'openssl', 'json', 'pdo', 'mbstring', 'fileinfo'] as $extension) {
            $check = collect($checks)->firstWhere('label', 'Estensione PHP: '.$extension);

            $this->assertNotNull($check);
            $this->assertTrue($check['critical']);
        }
    }

    public function test_gd_extension_check_is_recommended_but_not_critical(): void
    {
        $checks = (new RequirementsChecker)->check();

        $gdCheck = collect($checks)->firstWhere('label', 'Estensione PHP: gd (consigliata)');

        $this->assertNotNull($gdCheck);
        $this->assertFalse($gdCheck['critical']);
    }

    public function test_passes_critical_checks_returns_true_when_every_check_is_ok(): void
    {
        $this->assertTrue((new RequirementsChecker)->passesCriticalChecks());
    }
}
