<?php

namespace Tests\Feature\Federation;

use App\Application\Services\CommentComposer;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class NoteContentNegotiationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function publishPost(User $author, string $body, string $visibility = Post::VISIBILITY_PUBLIC): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => $visibility,
        ]);
    }

    public function test_an_activity_json_request_receives_the_note_document(): void
    {
        $author = $this->createFullAccount('notaautore');
        $post = $this->publishPost($author, 'Un post pubblico con #hashtag e testo.');

        $response = $this->get(route('posts.show', $post), ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/activity+json; charset=utf-8');
        $response->assertJson([
            'id' => url("/posts/{$post->id}"),
            'type' => 'Note',
            'attributedTo' => $author->actor->uri,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ]);
        $this->assertStringContainsString('hashtag', $response->json('content'));
        $this->assertStringContainsString('rel="tag"', $response->json('content'));
        $this->assertStringContainsString('class="mention hashtag"', $response->json('content'));
        $this->assertStringNotContainsString('class="post-link"', $response->json('content'));
        $tags = collect($response->json('tag'));
        $this->assertTrue($tags->contains(fn ($tag) => $tag['type'] === 'Hashtag' && $tag['name'] === '#hashtag'));
    }

    public function test_a_titled_post_exposes_name_and_a_bold_content_fallback(): void
    {
        $author = $this->createFullAccount('titolista');
        $post = app(PostComposer::class)->compose($author->actor, [
            'title' => 'Un titolo',
            'body' => 'Il corpo del post.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->get(route('posts.show', $post), ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertJsonPath('name', 'Un titolo');
        $content = $response->json('content');
        $this->assertStringContainsString('<p><b>Un titolo</b></p>', $content);
        $this->assertStringContainsString('Il corpo del post.', $content);
    }

    public function test_a_deleted_post_is_represented_as_a_tombstone(): void
    {
        $author = $this->createFullAccount('cancellatore');
        $post = $this->publishPost($author, 'Post che verra eliminato.');

        $post->update(['status' => Post::STATUS_DELETED, 'body' => '']);

        $response = $this->get(route('posts.show', $post), ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertJson([
            'id' => url("/posts/{$post->id}"),
            'type' => 'Tombstone',
            'formerType' => 'Note',
        ]);
    }

    public function test_a_followers_only_post_is_not_exposed_anonymously_via_content_negotiation(): void
    {
        $author = $this->createFullAccount('privatoap');
        $post = $this->publishPost($author, 'Solo per i follower.', Post::VISIBILITY_FOLLOWERS);

        $this->get(route('posts.show', $post), ['Accept' => 'application/activity+json'])
            ->assertNotFound();
    }

    public function test_a_top_level_comment_replies_to_the_post(): void
    {
        $author = $this->createFullAccount('commentoautore');
        $post = $this->publishPost($author, 'Post con commenti.');

        $commenter = $this->createFullAccount('commentatore');
        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Bel post!');

        $response = $this->get(route('comments.show', $comment), ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertJson([
            'id' => url("/comments/{$comment->id}"),
            'type' => 'Note',
            'attributedTo' => $commenter->actor->uri,
            'inReplyTo' => url("/posts/{$post->id}"),
        ]);
    }

    public function test_a_reply_comment_replies_to_the_parent_comment(): void
    {
        $author = $this->createFullAccount('threadautore');
        $post = $this->publishPost($author, 'Post con thread.');

        $commenter = $this->createFullAccount('threadcommentatore');
        $parent = app(CommentComposer::class)->compose($commenter->actor, $post, 'Primo commento.');

        $replier = $this->createFullAccount('threadrisponditore');
        $reply = app(CommentComposer::class)->compose($replier->actor, $post, 'Risposta.', $parent);

        $response = $this->get(route('comments.show', $reply), ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertJsonPath('inReplyTo', url("/comments/{$parent->id}"));
    }

    public function test_a_browser_request_for_a_comment_redirects_to_the_post_permalink(): void
    {
        $author = $this->createFullAccount('redirectautore');
        $post = $this->publishPost($author, 'Post per il redirect.');

        $commenter = $this->createFullAccount('redirectcommentatore');
        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Commento.');

        $response = $this->get(route('comments.show', $comment));

        $response->assertRedirect(route('posts.show', $post).'#commento-'.$comment->id);
    }

    public function test_a_deleted_comment_is_represented_as_a_tombstone(): void
    {
        $author = $this->createFullAccount('commentocancellato');
        $post = $this->publishPost($author, 'Post con commento cancellato.');

        $commenter = $this->createFullAccount('cancellacommento');
        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Commento da eliminare.');
        $comment->update(['status' => Comment::STATUS_DELETED, 'body' => '']);

        $response = $this->get(route('comments.show', $comment), ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertJson([
            'type' => 'Tombstone',
            'formerType' => 'Note',
        ]);
    }
}
