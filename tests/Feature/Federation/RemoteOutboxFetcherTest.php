<?php

namespace Tests\Feature\Federation;

use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Outbox\RemoteOutboxFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

/**
 * Al primo caricamento (o dopo la scadenza della cache) della pagina
 * profilo di un Actor remoto, {@see RemoteOutboxFetcher} interroga il suo
 * outbox reale per farne comparire almeno i post pubblici piu' recenti,
 * invece di affidarsi soltanto a cio' che l'inbox ha gia' ricevuto (che per
 * costruzione ignora un autore non ancora seguito da nessun Actor locale,
 * vedi InboxActivityProcessorTest).
 */
class RemoteOutboxFetcherTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function fakeOutbox(Actor $remote, array $items): void
    {
        $outboxUrl = $remote->endpoints->outbox;

        Http::fake([
            $outboxUrl => Http::response([
                'id' => $outboxUrl,
                'type' => 'OrderedCollection',
                'totalItems' => count($items),
                'first' => $outboxUrl.'?page=1',
            ], 200, ['Content-Type' => 'application/activity+json']),
            $outboxUrl.'?page=1' => Http::response([
                'id' => $outboxUrl.'?page=1',
                'type' => 'OrderedCollectionPage',
                'partOf' => $outboxUrl,
                'orderedItems' => $items,
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function noteActivity(Actor $author, array $overrides = []): array
    {
        $noteUri = $author->uri.'/posts/'.uniqid();

        return [
            'id' => $noteUri.'/attivita',
            'type' => 'Create',
            'actor' => $author->uri,
            'object' => array_merge([
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Post pubblicato sul server di origine.</p>',
                'published' => now()->subHour()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ], $overrides),
        ];
    }

    public function test_visiting_a_remote_profile_fetches_and_caches_its_recent_public_posts(): void
    {
        $viewer = $this->createFullAccount('esploratore');
        $remote = $this->createRemoteActor('silvano');

        $this->fakeOutbox($remote, [
            $this->noteActivity($remote, ['content' => '<p>Primo post pubblico.</p>']),
            $this->noteActivity($remote, ['content' => '<p>Secondo post, non elencato.</p>', 'to' => [], 'cc' => ['https://www.w3.org/ns/activitystreams#Public']]),
        ]);

        $response = $this->actingAs($viewer)->get(route('actors.show', $remote));

        $response->assertOk();
        $response->assertSee('Primo post pubblico.');
        $response->assertSee('Secondo post, non elencato.');
        $this->assertSame(2, Post::query()->where('actor_id', $remote->id)->count());
        $this->assertNotNull($remote->fresh()->posts_fetched_at);
    }

    public function test_it_skips_replies_non_public_notes_and_impersonation_attempts(): void
    {
        $viewer = $this->createFullAccount('esploratore2');
        $remote = $this->createRemoteActor('tobia');
        $impostor = $this->createRemoteActor('impostore', 'altro.example');

        $this->fakeOutbox($remote, [
            $this->noteActivity($remote, ['inReplyTo' => 'https://sconosciuto.example/posts/1']),
            $this->noteActivity($remote, ['to' => [$remote->uri.'/followers'], 'cc' => []]),
            $this->noteActivity($impostor, ['attributedTo' => $impostor->uri]),
        ]);

        $response = $this->actingAs($viewer)->get(route('actors.show', $remote));

        $response->assertOk();
        $this->assertSame(0, Post::query()->where('actor_id', $remote->id)->count());
        $this->assertSame(0, Post::query()->where('actor_id', $impostor->id)->count());
    }

    public function test_it_does_not_refetch_the_outbox_before_the_cache_ttl_expires(): void
    {
        $viewer = $this->createFullAccount('esploratore3');
        $remote = $this->createRemoteActor('ugolino');
        $this->fakeOutbox($remote, [$this->noteActivity($remote)]);

        $this->actingAs($viewer)->get(route('actors.show', $remote))->assertOk();
        Http::assertSentCount(2);

        $this->actingAs($viewer)->get(route('actors.show', $remote))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_it_refetches_the_outbox_after_the_cache_ttl_expires(): void
    {
        config(['openbook.federation.posts_cache_ttl_hours' => 6]);
        $viewer = $this->createFullAccount('esploratore4');
        $remote = $this->createRemoteActor('venanzio', overrides: ['posts_fetched_at' => now()->subHours(7)]);
        $this->fakeOutbox($remote, [$this->noteActivity($remote)]);

        $this->actingAs($viewer)->get(route('actors.show', $remote))->assertOk();

        Http::assertSentCount(2);
    }

    public function test_it_records_the_fetch_attempt_even_when_the_remote_outbox_is_unreachable(): void
    {
        $viewer = $this->createFullAccount('esploratore5');
        $remote = $this->createRemoteActor('zosimo');
        Http::fake(['*' => Http::response('', 503)]);

        $response = $this->actingAs($viewer)->get(route('actors.show', $remote));

        $response->assertOk();
        $this->assertSame(0, Post::query()->where('actor_id', $remote->id)->count());
        $this->assertNotNull($remote->fresh()->posts_fetched_at);
    }

    public function test_backfilled_posts_do_not_notify_locally_mentioned_actors(): void
    {
        $viewer = $this->createFullAccount('esploratore6');
        $mentioned = $this->createFullAccount('menzionatobackfill');
        $remote = $this->createRemoteActor('yara');

        $this->fakeOutbox($remote, [
            $this->noteActivity($remote, [
                'content' => '<p>Ciao @menzionatobackfill!</p>',
                'tag' => [
                    ['type' => 'Mention', 'href' => $mentioned->actor->uri, 'name' => '@menzionatobackfill'],
                ],
            ]),
        ]);

        $this->actingAs($viewer)->get(route('actors.show', $remote))->assertOk();

        $this->assertDatabaseHas('mentions', ['actor_id' => $mentioned->actor->id]);
        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $mentioned->id,
            'type' => Notification::TYPE_MENTION,
        ]);
    }

    public function test_the_outbox_is_never_fetched_for_a_local_actor(): void
    {
        $viewer = $this->createFullAccount('esploratore7');
        Http::fake();

        app(RemoteOutboxFetcher::class)->fetchRecentPosts($viewer->actor);

        Http::assertNothingSent();
        $this->assertNull($viewer->actor->fresh()->posts_fetched_at);
    }

    public function test_pixelfed_style_stub_outbox_falls_back_to_atom_feed(): void
    {
        $viewer = $this->createFullAccount('pixviewer');
        $remote = $this->createRemoteActor('fotografo', 'pixelfed.example');
        $noteUri = $remote->uri.'/p/'.uniqid();
        $imageUrl = 'https://pixelfed.example/storage/photo.jpg';
        $outboxUrl = $remote->endpoints->outbox;
        $atomUrl = $remote->uri.'.atom';

        Http::fake([
            $outboxUrl => Http::response([
                'id' => $outboxUrl,
                'type' => 'OrderedCollection',
                'totalItems' => 2141,
            ], 200, ['Content-Type' => 'application/activity+json']),
            $atomUrl => Http::response(<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <id>{$noteUri}</id>
    <title>Fornace</title>
    <link rel="alternate" href="{$noteUri}" />
  </entry>
</feed>
XML, 200, ['Content-Type' => 'application/atom+xml']),
            $noteUri => Http::response([
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => 'Fornace Penna',
                'url' => $noteUri,
                'published' => now()->subHour()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'attachment' => [
                    [
                        'type' => 'Document',
                        'mediaType' => 'image/jpeg',
                        'url' => $imageUrl,
                    ],
                ],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $this->actingAs($viewer)->get(route('actors.show', $remote))->assertOk();

        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);
        $this->assertSame('Fornace Penna', $post->body);
        $this->assertCount(1, $post->media);
        $this->assertSame($imageUrl, $post->media->first()->url());
    }

    public function test_empty_cache_retries_within_ttl_after_stub_outbox(): void
    {
        $viewer = $this->createFullAccount('retryviewer');
        $remote = $this->createRemoteActor('stubonly', 'pixelfed.example');
        $outboxUrl = $remote->endpoints->outbox;
        $atomUrl = $remote->uri.'.atom';
        $noteUri = $remote->uri.'/p/retry1';

        $remote->forceFill(['posts_fetched_at' => now()->subMinute()])->saveQuietly();

        Http::fake([
            $outboxUrl => Http::response([
                'id' => $outboxUrl,
                'type' => 'OrderedCollection',
                'totalItems' => 3,
            ], 200, ['Content-Type' => 'application/activity+json']),
            $atomUrl => Http::response(<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry><id>{$noteUri}</id></entry>
</feed>
XML, 200, ['Content-Type' => 'application/atom+xml']),
            $noteUri => Http::response([
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $remote->uri,
                'content' => 'Dopo lo stub',
                'published' => now()->subHour()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        $this->actingAs($viewer)->get(route('actors.show', $remote))->assertOk();

        $this->assertDatabaseHas('posts', [
            'uri' => $noteUri,
            'actor_id' => $remote->id,
            'body' => 'Dopo lo stub',
        ]);
    }

    public function test_a_lemmy_group_outbox_announce_create_page_is_ingested(): void
    {
        $viewer = $this->createFullAccount('esploratorelemmy');
        $group = $this->createRemoteActor('news', 'lemmy.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'News',
        ]);
        $author = $this->createRemoteActor('alice', 'lemmy.example');

        Follow::query()->create([
            'follower_id' => $viewer->actor->id,
            'following_id' => $group->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $pageUri = 'https://lemmy.example/post/42';
        $this->fakeOutbox($group, [[
            'id' => $group->uri.'/activities/announce/1',
            'type' => 'Announce',
            'actor' => $group->uri,
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => [
                'id' => $author->uri.'/activities/create/1',
                'type' => 'Create',
                'actor' => $author->uri,
                'object' => [
                    'id' => $pageUri,
                    'type' => 'Page',
                    'attributedTo' => $author->uri,
                    'name' => 'Link post Lemmy',
                    'url' => 'https://example.com/articolo',
                    'published' => now()->subHour()->toAtomString(),
                    'to' => [$group->uri, 'https://www.w3.org/ns/activitystreams#Public'],
                ],
            ],
        ]]);

        $response = $this->actingAs($viewer)->get(route('actors.show', $group));

        $response->assertOk();
        $response->assertSee('Link post Lemmy');
        $this->assertDatabaseHas('posts', [
            'uri' => $pageUri,
            'actor_id' => $author->id,
            'title' => 'Link post Lemmy',
            'body' => 'https://example.com/articolo',
        ]);
        $this->assertTrue(
            Announce::query()
                ->where('actor_id', $group->id)
                ->where('post_id', Post::query()->where('uri', $pageUri)->value('id'))
                ->exists()
        );
    }
}
