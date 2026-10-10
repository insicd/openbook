<?php

namespace Tests\Feature\Console;

use App\Federation\Inbox\InboxItem;
use App\Jobs\Federation\ProcessInboxActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReprocessInboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_ignored_and_pending_items_without_touching_processed_or_failed(): void
    {
        Queue::fake();
        $ignored = $this->inboxItem('ignored', InboxItem::STATUS_IGNORED, 'vecchio errore');
        $secondIgnored = $this->inboxItem('ignored-2', InboxItem::STATUS_IGNORED);
        $pending = $this->inboxItem('pending', InboxItem::STATUS_PENDING, 'stale error');
        $processed = $this->inboxItem('processed', InboxItem::STATUS_PROCESSED);
        $failed = $this->inboxItem('failed', InboxItem::STATUS_FAILED, 'failure');

        $this->artisan('openbook:reprocess-inbox', ['--chunk' => 1])
            ->expectsOutputToContain('3 attività ignorate o pendenti accodate nella coda inbox.')
            ->assertSuccessful();

        $ignored->refresh();
        $this->assertSame(InboxItem::STATUS_PENDING, $ignored->status);
        $this->assertNull($ignored->processed_at);
        $this->assertNull($ignored->error);
        $this->assertSame(InboxItem::STATUS_PENDING, $secondIgnored->fresh()->status);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $processed->fresh()->status);

        $this->assertSame(InboxItem::STATUS_PENDING, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->error);
        $this->assertSame(InboxItem::STATUS_FAILED, $failed->fresh()->status);
        $this->assertSame('failure', $failed->fresh()->error);

        Queue::assertPushed(ProcessInboxActivityJob::class, 3);
        Queue::assertPushed(ProcessInboxActivityJob::class, fn (ProcessInboxActivityJob $job): bool => $job->inboxItemId === $pending->id);
        Queue::assertPushed(ProcessInboxActivityJob::class, fn (ProcessInboxActivityJob $job): bool => $job->inboxItemId === $ignored->id);
        Queue::assertPushed(ProcessInboxActivityJob::class, fn (ProcessInboxActivityJob $job): bool => $job->inboxItemId === $secondIgnored->id);
    }

    public function test_it_is_a_no_op_when_only_processed_or_failed_rows_exist(): void
    {
        Queue::fake();
        $this->inboxItem('processed', InboxItem::STATUS_PROCESSED);
        $this->inboxItem('failed', InboxItem::STATUS_FAILED);

        $this->artisan('openbook:reprocess-inbox')
            ->expectsOutputToContain('0 attività ignorate o pendenti accodate nella coda inbox.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_it_enqueues_imported_pending_items_without_existing_jobs(): void
    {
        config(['queue.default' => 'database']);
        $pending = $this->inboxItem('imported', InboxItem::STATUS_PENDING);
        $this->assertDatabaseCount('jobs', 0);

        $this->artisan('openbook:reprocess-inbox')
            ->expectsOutputToContain('1 attività ignorate o pendenti accodate nella coda inbox.')
            ->assertSuccessful();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'inbox']);
        $payload = json_decode(DB::table('jobs')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command']);
        $this->assertInstanceOf(ProcessInboxActivityJob::class, $job);
        $this->assertSame($pending->id, $job->inboxItemId);
        $this->assertSame(InboxItem::STATUS_PENDING, $pending->fresh()->status);
    }

    private function inboxItem(string $suffix, string $status, ?string $error = null): InboxItem
    {
        return InboxItem::query()->create([
            'is_shared' => true,
            'remote_activity_uri' => 'https://remote.example/activities/'.$suffix,
            'activity_type' => 'Create',
            'actor_uri' => 'https://remote.example/users/alice',
            'payload' => json_encode(['type' => 'Create'], JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => $status,
            'error' => $error,
            'received_at' => now(),
            'processed_at' => $status === InboxItem::STATUS_PENDING ? null : now(),
        ]);
    }
}
