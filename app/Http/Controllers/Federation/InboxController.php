<?php

namespace App\Http\Controllers\Federation;

use App\Application\Services\DomainBlockManager;
use App\Federation\Actors\Actor;
use App\Federation\Actors\LocalActorResolver;
use App\Federation\Inbox\ForwardedActivityAuthenticator;
use App\Federation\Inbox\InboxItem;
use App\Federation\Resolution\ObjectResolver;
use App\Federation\Support\ActivityPubUri;
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
        private readonly LocalActorResolver $localActors,
        private readonly ForwardedActivityAuthenticator $forwardedActivities,
        private readonly ObjectResolver $objects,
    ) {}

    public function forUser(Request $request, string $username): JsonResponse
    {
        return $this->receive($request, $this->localActors->findByUsernameOrFail($username));
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

        $isActorSelfDelete = $this->isActorSelfDelete($activity, $actorUri);

        if ($isActorSelfDelete && ! $this->remoteActorExists($actorUri)) {
            // Idempotenza: cancellare un Actor remoto che non possediamo e'
            // gia' un successo e non richiede autenticazione o accodamento.
            return response()->json(['status' => 'accepted'], 202);
        }

        if (($activity['type'] ?? null) === 'Delete'
            && ! $isActorSelfDelete
            && ($objectId = $this->deleteObjectId($activity)) !== null
            && $this->objects->resolvePostOrComment($objectId) === null) {
            // Anche la cancellazione di un contenuto che non abbiamo piu'
            // e' gia' soddisfatta e non richiede autenticazione o coda.
            return response()->json(['status' => 'accepted'], 202);
        }

        $verification = $this->verifier->verify(
            $request,
            cachedOnlyActorUri: $isActorSelfDelete ? $actorUri : null,
        );

        if (! $verification->valid) {
            Log::channel('single')->info('federation.inbox.rejected', [
                'reason' => $verification->error,
                'activity_id' => $activity['id'],
            ]);

            return response()->json(['error' => 'Firma non valida.'], 401);
        }

        // Consegna diretta: firmatario HTTP = activity.actor.
        // Inbox forwarding: firma HTTP di un altro Actor + LD Signature
        // (Mastodon) oppure refetch same-origin (Primer ActivityPub).
        if ($verification->actor === null || ! ActivityPubUri::same($verification->actor->uri, $actorUri)) {
            $authenticated = $this->forwardedActivities->authenticate($activity, $actorUri);

            if ($authenticated === null) {
                Log::channel('single')->info('federation.inbox.actor_mismatch', [
                    'signed_by' => $verification->actor?->uri,
                    'claimed_actor' => $actorUri,
                    'activity_id' => $activity['id'],
                ]);

                return response()->json(['error' => 'Attore firmatario non corrispondente.'], 401);
            }

            Log::channel('single')->info('federation.inbox.forwarded_accepted', [
                'signed_by' => $verification->actor?->uri,
                'claimed_actor' => $actorUri,
                'activity_id' => $authenticated['id'] ?? $activity['id'],
            ]);

            $activity = $authenticated;
            $actorUri = is_string($activity['actor'] ?? null)
                ? $activity['actor']
                : (is_array($activity['actor'] ?? null) ? ($activity['actor']['id'] ?? $actorUri) : $actorUri);
            $body = json_encode($activity, JSON_THROW_ON_ERROR);
        } else {
            // Il processor usa l'identita' canonica gia' verificata, non una
            // variante equivalente ricevuta nel payload.
            $actorUri = $verification->actor->uri;
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

    /**
     * Una self-Delete Actor ha object uguale all'identita' dichiarata, sia
     * nella forma URI sia nella forma Tombstone incorporata.
     *
     * @param  array<string, mixed>  $activity
     */
    private function isActorSelfDelete(array $activity, string $actorUri): bool
    {
        if (($activity['type'] ?? null) !== 'Delete') {
            return false;
        }

        $objectId = $this->deleteObjectId($activity);

        return is_string($objectId)
            && ActivityPubUri::same($objectId, $actorUri);
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function deleteObjectId(array $activity): ?string
    {
        $object = $activity['object'] ?? null;

        if (is_string($object) && $object !== '') {
            return $object;
        }

        return is_array($object) && is_string($object['id'] ?? null) && $object['id'] !== ''
            ? $object['id']
            : null;
    }

    private function remoteActorExists(string $actorUri): bool
    {
        $host = parse_url($actorUri, PHP_URL_HOST);
        $configuredDomain = (string) config('openbook.domain');
        $localHost = parse_url('//'.$configuredDomain, PHP_URL_HOST)
            ?: parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($host) && is_string($localHost) && strcasecmp($host, $localHost) === 0) {
            return true;
        }

        $normalized = ActivityPubUri::normalize($actorUri);

        return Actor::query()
            ->where('is_local', false)
            ->where(function ($query) use ($actorUri, $normalized): void {
                $query->where('uri', $actorUri)
                    ->orWhere('uri', $normalized)
                    ->orWhere('uri', $normalized.'/');
            })
            ->exists();
    }
}
