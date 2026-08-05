<?php

namespace Tests\Feature\Reactions;

use App\Application\Services\AnnounceManager;
use App\Application\Services\CommentComposer;
use App\Application\Services\PostComposer;
use App\Application\Services\ReactionManager;
use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ReactionTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function publishPost(User $author): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => 'Un post da apprezzare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
    }

    public function test_liking_a_post_increments_the_counter_and_notifies_the_author(): void
    {
        $author = $this->createFullAccount('autoreliked');
        $liker = $this->createFullAccount('mipiace');
        $post = $this->publishPost($author);

        app(ReactionManager::class)->like($liker->actor, $post);

        $post->refresh();
        $this->assertSame(1, $post->likes_count);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $author->id,
            'actor_id' => $liker->actor->id,
            'type' => Notification::TYPE_LIKE,
        ]);
    }

    public function test_liking_the_same_post_twice_does_not_create_a_duplicate(): void
    {
        $author = $this->createFullAccount('autoreliked2');
        $liker = $this->createFullAccount('mipiace2');
        $post = $this->publishPost($author);

        $reactions = app(ReactionManager::class);
        $reactions->like($liker->actor, $post);
        $reactions->like($liker->actor, $post);

        $post->refresh();
        $this->assertSame(1, $post->likes_count);
        $this->assertDatabaseCount('likes', 1);
    }

    public function test_unliking_decrements_the_counter(): void
    {
        $author = $this->createFullAccount('autoreliked3');
        $liker = $this->createFullAccount('mipiace3');
        $post = $this->publishPost($author);

        $reactions = app(ReactionManager::class);
        $reactions->like($liker->actor, $post);
        $reactions->unlike($liker->actor, $post);

        $post->refresh();
        $this->assertSame(0, $post->likes_count);
        $this->assertDatabaseCount('likes', 0);
    }

    public function test_a_user_does_not_get_notified_for_liking_their_own_post(): void
    {
        $author = $this->createFullAccount('autoreliked4');
        $post = $this->publishPost($author);

        app(ReactionManager::class)->like($author->actor, $post);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_comments_can_also_be_liked(): void
    {
        $author = $this->createFullAccount('autorecomm');
        $commenter = $this->createFullAccount('commentliked');
        $liker = $this->createFullAccount('likecommento');
        $post = $this->publishPost($author);

        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Un commento carino');

        app(ReactionManager::class)->like($liker->actor, $comment);

        $comment->refresh();
        $this->assertSame(1, $comment->likes_count);
    }

    public function test_announcing_a_post_increments_the_counter_without_duplicating_content(): void
    {
        $author = $this->createFullAccount('autoreannounce');
        $sharer = $this->createFullAccount('condivisore');
        $post = $this->publishPost($author);

        app(AnnounceManager::class)->announce($sharer->actor, $post);

        $post->refresh();
        $this->assertSame(1, $post->announces_count);
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $author->id,
            'actor_id' => $sharer->actor->id,
            'type' => Notification::TYPE_SHARE,
        ]);
    }

    public function test_announcing_the_same_post_twice_does_not_duplicate(): void
    {
        $author = $this->createFullAccount('autoreannounce2');
        $sharer = $this->createFullAccount('condivisore2');
        $post = $this->publishPost($author);

        $announces = app(AnnounceManager::class);
        $announces->announce($sharer->actor, $post);
        $announces->announce($sharer->actor, $post);

        $post->refresh();
        $this->assertSame(1, $post->announces_count);
    }

    public function test_unannouncing_decrements_the_counter(): void
    {
        $author = $this->createFullAccount('autoreannounce3');
        $sharer = $this->createFullAccount('condivisore3');
        $post = $this->publishPost($author);

        $announces = app(AnnounceManager::class);
        $announces->announce($sharer->actor, $post);
        $announces->unannounce($sharer->actor, $post);

        $post->refresh();
        $this->assertSame(0, $post->announces_count);
    }

    public function test_like_and_announce_routes_require_authentication(): void
    {
        $author = $this->createFullAccount('autoreannounce4');
        $post = $this->publishPost($author);

        $this->post(route('posts.like', $post))->assertRedirect(route('login'));
        $this->post(route('posts.announce', $post))->assertRedirect(route('login'));
    }

    public function test_liking_a_post_via_json_returns_updated_state_without_redirect(): void
    {
        $author = $this->createFullAccount('jsonlikeauthor');
        $liker = $this->createFullAccount('jsonliker');
        $post = $this->publishPost($author);

        $response = $this->actingAs($liker)->postJson(route('posts.like', $post));

        $response->assertOk();
        $response->assertJson([
            'liked' => true,
            'likes_count' => 1,
        ]);
        $this->assertSame(1, $post->fresh()->likes_count);

        $unlike = $this->actingAs($liker)->deleteJson(route('posts.unlike', $post));
        $unlike->assertOk();
        $unlike->assertJson([
            'liked' => false,
            'likes_count' => 0,
        ]);
    }

    public function test_html_like_redirects_back_to_the_post_fragment(): void
    {
        $author = $this->createFullAccount('htmllikeauthor');
        $liker = $this->createFullAccount('htmlliker');
        $post = $this->publishPost($author);

        $response = $this->actingAs($liker)
            ->from(route('feed.index'))
            ->post(route('posts.like', $post));

        $response->assertRedirect();
        $this->assertStringContainsString('#post-'.$post->id, $response->headers->get('Location'));
    }

    public function test_post_cards_expose_an_anchor_and_ajax_like_markup(): void
    {
        $author = $this->createFullAccount('anchorlike');
        $post = $this->publishPost($author);

        $this->actingAs($author)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee('id="post-'.$post->id.'"', false)
            ->assertSee('data-like-form', false)
            ->assertSee('data-like-action="'.route('posts.like', $post).'"', false);
    }
}
