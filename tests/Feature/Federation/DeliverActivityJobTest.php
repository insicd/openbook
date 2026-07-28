<?php

namespace Tests\Feature\Federation;

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
}
