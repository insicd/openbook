<?php

namespace Tests\Feature\Moderation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsFederatedReportPersistence;
use Tests\TestCase;

class FederatedReportPersistenceTest extends TestCase
{
    use RefreshDatabase, TestsFederatedReportPersistence;
}
