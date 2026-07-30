<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ComposeShortcutTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_authenticated_pages_include_the_compose_shortcut_controls(): void
    {
        $user = $this->createFullAccount('nuovopost');

        $response = $this->actingAs($user)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('id="ob-compose-header"', false);
        $response->assertSee('id="ob-compose-fab"', false);
        $response->assertSee('id="ob-composer"', false);
        $response->assertSee('assets/js/compose-shortcut.js', false);
        $response->assertSee('aria-label="Nuovo post"', false);
    }

    public function test_guests_do_not_see_the_compose_shortcut(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('id="ob-compose-header"', false);
        $response->assertDontSee('id="ob-compose-fab"', false);
    }
}
