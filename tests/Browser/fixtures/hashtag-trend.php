<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ViewErrorBag;

putenv('APP_CONFIG_CACHE='.sys_get_temp_dir().'/openbook-hashtag-browser-config.php');
putenv('APP_ROUTES_CACHE='.sys_get_temp_dir().'/openbook-hashtag-browser-routes.php');
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:']);
Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
$app->setLocale('it');
URL::forceRootUrl('http://openbook.test');
URL::forceScheme('http');
request()->setLaravelSession(app('session.store'));
view()->share('errors', new ViewErrorBag);
$tagTrend = [];
for ($offset = 29; $offset >= 0; $offset--) {
    $tagTrend[] = ['day' => now('UTC')->subDays($offset)->toDateString(), 'count' => $offset % 6 === 0 ? 0 : 6 + (($offset * 7) % 20)];
}
echo view('hashtags.show', ['tagName' => 'music', 'posts' => null, 'events' => collect(), 'tagTrend' => $tagTrend, 'isFollowing' => false])->render();
