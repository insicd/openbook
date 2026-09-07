<?php

namespace Tests\Unit\Federation;

use App\Federation\Support\ActivityPubUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ActivityPubUriTest extends TestCase
{
    #[DataProvider('equivalentUris')]
    public function test_it_normalizes_equivalent_activitypub_identifiers(string $left, string $right): void
    {
        $this->assertTrue(ActivityPubUri::same($left, $right));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentUris(): iterable
    {
        yield 'trailing slash' => ['https://remote.example/users/alice', 'https://remote.example/users/alice/'];
        yield 'encoded path' => ['https://remote.example/users/%40alice', 'https://remote.example/users/@alice'];
        yield 'host and scheme case' => ['HTTPS://REMOTE.EXAMPLE/users/alice', 'https://remote.example/users/alice'];
        yield 'default port' => ['https://remote.example:443/users/alice', 'https://remote.example/users/alice'];
    }

    public function test_it_does_not_ignore_query_or_fragment_differences(): void
    {
        $this->assertFalse(ActivityPubUri::same('https://remote.example/users/alice?x=1', 'https://remote.example/users/alice?x=2'));
        $this->assertFalse(ActivityPubUri::same('https://remote.example/users/alice#one', 'https://remote.example/users/alice#two'));
    }

    public function test_it_removes_only_the_fragment_from_a_key_id(): void
    {
        $this->assertSame(
            'https://remote.example/users/alice',
            ActivityPubUri::withoutFragment('https://remote.example/users/alice#main-key'),
        );
    }
}
