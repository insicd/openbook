<?php

use App\Application\Queries\ReportModerationQuery;
use App\Application\Services\ReportManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\CreatesReportFixtures;

putenv('APP_CONFIG_CACHE='.sys_get_temp_dir().'/openbook-browser-config.php');
putenv('APP_ROUTES_CACHE='.sys_get_temp_dir().'/openbook-browser-routes.php');
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:', 'openbook.actor_key_bits' => 2048]);
Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
$app->setLocale($argv[2] ?? 'en');
$request = Request::create('http://openbook.test/admin/segnalazioni');
$app->instance('request', $request);
$request->setLaravelSession(app('session.store'));
URL::forceRootUrl('http://openbook.test');
view()->share('errors', new ViewErrorBag);
$fixture = new class
{
    use CreatesReportFixtures;

    public function render(string $mode): string
    {
        [$sender, $targets] = $this->reportFixture();
        $mod = $this->createFullAccount('moderator');
        $mod->forceFill(['is_moderator' => true])->save();
        Auth::setUser($mod);
        request()->setUserResolver(fn () => $mod);
        $targets[1]['subject']->update(['title' => 'Repeated unwanted messages', 'body' => "Evidence of the reported behaviour.\nOnly this content is previewed."]);
        $targets[3]['subject']->update(['visibility' => 'direct']);
        $fed = $this->storeReport($sender, $targets, details: "Please examine these references.\nThe sending Actor represents our server.");
        if ($mode === 'profile') {
            $fed = $this->storeReport($sender, [$targets[0]], 'profile', '[SIMULATION] Please review this local profile.');
        }
        $local = app(ReportManager::class)->reportPost($mod, $targets[1]['subject'], ['reason' => 'spam', 'details' => 'Local report for comparison.']);
        $local->refresh();
        $missing = $this->storeReport($sender, [['type' => 'post', 'uri' => 'https://openbook.test/posts/'.Str::uuid(), 'subject' => null]], 'missing');
        $queries = app(ReportModerationQuery::class);
        if ($mode === 'list') {
            return view('admin.reports.index', ['reports' => $queries->listing('all')->paginate(20), 'status' => 'all'])->render();
        }
        $report = match ($mode) {
            'local' => $local,
            'missing' => $missing,
            default => $fed,
        };
        $report->load(['reporter.profile', 'reviewer', 'targets']);

        return view('admin.reports.show', ['report' => $report, 'previews' => $queries->previews($report, $mod->actor)])->render();
    }
};
echo $fixture->render($argv[1] ?? 'detail');
