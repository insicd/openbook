<?php

namespace Tests\Feature;

use App\Application\Services\AccountRegistrar;
use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountRegistrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_complete_local_account_graph(): void
    {
        $registrar = app(AccountRegistrar::class);

        $user = $registrar->register([
            'username' => 'giulia',
            'email' => 'giulia@example.test',
            'password' => Hash::make('Password123'),
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertDatabaseHas('users', ['username' => 'giulia', 'email' => 'giulia@example.test']);
        $this->assertDatabaseHas('profiles', ['user_id' => $user->id, 'display_name' => 'giulia']);
        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id]);

        $actor = Actor::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($actor);
        $this->assertTrue($actor->is_local);
        $this->assertSame(Actor::TYPE_PERSON, $actor->type);
        $this->assertSame('giulia', $actor->preferred_username);
        $this->assertSame(config('openbook.domain'), $actor->domain);
        $this->assertSame(url('/@giulia'), $actor->uri);

        $this->assertNotNull($actor->key);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $actor->key->public_key);
        $this->assertTrue($actor->key->hasPrivateKey());

        $this->assertNotNull($actor->endpoints);
        $this->assertSame(url('/inbox'), $actor->endpoints->shared_inbox);
        $this->assertSame(url('/users/giulia/inbox'), $actor->endpoints->inbox);
    }

    public function test_the_private_key_is_stored_encrypted_at_rest(): void
    {
        $registrar = app(AccountRegistrar::class);

        $user = $registrar->register([
            'username' => 'lorenzo',
            'email' => 'lorenzo@example.test',
            'password' => Hash::make('Password123'),
        ]);

        $actor = Actor::query()->where('user_id', $user->id)->first();

        $rawValue = DB::table('actor_keys')->where('actor_id', $actor->id)->value('private_key');

        $this->assertStringNotContainsString('BEGIN', $rawValue);
        $this->assertStringStartsWith('-----BEGIN', $actor->key->fresh()->private_key);
    }

    public function test_registration_rolls_back_completely_on_failure(): void
    {
        $registrar = app(AccountRegistrar::class);

        $registrar->register([
            'username' => 'unico',
            'email' => 'unico@example.test',
            'password' => Hash::make('Password123'),
        ]);

        try {
            $registrar->register([
                'username' => 'unico',
                'email' => 'altro@example.test',
                'password' => Hash::make('Password123'),
            ]);
            $this->fail('Ci si aspettava un errore di unicita del nome utente.');
        } catch (\Throwable) {
            // atteso
        }

        $this->assertSame(1, User::query()->where('username', 'unico')->count());
        $this->assertDatabaseMissing('users', ['email' => 'altro@example.test']);
    }
}
