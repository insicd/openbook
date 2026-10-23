<?php

namespace Tests\Concerns;

use App\Application\Services\DatabaseMaintenanceService;
use App\Application\Services\ReportManager;
use App\Domain\Moderation\Report;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

trait TestsReportRetention
{
    public function test_cleanup_expires_only_cases_with_old_known_terminal_closures(): void
    {
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
        [$sender, $targets] = $this->reportFixture();
        $cutoff = now()->subMonthsNoOverflow(12);
        $expired = [];
        $preserved = [];
        foreach ([
            ['expired-dismissed', 'dismissed', $cutoff->copy()->subSecond(), true],
            ['expired-actioned', 'actioned', $cutoff->copy()->subDay(), true],
            ['boundary', 'dismissed', $cutoff, false],
            ['recent', 'actioned', $cutoff->copy()->addSecond(), false],
            ['old-open', 'open', $cutoff->copy()->subYear(), false],
            ['old-reviewed', 'reviewed', $cutoff->copy()->subYear(), false],
            ['undated', 'dismissed', null, false],
        ] as [$id, $status, $closedAt, $expires]) {
            $report = $this->storeReport($sender, $targets, $id);
            $report->update(['status' => $status, 'closed_at' => $closedAt, 'created_at' => now()->subYears(3)]);
            $expires ? $expired[] = $report : $preserved[] = $report;
        }
        // The same retention applies to local cases closed through the shared workflow.
        $localReporter = $this->createFullAccount('localreporter');
        $local = app(ReportManager::class)->reportPost($localReporter, $targets[1]['subject'], ['reason' => 'other']);
        $local->update(['status' => 'actioned', 'closed_at' => $cutoff->copy()->subDay()]);
        $service = app(DatabaseMaintenanceService::class);
        $this->assertSame(3, $service->purgeableCount('reports'));
        $snapshot = collect($service->snapshots())->firstWhere('key', 'reports');
        $this->assertSame(3, $snapshot['purgeable_count']);
        $this->assertStringContainsString('12', $snapshot['description']);
        $this->assertSame(3, $service->purgeKey('reports'));
        foreach ([...$expired, $local] as $report) {
            $this->assertDatabaseMissing('reports', ['id' => $report->id]);
            $this->assertDatabaseMissing('report_targets', ['report_id' => $report->id]);
        }
        foreach ($preserved as $report) {
            $this->assertDatabaseHas('reports', ['id' => $report->id]);
        }
        foreach ($expired as $report) {
            $hash = hash('sha256', $report->remote_activity_uri);
            $this->assertDatabaseHas('report_receipts', ['activity_hash' => $hash, 'report_id' => null]);
            $this->assertNull(app(ReportManager::class)->recordFederated($sender, $report->remote_activity_uri, 'Replay', $targets));
        }
        $this->assertSame(['activity_hash', 'report_id'], array_keys((array) DB::table('report_receipts')->first()));
        $this->assertSame(0, $service->purgeKey('reports'));
    }

    public function test_retention_uses_configured_calendar_months_and_reopening_resets_the_clock(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 31)->startOfDay());
        config(['openbook.maintenance.closed_report_retention_months' => 1]);
        [$sender, $targets] = $this->reportFixture();
        $expired = $this->storeReport($sender, $targets, 'expired');
        $expired->update(['status' => 'dismissed', 'closed_at' => now()->setDate(2026, 2, 27)]);
        $boundary = $this->storeReport($sender, $targets, 'boundary');
        $boundary->update(['status' => 'dismissed', 'closed_at' => now()->setDate(2026, 2, 28)]);
        $reopened = $this->storeReport($sender, $targets, 'reopened');
        $reopened->update(['status' => 'actioned', 'closed_at' => now()->subYear()]);
        $moderator = $this->createFullAccount('moderator');
        $moderator->forceFill(['is_moderator' => true])->save();
        app(ReportManager::class)->markReviewed($reopened, $moderator);
        $this->assertNull($reopened->fresh()->closed_at);
        $service = app(DatabaseMaintenanceService::class);
        $this->assertSame(1, $service->purgeKey('reports'));
        $this->assertDatabaseMissing('reports', ['id' => $expired->id]);
        $this->assertDatabaseHas('reports', ['id' => $boundary->id]);
        $this->assertDatabaseHas('reports', ['id' => $reopened->id]);
        app(ReportManager::class)->dismiss($reopened, $moderator);
        $this->assertTrue($reopened->fresh()->closed_at->equalTo(now()));
        $this->assertSame(0, $service->purgeKey('reports'));
        // Match the minimum-one convention of the existing maintenance settings.
        config(['openbook.maintenance.closed_report_retention_months' => 0]);
        $this->assertSame(0, $service->purgeableCount('reports'));
    }

    public function test_automatic_positive_closures_expire_from_the_actual_closure_date(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $targets[1]['subject']->update(['status' => 'deleted']);
        $report = $this->storeReport($sender, [$targets[0], $targets[1]]);
        $this->assertSame(Report::CLOSURE_UNAVAILABLE, $report->closure_reason);
        $this->assertNull($report->reviewed_by);
        $service = app(DatabaseMaintenanceService::class);
        $this->assertSame(0, $service->purgeKey('reports'));
        $this->travelTo(now()->addMonthsNoOverflow(12)->addDay());
        $this->assertSame(1, $service->purgeKey('reports'));
        $this->assertDatabaseHas('report_receipts', ['activity_hash' => hash('sha256', $report->remote_activity_uri), 'report_id' => null]);
    }

    public function test_staff_transition_does_not_revive_a_case_purged_since_it_was_loaded(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $report->update(['status' => 'dismissed', 'closed_at' => now()->subMonthsNoOverflow(13)]);
        $moderator = $this->createFullAccount('moderator');
        $moderator->forceFill(['is_moderator' => true])->save();
        $this->assertSame(1, app(DatabaseMaintenanceService::class)->purgeKey('reports'));
        try {
            app(ReportManager::class)->markReviewed($report, $moderator);
            $this->fail('An expired case must remain absent.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('reports', ['id' => $report->id]);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }
}
