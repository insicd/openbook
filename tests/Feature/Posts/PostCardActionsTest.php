<?php

namespace Tests\Feature\Posts;

use App\Application\Services\PostComposer;
use App\Domain\Posts\Post;
use App\Policies\PostPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Dettaglio UI della card post: link all'originale remoto sull'orario,
 * azioni ridotte a sole icone (con contatore), eliminazione solo per i
 * propri post locali e nascosta dentro il menu a tre puntini.
 */
class PostCardActionsTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_remote_post_timestamp_links_to_the_original_uri_in_a_new_tab(): void
    {
        $viewer = $this->createFullAccount('viewerremoto');
        $remote = $this->createRemoteActor('alice');
        $uri = 'https://remoto.example/users/alice/statuses/42';

        $post = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => $uri,
            'body' => 'Post remoto in cache.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('href="'.$uri.'"', false);
        $response->assertSee('target="_blank"', false);
        $response->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_a_local_post_timestamp_still_links_to_the_local_post_page(): void
    {
        $author = $this->createFullAccount('localelink');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post locale.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($author)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('href="'.route('posts.show', $post).'"', false);
        $response->assertDontSee('target="_blank"', false);
    }

    public function test_action_buttons_are_icon_only_without_word_labels(): void
    {
        $author = $this->createFullAccount('iconeazioni');
        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post con azioni iconiche.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($author)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('class="ob-post__action"', false);
        $response->assertDontSee('>Mi piace (', false);
        $response->assertDontSee('>Commenta (', false);
        $response->assertDontSee('>Condividi (', false);
        // I testi restano disponibili come aria-label per l'accessibilita'.
        $response->assertSee('aria-label="Mi piace (0)"', false);
    }

    public function test_the_delete_control_lives_in_the_overflow_menu_for_own_local_posts_only(): void
    {
        $author = $this->createFullAccount('proprietariomenu');
        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Il mio post eliminabile.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($author)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('class="ob-post__menu"', false);
        $response->assertSee('aria-label="Altre azioni sul post"', false);
        $response->assertSee('class="ob-post__menu-item"', false);
        $response->assertSee('Elimina', false);
        // Non deve piu' comparire come bottone in linea fra like/comment/share.
        $html = $response->getContent();
        $actionsPos = strpos($html, 'ob-post__actions');
        $deleteInActions = $actionsPos !== false && str_contains(substr($html, $actionsPos, 800), 'Elimina');
        $this->assertFalse($deleteInActions, 'delete must not appear among the inline action buttons');
    }

    public function test_a_remote_post_cannot_be_deleted_even_by_an_admin(): void
    {
        $admin = $this->createFullAccount('adminremoto', ['is_admin' => true]);
        $remote = $this->createRemoteActor('bob');
        $post = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => 'https://remoto.example/users/bob/statuses/9',
            'body' => 'Non eliminabile.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->assertFalse((new PostPolicy)->delete($admin, $post));

        $this->actingAs($admin)->delete(route('posts.destroy', $post))->assertForbidden();

        $response = $this->actingAs($admin)->get(route('posts.show', $post));
        $response->assertOk();
        $response->assertDontSee('class="ob-post__menu"', false);
        $response->assertDontSee('class="ob-post__menu-item"', false);
    }
}
