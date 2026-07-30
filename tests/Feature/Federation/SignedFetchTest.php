<?php

namespace Tests\Feature\Federation;

use App\Domain\Posts\Post;
use App\Infrastructure\Security\HttpSignatureSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * I GET ActivityPub (Note, outbox, Actor) devono essere firmati con la
 * chiave di un Actor locale (authorized fetch), altrimenti molte istanze
 * rispondono 401.
 */
class SignedFetchTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_fetching_remote_replies_sends_a_valid_http_signature(): void
    {
        $viewer = $this->createFullAccount('firmatorefetch');
        $author = $this->createRemoteActor('autorefirmato');
        $postUri = $author->uri.'/statuses/99';

        $post = Post::query()->create([
            'actor_id' => $author->id,
            'uri' => $postUri,
            'body' => 'Post da firmare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Http::fake([
            $postUri => Http::response([
                'id' => $postUri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Post</p>',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'replies' => [
                    'id' => $postUri.'/replies',
                    'type' => 'Collection',
                    'first' => [
                        'type' => 'CollectionPage',
                        'items' => [],
                    ],
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk();

        Http::assertSent(function (Request $request) use ($postUri, $viewer): bool {
            if ($request->url() !== $postUri || $request->method() !== 'GET') {
                return false;
            }

            $signatureHeader = $request->header('Signature')[0] ?? '';
            preg_match('/keyId="([^"]+)"/', $signatureHeader, $keyMatch);
            preg_match('/signature="([^"]+)"/', $signatureHeader, $sigMatch);

            if (($keyMatch[1] ?? null) !== $viewer->actor->uri.'#main-key') {
                return false;
            }

            if ($request->hasHeader('Digest')) {
                return false;
            }

            $signingString = HttpSignatureSigner::buildSigningString('GET', '/users/autorefirmato/statuses/99', [
                'host' => 'remoto.example',
                'date' => $request->header('Date')[0] ?? '',
            ], ['(request-target)', 'host', 'date']);

            $signatureBinary = base64_decode($sigMatch[1] ?? '', true);

            return $signatureBinary !== false
                && openssl_verify($signingString, $signatureBinary, $viewer->actor->key->public_key, OPENSSL_ALGO_SHA256) === 1;
        });
    }

    public function test_signed_fetch_retries_without_query_string_on_401(): void
    {
        $viewer = $this->createFullAccount('doubleknock');
        $author = $this->createRemoteActor('paginato');
        $postUri = $author->uri.'/statuses/7';
        $pageUrl = $postUri.'/replies?only_other_accounts=true&page=true';

        $post = Post::query()->create([
            'actor_id' => $author->id,
            'uri' => $postUri,
            'body' => 'Post paginato.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $attempts = 0;

        Http::fake(function (Request $request) use ($postUri, $pageUrl, &$attempts) {
            if ($request->url() === $postUri) {
                return Http::response([
                    'id' => $postUri,
                    'type' => 'Note',
                    'attributedTo' => 'https://remoto.example/users/paginato',
                    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                    'replies' => [
                        'type' => 'Collection',
                        'first' => $pageUrl,
                    ],
                ], 200);
            }

            if ($request->url() === $pageUrl) {
                $attempts++;
                preg_match('/headers="([^"]+)"/', $request->header('Signature')[0] ?? '', $h);
                $sigHeaders = $h[1] ?? '';

                // Prima richiesta: target con query -> 401; seconda: senza query -> 200.
                if ($attempts === 1) {
                    return Http::response('unauthorized', 401);
                }

                return Http::response([
                    'id' => $pageUrl,
                    'type' => 'CollectionPage',
                    'items' => [],
                ], 200);
            }

            return Http::response('not found', 404);
        });

        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk();

        $this->assertSame(2, $attempts);
    }

    public function test_fetch_signing_can_be_disabled(): void
    {
        config(['openbook.federation.fetch.signed' => false]);

        $viewer = $this->createFullAccount('nonsigned');
        $author = $this->createRemoteActor('pubblico');
        $postUri = $author->uri.'/statuses/1';

        $post = Post::query()->create([
            'actor_id' => $author->id,
            'uri' => $postUri,
            'body' => 'Post.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Http::fake([
            $postUri => Http::response([
                'id' => $postUri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'replies' => ['type' => 'Collection', 'items' => []],
            ], 200),
        ]);

        $this->actingAs($viewer)->get(route('posts.show', $post))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === $postUri
            && ! $request->hasHeader('Signature'));
    }
}
