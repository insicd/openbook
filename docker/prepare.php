<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\ConsoleOutput;

$basePath = '/var/www/html';
$lock = fopen($basePath.'/storage/framework/docker-startup.lock', 'c');

if ($lock === false || ! flock($lock, LOCK_EX)) {
    fwrite(STDERR, "Cannot acquire the Openbook startup lock.\n");
    exit(1);
}

try {
    require $basePath.'/vendor/autoload.php';
    $app = require $basePath.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $output = new ConsoleOutput;

    $status = $kernel->call('migrate', [
        '--force' => true,
        '--no-interaction' => true,
    ], $output);

    if ($status === 0) {
        // Compiled views survive image replacement in the shared storage volume.
        $status = $kernel->call('view:clear', ['--no-interaction' => true], $output);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Openbook startup failed: '.$exception->getMessage().PHP_EOL);
    $status = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($status);
