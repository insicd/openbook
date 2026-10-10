<?php

use App\Domain\Accounts\User;
use App\Domain\Profiles\Profile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ViewErrorBag;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->setLocale('en');
$user = new User;
$user->setRelation('profile', new Profile(['links' => [
    ['label' => 'Website', 'url' => 'https://example.test'],
    ['label' => 'Occupation', 'value' => 'Teacher'],
]]));
view()->share('errors', new ViewErrorBag);
echo view('settings._profile_fields', ['viewer' => $user])->render();
