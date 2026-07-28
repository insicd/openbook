<?php

namespace Tests\Feature\Console;

use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_new_administrator_account(): void
    {
        $this->artisan('openbook:make-admin', [
            '--username' => 'cliadmin',
            '--email' => 'cliadmin@example.test',
            '--password' => 'Password123',
        ])->assertSuccessful();

        $user = User::query()->where('username', 'cliadmin')->firstOrFail();

        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNotNull(Actor::query()->where('user_id', $user->id)->first());
    }

    public function test_it_rejects_invalid_input(): void
    {
        $this->artisan('openbook:make-admin', [
            '--username' => 'A',
            '--email' => 'non-valida',
            '--password' => '123',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_promotes_an_existing_user_to_administrator(): void
    {
        $user = User::factory()->create(['username' => 'dapromuovere', 'is_admin' => false]);

        $this->artisan('openbook:make-admin', ['--promote' => 'dapromuovere'])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_promoting_an_unknown_user_fails(): void
    {
        $this->artisan('openbook:make-admin', ['--promote' => 'nessunoquiesiste'])
            ->assertFailed();
    }
}
