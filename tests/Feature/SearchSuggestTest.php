<?php

namespace Tests\Feature;

use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
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

    public function test_at_prefix_finds_a_local_person_and_a_spaced_name_finds_a_remote_person(): void
    {
        $viewer = $this->createFullAccount('ricercatore');
        $this->createFullAccount('zeldazarathustra');
        $this->createRemoteActor('nuke', 'openb.app', ['name' => 'Dario Fadda']);

        $this->actingAs($viewer)
            ->getJson(route('search.suggest', ['q' => '@zeldaz']))
            ->assertOk()
            ->assertJsonFragment(['handle' => 'zeldazarathustra', 'is_local' => true]);

        $this->actingAs($viewer)
            ->getJson(route('search.suggest', ['q' => 'Dario fadda']))
            ->assertOk()
            ->assertJsonFragment(['handle' => 'nuke@openb.app', 'display_name' => 'Dario Fadda']);
    }

    public function test_a_partial_federated_handle_filters_by_username_and_domain(): void
    {
        $viewer = $this->createFullAccount('ricercatore');
        $this->createRemoteActor('nuke', 'openb.app');
        $this->createRemoteActor('nuke', 'altra.example');

        $this->actingAs($viewer)
            ->getJson(route('search.suggest', ['q' => '@nuke@openb']))
            ->assertOk()
            ->assertJsonFragment(['handle' => 'nuke@openb.app'])
            ->assertJsonMissing(['handle' => 'nuke@altra.example']);
    }

    public function test_search_suggestions_respect_discoverable_for_local_and_remote_people(): void
    {
        $viewer = $this->createFullAccount('ricercatore');
        $local = $this->createFullAccount('nascostolocale');
        $local->settings->forceFill(['discoverable' => false])->save();
        $this->createRemoteActor('nascostoremoto', 'social.example', ['discoverable' => false]);
        $this->createRemoteActor('nascostovisibile', 'social.example');

        $response = $this->actingAs($viewer)
            ->getJson(route('search.suggest', ['q' => 'nascosto']));

        $response->assertOk()
            ->assertJsonMissing(['handle' => 'nascostolocale'])
            ->assertJsonMissing(['handle' => 'nascostoremoto@social.example'])
            ->assertJsonFragment(['handle' => 'nascostovisibile@social.example']);
    }

    public function test_disabled_local_account_is_not_suggested_even_if_its_actor_is_active(): void
    {
        $viewer = $this->createFullAccount('ricercatore');
        $disabled = $this->createFullAccount('accountdisabilitato');
        $disabled->forceFill(['status' => User::STATUS_DISABLED])->save();

        $this->actingAs($viewer)
            ->getJson(route('search.suggest', ['q' => 'accountdisabilitato']))
            ->assertOk()
            ->assertJsonMissing(['handle' => 'accountdisabilitato']);
    }

    public function test_people_are_ranked_before_bio_matches_across_local_and_remote_actors(): void
    {
        $viewer = $this->createFullAccount('ricercatore');

        for ($index = 0; $index < 5; $index++) {
            $local = $this->createFullAccount('profilo'.$index);
            $local->profile->forceFill(['bio' => 'Mi chiamo Dario.'])->save();
        }

        $this->createRemoteActor('nuke', 'openb.app', ['name' => 'Dario Fadda']);

        $response = $this->actingAs($viewer)
            ->getJson(route('search.suggest', ['q' => 'Dario']));

        $response->assertOk()
            ->assertJsonCount(5, 'suggestions')
            ->assertJsonPath('suggestions.0.handle', 'nuke@openb.app');
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
