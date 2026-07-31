<?php

namespace Tests\Feature\Federation;

use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\ActivitySerializer;
use App\Infrastructure\Security\HttpSignatureSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ActorContentNegotiationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_a_normal_browser_request_still_receives_html(): void
    {
        $this->createFullAccount('htmluser');

        $response = $this->get('/@htmluser');

        $response->assertOk();
        $response->assertSee('htmluser');
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
    }

    public function test_an_activity_json_request_to_users_path_receives_the_person_document(): void
    {
        $user = $this->createFullAccount('apuser');

        $response = $this->get('/users/apuser', ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/activity+json; charset=utf-8');

        $response->assertJson([
            'id' => url('/users/apuser'),
            'type' => 'Person',
            'preferredUsername' => 'apuser',
            'url' => url('/@apuser'),
            'inbox' => url('/users/apuser/inbox'),
            'outbox' => url('/users/apuser/outbox'),
            'followers' => url('/users/apuser/followers'),
            'following' => url('/users/apuser/following'),
        ]);

        $response->assertJsonPath('publicKey.owner', url('/users/apuser'));
        $response->assertJsonPath('publicKey.id', url('/users/apuser').'#main-key');
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $response->json('publicKey.publicKeyPem'));
        $this->assertSame(url('/users/apuser'), $user->actor->fresh()->uri);
    }

    public function test_an_activity_json_request_to_at_path_redirects_to_users_path(): void
    {
        $this->createFullAccount('redirectap');

        $response = $this->get('/@redirectap', ['Accept' => 'application/activity+json']);

        $response->assertRedirect(url('/users/redirectap'));
    }

    public function test_a_ld_json_request_also_receives_the_person_document(): void
    {
        $this->createFullAccount('lduser');

        $response = $this->get('/users/lduser', ['Accept' => 'application/ld+json']);

        $response->assertOk();
        $response->assertJsonPath('type', 'Person');
        $response->assertJsonPath('id', url('/users/lduser'));
    }

    public function test_stale_endpoint_hosts_in_the_database_are_not_advertised(): void
    {
        $user = $this->createFullAccount('stalehost');
        $user->actor->endpoints->forceFill([
            'inbox' => 'https://old.example/users/stalehost/inbox',
            'outbox' => 'https://old.example/users/stalehost/outbox',
            'followers' => 'https://old.example/users/stalehost/followers',
            'following' => 'https://old.example/users/stalehost/following',
            'shared_inbox' => 'https://old.example/inbox',
        ])->saveQuietly();

        $response = $this->get('/users/stalehost', ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertJsonPath('inbox', url('/users/stalehost/inbox'));
        $response->assertJsonPath('endpoints.sharedInbox', url('/inbox'));
        $this->assertStringNotContainsString('old.example', (string) $response->getContent());
    }

    public function test_follow_and_signature_use_users_path_even_with_legacy_uri_in_database(): void
    {
        $user = $this->createFullAccount('legacyuri');
        $actor = $user->actor->load('key');
        $actor->forceFill(['uri' => url('/@legacyuri')])->saveQuietly();

        $this->assertSame(url('/users/legacyuri'), $actor->fresh()->activityPubId());

        $follow = new Follow([
            'id' => '00000000-0000-4000-8000-000000000001',
        ]);
        $follow->setRelation('follower', $actor->fresh());
        $follow->setRelation('following', new Actor([
            'uri' => 'https://lemmy.example/c/test',
            'is_local' => false,
            'preferred_username' => 'test',
            'domain' => 'lemmy.example',
            'type' => 'group',
        ]));

        $activity = ActivitySerializer::follow($follow);
        $this->assertSame(url('/users/legacyuri'), $activity['actor']);

        $headers = (new HttpSignatureSigner)->authorizationHeaders(
            'POST',
            'https://lemmy.example/inbox',
            $actor->fresh()->load('key'),
            '{}',
        );
        $this->assertStringContainsString('keyId="'.url('/users/legacyuri').'#main-key"', $headers['Signature']);
    }
}
