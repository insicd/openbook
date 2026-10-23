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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

trait TestsFederatedReportPersistence
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

    public function test_a_federated_case_stores_all_five_target_types_without_a_local_reporter(): void
    {
        Queue::fake();
        [$sender, $targets] = $this->reportFixture();
        $targets[] = $targets[1];
        $report = $this->storeReport($sender, $targets);

        $this->assertSame(Report::ORIGIN_FEDERATED, $report->origin);
        $this->assertNull($report->reporter_id);
        $this->assertNull($report->post_id);
        $this->assertNull($report->comment_id);
        $this->assertTrue($report->reporterActor->is($sender));
        $this->assertSame($sender->uri, $report->reporter_uri);
        $this->assertSame('remote.example', $report->reporter_domain);
        $this->assertSame('Reason', $report->details);
        $this->assertSame(Report::STATUS_OPEN, $report->status);
        $this->assertNull($report->closed_at);
        $this->assertCount(5, $report->targets);
        foreach ($report->targets as $target) {
            $relation = $target->type === ReportTarget::TYPE_EVENT_COMMENT ? 'eventComment' : $target->type;
            $this->assertNotNull($target->$relation);
            $this->assertFalse($target->unavailable);
        }
        $this->assertDatabaseCount('report_receipts', 1);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_replay_preserves_the_original_case_and_does_not_recreate_a_purged_case(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $mod = $this->createFullAccount('moderator');
        $mod->forceFill(['is_moderator' => true])->save();
        app(ReportManager::class)->dismiss($report, $mod);
        $replay = $this->storeReport($sender, [$targets[0]], details: 'Changed reason');
        $this->assertSame($report->id, $replay->id);
        $this->assertSame(Report::STATUS_DISMISSED, $replay->status);
        $this->assertSame('Reason', $replay->details);
        $this->assertCount(5, $replay->targets);
        $this->assertNotSame($report->id, $this->storeReport($sender, $targets, 'two')->id);

        $report->delete();
        $this->assertNull($this->storeReport($sender, $targets));
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('report_receipts', 2);
        $this->assertDatabaseHas('report_receipts', [
            'activity_hash' => hash('sha256', 'https://remote.example/reports/one'), 'report_id' => null,
        ]);
    }

    public function test_removed_content_closes_positively_but_profiles_and_mixed_cases_remain_open(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $targets[1]['subject']->update(['status' => 'deleted']);
        $missing = ['type' => ReportTarget::TYPE_COMMENT, 'uri' => 'https://openbook.test/comments/missing', 'subject' => null];
        $report = $this->storeReport($sender, [$targets[0], $targets[1], $missing]);
        $this->assertSame(Report::STATUS_ACTIONED, $report->status);
        $this->assertSame(Report::CLOSURE_UNAVAILABLE, $report->closure_reason);
        $this->assertNotNull($report->closed_at);
        $this->assertNull($report->reviewed_at);
        $this->assertNull($report->reviewed_by);
        $this->assertSame(2, $report->targets()->where('unavailable', true)->count());

        $profile = $this->storeReport($sender, [$targets[0]], 'profile', '');
        $this->assertSame(Report::STATUS_OPEN, $profile->status);
        $this->assertNull($profile->details);
        $targets[3]['subject']->update(['visibility' => 'direct']);
        $mixed = $this->storeReport($sender, [$targets[1], $targets[3], $missing], 'mixed');
        $this->assertSame(Report::STATUS_OPEN, $mixed->status);
        $this->assertNull($mixed->closed_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_federated_motivation_is_not_truncated_to_the_local_form_or_mysql_text_limit(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $details = str_repeat('é', 40000);
        $report = $this->storeReport($sender, $targets, details: $details);
        $this->assertSame($details, $report->fresh()->details);
    }

    public function test_deleting_subjects_or_the_sender_preserves_the_case_and_uris(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $targets[1]['subject']->delete();
        $targets[3]['subject']->delete();
        $targets[0]['subject']->delete();
        $senderUri = $sender->uri;
        $sender->delete();

        $report->refresh();
        $this->assertNull($report->reporter_actor_id);
        $this->assertSame($senderUri, $report->reporter_uri);
        $this->assertCount(5, $report->targets);
        foreach ($report->targets as $target) {
            $this->assertContains($target->uri, array_column($targets, 'uri'));
            foreach (['actor_id', 'post_id', 'comment_id', 'event_id', 'event_comment_id'] as $column) {
                $this->assertNull($target->$column);
            }
        }
    }

    public function test_case_targets_and_receipt_roll_back_together_on_a_write_failure(): void
    {
        [$sender, $targets] = $this->reportFixture();
        ReportTarget::creating(function (): void {
            throw new RuntimeException('Simulated target write failure');
        });
        try {
            $this->storeReport($sender, $targets);
            $this->fail('Expected target persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated target write failure', $exception->getMessage());
        } finally {
            ReportTarget::flushEventListeners();
        }
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('report_targets', 0);
        $this->assertDatabaseCount('report_receipts', 0);
        $this->assertNotNull($this->storeReport($sender, $targets));
    }

    public function test_remote_and_non_person_subjects_are_rejected_without_writing_a_receipt(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $remotePost = $targets[1]['subject']->replicate();
        $remotePost->uri = 'https://remote.example/posts/one';
        $remotePost->save();
        $group = $this->createRemoteActor('group', 'openbook.test', ['is_local' => true, 'type' => Actor::TYPE_GROUP]);
        foreach ([
            ['type' => ReportTarget::TYPE_POST, 'uri' => $remotePost->uri, 'subject' => $remotePost],
            ['type' => ReportTarget::TYPE_ACTOR, 'uri' => $sender->uri, 'subject' => $sender],
            ['type' => ReportTarget::TYPE_ACTOR, 'uri' => $group->uri, 'subject' => $group],
            ['type' => ReportTarget::TYPE_EVENT, 'uri' => $targets[1]['uri'], 'subject' => $targets[1]['subject']],
        ] as $target) {
            try {
                $this->storeReport($sender, [$target]);
                $this->fail('Expected inconsistent or non-local target to fail.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('report_receipts', 0);
                $this->assertDatabaseCount('reports', 0);
            }
        }
    }

    public function test_review_does_not_close_the_case_and_manual_closure_has_its_own_timestamp(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $mod = $this->createFullAccount('moderator');
        $mod->forceFill(['is_moderator' => true])->save();
        $manager = app(ReportManager::class);
        $this->travelTo(now()->startOfSecond());
        $manager->markReviewed($report, $mod);
        $this->assertNull($report->closed_at);
        $reviewedAt = $report->reviewed_at->copy();
        $this->travel(5)->minutes();
        $manager->markActioned($report, $mod);
        $this->assertTrue($report->closed_at->greaterThan($reviewedAt));
        $this->assertSame($mod->id, $report->reviewed_by);
        $this->assertNull($report->closure_reason);
        $manager->markReviewed($report, $mod);
        $this->assertNull($report->closed_at);
        $this->travelBack();
    }

    public function test_migration_round_trip_preserves_old_local_reports_and_unique_constraints(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $reporter = $this->createFullAccount('localreporter');
        $local = app(ReportManager::class)->reportPost($reporter, $targets[1]['subject'], ['reason' => 'spam']);
        $migration = require database_path('migrations/2026_10_23_000000_extend_reports_for_federation.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('report_targets'));
        $this->assertFalse(Schema::hasColumn('reports', 'origin'));
        $this->assertDatabaseHas('reports', ['id' => $local->id, 'reporter_id' => $reporter->id]);
        $migration->up();
        $this->assertSame(Report::ORIGIN_LOCAL, $local->fresh()->origin);
        $this->storeReport($sender, $targets);
        $migration->down();
        $this->assertDatabaseCount('reports', 1);
        $migration->up();
        $again = app(ReportManager::class)->reportPost($reporter, $targets[1]['subject'], ['reason' => 'other']);
        $this->assertSame($local->id, $again->id);
        $this->assertSame('spam', $again->reason);
        $this->assertDatabaseCount('report_receipts', 0);
    }
}
