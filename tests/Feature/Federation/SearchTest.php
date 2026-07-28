<?php

namespace Tests\Feature\Federation;

use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

/**
 * Ricerca federata (Fase 4): un indirizzo "utente@dominio" locale
 * reindirizza direttamente al profilo, uno remoto passa da una risoluzione
 * WebFinger + recupero del documento Actor (entrambi simulati con
 * Http::fake, nessuna richiesta di rete reale).
 */
class SearchTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private const REMOTE_ACTOR_URI = 'https://social.example/users/nora';

    public function test_a_guest_cannot_access_the_search_page(): void
    {
        $this->get(route('search.create'))->assertRedirect(route('login'));
    }

    public function test_searching_a_local_handle_redirects_to_the_local_profile(): void
    {
        $viewer = $this->createFullAccount('cercatore');
        $target = $this->createFullAccount('trovato');
        $domain = config('openbook.domain');

        $response = $this->actingAs($viewer)->post(route('search.perform'), [
            'q' => "trovato@{$domain}",
        ]);

        $response->assertRedirect(route('profile.show', $target->username));
    }

    public function test_searching_an_unknown_local_handle_fails_validation(): void
    {
        $viewer = $this->createFullAccount('cercatore2');
        $domain = config('openbook.domain');

        $response = $this->actingAs($viewer)->post(route('search.perform'), [
            'q' => "fantasma@{$domain}",
        ]);

        $response->assertSessionHasErrors('q');
    }

    public function test_searching_a_remote_handle_resolves_it_via_webfinger_and_redirects_to_the_actor_page(): void
    {
        $viewer = $this->createFullAccount('cercatore3');

        Http::fake([
            'https://social.example/.well-known/webfinger*' => Http::response([
                'subject' => 'acct:nora@social.example',
                'links' => [
                    ['rel' => 'self', 'type' => 'application/activity+json', 'href' => self::REMOTE_ACTOR_URI],
                ],
            ], 200, ['Content-Type' => 'application/jrd+json']),
            self::REMOTE_ACTOR_URI => Http::response([
                'id' => self::REMOTE_ACTOR_URI,
                'type' => 'Person',
                'preferredUsername' => 'nora',
                'name' => 'Nora',
                'inbox' => self::REMOTE_ACTOR_URI.'/inbox',
                'publicKey' => [
                    'id' => self::REMOTE_ACTOR_URI.'#main-key',
                    'owner' => self::REMOTE_ACTOR_URI,
                    'publicKeyPem' => '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $response = $this->actingAs($viewer)->post(route('search.perform'), [
            'q' => 'nora@social.example',
        ]);

        $actor = Actor::query()->where('uri', self::REMOTE_ACTOR_URI)->firstOrFail();
        $this->assertFalse($actor->is_local);
        $response->assertRedirect(route('actors.show', $actor));
    }

    public function test_searching_an_address_at_an_unreachable_domain_fails_validation(): void
    {
        $viewer = $this->createFullAccount('cercatore4');

        Http::fake(['*' => Http::response('', 404)]);

        $response = $this->actingAs($viewer)->post(route('search.perform'), [
            'q' => 'chiunque@irraggiungibile.example',
        ]);

        $response->assertSessionHasErrors('q');
    }

    public function test_a_malformed_query_fails_validation(): void
    {
        $viewer = $this->createFullAccount('cercatore5');

        $response = $this->actingAs($viewer)->post(route('search.perform'), [
            'q' => 'non e un indirizzo valido',
        ]);

        $response->assertSessionHasErrors('q');
    }
}
