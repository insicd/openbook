<?php

namespace Tests\Feature\Federation;

use App\Domain\Federation\Relay;
use App\Domain\Moderation\DomainBlock;
use App\Infrastructure\Security\HttpSignatureSigner;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

/**
 * Verifica che la consegna di una singola attivita' (Fase 4) firmi
 * correttamente la richiesta con la chiave privata dell'Actor locale
 * mittente e fallisca in modo definitivo (senza ritentare) quando quella
 * chiave non e' disponibile.
 */
class DeliverActivityJobTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_it_signs_and_delivers_the_activity_to_the_remote_inbox(): void
    {
        $sender = $this->createFullAccount('firmatario');
        $inboxUrl = 'https://destinazione.example/users/bob/inbox';

        Http::fake([$inboxUrl => Http::response('', 202)]);

        $activity = ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => 'https://example.test/x', 'type' => 'Follow'];

        $job = new DeliverActivityJob($inboxUrl, $activity, $sender->actor->id);
        app()->call([$job, 'handle']);

        Http::assertSent(function (Request $request) use ($inboxUrl, $sender, $activity): bool {
            if ($request->url() !== $inboxUrl || $request->method() !== 'POST') {
                return false;
            }

            if (json_decode($request->body(), true) !== $activity) {
                return false;
            }

            $signatureHeader = $request->header('Signature')[0] ?? '';
            preg_match('/keyId="([^"]+)"/', $signatureHeader, $keyMatch);
            preg_match('/signature="([^"]+)"/', $signatureHeader, $sigMatch);

            if (($keyMatch[1] ?? null) !== $sender->actor->uri.'#main-key') {
                return false;
            }

            $signingString = HttpSignatureSigner::buildSigningString('POST', '/users/bob/inbox', [
                'host' => 'destinazione.example',
                'date' => $request->header('Date')[0] ?? '',
                'digest' => $request->header('Digest')[0] ?? '',
            ], ['(request-target)', 'host', 'date', 'digest']);

            $signatureBinary = base64_decode($sigMatch[1] ?? '', true);

            return $signatureBinary !== false
                && openssl_verify($signingString, $signatureBinary, $sender->actor->key->public_key, OPENSSL_ALGO_SHA256) === 1;
        });
    }

    public function test_it_fails_permanently_when_the_local_actor_has_no_private_key(): void
    {
        $sender = $this->createFullAccount('senzachiaveprivata');
        $sender->actor->key()->delete();

        Http::fake();

        $job = new DeliverActivityJob('https://destinazione.example/inbox', ['type' => 'Follow'], $sender->actor->id);

        // fail() e' un no-op fuori da un vero worker (nessun job sottostante
        // impostato): l'assenza di eccezioni propagate e' gia' la prova che
        // il fallimento e' gestito internamente, senza ritentare la consegna.
        app()->call([$job, 'handle']);

        Http::assertNothingSent();
    }

    public function test_a_non_successful_response_throws_so_the_queue_retries(): void
    {
        $sender = $this->createFullAccount('rifiutatoallaconsegna');
        $inboxUrl = 'https://destinazione.example/inbox-rifiutato';

        Http::fake([$inboxUrl => Http::response('nope', 500)]);

        $job = new DeliverActivityJob($inboxUrl, ['type' => 'Follow'], $sender->actor->id);

        $this->expectException(\RuntimeException::class);
        app()->call([$job, 'handle']);
    }

    public function test_successful_relay_delivery_updates_its_diagnostics(): void
    {
        $sender = $this->createFullAccount('relayfirmatario');
        $relay = $this->relay('relay-success.example', Relay::STATE_PENDING);

        Http::fake([$relay->inbox_url => Http::response('', 202)]);

        $job = new DeliverActivityJob(
            $relay->inbox_url,
            ['type' => 'Follow'],
            $sender->actor->id,
            $relay->id,
        );
        app()->call([$job, 'handle']);

        $relay->refresh();
        $this->assertSame(Relay::STATE_PENDING, $relay->state);
        $this->assertNotNull($relay->last_success_at);
        $this->assertNull($relay->last_failure_at);
        $this->assertNull($relay->last_error);
    }

    public function test_exhausted_relay_follow_delivery_marks_the_subscription_as_failed(): void
    {
        $sender = $this->createFullAccount('relayfallito');
        $relay = $this->relay('relay-failed.example', Relay::STATE_PENDING);
        $job = new DeliverActivityJob(
            $relay->inbox_url,
            ['type' => 'Follow'],
            $sender->actor->id,
            $relay->id,
        );

        $job->failed(new \RuntimeException('Relay non raggiungibile'));

        $relay->refresh();
        $this->assertSame(Relay::STATE_FAILED, $relay->state);
        $this->assertNotNull($relay->last_failure_at);
        $this->assertSame('Relay non raggiungibile', $relay->last_error);
    }

    public function test_queued_content_is_skipped_when_the_relay_is_no_longer_publishable(): void
    {
        $sender = $this->createFullAccount('relayrevocato');
        Http::fake();

        foreach (['Create', 'Announce'] as $activityType) {
            foreach ([
                [Relay::STATE_IDLE, true],
                [Relay::STATE_ACCEPTED, false],
            ] as [$state, $publishEnabled]) {
                $relay = $this->relay(
                    'relay-'.strtolower($activityType).'-'.strtolower($state).'-'.(int) $publishEnabled.'.example',
                    $state,
                );
                $relay->update(['publish_enabled' => $publishEnabled]);

                $job = new DeliverActivityJob(
                    $relay->inbox_url,
                    ['type' => $activityType, 'id' => 'https://local.example/activities/'.$relay->id],
                    $sender->actor->id,
                    $relay->id,
                );
                app()->call([$job, 'handle']);
            }
        }

        Http::assertNothingSent();
    }

    public function test_queued_content_is_skipped_when_the_relay_was_removed(): void
    {
        $sender = $this->createFullAccount('relayrimosso');
        $relay = $this->relay('relay-removed.example', Relay::STATE_ACCEPTED);
        $relayId = $relay->id;
        $inboxUrl = $relay->inbox_url;
        $relay->delete();
        Http::fake();

        $job = new DeliverActivityJob(
            $inboxUrl,
            ['type' => 'Announce', 'id' => 'https://local.example/activities/removed'],
            $sender->actor->id,
            $relayId,
        );
        app()->call([$job, 'handle']);

        Http::assertNothingSent();
    }

    public function test_queued_relay_delivery_is_skipped_when_its_domain_is_blocked(): void
    {
        $sender = $this->createFullAccount('relaybloccato');
        $relay = $this->relay('relay-blocked.example', Relay::STATE_ACCEPTED);
        DomainBlock::query()->create(['domain' => 'relay-blocked.example']);
        Http::fake();

        $job = new DeliverActivityJob(
            $relay->inbox_url,
            ['type' => 'Create', 'id' => 'https://local.example/activities/blocked'],
            $sender->actor->id,
            $relay->id,
        );
        app()->call([$job, 'handle']);

        Http::assertNothingSent();
    }

    public function test_unsubscribe_can_still_run_after_the_relay_becomes_idle(): void
    {
        $sender = $this->createFullAccount('relayunsubscribe');
        $relay = $this->relay('relay-undo.example', Relay::STATE_IDLE);
        Http::fake([$relay->inbox_url => Http::response('', 202)]);

        $job = new DeliverActivityJob(
            $relay->inbox_url,
            ['type' => 'Undo', 'id' => 'https://local.example/activities/undo'],
            $sender->actor->id,
            $relay->id,
        );
        app()->call([$job, 'handle']);

        Http::assertSentCount(1);
    }

    private function relay(string $host, string $state): Relay
    {
        $url = 'https://'.$host.'/inbox';

        return Relay::query()->create([
            'protocol' => Relay::PROTOCOL_MASTODON,
            'inbox_url' => $url,
            'inbox_url_hash' => hash('sha256', $url),
            'state' => $state,
            'receive_enabled' => true,
            'publish_enabled' => true,
        ]);
    }
}
