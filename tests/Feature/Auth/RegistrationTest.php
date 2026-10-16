<?php

namespace Tests\Feature\Auth;

use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        // Header esplicito: questo test verifica il contenuto della pagina,
        // non la deduzione della lingua dal browser (vedi GuestLocaleTest),
        // quindi non deve dipendere dall'Accept-Language di default che il
        // client di test invia quando non specificato altrimenti.
        $response = $this->withHeaders(['Accept-Language' => 'it'])->get(route('register'));

        $response->assertOk();
        $response->assertSee('Crea il tuo account');
        $response->assertSee('pattern="[a-zA-Z0-9_]+"', false);
        $response->assertSee('Le lettere vengono salvate in minuscolo.');
    }

    public function test_a_user_can_register_with_valid_data(): void
    {
        Event::fake();

        $response = $this->post(route('register'), [
            'username' => 'NuovoUtente',
            'email' => 'nuovo@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertRedirect(route('feed.index'));
        $this->assertAuthenticated();

        $user = User::query()->where('username', 'nuovoutente')->firstOrFail();
        $this->assertSame('nuovo@example.test', $user->email);
        $this->assertFalse($user->is_admin);

        $actor = Actor::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('nuovoutente', $actor->preferred_username);
        $this->assertSame(config('app.url').'/users/nuovoutente', $actor->uri);
    }

    public function test_registration_handle_preview_uses_lowercase_for_previous_input(): void
    {
        $this->withSession(['_old_input' => ['username' => 'MimmoAmarelli']])
            ->get(route('register'))
            ->assertOk()
            ->assertSee('@mimmoamarelli@'.config('openbook.domain'))
            ->assertDontSee('@MimmoAmarelli@'.config('openbook.domain'));
    }

    public function test_invalid_registration_redisplays_the_normalized_handle(): void
    {
        $this->from(route('register'))->post(route('register'), [
            'username' => 'MimmoAmarelli',
            'email' => 'mimmo@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('register'))->assertSessionHasErrors('password');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('@mimmoamarelli@'.config('openbook.domain'))
            ->assertDontSee('@MimmoAmarelli@'.config('openbook.domain'));
    }

    #[DataProvider('invalidUsernames')]
    public function test_invalid_usernames_are_still_rejected(mixed $username): void
    {
        $response = $this->from(route('register'))->post(route('register'), [
            'username' => $username,
            'email' => 'invalido@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->get(route('register'))->assertOk();
    }

    public static function invalidUsernames(): array
    {
        return [
            'spaces and punctuation' => ['Nome Non Valido!'],
            'non ASCII letters' => ['PettiRössö'],
            'too short' => ['Ab'],
            'too long' => [str_repeat('A', 33)],
            'missing' => [null],
            'array' => [['PettiRosso']],
        ];
    }

    public function test_username_and_email_must_be_unique(): void
    {
        User::factory()->create(['username' => 'esistente', 'email' => 'esistente@example.test']);

        $response = $this->post(route('register'), [
            'username' => 'Esistente',
            'email' => 'nuovo2@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_a_mixed_case_username_cannot_reuse_a_local_actor_name(): void
    {
        Actor::query()->create([
            'type' => Actor::TYPE_GROUP,
            'is_local' => true,
            'preferred_username' => 'pettirosso',
            'domain' => config('openbook.domain'),
            'uri' => config('app.url').'/users/pettirosso',
            'status' => Actor::STATUS_ACTIVE,
        ]);

        $this->post(route('register'), [
            'username' => 'PettiRosso',
            'email' => 'pettirosso@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['username' => 'pettirosso']);
    }

    public function test_password_confirmation_must_match(): void
    {
        $response = $this->post(route('register'), [
            'username' => 'utentedue',
            'email' => 'utentedue@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'AltraPassword123',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_weak_passwords_are_rejected(): void
    {
        $response = $this->post(route('register'), [
            'username' => 'utentetre',
            'email' => 'utentetre@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
