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
