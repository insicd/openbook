<?php

namespace Tests\Feature\Auth;

use App\Domain\Accounts\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

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
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $response = $this->actingAs($user)->get($url);

        $response->assertRedirect(route('feed.index'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_invalid_hash_does_not_verify_the_email(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('email-sbagliata@example.test')],
        );

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
