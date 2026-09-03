<?php

namespace Tests\Feature\Comments;

use App\Application\Services\CommentComposer;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Federation\Serialization\NoteSerializer;
use App\Policies\CommentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->assertSame(0, $post->fresh()->comments_count);

        $response = $this->actingAs($author)->get(route('posts.show', $post));
        $response->assertOk();
        $response->assertDontSee(__('openbook.comments.deleted'), false);
        $response->assertDontSee('Da eliminare', false);
        $response->assertDontSee('id="commento-'.$comment->id.'"', false);
    }

    public function test_deleting_a_reply_decrements_parent_replies_count(): void
    {
        $author = $this->createFullAccount('autorecontatori');
        $commenter = $this->createFullAccount('commentatorecontatori');
        $post = $this->publishPost($author);

        $parent = app(CommentComposer::class)->compose($commenter->actor, $post, 'Padre');
        $reply = app(CommentComposer::class)->compose($commenter->actor, $post, 'Figlio', $parent);

        $this->assertSame(2, $post->fresh()->comments_count);
        $this->assertSame(1, $parent->fresh()->replies_count);

        $this->actingAs($commenter)->delete(route('comments.destroy', $reply))->assertRedirect();

        $this->assertSame(1, $post->fresh()->comments_count);
        $this->assertSame(0, $parent->fresh()->replies_count);
    }

    public function test_replies_to_a_deleted_comment_remain_visible_without_tombstone(): void
    {
        $author = $this->createFullAccount('autorepadre');
        $commenter = $this->createFullAccount('padreeliminato');
        $replier = $this->createFullAccount('figliovisibile');
        $post = $this->publishPost($author);

        $parent = app(CommentComposer::class)->compose($commenter->actor, $post, 'Padre che sparisce.');
        app(CommentComposer::class)->compose($replier->actor, $post, 'Figlio che resta.', $parent);

        $this->actingAs($commenter)->delete(route('comments.destroy', $parent))->assertRedirect();

        $response = $this->actingAs($author)->get(route('posts.show', $post));
        $response->assertOk();
        $response->assertDontSee(__('openbook.comments.deleted'), false);
        $response->assertDontSee('Padre che sparisce.', false);
        $response->assertSee('Figlio che resta.', false);

        $this->actingAs($author)
            ->get(route('comments.show', $parent))
            ->assertRedirect(route('posts.show', $post).'#commenti');
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
            'parent_comment_id' => null,
        ]);
    }

    public function test_a_comment_can_include_image_attachments(): void
    {
        Storage::fake('public');

        $author = $this->createFullAccount('autorefoto');
        $commenter = $this->createFullAccount('commentofoto');
        $post = $this->publishPost($author);

        $comment = app(CommentComposer::class)->compose(
            $commenter->actor,
            $post,
            'Guarda questa.',
            null,
            [UploadedFile::fake()->image('reply.jpg', 800, 600)],
            ['Foto in risposta'],
        );

        $this->assertCount(1, $comment->media);
        $media = $comment->media()->first();
        $this->assertSame('Foto in risposta', $media->alt_text);
        Storage::disk('public')->assertExists($media->path);

        $note = NoteSerializer::forComment($comment->fresh(['media', 'actor.endpoints', 'post', 'mentions']));
        $this->assertSame('Image', $note['attachment'][0]['type']);
        $this->assertSame('Foto in risposta', $note['attachment'][0]['name']);
    }

    public function test_a_comment_can_include_audio_attachments(): void
    {
        Storage::fake('public');

        $author = $this->createFullAccount('autoreaudio');
        $commenter = $this->createFullAccount('commentoaudio');
        $post = $this->publishPost($author);

        $comment = app(CommentComposer::class)->compose(
            $commenter->actor,
            $post,
            'Ascolta.',
            null,
            [UploadedFile::fake()->create('reply.mp3', 50, 'audio/mpeg')],
            ['Clip audio'],
        );

        $this->assertCount(1, $comment->media);
        $this->assertTrue($comment->media()->first()->isAudio());

        $note = NoteSerializer::forComment($comment->fresh(['media', 'actor.endpoints', 'post', 'mentions']));
        $this->assertSame('Document', $note['attachment'][0]['type']);
        $this->assertSame('audio/mpeg', $note['attachment'][0]['mediaType']);
        $this->assertSame('Clip audio', $note['attachment'][0]['name']);
    }

    public function test_a_comment_with_image_can_be_posted_through_http(): void
    {
        Storage::fake('public');

        $author = $this->createFullAccount('autorehttpfoto');
        $commenter = $this->createFullAccount('httpfoto');
        $post = $this->publishPost($author);

        $this->actingAs($commenter)->post(route('comments.store', $post), [
            'body' => 'Con allegato.',
            'images' => [UploadedFile::fake()->image('c.jpg')],
            'alt_texts' => ['Alt commento'],
        ])->assertRedirect();

        $comment = Comment::query()->where('body', 'Con allegato.')->firstOrFail();
        $this->assertCount(1, $comment->media);

        $this->actingAs($author)
            ->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('data-lightbox-trigger', false)
            ->assertSee('Alt commento', false);
    }

    public function test_http_rejects_invalid_comment_image_mime(): void
    {
        Storage::fake('public');

        $author = $this->createFullAccount('autorepdf');
        $commenter = $this->createFullAccount('commentopdf');
        $post = $this->publishPost($author);

        $this->actingAs($commenter)->post(route('comments.store', $post), [
            'body' => 'PDF non ammesso.',
            'images' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
        ])->assertSessionHasErrors('images.0');

        $this->assertDatabaseMissing('comments', ['body' => 'PDF non ammesso.']);
    }

    public function test_two_top_level_comments_stay_siblings_not_nested(): void
    {
        $author = $this->createFullAccount('autoreannida');
        $first = $this->createFullAccount('primocommento');
        $second = $this->createFullAccount('secondocommento');
        $post = $this->publishPost($author);

        $this->actingAs($first)->post(route('comments.store', $post), [
            'body' => 'Primo top level.',
        ])->assertRedirect();

        $this->actingAs($second)->post(route('comments.store', $post), [
            'body' => 'Secondo top level.',
        ])->assertRedirect();

        $comments = Comment::query()
            ->where('post_id', $post->id)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $comments);
        $this->assertNull($comments[0]->parent_comment_id);
        $this->assertNull($comments[1]->parent_comment_id);

        $html = $this->actingAs($author)->get(route('posts.show', $post))->assertOk()->getContent();

        $firstPos = strpos($html, 'Primo top level.');
        $secondPos = strpos($html, 'Secondo top level.');
        $this->assertNotFalse($firstPos);
        $this->assertNotFalse($secondPos);
        $this->assertLessThan($secondPos, $firstPos);

        // Il secondo commento non deve finire dentro il blocco replies del primo.
        $repliesAfterFirst = strpos($html, 'ob-comment__replies', $firstPos);
        $this->assertTrue(
            $repliesAfterFirst === false || $repliesAfterFirst > $secondPos,
            'Il secondo commento di primo livello non deve essere annidato sotto il primo.'
        );
    }

    public function test_a_reply_posted_through_http_keeps_the_parent_link(): void
    {
        $author = $this->createFullAccount('autorerisposta');
        $commenter = $this->createFullAccount('padrethread');
        $replier = $this->createFullAccount('figliothread');
        $post = $this->publishPost($author);

        $parent = app(CommentComposer::class)->compose($commenter->actor, $post, 'Commento padre.');

        $this->actingAs($replier)->post(route('comments.store', $post), [
            'body' => 'Risposta annidata.',
            'parent_comment_id' => $parent->id,
        ])->assertRedirect();

        $reply = Comment::query()->where('body', 'Risposta annidata.')->firstOrFail();
        $this->assertSame($parent->id, $reply->parent_comment_id);

        $html = $this->actingAs($author)->get(route('posts.show', $post))->assertOk()->getContent();
        $parentPos = strpos($html, 'Commento padre.');
        $repliesPos = strpos($html, 'ob-comment__replies', $parentPos);
        $replyPos = strpos($html, 'Risposta annidata.');

        $this->assertNotFalse($repliesPos);
        $this->assertGreaterThan($repliesPos, $replyPos);
    }

    public function test_comment_actions_are_icon_only_and_delete_lives_in_the_overflow_menu(): void
    {
        $author = $this->createFullAccount('autoreicone');
        $commenter = $this->createFullAccount('commentatoreicone');
        $post = $this->publishPost($author);
        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Commento con menu.');

        $response = $this->actingAs($commenter)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('class="ob-post__action"', false);
        $response->assertDontSee('>Mi piace (', false);
        $response->assertSee('aria-label="Rispondi"', false);
        $response->assertSee('aria-label="Altre azioni sul commento"', false);
        $response->assertSee('class="ob-post__menu-item"', false);
        $response->assertSee('Elimina', false);

        $html = $response->getContent();
        $commentActionsPos = strpos($html, 'id="commento-'.$comment->id.'"');
        $this->assertNotFalse($commentActionsPos);
        $slice = substr($html, $commentActionsPos, 4000);
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
        $response->assertSee('aria-label="Altre azioni sul commento"', false);
        $response->assertSee(__('openbook.actions.report'), false);
        $response->assertSee(route('comments.report.create', $comment), false);
        $this->assertStringNotContainsString(
            'method="POST" action="'.route('comments.destroy', $comment).'"',
            $response->getContent(),
        );
    }

    public function test_deep_replies_stay_at_one_indent_with_parent_link(): void
    {
        $author = $this->createFullAccount('autoreannidaprofondo');
        $alice = $this->createFullAccount('aliceannida');
        $bruno = $this->createFullAccount('brunoannida');
        $carla = $this->createFullAccount('carlaannida');
        $post = $this->publishPost($author);

        $root = app(CommentComposer::class)->compose($alice->actor, $post, 'Commento radice.');
        $child = app(CommentComposer::class)->compose($bruno->actor, $post, 'Risposta di primo livello.', $root);
        app(CommentComposer::class)->compose($carla->actor, $post, 'Risposta di secondo livello.', $child);

        $html = $this->actingAs($author)->get(route('posts.show', $post))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'ob-comment__replies'));
        $this->assertStringContainsString('Risposta di secondo livello.', $html);
        $this->assertStringContainsString(
            __('openbook.comments.in_reply_to', ['name' => $bruno->actor->displayName()]),
            $html,
        );

        $repliesPos = strpos($html, 'ob-comment__replies');
        $deepPos = strpos($html, 'Risposta di secondo livello.');
        $this->assertNotFalse($repliesPos);
        $this->assertGreaterThan($repliesPos, $deepPos);
    }

    public function test_comment_permalink_shows_ancestors_and_replies(): void
    {
        $author = $this->createFullAccount('autorepermalink');
        $alice = $this->createFullAccount('alicepermalink');
        $bruno = $this->createFullAccount('brunopermalink');
        $carla = $this->createFullAccount('carlapermalink');
        $post = $this->publishPost($author, 'Post del thread.');

        $root = app(CommentComposer::class)->compose($alice->actor, $post, 'Padre del permalink.');
        $child = app(CommentComposer::class)->compose($bruno->actor, $post, 'Commento in focus.', $root);
        app(CommentComposer::class)->compose($carla->actor, $post, 'Nipote visibile.', $child);

        $response = $this->actingAs($author)->get(route('comments.show', $child));

        $response->assertOk();
        $response->assertSee('Post del thread.', false);
        $response->assertSee('Padre del permalink.', false);
        $response->assertSee('Commento in focus.', false);
        $response->assertSee('Nipote visibile.', false);
        $response->assertSee('ob-comment--focused', false);
        $response->assertSee(route('comments.show', $child), false);
    }

    public function test_a_comment_federates_an_implicit_mention_without_showing_it_locally(): void
    {
        $author = $this->createFullAccount('autoreimplicito');
        $commenter = $this->createFullAccount('commentoimplicito');
        $post = $this->publishPost($author, 'Post da commentare senza chiocciola.');

        $comment = app(CommentComposer::class)->compose($commenter->actor, $post, 'Bellissimo, senza menzione.');

        $this->assertDatabaseMissing('mentions', [
            'mentionable_id' => $comment->id,
            'mentionable_type' => $comment->getMorphClass(),
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $author->id,
            'type' => Notification::TYPE_COMMENT,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => Notification::TYPE_MENTION,
        ]);

        $localHtml = (string) \App\Domain\Posts\PostBodyRenderer::render($comment->body);
        $this->assertStringContainsString('Bellissimo, senza menzione.', $localHtml);
        $this->assertStringNotContainsString('@'.$author->actor->handle(), $localHtml);

        $this->actingAs($author)
            ->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('Bellissimo, senza menzione.', false);

        $note = NoteSerializer::forComment($comment->fresh());
        $authorId = $author->actor->activityPubId();
        $this->assertTrue(collect($note['tag'] ?? [])->contains(
            fn (array $tag): bool => ($tag['type'] ?? null) === 'Mention' && ($tag['href'] ?? null) === $authorId
        ));
        $this->assertStringContainsString($authorId, $note['content']);
        $this->assertStringContainsString('@'.$author->actor->handle(), $note['content']);
        $this->assertContains($authorId, $note['cc'] ?? []);
    }

    public function test_a_nested_reply_federates_an_implicit_mention_of_the_parent_author(): void
    {
        $author = $this->createFullAccount('opimplicito');
        $commenter = $this->createFullAccount('padreimplicito');
        $replier = $this->createFullAccount('figlioimplicito');
        $post = $this->publishPost($author);
        $parent = app(CommentComposer::class)->compose($commenter->actor, $post, 'Primo commento.');
        $reply = app(CommentComposer::class)->compose($replier->actor, $post, 'Risposta senza chiocciola.', $parent);

        $this->assertDatabaseMissing('notifications', [
            'type' => Notification::TYPE_MENTION,
        ]);

        $note = NoteSerializer::forComment($reply->fresh());
        $parentAuthorId = $commenter->actor->activityPubId();
        $opId = $author->actor->activityPubId();

        $this->assertTrue(collect($note['tag'] ?? [])->contains(
            fn (array $tag): bool => ($tag['type'] ?? null) === 'Mention' && ($tag['href'] ?? null) === $parentAuthorId
        ));
        $this->assertFalse(collect($note['tag'] ?? [])->contains(
            fn (array $tag): bool => ($tag['type'] ?? null) === 'Mention' && ($tag['href'] ?? null) === $opId
        ));
        $this->assertStringContainsString($parentAuthorId, $note['content']);
        $this->assertStringNotContainsString($opId, $note['content']);
    }

    public function test_a_comment_on_own_post_does_not_federate_a_self_mention(): void
    {
        $author = $this->createFullAccount('autocommento');
        $post = $this->publishPost($author);
        $comment = app(CommentComposer::class)->compose($author->actor, $post, 'Aggiungo io.');

        $note = NoteSerializer::forComment($comment->fresh());
        $this->assertSame([], $note['tag'] ?? []);
        $this->assertStringNotContainsString('@'.$author->actor->handle(), $note['content']);
    }

    public function test_an_explicit_mention_of_the_replied_author_is_not_duplicated_in_federated_html(): void
    {
        $author = $this->createFullAccount('autoreesplicito');
        $commenter = $this->createFullAccount('commentoesplicito');
        $post = $this->publishPost($author);
        $comment = app(CommentComposer::class)->compose(
            $commenter->actor,
            $post,
            'Ciao @autoreesplicito, bel post.',
        );

        $note = NoteSerializer::forComment($comment->fresh());
        $authorId = $author->actor->activityPubId();
        $this->assertSame(1, substr_count($note['content'], $authorId));
        $this->assertTrue(collect($note['tag'] ?? [])->contains(
            fn (array $tag): bool => ($tag['type'] ?? null) === 'Mention' && ($tag['href'] ?? null) === $authorId
        ));
        $this->assertDatabaseHas('mentions', [
            'mentionable_id' => $comment->id,
            'actor_id' => $author->actor->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $author->id,
            'type' => Notification::TYPE_COMMENT,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $author->id,
            'type' => Notification::TYPE_MENTION,
        ]);
    }

    public function test_mentioning_someone_else_in_a_comment_still_notifies_them(): void
    {
        $author = $this->createFullAccount('autoreterzo');
        $commenter = $this->createFullAccount('commentoterzo');
        $mentioned = $this->createFullAccount('citatoterzo');
        $post = $this->publishPost($author);

        app(CommentComposer::class)->compose(
            $commenter->actor,
            $post,
            'Guarda @citatoterzo, che post.',
        );

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $author->id,
            'type' => Notification::TYPE_COMMENT,
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $mentioned->id,
            'type' => Notification::TYPE_MENTION,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $mentioned->id,
            'type' => Notification::TYPE_COMMENT,
        ]);
    }

    public function test_replying_to_a_comment_while_mentioning_its_author_does_not_double_notify(): void
    {
        $author = $this->createFullAccount('opnestnotif');
        $commenter = $this->createFullAccount('padrenestnotif');
        $replier = $this->createFullAccount('figlionestnotif');
        $post = $this->publishPost($author);
        $parent = app(CommentComposer::class)->compose($commenter->actor, $post, 'Primo.');

        app(CommentComposer::class)->compose(
            $replier->actor,
            $post,
            'Ciao @padrenestnotif, concordo.',
            $parent,
        );

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $commenter->id,
            'type' => Notification::TYPE_REPLY,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $commenter->id,
            'type' => Notification::TYPE_MENTION,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $author->id,
            'type' => Notification::TYPE_MENTION,
        ]);
    }
}
