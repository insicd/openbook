<?php

namespace Tests\Feature\Federation;

use App\Application\Services\LocalPollVoter;
use App\Application\Services\PostComposer;
use App\Domain\Comments\Comment;
use App\Domain\Polls\PollVote;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class LocalPollVoteImportTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private Post $post;

    private Actor $voter;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $author = $this->createFullAccount('author')->actor;
        $this->voter = $this->createRemoteActor('voter', 'remote.example');
        $this->post = app(PostComposer::class)->compose($author, [
            'body' => 'Quando ci vediamo?',
            'poll' => ['options' => ['Sabato', 'Domenica'], 'multiple' => true, 'duration_seconds' => 300],
        ]);
    }

    private function activity(): array
    {
        $activity = json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Polls/local-vote-create.json')), true, 512, JSON_THROW_ON_ERROR);
        $activity['to'] = $activity['object']['to'] = [$this->post->actor->uri];
        $activity['object']['inReplyTo'] = NoteSerializer::postUri($this->post);

        return $activity;
    }

    private function item(array $activity, array $overrides = []): InboxItem
    {
        return InboxItem::create(array_replace([
            'is_shared' => true, 'remote_activity_uri' => 'https://remote.example/activities/'.Str::uuid(),
            'activity_type' => $activity['type'], 'actor_uri' => $this->voter->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR), 'signature_valid' => true,
            'status' => 'pending', 'received_at' => now(),
        ], $overrides));
    }

    private function process(InboxItem $item): string
    {
        return app(InboxActivityProcessor::class)->process($item);
    }

    public function test_inline_vote_is_processed_once_without_posts_comments_notifications_or_messages(): void
    {
        $item = $this->item($this->activity());
        $this->assertSame('processed', $this->process($item));
        $this->assertSame('processed', $this->process($item));
        $this->assertSame(1, PollVote::count());
        $this->assertSame(1, $this->post->poll->fresh()->voters_count);
        $this->assertSame(1, Post::count());
        $this->assertSame(0, Comment::count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('conversations')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_original_receipt_controls_inline_vote_despite_late_processing_and_remote_published(): void
    {
        $activity = $this->activity();
        $activity['object']['published'] = '2099-01-01T00:00:00Z';
        $item = $this->item($activity, ['received_at' => $this->post->poll->ends_at->copy()->subSecond()]);
        $this->travelTo($this->post->poll->ends_at->copy()->addHour());
        $this->assertSame('processed', $this->process($item));
        $late = $activity;
        $late['object']['id'] .= '-late';
        $late['object']['name'] = 'Domenica';
        $late['object']['published'] = '2000-01-01T00:00:00Z';
        $this->assertSame('ignored', $this->process($this->item($late)));
        $this->assertSame([1, 0], $this->post->poll->fresh()->options->pluck('votes_count')->all());
    }

    #[DataProvider('invalidVoteDocuments')]
    public function test_forged_or_invalid_vote_is_ignored(array $changes): void
    {
        $activity = $this->activity();
        $activity['object'] = array_replace($activity['object'], $changes);
        $this->assertSame('ignored', $this->process($this->item($activity)));
        $this->assertSame(0, PollVote::count());
        $this->assertSame(0, Comment::count());
    }

    public static function invalidVoteDocuments(): array
    {
        return [
            'different author' => [['attributedTo' => 'https://other.example/users/victim']],
            'ambiguous author list' => [['attributedTo' => ['https://remote.example/users/voter', 'https://other.example/users/victim']]],
            'different origin' => [['id' => 'https://other.example/votes/one']],
            'different port' => [['id' => 'https://remote.example:444/votes/one']],
            'credentials' => [['id' => 'https://attacker@remote.example/votes/one']],
            'invalid URI' => [['id' => 'javascript:vote']],
            'unknown option' => [['name' => 'sabato']],
            'option not trimmed' => [['name' => ' Sabato ']],
            'foreign target' => [['inReplyTo' => 'https://other.example/posts/00000000-0000-0000-0000-000000000000']],
        ];
    }

    public function test_signed_actor_envelope_and_signature_validity_are_checked(): void
    {
        $activity = $this->activity();
        $activity['actor'] = 'https://other.example/users/victim';
        $this->assertSame('ignored', $this->process($this->item($activity)));
        $this->assertSame('ignored', $this->process($this->item($this->activity(), ['signature_valid' => false])));
        $this->assertSame(0, PollVote::count());
    }

    public function test_personal_inbox_does_not_grant_access_to_followers_only_poll(): void
    {
        // Fixture simulates an already published restricted Question.
        $this->post->forceFill(['visibility' => Post::VISIBILITY_FOLLOWERS])->save();
        $item = $this->item($this->activity(), ['target_actor_id' => $this->post->actor_id, 'is_shared' => false]);
        $this->assertSame('ignored', $this->process($item));
        $this->assertSame(0, PollVote::count());
    }

    public function test_personal_inbox_for_another_actor_is_ignored(): void
    {
        $other = $this->createFullAccount('other')->actor;
        $this->assertSame('ignored', $this->process($this->item($this->activity(), ['target_actor_id' => $other->id, 'is_shared' => false])));
    }

    public function test_non_vote_lifecycle_activities_preserve_the_original_choices(): void
    {
        $activity = $this->activity();
        $this->assertSame('processed', $this->process($this->item($activity)));
        foreach (['Update', 'Delete', 'Undo', 'Announce'] as $type) {
            $activity['type'] = $type;
            $activity['object']['name'] = 'Domenica';
            $this->process($this->item($activity));
        }
        $this->assertSame([1, 0], $this->post->poll->fresh()->options->pluck('votes_count')->all());
        $this->assertSame(0, Comment::count());
    }

    public function test_vote_uri_fetched_in_time_is_accepted_but_late_fetch_cannot_use_old_receipt(): void
    {
        $activity = $this->activity();
        $note = $activity['object'];
        Http::fake([$note['id'] => Http::response($note)]);
        $activity['object'] = $note['id'];
        $this->assertSame('processed', $this->process($this->item($activity)));
        $this->assertSame(1, PollVote::count());
        $note['id'] .= '-late';
        $note['name'] = 'Domenica';
        $activity['object'] = $note['id'];
        Http::fake([$note['id'] => Http::response($note)]);
        $item = $this->item($activity);
        $this->travelTo($this->post->poll->ends_at->copy()->addHour());
        $this->assertSame('ignored', $this->process($item));
        $this->assertSame([1, 0], $this->post->poll->fresh()->options->pluck('votes_count')->all());
    }

    public function test_fetched_note_must_match_the_requested_uri(): void
    {
        $activity = $this->activity();
        $note = $activity['object'];
        $activity['object'] = $note['id'].'-substituted';
        Http::fake([$activity['object'] => Http::response($note)]);
        $this->assertSame('ignored', $this->process($this->item($activity)));
        $this->assertSame(0, PollVote::count());
    }

    public function test_foreign_host_cannot_address_a_local_uuid_as_its_own_post(): void
    {
        $activity = $this->activity();
        $activity['object']['inReplyTo'] = 'https://evil.example/posts/'.$this->post->id;
        $this->assertSame('ignored', $this->process($this->item($activity)));
        $this->assertSame(0, PollVote::count());
    }

    public function test_remote_poll_is_never_counted_as_local_authority(): void
    {
        $this->post->forceFill(['uri' => 'https://remote.example/posts/'.$this->post->id])->save();
        $activity = $this->activity();
        $this->assertSame('ignored', $this->process($this->item($activity)));
        $this->assertSame(0, $this->post->poll->fresh()->voters_count);
        $local = $this->createFullAccount('local')->actor;
        $this->expectException(ModelNotFoundException::class);
        app(LocalPollVoter::class)->vote($local, $this->post->poll, [$this->post->poll->options[0]->id], $this->post->poll->optionsVersion());
    }
}
