<?php

namespace Tests\Feature\Console;

use App\Federation\Inbox\InboxItem;
use App\Jobs\Federation\ProcessInboxActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReprocessInboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_every_ignored_item_without_touching_other_states(): void
    {
        Queue::fake();
        $ignored = $this->inboxItem('ignored', InboxItem::STATUS_IGNORED, 'vecchio errore');
        $secondIgnored = $this->inboxItem('ignored-2', InboxItem::STATUS_IGNORED);
        $processed = $this->inboxItem('processed', InboxItem::STATUS_PROCESSED);

        $this->artisan('openbook:reprocess-inbox', ['--chunk' => 1])
            ->expectsOutputToContain('2 attività ignorate rimesse nella coda inbox.')
            ->assertSuccessful();

        $ignored->refresh();
        $this->assertSame(InboxItem::STATUS_PENDING, $ignored->status);
        $this->assertNull($ignored->processed_at);
        $this->assertNull($ignored->error);
        $this->assertSame(InboxItem::STATUS_PENDING, $secondIgnored->fresh()->status);
        $this->assertSame(InboxItem::STATUS_PROCESSED, $processed->fresh()->status);

        Queue::assertPushed(ProcessInboxActivityJob::class, 2);
        Queue::assertPushed(ProcessInboxActivityJob::class, fn (ProcessInboxActivityJob $job): bool => $job->inboxItemId === $ignored->id);
        Queue::assertPushed(ProcessInboxActivityJob::class, fn (ProcessInboxActivityJob $job): bool => $job->inboxItemId === $secondIgnored->id);
    }

    public function test_it_is_a_no_op_when_no_ignored_rows_exist(): void
    {
        Queue::fake();
        $this->inboxItem('processed', InboxItem::STATUS_PROCESSED);

        $this->artisan('openbook:reprocess-inbox')
            ->expectsOutputToContain('0 attività ignorate rimesse nella coda inbox.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
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
