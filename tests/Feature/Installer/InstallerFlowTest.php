<?php

namespace Tests\Feature\Installer;

use App\Infrastructure\Installation\InstallationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallerFlowTest extends TestCase
{
    use RefreshDatabase;
    use UsesTemporaryEnvironmentFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTemporaryEnvironmentFile();
    }

    protected function tearDown(): void
    {
        $this->cleanupTemporaryEnvironmentFile();

        parent::tearDown();
    }

    public function test_any_request_is_redirected_to_the_installer_when_not_installed(): void
    {
        $this->simulateNotInstalled();

        $this->get('/')->assertRedirect('/install');
        $this->get('/accedi')->assertRedirect('/install');
    }

    public function test_the_installer_is_unreachable_once_the_application_is_installed(): void
    {
        InstallationLock::lock('testing');

        $this->get('/install')->assertRedirect('/');
        $this->get('/install/database')->assertRedirect('/');
    }

    public function test_the_health_check_route_is_never_blocked(): void
    {
        $this->simulateNotInstalled();

        $this->get('/up')->assertOk();
    }

    public function test_requirements_step_reports_the_running_php_version(): void
    {
        $this->simulateNotInstalled();

        $response = $this->get(route('install.requirements'));

        $response->assertOk();
        $response->assertSee(PHP_VERSION);
    }

    public function test_instance_step_redirects_back_to_database_step_when_schema_is_missing(): void
    {
        $this->simulateNotInstalled();

        // Punta la connessione predefinita verso un secondo database :memory:
        // completamente vuoto, senza toccare la connessione "sqlite" che
        // RefreshDatabase gestisce tramite transazione per il resto della suite.
        config([
            'database.connections.sqlite_without_schema' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'database.default' => 'sqlite_without_schema',
        ]);

        try {
            $response = $this->get(route('install.instance'));

            $response->assertRedirect(route('install.database'));
        } finally {
            config(['database.default' => 'sqlite']);
            DB::purge('sqlite_without_schema');
        }
    }

    public function test_database_step_is_shown_when_schema_already_exists(): void
    {
        $this->simulateNotInstalled();

        // RefreshDatabase ha gia' migrato la connessione sqlite in memoria di test.
        $response = $this->get(route('install.database'));

        $response->assertRedirect(route('install.instance'));
    }

    public function test_instance_step_creates_the_administrator_and_locks_the_installer(): void
    {
        $this->simulateNotInstalled();

        $response = $this->post(route('install.instance.store'), [
            'site_name' => 'Openbook Test',
            'domain' => 'openbook.example.test',
            'registration_open' => '1',
            'admin_username' => 'amministratore',
            'admin_email' => 'admin@openbook.example.test',
            'admin_password' => 'Password123',
            'admin_password_confirmation' => 'Password123',
        ]);

        $response->assertOk();
        $response->assertSee('Installazione completata');

        $this->assertDatabaseHas('users', [
            'username' => 'amministratore',
            'is_admin' => true,
        ]);

        $this->assertTrue(InstallationLock::isInstalled());

        // Una volta installato, l'installer non deve piu' essere raggiungibile.
        $this->get(route('install.requirements'))->assertRedirect('/');
    }

    public function test_instance_step_validates_required_fields(): void
    {
        $this->simulateNotInstalled();

        $response = $this->post(route('install.instance.store'), []);

        $response->assertRedirect(route('install.instance'));
        $response->assertSessionHasErrors(['site_name', 'domain', 'admin_username', 'admin_email', 'admin_password']);
        $this->assertFalse(InstallationLock::isInstalled());
    }
}
