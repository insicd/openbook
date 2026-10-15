<?php

namespace Tests\Feature\Polls;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsRemotePollPersistence;
use Tests\TestCase;

class RemotePollPersistenceTest extends TestCase
{
    use RefreshDatabase, TestsRemotePollPersistence;
}
