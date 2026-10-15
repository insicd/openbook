<?php

namespace Tests\Feature\Polls;

use App\Application\Services\FollowManager;
use App\Application\Services\RemotePollVoter;
use App\Domain\Polls\RemotePoll;
use App\Domain\Polls\RemotePollVote;
use App\Domain\Posts\Post;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRemotePolls;
use Tests\TestCase;

class RemotePollUiTest extends TestCase
{
    use CreatesRemotePolls, RefreshDatabase;

    public function test_detail_and_feed_render_closed_polls_with_remote_results(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        $poll->update(['closed' => true]);
        Queue::fake();
        app(FollowManager::class)->follow($voter, $poll->post->actor)->update(['status' => 'accepted']);
        foreach ([route('posts.show', $poll->post), route('feed.index')] as $url) {
            $response = $this->actingAs($voter->user)->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
            $response->assertSee('Sondaggio concluso')->assertSee('Sabato')->assertSee('Domenica')
                ->assertSee($poll->voters_count.' partecipanti')->assertDontSee('data-poll-form', false);
        }
    }

    public function test_open_poll_uses_native_single_or_multiple_controls_and_positive_labels(): void
    {
        foreach ([false, true] as $multiple) {
            // Separate fixtures use the same account handle; reuse the poll and switch its mode.
            if (! isset($poll)) {
                [$voter, $poll] = $this->fixtureForPage();
            }
            $poll->update(['multiple' => $multiple, 'ends_at' => null]);
            $response = $this->actingAs($voter->user)->get(route('posts.show', $poll->post))->assertOk();
            $response->assertSee('Sondaggio aperto')->assertSee('data-poll-form', false)
                ->assertSee('type="'.($multiple ? 'checkbox' : 'radio').'"', false)
                ->assertSee($multiple ? 'Scegli una o più risposte' : 'Scegli una risposta')
                ->assertDontSee('Termina il');
        }
    }

