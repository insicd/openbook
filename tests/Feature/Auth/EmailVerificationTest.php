<?php

namespace Tests\Feature\Auth;

use App\Domain\Accounts\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\CreatesAccounts;

    public function test_unverified_user_sees_the_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('verification.notice'));

        $response->assertOk();
    }

    public function test_already_verified_user_is_redirected_to_feed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('verification.notice'));

        $response->assertRedirect(route('feed.index'));
    }

    public function test_a_signed_link_verifies_the_email(): void
    {
        $user = $this->createFullAccount('confirmationflow');
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->get(route('feed.index'))->assertOk()->assertDontSee('id="ob-composer"', false);
        $payload = ['body' => 'First verified post', 'visibility' => 'public'];
        $this->post(route('posts.store'), $payload)->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('posts', 0);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $response = $this->actingAs($user)->get($url);

        $response->assertRedirect(route('feed.index'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->post(route('posts.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('posts', ['actor_id' => $user->actor->id, 'body' => $payload['body']]);
    }

    public static function invalidVerificationLinks(): array
    {
        return ['hash' => ['hash'], 'expired' => ['expired'], 'signature' => ['signature'], 'identity' => ['identity']];
    }

    #[DataProvider('invalidVerificationLinks')]
    public function test_an_invalid_link_does_not_verify_the_email(string $failure): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            $failure === 'expired' ? now()->subMinute() : now()->addMinutes(60),
            ['id' => $failure === 'identity' ? 'another-user' : $user->id,
                'hash' => sha1($failure === 'hash' ? 'email-sbagliata@example.test' : $user->email)],
        );

        if ($failure === 'signature') {
            $url .= '&tampered=1';
        }

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_registration_dispatches_a_verification_notification(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'username' => 'daverificare',
            'email' => 'daverificare@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $user = User::query()->where('username', 'daverificare')->firstOrFail();

        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_verification_email_can_be_resent(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->post(route('verification.send'));

        $response->assertRedirect();
        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
