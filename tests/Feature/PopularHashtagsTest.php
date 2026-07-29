<?php

namespace Tests\Feature;

use App\Application\Queries\PopularHashtagsQuery;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Sidebar destra, riquadro "Questa istanza": niente piu' numero di iscritti
 * (rimosso su richiesta), al suo posto i tag piu' usati dalla community
 * locale. Vedi {@see PopularHashtagsQuery}.
 */
class PopularHashtagsTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function publishPost(User $author, string $body, string $visibility = Post::VISIBILITY_PUBLIC): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => $visibility,
        ]);
    }

    public function test_it_ranks_hashtags_by_number_of_local_public_uses(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $this->publishPost($alice, 'Bella giornata di #sole');
        $this->publishPost($bob, 'Ancora #sole oggi');
        $this->publishPost($bob, 'Un post su #pioggia');

        $top = app(PopularHashtagsQuery::class)->top();

        $this->assertSame(['sole', 'pioggia'], $top->pluck('name')->all());
        $this->assertSame(2, $top->firstWhere('name', 'sole')->usage_count);
        $this->assertSame(1, $top->firstWhere('name', 'pioggia')->usage_count);
    }

    public function test_it_excludes_followers_only_and_direct_posts(): void
    {
        $alice = $this->createFullAccount('alice');

        $this->publishPost($alice, 'Pubblico su #aperto', Post::VISIBILITY_PUBLIC);
        $this->publishPost($alice, 'Riservato ai follower su #chiuso', Post::VISIBILITY_FOLLOWERS);
        $this->publishPost($alice, 'Diretto su #privato', Post::VISIBILITY_DIRECT);

        $names = app(PopularHashtagsQuery::class)->top()->pluck('name');

        $this->assertTrue($names->contains('aperto'));
        $this->assertFalse($names->contains('chiuso'));
        $this->assertFalse($names->contains('privato'));
    }

    public function test_it_excludes_hashtags_used_only_by_remote_cached_posts(): void
    {
        $remoteActor = $this->createRemoteActor('remoto');

        $remotePost = Post::query()->create([
            'actor_id' => $remoteActor->id,
            'uri' => $remoteActor->uri.'/posts/1',
            'body' => 'Post remoto su #fediverso',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $hashtag = Hashtag::query()->create(['name' => 'fediverso']);
        $remotePost->hashtags()->attach($hashtag->id);

        $names = app(PopularHashtagsQuery::class)->top()->pluck('name');

        $this->assertFalse($names->contains('fediverso'));
    }

    public function test_the_sidebar_shows_the_most_used_hashtags_instead_of_the_members_count(): void
    {
        $alice = $this->createFullAccount('alice');
        $this->publishPost($alice, 'Un post su #laravel');

        $response = $this->actingAs($alice)->get('/home');

        $response->assertOk();
        $response->assertSee('#laravel');
        $response->assertDontSee(__('openbook.sidebar.no_popular_hashtags'));
    }

    public function test_the_sidebar_shows_an_empty_state_when_no_hashtag_has_been_used_yet(): void
    {
        $alice = $this->createFullAccount('alice');

        $response = $this->actingAs($alice)->get('/home');

        $response->assertOk();
        $response->assertSee(__('openbook.sidebar.no_popular_hashtags'));
    }
}
