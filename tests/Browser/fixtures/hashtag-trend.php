<?php

use App\Application\Services\AccountRegistrar;
use App\Domain\Posts\Hashtag;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
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
if ($mode = getenv('OPENBOOK_HASHTAG_TICKERS')) {
    if ($mode === 'auth') {
        Queue::fake();
        $user = app(AccountRegistrar::class)->register([
            'username' => 'tickerpreview', 'email' => 'tickerpreview@example.test', 'password' => 'Synthetic-test-password',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        auth()->setUser($user);
    }
    $hashtags = collect();
    $tagTrends = [];
    $followed = [];
    foreach (['music', 'fotografia', 'unhashtagparticolarmentelungoperverificareillayout', 'arte', 'cinema', 'viaggi', 'concerti', 'opensource'] as $index => $name) {
        $tag = new Hashtag(['name' => $name]);
        $tag->id = (string) Str::uuid();
        $tag->usage_count = 1234 - $index * 130;
        $hashtags->push($tag);
        if ($index % 2 === 0) {
            $followed[] = $tag->id;
        }
        for ($day = 6; $day >= 0; $day--) {
            $tagTrends[$tag->id][] = ['day' => now('UTC')->subDays($day)->toDateString(), 'count' => $index === 3 ? 0 : (($day * 11 + $index * 3) % 23)];
        }
    }
    echo view('hashtags.index', ['hashtags' => $hashtags, 'tagTrends' => $tagTrends,
        'trendingDays' => 7, 'followedHashtagIds' => $followed])->render();
} else {
    echo view('hashtags.show', ['tagName' => 'music', 'posts' => null, 'events' => collect(), 'tagTrend' => $tagTrend, 'isFollowing' => false])->render();
}
