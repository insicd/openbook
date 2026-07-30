<?php

namespace App\Http\Controllers\Federation;

use App\Application\Services\DomainBlockManager;
use App\Domain\Accounts\User;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxItem;
use App\Http\Controllers\Controller;
use App\Infrastructure\Security\HttpSignatureVerifier;
use App\Jobs\Federation\ProcessInboxActivityJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Inbox per-utente e condiviso (sezione 20 del design). Autentica, valida
 * nella forma minima, deduplica e memorizza l'attivita' grezza, poi accoda
 * la sua elaborazione semantica (nuovi follow/like/condivisioni/contenuti)
 * su una coda separata ({@see ProcessInboxActivityJob}), cosi' da non
 * eseguire elaborazioni pesanti (e potenzialmente lente, se serve risolvere
 * un Actor remoto sconosciuto) nel ciclo di risposta HTTP.
 */
final class InboxController extends Controller
{
    public function __construct(
        private readonly HttpSignatureVerifier $verifier,
        private readonly DomainBlockManager $domainBlocks,
    ) {}

    public function forUser(Request $request, string $username): JsonResponse
    {
        $user = User::query()->where('username', mb_strtolower($username))->with('actor')->first();

        if ($user === null || $user->actor === null) {
            abort(404);
        }

        return $this->receive($request, $user->actor);
    }

    public function shared(Request $request): JsonResponse
    {
        return $this->receive($request, null);
    }

    private function receive(Request $request, ?Actor $targetActor): JsonResponse
    {
        $body = (string) $request->getContent();
        $maxBodyBytes = (int) config('openbook.federation.inbox.max_body_bytes', 200_000);

        if (strlen($body) > $maxBodyBytes) {
            return response()->json(['error' => 'Payload troppo grande.'], 413);
        }

        $contentType = (string) $request->header('Content-Type', '');

        if (! str_contains($contentType, 'application/activity+json') && ! str_contains($contentType, 'application/ld+json')) {
            return response()->json(['error' => 'Content-Type non supportato.'], 415);
        }

        $maxDepth = (int) config('openbook.federation.inbox.max_json_depth', 32);

        try {
            $activity = json_decode($body, true, $maxDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'JSON non valido.'], 400);
        }

        if (! is_array($activity)
            || ! isset($activity['id'], $activity['type'], $activity['actor'])
            || ! is_string($activity['id'])
            || ! is_string($activity['type'])) {
            return response()->json(['error' => "Attivita' non valida."], 400);
        }

        $actorUri = is_string($activity['actor'])
            ? $activity['actor']
            : ($activity['actor']['id'] ?? null);

        if (! is_string($actorUri) || $actorUri === '') {
            return response()->json(['error' => 'Campo "actor" mancante o non valido.'], 400);
        }

        if ($this->domainBlocks->isBlockedUrl($actorUri)) {
            Log::channel('single')->info('federation.inbox.domain_blocked', [
                'actor_uri' => $actorUri,
                'activity_id' => $activity['id'],
            ]);

            return response()->json(['error' => 'Dominio bloccato.'], 403);
        }

        $verification = $this->verifier->verify($request);

        if (! $verification->valid) {
            Log::channel('single')->info('federation.inbox.rejected', [
                'reason' => $verification->error,
                'activity_id' => $activity['id'],
            ]);

            return response()->json(['error' => 'Firma non valida.'], 401);
        }

        // L'attore che ha firmato la richiesta deve coincidere con quello
        // dichiarato nel campo "actor" dell'attivita': impedisce a un Actor
        // di firmare validamente un'attivita' spacciandosi per un altro.
        if ($verification->actor === null || $verification->actor->uri !== $actorUri) {
            Log::channel('single')->info('federation.inbox.actor_mismatch', [
                'signed_by' => $verification->actor?->uri,
                'claimed_actor' => $actorUri,
            ]);

            return response()->json(['error' => 'Attore firmatario non corrispondente.'], 401);
        }

        if (InboxItem::query()->where('remote_activity_uri', $activity['id'])->exists()) {
            // Idempotente: una ri-consegna della stessa attivita' non e' un errore.
            return response()->json(['status' => 'accepted'], 202);
        }

        try {
            $item = InboxItem::query()->create([
                'target_actor_id' => $targetActor?->id,
                'is_shared' => $targetActor === null,
                'remote_activity_uri' => $activity['id'],
                'activity_type' => $activity['type'],
                'actor_uri' => $actorUri,
                'payload' => $body,
                'signature_key_id' => $verification->keyId,
                'signature_valid' => true,
                'status' => InboxItem::STATUS_PENDING,
                'received_at' => now(),
            ]);

            ProcessInboxActivityJob::dispatch($item->id)->afterCommit();
        } catch (UniqueConstraintViolationException) {
            // Corsa tra due consegne concorrenti della stessa attivita': il
            // vincolo univoco sul database resta la difesa ultima.
        }

        return response()->json(['status' => 'accepted'], 202);
    }
}
