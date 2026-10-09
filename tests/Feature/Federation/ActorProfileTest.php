<?php

namespace Tests\Feature\Federation;

use App\Application\Services\FollowManager;
use App\Federation\Actors\Actor;
use App\Support\CompactNumber;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Pagina profilo di comodo per un Actor remoto in cache locale (Fase 4):
 * mostra dati/statistiche gia' note e permette di avviare/annullare un
 * follow, ma non e' mai raggiungibile per un Actor locale (che ha sempre
 * "/@{username}" come identificatore canonico).
 */
class ActorProfileTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_guest_cannot_view_a_remote_actor_profile(): void
    {
        $remote = $this->createRemoteActor('ophelia');

        $this->get(route('actors.show', $remote))->assertRedirect(route('login'));
    }

    public function test_it_shows_a_cached_remote_actor_profile(): void
    {
        // La pagina profilo tenta anche un recupero dell'outbox reale
        // (RemoteOutboxFetcher): qui non ci interessa, quindi simuliamo una
        // risposta qualunque senza fare una richiesta di rete reale.
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('visitatore');
        $remote = $this->createRemoteActor('peter');

        $response = $this->actingAs($viewer)->get(route('actors.show', $remote));

        $response->assertOk();
        $response->assertSee('Peter');
        $response->assertSee('@peter@remoto.example');
    }

    public function test_visiting_a_stale_profile_refreshes_the_actor_fields_and_reuses_the_fresh_cache(): void
    {
        $viewer = $this->createFullAccount('refreshviewer');
        $remote = $this->createRemoteActor('staleprofile', overrides: [
            'last_fetched_at' => now()->subDays(2),
            'collections_fetched_at' => now(),
        ]);
        Http::fake([
            $remote->uri => Http::response([
                'id' => $remote->uri,
                'type' => 'Person',
                'preferredUsername' => $remote->preferred_username,
                'name' => 'Profilo aggiornato',
                'published' => $remote->published_at->toAtomString(),
                'inbox' => $remote->uri.'/inbox',
                'outbox' => $remote->uri.'/outbox',
                'publicKey' => [
                    'id' => $remote->uri.'#main-key',
                    'owner' => $remote->uri,
                    'publicKeyPem' => $remote->key->public_key,
                ],
                'attachment' => [['type' => 'PropertyValue', 'name' => 'Website', 'value' => '<a href="https://example.test/about">Sito personale</a>']],
            ]),
            '*' => Http::response('', 404),
        ]);

        foreach (range(1, 2) as $visit) {
            $this->actingAs($viewer)->get(route('actors.show', $remote))
                ->assertOk()->assertSee('Profilo aggiornato')->assertSee('Website')
                ->assertSee('href="https://example.test/about"', false);
        }

        $this->assertSame([['label' => 'Website', 'value' => '[Sito personale](https://example.test/about)']], $remote->fresh()->links);
        $actorRequests = Http::recorded(fn ($request): bool => $request->url() === $remote->uri);
        $this->assertCount(1, $actorRequests);
    }

    public function test_a_failed_actor_refresh_keeps_the_cached_profile_visible(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $viewer = $this->createFullAccount('failedrefreshviewer');
        $remote = $this->createRemoteActor('failedrefresh', overrides: [
            'last_fetched_at' => now()->subDays(2),
            'links' => [['label' => 'Professione', 'value' => 'Insegnante']],
        ]);

        $this->actingAs($viewer)->get(route('actors.show', $remote))
            ->assertOk()->assertSee('Failedrefresh')->assertSee('Professione')->assertSee('Insegnante');
        $this->assertSame([['label' => 'Professione', 'value' => 'Insegnante']], $remote->fresh()->links);
        Http::assertSent(fn ($request): bool => $request->url() === $remote->uri);
    }

    public function test_visiting_a_suspended_profile_does_not_refresh_or_reactivate_its_actor(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('suspendedrefreshviewer');
        $remote = $this->createRemoteActor('suspendedrefresh', overrides: [
            'status' => Actor::STATUS_SUSPENDED,
            'last_fetched_at' => now()->subDays(2),
        ]);

        $this->actingAs($viewer)->get(route('actors.show', $remote))->assertOk();

        Http::assertNotSent(fn ($request): bool => $request->url() === $remote->uri);
        $this->assertSame(Actor::STATUS_SUSPENDED, $remote->fresh()->status);
    }

    public function test_it_shows_remote_profile_fields_as_safe_text_and_links_below_the_bio(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('fieldsviewer');
        $remote = $this->createRemoteActor('fieldsremote', overrides: [
            'summary' => '<p>Biografia remota</p>',
            'links' => [
                ['label' => 'Professione', 'value' => 'Insegnante'],
                ['label' => '<script>alert(1)</script>', 'value' => '[Il mio sito](https://example.test/about)'],
                ['label' => 'Non sicuro', 'value' => '[clicca](javascript:alert(1))<img src="https://evil.example/tracker" onerror="alert(1)">'],
            ],
        ]);

        $response = $this->actingAs($viewer)->get(route('actors.show', $remote));

        $response->assertOk()
            ->assertSeeInOrder(['Biografia remota', 'Professione', 'Insegnante', 'Il mio sito'])
            ->assertSee('<script>alert(1)</script>')
            ->assertSee('href="https://example.test/about"', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('href="javascript:', false)
            ->assertDontSee('src="https://evil.example/tracker"', false);
    }

    public function test_it_omits_the_profile_fields_section_when_no_fields_are_cached(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('emptyfieldsviewer');
        $remote = $this->createRemoteActor('emptyfields');

        $this->actingAs($viewer)->get(route('actors.show', $remote))
            ->assertOk()->assertDontSee('class="ob-profile-fields"', false);
    }

    public function test_it_labels_a_remote_application_as_an_automated_account(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('visitatoreapp');
        $remote = $this->createRemoteActor('agenda', overrides: [
            'type' => Actor::TYPE_APPLICATION,
        ]);

        $this->actingAs($viewer)
            ->get(route('actors.show', $remote))
            ->assertOk()
            ->assertSee(__('openbook.actors.application_badge'));
    }

    public function test_it_shows_remote_follower_counts_and_join_date(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('contatoriremoti');
        $remote = $this->createRemoteActor('contaia', overrides: [
            'published_at' => '2019-06-15 00:00:00',
            'followers_count' => 12800,
            'following_count' => 42,
            'collections_fetched_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('actors.show', $remote));

        $response->assertOk();
        $response->assertSee(CompactNumber::format(12800), false);
        $response->assertSee('42', false);
        $response->assertSee(__('openbook.profile.joined_on', [
            'date' => $remote->published_at->translatedFormat('d F Y'),
        ]));
    }

    public function test_it_renders_when_the_remote_outbox_is_unreachable(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException(
                new ConnectException(
                    'cURL error 28: Connection timed out after 10001 milliseconds',
                    new Request('GET', 'https://offline.example/outbox'),
                ),
            );
        });

        $viewer = $this->createFullAccount('visitoffline');
        $remote = $this->createRemoteActor('offlinegroup', 'offline.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Community offline',
        ]);

        $this->actingAs($viewer)
            ->get(route('actors.show', $remote))
            ->assertOk()
            ->assertSee('Community offline');
    }

    public function test_hashtags_in_a_remote_actor_summary_are_rendered_as_links(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('visitatorebio');
        $remote = $this->createRemoteActor('bioremote', overrides: [
            'summary' => '<p>Scrivo di <a href="https://example.test/tags/fediverso">#fediverso</a></p>',
        ]);

        $response = $this->actingAs($viewer)->get(route('actors.show', $remote));

        $response->assertOk();
        // Il markup remoto viene sanitizzato, poi gli hashtag riconosciuti
        // vengono collegati alla corrispondente pagina locale.
        $response->assertSee('href="'.route('hashtags.show', 'fediverso').'"', false);
        $response->assertSee('#fediverso');
        $response->assertDontSee('https://example.test/tags/fediverso', false);
    }

    public function test_custom_emojis_are_rendered_in_remote_actor_name_and_summary(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $viewer = $this->createFullAccount('visitatoreemoji');
        $remote = $this->createRemoteActor('emoji', overrides: [
            'name' => 'Emoji :blobcat:',
            'summary' => '<p>Bio :blobcat:</p>',
            'custom_emojis' => [':blobcat:' => 'https://remoto.example/emoji/blobcat.png'],
        ]);

        $response = $this->actingAs($viewer)->get(route('actors.show', $remote));

        $response->assertOk();
        $response->assertSee('class="ob-custom-emoji"', false);
        $response->assertSee('src="https://remoto.example/emoji/blobcat.png"', false);
        $response->assertSee('alt=":blobcat:"', false);
    }

    public function test_visiting_a_local_actor_id_redirects_to_the_canonical_profile(): void
    {
        $viewer = $this->createFullAccount('visitatore2');
        $target = $this->createFullAccount('localecanon');

        $response = $this->actingAs($viewer)->get(route('actors.show', $target->actor));

        $response->assertRedirect(route('profile.show', $target->username));
    }

    public function test_a_viewer_can_follow_a_remote_actor_from_its_profile_page(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('follower1');
        $remote = $this->createRemoteActor('quentin');

        $this->actingAs($viewer)->post(route('actors.follow', $remote))->assertRedirect();

        $this->assertTrue(app(FollowManager::class)->hasPendingRequest($viewer->actor, $remote));
    }

    public function test_a_viewer_can_cancel_a_pending_follow_from_its_profile_page(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('follower2');
        $remote = $this->createRemoteActor('rachel');

        app(FollowManager::class)->follow($viewer->actor, $remote);
        $this->actingAs($viewer)->delete(route('actors.unfollow', $remote))->assertRedirect();

        $this->assertFalse(app(FollowManager::class)->hasPendingRequest($viewer->actor, $remote));
    }
}
