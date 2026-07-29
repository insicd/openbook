<?php

namespace Tests\Feature\Auth;

use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
    }

    public function test_a_user_can_register_with_valid_data(): void
    {
        Event::fake();

        $response = $this->post(route('register'), [
            'username' => 'nuovoutente',
            'email' => 'nuovo@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertRedirect(route('profile.show', 'nuovoutente'));
        $this->assertAuthenticated();

        $user = User::query()->where('username', 'nuovoutente')->firstOrFail();
        $this->assertSame('nuovo@example.test', $user->email);
        $this->assertFalse($user->is_admin);

        $this->assertNotNull(Actor::query()->where('user_id', $user->id)->first());
    }

    public function test_username_must_be_lowercase_letters_numbers_and_underscores(): void
    {
        $response = $this->post(route('register'), [
            'username' => 'Nome Non Valido!',
            'email' => 'invalido@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_username_and_email_must_be_unique(): void
    {
        User::factory()->create(['username' => 'esistente', 'email' => 'esistente@example.test']);

        $response = $this->post(route('register'), [
            'username' => 'esistente',
            'email' => 'nuovo2@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertSessionHasErrors('username');
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
