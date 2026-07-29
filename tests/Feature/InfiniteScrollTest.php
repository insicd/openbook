<?php

namespace Tests\Feature;

use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

/**
 * La paginazione a numeri di pagina e' stata sostituita da uno scorrimento
 * infinito lato client (vedi "public/assets/js/infinite-scroll.js"): il
 * server continua pero' a esporre "?page=N" esattamente come prima (usato
 * sia dal fetch() dello script sia, dentro <noscript>, da chi naviga senza
 * JavaScript), quindi cio' che verifichiamo qui e' il markup che lo script
 * si aspetta di trovare, non un comportamento lato client.
 */
class InfiniteScrollTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function publishPost(User $author, string $body): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
    }

    public function test_the_feed_exposes_a_next_page_url_for_the_infinite_scroll_script_when_there_are_more_posts(): void
    {
        config(['openbook.feed.per_page' => 2]);

        $user = $this->createFullAccount('infinitescroll');
        $this->publishPost($user, 'Primo post.');
        $this->publishPost($user, 'Secondo post.');
        $this->publishPost($user, 'Terzo post.');

        $response = $this->actingAs($user)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('id="ob-post-list"', false);
        $response->assertSee('data-infinite-scroll', false);
        $response->assertSee('data-next-url="'.route('feed.index', ['page' => 2]).'"', false);

        // La paginazione classica resta disponibile per chi naviga senza JavaScript.
        $response->assertSee('<noscript>', false);
        $response->assertSee('ob-pagination', false);
    }

    public function test_the_feed_has_no_next_page_url_when_every_post_fits_on_one_page(): void
    {
        $user = $this->createFullAccount('infinitescrollone');
        $this->publishPost($user, 'Unico post.');

        $response = $this->actingAs($user)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('data-infinite-scroll', false);
        $response->assertDontSee('data-next-url', false);
        $response->assertDontSee('<noscript>', false);
    }

    public function test_fetching_the_next_page_url_returns_the_remaining_posts_inside_the_same_container(): void
    {
        config(['openbook.feed.per_page' => 2]);

        $user = $this->createFullAccount('infinitescrollpage');
        $this->publishPost($user, 'Post numero uno.');
        $this->publishPost($user, 'Post numero due.');
        $this->publishPost($user, 'Post numero tre.');

        $response = $this->actingAs($user)->get(route('feed.index', ['page' => 2]));

        $response->assertOk();
        $response->assertSee('id="ob-post-list"', false);
        $response->assertSee('Post numero uno.');
        $response->assertDontSee('data-next-url', false);
    }

    public function test_a_hashtag_page_for_an_unknown_tag_still_renders_the_infinite_scroll_container_without_errors(): void
    {
        $response = $this->get(route('hashtags.show', 'inesistente'));

        $response->assertOk();
        $response->assertSee('id="ob-post-list"', false);
        $response->assertDontSee('data-next-url', false);
    }
}
