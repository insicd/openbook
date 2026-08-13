<?php

namespace Tests\Feature;

use App\Application\Services\PostComposer;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class SearchSuggestTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_guests_cannot_request_search_suggestions(): void
    {
        $this->getJson(route('search.suggest', ['q' => 'ali']))
            ->assertUnauthorized();
    }

    public function test_search_suggestions_include_local_people_hashtags_and_known_remotes(): void
    {
        $viewer = $this->createFullAccount('cercatore');
        $local = $this->createFullAccount('alice');
        $local->profile->update(['display_name' => 'Alice Locale']);
        $remote = $this->createRemoteActor('alicia', 'social.example', [
            'name' => 'Alicia Remota',
        ]);

        app(PostComposer::class)->compose($local->actor, [
            'body' => 'Ciao #alimentazione',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        Hashtag::query()->create(['name' => 'altro']);

        $response = $this->actingAs($viewer)
            ->getJson(route('search.suggest', ['q' => 'ali']));

        $response->assertOk();
        $response->assertJsonFragment([
            'type' => 'person',
            'handle' => 'alice',
            'display_name' => 'Alice Locale',
            'is_local' => true,
            'url' => route('profile.show', 'alice'),
        ]);
        $response->assertJsonFragment([
            'type' => 'person',
            'handle' => 'alicia@social.example',
            'display_name' => 'Alicia Remota',
            'is_local' => false,
        ]);
        $response->assertJsonFragment([
            'type' => 'hashtag',
            'handle' => 'alimentazione',
            'display_name' => '#alimentazione',
            'url' => route('hashtags.show', 'alimentazione'),
        ]);
        $response->assertJsonMissing(['handle' => 'altro']);
        $this->assertNotNull($remote->id);
    }

    public function test_hash_prefixed_suggestions_return_only_hashtags(): void
    {
        $viewer = $this->createFullAccount('taggatore');
        $this->createFullAccount('alice');
        Hashtag::query()->create(['name' => 'openbook']);
        Hashtag::query()->create(['name' => 'opencore']);

        $response = $this->actingAs($viewer)
            ->getJson(route('search.suggest', ['q' => '#open']));

        $response->assertOk();
        $response->assertJsonFragment(['type' => 'hashtag', 'handle' => 'openbook']);
        $response->assertJsonFragment(['type' => 'hashtag', 'handle' => 'opencore']);
        $response->assertJsonMissing(['type' => 'person']);
    }

    public function test_short_queries_return_no_suggestions(): void
    {
        $viewer = $this->createFullAccount('breve');
        $this->createFullAccount('ab');

        $response = $this->actingAs($viewer)
            ->getJson(route('search.suggest', ['q' => 'a']));

        $response->assertOk();
        $response->assertJsonCount(0, 'suggestions');
    }

    public function test_search_fields_enable_autocomplete_markup(): void
    {
        $viewer = $this->createFullAccount('uimarkup');

        $home = $this->actingAs($viewer)->get(route('feed.index'));
        $home->assertOk();
        $home->assertSee('data-search-suggest', false);
        $home->assertSee('assets/js/search-suggest.js', false);
        $home->assertSee(route('search.suggest'), false);

        $search = $this->actingAs($viewer)->get(route('search.create'));
        $search->assertOk();
        $search->assertSee('id="search-q"', false);
        $search->assertSee('data-search-suggest', false);
    }
}
