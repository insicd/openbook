<?php

namespace Tests\Feature\Polls;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\LocalPollVoter;
use App\Application\Services\PostComposer;
use App\Domain\Polls\Poll;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class LocalPollUiTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(bool $multiple = false): array
    {
        Http::preventStrayRequests();
        $author = $this->createFullAccount('author');
        $voter = $this->createFullAccount('voter');
        $post = app(PostComposer::class)->compose($author->actor, $this->input($multiple));

        return [$author, $voter, $post->poll];
    }

    private function input(bool $multiple = false): array
    {
        return ['body' => 'Quando ci vediamo?', 'visibility' => 'public',
            'poll' => ['options' => ['Sabato', 'Domenica'], 'multiple' => $multiple, 'duration_seconds' => 86400]];
    }

    private function selection(Poll $poll, ?array $choices = null): array
    {
        return ['choices' => $choices ?? [$poll->options[0]->id], 'options_version' => $poll->optionsVersion()];
    }

    public function test_composer_starts_with_two_options_and_default_one_day(): void
    {
        $user = $this->createFullAccount('author');
        $this->actingAs($user);
        view()->share('errors', new ViewErrorBag);
        $html = view('posts._composer')->render();
        $this->assertSame(2, substr_count($html, 'data-poll-option-input'));
        $this->assertStringContainsString('Allega un sondaggio', $html);
        $this->assertStringContainsString('value="86400" selected', $html);
        $this->assertStringContainsString('data-poll-option-add', $html);
        $this->assertStringNotContainsString('name="poll[ends_at]"', $html);
    }

    public function test_empty_optional_poll_fields_publish_an_ordinary_post(): void
    {
        $user = $this->createFullAccount('author');
        $data = $this->input();
        $data['poll_composer'] = '1';
        $data['poll']['options'] = ['', ''];
        $this->actingAs($user)->post(route('posts.store'), $data)->assertRedirect();
        $this->assertSame(1, Post::count());
        $this->assertSame(0, Poll::count());
    }

    public function test_six_option_poll_is_published_through_the_ordinary_form(): void
    {
        $user = $this->createFullAccount('author');
        $data = $this->input(true);
        $data['poll_composer'] = '1';
        $data['poll']['options'] = ['Uno', 'Due', 'Tre', 'Quattro', 'Cinque', str_repeat('💪', 50)];
        $this->actingAs($user)->post(route('posts.store'), $data)->assertRedirect();
        $this->assertSame($data['poll']['options'], Poll::sole()->options->pluck('name')->all());
        $this->assertTrue(Poll::sole()->multiple);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_html_option_controls_preserve_body_and_settings_without_publishing(): void
    {
        $user = $this->createFullAccount('author');
        $data = $this->input(true);
        $data['poll_option_action'] = 'add';
        $this->actingAs($user)->from(route('feed.index'))->post(route('posts.poll.options'), $data)
            ->assertRedirect(route('feed.index').'#composer-poll')->assertSessionHasInput('body', $data['body'])
            ->assertSessionHasInput('poll.options', ['Sabato', 'Domenica', ''])
            ->assertSessionHasInput('poll.multiple', true);
        $this->get(route('feed.index'))->assertOk()->assertSee('Quando ci vediamo?')->assertSee('value="Sabato"', false);
        $this->assertSame(0, Post::count());
        $data['poll']['options'][] = 'Altro';
        $data['poll_option_action'] = 'remove:0';
        $this->post(route('posts.poll.options'), $data)->assertSessionHasInput('poll.options', ['Domenica', 'Altro']);
        $data['poll_option_action'] = 'clear';
        $this->post(route('posts.poll.options'), $data)->assertSessionHasInput('poll.options', ['', '']);
    }

    public function test_invalid_partial_poll_keeps_user_input_for_correction(): void
    {
        $user = $this->createFullAccount('author');
        $data = $this->input();
        $data['poll_composer'] = '1';
        $data['poll']['options'][1] = '';
        $this->actingAs($user)->from(route('feed.index'))->post(route('posts.store'), $data)
            ->assertSessionHasErrors('poll.options.1')->assertSessionHasInput('body', $data['body'])
            ->assertSessionHasInput('poll.options.0', 'Sabato');
        $this->assertSame(0, Post::count());
    }

    public function test_local_poll_offers_voting_in_detail_and_feed_with_author_disclosure(): void
    {
        [$author, $voter, $poll] = $this->fixture();
        Follow::create(['follower_id' => $voter->actor->id, 'following_id' => $author->actor->id,
            'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        foreach ([route('posts.show', $poll->post), route('feed.index')] as $url) {
            $this->actingAs($voter)->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('data-poll-form', false)
                ->assertSee('data-poll-results="0"', false)->assertSee('Mostra risultati')
                ->assertSee('L’autore del sondaggio può consultare le risposte di ciascun partecipante.');
        }
    }

    public function test_native_results_link_can_return_to_choices_and_then_vote(): void
    {
        [, $voter, $poll] = $this->fixture();
        $this->actingAs($voter)->get(route('posts.show', $poll->post).'?poll_results='.$poll->post_id)
            ->assertOk()->assertSee('data-poll-results="1"', false)->assertSee('Torna alle opzioni')
            ->assertSee('hidden disabled', false);
        $this->get(route('posts.show', $poll->post))->assertSee('data-poll-results="0"', false);
        $this->from(route('posts.show', $poll->post))->post(route('posts.poll.vote', $poll->post), $this->selection($poll))
            ->assertRedirect(route('posts.show', $poll->post).'#poll-'.$poll->post_id)->assertSessionHas('status', 'Voto registrato.');
        $this->get(route('posts.show', $poll->post))->assertSee('data-poll-results="1"', false)
            ->assertSee('La tua risposta')->assertDontSee('data-poll-form', false);
    }

    public function test_multiple_json_vote_updates_counts_and_returns_confirmed_personal_choices(): void
    {
        [, $voter, $poll] = $this->fixture(true);
        $this->actingAs($voter)->postJson(route('posts.poll.vote', $poll->post), $this->selection($poll, $poll->options->modelKeys()))
            ->assertOk()->assertJsonPath('message', 'Voto registrato.')
            ->assertJson(fn ($json) => $json->where('html', fn ($html) => substr_count($html, 'La tua risposta') === 2
                && ! str_contains($html, 'data-poll-form') && str_contains($html, '1 partecipante'))->etc());
        $this->assertSame(1, $poll->fresh()->voters_count);
        $this->assertSame([1, 1], $poll->fresh()->options->pluck('votes_count')->all());
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertNothingSent();
    }

    public function test_expiry_while_form_is_open_is_checked_on_submission(): void
    {
        [, $voter, $poll] = $this->fixture();
        $this->actingAs($voter)->get(route('posts.show', $poll->post))->assertSee('data-poll-form', false);
        $this->travelTo($poll->ends_at);
        $this->postJson(route('posts.poll.vote', $poll->post), $this->selection($poll))
            ->assertUnprocessable()->assertJsonValidationErrors('poll');
        $this->get(route('posts.show', $poll->post))->assertSee('data-poll-results="1"', false)
            ->assertDontSee('data-poll-form', false)->assertSee('Sondaggio concluso');
    }

    public function test_choice_from_another_poll_and_stale_version_are_rejected(): void
    {
        [$author, $voter, $poll] = $this->fixture();
        $other = app(PostComposer::class)->compose($author->actor, $this->input())->poll;
        $this->actingAs($voter)->postJson(route('posts.poll.vote', $poll->post), $this->selection($poll, [$other->options[0]->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('choices');
        $this->postJson(route('posts.poll.vote', $poll->post), array_replace($this->selection($poll), ['options_version' => str_repeat('0', 64)]))
            ->assertUnprocessable()->assertJsonValidationErrors('choices');
        $this->assertSame(0, $poll->fresh()->voters_count);
    }

    public function test_own_choice_stays_private_across_users_and_guests(): void
    {
        [$author, $voter, $poll] = $this->fixture();
        app(LocalPollVoter::class)->vote($voter->actor, $poll, [$poll->options[0]->id], $poll->optionsVersion());
        $this->actingAs($voter)->get(route('posts.show', $poll->post))->assertSee('La tua risposta');
        $this->actingAs($author)->get(route('posts.show', $poll->post))->assertDontSee('La tua risposta')->assertSee('data-poll-form', false);
        auth()->forgetGuards();
        $this->get(route('posts.show', $poll->post))->assertOk()->assertDontSee('La tua risposta')
            ->assertDontSee('data-poll-form', false)->assertSee('data-poll-results-toggle', false);
    }

    public function test_private_community_blocks_both_page_and_vote_for_non_members(): void
    {
        [$author, $voter, $poll] = $this->fixture();
        $community = app(CommunityRegistrar::class)->register($author, ['slug' => 'privatepolls', 'name' => 'Private polls', 'is_private' => true]);
        $poll->post->forceFill(['community_id' => $community->id])->save();
        $this->actingAs($voter)->get(route('posts.show', $poll->post))->assertNotFound();
        $this->postJson(route('posts.poll.vote', $poll->post), $this->selection($poll))->assertNotFound();
        $this->assertSame(0, $poll->fresh()->voters_count);
    }

    public function test_editor_shows_fixed_definition_and_visibility_without_poll_inputs(): void
    {
        [$author, , $poll] = $this->fixture();
        $this->actingAs($author)->get(route('posts.show', $poll->post))->assertOk()->assertDontSee('data-edit-post', false);
        $this->get(route('posts.edit', $poll->post))->assertOk()->assertSee('Sabato')
            ->assertSee('Il sondaggio mantiene la visibilità scelta alla pubblicazione.');
        $this->actingAs($author);
        view()->share('errors', new ViewErrorBag);
        $editor = view('posts._composer', ['editingPost' => $poll->post, 'method' => 'PUT'])->render();
        $this->assertStringNotContainsString('name="poll[options][]"', $editor);
        $this->assertStringNotContainsString('name="visibility" data-composer-fill', $editor);
        $this->put(route('posts.update', $poll->post), ['body' => 'Domanda corretta', 'visibility' => 'public'])
            ->assertRedirect();
        $this->assertSame('Domanda corretta', $poll->post->fresh()->body);
    }

    public function test_warning_wraps_poll_and_quoted_poll_stays_read_only(): void
    {
        [$author, $voter, $poll] = $this->fixture();
        $poll->post->update(['content_warning' => 'Spoiler']);
        $response = $this->actingAs($voter)->get(route('posts.show', $poll->post))->assertOk();
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'data-poll-id'), strpos($html, 'Spoiler'));
        Poll::annotateViewerState([$poll], $voter->actor);
        $embedded = view('posts._poll', ['post' => $poll->post, 'embed' => true])->render();
        $this->assertStringNotContainsString('data-poll-form', $embedded);
        $this->assertStringNotContainsString('data-poll-results-toggle', $embedded);
        $this->assertStringContainsString('Sabato', $embedded);
    }
}
