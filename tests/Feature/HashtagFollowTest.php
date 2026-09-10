<?php

namespace Tests\Feature;

use App\Domain\Posts\Hashtag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class HashtagFollowTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_following_an_unknown_hashtag_creates_the_tag_and_relation(): void
    {
        $user = $this->createFullAccount('hashtagfollower');

        $this->actingAs($user)
            ->post(route('hashtags.follow', ['name' => 'Laravel']))
            ->assertRedirect();

        $hashtag = Hashtag::query()->where('name', 'laravel')->firstOrFail();

        $this->assertDatabaseHas('hashtag_follows', [
            'actor_id' => $user->actor->id,
            'hashtag_id' => $hashtag->id,
        ]);
    }

    public function test_following_a_hashtag_is_idempotent(): void
    {
        $user = $this->createFullAccount('idempotenttag');

        $this->actingAs($user)->post(route('hashtags.follow', ['name' => 'musica']));
        $this->actingAs($user)->post(route('hashtags.follow', ['name' => 'musica']));

        $this->assertDatabaseCount('hashtags', 1);
        $this->assertDatabaseCount('hashtag_follows', 1);
    }

    public function test_unfollowing_a_hashtag_only_removes_the_current_actor_relation(): void
    {
        $alice = $this->createFullAccount('tagalice');
        $bob = $this->createFullAccount('tagbob');
        $hashtag = Hashtag::query()->create(['name' => 'fotografia']);

        $alice->actor->followedHashtags()->attach($hashtag->id);
        $bob->actor->followedHashtags()->attach($hashtag->id);

        $this->actingAs($alice)
            ->delete(route('hashtags.unfollow', ['name' => 'fotografia']))
            ->assertRedirect();

        $this->assertDatabaseMissing('hashtag_follows', [
            'actor_id' => $alice->actor->id,
            'hashtag_id' => $hashtag->id,
        ]);
        $this->assertDatabaseHas('hashtag_follows', [
            'actor_id' => $bob->actor->id,
            'hashtag_id' => $hashtag->id,
        ]);
    }

    public function test_unfollowing_an_unknown_hashtag_is_idempotent(): void
    {
        $user = $this->createFullAccount('unknownuntag');

        $this->actingAs($user)
            ->delete(route('hashtags.unfollow', ['name' => 'inesistente']))
            ->assertRedirect();

        $this->assertDatabaseCount('hashtags', 0);
        $this->assertDatabaseCount('hashtag_follows', 0);
    }

    public function test_invalid_hashtag_names_are_rejected(): void
    {
        $user = $this->createFullAccount('invalidtag');

        $this->actingAs($user)
            ->post('/tag/non-valido/segui')
            ->assertNotFound();

        $this->assertDatabaseCount('hashtags', 0);
    }

    public function test_follow_routes_require_authentication(): void
    {
        $this->post(route('hashtags.follow', ['name' => 'musica']))
            ->assertRedirect(route('login'));
        $this->delete(route('hashtags.unfollow', ['name' => 'musica']))
            ->assertRedirect(route('login'));
    }

    public function test_hashtag_follow_relations_are_deleted_with_the_actor_and_hashtag(): void
    {
        $user = $this->createFullAccount('cascadetag');
        $hashtag = Hashtag::query()->create(['name' => 'temporaneo']);

        $user->actor->followedHashtags()->attach($hashtag->id);
        $user->actor->delete();

        $this->assertDatabaseCount('hashtag_follows', 0);

        $other = $this->createFullAccount('cascadetag2');
        $other->actor->followedHashtags()->attach($hashtag->id);
        $hashtag->delete();

        $this->assertDatabaseCount('hashtag_follows', 0);
    }

    public function test_hashtag_page_shows_the_correct_follow_action(): void
    {
        $user = $this->createFullAccount('tagpagestate');
        $hashtag = Hashtag::query()->create(['name' => 'musica']);

        $this->actingAs($user)
            ->get(route('hashtags.show', $hashtag->name))
            ->assertOk()
            ->assertSee(route('hashtags.follow', ['name' => $hashtag->name]), false)
            ->assertSee(__('openbook.follow.follow'));

        $user->actor->followedHashtags()->attach($hashtag->id);

        $this->actingAs($user)
            ->get(route('hashtags.show', $hashtag->name))
            ->assertOk()
            ->assertSee(route('hashtags.unfollow', ['name' => $hashtag->name]), false)
            ->assertSee(__('openbook.follow.unfollow'));
    }

    public function test_guest_can_view_hashtag_pages_without_follow_actions(): void
    {
        $hashtag = Hashtag::query()->create(['name' => 'pubblico']);

        $this->get(route('hashtags.show', $hashtag->name))
            ->assertOk()
            ->assertDontSee(route('hashtags.follow', ['name' => $hashtag->name]), false);
    }
}
