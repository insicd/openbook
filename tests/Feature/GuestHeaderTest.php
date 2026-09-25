<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_header_exposes_events_login_and_register_with_accessible_labels(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee(route('events.index'), false);
        $response->assertSee(route('login'), false);
        $response->assertSee(route('register'), false);
        $response->assertSee(__('openbook.nav.events'), false);
        $response->assertSee(__('openbook.nav.login'), false);
        $response->assertSee(__('openbook.nav.register'), false);
        $response->assertSee('ob-nav--guest', false);
    }
}
