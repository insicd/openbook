<?php

namespace Tests\Concerns;

use App\Application\Services\ReportManager;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Moderation\Report;
use App\Domain\Moderation\ReportTarget;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;

trait CreatesReportFixtures
{
    use CreatesAccounts, CreatesRemoteActors;

    private function reportFixture(): array
    {
        $author = $this->createFullAccount('reported')->actor;
        $sender = $this->createRemoteActor('instance', 'remote.example', ['type' => Actor::TYPE_APPLICATION]);
        $post = Post::query()->create(['actor_id' => $author->id, 'body' => 'Post',
            'visibility' => 'public', 'status' => 'published', 'published_at' => now()]);
        $comment = Comment::query()->create(['post_id' => $post->id, 'actor_id' => $author->id,
            'body' => 'Comment', 'status' => 'published']);
        $event = Event::query()->create(['actor_id' => $author->id, 'uri' => 'https://openbook.test/eventi/example',
            'name' => 'Event', 'start_at' => now()->addDay(), 'visibility' => 'public', 'status' => 'scheduled']);
        $event->update(['uri' => route('events.show', $event)]);
        $eventComment = EventComment::query()->create(['event_id' => $event->id, 'actor_id' => $author->id,
            'uri' => 'https://openbook.test/event-comments/example', 'body' => 'Event comment', 'status' => 'published']);

        $eventComment->update(['uri' => route('event-comments.show', $eventComment)]);

        $targets = [
            ['type' => ReportTarget::TYPE_ACTOR, 'uri' => $author->uri, 'subject' => $author],
            ['type' => ReportTarget::TYPE_POST, 'uri' => route('posts.show', $post), 'subject' => $post],
            ['type' => ReportTarget::TYPE_COMMENT, 'uri' => route('comments.show', $comment), 'subject' => $comment],
            ['type' => ReportTarget::TYPE_EVENT, 'uri' => $event->uri, 'subject' => $event],
            ['type' => ReportTarget::TYPE_EVENT_COMMENT, 'uri' => $eventComment->uri, 'subject' => $eventComment],
        ];

        return [$sender, $targets];
    }

    private function storeReport(Actor $sender, array $targets, string $id = 'one', ?string $details = 'Reason'): ?Report
    {
        return app(ReportManager::class)->recordFederated($sender, 'https://remote.example/reports/'.$id, $details, $targets);
    }
}
