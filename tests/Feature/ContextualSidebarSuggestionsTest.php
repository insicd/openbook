<?php

namespace Tests\Feature;

use App\Application\Queries\SidebarSuggestionContext;
use App\Application\Queries\SuggestedActorsByBioQuery;
use App\Application\Services\PostComposer;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ContextualSidebarSuggestionsTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_hashtag_page_suggests_people_with_the_tag_in_their_bio(): void
    {
        $viewer = $this->createFullAccount('tagviewer');
        $match = $this->createFullAccount('tagmatch');
        $other = $this->createFullAccount('tagother');

        $match->profile->forceFill(['bio' => 'Pubblico foto di #street e viaggi'])->save();
        $other->profile->forceFill(['bio' => 'Solo cucina e ricette'])->save();

        $remote = $this->createRemoteActor('remotetag', 'fed.example', [
            'summary' => '<p>Portfolio #street photography</p>',
        ]);

        Hashtag::query()->create(['name' => 'street']);
        app(PostComposer::class)->compose($match->actor, [
            'body' => 'Un post #street',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($viewer)->get(route('hashtags.show', 'street'));

        $response->assertOk();
        $response->assertSee(route('profile.show', $match->username), false);
        $response->assertSee(route('actors.show', $remote), false);
        $response->assertDontSee(route('profile.show', $other->username), false);
    }

    public function test_search_page_suggests_people_with_the_keyword_in_their_bio(): void
    {
        $viewer = $this->createFullAccount('searchviewer');
        $match = $this->createFullAccount('devmatch');
        $other = $this->createFullAccount('devother');

        $match->profile->forceFill(['bio' => 'Sviluppo software libero e open source'])->save();
        $other->profile->forceFill(['bio' => 'Collezionista di francobolli'])->save();

        $response = $this->actingAs($viewer)->get(route('search.create', ['q' => 'software']));

        $response->assertOk();
        $response->assertSee(route('profile.show', $match->username), false);
        $response->assertDontSee(route('profile.show', $other->username), false);
    }

    public function test_undiscoverable_locals_are_excluded_from_contextual_suggestions(): void
    {
        $viewer = $this->createFullAccount('hiddenviewer');
        $hidden = $this->createFullAccount('hiddenbio');
        $hidden->profile->forceFill(['bio' => 'Parlo sempre di privacy'])->save();
        $hidden->settings->forceFill(['discoverable' => false])->save();

        $suggestions = app(SuggestedActorsByBioQuery::class)->forViewer($viewer->actor, 'privacy');

        $this->assertTrue($suggestions->doesntContain(fn ($actor) => $actor->user_id === $hidden->id));
    }

    public function test_undiscoverable_remotes_are_excluded_from_contextual_suggestions(): void
    {
        $viewer = $this->createFullAccount('remotepreview');
        $hidden = $this->createRemoteActor('hiddenremote', 'fed.example', [
            'summary' => '<p>Parlo sempre di privacy</p>',
            'discoverable' => false,
        ]);

        $suggestions = app(SuggestedActorsByBioQuery::class)->forViewer($viewer->actor, 'privacy');

        $this->assertTrue($suggestions->doesntContain(fn ($actor) => $actor->id === $hidden->id));
    }

    public function test_feed_route_does_not_activate_bio_context(): void
    {
        $this->assertNull(SidebarSuggestionContext::bioSearchTerm());
    }

    public function test_hashtag_route_exposes_the_tag_as_bio_context(): void
    {
        $this->get(route('hashtags.show', 'opensource'));

        $this->assertSame('opensource', SidebarSuggestionContext::bioSearchTerm());
    }

    public function test_search_route_exposes_keyword_as_bio_context(): void
    {
        $this->get(route('search.create', ['q' => 'federazione']));

        $this->assertSame('federazione', SidebarSuggestionContext::bioSearchTerm());
    }

    public function test_search_route_ignores_federated_handles_for_bio_context(): void
    {
        $domain = config('openbook.domain');
        $this->get(route('search.create', ['q' => "alice@{$domain}"]));

        $this->assertNull(SidebarSuggestionContext::bioSearchTerm());
    }
}
