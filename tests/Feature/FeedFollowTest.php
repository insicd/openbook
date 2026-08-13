<?php

namespace Tests\Feature;

use App\Application\Queries\FeedQuery;
use App\Application\Services\FollowManager;
use App\Domain\Feeds\FeedDocumentParser;
use App\Domain\Feeds\FeedSource;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class FeedFollowTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private const SITE_URL = 'https://blog.example/';

    private const FEED_URL = 'https://blog.example/feed.xml';

    private function atomFeed(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Blog di Esempio</title>
  <subtitle>Notizie dal blog</subtitle>
  <link href="https://blog.example/" rel="alternate"/>
  <link href="https://blog.example/feed.xml" rel="self"/>
  <icon>https://blog.example/icon.png</icon>
  <entry>
    <id>https://blog.example/posts/1</id>
    <title>Prima voce</title>
    <link href="https://blog.example/posts/1" rel="alternate"/>
    <published>2024-01-02T10:00:00Z</published>
    <content type="html"><![CDATA[<p>Ciao dal <a href="https://blog.example/posts/1">blog</a>.</p>]]></content>
  </entry>
  <entry>
    <id>https://blog.example/posts/2</id>
    <title>Seconda voce</title>
    <link href="https://blog.example/posts/2" rel="alternate"/>
    <updated>2024-01-03T12:00:00Z</updated>
    <summary>Riassunto della seconda.</summary>
  </entry>
</feed>
XML;
    }

    private function htmlWithAlternate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html><head>
  <title>Blog</title>
  <link rel="alternate" type="application/atom+xml" href="/feed.xml">
</head><body>Ciao</body></html>
HTML;
    }

    public function test_the_parser_reads_atom_entries(): void
    {
        $parser = new FeedDocumentParser();
        $meta = $parser->parseMetadata($this->atomFeed());
        $entries = $parser->parseEntries($this->atomFeed());

        $this->assertSame(FeedSource::FORMAT_ATOM, $meta['format']);
        $this->assertSame('Blog di Esempio', $meta['title']);
        $this->assertCount(2, $entries);
        $this->assertSame('https://blog.example/posts/1', $entries[0]->uri);
        $this->assertStringContainsString('Ciao dal', $entries[0]->body);
    }

    public function test_searching_a_feed_url_creates_a_feed_actor_and_imports_entries(): void
    {
        $viewer = $this->createFullAccount('feedviewer');

        Http::fake([
            self::FEED_URL => Http::response($this->atomFeed(), 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', [
            'q' => self::FEED_URL,
        ]));

        $actor = Actor::query()->where('uri', self::FEED_URL)->firstOrFail();
        $this->assertTrue($actor->isFeed());
        $this->assertFalse($actor->is_local);
        $this->assertSame('Blog di Esempio', $actor->name);
        $this->assertNotNull($actor->feedSource);
        $this->assertSame(self::FEED_URL, $actor->feedSource->feed_url);
        $this->assertSame(2, Post::query()->where('actor_id', $actor->id)->count());
        $response->assertRedirect(route('actors.show', $actor));
    }

    public function test_searching_a_site_url_discovers_the_alternate_feed(): void
    {
        $viewer = $this->createFullAccount('feeddiscover');

        Http::fake([
            self::SITE_URL => Http::response($this->htmlWithAlternate(), 200, ['Content-Type' => 'text/html']),
            self::FEED_URL => Http::response($this->atomFeed(), 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $response = $this->actingAs($viewer)->get(route('search.create', [
            'q' => self::SITE_URL,
        ]));

        $actor = Actor::query()->where('type', Actor::TYPE_FEED)->firstOrFail();
        $response->assertRedirect(route('actors.show', $actor));
        $this->assertSame(self::FEED_URL, $actor->feedSource?->feed_url);
    }

    public function test_following_a_feed_is_accepted_immediately_without_federation(): void
    {
        $viewer = $this->createFullAccount('feedfollow');

        Http::fake([
            self::FEED_URL => Http::response($this->atomFeed(), 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $this->actingAs($viewer)->get(route('search.create', ['q' => self::FEED_URL]));
        $actor = Actor::query()->where('uri', self::FEED_URL)->firstOrFail();

        Http::fake(); // nessuna consegna AP attesa

        $follow = app(FollowManager::class)->follow($viewer->actor, $actor);

        $this->assertSame(Follow::STATUS_ACCEPTED, $follow->status);
        $this->assertTrue(app(FollowManager::class)->isFollowing($viewer->actor, $actor));

        $feed = app(FeedQuery::class)->forActor($viewer->actor);
        $this->assertTrue($feed->getCollection()->contains(
            fn (Post $post) => $post->actor_id === $actor->id,
        ));
    }

    public function test_fetch_feeds_command_imports_new_entries_for_followed_feeds(): void
    {
        $viewer = $this->createFullAccount('feedcron');
        $updatedFeed = str_replace(
            '</feed>',
            '<entry><id>https://blog.example/posts/3</id><title>Terza</title><link href="https://blog.example/posts/3" rel="alternate"/><updated>2024-01-04T08:00:00Z</updated><summary>Nuova</summary></entry></feed>',
            $this->atomFeed(),
        );

        Http::fake([
            self::FEED_URL => Http::sequence()
                ->push($this->atomFeed(), 200, ['Content-Type' => 'application/atom+xml'])
                ->push($updatedFeed, 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $this->actingAs($viewer)->get(route('search.create', ['q' => self::FEED_URL]));
        $actor = Actor::query()->where('uri', self::FEED_URL)->firstOrFail();
        app(FollowManager::class)->follow($viewer->actor, $actor);

        $actor->feedSource->update(['last_fetched_at' => now()->subHours(2)]);

        $this->artisan('openbook:fetch-feeds', ['--limit' => 5, '--max-time' => 20])
            ->assertSuccessful();

        $this->assertTrue(Post::query()->where('uri', 'https://blog.example/posts/3')->exists());
        $this->assertSame(3, Post::query()->where('actor_id', $actor->id)->count());
    }

    public function test_feed_profile_shows_feed_badge_and_hides_messages(): void
    {
        $viewer = $this->createFullAccount('feedui');

        Http::fake([
            self::FEED_URL => Http::response($this->atomFeed(), 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $this->actingAs($viewer)->get(route('search.create', ['q' => self::FEED_URL]));
        $actor = Actor::query()->where('uri', self::FEED_URL)->firstOrFail();

        $response = $this->actingAs($viewer)->get(route('actors.show', $actor));
        $response->assertOk();
        $response->assertSee(__('openbook.actors.feed_badge'));
        $response->assertSee(__('openbook.actors.feed_notice', [], 'it'));
        $response->assertDontSee(route('messages.open_actor', $actor), false);
        $response->assertSee('Prima voce');
    }
}
