<?php

namespace Tests\Feature\Polls;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsLocalPollVoting;
use Tests\TestCase;

class LocalPollVotingTest extends TestCase
{
    use RefreshDatabase, TestsLocalPollVoting;
}
