<?php

use App\Application\Services\ReportManager;
use App\Domain\Moderation\ReportTarget;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Standalone process; connection details travel over stdin, never argv.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.connections.concurrent_report' => $input['database'], 'database.default' => 'concurrent_report']);
DB::beginTransaction();
// Both workers establish an outer snapshot before either creates the case.
DB::table('reports')->count();
echo "ready\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'go') {
    exit(2);
}
$report = app(ReportManager::class)->recordFederated(
    Actor::findOrFail($input['sender']), 'https://remote.example/reports/concurrent', 'Reason',
    [['type' => ReportTarget::TYPE_POST, 'uri' => $input['uri'], 'subject' => Post::findOrFail($input['post'])]],
);
DB::commit();
echo $report->id."\n";
