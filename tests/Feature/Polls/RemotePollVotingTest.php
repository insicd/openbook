<?php

namespace Tests\Feature\Polls;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsRemotePollVoting;
use Tests\TestCase;

class RemotePollVotingTest extends TestCase
{
    use RefreshDatabase, TestsRemotePollVoting;
}
