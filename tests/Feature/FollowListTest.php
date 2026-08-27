<?php

namespace Tests\Feature;

use App\Application\Services\FollowManager;
use App\Domain\SocialGraph\Follow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class FollowListTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_guest_can_view_the_followers_list_of_a_local_profile(): void
    {
        $alice = $this->createFullAccount('alicefollowers');
        $bob = $this->createFullAccount('bobfollowers');
        app(FollowManager::class)->follow($bob->actor, $alice->actor);

        $response = $this->get('/@alicefollowers/follower');

        $response->assertOk();
        $response->assertSee('bobfollowers');
    }

    public function test_a_guest_can_view_the_following_list_of_a_local_profile(): void
    {
        $alice = $this->createFullAccount('alicefollowing');
        $bob = $this->createFullAccount('bobfollowing');
        app(FollowManager::class)->follow($alice->actor, $bob->actor);

        $response = $this->get('/@alicefollowing/seguiti');

        $response->assertOk();
        $response->assertSee('bobfollowing');
    }

    public function test_a_pending_follow_request_does_not_appear_in_either_list(): void
    {
        $alice = $this->createFullAccount('aliceprotetta');
        $alice->actor->update(['manually_approves_followers' => true]);
        $bob = $this->createFullAccount('bobinattesa');
        app(FollowManager::class)->follow($bob->actor, $alice->actor);

        $this->get('/@aliceprotetta/follower')->assertDontSee('bobinattesa');
        $this->get('/@bobinattesa/seguiti')->assertDontSee('aliceprotetta');
    }

    public function test_an_authenticated_viewer_sees_a_follow_button_for_someone_not_yet_followed(): void
    {
        $alice = $this->createFullAccount('aliceb1');
        $bob = $this->createFullAccount('bobb1');
        $carol = $this->createFullAccount('carolb1');
        app(FollowManager::class)->follow($bob->actor, $alice->actor);

        $response = $this->actingAs($carol)->get('/@aliceb1/follower');

        $response->assertOk();
        $response->assertSee(route('actors.follow', $bob->actor));
    }

    public function test_an_authenticated_viewer_sees_an_unfollow_button_for_someone_already_followed(): void
    {
        $alice = $this->createFullAccount('aliceb2');
        $bob = $this->createFullAccount('bobb2');
        app(FollowManager::class)->follow($bob->actor, $alice->actor);
        app(FollowManager::class)->follow($alice->actor, $bob->actor);

        $response = $this->actingAs($alice)->get('/@aliceb2/follower');

        $response->assertOk();
        $response->assertSee(route('actors.unfollow', $bob->actor));
    }

    public function test_the_followers_list_does_not_show_a_follow_button_on_the_viewers_own_row(): void
    {
        $alice = $this->createFullAccount('aliceb3');
        $carol = $this->createFullAccount('carolb3');
        app(FollowManager::class)->follow($carol->actor, $alice->actor);

        // Carol compare nell'elenco dei follower di Alice: sulla propria
        // riga non deve comparire alcun pulsante segui/smetti di seguire.
        $response = $this->actingAs($carol)->get('/@aliceb3/follower');

        // "actors.follow" e "actors.unfollow" condividono lo stesso URL
        // (differiscono solo per verbo HTTP): se non compare, non c'e' ne'
        // un modulo per seguire ne' uno per smettere di seguire quella riga.
        $response->assertOk();
        $response->assertSee('carolb3');
        $response->assertDontSee(route('actors.follow', $carol->actor));
    }

    public function test_visiting_the_followers_list_of_a_remote_actor_requires_authentication(): void
    {
        $remote = $this->createRemoteActor('remota1');

        $this->get(route('actors.followers', $remote))->assertRedirect(route('login'));
    }

    public function test_an_authenticated_user_can_view_the_followers_list_of_a_remote_actor(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 404)]);

        $remote = $this->createRemoteActor('remota2');
        $local = $this->createFullAccount('localefollower');
        app(FollowManager::class)->follow($local->actor, $remote)
            ->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);

        $response = $this->actingAs($local)->get(route('actors.followers', $remote));

        $response->assertOk();
        $response->assertSee('localefollower');
    }

    public function test_visiting_the_follow_lists_of_a_local_actor_id_redirects_to_the_canonical_profile_list(): void
    {
        $user = $this->createFullAccount('canonicolist');
        $viewer = $this->createFullAccount('viewerlist');

        $this->actingAs($viewer)
            ->get(route('actors.followers', $user->actor))
            ->assertRedirect(route('profile.followers', $user->username));

        $this->actingAs($viewer)
            ->get(route('actors.following', $user->actor))
            ->assertRedirect(route('profile.following', $user->username));
    }

    public function test_the_profile_page_links_the_counters_to_the_follow_lists(): void
    {
        $user = $this->createFullAccount('contatori');

        $response = $this->get('/@contatori');

        $response->assertOk();
        $response->assertSee(route('profile.followers', $user->username));
        $response->assertSee(route('profile.following', $user->username));
    }

    public function test_the_followers_list_exposes_infinite_scroll_markup_when_there_are_more_pages(): void
    {
        config(['openbook.feed.per_page' => 2]);

        $alice = $this->createFullAccount('alicepages');
        $bob = $this->createFullAccount('bobpages');
        $carol = $this->createFullAccount('carolpages');
        $dave = $this->createFullAccount('davepages');

        app(FollowManager::class)->follow($bob->actor, $alice->actor);
        app(FollowManager::class)->follow($carol->actor, $alice->actor);
        app(FollowManager::class)->follow($dave->actor, $alice->actor);

        $response = $this->get('/@alicepages/follower');

        $response->assertOk();
        $response->assertSee('id="ob-follow-list"', false);
        $response->assertSee('data-infinite-scroll', false);
        $response->assertSee('data-next-url="'.url('/@alicepages/follower?page=2').'"', false);
        $response->assertSee('<noscript>', false);
        $response->assertSee('ob-pagination', false);

        $pageTwo = $this->get('/@alicepages/follower?page=2');
        $pageTwo->assertOk();
        $pageTwo->assertSee('id="ob-follow-list"', false);
        $pageTwo->assertDontSee('data-next-url', false);
    }
}
