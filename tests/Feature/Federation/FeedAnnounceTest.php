<?php

namespace Tests\Feature\Federation;

use App\Application\Services\AnnounceManager;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class FeedAnnounceTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private const FEED_URL = 'https://blog.example/feed.xml';

    private function atomFeed(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Blog di Esempio</title>
  <link href="https://blog.example/" rel="alternate"/>
  <link href="https://blog.example/feed.xml" rel="self"/>
  <entry>
    <id>https://blog.example/posts/1</id>
    <title>Prima voce</title>
    <link href="https://blog.example/posts/1" rel="alternate"/>
    <published>2024-01-02T10:00:00Z</published>
    <content type="html"><![CDATA[<p>Ciao dal blog.</p>]]></content>
  </entry>
</feed>
XML;
    }

    private function importFeedActor(): Actor
    {
        $viewer = $this->createFullAccount('feedannouncer');

        Http::fake([
            self::FEED_URL => Http::response($this->atomFeed(), 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $this->actingAs($viewer)->get(route('search.create', ['q' => self::FEED_URL]));

        return Actor::query()->whereHas('feedSource', function ($query) {
            $query->where('feed_url', self::FEED_URL);
        })->with(['key', 'endpoints', 'feedSource'])->firstOrFail();
    }

    public function test_a_feed_item_is_served_as_a_local_note(): void
    {
        $actor = $this->importFeedActor();
        $post = Post::query()->where('actor_id', $actor->id)->firstOrFail();

        $this->assertNull($post->uri);
        $this->assertSame('https://blog.example/posts/1', $post->source_uri);
        $this->assertFalse($post->isRemote());

        $response = $this->get(route('posts.show', $post), ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertJsonPath('id', url("/posts/{$post->id}"));
        $response->assertJsonPath('type', 'Note');
        $response->assertJsonPath('attributedTo', url('/feeds/'.$actor->id));
        $response->assertJsonPath('name', 'Prima voce');
        $this->assertStringContainsString('Ciao dal blog', $response->json('content'));
        $this->assertStringNotContainsString(self::FEED_URL, (string) $response->json('id'));
    }

    public function test_a_feed_actor_exposes_an_activitypub_document(): void
    {
        $actor = $this->importFeedActor();

        $response = $this->get(route('feeds.actor', $actor), ['Accept' => 'application/activity+json']);

        $response->assertOk();
        $response->assertJsonPath('id', url('/feeds/'.$actor->id));
        $response->assertJsonPath('type', 'Person');
        $response->assertJsonPath('preferredUsername', $actor->preferred_username);
        $response->assertJsonPath('inbox', url('/inbox'));
        $this->assertNotEmpty($response->json('publicKey.publicKeyPem'));

        $webfinger = $this->get('/.well-known/webfinger?resource='.urlencode('acct:'.$actor->handle()));
        $webfinger->assertOk();
        $self = collect($webfinger->json('links'))->firstWhere('rel', 'self');
        $this->assertSame(url('/feeds/'.$actor->id), $self['href']);
    }

    public function test_announcing_a_feed_post_sends_an_inline_note_not_the_article_url(): void
    {
        Queue::fake();
        $actor = $this->importFeedActor();
        $post = Post::query()->where('actor_id', $actor->id)->firstOrFail();
        $sharer = $this->createFullAccount('condivisorefeed');
        $remoteFollower = $this->createRemoteActor('lettoreremoto');

        Follow::query()->create([
            'follower_id' => $remoteFollower->id,
            'following_id' => $sharer->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        app(AnnounceManager::class)->announce($sharer->actor, $post);

        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($post, $actor, $remoteFollower): bool {
            if ($job->inboxUrl !== $remoteFollower->endpoints->shared_inbox) {
                return false;
            }

            $activity = $job->activity;
            $object = $activity['object'] ?? null;

            return ($activity['type'] ?? null) === 'Announce'
                && is_array($object)
                && ($object['type'] ?? null) === 'Note'
                && ($object['id'] ?? null) === url("/posts/{$post->id}")
                && ($object['attributedTo'] ?? null) === url('/feeds/'.$actor->id)
                && str_contains((string) ($object['content'] ?? ''), 'Ciao dal blog')
                && ($object['id'] ?? null) !== 'https://blog.example/posts/1';
        });

        Queue::assertNotPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === url('/inbox'));
    }
}
