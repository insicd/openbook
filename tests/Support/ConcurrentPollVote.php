<?php

use App\Application\Services\RemotePollVoter;
use App\Domain\Polls\RemotePoll;
use App\Federation\Actors\Actor;
use App\Infrastructure\Security\Http\DnsResolver;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\FakeDnsResolver;

// Standalone test process: connection details travel over stdin, never argv.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['database.connections.concurrent_poll' => $input['database'], 'database.default' => 'concurrent_poll', 'app.key' => $input['key']]);
$app->forgetInstance('encrypter');
$app->bind(DnsResolver::class, FakeDnsResolver::class);
echo "ready\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'go') {
    exit(2);
}
$vote = app(RemotePollVoter::class)->vote(
    Actor::findOrFail($input['voter']),
    RemotePoll::findOrFail($input['poll']),
    $input['choices'], $input['version'],
);
echo $vote->id."\n";
