<?php

use App\Application\Services\PollResultsProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.connections.concurrent_poll' => $input['database'], 'database.default' => 'concurrent_poll', 'app.key' => $input['key']]);
$app->forgetInstance('encrypter');
Carbon::setTestNow($input['clock']);
echo "ready\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'go') {
    exit(2);
}
$processor = app(PollResultsProcessor::class);
if ($input['close']) {
    $processor->close($input['poll']);
}
echo $processor->distribute($input['poll'], $input['batch'])."\n";
