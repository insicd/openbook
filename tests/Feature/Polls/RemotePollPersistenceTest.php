<?php

namespace Tests\Feature\Polls;

use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\TestsRemotePollPersistence;
use Tests\TestCase;

class RemotePollPersistenceTest extends TestCase
{
    use TestsRemotePollPersistence;

    protected function setUp(): void
    {
        parent::setUp();
        // Migration tests must run without an outer SQLite transaction so that
        // schema rebuilds can disable FK checks and preserve existing children.
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
}
