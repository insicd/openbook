<?php

namespace Tests\Feature\Polls;

use App\Application\Queries\PollVotersQuery;
use App\Application\Services\PostComposer;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TestsPollVoters;
use Tests\Support\PollMysqlTestCase;

class PollVotersMysqlTest extends PollMysqlTestCase
{
    use TestsPollVoters;

    public function test_option_listing_and_counts_use_indexes_on_realistic_data(): void
    {
        [$author, $poll] = $this->votersFixture();
        $this->seedVoters($poll, 211);
        $other = app(PostComposer::class)->compose($author->actor, ['body' => 'Other',
            'poll' => ['options' => ['One', 'Two'], 'multiple' => false, 'duration_seconds' => 300]])->poll;
        $this->seedVoters($other, 1000, 'other');
        DB::select('ANALYZE TABLE poll_vote_choices, poll_votes, actors');
        $query = app(PollVotersQuery::class)->identifiable($poll->options[0]);
        foreach ([$query->clone()->limit(25)->offset(100), $query->clone()->reorder()->select([])->selectRaw('count(*) as aggregate')] as $selection) {
            $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$selection->toSql(), $selection->getBindings());
            $choicePlan = collect($plan)->firstWhere('table', 'poll_vote_choices');
            $this->assertSame('poll_choices_listing_index', $choicePlan->key, json_encode($plan));
            $this->assertNotSame('ALL', $choicePlan->type, json_encode($plan));
            $this->assertStringNotContainsString('filesort', (string) $choicePlan->Extra, json_encode($plan));
            foreach (['ballots', 'voters'] as $table) {
                $this->assertSame('PRIMARY', collect($plan)->firstWhere('table', $table)->key, json_encode($plan));
            }
        }
        $this->assertSame(211, app(PollVotersQuery::class)->total($poll->options[0]));
    }
}
