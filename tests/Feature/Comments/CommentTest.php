<?php

namespace Tests\Feature\Comments;

use App\Application\Services\CommentComposer;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Policies\CommentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function publishPost(User $author, string $body = 'Un post di prova.'): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
    }

    public function test_a_top_level_comment_increments_the_post_counter_and_notifies_the_author(): void
    {
        $author = $this->createFullAccount('autorepost');
        $commenter = $this->createFullAccount('commentatore');
        $post = $this->publishPost($author);

        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Bellissimo post!');

        $post->refresh();
        $this->assertSame(1, $post->comments_count);
        $this->assertNull($comment->parent_comment_id);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $author->id,
            'actor_id' => $commenter->actor->id,
            'type' => Notification::TYPE_COMMENT,
        ]);
    }

    public function test_a_reply_increments_the_parent_comment_counter_and_notifies_its_author(): void
    {
        $author = $this->createFullAccount('autorepost2');
        $commenter = $this->createFullAccount('commentatore2');
        $replier = $this->createFullAccount('risponditore');
        $post = $this->publishPost($author);

        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Primo commento.');
        $reply = app(CommentComposer::class)->compose($replier->actor, $post, 'Una risposta.', $comment);

        $comment->refresh();
        $post->refresh();

        $this->assertSame(1, $comment->replies_count);
        $this->assertSame(2, $post->comments_count);
        $this->assertSame($comment->id, $reply->parent_comment_id);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $commenter->id,
            'actor_id' => $replier->actor->id,
            'type' => Notification::TYPE_REPLY,
        ]);
    }

    public function test_a_comment_cannot_be_created_with_a_parent_from_a_different_post(): void
    {
        $author = $this->createFullAccount('autorepost3');
        $commenter = $this->createFullAccount('commentatore3');
        $postA = $this->publishPost($author, 'Post A');
        $postB = $this->publishPost($author, 'Post B');

        $commentOnA = app(CommentComposer::class)->compose($commenter->actor, $postA, 'Commento su A');

        $this->expectException(\InvalidArgumentException::class);
        app(CommentComposer::class)->compose($commenter->actor, $postB, 'Non valido', $commentOnA);
    }

    public function test_only_the_author_can_delete_their_comment(): void
    {
        $author = $this->createFullAccount('autorepost4');
        $commenter = $this->createFullAccount('commentatore4');
        $post = $this->publishPost($author);

        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Da eliminare');

        $this->actingAs($author)->delete(route('comments.destroy', $comment))->assertForbidden();

        $this->actingAs($commenter)->delete(route('comments.destroy', $comment))->assertRedirect();

        $comment->refresh();
        $this->assertSame(Comment::STATUS_DELETED, $comment->status);
    }

    public function test_a_comment_can_be_posted_through_the_http_endpoint(): void
    {
        $author = $this->createFullAccount('autorepost5');
        $commenter = $this->createFullAccount('commentatore5');
        $post = $this->publishPost($author);

        $response = $this->actingAs($commenter)->post(route('comments.store', $post), [
            'body' => 'Commento via HTTP.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('comments', [
            'post_id' => $post->id,
            'actor_id' => $commenter->actor->id,
            'body' => 'Commento via HTTP.',
        ]);
    }

    public function test_comment_actions_are_icon_only_and_delete_lives_in_the_overflow_menu(): void
    {
        $author = $this->createFullAccount('autoreicone');
        $commenter = $this->createFullAccount('commentatoreicone');
        $post = $this->publishPost($author);
        app(CommentComposer::class)->compose($commenter->actor, $post, 'Commento con menu.');

        $response = $this->actingAs($commenter)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('class="ob-post__action"', false);
        $response->assertDontSee('>Mi piace (', false);
        $response->assertSee('aria-label="Rispondi"', false);
        $response->assertSee('aria-label="Altre azioni sul commento"', false);
        $response->assertSee('class="ob-post__menu-item"', false);
        $response->assertSee('Elimina', false);

        $html = $response->getContent();
        $commentActionsPos = strpos($html, 'ob-comment');
        $this->assertNotFalse($commentActionsPos);
        $slice = substr($html, $commentActionsPos, 2500);
        $actionsPos = strpos($slice, 'ob-post__actions');
        $this->assertNotFalse($actionsPos);
        $actionsSlice = substr($slice, $actionsPos, 600);
        $this->assertFalse(
            str_contains($actionsSlice, 'Elimina'),
            'delete must not appear among the inline comment action buttons'
        );
        $this->assertFalse(
            str_contains($actionsSlice, '>Rispondi'),
            'reply must be icon-only among the inline comment action buttons'
        );
    }

    public function test_a_remote_comment_cannot_be_deleted(): void
    {
        $admin = $this->createFullAccount('admincommento', ['is_admin' => true]);
        $author = $this->createFullAccount('autorepostremoto');
        $post = $this->publishPost($author);
        $remote = $this->createRemoteActor('remotecmtr');

        $comment = Comment::query()->create([
            'post_id' => $post->id,
            'actor_id' => $remote->id,
            'uri' => 'https://remoto.example/users/remotecmtr/statuses/7',
            'body' => 'Commento remoto in cache.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->assertFalse((new CommentPolicy)->delete($admin, $comment));
        $this->actingAs($admin)->delete(route('comments.destroy', $comment))->assertForbidden();

        $response = $this->actingAs($admin)->get(route('posts.show', $post));
        $response->assertOk();
        $response->assertDontSee('aria-label="Altre azioni sul commento"', false);
    }
}
