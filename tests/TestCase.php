<?php

namespace Tests;

use App\Domain\Posts\PostBodyRenderer;
use App\Infrastructure\Installation\InstallationLock;
use App\Infrastructure\Security\Http\DnsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeDnsResolver;

abstract class TestCase extends BaseTestCase
{
    /**
     * La maggior parte dei test presuppone un'istanza gia' installata: il
     * middleware EnsureApplicationIsInstalled verifica un file di lock reale
     * sul filesystem (per poter funzionare anche prima delle migration), che
     * qui viene simulato cosi' da non dipendere dallo stato della macchina
     * di sviluppo su cui girano i test. Il contenuto originale (se il lock
     * esisteva gia', ad esempio su una macchina di sviluppo gia' installata)
     * viene salvato e ripristinato esattamente, cosi' la suite non lascia
     * l'ambiente reale in uno stato "da reinstallare".
     */
    private ?string $originalLockContents = null;

    /**
     * Impedisce alla suite di usare accidentalmente il database configurato
     * per l'istanza locale. RefreshDatabase esegue migrate:fresh sulle
     * connessioni non in-memory, quindi una configurazione Laravel rimasta in
     * cache potrebbe altrimenti cancellare il database di sviluppo.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $connection = $this->app['config']->get('database.default');
        $database = $this->app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(sprintf(
                'Test interrotti: la connessione database attiva è [%s] con database [%s], atteso sqlite [:memory:]. Rimuovere la cache di configurazione prima di eseguire la suite.',
                (string) $connection,
                (string) $database,
            ));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // La risoluzione DNS reale non deve mai far parte della suite: i
        // test di federazione usano domini fittizi (es. "remote.example").
        $this->app->bind(DnsResolver::class, FakeDnsResolver::class);

        PostBodyRenderer::clearMentionHrefCache();

        $this->originalLockContents = is_file(InstallationLock::path())
            ? file_get_contents(InstallationLock::path())
            : null;

        if ($this->originalLockContents === null) {
            InstallationLock::lock('testing');
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalLockContents === null) {
            if (is_file(InstallationLock::path())) {
                unlink(InstallationLock::path());
            }
        } else {
            file_put_contents(InstallationLock::path(), $this->originalLockContents, LOCK_EX);
        }

        parent::tearDown();
    }

    /**
     * Utilizzata dai test dedicati all'installer per simulare un'istanza
     * ancora da configurare.
     */
    protected function simulateNotInstalled(): void
    {
        if (is_file(InstallationLock::path())) {
            unlink(InstallationLock::path());
        }
    }
}
