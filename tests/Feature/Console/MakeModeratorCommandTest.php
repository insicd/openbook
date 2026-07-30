<?php

namespace Tests\Feature\Console;

use App\Domain\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeModeratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_promotes_an_existing_user_to_moderator(): void
    {
        $user = User::factory()->create(['username' => 'futuromodcli']);

        $this->artisan('openbook:make-moderator', ['--promote' => 'futuromodcli'])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_moderator);
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_promoting_an_unknown_user_fails(): void
    {
        $this->artisan('openbook:make-moderator', ['--promote' => 'inesistente'])
            ->assertFailed();
    }
}
