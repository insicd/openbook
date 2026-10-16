<?php

namespace Tests\Concerns;

use App\Application\Queries\PollVotersQuery;
use App\Application\Services\PostComposer;
use App\Domain\Polls\Poll;
use App\Domain\Polls\PollVote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait TestsPollVoters
{
    use CreatesAccounts;

    private function votersFixture(): array
    {
        Http::preventStrayRequests();
        $author = $this->createFullAccount('author');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Scegli il giorno', 'poll' => ['options' => ['Sabato', 'Domenica'], 'multiple' => true, 'duration_seconds' => 86400],
        ]);

        return [$author, $post->poll];
    }

    /** Bulk fixtures avoid hundreds of irrelevant RSA key generations. */
    private function seedVoters(Poll $poll, int $count, string $prefix = 'participant'): array
    {
        $actors = $votes = $choices = [];
        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::uuid();
            $uri = 'https://voters.example/users/'.$prefix.$i;
            $actors[] = ['id' => $id, 'uri' => $uri, 'type' => 'person', 'is_local' => false,
                'preferred_username' => $prefix.$i, 'domain' => 'voters.example', 'name' => 'Participant '.$prefix.$i, 'status' => 'active'];
            $voteId = (string) Str::uuid();
            $votes[] = ['id' => $voteId, 'poll_id' => $poll->id, 'actor_id' => $id,
                'voter_hash' => PollVote::identityHash($uri), 'created_at' => now()];
            foreach ($poll->options as $option) {
                $choices[] = ['id' => (string) Str::uuid(), 'vote_id' => $voteId, 'option_id' => $option->id,
                    'position' => $option->position, 'name' => $option->name, 'name_hash' => $option->name_hash, 'created_at' => now()];
            }
        }
        foreach (['actors' => $actors, 'poll_votes' => $votes, 'poll_vote_choices' => $choices] as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $poll->options()->increment('votes_count', $count);
        $poll->increment('voters_count', $count);

        return array_column($actors, 'id');
    }

    public function test_author_detail_paginates_more_than_two_hundred_voters_without_duplicates(): void
    {
        [$author, $poll] = $this->votersFixture();
        $this->seedVoters($poll, 211);
        $url = route('posts.poll.voters', [$poll->post, $poll->options[0]->id]);
        $ids = [];
        for ($page = 1; $page <= 9; $page++) {
            $response = $this->actingAs($author)->get($url.'?page='.$page.'&per_page=200')->assertOk();
            $paginator = $response->viewData('voters');
            $this->assertSame(25, $paginator->perPage());
            $this->assertSame(211, $paginator->total());
            $ids = array_merge($ids, $paginator->getCollection()->modelKeys());
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertCount(211, array_unique($ids));
        $this->assertSame($ids, app(PollVotersQuery::class)->identifiable($poll->options[0])->pluck('poll_vote_choices.id')->all());
        $this->get(route('posts.poll.voters', [$poll->post, $poll->options[1]->id]))->assertOk()
            ->assertViewHas('voters', fn ($voters) => $voters->total() === 211);
    }

    public function test_deleted_and_orphaned_identities_only_contribute_to_archived_aggregate(): void
    {
        [$author, $poll] = $this->votersFixture();
        $ids = $this->seedVoters($poll, 4);
        DB::table('actors')->where('id', $ids[0])->delete();
        DB::table('actors')->where('id', $ids[1])->update(['status' => 'deleted']);
        DB::table('actors')->where('id', $ids[2])->update(['is_local' => true, 'user_id' => null]);
        $this->actingAs($author)->get(route('posts.poll.voters', $poll->post))->assertOk()
            ->assertViewHas('archivedCount', 3)
            ->assertViewHas('voters', fn ($voters) => $voters->total() === 1)
            ->assertDontSee('Participant participant0')->assertDontSee('Participant participant1')
            ->assertDontSee('Participant participant2')->assertSee('Participant participant3');
        $this->assertSame(4, $poll->fresh()->voters_count);
        $this->assertSame(4, app(PollVotersQuery::class)->total($poll->options[0]));
        DB::table('actors')->where('id', $ids[3])->update(['status' => 'deleted']);
        $this->get(route('posts.poll.voters', $poll->post))->assertOk()->assertViewHas('archivedCount', 4)
            ->assertDontSee('Nessuno ha votato per questa opzione');
    }
}
