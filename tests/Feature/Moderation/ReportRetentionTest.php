<?php

namespace Tests\Feature\Moderation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesReportFixtures;
use Tests\Concerns\TestsReportRetention;
use Tests\TestCase;

class ReportRetentionTest extends TestCase
{
    use CreatesReportFixtures, RefreshDatabase, TestsReportRetention;

    public function test_administrator_can_preview_and_purge_expired_cases_but_moderator_cannot(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $report->update(['status' => 'dismissed', 'closed_at' => now()->subMonthsNoOverflow(13)]);
        $staff = $this->createFullAccount('staff');
        $staff->forceFill(['is_moderator' => true])->save();
        $this->actingAs($staff)->post(route('admin.database.purge'), ['table' => 'reports'])->assertForbidden();
        $this->assertDatabaseHas('reports', ['id' => $report->id]);
        $staff->forceFill(['is_admin' => true])->save();
        $this->get(route('admin.database.index', ['tab' => 'maintenance']))->assertOk()
            ->assertViewHas('tables', fn ($tables) => collect($tables)->firstWhere('key', 'reports')['purgeable_count'] === 1);
        $this->post(route('admin.database.purge'), ['table' => 'reports'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
        $audit = DB::table('audit_logs')->where('action', 'database.purge')->sole();
        $metadata = json_decode($audit->meta, true);
        $this->assertSame(12, $metadata['closed_report_retention_months']);
        $this->assertSame(1, $metadata['deleted']);
    }

    public function test_retention_index_rolls_back_without_altering_cases_or_receipts(): void
    {
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $migration = require database_path('migrations/2026_10_23_000003_add_report_retention_index.php');
        $migration->down();
        $this->assertFalse(Schema::hasIndex('reports', 'reports_closed_at_status_index'));
        $migration->up();
        $this->assertTrue(Schema::hasIndex('reports', 'reports_closed_at_status_index'));
        $this->assertDatabaseHas('reports', ['id' => $report->id]);
        $this->assertDatabaseCount('report_receipts', 1);
    }
}
