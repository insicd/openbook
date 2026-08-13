<?php

namespace Tests\Feature;

use App\Application\Queries\FeedCursor;
use App\Application\Queries\FeedQuery;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

/**
 * Lo scorrimento infinito lato client (vedi "public/assets/js/infinite-scroll.js")
 * scarica la pagina successiva indicata da "data-next-url". La paginazione dei
 * feed usa un cursore (?cursor=...) ancorato all'ultimo post mostrato, cosi' i
 * post pubblicati mentre si scrolla non spostano l'OFFSET e non creano duplicati.
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

    public function test_the_feed_exposes_a_cursor_based_next_url_when_there_are_more_posts(): void
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
        $response->assertSee('data-next-url="', false);
        $response->assertSee('cursor=', false);
        $response->assertDontSee('page=2', false);

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

    public function test_fetching_the_next_cursor_url_returns_the_remaining_posts_inside_the_same_container(): void
    {
        config(['openbook.feed.per_page' => 2]);

        $user = $this->createFullAccount('infinitescrollpage');
        $this->publishPost($user, 'Post numero uno.');
        $this->publishPost($user, 'Post numero due.');
        $this->publishPost($user, 'Post numero tre.');

        $firstPage = $this->actingAs($user)->get(route('feed.index'));
        $firstPage->assertOk();

        preg_match('/data-next-url="([^"]+)"/', $firstPage->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $nextUrl = html_entity_decode($matches[1], ENT_QUOTES);

        $response = $this->actingAs($user)->get($nextUrl);

        $response->assertOk();
        $response->assertSee('id="ob-post-list"', false);
        $response->assertSee('Post numero uno.');
        $response->assertDontSee('Post numero due.');
        $response->assertDontSee('Post numero tre.');
        $response->assertDontSee('data-next-url', false);
    }

    public function test_a_new_post_published_while_scrolling_does_not_duplicate_items_from_the_previous_page(): void
    {
        config(['openbook.feed.per_page' => 2]);

        $user = $this->createFullAccount('infinitescrolldup');
        $oldest = $this->publishPost($user, 'Post piu vecchio.');
        $middle = $this->publishPost($user, 'Post centrale.');
        $newest = $this->publishPost($user, 'Post piu recente.');

        $feedQuery = app(FeedQuery::class);
        $firstPage = $feedQuery->forActor($user->actor);
        $firstIds = $firstPage->getCollection()->pluck('id')->all();

        $this->assertCount(2, $firstIds);
        $this->assertContains($newest->id, $firstIds);
        $this->assertContains($middle->id, $firstIds);

        $cursor = FeedCursor::fromPost($firstPage->getCollection()->last(), useShareSort: true);

        $this->publishPost($user, 'Post arrivato mentre scrollo.');

        $secondPage = $feedQuery->forActor($user->actor, $cursor);
        $secondIds = $secondPage->getCollection()->pluck('id')->all();

        $this->assertSame([$oldest->id], $secondIds);
        $this->assertNotContains($middle->id, $secondIds);
        $this->assertNotContains($newest->id, $secondIds);
    }

    public function test_the_home_feed_cursor_query_does_not_put_shared_at_alias_in_where(): void
    {
        config(['openbook.feed.per_page' => 2]);

        $user = $this->createFullAccount('infinitescrollsql');
        $this->publishPost($user, 'Uno.');
        $this->publishPost($user, 'Due.');
        $this->publishPost($user, 'Tre.');

        $feedQuery = app(FeedQuery::class);
        $firstPage = $feedQuery->forActor($user->actor);
        $cursor = FeedCursor::fromPost($firstPage->getCollection()->last(), useShareSort: true);

        $sql = null;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$sql): void {
            if (str_contains($query->sql, 'coalesce(shared_at')) {
                $sql = $query->sql;
            }
        });

        $feedQuery->forActor($user->actor, $cursor);

        $this->assertNotNull($sql);
        // ORDER BY puo' usare l'alias; il filtro cursore no (MySQL).
        $this->assertStringNotContainsString('coalesce(shared_at, published_at) <', $sql);
        $this->assertStringNotContainsString('coalesce(shared_at, published_at) =', $sql);
        $this->assertMatchesRegularExpression(
            '/coalesce\(\(select ["`]?created_at["`]? from ["`]?announces["`]?.*\) < \?/is',
            $sql,
        );
    }

    public function test_a_hashtag_page_for_an_unknown_tag_still_renders_the_infinite_scroll_container_without_errors(): void
    {
        $response = $this->get(route('hashtags.show', 'inesistente'));

        $response->assertOk();
        $response->assertSee('id="ob-post-list"', false);
        $response->assertDontSee('data-next-url', false);
    }

    public function test_a_hashtag_page_lists_matching_posts_via_the_belongs_to_many_relation(): void
    {
        config(['openbook.feed.per_page' => 2]);

        $user = $this->createFullAccount('hashtagpage');
        $this->publishPost($user, 'Primo #openbook');
        $this->publishPost($user, 'Secondo #openbook');
        $this->publishPost($user, 'Terzo #openbook');
        $this->publishPost($user, 'Senza tag.');

        $response = $this->get(route('hashtags.show', 'openbook'));

        $response->assertOk();
        // per_page=2, ordine dal piu' recente: Terzo e Secondo in prima pagina.
        $response->assertSee('Terzo', false);
        $response->assertSee('Secondo', false);
        $response->assertDontSee('Primo', false);
        $response->assertDontSee('Senza tag.', false);
        $response->assertSee('data-next-url="', false);
        $response->assertSee('cursor=', false);
    }
}
