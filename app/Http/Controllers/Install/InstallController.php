<?php

namespace App\Http\Controllers\Install;

use App\Application\Services\AccountRegistrar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Install\DatabaseConfigRequest;
use App\Http\Requests\Install\InstanceSetupRequest;
use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Installation\EnvironmentFileWriter;
use App\Infrastructure\Installation\InstallationLock;
use App\Infrastructure\Installation\RequirementsChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Throwable;

/**
 * Installer web guidato, pensato per shared hosting: nessun accesso SSH
 * richiesto, ogni passaggio e' una normale richiesta HTTP. Lo stato della
 * procedura non viene tenuto in sessione ma dedotto ogni volta dallo stato
 * reale del sistema (connessione al database, presenza delle tabelle,
 * esistenza del file di lock), rendendo l'installer ripetibile in sicurezza
 * in caso di interruzione a meta' processo.
 */
class InstallController extends Controller
{
    public function __construct(
        private readonly RequirementsChecker $requirementsChecker,
        private readonly EnvironmentFileWriter $envWriter,
    ) {}

    public function requirements(): View
    {
        return view('install.requirements', [
            'checks' => $this->requirementsChecker->check(),
            'canContinue' => $this->requirementsChecker->passesCriticalChecks(),
        ]);
    }

    public function showDatabase(): View|RedirectResponse
    {
        if (! $this->requirementsChecker->passesCriticalChecks()) {
            return redirect()->route('install.requirements');
        }

        if ($this->databaseReady()) {
            return redirect()->route('install.instance');
        }

        return view('install.database', ['error' => null]);
    }

    public function storeDatabase(DatabaseConfigRequest $request): View|RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->testConnection($data);
        } catch (PDOException $exception) {
            $request->flash();

            return view('install.database', [
                'error' => 'Connessione al database non riuscita: '.$this->cleanDatabaseErrorMessage($exception->getMessage()),
            ]);
        }

        $this->applyRuntimeDatabaseConfig($data);

        try {
            $this->ensureApplicationKey();
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $exception) {
            $request->flash();

            return view('install.database', [
                'error' => 'Connessione riuscita, ma l\'esecuzione delle migration e fallita: '.$exception->getMessage(),
            ]);
        }

        $this->envWriter->update([
            'DB_CONNECTION' => $data['driver'],
            'DB_HOST' => $data['host'],
            'DB_PORT' => (string) $data['port'],
            'DB_DATABASE' => $data['database'],
            'DB_USERNAME' => $data['username'],
            'DB_PASSWORD' => $data['password'] ?? '',
        ]);

        return redirect()->route('install.instance');
    }

    public function showInstance(): View|RedirectResponse
    {
        if (! $this->databaseReady()) {
            return redirect()->route('install.database');
        }

        return view('install.instance', [
            'defaultDomain' => request()->getHost(),
        ]);
    }

    public function storeInstance(Request $request, AccountRegistrar $registrar): View|RedirectResponse
    {
        if (! $this->databaseReady()) {
            return redirect()->route('install.database');
        }

        $validator = Validator::make($request->all(), (new InstanceSetupRequest)->rules(), [], (new InstanceSetupRequest)->attributes());

        if ($validator->fails()) {
            return redirect()->route('install.instance')
                ->withErrors($validator)
                ->withInput();
        }

        $data = $validator->validated();

        $rootUrl = 'https://'.$data['domain'];

        Config::set('openbook.domain', $data['domain']);
        Config::set('app.name', $data['site_name']);
        Config::set('app.url', $rootUrl);
        URL::forceRootUrl($rootUrl);

        $this->envWriter->update([
            'APP_NAME' => $data['site_name'],
            'APP_URL' => $rootUrl,
            'OPENBOOK_DOMAIN' => $data['domain'],
            'OPENBOOK_REGISTRATION_OPEN' => ! empty($data['registration_open']) ? 'true' : 'false',
        ]);

        $admin = $registrar->register([
            'username' => mb_strtolower($data['admin_username']),
            'email' => $data['admin_email'],
            'password' => Hash::make($data['admin_password']),
            'is_admin' => true,
        ]);

        $admin->forceFill(['email_verified_at' => now()])->save();

        SystemSetting::put('site_name', $data['site_name']);
        SystemSetting::put('registration_open', ! empty($data['registration_open']) ? '1' : '0');

        $cronToken = Str::random(64);
        SystemSetting::put('cron_token', $cronToken);

        $this->envWriter->update([
            'OPENBOOK_INSTALLED' => 'true',
        ]);

        InstallationLock::lock(config('app.version', '0.2.0-milestone2'));

        $storageLinked = $this->ensurePublicStorageLink();

        return view('install.finish', [
            'cronToken' => $cronToken,
            'domain' => $data['domain'],
            'adminUsername' => $admin->username,
            'storageLinked' => $storageLinked,
        ]);
    }

    private function databaseReady(): bool
    {
        try {
            return Schema::hasTable('users') && Schema::hasTable('system_settings');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array{driver: string, host: string, port: int, database: string, username: string, password?: string|null}  $data
     */
    private function testConnection(array $data): void
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $data['host'], $data['port'], $data['database']);

        $pdo = new PDO($dsn, $data['username'], $data['password'] ?? '', [
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo = null;
        unset($pdo);
    }

    /**
     * @param  array{driver: string, host: string, port: int, database: string, username: string, password?: string|null}  $data
     */
    private function applyRuntimeDatabaseConfig(array $data): void
    {
        $connection = $data['driver'];

        Config::set("database.connections.{$connection}.host", $data['host']);
        Config::set("database.connections.{$connection}.port", $data['port']);
        Config::set("database.connections.{$connection}.database", $data['database']);
        Config::set("database.connections.{$connection}.username", $data['username']);
        Config::set("database.connections.{$connection}.password", $data['password'] ?? '');
        Config::set('database.default', $connection);

        DB::purge($connection);
        DB::reconnect($connection);
    }

    private function ensureApplicationKey(): void
    {
        if (! empty(config('app.key'))) {
            return;
        }

        Artisan::call('key:generate', ['--force' => true]);
    }

    /**
     * Crea il collegamento simbolico "public/storage" verso "storage/app/public",
     * necessario per rendere raggiungibili gli allegati caricati dagli utenti.
     * Alcuni hosting condivisi non consentono la funzione symlink(): in quel
     * caso l'operazione fallisce in sicurezza e l'amministratore viene
     * avvisato nella pagina finale, con l'indicazione di crearlo manualmente
     * o di configurare il server per servire direttamente la cartella.
     */
    private function ensurePublicStorageLink(): bool
    {
        if (is_link(public_path('storage')) || is_dir(public_path('storage'))) {
            return true;
        }

        try {
            Artisan::call('storage:link');

            return is_link(public_path('storage'));
        } catch (Throwable) {
            return false;
        }
    }

    private function cleanDatabaseErrorMessage(string $message): string
    {
        // Evita di riflettere credenziali o DSN completi nel messaggio mostrato.
        return Str::of($message)
            ->after('SQLSTATE')
            ->limit(160)
            ->toString();
    }
}
