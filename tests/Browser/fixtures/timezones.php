<?php

use App\Application\Services\AccountRegistrar;
use App\Domain\Events\Event;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ViewErrorBag;

putenv('APP_CONFIG_CACHE='.sys_get_temp_dir().'/openbook-browser-config.php');
putenv('APP_ROUTES_CACHE='.sys_get_temp_dir().'/openbook-browser-routes.php');
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'UTC']);
date_default_timezone_set('UTC');
Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
$app->setLocale('en');
$request = Request::create('http://openbook.test/timezone', getenv('OPENBOOK_BROWSER_METHOD') ?: 'GET', [], json_decode(getenv('OPENBOOK_BROWSER_COOKIES') ?: '{}', true));
$app->instance('request', $request);
$request->setLaravelSession(app('session.store'));
URL::forceRootUrl('http://openbook.test');
view()->share('errors', new ViewErrorBag);
$user = app(AccountRegistrar::class)->register([
    'username' => 'browserzone', 'email' => 'zone@example.test', 'password' => 'Password123',
]);
$preferred = getenv('OPENBOOK_BROWSER_TIMEZONE') ?: null;
$user->settings->update(['timezone' => $preferred]);
if (getenv('OPENBOOK_BROWSER_GUEST') !== '1') {
    Auth::setUser($user);
    $request->setUserResolver(fn () => $user);
}
$event = Event::create([
    'uri' => 'https://events.example/browser', 'actor_id' => $user->actor->id, 'name' => 'Helsinki evening',
    'start_at' => Carbon::parse('2027-01-16 00:30', 'Europe/Helsinki')->utc(),
    'timezone' => 'Europe/Helsinki', 'status' => 'scheduled', 'visibility' => 'public',
]);
if (getenv('OPENBOOK_BROWSER_FORM') === '1') {
    echo view('events.create', ['timezones' => collect(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))->groupBy(fn ($timezone) => explode('/', $timezone, 2)[0])])->render();
} else {
    echo Blade::render(<<<'BLADE'
        @extends('layouts.app')
        @section('content')
            <p id="local-date">@userdate($event->start_at, 'Y-m-d H:i')</p>
            @include('events._card', ['event' => $event])
        @endsection
        BLADE, ['event' => $event]);
}
