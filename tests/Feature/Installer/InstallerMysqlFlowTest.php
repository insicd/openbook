<?php

namespace Tests\Feature\Installer;

use App\Infrastructure\Installation\InstallationLock;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Test di integrazione reale contro un server MySQL raggiungibile, per
 * verificare l'unico percorso che non puo' essere esercitato in modo
 * affidabile con SQLite: il passo 2 dell'installer (test di connessione +
 * esecuzione delle migration su MySQL/MariaDB veri).
 *
 * Il test si salta automaticamente se le variabili OPENBOOK_TEST_MYSQL_*
 * non puntano a un server raggiungibile (es. in ambienti CI privi di MySQL).
 */
class InstallerMysqlFlowTest extends TestCase
{
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

    private function skipIfMysqlUnavailable(): array
    {
        $config = [
            'host' => env('OPENBOOK_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => (int) env('OPENBOOK_TEST_MYSQL_PORT', 3306),
            'database' => env('OPENBOOK_TEST_MYSQL_DATABASE', 'openbook_test'),
            'username' => env('OPENBOOK_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('OPENBOOK_TEST_MYSQL_PASSWORD', ''),
        ];

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']),
                $config['username'],
                $config['password'],
                [PDO::ATTR_TIMEOUT => 2],
            );

            foreach ($pdo->query('SHOW TABLES') as $row) {
                $pdo->exec('DROP TABLE IF EXISTS `'.$row[0].'`');
            }
        } catch (PDOException $exception) {
            $this->markTestSkipped('Nessun server MySQL di test raggiungibile: '.$exception->getMessage());
        }

        return $config;
    }

    public function test_the_database_step_connects_and_migrates_a_real_mysql_server(): void
    {
        $config = $this->skipIfMysqlUnavailable();

        $this->simulateNotInstalled();

        $response = $this->post(route('install.database.store'), [
            'driver' => 'mysql',
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
        ]);

        $response->assertRedirect(route('install.instance'));

        $this->assertTrue(DB::connection('mysql')->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection('mysql')->getSchemaBuilder()->hasTable('actor_keys'));

        $envContents = file_get_contents($this->temporaryEnvPath);
        $this->assertStringContainsString('DB_DATABASE='.$config['database'], $envContents);
    }

    public function test_the_database_step_reports_a_friendly_error_for_wrong_credentials(): void
    {
        $config = $this->skipIfMysqlUnavailable();

        $this->simulateNotInstalled();

        $response = $this->post(route('install.database.store'), [
            'driver' => 'mysql',
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => 'password-decisamente-sbagliata',
        ]);

        $response->assertOk();
        $response->assertSee('Connessione al database non riuscita');
        $this->assertFalse(InstallationLock::isInstalled());
    }
}
