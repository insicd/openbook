<?php

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Security\RsaKeyPairGenerator;
use PHPUnit\Framework\TestCase;

class RsaKeyPairGeneratorTest extends TestCase
{
    public function test_it_generates_a_valid_pem_key_pair(): void
    {
        $generator = new RsaKeyPairGenerator(2048);

        $keyPair = $generator->generate();

        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $keyPair->publicKey);
        $this->assertStringContainsString('-----END PUBLIC KEY-----', $keyPair->publicKey);
        $this->assertStringContainsString('PRIVATE KEY-----', $keyPair->privateKey);

        $publicResource = openssl_pkey_get_public($keyPair->publicKey);
        $this->assertNotFalse($publicResource);

        $privateResource = openssl_pkey_get_private($keyPair->privateKey);
        $this->assertNotFalse($privateResource);

        $details = openssl_pkey_get_details($publicResource);
        $this->assertSame(2048, $details['bits']);
        $this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
    }

    public function test_it_generates_distinct_keys_on_each_call(): void
    {
        $generator = new RsaKeyPairGenerator(2048);

        $first = $generator->generate();
        $second = $generator->generate();

        $this->assertNotSame($first->publicKey, $second->publicKey);
        $this->assertNotSame($first->privateKey, $second->privateKey);
    }

    public function test_the_key_pair_can_sign_and_verify_data(): void
    {
        $generator = new RsaKeyPairGenerator(2048);
        $keyPair = $generator->generate();

        $payload = 'openbook-signature-test-payload';

        $signed = openssl_sign($payload, $signature, $keyPair->privateKey, OPENSSL_ALGO_SHA256);
        $this->assertTrue($signed);

        $verified = openssl_verify($payload, $signature, $keyPair->publicKey, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $verified);
    }

    public function test_it_honours_a_custom_bit_length_override(): void
    {
        $generator = new RsaKeyPairGenerator(2048);

        $keyPair = $generator->generate(3072);

        $details = openssl_pkey_get_details(openssl_pkey_get_public($keyPair->publicKey));

        $this->assertSame(3072, $details['bits']);
    }
}
