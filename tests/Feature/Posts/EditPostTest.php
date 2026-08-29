<?php

namespace Tests\Feature\Posts;

use App\Application\Services\MessageComposer;
use App\Application\Services\PostComposer;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Policies\PostPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class EditPostTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_the_author_can_open_the_edit_page(): void
    {
        $author = $this->createFullAccount('autoremodifica');
        $post = $this->publish($author, 'Testo originale da modificare.');

        $this->actingAs($author)
            ->get(route('posts.edit', $post))
            ->assertOk()
            ->assertSee('Testo originale da modificare.', false)
            ->assertSee(__('openbook.posts.edit_title'), false)
            ->assertSee(__('openbook.composer.save'), false)
            ->assertSee('name="_method"', false);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $author = $this->createFullAccount('ospitemodifica');
        $post = $this->publish($author, 'Post visibile.');

        $this->get(route('posts.edit', $post))->assertRedirect(route('login'));
        $this->put(route('posts.update', $post), [
            'body' => 'Non dovrebbe passare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ])->assertRedirect(route('login'));
    }

    public function test_another_user_cannot_edit_the_post(): void
    {
        $author = $this->createFullAccount('proprietariomod');
        $stranger = $this->createFullAccount('estraneomod');
        $admin = $this->createFullAccount('adminmod', ['is_admin' => true]);
        $post = $this->publish($author, 'Solo il proprietario modifica.');

        $this->assertFalse((new PostPolicy)->update($stranger, $post));
        $this->assertFalse((new PostPolicy)->update($admin, $post));

        $this->actingAs($stranger)->get(route('posts.edit', $post))->assertForbidden();
        $this->actingAs($admin)->get(route('posts.edit', $post))->assertForbidden();
        $this->actingAs($stranger)->put(route('posts.update', $post), [
            'body' => 'Tentativo altrui.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ])->assertForbidden();
    }

    public function test_a_remote_post_cannot_be_edited(): void
    {
        $viewer = $this->createFullAccount('vieweredit');
        $remote = $this->createRemoteActor('aliceedit');
        $post = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => 'https://remoto.example/users/aliceedit/statuses/7',
            'body' => 'Nota remota.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->actingAs($viewer)->get(route('posts.edit', $post))->assertForbidden();
    }

    public function test_a_direct_message_cannot_be_edited(): void
    {
        $alice = $this->createFullAccount('alicemodifica');
        $bob = $this->createFullAccount('bobmodifica');
        $message = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Ciao in privato.');

        $this->assertTrue($message->isDirectMessage());
        $this->assertFalse((new PostPolicy)->update($alice, $message));

        $this->actingAs($alice)->get(route('posts.edit', $message))->assertForbidden();
    }

    public function test_the_author_can_update_fields_and_marks_the_post_as_edited(): void
    {
        $author = $this->createFullAccount('salvataggio');
        $post = app(PostComposer::class)->compose($author->actor, [
            'title' => 'Titolo vecchio',
            'content_warning' => 'avviso vecchio',
            'body' => 'Corpo vecchio.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->assertNull($post->edited_at);

        $response = $this->actingAs($author)->put(route('posts.update', $post), [
            'title' => 'Titolo nuovo',
            'content_warning' => 'avviso nuovo',
            'body' => 'Corpo aggiornato.',
            'visibility' => Post::VISIBILITY_UNLISTED,
        ]);

        $response->assertRedirect(route('posts.show', $post));
        $response->assertSessionHas('status', __('openbook.posts.updated'));

        $post->refresh();
        $this->assertSame('Titolo nuovo', $post->title);
        $this->assertSame('avviso nuovo', $post->content_warning);
        $this->assertSame('Corpo aggiornato.', $post->body);
        $this->assertSame(Post::VISIBILITY_UNLISTED, $post->visibility);
        $this->assertNotNull($post->edited_at);
        $this->assertTrue($post->wasEdited());
    }

    public function test_hashtags_are_resynced_on_update(): void
    {
        $author = $this->createFullAccount('tagmodifica');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Parliamo di #sole e #mare',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->assertSame(['mare', 'sole'], $post->hashtags()->pluck('name')->sort()->values()->all());

        $this->actingAs($author)->put(route('posts.update', $post), [
            'body' => 'Adesso solo #vento',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ])->assertRedirect(route('posts.show', $post));

        $this->assertSame(['vento'], $post->fresh()->hashtags()->pluck('name')->all());
    }

    public function test_a_new_local_mention_is_notified_once(): void
    {
        $author = $this->createFullAccount('menzionante');
        $mentioned = $this->createFullAccount('menzionato');
        $post = $this->publish($author, 'Nessuna menzione all inizio.');

        $this->actingAs($author)->put(route('posts.update', $post), [
            'body' => 'Ciao @menzionato, guarda qui.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ])->assertRedirect();

        $this->assertDatabaseHas('mentions', [
            'mentionable_id' => $post->id,
            'actor_id' => $mentioned->actor->id,
        ]);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $mentioned->id,
            'type' => Notification::TYPE_MENTION,
            'actor_id' => $author->actor->id,
        ]);

        $this->actingAs($author)->put(route('posts.update', $post), [
            'body' => 'Ancora tu @menzionato, seconda modifica.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ])->assertRedirect();

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_validation_errors_return_to_the_edit_page(): void
    {
        $author = $this->createFullAccount('validazionemod');
        $post = $this->publish($author, 'Testo valido.');

        $this->actingAs($author)
            ->from(route('feed.index'))
            ->put(route('posts.update', $post), [
                'body' => '',
                'visibility' => Post::VISIBILITY_PUBLIC,
            ])
            ->assertRedirect(route('posts.edit', $post))
            ->assertSessionHasErrors('body');
    }

    public function test_the_overflow_menu_includes_edit_markup_for_own_posts(): void
    {
        $author = $this->createFullAccount('menumodifica');
        $post = $this->publish($author, 'Post con voce modifica.');

        $this->actingAs($author)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertSee('data-edit-post', false)
            ->assertSee('data-edit-action="'.route('posts.update', $post).'"', false)
            ->assertSee(__('openbook.actions.edit'), false)
            ->assertSee('href="'.route('posts.edit', $post).'"', false);
    }

    private function publish($author, string $body): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
    }
}
