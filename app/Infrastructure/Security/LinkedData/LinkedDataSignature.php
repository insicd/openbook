<?php

namespace App\Infrastructure\Security\LinkedData;

use App\Application\Services\DomainBlockManager;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Support\ActivityPubUri;
use Illuminate\Support\Facades\Log;
use JsonLdException;
use RuntimeException;
use Throwable;

/**
 * Linked Data Signatures {@code RsaSignature2017} compatibili con Mastodon /
 * Friendica / Pleroma: autenticano l'attivita' indipendentemente dalla
 * firma HTTP del transport (necessarie per inbox forwarding).
 *
 * Algoritmo (come Mastodon):
 * 1. options = signature senza type/id/signatureValue + @context identity/v1
 * 2. document = attivita' senza signature
 * 3. URDNA2015 → SHA-256 hex di ciascuno
 * 4. RSA-SHA256 su (optionsHash + documentHash)
 */
final class LinkedDataSignature
{
    private const IDENTITY_CONTEXT = 'https://w3id.org/identity/v1';

    private const SECURITY_CONTEXT = 'https://w3id.org/security/v1';

    /**
     * @var list<string>
     */
    private const SUSPICIOUS_KEYS = ['@graph', '@included', '@reverse'];

    public function __construct(
        private readonly JsonLdDocumentLoader $documentLoader,
        private readonly RemoteActorResolver $remoteActors,
        private readonly DomainBlockManager $domainBlocks,
    ) {}

    /**
     * Verifica la LD Signature e restituisce l'Actor firmatario se coincide
     * con {@code $expectedActorUri} (activity.actor).
     *
     * @param  array<string, mixed>  $activity
     */
    public function verifyActor(array $activity, string $expectedActorUri): ?Actor
    {
        $signature = $activity['signature'] ?? null;

        if (! is_array($signature) || ($signature['type'] ?? null) !== 'RsaSignature2017') {
            return null;
        }

        if ($this->containsSuspiciousTerms($activity)) {
            Log::channel('single')->info('federation.ld_signature.rejected', [
                'reason' => 'suspicious_jsonld',
                'activity_id' => $activity['id'] ?? null,
            ]);

            return null;
        }

        $creator = is_string($signature['creator'] ?? null) ? $signature['creator'] : null;
        $signatureValue = is_string($signature['signatureValue'] ?? null) ? $signature['signatureValue'] : null;

        if ($creator === null || $signatureValue === null || $signatureValue === '') {
            return null;
        }

        if ($this->domainBlocks->isBlockedUrl($creator) || $this->domainBlocks->isBlockedUrl($expectedActorUri)) {
            return null;
        }

        if (isset($signature['expires']) && is_string($signature['expires'])) {
            $expires = strtotime($signature['expires']);

            if ($expires !== false && $expires < time()) {
                Log::channel('single')->info('federation.ld_signature.rejected', [
                    'reason' => 'expired',
                    'activity_id' => $activity['id'] ?? null,
                ]);

                return null;
            }
        }

        $actor = $this->remoteActors->resolveCachedActorForKeyId($creator);

        if ($actor === null) {
            $actor = $this->remoteActors->resolveByKeyId($creator);
        }

        if ($actor === null || $actor->key === null || blank($actor->key->public_key)) {
            Log::channel('single')->info('federation.ld_signature.rejected', [
                'reason' => 'creator_unresolved',
                'creator' => $creator,
            ]);

            return null;
        }

        if (! ActivityPubUri::same($actor->uri, $expectedActorUri)) {
            Log::channel('single')->info('federation.ld_signature.rejected', [
                'reason' => 'creator_actor_mismatch',
                'creator_actor' => $actor->uri,
                'expected_actor' => $expectedActorUri,
            ]);

            return null;
        }

        try {
            $optionsHash = $this->hash($this->signableOptions($signature));
        } catch (Throwable $exception) {
            $this->logNormalizationFailure($activity, $creator, 'options', $exception);

            return null;
        }

        try {
            $documentHash = $this->hash($this->signableData($activity));
        } catch (Throwable $exception) {
            $this->logNormalizationFailure($activity, $creator, 'document', $exception);

            return null;
        }

        $payload = $optionsHash.$documentHash;
        $binary = base64_decode($signatureValue, true);

        if ($binary === false) {
            Log::channel('single')->info('federation.ld_signature.rejected', [
                'reason' => 'signature_value_invalid',
                'activity_id' => $activity['id'] ?? null,
                'creator' => $creator,
            ]);

            return null;
        }

        $ok = openssl_verify($payload, $binary, $actor->key->public_key, OPENSSL_ALGO_SHA256);

        if ($ok !== 1) {
            Log::channel('single')->info('federation.ld_signature.rejected', [
                'reason' => 'verify_failed',
                'activity_id' => $activity['id'] ?? null,
                'creator' => $creator,
            ]);

            return null;
        }

        return $actor;
    }

