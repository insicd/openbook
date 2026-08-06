<?php

namespace Tests\Unit\Infrastructure\Security\LinkedData;

use App\Infrastructure\Security\LinkedData\LinkedDataSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class LinkedDataSignatureTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_sign_and_verify_round_trip(): void
    {
        $user = $this->createFullAccount('ldsigner');
        $actor = $user->actor->load('key');

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actor->activityPubId().'/statuses/1/attivita',
            'type' => 'Create',
            'actor' => $actor->activityPubId(),
            'object' => [
                'id' => $actor->activityPubId().'/statuses/1',
                'type' => 'Note',
                'attributedTo' => $actor->activityPubId(),
                'content' => '<p>Ciao LD</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];

        $signed = app(LinkedDataSignature::class)->sign($activity, $actor);

        $this->assertArrayHasKey('signature', $signed);
        $this->assertSame('RsaSignature2017', $signed['signature']['type']);
        $this->assertSame($actor->activityPubId().'#main-key', $signed['signature']['creator']);
        $this->assertNotEmpty($signed['signature']['signatureValue']);

        $verified = app(LinkedDataSignature::class)->verifyActor($signed, $actor->activityPubId());

        $this->assertNotNull($verified);
        $this->assertSame($actor->id, $verified->id);
    }

    public function test_tampered_activity_fails_verification(): void
    {
        $user = $this->createFullAccount('ldtamper');
        $actor = $user->actor->load('key');

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actor->activityPubId().'/statuses/2/attivita',
            'type' => 'Create',
            'actor' => $actor->activityPubId(),
            'object' => [
                'id' => $actor->activityPubId().'/statuses/2',
                'type' => 'Note',
                'content' => '<p>Originale</p>',
            ],
        ];

        $signed = app(LinkedDataSignature::class)->sign($activity, $actor);
        $signed['object']['content'] = '<p>Manomesso</p>';

        $this->assertNull(app(LinkedDataSignature::class)->verifyActor($signed, $actor->activityPubId()));
    }
}
