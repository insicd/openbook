<?php

namespace Tests\Feature\Polls;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsPollResults;
use Tests\TestCase;

class PollResultsTest extends TestCase
{
    use RefreshDatabase, TestsPollResults;
}
