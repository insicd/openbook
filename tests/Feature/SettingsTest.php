<?php

namespace Tests\Feature;

use App\Application\Services\FollowManager;
use App\Domain\SocialGraph\Follow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_a_guest_cannot_view_the_settings_page(): void
    {
        $response = $this->get('/impostazioni');

        $response->assertRedirect(route('login'));
    }

    public function test_an_authenticated_user_can_view_the_settings_page(): void
    {
        $user = $this->createFullAccount('alice');

        $response = $this->actingAs($user)->get('/impostazioni');

        $response->assertOk();
        $response->assertSee(__('openbook.settings.title'));
    }

    public function test_a_user_can_update_their_display_name_bio_and_links(): void
    {
        $user = $this->createFullAccount('alice');

        $response = $this->actingAs($user)->put(route('settings.profile.update'), [
            'display_name' => 'Alice Wonderland',
            'bio' => 'Curiouser and curiouser.',
            'links' => [
                ['label' => 'Sito', 'url' => 'https://example.test'],
                ['label' => '', 'url' => ''],
            ],
        ]);

        $response->assertRedirect(route('settings.edit'));

        $user->profile->refresh();
        $this->assertSame('Alice Wonderland', $user->profile->display_name);
        $this->assertSame('Curiouser and curiouser.', $user->profile->bio);
        $this->assertSame([['label' => 'Sito', 'url' => 'https://example.test']], $user->profile->links);
    }

    public function test_updating_the_display_name_also_updates_the_federated_actor_name(): void
    {
        $user = $this->createFullAccount('alice');

        $this->actingAs($user)->put(route('settings.profile.update'), [
            'display_name' => 'Alice Wonderland',
        ]);

        $this->assertSame('Alice Wonderland', $user->actor->fresh()->name);
    }

    public function test_a_user_can_upload_an_avatar_and_the_previous_one_is_removed(): void
    {
        Storage::fake('public');
        $user = $this->createFullAccount('alice');

        $this->actingAs($user)->put(route('settings.profile.update'), [
            'display_name' => 'Alice',
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 800, 800),
        ]);

        $user->profile->refresh();
        $firstPath = $user->profile->avatar_path;
        $this->assertNotNull($firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($user)->put(route('settings.profile.update'), [
            'display_name' => 'Alice',
            'avatar' => UploadedFile::fake()->image('avatar2.jpg', 800, 800),
        ]);

        $user->profile->refresh();
        $this->assertNotSame($firstPath, $user->profile->avatar_path);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($user->profile->avatar_path);
    }

    public function test_it_rejects_a_non_image_file_as_avatar(): void
    {
        Storage::fake('public');
        $user = $this->createFullAccount('alice');

        $response = $this->actingAs($user)->put(route('settings.profile.update'), [
            'display_name' => 'Alice',
            'avatar' => UploadedFile::fake()->create('script.php', 5, 'application/x-httpd-php'),
        ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->profile->fresh()->avatar_path);
    }

    public function test_a_user_can_update_their_interface_language(): void
    {
        $user = $this->createFullAccount('alice');

        $response = $this->actingAs($user)->put(route('settings.account.update'), [
            'locale' => 'en',
            'default_post_visibility' => 'public',
        ]);

        $response->assertRedirect(route('settings.edit'));
        $this->assertSame('en', $user->settings->fresh()->locale);

        $response = $this->actingAs($user)->get(route('settings.edit'));
        $response->assertSee(__('openbook.settings.title', [], 'en'));
    }

    public function test_a_user_can_change_their_default_post_visibility(): void
    {
        $user = $this->createFullAccount('alice');

        $this->actingAs($user)->put(route('settings.account.update'), [
            'locale' => 'it',
            'default_post_visibility' => 'followers',
        ]);

        $this->assertSame('followers', $user->settings->fresh()->default_post_visibility);

        $response = $this->actingAs($user)->get(route('feed.index'));
        $response->assertSee('name="visibility"', false);
    }

    public function test_enabling_the_protected_account_option_makes_new_follow_requests_pending(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $this->actingAs($alice)->put(route('settings.account.update'), [
            'locale' => 'it',
            'default_post_visibility' => 'public',
            'manually_approves_followers' => '1',
        ]);

        $this->assertTrue($alice->actor->fresh()->manually_approves_followers);
        $this->assertTrue($alice->settings->fresh()->manually_approves_followers);

        $follow = app(FollowManager::class)->follow($bob->actor, $alice->actor->fresh());

        $this->assertTrue($follow->status === Follow::STATUS_PENDING);
    }

    public function test_disabling_discoverable_removes_the_account_from_suggestions(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $this->actingAs($bob)->put(route('settings.account.update'), [
            'locale' => 'it',
            'default_post_visibility' => 'public',
            'discoverable' => '0',
        ]);

        $this->assertFalse($bob->settings->fresh()->discoverable);

        $response = $this->actingAs($alice)->get(route('feed.index'));
        $response->assertDontSee($bob->actor->fresh()->displayName());
    }
}
