<?php

// Isolated fixture for the JavaScript OAuth integration test; never use the local DB.
use App\Application\Services\AccountRegistrar;
use App\Infrastructure\Auth\OAuthSigningKeys;
use App\Infrastructure\Database\SystemSetting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

$directory = getenv('OPENBOOK_OAUTH_TEST_DIR');
if (! $directory || ! is_dir($directory) || ! str_starts_with(realpath($directory), rtrim(realpath(sys_get_temp_dir()), '/').'/openbook-oauth-')) {
    throw new RuntimeException('A dedicated temporary test directory is required.');
}
putenv('APP_CONFIG_CACHE='.$directory.'/config.php');
putenv('APP_ROUTES_CACHE='.$directory.'/routes.php');
$environment = getenv('OPENBOOK_OAUTH_TEST_ENV') ?: 'local';
if (! in_array($environment, ['local', 'production'], true)) {
    throw new RuntimeException('Only local or production test environments are supported.');
}
putenv('APP_ENV='.$environment);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$kernel = $app->make(Kernel::class);
config([
    'app.env' => $environment, 'app.url' => getenv('OPENBOOK_OAUTH_TEST_URL'), 'app.locale' => 'en',
    'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite',
    'session.driver' => 'file', 'session.files' => $directory.'/sessions', 'session.secure' => false,
    'session.cookie' => 'openbook_oauth_test', 'session.domain' => null,
    'cache.default' => 'array', 'openbook.domain' => 'oauth.example', 'openbook.actor_key_bits' => 2048,
]);
$app->setLocale('en');
URL::forceRootUrl(getenv('OPENBOOK_OAUTH_TEST_URL'));
if ($environment === 'production' && parse_url(URL::to('/'), PHP_URL_SCHEME) !== 'https') {
    throw new RuntimeException('The production bootstrap must force HTTPS.');
}
// Only this isolated loopback fixture uses HTTP; production HTTPS behavior is verified above.
URL::forceScheme('http');
if (PHP_SAPI !== 'cli-server') {
    if (in_array('--inspect-keys', $argv ?? [], true)) {
        $raw = SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->value('value');
        if ($raw === null) {
            throw new RuntimeException('OAuth did not initialize its signing keys.');
        }
        $keys = app(OAuthSigningKeys::class)->getOrCreate();
        echo json_encode([
            'count' => SystemSetting::query()->where('key', 'oauth_signing_keys')->count(),
            'encrypted' => ! str_contains($raw, $keys->privateKey) && ! str_contains($raw, 'PRIVATE KEY'),
        ]);

        return;
    }
    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    $user = app(AccountRegistrar::class)->register([
        'username' => 'oauthclient', 'email' => 'oauthclient@example.test',
        'password' => Hash::make('OAuthClientTest123'),
    ]);
    $user->forceFill(['email_verified_at' => now()])->save();
    echo json_encode(['personId' => $user->actor->id, 'signingKeysInitialized' => SystemSetting::query()->where('key', 'oauth_signing_keys')->exists()]);

    return;
}
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
