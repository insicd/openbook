<?php

namespace Tests\Feature;

use App\Application\Services\CommentComposer;
use App\Application\Services\CommunityRegistrar;
use App\Application\Services\PostComposer;
use App\Federation\Actors\Actor;
use App\Domain\Events\Event;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Ricerca per parole chiave: persone locali e remote note, contenuti
 * indicizzati localmente e catalogo degli eventi visibili.
 */
class LocalSearchTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_keyword_search_finds_local_people_by_username_display_name_and_bio(): void
    {
        $viewer = $this->createFullAccount('cercatorelocale');
        $match = $this->createFullAccount('botanico');
        $match->profile->forceFill([
            'display_name' => 'Amico delle piante',
            'bio' => 'Colleziono felci rare.',
        ])->save();
        $this->createFullAccount('cuoco');

        $byUsername = $this->actingAs($viewer)->get(route('search.create', ['q' => 'botan']));
        $byUsername->assertOk();
        $byUsername->assertSee('botanico', false);
        $byUsername->assertSee(__('openbook.search.people'), false);
        $byUsername->assertDontSee('cuoco@', false);

        $byBio = $this->actingAs($viewer)->get(route('search.create', ['q' => 'felci']));
        $byBio->assertOk();
        $byBio->assertSee('Amico delle piante', false);
    }

    public function test_people_results_include_known_remote_actors_and_normalize_at_only_for_people(): void
    {
        $viewer = $this->createFullAccount('ricercatore');
        $this->createFullAccount('zeldazarathustra');
        $this->createRemoteActor('nuke', 'openb.app', ['name' => 'Dario Fadda']);

        $this->actingAs($viewer)
            ->get(route('search.create', ['q' => '@zeldaz']))
            ->assertOk()
            ->assertSee('@zeldazarathustra@'.config('openbook.domain'), false);

        $this->actingAs($viewer)
            ->get(route('search.create', ['q' => 'Dario fadda']))
            ->assertOk()
            ->assertSee('Dario Fadda', false)
            ->assertSee('@nuke@openb.app', false);
    }

    public function test_undiscoverable_remote_is_excluded_from_keyword_people_results(): void
    {
        $viewer = $this->createFullAccount('ricercatore');
        $this->createRemoteActor('segretoremoto', 'social.example', [
            'name' => 'Persona segreta',
            'discoverable' => false,
        ]);

        $this->actingAs($viewer)
            ->get(route('search.create', ['q' => 'segretoremoto']))
            ->assertOk()
            ->assertDontSee('@segretoremoto@social.example', false);
    }

    public function test_at_prefix_is_kept_when_searching_post_text(): void
    {
        $viewer = $this->createFullAccount('ricercatore');
        $composer = app(PostComposer::class);
        $composer->compose($viewer->actor, [
            'body' => 'Menzione @zeldaz nella discussione.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $composer->compose($viewer->actor, [
            'body' => 'Solo zeldaz senza chiocciola.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($viewer)
            ->get(route('search.create', ['q' => '@zeldaz']))
            ->assertOk()
            ->assertSee('nella discussione', false)
            ->assertDontSee('Solo zeldaz senza chiocciola.', false);
    }

    public function test_a_keyword_search_finds_local_and_known_public_posts(): void
    {
        $viewer = $this->createFullAccount('cercacontenuti');
        $author = $this->createFullAccount('autorelocale');
        $author->settings->forceFill(['indexable' => true])->save();
        $author->actor->update(['indexable' => true]);

        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Oggi parliamo di astronomia amatoriale.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Un post qualunque.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        app(CommentComposer::class)->compose($author->actor, $post, 'Commento sulla nebulosa e sull astronomia.');

        $remote = $this->createRemoteActor('remotoastro', 'remoto.example', [
            'indexable' => false,
        ]);
        Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => 'https://remoto.example/users/remotoastro/statuses/1',
            'body' => 'Post remoto di astronomia.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $feed = Actor::query()->create([
            'type' => Actor::TYPE_FEED,
            'is_local' => false,
            'preferred_username' => 'osservatoriofeed',
            'domain' => (string) config('openbook.domain'),
            'uri' => 'https://osservatorio.example/feed.xml',
            'name' => 'Osservatorio',
            'discoverable' => false,
            'indexable' => false,
            'status' => Actor::STATUS_ACTIVE,
        ]);
        Post::query()->create([
            'actor_id' => $feed->id,
            'uri' => 'https://osservatorio.example/posts/astro',
            'title' => 'Bollettino',
            'body' => 'Voce feed sull astronomia.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => 'astronomia']));

        $response->assertOk();
        $response->assertSee('Oggi parliamo di astronomia amatoriale.', false);
        $response->assertSee('Commento sulla nebulosa e sull astronomia.', false);
        $response->assertSee('Post remoto di astronomia.', false);
        $response->assertSee('Voce feed sull astronomia.', false);
    }

    public function test_a_keyword_search_finds_bots_feeds_and_public_communities(): void
    {
        $viewer = $this->createFullAccount('cercaprofili');
        $this->createRemoteActor('followback', 'tags.pub', [
            'type' => Actor::TYPE_APPLICATION,
            'name' => 'Follow Back Bot',
        ]);
        Actor::query()->create([
            'type' => Actor::TYPE_FEED,
            'is_local' => false,
            'preferred_username' => 'giornalequartiere',
            'domain' => (string) config('openbook.domain'),
            'uri' => 'https://quartiere.example/rss.xml',
            'name' => 'Giornale di quartiere',
            'discoverable' => false,
            'indexable' => false,
            'status' => Actor::STATUS_ACTIVE,
        ]);
        app(CommunityRegistrar::class)->register($viewer, [
            'slug' => 'astronomiaclub',
            'name' => 'Club di astronomia',
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => 'astronomia']));

        $response->assertOk();
        $response->assertSee(__('openbook.search.communities'), false);
        $response->assertSee('Club di astronomia', false);

        $this->actingAs($viewer)
            ->get(route('search.create', ['q' => 'Follow Back']))
            ->assertOk()
            ->assertSee(__('openbook.search.applications'), false)
            ->assertSee('Follow Back Bot', false);

        $this->actingAs($viewer)
            ->get(route('search.create', ['q' => 'quartiere']))
            ->assertOk()
            ->assertSee(__('openbook.search.feeds'), false)
            ->assertSee('Giornale di quartiere', false);
    }

    public function test_a_single_hashtag_match_with_hash_prefix_opens_the_tag_page(): void
    {
        $viewer = $this->createFullAccount('cercahashtag');
        Hashtag::query()->create(['name' => 'openbook']);
        Hashtag::query()->create(['name' => 'altro']);

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => '#open']));

        $response->assertRedirect(route('hashtags.show', 'openbook'));
    }

    public function test_multiple_hashtag_matches_keep_the_search_results_page(): void
    {
        $viewer = $this->createFullAccount('cercamultihtag');
        Hashtag::query()->create(['name' => 'openbook']);
        Hashtag::query()->create(['name' => 'opencore']);
        Hashtag::query()->create(['name' => 'altro']);

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => '#open']));

        $response->assertOk();
        $response->assertSee('#openbook', false);
        $response->assertSee('#opencore', false);
        $response->assertDontSee('#altro', false);
        $response->assertSee(__('openbook.search.hashtags'), false);
    }

    public function test_hashtag_search_without_hash_prefix_does_not_auto_redirect(): void
    {
        $viewer = $this->createFullAccount('cercasenzahash');
        Hashtag::query()->create(['name' => 'openbook']);

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => 'openbook']));

        $response->assertOk();
        $response->assertSee('#openbook', false);
        $response->assertSee(__('openbook.search.hashtags'), false);
    }

    public function test_undiscoverable_accounts_are_excluded_from_keyword_people_results(): void
    {
        $viewer = $this->createFullAccount('cercadiscover');
        $hidden = $this->createFullAccount('nascosto');
        $hidden->settings->forceFill(['discoverable' => false])->save();

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => 'nascosto']));

        $response->assertOk();
        $response->assertSeeText('Nessun risultato per "nascosto".');
        $response->assertDontSee('@nascosto@', false);
    }

    public function test_non_indexable_public_posts_are_hidden_from_other_users_in_search(): void
    {
        $viewer = $this->createFullAccount('cercaindex');
        $author = $this->createFullAccount('autorechiuso');

        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Un trattato di entomologia urbana.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => 'entomologia']));

        $response->assertOk();
        $response->assertDontSee('Un trattato di entomologia urbana.', false);
        $response->assertSeeText('Nessun risultato per "entomologia".');
    }

    public function test_an_author_can_still_search_their_own_non_indexable_posts(): void
    {
        $author = $this->createFullAccount('autoreproprio');

        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Appunti privati di entomologia.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($author)->get(route('search.create', ['q' => 'entomologia']));

        $response->assertOk();
        $response->assertSee('Appunti privati di entomologia.', false);
    }

    public function test_followers_only_posts_are_hidden_from_non_followers_in_search(): void
    {
        $viewer = $this->createFullAccount('cercaprivato');
        $author = $this->createFullAccount('autoreprivato');

        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Segreto sulla ricetta della pizza.',
            'visibility' => Post::VISIBILITY_FOLLOWERS,
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => 'ricetta']));

        $response->assertOk();
        $response->assertDontSee('Segreto sulla ricetta della pizza.', false);
    }

    public function test_a_query_that_is_not_a_handle_does_not_fail_validation(): void
    {
        $viewer = $this->createFullAccount('cercatesto');

        $response = $this->actingAs($viewer)->get(route('search.create', [
            'q' => 'non e un indirizzo valido',
        ]));

        $response->assertOk();
        $response->assertSessionDoesntHaveErrors('q');
        $response->assertSeeText('Nessun risultato per "non e un indirizzo valido".');
    }

    public function test_like_wildcards_in_the_query_are_treated_literally(): void
    {
        $viewer = $this->createFullAccount('cercajolly');
        $author = $this->createFullAccount('autorejolly');
        $author->settings->forceFill(['indexable' => true])->save();
        $author->actor->update(['indexable' => true]);

        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Sconto del 100% solo oggi.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Sconto del 100X solo oggi.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => '100%']));

        $response->assertOk();
        $response->assertSee('Sconto del 100% solo oggi.', false);
        $response->assertDontSee('Sconto del 100X solo oggi.', false);
    }

    public function test_search_finds_remote_events_by_text_location_and_hashtag(): void
    {
        $viewer = $this->createFullAccount('cercaeventi');
        $actor = $this->createRemoteActor('agendaeventi');
        $event = Event::query()->create([
            'actor_id' => $actor->id,
            'uri' => 'https://remoto.example/events/jazz',
            'name' => 'Concerto jazz cosmico',
            'summary' => 'Una serata di improvvisazione.',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
        ]);
        $event->location()->create(['name' => 'Auditorium Galassia', 'source' => 'remote']);
        $hashtag = Hashtag::query()->create(['name' => 'spacejazz']);
        $event->hashtags()->attach($hashtag);

        foreach (['cosmico', 'Galassia', 'spacejazz'] as $term) {
            $this->actingAs($viewer)
                ->get(route('search.create', ['q' => $term]))
                ->assertOk()
                ->assertSee('Concerto jazz cosmico')
                ->assertSee(__('openbook.events.badge'));
        }
    }

    public function test_search_does_not_reveal_private_events_or_tombstones(): void
    {
        $viewer = $this->createFullAccount('cercaeventiprivati');
        $actor = $this->createRemoteActor('agendaprivata');

        Event::query()->create([
            'actor_id' => $actor->id,
            'uri' => 'https://remoto.example/events/segreto',
            'name' => 'Riunione segretissima',
            'visibility' => Event::VISIBILITY_DIRECT,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
        ]);
        Event::query()->create([
            'actor_id' => $actor->id,
            'uri' => 'https://remoto.example/events/deleted',
            'name' => 'Festival segretissimo eliminato',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_DELETED,
            'start_at' => now()->addDay(),
            'deleted_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->get(route('search.create', ['q' => 'segretissim']))
            ->assertOk()
            ->assertDontSee('Riunione segretissima')
            ->assertDontSee('Festival segretissimo eliminato');
    }
}
