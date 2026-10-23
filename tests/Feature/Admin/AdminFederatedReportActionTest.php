<?php

namespace Tests\Feature\Admin;

use App\Application\Services\ReportManager;
use App\Domain\Accounts\User;
use App\Domain\Events\EventComment;
use App\Domain\Moderation\Report;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Infrastructure\Media\Media;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReportFixtures;
use Tests\TestCase;

class AdminFederatedReportActionTest extends TestCase
{
    use CreatesReportFixtures, RefreshDatabase;

    private function moderator(bool $admin = false): User
    {
        $user = $this->createFullAccount('moderator');
        $user->forceFill(['is_admin' => $admin, 'is_moderator' => ! $admin])->save();

        return $user;
    }

    public static function deletionCases(): array
    {
        $cases = [];
        foreach ([false, true] as $admin) {
            foreach ([1 => 'post', 2 => 'comment', 3 => 'event', 4 => 'event_comment'] as $index => $type) {
                $cases[($admin ? 'admin ' : 'moderator ').$type] = [$admin, $index];
            }
        }

        return $cases;
    }

    #[DataProvider('deletionCases')]
    public function test_staff_delete_only_the_selected_content_with_ordinary_federated_delete(bool $admin, int $index): void
    {
        Queue::fake();
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $author = $targets[0]['subject'];
        Follow::query()->create(['follower_id' => $sender->id, 'following_id' => $author->id, 'status' => Follow::STATUS_ACCEPTED, 'requested_at' => now(), 'accepted_at' => now()]);
        $subject = $targets[$index]['subject'];
        $media = null;
        if ($index >= 3) {
            Storage::fake('public');
            $media = Media::query()->create(['actor_id' => $author->id, 'disk' => 'public',
                'path' => 'test/reported.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 4]);
            Storage::disk('public')->put($media->path, 'test');
            $subject->attachments()->create(['media_id' => $media->id, 'position' => 0]);
        }
        $target = $report->targets()->where('type', $targets[$index]['type'])->sole();
        $mod = $this->moderator($admin);
        $this->actingAs($mod)->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee('value="'.$target->id.'"', false)
            ->assertSee(route('admin.users.index', ['q' => 'reported']));
        $this->post(route('admin.reports.action', $report), ['targets' => [$target->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('deleted', $subject->fresh()->status);
        if ($media !== null) {
            $this->assertDatabaseMissing('media', ['id' => $media->id]);
            Storage::disk('public')->assertMissing($media->path);
        }
        $this->assertSame(Report::STATUS_ACTIONED, $report->fresh()->status);
        $this->assertNotNull($report->fresh()->closed_at);
        $this->assertSame($mod->id, $report->fresh()->reviewed_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.target_deleted', 'subject_id' => $subject->id, 'actor_id' => $mod->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.actioned', 'subject_id' => $report->id]);
        foreach ([1, 2, 3, 4] as $other) {
            if ($other === $index || ($index === 3 && $other === 4)) {
                continue; // An event deletion follows the ordinary cascade to its comments.
            }
            $this->assertNotSame('deleted', $targets[$other]['subject']->fresh()->status);
        }
        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($author, $subject, $report, $sender): bool {
            $this->assertSame('Delete', $job->activity['type']);
            $this->assertSame('Tombstone', $job->activity['object']['type']);
            $this->assertSame($author->id, $job->signingActorId);
            $json = json_encode($job->activity, JSON_UNESCAPED_SLASHES);
            foreach ([$report->details, $report->remote_activity_uri, $sender->uri] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }

            return $job->activity['object']['id'] === ($subject instanceof Post ? route('posts.show', $subject) : $subject->uri ?? route('comments.show', $subject));
        });
        $count = Queue::pushed(DeliverActivityJob::class)->count();
        $audits = DB::table('audit_logs')->count();
        $this->post(route('admin.reports.action', $report), ['targets' => [$target->id]])->assertRedirect();
        $this->assertSame($count, Queue::pushed(DeliverActivityJob::class)->count());
        $this->assertDatabaseCount('audit_logs', $audits);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_foreign_targets_and_profiles_cannot_be_submitted_for_deletion(): void
    {
        Queue::fake();
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $other = $this->storeReport($sender, [$targets[1]], 'other');
        $selected = $report->targets()->where('type', 'post')->sole();
        $profile = $report->targets()->where('type', 'actor')->sole();
        $this->actingAs($this->moderator());
        foreach ([$other->targets()->sole()->id, $profile->id] as $invalid) {
            $this->post(route('admin.reports.action', $report), ['targets' => [$selected->id, $invalid]])
                ->assertSessionHasErrors('targets');
            $this->assertSame('published', $targets[1]['subject']->fresh()->status);
            $this->assertSame(Report::STATUS_OPEN, $report->fresh()->status);
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_subject_locality_is_rechecked_at_action_time(): void
    {
        Queue::fake();
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $selected = $report->targets()->where('type', 'post')->sole();
        $targets[1]['subject']->update(['actor_id' => $sender->id, 'remote_object_id' => 'https://remote.example/post/1']);
        $this->actingAs($this->moderator())->post(route('admin.reports.action', $report), ['targets' => [$selected->id]])
            ->assertSessionHasErrors('targets');
        $this->assertSame('published', $targets[1]['subject']->fresh()->status);
        $this->assertSame(Report::STATUS_OPEN, $report->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_removed_target_is_not_deleted_again_and_other_references_stay_untouched(): void
    {
        Queue::fake();
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $selected = $report->targets()->where('type', 'event_comment')->sole();
        $targets[4]['subject']->delete();
        $this->actingAs($this->moderator())->post(route('admin.reports.action', $report), ['targets' => [$selected->id]])->assertRedirect();
        $this->assertSame(Report::STATUS_ACTIONED, $report->fresh()->status);
        $this->assertSame('scheduled', $targets[3]['subject']->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.target_deleted']);
        Queue::assertNothingPushed();
    }

    public function test_profile_only_case_links_to_user_tools_and_can_close_without_automatic_sanctions(): void
    {
        Queue::fake();
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, [$targets[0]]);
        $mod = $this->moderator();
        $this->actingAs($mod)->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee(route('admin.users.index', ['q' => 'reported']))->assertDontSee('name="targets[]"', false);
        app(ReportManager::class)->markReviewed($report, $mod);
        $this->post(route('admin.reports.action', $report))->assertRedirect();
        $this->assertSame(Report::STATUS_ACTIONED, $report->fresh()->status);
        $this->assertSame('active', $targets[0]['subject']->fresh()->status);
        Queue::assertNothingPushed();
    }

    #[DataProvider('deletionCases')]
    public function test_regular_users_cannot_apply_report_actions_even_with_valid_target_ids(bool $unused, int $index): void
    {
        Queue::fake();
        [$sender, $targets] = $this->reportFixture();
        $report = $this->storeReport($sender, $targets);
        $target = $report->targets()->where('type', $targets[$index]['type'])->sole();
        $this->actingAs($targets[0]['subject']->user)->post(route('admin.reports.action', $report), ['targets' => [$target->id]])->assertForbidden();
        $this->assertNotSame('deleted', $targets[$index]['subject']->fresh()->status);
        $this->assertSame('open', $report->fresh()->status);
        Queue::assertNothingPushed();
    }

    public static function eventRoles(): array
    {
        return ['moderator' => [false], 'admin' => [true]];
    }

    #[DataProvider('eventRoles')]
    public function test_staff_can_use_the_ordinary_event_and_event_comment_delete_routes(bool $admin): void
    {
        Queue::fake();
        [$sender, $targets] = $this->reportFixture();
        $mod = $this->moderator($admin);
        $this->actingAs($mod)->delete(route('event-comments.destroy', $targets[4]['subject']))->assertRedirect();
        $this->assertSame(EventComment::STATUS_DELETED, $targets[4]['subject']->fresh()->status);
        $this->delete(route('events.destroy', $targets[3]['subject']))->assertRedirect();
        $this->assertSame('deleted', $targets[3]['subject']->fresh()->status);
        // Delete permission does not grant editing or access to private bodies.
        $this->get(route('events.edit', $targets[3]['subject']))->assertForbidden();
        $targets[3]['subject']->update(['status' => 'scheduled', 'remote_object_id' => 'https://remote.example/events/1', 'actor_id' => $sender->id]);
        $this->delete(route('events.destroy', $targets[3]['subject']))->assertForbidden();
    }
}
