<?php

namespace Tests\Feature;

use App\Application\Services\AnnounceManager;
use App\Application\Services\CommentComposer;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Application\Services\ReactionManager;
use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_activity_lists_comments_and_shares_but_not_likes_or_follows(): void
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
        $activity->assertSee('data-activity-type="announce"', false);
        $activity->assertSee('Un saggio sul fediverso.');
        $activity->assertSee(route('profile.show', 'autorefeed'), false);
        $activity->assertSee(route('posts.show', $post), false);
        $activity->assertDontSee('data-activity-type="like_post"', false);
        $activity->assertDontSee('data-activity-type="like_comment"', false);
        $activity->assertDontSee('data-activity-type="follow"', false);
        $activity->assertDontSee('data-activity-type="post"', false);
    }

    public function test_activity_hides_comments_on_posts_the_viewer_cannot_see(): void
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
            ->assertSee(__('openbook.profile.no_activity_yet'));

        $this->actingAs($actor)
            ->get(route('profile.activity', 'interagente'))
            ->assertOk()
            ->assertSee('Risposta riservata.')
            ->assertDontSee('data-activity-type="like_post"', false);
    }

    public function test_likes_and_follows_alone_do_not_fill_the_activity_tab(): void
    {
        $author = $this->createFullAccount('soloautore');
        $actor = $this->createFullAccount('soloreazioni');
        $post = $this->publishPost($author, 'Post senza eco pubblica.');

        app(ReactionManager::class)->like($actor->actor, $post);
        app(FollowManager::class)->follow($actor->actor, $author->actor);

        $this->get(route('profile.activity', 'soloreazioni'))
            ->assertOk()
            ->assertSee(__('openbook.profile.no_activity_yet'))
            ->assertDontSee('data-activity-type="like_post"', false)
            ->assertDontSee('data-activity-type="follow"', false);
    }

    public function test_the_activity_tab_exposes_infinite_scroll_markup_when_there_are_more_pages(): void
    {
        config(['openbook.profile.activity_per_page' => 2]);

        $author = $this->createFullAccount('paginatore');
        $actor = $this->createFullAccount('commentapagine');
        $post = $this->publishPost($author, 'Post da commentare a raffica.');

        $this->commentAt($actor, $post, 'Commento piu vecchio.', now()->subMinutes(3));
        $this->commentAt($actor, $post, 'Commento di mezzo.', now()->subMinutes(2));
        $this->commentAt($actor, $post, 'Commento piu recente.', now()->subMinutes(1));

        $response = $this->get(route('profile.activity', 'commentapagine'));

        $response->assertOk();
        $response->assertSee('id="ob-activity-list"', false);
        $response->assertSee('data-infinite-scroll', false);
        $response->assertSee('data-next-url="'.url('/@commentapagine/attivita?page=2').'"', false);
        $response->assertSee('<noscript>', false);
        $response->assertSee(__('openbook.profile.activity_scroll.next'));
        $response->assertSee('Commento piu recente.');
        $response->assertSee('Commento di mezzo.');
        $response->assertDontSee('Commento piu vecchio.');

        $pageTwo = $this->get(route('profile.activity', 'commentapagine').'?page=2');
        $pageTwo->assertOk();
        $pageTwo->assertSee('Commento piu vecchio.');
        $pageTwo->assertDontSee('Commento piu recente.');
        $pageTwo->assertDontSee('data-next-url', false);
    }

    public function test_a_remote_person_profile_has_an_activity_tab_with_a_local_incomplete_notice(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('visitattivita');
        $remote = $this->createRemoteActor('remotoattivo');
        $localPost = $this->publishPost($viewer, 'Post locale da commentare.');
        app(CommentComposer::class)->compose($remote, $localPost, 'Commento remoto visibile qui.');
        app(ReactionManager::class)->like($remote, $localPost);

        $this->actingAs($viewer)
            ->get(route('actors.show', $remote))
            ->assertOk()
            ->assertSee(__('openbook.profile.tab_activity'))
            ->assertSee(route('actors.activity', $remote), false);

        $activity = $this->actingAs($viewer)->get(route('actors.activity', $remote));
        $activity->assertOk();
        $activity->assertSee(__('openbook.profile.activity_remote_notice'));
        $activity->assertSee('data-activity-type="comment"', false);
        $activity->assertSee('Commento remoto visibile qui.');
        $activity->assertDontSee('data-activity-type="like_post"', false);
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

    private function commentAt(User $actor, Post $post, string $body, Carbon $at): Comment
    {
        $comment = app(CommentComposer::class)->compose($actor->actor, $post, $body);
        $comment->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $comment;
    }
}
