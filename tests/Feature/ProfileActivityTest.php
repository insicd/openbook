<?php

namespace Tests\Feature;

use App\Application\Services\AnnounceManager;
use App\Application\Services\CommentComposer;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Application\Services\ReactionManager;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ProfileActivityTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function publishPost(User $author, string $body, string $visibility = Post::VISIBILITY_PUBLIC): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => $visibility,
        ]);
    }

    public function test_the_profile_exposes_an_activity_tab(): void
    {
        $this->createFullAccount('attivista');

        $this->get(route('profile.show', 'attivista'))
            ->assertOk()
            ->assertSee(__('openbook.profile.tab_activity'))
            ->assertSee(route('profile.activity', 'attivista'), false);

        $this->get(route('profile.activity', 'attivista'))
            ->assertOk()
            ->assertSee(__('openbook.profile.no_activity_yet'))
            ->assertSee('ob-profile-tabs__tab is-active', false);
    }

    public function test_activity_includes_comments_likes_shares_and_follows(): void
    {
        $author = $this->createFullAccount('autorefeed');
        $actor = $this->createFullAccount('cronologia');
        $post = $this->publishPost($author, 'Un saggio sul fediverso.');

        app(CommentComposer::class)->compose($actor->actor, $post, 'Commento solo in attivita.');
        app(ReactionManager::class)->like($actor->actor, $post);
        app(AnnounceManager::class)->announce($actor->actor, $post);
        app(FollowManager::class)->follow($actor->actor, $author->actor);

        $postsTab = $this->get(route('profile.show', 'cronologia'));
        $postsTab->assertOk();
        $postsTab->assertDontSee('Commento solo in attivita.');

        $activity = $this->get(route('profile.activity', 'cronologia'));
        $activity->assertOk();
        $activity->assertSee('id="ob-activity-list"', false);
        $activity->assertSee('data-activity-type="comment"', false);
        $activity->assertSee('Commento solo in attivita.');
        $activity->assertSee('data-activity-type="like_post"', false);
        $activity->assertSee('Un saggio sul fediverso.');
        $activity->assertSee('data-activity-type="announce"', false);
        $activity->assertSee('data-activity-type="follow"', false);
        $activity->assertSee(route('profile.show', 'autorefeed'), false);
        $activity->assertSee(route('posts.show', $post), false);
    }

    public function test_activity_hides_interactions_on_posts_the_viewer_cannot_see(): void
    {
        $author = $this->createFullAccount('privato');
        $actor = $this->createFullAccount('interagente');
        $stranger = $this->createFullAccount('estraneo');
        $privatePost = $this->publishPost($author, 'Segreto tra follower.', Post::VISIBILITY_FOLLOWERS);

        app(FollowManager::class)->follow($actor->actor, $author->actor);
        app(CommentComposer::class)->compose($actor->actor, $privatePost, 'Risposta riservata.');
        app(ReactionManager::class)->like($actor->actor, $privatePost);

        $this->actingAs($stranger)
            ->get(route('profile.activity', 'interagente'))
            ->assertOk()
            ->assertDontSee('Risposta riservata.')
            ->assertDontSee('Segreto tra follower.')
            ->assertDontSee('data-activity-type="comment"', false)
            ->assertDontSee('data-activity-type="like_post"', false)
            ->assertSee('data-activity-type="follow"', false);

        $this->actingAs($actor)
            ->get(route('profile.activity', 'interagente'))
            ->assertOk()
            ->assertSee('Risposta riservata.')
            ->assertSee('data-activity-type="like_post"', false);
    }

    public function test_pending_follows_are_not_listed_as_activity(): void
    {
        $protected = $this->createFullAccount('protetto');
        $protected->actor->forceFill(['manually_approves_followers' => true])->save();
        $actor = $this->createFullAccount('inchiodo');

        $follow = app(FollowManager::class)->follow($actor->actor, $protected->actor);
        $this->assertSame(Follow::STATUS_PENDING, $follow->status);

        $this->get(route('profile.activity', 'inchiodo'))
            ->assertOk()
            ->assertDontSee('data-activity-type="follow"', false)
            ->assertSee(__('openbook.profile.no_activity_yet'));
    }

    public function test_the_activity_tab_exposes_infinite_scroll_markup_when_there_are_more_pages(): void
    {
        config(['openbook.profile.activity_per_page' => 2]);

        $author = $this->createFullAccount('paginatore');
        $this->publishPost($author, 'Post piu vecchio.')->forceFill([
            'published_at' => now()->subMinutes(3),
        ])->save();
        $this->publishPost($author, 'Post di mezzo.')->forceFill([
            'published_at' => now()->subMinutes(2),
        ])->save();
        $this->publishPost($author, 'Post piu recente.')->forceFill([
            'published_at' => now()->subMinutes(1),
        ])->save();

        $response = $this->get(route('profile.activity', 'paginatore'));

        $response->assertOk();
        $response->assertSee('id="ob-activity-list"', false);
        $response->assertSee('data-infinite-scroll', false);
        $response->assertSee('data-next-url="'.url('/@paginatore/attivita?page=2').'"', false);
        $response->assertSee('<noscript>', false);
        $response->assertSee(__('openbook.profile.activity_scroll.next'));
        $response->assertSee('Post piu recente.');
        $response->assertSee('Post di mezzo.');
        $response->assertDontSee('Post piu vecchio.');

        $pageTwo = $this->get(route('profile.activity', 'paginatore').'?page=2');
        $pageTwo->assertOk();
        $pageTwo->assertSee('Post piu vecchio.');
        $pageTwo->assertDontSee('Post piu recente.');
        $pageTwo->assertDontSee('data-next-url', false);
    }

    public function test_a_remote_person_profile_has_an_activity_tab_with_a_local_incomplete_notice(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('visitattivita');
        $remote = $this->createRemoteActor('remotoattivo');
        $localPost = $this->publishPost($viewer, 'Post locale da apprezzare.');
        app(ReactionManager::class)->like($remote, $localPost);

        $this->actingAs($viewer)
            ->get(route('actors.show', $remote))
            ->assertOk()
            ->assertSee(__('openbook.profile.tab_activity'))
            ->assertSee(route('actors.activity', $remote), false);

        $activity = $this->actingAs($viewer)->get(route('actors.activity', $remote));
        $activity->assertOk();
        $activity->assertSee(__('openbook.profile.activity_remote_notice'));
        $activity->assertSee('data-activity-type="like_post"', false);
        $activity->assertSee('Post locale da apprezzare.');
    }

    public function test_remote_groups_and_feeds_do_not_have_an_activity_tab(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('visitgruppo');
        $group = $this->createRemoteActor('grupporemoto', overrides: [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Community remota',
        ]);
        $feed = $this->createRemoteActor('feedrss', overrides: [
            'type' => Actor::TYPE_FEED,
            'name' => 'Blog RSS',
        ]);

        $this->actingAs($viewer)
            ->get(route('actors.show', $group))
            ->assertOk()
            ->assertDontSee(__('openbook.profile.tab_activity'))
            ->assertDontSee(route('actors.activity', $group), false);

        $this->actingAs($viewer)
            ->get(route('actors.activity', $group))
            ->assertRedirect(route('actors.show', $group));

        $this->actingAs($viewer)
            ->get(route('actors.show', $feed))
            ->assertOk()
            ->assertDontSee(__('openbook.profile.tab_activity'));

        $this->actingAs($viewer)
            ->get(route('actors.activity', $feed))
            ->assertRedirect(route('actors.show', $feed));
    }

    public function test_visiting_activity_on_a_local_actor_id_redirects_to_the_canonical_url(): void
    {
        $viewer = $this->createFullAccount('visitcanone');
        $target = $this->createFullAccount('canoneattivita');

        $this->actingAs($viewer)
            ->get(route('actors.activity', $target->actor))
            ->assertRedirect(route('profile.activity', $target->username));
    }
}
