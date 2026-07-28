<?php

namespace Tests\Feature\Federation;

use App\Federation\Inbox\InboxItem;
use App\Infrastructure\Security\HttpSignatureSigner;
use App\Infrastructure\Security\KeyPair;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAccounts;
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
    use CreatesAccounts, RefreshDatabase;

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
        $parts = $this->buildSignedFollowActivity(
            '/users/spoofato/inbox',
            $target->actor->uri,
            claimedActor: 'https://remoto.example/users/qualcunaltro'
        );

        $response = $this->postSigned($parts);

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
}
