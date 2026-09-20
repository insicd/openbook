<?php

namespace Tests\Feature\Federation;

use App\Application\Queries\FeedQuery;
use App\Domain\Events\Event;
use App\Domain\Federation\Relay;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RelayReceptionTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_public_relay_post_is_imported_without_followers_and_reaches_world_and_followed_hashtag_feed(): void
    {
        $viewer = $this->createFullAccount('relayviewer');
        $author = $this->createRemoteActor('relayauthor', 'origin.example');
        $relay = $this->relay();
        $activity = $this->postActivity($author, public: true);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($activity, $author, $relay));

        $post = Post::query()->where('uri', $activity['object']['id'])->firstOrFail();
        $hashtag = Hashtag::query()->where('name', 'relaytest')->firstOrFail();
        $this->assertSame(0, $post->announces_count);
        $this->assertDatabaseMissing('announces', [
            'post_id' => $post->id,
        ]);
        $this->assertTrue(app(FeedQuery::class)->world()->getCollection()->contains('id', $post->id));
        $this->assertFalse(app(FeedQuery::class)->forActor($viewer->actor)->getCollection()->contains('id', $post->id));

        $viewer->actor->followedHashtags()->attach($hashtag->id);

        $this->assertTrue(app(FeedQuery::class)->forActor($viewer->actor)->getCollection()->contains('id', $post->id));
    }

    public function test_an_unlisted_post_received_from_a_relay_is_ignored(): void
    {
        $author = $this->createRemoteActor('relayunlisted', 'origin.example');
        $activity = $this->postActivity($author, public: false);

        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process($activity, $author, $this->relay()));
        $this->assertDatabaseMissing('posts', ['uri' => $activity['object']['id']]);
    }

    public function test_disabling_a_relay_before_processing_removes_its_relevance_bypass(): void
    {
        $author = $this->createRemoteActor('relaydisabled', 'origin.example');
        $activity = $this->postActivity($author, public: true);
        $relay = $this->relay();
        $relay->update(['state' => Relay::STATE_IDLE]);

        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process($activity, $author, $relay));
        $this->assertDatabaseMissing('posts', ['uri' => $activity['object']['id']]);
    }

    public function test_a_public_event_received_from_a_relay_is_imported_but_an_unlisted_one_is_not(): void
    {
        $author = $this->createRemoteActor('relayevents', 'events.example');
        $relay = $this->relay();
        $public = $this->eventActivity($author, 'public', true);
        $unlisted = $this->eventActivity($author, 'unlisted', false);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $this->process($public, $author, $relay));
        $this->assertSame(InboxItem::STATUS_IGNORED, $this->process($unlisted, $author, $relay));
        $this->assertDatabaseHas('events', [
            'uri' => $public['object']['id'],
            'visibility' => Event::VISIBILITY_PUBLIC,
        ]);
        $event = Event::query()->where('uri', $public['object']['id'])->firstOrFail();
        $this->assertDatabaseMissing('event_announces', [
            'event_id' => $event->id,
        ]);
        $this->assertDatabaseMissing('events', ['uri' => $unlisted['object']['id']]);
    }

    private function relay(): Relay
    {
        $url = 'https://relay.example/inbox';

        return Relay::query()->firstOrCreate(['inbox_url_hash' => hash('sha256', $url)], [
            'protocol' => Relay::PROTOCOL_MASTODON,
            'actor_uri' => 'https://relay.example/actor',
            'inbox_url' => $url,
            'state' => Relay::STATE_ACCEPTED,
            'receive_enabled' => true,
            'publish_enabled' => true,
            'accepted_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function postActivity(Actor $author, bool $public): array
    {
        $suffix = $public ? 'public' : 'unlisted';
        $audience = $public
            ? ['https://www.w3.org/ns/activitystreams#Public']
            : [$author->uri.'/followers'];

        return [
            'id' => $author->uri.'/statuses/'.$suffix.'/activity',
            'type' => 'Create',
            'actor' => $author->uri,
            'object' => [
                'id' => $author->uri.'/statuses/'.$suffix,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Contenuto dal relay #relaytest</p>',
                'published' => now()->toAtomString(),
                'to' => $audience,
                'tag' => [['type' => 'Hashtag', 'name' => '#relaytest']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function eventActivity(Actor $author, string $suffix, bool $public): array
    {
        return [
            'id' => $author->uri.'/events/'.$suffix.'/activity',
            'type' => 'Create',
            'actor' => $author->uri,
            'object' => [
                'id' => $author->uri.'/events/'.$suffix,
                'type' => 'Event',
                'actor' => $author->uri,
                'attributedTo' => $author->uri,
                'name' => 'Evento '.$suffix,
                'content' => '<p>Descrizione.</p>',
                'startTime' => now()->addMonth()->toAtomString(),
                'published' => now()->toAtomString(),
                'to' => $public
                    ? ['https://www.w3.org/ns/activitystreams#Public']
                    : [$author->uri.'/followers'],
            ],
        ];
    }

    /** @param array<string, mixed> $activity */
    private function process(array $activity, Actor $author, Relay $relay): string
    {
        $item = InboxItem::query()->create([
            'relay_id' => $relay->id,
            'is_shared' => true,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => $activity['type'],
            'actor_uri' => $author->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        return app(InboxActivityProcessor::class)->process($item);
    }
}
