<?php

namespace Tests\Feature;

use App\Application\Services\PostComposer;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class FeedWelcomeTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_empty_feed_shows_a_welcome_kit_with_staff_local_and_remote_suggestions(): void
    {
        $admin = $this->createFullAccount('kitadmin');
        $admin->forceFill(['is_admin' => true])->save();

        $local = $this->createFullAccount('kitlocale');
        $local->profile->update(['display_name' => 'Lucia Locale']);

        $remote = $this->createRemoteActor('fediverso', 'social.example', [
            'name' => 'Remo Remoto',
        ]);

        Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => $remote->uri.'/posts/welcome-kit',
            'body' => 'Ciao dal fediverso.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $newbie = $this->createFullAccount('kitnewbie');

        $this->actingAs($newbie)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee(__('openbook.feed.welcome_title'), false)
            ->assertSee(__('openbook.feed.welcome_staff'), false)
            ->assertSee('@kitadmin', false)
            ->assertSee(__('openbook.feed.welcome_local'), false)
            ->assertSee('Lucia Locale', false)
            ->assertSee(__('openbook.feed.welcome_remote'), false)
            ->assertSee('Remo Remoto', false)
            ->assertSee(route('communities.index'), false)
            ->assertSee(route('world.discover'), false)
            ->assertDontSee('id="ob-post-list"', false);
    }

    public function test_welcome_kit_is_hidden_once_the_feed_has_posts(): void
    {
        $user = $this->createFullAccount('kitposter');

        app(PostComposer::class)->compose($user->actor, [
            'body' => 'Il mio primo post.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertDontSee(__('openbook.feed.welcome_title'), false)
            ->assertSee('Il mio primo post.', false)
            ->assertSee('id="ob-post-list"', false);
    }

    public function test_welcome_kit_does_not_suggest_accounts_already_followed(): void
    {
        $admin = $this->createFullAccount('kitalready');
        $admin->forceFill(['is_admin' => true])->save();

        $newbie = $this->createFullAccount('kitfollower');

        Follow::query()->create([
            'follower_id' => $newbie->actor->id,
            'following_id' => $admin->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($newbie)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee(__('openbook.feed.welcome_title'), false)
            ->assertDontSee('@kitalready', false);
    }
}
