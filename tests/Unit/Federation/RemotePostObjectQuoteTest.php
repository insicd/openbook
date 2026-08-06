<?php

namespace Tests\Unit\Federation;

use App\Federation\Inbox\RemotePostObject;
use PHPUnit\Framework\TestCase;

class RemotePostObjectQuoteTest extends TestCase
{
    public function test_quote_uri_prefers_fep_quote_then_legacy_keys(): void
    {
        $this->assertSame(
            'https://a.example/posts/1',
            RemotePostObject::quoteUri(['quote' => 'https://a.example/posts/1', 'quoteUrl' => 'https://b.example/x']),
        );

        $this->assertSame(
            'https://b.example/x',
            RemotePostObject::quoteUri(['quoteUrl' => 'https://b.example/x']),
        );

        $this->assertSame(
            'https://c.example/y',
            RemotePostObject::quoteUri(['_misskey_quote' => 'https://c.example/y']),
        );

        $this->assertSame(
            'https://d.example/z',
            RemotePostObject::quoteUri(['quote' => ['id' => 'https://d.example/z', 'type' => 'Note']]),
        );
    }

    public function test_strip_quote_fallback_removes_re_and_bare_url(): void
    {
        $uri = 'https://mastodon.example/users/a/statuses/1';

        $this->assertSame(
            'La mia opinione.',
            RemotePostObject::stripQuoteFallbackFromBody("La mia opinione.\n\nRE: {$uri}", $uri),
        );

        $this->assertSame(
            'Commento',
            RemotePostObject::stripQuoteFallbackFromBody("Commento\n{$uri}", $uri),
        );

        $this->assertSame(
            'Con link markdown',
            RemotePostObject::stripQuoteFallbackFromBody("Con link markdown\n[{$uri}]({$uri})", $uri),
        );

        $this->assertSame(
            '',
            RemotePostObject::stripQuoteFallbackFromBody("RE: {$uri}", $uri),
        );
    }
}