    /**
     * Registra solo la forma del documento e la fase fallita: mai payload,
     * firma, chiavi o messaggi di eccezione potenzialmente voluminosi.
     *
     * @param  array<string, mixed>  $activity
     */
    private function logNormalizationFailure(
        array $activity,
        string $creator,
        string $phase,
        Throwable $exception,
    ): void {
        $context = $phase === 'document'
            ? ($activity['@context'] ?? null)
            : self::IDENTITY_CONTEXT;

        Log::channel('single')->info('federation.ld_signature.rejected', [
            'reason' => 'normalize_failed',
            'phase' => $phase,
            'category' => $exception->getPrevious() instanceof JsonLdException ? 'jsonld' : 'document',
            'context_shape' => $this->contextShape($context),
            'activity_id' => $activity['id'] ?? null,
            'creator' => $creator,
        ]);
    }

    private function contextShape(mixed $context): string
    {
        if ($context === null) {
            return 'missing';
        }

        if (is_string($context)) {
            return 'string';
        }

        if (! is_array($context)) {
            return 'other';
        }

        return array_is_list($context) ? 'list' : 'object';
    }

    /**
     * Aggiunge {@code signature} RsaSignature2017 all'attivita' (copia).
     *
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    public function sign(array $activity, Actor $actor): array
    {
        $actor->loadMissing('key');

        if ($actor->key === null || ! $actor->key->hasPrivateKey()) {
            throw new \InvalidArgumentException('Actor senza chiave privata per LD Signature.');
        }

        $options = [
            'type' => 'RsaSignature2017',
            'creator' => $actor->activityPubId().'#main-key',
            'created' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires' => now()->utc()->addDays(2)->format('Y-m-d\TH:i:s\Z'),
        ];

        $optionsHash = $this->hash($this->signableOptions($options));
        $documentHash = $this->hash($this->signableData($activity));
        $payload = $optionsHash.$documentHash;

        $ok = openssl_sign($payload, $binary, $actor->key->private_key, OPENSSL_ALGO_SHA256);

        if ($ok !== true) {
            throw new RuntimeException('openssl_sign fallita per LD Signature.');
        }

        $options['signatureValue'] = base64_encode($binary);

        $signed = $this->withSecurityContext($activity);
        $signed['signature'] = $options;

        return $signed;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    private function signableData(array $activity): array
    {
        unset($activity['signature']);

        return $activity;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function signableOptions(array $options): array
    {
        unset($options['type'], $options['id'], $options['signatureValue']);

        return array_merge(['@context' => self::IDENTITY_CONTEXT], $options);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function hash(array $document): string
    {
        $this->documentLoader->register();

        if ($this->containsSuspiciousTerms($document)) {
            throw new RuntimeException('Documento JSON-LD con termini non supportati.');
        }

        $jsonObject = json_decode(json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            $normalized = jsonld_normalize($jsonObject, [
                'algorithm' => 'URDNA2015',
                'format' => 'application/nquads',
            ]);
        } catch (JsonLdException $exception) {
            throw new RuntimeException('Normalizzazione JSON-LD fallita: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_string($normalized) || $normalized === '') {
            throw new RuntimeException('Normalizzazione JSON-LD ha prodotto output vuoto.');
        }

        return hash('sha256', $normalized);
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    private function withSecurityContext(array $activity): array
    {
        $context = $activity['@context'] ?? null;

        if (is_string($context)) {
            $context = [$context];
        } elseif (! is_array($context)) {
            $context = ['https://www.w3.org/ns/activitystreams'];
        }

        if (! in_array(self::SECURITY_CONTEXT, $context, true)) {
            $context[] = self::SECURITY_CONTEXT;
        }

        $activity['@context'] = count($context) === 1 ? $context[0] : array_values($context);

        return $activity;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function containsSuspiciousTerms(array $data): bool
    {
        $stack = [$data];

        while ($stack !== []) {
            $node = array_pop($stack);

            foreach ($node as $key => $value) {
                if (is_string($key) && in_array($key, self::SUSPICIOUS_KEYS, true)) {
                    return true;
                }

                if (is_string($value) && in_array($value, self::SUSPICIOUS_KEYS, true)) {
                    return true;
                }

                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return false;
    }
}
