<?php

namespace Tests\Feature;

use App\Application\Services\StatsDataset;
use App\Domain\Communities\Community;
use App\Domain\Events\Event;
use App\Domain\Posts\Hashtag;
use App\Domain\Posts\Post;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_guests_cannot_read_the_page_or_datasets(): void
    {
        $this->get('/stats')->assertRedirect(route('login'));
        $this->getJson('/stats/ritmo')->assertUnauthorized();
        $this->getJson('/stats/lingue')->assertUnauthorized();
        $this->getJson('/stats/domini')->assertUnauthorized();
        $this->getJson('/stats/settimane')->assertUnauthorized();
    }

    public function test_an_ordinary_user_can_read_stats_without_aggregates_in_the_html_response(): void
    {
        $user = $this->createFullAccount('statsviewer');
        $this->assertFalse($user->isStaff());
        $aggregates = [];
        DB::listen(function ($query) use (&$aggregates): void {
            if (str_contains($query->sql, ' AS bucket') || str_contains($query->sql, ' AS week')) {
                $aggregates[] = $query->sql;
            }
        });
        $this->actingAs($user)->get('/stats')->assertOk()
            ->assertSee('data-stats-card="rhythm"', false)
            ->assertSee('data-stats-card="languages"', false);
        $this->assertSame([], $aggregates);
        $this->getJson('/stats/ritmo')->assertOk()->assertJsonPath('metric', 'rhythm');
        $this->getJson('/stats/lingue')->assertOk()->assertJsonPath('metric', 'languages');
        $this->getJson('/stats/domini')->assertOk()->assertJsonPath('metric', 'domains');
        $this->getJson('/stats/settimane')->assertOk()->assertJsonPath('metric', 'weekly');
    }

    public function test_hourly_buckets_are_complete_utc_hours_and_results_are_not_cached(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 12:37:00 UTC');
        $author = $this->createFullAccount('statshours');
        $author->actor->forceFill(['discoverable' => true, 'indexable' => true])->save();
        $attributes = [
            'actor_id' => $author->actor->id, 'body' => 'Example', 'status' => 'published',
            'visibility' => 'public', 'published_at' => '2026-10-25 11:30:00',
        ];
        Post::create($attributes);
        Post::create(array_merge($attributes, ['published_at' => '2026-10-25 12:00:00']));
        Post::create(array_merge($attributes, ['published_at' => '2026-10-10 11:59:59']));
        $data = app(StatsDataset::class)->rhythm();
        $this->assertCount(360, $data['rows']);
        $this->assertSame('2026-10-10T12:00:00Z', $data['rows'][0]['at']);
        $this->assertSame('2026-10-25T11:00:00Z', $data['rows'][359]['at']);
        $this->assertSame(1, array_sum(array_column($data['rows'], 'total')));
        foreach ($data['rows'] as $index => $row) {
            if ($index > 0) {
                $this->assertSame(3600, strtotime($row['at']) - strtotime($data['rows'][$index - 1]['at']));
            }
        }
        Post::create($attributes);
        $this->assertSame(2, array_sum(array_column(app(StatsDataset::class)->rhythm()['rows'], 'total')));
        $this->actingAs($author)->getJson('/stats/ritmo')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_non_public_posts_and_authors_who_opted_out_are_excluded(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:30:00 UTC');
        $author = $this->createFullAccount('statsprivacy');
        $author->actor->forceFill(['discoverable' => true, 'indexable' => true])->save();
        foreach (['public', 'unlisted', 'followers', 'direct'] as $visibility) {
            Post::create([
                'actor_id' => $author->actor->id, 'body' => 'Example', 'status' => 'published',
                'visibility' => $visibility, 'published_at' => '2026-10-22 14:00:00',
            ]);
        }
        $this->assertSame(1, array_sum(array_column(app(StatsDataset::class)->rhythm()['rows'], 'total')));
        $author->actor->forceFill(['indexable' => false])->save();
        $this->assertSame(0, array_sum(array_column(app(StatsDataset::class)->rhythm()['rows'], 'total')));
    }

    public function test_sqlite_application_wall_clock_is_converted_to_utc_buckets(): void
    {
        config(['app.timezone' => 'Europe/Helsinki']);
        CarbonImmutable::setTestNow('2026-10-22 15:37:00 UTC');
        $author = $this->createFullAccount('statstimezone');
        $author->actor->forceFill(['discoverable' => true, 'indexable' => true])->save();
        Post::create([
            'actor_id' => $author->actor->id, 'body' => 'Example', 'status' => 'published',
            'visibility' => 'public', 'published_at' => '2026-10-22 17:30:00',
        ]);
        $data = app(StatsDataset::class)->rhythm();
        $this->assertSame('2026-10-22T14:00:00Z', $data['rows'][359]['at']);
        $this->assertSame(1, $data['rows'][359]['total']);
        $this->assertSame(1, array_sum(array_column($data['rows'], 'total')));
    }

    public function test_languages_combine_posts_and_events_preserve_variants_and_exclude_missing_values(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:30:00 UTC');
        $author = $this->createFullAccount('statslanguages');
        $author->actor->forceFill(['discoverable' => true, 'indexable' => true])->save();
        foreach (['IT', 'it-IT', null, '', 'und'] as $language) {
            Post::create([
                'actor_id' => $author->actor->id, 'body' => 'Example', 'status' => 'published',
                'visibility' => 'public', 'published_at' => '2026-10-22 14:00:00', 'language' => $language,
            ]);
        }
        Event::create([
            'actor_id' => $author->actor->id, 'uri' => 'https://example.test/events/one',
            'name' => 'Example', 'visibility' => 'public', 'status' => 'scheduled',
            'start_at' => '2026-11-01 15:00:00', 'published_at' => '2026-10-22 14:00:00', 'language' => 'it',
        ]);
        $rows = collect(app(StatsDataset::class)->languages()['rows']);
        $this->assertEqualsCanonicalizing(['it', 'it-it'], $rows->pluck('language')->all());
        $this->assertSame(2, $rows->firstWhere('language', 'it')['count']);
        $this->assertSame(3, $rows->sum('count'));
        $this->assertSame(['language', 'count'], array_keys($rows->first()));
    }

    public function test_blocks_match_the_canonical_uri_host_even_when_the_handle_domain_differs(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:30:00 UTC');
        $actor = $this->createRemoteActor('statsremote', 'handle.example', [
            'uri' => 'https://blocked.example:8443/users/statsremote', 'discoverable' => true, 'indexable' => true,
        ]);
        DB::table('domain_blocks')->insert(['id' => (string) Str::uuid(), 'domain' => 'blocked.example']);
        Post::create([
            'actor_id' => $actor->id, 'body' => 'Example', 'status' => 'published',
            'visibility' => 'public', 'published_at' => '2026-10-22 14:00:00',
        ]);
        $this->assertSame(0, array_sum(array_column(app(StatsDataset::class)->rhythm()['rows'], 'total')));
    }

    public function test_private_communities_and_content_warning_policy_apply_to_both_datasets(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:30:00 UTC');
        config([
            'openbook.moderation.hide_content_warnings_from_world' => true,
            'openbook.moderation.forced_content_warning_hashtags' => ['hidden'],
        ]);
        $author = $this->createFullAccount('statsmoderation');
        $author->actor->forceFill(['discoverable' => true, 'indexable' => true])->save();
        $group = $this->createRemoteActor('statsgroup', 'group.example', ['type' => 'group']);
        $community = Community::create([
            'actor_id' => $group->id, 'owner_user_id' => $author->id,
            'slug' => 'statsprivate', 'is_private' => true,
        ]);
        $attributes = [
            'actor_id' => $author->actor->id, 'body' => 'Example', 'status' => 'published',
            'visibility' => 'public', 'published_at' => '2026-10-22 14:00:00', 'language' => 'it',
        ];
        Post::create($attributes);
        Post::create(array_merge($attributes, ['community_id' => $community->id]));
        Post::create(array_merge($attributes, ['content_warning' => 'Sensitive']));
        $tagged = Post::create($attributes);
        $tag = Hashtag::create(['name' => 'hidden']);
        $tagged->hashtags()->attach($tag);
        foreach ([false, true] as $sensitive) {
            Event::create([
                'actor_id' => $author->actor->id, 'uri' => 'https://example.test/events/'.(int) $sensitive,
                'name' => 'Example', 'visibility' => 'public', 'status' => 'scheduled',
                'start_at' => '2026-11-01 15:00:00', 'published_at' => '2026-10-22 14:00:00',
                'language' => 'fi', 'sensitive' => $sensitive,
            ]);
        }
        $this->assertSame(1, array_sum(array_column(app(StatsDataset::class)->rhythm()['rows'], 'total')));
        $rows = collect(app(StatsDataset::class)->languages()['rows']);
        $this->assertSame(1, $rows->where('language', 'it')->sum('count'));
        $this->assertSame(1, $rows->where('language', 'fi')->sum('count'));
    }

    public function test_weekly_counts_deduplicate_authors_across_all_domains_and_origins(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 15:30:00 UTC');
        $local = $this->createFullAccount('statsweekly');
        $local->actor->forceFill(['discoverable' => true, 'indexable' => true])->save();
        $remote = $this->createRemoteActor('weekly', 'weekly.example', ['discoverable' => true, 'indexable' => true]);
        foreach ([$local->actor, $remote] as $actor) {
            foreach (['2026-10-12 10:00:00', '2026-10-12 11:00:00', '2026-10-19 10:00:00'] as $publishedAt) {
                Post::create([
                    'actor_id' => $actor->id, 'body' => 'Weekly example', 'status' => 'published',
                    'visibility' => 'public', 'published_at' => $publishedAt,
                ]);
            }
        }
        Post::create([
            'actor_id' => $local->actor->id, 'body' => 'Private', 'status' => 'published',
            'visibility' => 'followers', 'published_at' => '2026-10-12 12:00:00',
        ]);
        $data = app(StatsDataset::class)->weekly();
        $rows = collect($data['rows']);
        $this->assertSame(4, $rows->where('week', '2026-10-12')->sum('count'));
        $this->assertSame(2, $rows->where('week', '2026-10-12')->sum('authors'));
        $this->assertSame(2, $rows->where('week', '2026-10-19')->sum('authors'));
        $this->assertCount(2, $rows);
        $this->assertSame(6, $rows->sum('count'));
        $this->assertSame(['week', 'count', 'authors'], array_keys($rows->first()));
        $this->assertContains('2026-10-05', $data['weeks']);
        $this->assertSame('2026-10-19', end($data['weeks']));
        $this->actingAs($local)->getJson('/stats/settimane')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_domain_counts_include_eligible_types_and_exclude_opt_outs_and_blocked_hosts(): void
    {
        $this->createRemoteActor('person', 'counts.example', ['discoverable' => true, 'indexable' => true]);
        $this->createRemoteActor('community', 'counts.example', ['type' => 'group', 'discoverable' => true, 'indexable' => true]);
        $this->createRemoteActor('hidden', 'counts.example', ['discoverable' => true, 'indexable' => false]);
        $this->createRemoteActor('blocked', 'blocked.example', ['discoverable' => true, 'indexable' => true]);
        DB::table('domain_blocks')->insert(['id' => (string) Str::uuid(), 'domain' => 'blocked.example', 'created_at' => now(), 'updated_at' => now()]);
        $rows = collect(app(StatsDataset::class)->domains()['rows']);
        $this->assertSame(2, $rows->where('domain', 'counts.example')->sum('count'));
        $this->assertSame(1, $rows->where('domain', 'counts.example')->where('type', 'group')->sum('count'));
        $this->assertSame(0, $rows->where('domain', 'blocked.example')->sum('count'));
    }
}
