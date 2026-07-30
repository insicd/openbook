<?php

namespace Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\HttpSignatureSigner;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class HttpSignatureSignerTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_digest_matches_the_rfc3230_format(): void
    {
        $digest = HttpSignatureSigner::digest('ciao mondo');

        $this->assertSame('SHA-256='.base64_encode(hash('sha256', 'ciao mondo', true)), $digest);
    }

    public function test_build_signing_string_assembles_components_in_order(): void
    {
        $signingString = HttpSignatureSigner::buildSigningString(
            'POST',
            '/users/alice/inbox',
            ['host' => 'esempio.it', 'date' => 'Tue, 01 Jul 2025 12:00:00 GMT', 'digest' => 'SHA-256=abc'],
            ['(request-target)', 'host', 'date', 'digest']
        );

        $this->assertSame(
            "(request-target): post /users/alice/inbox\nhost: esempio.it\ndate: Tue, 01 Jul 2025 12:00:00 GMT\ndigest: SHA-256=abc",
            $signingString
        );
    }

    public function test_a_signature_produced_by_sign_is_verifiable_with_the_matching_public_key(): void
    {
        $keyPair = (new RsaKeyPairGenerator)->generate(2048);
        $signer = new HttpSignatureSigner;

        $signatureHeader = $signer->sign(
            'POST',
            '/users/alice/inbox',
            ['host' => 'esempio.it', 'date' => 'Tue, 01 Jul 2025 12:00:00 GMT'],
            'https://esempio.it/@alice#main-key',
            $keyPair->privateKey,
            ['(request-target)', 'host', 'date']
        );

        $this->assertStringContainsString('keyId="https://esempio.it/@alice#main-key"', $signatureHeader);
        $this->assertStringContainsString('algorithm="rsa-sha256"', $signatureHeader);

        preg_match('/signature="([^"]+)"/', $signatureHeader, $matches);
        $signatureBinary = base64_decode($matches[1], true);

        $signingString = HttpSignatureSigner::buildSigningString(
            'POST',
            '/users/alice/inbox',
            ['host' => 'esempio.it', 'date' => 'Tue, 01 Jul 2025 12:00:00 GMT'],
            ['(request-target)', 'host', 'date']
        );

        $this->assertSame(1, openssl_verify($signingString, $signatureBinary, $keyPair->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_it_fails_loudly_when_a_required_header_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HttpSignatureSigner::buildSigningString('GET', '/actor', ['date' => 'now'], ['(request-target)', 'host', 'date']);
    }

    public function test_authorization_headers_for_get_omit_digest_and_include_query(): void
    {
        $account = $this->createFullAccount('headerget');
        $actor = $account->actor->load('key');
        $signer = new HttpSignatureSigner;

        $headers = $signer->authorizationHeaders(
            'GET',
            'https://remoto.example/users/a/replies?page=1',
            $actor,
        );

        $this->assertArrayHasKey('Date', $headers);
        $this->assertArrayHasKey('Signature', $headers);
        $this->assertArrayNotHasKey('Digest', $headers);
        $this->assertStringContainsString('keyId="'.$actor->uri.'#main-key"', $headers['Signature']);

        preg_match('/signature="([^"]+)"/', $headers['Signature'], $matches);
        $signatureBinary = base64_decode($matches[1], true);
        $signingString = HttpSignatureSigner::buildSigningString(
            'GET',
            '/users/a/replies?page=1',
            ['host' => 'remoto.example', 'date' => $headers['Date']],
            ['(request-target)', 'host', 'date'],
        );

        $this->assertSame(1, openssl_verify($signingString, $signatureBinary, $actor->key->public_key, OPENSSL_ALGO_SHA256));
    }

    public function test_authorization_headers_can_omit_query_string_from_request_target(): void
    {
        $account = $this->createFullAccount('omitquery');
        $actor = $account->actor->load('key');
        $signer = new HttpSignatureSigner;

        $headers = $signer->authorizationHeaders(
            'GET',
            'https://remoto.example/users/a/replies?page=1',
            $actor,
            omitQueryString: true,
        );

        preg_match('/signature="([^"]+)"/', $headers['Signature'], $matches);
        $signingString = HttpSignatureSigner::buildSigningString(
            'GET',
            '/users/a/replies',
            ['host' => 'remoto.example', 'date' => $headers['Date']],
            ['(request-target)', 'host', 'date'],
        );

        $this->assertSame(
            1,
            openssl_verify($signingString, base64_decode($matches[1], true), $actor->key->public_key, OPENSSL_ALGO_SHA256)
        );
    }
}