    public function test_json_vote_updates_only_personal_choices_and_html_fallback_confirms(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        $count = $poll->voters_count;
        $this->actingAs($voter->user)->postJson(route('posts.poll.vote', $poll->post), $this->selection($poll))
            ->assertOk()->assertJsonPath('message', 'Voto registrato.')
            ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, 'La tua risposta') && ! str_contains($html, 'data-poll-form'))->etc());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'delivery')->count());
        $this->assertSame($count, $poll->fresh()->voters_count);
        $this->actingAs($voter->user)->from(route('posts.show', $poll->post))->post(route('posts.poll.vote', $poll->post), $this->selection($poll))
            ->assertRedirect(route('posts.show', $poll->post).'#poll-'.$poll->post_id)->assertSessionHas('status', 'Voto registrato.');
        $this->assertSame(1, RemotePollVote::count());
        Http::assertNothingSent();
    }

    public function test_own_vote_is_private_across_viewers_guests_and_federation(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $this->actingAs($voter->user)->get(route('posts.show', $poll->post))->assertSee('La tua risposta')->assertSee('Voto registrato.');
        $other = $this->createFullAccount('otherpollreader');
        $this->actingAs($other)->get(route('posts.show', $poll->post))->assertDontSee('La tua risposta')->assertDontSee('Voto registrato.')->assertSee('data-poll-form', false);
        auth()->forgetGuards();
        $this->get(route('posts.show', $poll->post))->assertOk()->assertDontSee('La tua risposta')->assertDontSee('data-poll-form', false)->assertSee('Sign in with an active account');
        $document = json_encode(NoteSerializer::forPost($poll->post));
        $this->assertStringNotContainsString('viewerVote', $document);
        $this->assertStringNotContainsString('pollvoter', $document);
        RemotePoll::annotateViewerState([$poll], $voter);
        $this->assertArrayNotHasKey('viewerVote', $poll->toArray());
        RemotePoll::annotateViewerState([$poll], null);
        $this->assertNull($poll->getRelation('viewerVote'));
    }

    public function test_changed_options_and_expiry_are_rechecked_on_submission(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        $selection = $this->selection($poll);
        $poll->options[0]->update(['name' => 'Venerdì']);
        $this->actingAs($voter->user)->postJson(route('posts.poll.vote', $poll->post), $selection)->assertUnprocessable()->assertJsonValidationErrors('choices');
        $poll = $poll->fresh('options');
        $poll->update(['ends_at' => now()->subSecond()]);
        $this->postJson(route('posts.poll.vote', $poll->post), $this->selection($poll))->assertUnprocessable()->assertJsonValidationErrors('poll');
        $this->assertSame(0, RemotePollVote::count());
    }

    public function test_removed_or_renamed_choices_remain_visible_as_the_original_snapshot(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $poll->options[0]->update(['name' => 'Venerdì']);
        $this->actingAs($voter->user)->get(route('posts.show', $poll->post))->assertSee('Sabato')->assertSee('Venerdì')
            ->assertSee('La tua scelta resta registrata.')->assertDontSee('ob-poll__option--selected', false)->assertDontSee('data-poll-form', false);
    }

    public function test_unknown_or_inconsistent_counts_are_shown_without_invented_percentages(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        $poll->update(['voters_count' => 135, 'closed' => true]);
        $poll->options[0]->update(['votes_count' => 113]);
        $poll->options[1]->update(['votes_count' => 23]);
        $response = $this->actingAs($voter->user)->get(route('posts.show', $poll->post))->assertOk();
        $response->assertSee('113 voti')->assertSee('23 voti')->assertSee('135 partecipanti')->assertDontSee('ob-poll__bar', false);
        $poll->options[0]->update(['votes_count' => null]);
        $poll->update(['voters_count' => null]);
        $this->get(route('posts.show', $poll->post))->assertSee('Risultati in aggiornamento')->assertDontSee('0 partecipanti');
    }

    public function test_private_deleted_and_missing_polls_are_protected(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        $poll->post->update(['visibility' => 'followers']);
        $this->actingAs($voter->user)->get(route('posts.show', $poll->post))->assertNotFound();
        $this->postJson(route('posts.poll.vote', $poll->post), $this->selection($poll))->assertNotFound();
        $poll->post->update(['visibility' => 'public', 'status' => 'deleted']);
        $this->get(route('posts.show', $poll->post))->assertOk()->assertDontSee('data-poll-id', false);
        $this->postJson(route('posts.poll.vote', $poll->post), $this->selection($poll))->assertNotFound();
        $poll->post->update(['status' => 'published']);
        $poll->delete();
        $this->postJson(route('posts.poll.vote', $poll->post), $this->selection($poll))->assertNotFound();
        $this->assertSame(0, RemotePollVote::count());
    }

    public function test_html_validation_errors_belong_to_the_poll_and_guests_must_sign_in(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        $url = route('posts.poll.vote', $poll->post);
        $this->post($url, $this->selection($poll))->assertRedirect(route('login'));
        $this->actingAs($voter->user)->from(route('posts.show', $poll->post))->post($url, ['options_version' => $poll->optionsVersion()])
            ->assertSessionHasErrorsIn('poll-'.$poll->post_id, 'choices');
        $this->get(route('posts.show', $poll->post))->assertSee('Seleziona le risposte disponibili');
    }

    public function test_content_warning_and_remote_text_are_preserved_and_escaped(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        $poll->post->update(['content_warning' => 'Spoiler']);
        $poll->options[0]->update(['name' => '<script>alert(1)</script> 💪']);
        $html = $this->actingAs($voter->user)->get(route('posts.show', $poll->post))->assertOk()->getContent();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; 💪', $html);
        $start = strpos($html, '<details class="ob-post__cw">');
        $pollStart = strpos($html, 'data-poll-id=');
        $this->assertGreaterThan($start, $pollStart);
        $this->assertGreaterThan($pollStart, strpos($html, '</details>', $start));
    }

    public function test_personal_votes_are_loaded_in_batches_including_quoted_polls(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        app(RemotePollVoter::class)->vote($voter, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $post = Post::create(['actor_id' => $voter->id, 'quoted_post_id' => $poll->post_id, 'body' => 'Citazione', 'status' => 'published', 'visibility' => 'public', 'published_at' => now()])->load(Post::CARD_RELATIONS);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'remote_poll_vote')) {
                $queries[] = $query->sql;
            }
        });
        Post::annotateViewerState(array_fill(0, 10, $post), $voter);
        $this->assertCount(2, $queries);
        $this->assertNotNull($post->quotedPost->remotePoll->getRelation('viewerVote'));
        Post::whereKey($poll->post_id)->update(['visibility' => 'followers']);
        RemotePoll::annotateViewerState([$post->quotedPost->remotePoll], $voter);
        $this->assertFalse($post->quotedPost->remotePoll->getRelation('viewerCanSee'));
        $this->assertNull($post->quotedPost->remotePoll->getRelation('viewerVote'));
        $this->actingAs($voter->user);
        $this->assertStringNotContainsString('data-poll-id', view('posts._card', ['post' => $post])->render());
    }

    #[DataProvider('readers')]
    public function test_refresh_rechecks_visibility_before_rendering_the_post(bool $signedIn): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        $poll->post->update(['replies_fetched_at' => null]);
        $document = json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Polls/single-update.json')), true)['object'];
        $document['to'] = [$poll->post->actor->endpoints->followers];
        $document['cc'] = [];
        $document['content'] = '<p>Testo riservato dopo il refresh</p>';
        Http::fake([$poll->post->uri => Http::response($document)]);
        if ($signedIn) {
            $this->actingAs($voter->user);
        }
        $this->get(route('posts.show', $poll->post))->assertNotFound()->assertDontSee('Testo riservato dopo il refresh');
        $this->assertSame('followers', $poll->post->fresh()->visibility);
    }

    public static function readers(): array
    {
        return ['guest' => [false], 'authenticated non-follower' => [true]];
    }

    public function test_refresh_renders_current_text_and_content_warning_with_the_results(): void
    {
        [$voter, $poll] = $this->fixtureForPage();
        $poll->post->update(['replies_fetched_at' => null]);
        $document = json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Polls/single-update.json')), true)['object'];
        $document['content'] = '<p>Domanda aggiornata dal server remoto</p>';
        $document['sensitive'] = true;
        $document['summary'] = 'Spoiler aggiornato';
        Http::fake([$poll->post->uri => Http::response($document)]);
        $this->actingAs($voter->user)->get(route('posts.show', $poll->post))->assertOk()
            ->assertSee('Domanda aggiornata dal server remoto')->assertSee('Spoiler aggiornato');
    }

    private function fixtureForPage(): array
    {
        [$voter, $poll] = $this->votingFixture();
        // Reuse the ordinary refresh TTL; page rendering should not require network access.
        $poll->post->update(['replies_fetched_at' => now()]);
        app()->setLocale('it');

        return [$voter, $poll];
    }

    private function selection(RemotePoll $poll): array
    {
        return ['choices' => [$poll->options[0]->id], 'options_version' => $poll->optionsVersion()];
    }
}
