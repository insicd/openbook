<?php

namespace Tests\Unit\Infrastructure\Security\Http;

use App\Infrastructure\Security\Http\SafeHttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafeHttpClientTest extends TestCase
{
    public function test_it_returns_a_failed_response_instead_of_throwing_on_connection_errors(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException(
                new ConnectException(
                    'cURL error 28: Connection timed out after 10001 milliseconds',
                    new Request('GET', 'https://offline.example/outbox'),
                ),
            );
        });

        $response = app(SafeHttpClient::class)->get('https://offline.example/outbox');

        $this->assertFalse($response->successful());
        $this->assertSame(503, $response->status);
    }
}
