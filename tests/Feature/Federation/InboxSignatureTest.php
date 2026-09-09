<?php

namespace Tests\Feature\Federation;

use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Actors\ActorKey;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Inbox\InboxItem;
use App\Infrastructure\Security\HttpSignatureSigner;
use App\Infrastructure\Security\KeyPair;
use App\Infrastructure\Security\LinkedData\LinkedDataSignature;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Verifica solo la ricezione nell'inbox (autenticazione, validazione,
 * deduplicazione): l'elaborazione semantica delle attivita' (Fase 4) e'
 * accodata su un job separato, qui intercettato con Queue::fake() cosi' che
 * questi test restino concentrati sul solo livello di trasporto e non
 * effettuino consegne reali in uscita.
 */
class InboxSignatureTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private const REMOTE_ACTOR_URI = 'https://remoto.example/users/carol';

    private KeyPair $remoteKeyPair;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->remoteKeyPair = (new RsaKeyPairGenerator)->generate(2048);

        Http::fake([
            self::REMOTE_ACTOR_URI => Http::response([
                'id' => self::REMOTE_ACTOR_URI,
                'type' => 'Person',
                'preferredUsername' => 'carol',
                'inbox' => self::REMOTE_ACTOR_URI.'/inbox',
                'outbox' => self::REMOTE_ACTOR_URI.'/outbox',
                'followers' => self::REMOTE_ACTOR_URI.'/followers',
                'following' => self::REMOTE_ACTOR_URI.'/following',
                'publicKey' => [
                    'id' => self::REMOTE_ACTOR_URI.'#main-key',
                    'owner' => self::REMOTE_ACTOR_URI,
                    'publicKeyPem' => $this->remoteKeyPair->publicKey,
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);
    }

    /**
     * @return array{path: string, body: string, date: string, digest: string, signature: string}
     */
    private function buildSignedFollowActivity(string $path, string $localActorUri, ?string $claimedActor = null): array
    {
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Follow',
            'actor' => $claimedActor ?? self::REMOTE_ACTOR_URI,
            'object' => $localActorUri,
        ];

        $body = json_encode($activity, JSON_THROW_ON_ERROR);
        $date = now()->toRfc7231String();
        $digest = HttpSignatureSigner::digest($body);
        $host = parse_url(url('/'), PHP_URL_HOST);

        $signature = (new HttpSignatureSigner)->sign(
            'POST',
            $path,
            ['host' => $host, 'date' => $date, 'digest' => $digest],
            self::REMOTE_ACTOR_URI.'#main-key',
            $this->remoteKeyPair->privateKey,
            ['(request-target)', 'host', 'date', 'digest']
        );

        return compact('path', 'body', 'date', 'digest', 'signature');
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array{path: string, body: string, date: string, digest: string, signature: string}
     */
    private function signedActivityParts(string $path, array $activity, string $keyOwnerUri, KeyPair $keyPair): array
    {
        $body = json_encode($activity, JSON_THROW_ON_ERROR);
        $date = now()->toRfc7231String();
        $digest = HttpSignatureSigner::digest($body);
        $host = parse_url(url('/'), PHP_URL_HOST);
        $signature = (new HttpSignatureSigner)->sign(
            'POST',
            $path,
            ['host' => $host, 'date' => $date, 'digest' => $digest],
            $keyOwnerUri.'#main-key',
            $keyPair->privateKey,
            ['(request-target)', 'host', 'date', 'digest']
        );

        return compact('path', 'body', 'date', 'digest', 'signature');
    }

    /**
     * @param  array<string, string>  $overrideServer
     */
    private function postSigned(array $parts, array $overrideServer = []): TestResponse
    {
        $server = array_merge([
            'CONTENT_TYPE' => 'application/activity+json',
            'HTTP_DATE' => $parts['date'],
            'HTTP_DIGEST' => $parts['digest'],
            'HTTP_SIGNATURE' => $parts['signature'],
        ], $overrideServer);

        return $this->call('POST', $parts['path'], [], [], [], $server, $parts['body']);
    }

    public function test_a_validly_signed_activity_is_accepted_and_stored(): void
    {
        $target = $this->createFullAccount('bersaglio');
        $parts = $this->buildSignedFollowActivity('/users/bersaglio/inbox', $target->actor->uri);

        $response = $this->postSigned($parts);

        $response->assertStatus(202);

        $activity = json_decode($parts['body'], true);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => $activity['id'],
            'activity_type' => 'Follow',
            'actor_uri' => self::REMOTE_ACTOR_URI,
            'status' => InboxItem::STATUS_PENDING,
            'signature_valid' => true,
            'is_shared' => false,
        ]);
    }

    public function test_the_shared_inbox_also_accepts_a_validly_signed_activity(): void
    {
        $target = $this->createFullAccount('condiviso');
        $parts = $this->buildSignedFollowActivity('/inbox', $target->actor->uri);

        $response = $this->postSigned($parts);

        $response->assertStatus(202);
        $activity = json_decode($parts['body'], true);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => $activity['id'],
            'is_shared' => true,
        ]);
    }

    public function test_a_duplicate_activity_is_accepted_but_not_stored_twice(): void
    {
        $target = $this->createFullAccount('duplicato');
        $parts = $this->buildSignedFollowActivity('/users/duplicato/inbox', $target->actor->uri);

        $this->postSigned($parts)->assertStatus(202);
        $this->postSigned($parts)->assertStatus(202);

        $activity = json_decode($parts['body'], true);
        $this->assertSame(1, InboxItem::query()->where('remote_activity_uri', $activity['id'])->count());
    }

    public function test_a_request_without_a_signature_header_is_rejected(): void
    {
        $target = $this->createFullAccount('nofirma');
        $parts = $this->buildSignedFollowActivity('/users/nofirma/inbox', $target->actor->uri);

        $response = $this->call('POST', $parts['path'], [], [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
            'HTTP_DATE' => $parts['date'],
            'HTTP_DIGEST' => $parts['digest'],
        ], $parts['body']);

        $response->assertStatus(401);
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_a_self_delete_for_an_unknown_remote_actor_is_an_idempotent_no_op(): void
    {
        $target = $this->createFullAccount('deleteassente');
        $actorUri = 'https://assente.example/users/nessuno';
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorUri.'#delete',
            'type' => 'Delete',
            'actor' => $actorUri,
            'object' => ['id' => $actorUri, 'type' => 'Tombstone'],
        ];

        $response = $this->call('POST', '/users/'.$target->username.'/inbox', [], [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
        ], json_encode($activity, JSON_THROW_ON_ERROR));

        $response->assertStatus(202);
        $this->assertDatabaseCount('inbox_items', 0);
        Http::assertNothingSent();
    }

    public function test_a_delete_for_an_unknown_post_or_comment_is_an_idempotent_no_op(): void
    {
        $target = $this->createFullAccount('deletecontenutoassente');
        $actorUri = 'https://remoto.example/users/assente';
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorUri.'/activities/delete-1',
            'type' => 'Delete',
            'actor' => $actorUri,
            'object' => ['id' => $actorUri.'/posts/inesistente', 'type' => 'Tombstone'],
        ];

        $response = $this->call('POST', '/users/'.$target->username.'/inbox', [], [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
        ], json_encode($activity, JSON_THROW_ON_ERROR));

        $response->assertStatus(202);
        $this->assertDatabaseCount('inbox_items', 0);
        Http::assertNothingSent();
    }

    public function test_a_delete_for_an_existing_post_still_requires_authentication(): void
    {
        $target = $this->createFullAccount('deletecontenutopresente');
        $remote = $this->createRemoteActor('autorecontenuto');
        $post = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => $remote->uri.'/posts/1',
            'body' => 'Contenuto presente.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $remote->uri.'/activities/delete-1',
            'type' => 'Delete',
            'actor' => $remote->uri,
            'object' => $post->uri,
        ];

        $response = $this->call('POST', '/users/'.$target->username.'/inbox', [], [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
        ], json_encode($activity, JSON_THROW_ON_ERROR));

        $response->assertStatus(401);
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertSame(Post::STATUS_PUBLISHED, $post->fresh()->status);
    }

    public function test_a_known_actor_self_delete_uses_its_stale_cached_key_without_refetching(): void
    {
        $target = $this->createFullAccount('deletecached');
        $remote = $this->createRemoteActor('cacheddelete');
        $remote->key->update(['public_key' => $this->remoteKeyPair->publicKey]);
        $remote->update(['last_fetched_at' => now()->subDays(2)]);
        Http::fake([$remote->uri => Http::response([], 410)]);

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $remote->uri.'#delete',
            'type' => 'Delete',
            'actor' => $remote->uri,
            'object' => $remote->uri,
        ];
        $parts = $this->signedActivityParts('/users/'.$target->username.'/inbox', $activity, $remote->uri, $this->remoteKeyPair);

        $this->postSigned($parts)->assertStatus(202);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => $activity['id'],
            'activity_type' => 'Delete',
            'actor_uri' => $remote->uri,
        ]);
        Http::assertNothingSent();
    }

    public function test_a_known_actor_self_delete_does_not_refetch_after_cached_key_verification_fails(): void
    {
        $target = $this->createFullAccount('deletebadkey');
        $remote = $this->createRemoteActor('badkeydelete');
        $remote->update(['last_fetched_at' => now()->subDays(2)]);
        Http::fake([$remote->uri => Http::response([], 410)]);
        $wrongKey = (new RsaKeyPairGenerator)->generate(2048);
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $remote->uri.'#delete',
            'type' => 'Delete',
            'actor' => $remote->uri,
            'object' => $remote->uri,
        ];
        $parts = $this->signedActivityParts('/users/'.$target->username.'/inbox', $activity, $remote->uri, $wrongKey);

        $this->postSigned($parts)->assertStatus(401);
        $this->assertDatabaseCount('inbox_items', 0);
        Http::assertNothingSent();
    }

    public function test_a_known_actor_without_a_cached_key_cannot_use_the_idempotent_delete_no_op(): void
    {
        $target = $this->createFullAccount('deletenokey');
        $remote = $this->createRemoteActor('nokeydelete');
        $remote->key()->delete();
        Http::fake([$remote->uri => Http::response([], 410)]);
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $remote->uri.'#delete',
            'type' => 'Delete',
            'actor' => $remote->uri,
            'object' => $remote->uri,
        ];
        $parts = $this->signedActivityParts('/users/'.$target->username.'/inbox', $activity, $remote->uri, $this->remoteKeyPair);

        $this->postSigned($parts)->assertStatus(401);
        $this->assertDatabaseCount('inbox_items', 0);
        Http::assertNothingSent();
    }

    public function test_a_forwarded_actor_delete_can_fetch_the_transport_signer_and_use_the_deleted_actors_cached_ld_key(): void
    {
        $target = $this->createFullAccount('deleteforwarded');
        $deletedActorUri = 'https://commentatore.example/users/alice';
        $deletedActorKey = (new RsaKeyPairGenerator)->generate(2048);
        $deletedActor = Actor::query()->create([
            'type' => Actor::TYPE_PERSON,
            'is_local' => false,
            'preferred_username' => 'alice',
            'domain' => 'commentatore.example',
            'uri' => $deletedActorUri,
            'status' => Actor::STATUS_ACTIVE,
            'last_fetched_at' => now()->subDays(2),
        ]);
        ActorKey::query()->create([
            'actor_id' => $deletedActor->id,
            'public_key' => $deletedActorKey->publicKey,
            'private_key' => $deletedActorKey->privateKey,
        ]);
        $deletedActor->load('key');

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $deletedActorUri.'#delete',
            'type' => 'Delete',
            'actor' => $deletedActorUri,
            'object' => ['id' => $deletedActorUri, 'type' => 'Tombstone'],
        ];
        $signedActivity = app(LinkedDataSignature::class)
            ->sign($activity, $deletedActor);

        Http::fake([
            self::REMOTE_ACTOR_URI => Http::response([
                'id' => self::REMOTE_ACTOR_URI,
                'type' => 'Person',
                'preferredUsername' => 'carol',
                'inbox' => self::REMOTE_ACTOR_URI.'/inbox',
                'outbox' => self::REMOTE_ACTOR_URI.'/outbox',
                'followers' => self::REMOTE_ACTOR_URI.'/followers',
                'following' => self::REMOTE_ACTOR_URI.'/following',
                'publicKey' => [
                    'id' => self::REMOTE_ACTOR_URI.'#main-key',
                    'owner' => self::REMOTE_ACTOR_URI,
                    'publicKeyPem' => $this->remoteKeyPair->publicKey,
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $deletedActorUri => Http::response([], 410),
        ]);
        $parts = $this->signedActivityParts(
            '/users/'.$target->username.'/inbox',
            $signedActivity,
            self::REMOTE_ACTOR_URI,
            $this->remoteKeyPair,
        );

        $this->postSigned($parts)->assertStatus(202);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => $activity['id'],
            'activity_type' => 'Delete',
            'actor_uri' => $deletedActorUri,
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === self::REMOTE_ACTOR_URI);
        Http::assertNotSent(fn ($request): bool => $request->url() === $deletedActorUri);
    }

    public function test_a_tampered_body_fails_the_digest_check(): void
    {
        $target = $this->createFullAccount('manomesso');
        $parts = $this->buildSignedFollowActivity('/users/manomesso/inbox', $target->actor->uri);
        $parts['body'] = str_replace('Follow', 'Undo', $parts['body']);

        $response = $this->postSigned($parts);

        $response->assertStatus(401);
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_an_activity_whose_actor_field_does_not_match_the_signer_is_rejected(): void
    {
        $target = $this->createFullAccount('spoofato');
        // Actor dichiarato su un altro host: niente same-origin refetch.
        $parts = $this->buildSignedFollowActivity(
            '/users/spoofato/inbox',
            $target->actor->uri,
            claimedActor: 'https://altro.example/users/qualcunaltro'
        );

        $response = $this->postSigned($parts);

        $response->assertStatus(401);
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_an_equivalent_actor_uri_variant_is_treated_as_the_http_signer(): void
    {
        $target = $this->createFullAccount('urivariante');
        $parts = $this->buildSignedFollowActivity(
            '/users/urivariante/inbox',
            $target->actor->uri,
            claimedActor: self::REMOTE_ACTOR_URI.'/',
        );

        $this->postSigned($parts)->assertStatus(202);
        $activity = json_decode($parts['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => $activity['id'],
            'actor_uri' => self::REMOTE_ACTOR_URI,
            'signature_valid' => true,
        ]);
    }

    public function test_a_forwarded_create_with_ld_signature_is_accepted_without_origin_fetch(): void
    {
        $forwarderUri = self::REMOTE_ACTOR_URI;
        $commenterUri = 'https://commentatore.example/users/alice';
        $commenterKey = (new RsaKeyPairGenerator)->generate(2048);

        // Commentatore gia' in cache (come dopo un Follow o un Create precedente).
        $commenter = Actor::query()->create([
            'type' => Actor::TYPE_PERSON,
            'is_local' => false,
            'preferred_username' => 'alice',
            'domain' => 'commentatore.example',
            'uri' => $commenterUri,
            'name' => 'Alice',
            'status' => Actor::STATUS_ACTIVE,
            'last_fetched_at' => now(),
        ]);
        ActorKey::query()->create([
            'actor_id' => $commenter->id,
            'public_key' => $commenterKey->publicKey,
            'private_key' => $commenterKey->privateKey,
        ]);
        $commenter->load('key');

        $activityId = $commenterUri.'/statuses/77/activity';
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => 'Create',
            'actor' => $commenterUri,
            'object' => [
                'id' => $commenterUri.'/statuses/77',
                'type' => 'Note',
                'attributedTo' => $commenterUri,
                'content' => '<p>Con LD Signature</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];

        $signed = app(LinkedDataSignature::class)
            ->sign($activity, $commenter);

        Http::fake([
            $forwarderUri => Http::response([
                'id' => $forwarderUri,
                'type' => 'Person',
                'preferredUsername' => 'carol',
                'inbox' => $forwarderUri.'/inbox',
                'outbox' => $forwarderUri.'/outbox',
                'followers' => $forwarderUri.'/followers',
                'following' => $forwarderUri.'/following',
                'publicKey' => [
                    'id' => $forwarderUri.'#main-key',
                    'owner' => $forwarderUri,
                    'publicKeyPem' => $this->remoteKeyPair->publicKey,
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            // Nessun fetch dell'attivita': se LD fallisce, il test fallisce.
        ]);

        $target = $this->createFullAccount('seguaceld');
        $path = '/users/seguaceld/inbox';
        $body = json_encode($signed, JSON_THROW_ON_ERROR);
        $date = now()->toRfc7231String();
        $digest = HttpSignatureSigner::digest($body);
        $host = parse_url(url('/'), PHP_URL_HOST);
        $signature = (new HttpSignatureSigner)->sign(
            'POST',
            $path,
            ['host' => $host, 'date' => $date, 'digest' => $digest],
            $forwarderUri.'#main-key',
            $this->remoteKeyPair->privateKey,
            ['(request-target)', 'host', 'date', 'digest']
        );

        $response = $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
            'HTTP_DATE' => $date,
            'HTTP_DIGEST' => $digest,
            'HTTP_SIGNATURE' => $signature,
        ], $body);

        $response->assertStatus(202);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => $activityId,
            'actor_uri' => $commenterUri,
            'activity_type' => 'Create',
        ]);
        Http::assertNotSent(fn ($request) => $request->url() === $activityId);
    }

    public function test_a_forwarded_create_is_accepted_after_same_origin_refetch(): void
    {
        // Inbox forwarding: HTTP firmata dal forwarder, activity.actor = commentatore.
        // Autenticazione via GET dell'id attivita' sullo stesso host dell'actor.
        $forwarderUri = self::REMOTE_ACTOR_URI;
        $commenterUri = 'https://commentatore.example/users/alice';
        $activityId = $commenterUri.'/statuses/42/activity';
        $noteId = $commenterUri.'/statuses/42';

        $originActivity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => 'Create',
            'actor' => $commenterUri,
            'object' => [
                'id' => $noteId,
                'type' => 'Note',
                'attributedTo' => $commenterUri,
                'content' => '<p>Risposta inoltrata</p>',
                'inReplyTo' => 'https://remoto.example/users/carol/statuses/1',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];

        Http::fake([
            $forwarderUri => Http::response([
                'id' => $forwarderUri,
                'type' => 'Person',
                'preferredUsername' => 'carol',
                'inbox' => $forwarderUri.'/inbox',
                'outbox' => $forwarderUri.'/outbox',
                'followers' => $forwarderUri.'/followers',
                'following' => $forwarderUri.'/following',
                'publicKey' => [
                    'id' => $forwarderUri.'#main-key',
                    'owner' => $forwarderUri,
                    'publicKeyPem' => $this->remoteKeyPair->publicKey,
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $activityId => Http::response($originActivity, 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $target = $this->createFullAccount('seguacefwd');
        $path = '/users/seguacefwd/inbox';

        // Payload consegnato (puo' differire leggermente): actor = commentatore, firma = forwarder.
        $delivered = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => 'Create',
            'actor' => $commenterUri,
            'object' => $noteId,
        ];
        $body = json_encode($delivered, JSON_THROW_ON_ERROR);
        $date = now()->toRfc7231String();
        $digest = HttpSignatureSigner::digest($body);
        $host = parse_url(url('/'), PHP_URL_HOST);

        $signature = (new HttpSignatureSigner)->sign(
            'POST',
            $path,
            ['host' => $host, 'date' => $date, 'digest' => $digest],
            $forwarderUri.'#main-key',
            $this->remoteKeyPair->privateKey,
            ['(request-target)', 'host', 'date', 'digest']
        );

        $response = $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
            'HTTP_DATE' => $date,
            'HTTP_DIGEST' => $digest,
            'HTTP_SIGNATURE' => $signature,
        ], $body);

        $response->assertStatus(202);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => $activityId,
            'activity_type' => 'Create',
            'actor_uri' => $commenterUri,
            'status' => InboxItem::STATUS_PENDING,
            'signature_valid' => true,
        ]);

        $item = InboxItem::query()->where('remote_activity_uri', $activityId)->first();
        $this->assertNotNull($item);
        $payload = json_decode((string) $item->payload, true);
        $this->assertIsArray($payload);
        $this->assertSame('Create', $payload['type']);
        $this->assertIsArray($payload['object'] ?? null);
        $this->assertSame($noteId, $payload['object']['id']);
    }

    public function test_a_forwarded_activity_is_rejected_when_origin_actor_does_not_match(): void
    {
        $forwarderUri = self::REMOTE_ACTOR_URI;
        $claimedActor = 'https://commentatore.example/users/alice';
        $activityId = $claimedActor.'/statuses/99/activity';

        Http::fake([
            $forwarderUri => Http::response([
                'id' => $forwarderUri,
                'type' => 'Person',
                'preferredUsername' => 'carol',
                'inbox' => $forwarderUri.'/inbox',
                'outbox' => $forwarderUri.'/outbox',
                'followers' => $forwarderUri.'/followers',
                'following' => $forwarderUri.'/following',
                'publicKey' => [
                    'id' => $forwarderUri.'#main-key',
                    'owner' => $forwarderUri,
                    'publicKeyPem' => $this->remoteKeyPair->publicKey,
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
            $activityId => Http::response([
                'id' => $activityId,
                'type' => 'Create',
                'actor' => 'https://commentatore.example/users/impostore',
                'object' => $claimedActor.'/statuses/99',
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $target = $this->createFullAccount('seguacereject');
        $path = '/users/seguacereject/inbox';
        $delivered = [
            'id' => $activityId,
            'type' => 'Create',
            'actor' => $claimedActor,
            'object' => $claimedActor.'/statuses/99',
        ];
        $body = json_encode($delivered, JSON_THROW_ON_ERROR);
        $date = now()->toRfc7231String();
        $digest = HttpSignatureSigner::digest($body);
        $host = parse_url(url('/'), PHP_URL_HOST);
        $signature = (new HttpSignatureSigner)->sign(
            'POST',
            $path,
            ['host' => $host, 'date' => $date, 'digest' => $digest],
            $forwarderUri.'#main-key',
            $this->remoteKeyPair->privateKey,
            ['(request-target)', 'host', 'date', 'digest']
        );

        $response = $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
            'HTTP_DATE' => $date,
            'HTTP_DIGEST' => $digest,
            'HTTP_SIGNATURE' => $signature,
        ], $body);

        $response->assertStatus(401);
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_an_unsupported_content_type_is_rejected(): void
    {
        $target = $this->createFullAccount('contenttype');
        $parts = $this->buildSignedFollowActivity('/users/contenttype/inbox', $target->actor->uri);

        $response = $this->postSigned($parts, ['CONTENT_TYPE' => 'application/json']);

        $response->assertStatus(415);
    }

    public function test_an_oversized_body_is_rejected(): void
    {
        config(['openbook.federation.inbox.max_body_bytes' => 10]);

        $target = $this->createFullAccount('troppograndetest');
        $parts = $this->buildSignedFollowActivity('/users/troppograndetest/inbox', $target->actor->uri);

        $response = $this->postSigned($parts);

        $response->assertStatus(413);
    }

    public function test_an_unknown_local_user_inbox_returns_not_found(): void
    {
        $parts = $this->buildSignedFollowActivity('/users/nessuno/inbox', 'https://irrilevante.example/@x');

        $this->postSigned($parts)->assertStatus(404);
    }

    public function test_a_cavage_signature_with_standalone_publickey_key_id_is_accepted(): void
    {
        // tags.pub / activitypub-bot: keyId = …/publickey (CryptographicKey).
        $keyId = self::REMOTE_ACTOR_URI.'/publickey';

        Http::fake([
            self::REMOTE_ACTOR_URI => Http::response([
                'id' => self::REMOTE_ACTOR_URI,
                'type' => 'Person',
                'preferredUsername' => 'carol',
                'inbox' => self::REMOTE_ACTOR_URI.'/inbox',
                'outbox' => self::REMOTE_ACTOR_URI.'/outbox',
                'followers' => self::REMOTE_ACTOR_URI.'/followers',
                'following' => self::REMOTE_ACTOR_URI.'/following',
                'publicKey' => [
                    'id' => $keyId,
                    'owner' => self::REMOTE_ACTOR_URI,
                    'publicKeyPem' => $this->remoteKeyPair->publicKey,
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        // Come dopo un Follow: Actor gia' in cache con la PEM.
        $this->assertNotNull(app(RemoteActorResolver::class)->resolveByUri(self::REMOTE_ACTOR_URI));

        $target = $this->createFullAccount('tagskey');
        $path = '/users/tagskey/inbox';
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Follow',
            'actor' => self::REMOTE_ACTOR_URI,
            'object' => $target->actor->uri,
        ];
        $body = json_encode($activity, JSON_THROW_ON_ERROR);
        $date = now()->toRfc7231String();
        $digest = HttpSignatureSigner::digest($body);
        $host = parse_url(url('/'), PHP_URL_HOST);

        $signature = (new HttpSignatureSigner)->sign(
            'POST',
            $path,
            ['host' => $host, 'date' => $date, 'digest' => $digest],
            $keyId,
            $this->remoteKeyPair->privateKey,
            ['(request-target)', 'host', 'date', 'digest']
        );

        $response = $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/activity+json',
            'HTTP_DATE' => $date,
            'HTTP_DIGEST' => $digest,
            'HTTP_SIGNATURE' => $signature,
        ], $body);

        $response->assertStatus(202);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => $activity['id'],
            'signature_valid' => true,
        ]);
    }

    public function test_an_rfc_9421_signature_is_accepted(): void
    {
        $this->assertRfc9421Accepted(
            username: 'rfc9421',
            withAlg: true,
            spaceAfterSemicolon: false,
        );
    }

    public function test_a_mastodon_style_rfc_9421_signature_without_alg_is_accepted(): void
    {
        // Mastodon 4.5+: Signature-Input senza alg, spesso con `; created=`.
        $this->assertRfc9421Accepted(
            username: 'masto9421',
            withAlg: false,
            spaceAfterSemicolon: true,
        );
    }

    private function assertRfc9421Accepted(string $username, bool $withAlg, bool $spaceAfterSemicolon): void
    {
        $target = $this->createFullAccount($username);
        $path = '/users/'.$username.'/inbox';
        $fullUrl = url($path);
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => 'https://remoto.example/activities/'.uniqid(),
            'type' => 'Follow',
            'actor' => self::REMOTE_ACTOR_URI,
            'object' => $target->actor->uri,
        ];
        $body = json_encode($activity, JSON_THROW_ON_ERROR);
        $date = now()->toRfc7231String();
        $contentDigest = 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':';
        $host = (string) parse_url(url('/'), PHP_URL_HOST);
        $contentType = 'application/activity+json';
        $keyId = self::REMOTE_ACTOR_URI.'#main-key';
        $created = time();

        $components = ['@method', '@target-uri', 'content-digest'];
        $componentList = implode(' ', array_map(static fn (string $c): string => '"'.$c.'"', $components));
        $sep = $spaceAfterSemicolon ? '; ' : ';';
        $attrStr = "({$componentList}){$sep}created={$created}{$sep}keyid=\"{$keyId}\"";
        if ($withAlg) {
            $attrStr .= $sep.'alg="rsa-v1_5-sha256"';
        }

        $pairs = [
            '"@method": POST',
            '"@target-uri": '.$fullUrl,
            '"content-digest": '.$contentDigest,
            '"@signature-params": '.$attrStr,
        ];
        $signingString = implode("\n", $pairs);

        $ok = openssl_sign($signingString, $signatureBinary, $this->remoteKeyPair->privateKey, OPENSSL_ALGO_SHA256);
        $this->assertTrue($ok);

        $response = $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_DATE' => $date,
            'HTTP_CONTENT_DIGEST' => $contentDigest,
            'HTTP_SIGNATURE_INPUT' => 'sig1='.$attrStr,
            'HTTP_SIGNATURE' => 'sig1=:'.base64_encode($signatureBinary).':',
        ], $body);

        $response->assertStatus(202);
        $this->assertDatabaseHas('inbox_items', [
            'remote_activity_uri' => $activity['id'],
            'signature_valid' => true,
        ]);
    }

    public function test_a_cavage_digest_with_lowercase_algorithm_is_accepted(): void
    {
        $target = $this->createFullAccount('digestcase');
        $parts = $this->buildSignedFollowActivity('/users/digestcase/inbox', $target->actor->uri);
        // activitypub-bot Digester.equals e' case-insensitive sull'algoritmo.
        $parts['digest'] = preg_replace('/^SHA-256=/', 'sha-256=', $parts['digest']) ?? $parts['digest'];

        // La firma e' stata calcolata sul Digest originale SHA-256=…: aggiorna
        // anche la stringa firmata ricostruendo la Signature con lo stesso valore
        // inviato (come farebbe un peer che manda sha-256= minuscolo ovunque).
        $host = parse_url(url('/'), PHP_URL_HOST);
        $parts['signature'] = (new HttpSignatureSigner)->sign(
            'POST',
            $parts['path'],
            ['host' => $host, 'date' => $parts['date'], 'digest' => $parts['digest']],
            self::REMOTE_ACTOR_URI.'#main-key',
            $this->remoteKeyPair->privateKey,
            ['(request-target)', 'host', 'date', 'digest']
        );

        $this->postSigned($parts)->assertStatus(202);
    }
}
