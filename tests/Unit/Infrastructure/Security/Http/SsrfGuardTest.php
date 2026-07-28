<?php

namespace Tests\Unit\Infrastructure\Security\Http;

use App\Infrastructure\Security\Http\DnsResolver;
use App\Infrastructure\Security\Http\SsrfGuard;
use App\Infrastructure\Security\Http\SsrfViolationException;
use Tests\TestCase;

class SsrfGuardTest extends TestCase
{
    private function guardResolvingTo(array $ips): SsrfGuard
    {
        $resolver = new class($ips) implements DnsResolver
        {
            public function __construct(private readonly array $ips) {}

            public function resolve(string $host): array
            {
                return $this->ips;
            }
        };

        return new SsrfGuard($resolver);
    }

    public function test_it_allows_a_public_https_host(): void
    {
        $target = $this->guardResolvingTo(['1.1.1.1'])->assertUrlIsSafe('https://remoto.example/users/alice');

        $this->assertSame('remoto.example', $target->host);
        $this->assertSame(443, $target->port);
        $this->assertSame('1.1.1.1', $target->ip);
    }

    public function test_it_rejects_http_by_default(): void
    {
        $this->expectException(SsrfViolationException::class);

        $this->guardResolvingTo(['1.1.1.1'])->assertUrlIsSafe('http://remoto.example/users/alice');
    }

    public function test_it_allows_http_when_explicitly_configured_for_development(): void
    {
        config(['openbook.federation.fetch.allow_insecure' => true]);

        $target = $this->guardResolvingTo(['1.1.1.1'])->assertUrlIsSafe('http://remoto.example/users/alice');

        $this->assertSame('remoto.example', $target->host);
    }

    public function test_it_rejects_an_unsupported_scheme(): void
    {
        $this->expectException(SsrfViolationException::class);

        $this->guardResolvingTo(['1.1.1.1'])->assertUrlIsSafe('ftp://remoto.example/file');
    }

    public function test_it_rejects_a_host_resolving_to_a_private_address(): void
    {
        $this->expectException(SsrfViolationException::class);

        $this->guardResolvingTo(['10.0.0.5'])->assertUrlIsSafe('https://interno.example/actor');
    }

    public function test_it_rejects_a_host_resolving_to_a_loopback_address(): void
    {
        $this->expectException(SsrfViolationException::class);

        $this->guardResolvingTo(['127.0.0.1'])->assertUrlIsSafe('https://furbo.example/actor');
    }

    public function test_it_rejects_a_literal_loopback_ip_in_the_url_itself(): void
    {
        $this->expectException(SsrfViolationException::class);

        $this->guardResolvingTo(['1.1.1.1'])->assertUrlIsSafe('https://127.0.0.1/actor');
    }

    public function test_it_rejects_when_dns_resolution_fails(): void
    {
        $this->expectException(SsrfViolationException::class);

        $this->guardResolvingTo([])->assertUrlIsSafe('https://non-risolvibile.example/actor');
    }

    public function test_it_rejects_when_any_resolved_address_is_private_even_if_others_are_public(): void
    {
        $this->expectException(SsrfViolationException::class);

        $this->guardResolvingTo(['1.1.1.1', '192.168.1.1'])->assertUrlIsSafe('https://misto.example/actor');
    }
}
