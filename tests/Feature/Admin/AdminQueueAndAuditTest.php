<?php

namespace Tests\Feature\Admin;

use App\Federation\Inbox\InboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class AdminQueueAndAuditTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_admin_can_view_queue_and_audit_pages(): void
    {
        $admin = $this->createFullAccount('admincoda');
        $admin->forceFill(['is_admin' => true, 'is_moderator' => true])->save();

        DB::table('jobs')->insert(['queue' => 'pending-demo', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $uuid = '01800000-0000-7000-8000-000000000001';
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'failed-demo', 'payload' => '{}', 'exception' => 'Failure demo', 'failed_at' => now()]);
        InboxItem::query()->create(['remote_activity_uri' => 'https://remote.example/activity', 'actor_uri' => 'https://remote.example/actor', 'activity_type' => 'Create', 'payload' => '{}', 'signature_valid' => true, 'received_at' => now()]);
        $this->actingAs($admin)->get(route('admin.queue.index'))->assertOk()
            ->assertSee('pending-demo')->assertDontSee('Failure demo')->assertDontSee('https://remote.example/actor');
        $failedUrl = route('admin.queue.index', ['tab' => 'failed']);
        $this->get($failedUrl)->assertOk()->assertSee('Failure demo')->assertDontSee('pending-demo')->assertSee('aria-current="page"', false);
        $this->get(route('admin.queue.index', ['tab' => 'inbox']))->assertOk()->assertSee('https://remote.example/actor')->assertDontSee('pending-demo')->assertDontSee('Failure demo');
        Artisan::spy();
        $this->post(route('admin.queue.retry', $uuid))->assertRedirect($failedUrl);
        $this->post(route('admin.queue.forget', $uuid))->assertRedirect($failedUrl);
        $this->post(route('admin.queue.retry_all'))->assertRedirect($failedUrl);
        Artisan::shouldHaveReceived('call')->with('queue:retry', ['id' => [$uuid]])->once();
        Artisan::shouldHaveReceived('call')->with('queue:forget', ['id' => $uuid])->once();
        Artisan::shouldHaveReceived('call')->with('queue:retry', ['id' => ['all']])->once();

        $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk();
    }

    public function test_moderators_cannot_view_queue_or_audit(): void
    {
        $mod = $this->createFullAccount('modcoda');
        $mod->forceFill(['is_moderator' => true])->save();

        $this->actingAs($mod)->get(route('admin.queue.index'))->assertForbidden();
        $this->actingAs($mod)->get(route('admin.audit.index'))->assertForbidden();
    }
}
