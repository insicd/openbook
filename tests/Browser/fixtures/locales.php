<?php

use App\Application\Services\AccountRegistrar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ViewErrorBag;

putenv('APP_CONFIG_CACHE='.sys_get_temp_dir().'/openbook-browser-config.php');
putenv('APP_ROUTES_CACHE='.sys_get_temp_dir().'/openbook-browser-routes.php');
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
$app->setLocale('en');
URL::forceRootUrl('http://openbook.test');
request()->setLaravelSession(app('session.store'));
view()->share('errors', new ViewErrorBag);
$guest = view('auth.login')->render();
Auth::setUser(app(AccountRegistrar::class)->register([
    'username' => 'browserlocale', 'email' => 'browser@example.test', 'password' => Hash::make('Password123'),
]));
$account = view('auth.login')->render();
echo json_encode(['guest' => $guest, 'account' => $account], JSON_THROW_ON_ERROR);
