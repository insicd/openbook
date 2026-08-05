<?php

namespace Tests\Feature\Federation;

use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class RemoteActorResolverTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private const ACTOR_URI = 'https://remoto.example/users/walt';

    private function fakeActorDocument(array $overrides = []): array
    {
        return array_merge([
            'id' => self::ACTOR_URI,
            'type' => 'Person',
            'preferredUsername' => 'walt',
            'name' => 'Walt',
            'inbox' => self::ACTOR_URI.'/inbox',
            'outbox' => self::ACTOR_URI.'/outbox',
            'followers' => self::ACTOR_URI.'/followers',
            'endpoints' => ['sharedInbox' => 'https://remoto.example/inbox'],
            'publicKey' => [
                'id' => self::ACTOR_URI.'#main-key',
                'owner' => self::ACTOR_URI,
                'publicKeyPem' => '-----BEGIN PUBLIC KEY-----test-----END PUBLIC KEY-----',
            ],
        ], $overrides);
    }

    public function test_it_fetches_and_caches_a_remote_actor(): void
    {
        Http::fake([self::ACTOR_URI => Http::response($this->fakeActorDocument(), 200, ['Content-Type' => 'application/activity+json'])]);

        $actor = app(RemoteActorResolver::class)->resolveByUri(self::ACTOR_URI);

        $this->assertNotNull($actor);
        $this->assertFalse($actor->is_local);
        $this->assertSame('walt', $actor->preferred_username);
        $this->assertSame('remoto.example', $actor->domain);
        $this->assertSame(self::ACTOR_URI.'/inbox', $actor->endpoints->inbox);
        $this->assertSame('https://remoto.example/inbox', $actor->endpoints->shared_inbox);
    }

    public function test_it_reuses_the_cache_within_the_ttl_without_a_second_http_call(): void
    {
        Http::fake([self::ACTOR_URI => Http::response($this->fakeActorDocument(), 200, ['Content-Type' => 'application/activity+json'])]);

        $resolver = app(RemoteActorResolver::class);
        $first = $resolver->resolveByUri(self::ACTOR_URI);
        $second = $resolver->resolveByUri(self::ACTOR_URI);

        $this->assertSame($first->id, $second->id);
        Http::assertSentCount(1);
    }

    public function test_it_refetches_once_the_cache_has_expired(): void
    {
        Http::fake([self::ACTOR_URI => Http::response($this->fakeActorDocument(['name' => 'Walt Aggiornato']), 200, ['Content-Type' => 'application/activity+json'])]);

        $resolver = app(RemoteActorResolver::class);
        $resolver->resolveByUri(self::ACTOR_URI);

        Actor::query()->where('uri', self::ACTOR_URI)->update(['last_fetched_at' => now()->subDays(2)]);

        $refreshed = $resolver->resolveByUri(self::ACTOR_URI);

        $this->assertSame('Walt Aggiornato', $refreshed->name);
        Http::assertSentCount(2);
    }

    public function test_it_refuses_a_document_that_declares_a_different_id(): void
    {
        Http::fake([self::ACTOR_URI => Http::response($this->fakeActorDocument(['id' => 'https://altro.example/qualcunaltro']), 200, ['Content-Type' => 'application/activity+json'])]);

        $actor = app(RemoteActorResolver::class)->resolveByUri(self::ACTOR_URI);

        $this->assertNull($actor);
        $this->assertDatabaseCount('actors', 0);
    }

    public function test_it_refuses_to_treat_a_local_uri_as_a_remote_actor(): void
    {
        $local = $this->createFullAccount('localenonremoto');

        $actor = app(RemoteActorResolver::class)->resolveByUri($local->actor->uri);

        $this->assertNull($actor);
    }

    public function test_resolve_by_handle_performs_a_webfinger_lookup_then_fetches_the_actor(): void
    {
        Http::fake([
            'https://remoto.example/.well-known/webfinger*' => Http::response([
                'subject' => 'acct:walt@remoto.example',
                'links' => [
                    ['rel' => 'self', 'type' => 'application/activity+json', 'href' => self::ACTOR_URI],
                ],
            ], 200, ['Content-Type' => 'application/jrd+json']),
            self::ACTOR_URI => Http::response($this->fakeActorDocument(), 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $actor = app(RemoteActorResolver::class)->resolveByHandle('@walt@remoto.example');

        $this->assertNotNull($actor);
        $this->assertSame(self::ACTOR_URI, $actor->uri);
    }

    public function test_resolve_by_handle_returns_null_for_a_local_domain(): void
    {
        $domain = config('openbook.domain');

        $actor = app(RemoteActorResolver::class)->resolveByHandle("chiunque@{$domain}");

        $this->assertNull($actor);
    }

    public function test_resolve_by_handle_returns_null_when_webfinger_does_not_resolve(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $actor = app(RemoteActorResolver::class)->resolveByHandle('nessuno@remoto.example');

        $this->assertNull($actor);
    }

    public function test_resolve_by_key_id_with_fragment_uses_the_actor_uri(): void
    {
        Http::fake([self::ACTOR_URI => Http::response($this->fakeActorDocument(), 200, ['Content-Type' => 'application/activity+json'])]);

        $actor = app(RemoteActorResolver::class)->resolveByKeyId(self::ACTOR_URI.'#main-key');

        $this->assertNotNull($actor);
        $this->assertSame(self::ACTOR_URI, $actor->uri);
        Http::assertSentCount(1);
    }

    public function test_resolve_by_key_id_follows_a_cryptographic_key_document_to_its_owner(): void
    {
        // Come tags.pub / activitypub-bot: keyId e' un URL di CryptographicKey,
        // non l'URI Actor con frammento #main-key.
        $keyId = self::ACTOR_URI.'/publickey';
        $pem = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtest\n-----END PUBLIC KEY-----\n";

        Http::fake([
            $keyId => Http::response([
                'id' => $keyId,
                'type' => 'CryptographicKey',
                'owner' => self::ACTOR_URI,
                'publicKeyPem' => $pem,
            ], 200, ['Content-Type' => 'application/activity+json']),
            self::ACTOR_URI => Http::response($this->fakeActorDocument([
                'publicKey' => [
                    'id' => $keyId,
                    'owner' => self::ACTOR_URI,
                    'publicKeyPem' => $pem,
                ],
            ]), 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $actor = app(RemoteActorResolver::class)->resolveByKeyId($keyId);

        $this->assertNotNull($actor);
        $this->assertSame(self::ACTOR_URI, $actor->uri);
        $this->assertSame($pem, $actor->key?->public_key);
        Http::assertSent(fn ($request): bool => $request->url() === $keyId);
        Http::assertSent(fn ($request): bool => $request->url() === self::ACTOR_URI);
    }

    public function test_resolve_by_key_id_rejects_a_cryptographic_key_whose_owner_is_on_another_host(): void
    {
        $keyId = self::ACTOR_URI.'/publickey';

        Http::fake([
            $keyId => Http::response([
                'id' => $keyId,
                'type' => 'CryptographicKey',
                'owner' => 'https://altro.example/users/impostore',
                'publicKeyPem' => '-----BEGIN PUBLIC KEY-----x-----END PUBLIC KEY-----',
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $actor = app(RemoteActorResolver::class)->resolveByKeyId($keyId);

        $this->assertNull($actor);
        $this->assertDatabaseCount('actors', 0);
    }
}
