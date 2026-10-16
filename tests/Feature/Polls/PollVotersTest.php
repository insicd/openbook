<?php

namespace Tests\Feature\Polls;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\LocalPollVoter;
use App\Application\Services\PostComposer;
use App\Domain\Polls\Poll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TestsPollVoters;
use Tests\TestCase;

class PollVotersTest extends TestCase
{
    use RefreshDatabase, TestsPollVoters;

    public function test_only_exact_author_can_access_detail_even_for_administrators_and_moderators(): void
    {
        [$author, $poll] = $this->votersFixture();
        $url = route('posts.poll.voters', $poll->post);
        $this->get($url)->assertRedirect(route('login'));
        foreach ([[], ['is_admin' => true], ['is_moderator' => true]] as $i => $role) {
            $other = $this->createFullAccount('other'.$i);
            $other->forceFill($role)->save();
            $this->actingAs($other)->get($url)->assertForbidden();
            $this->get(route('posts.show', $poll->post))->assertOk()->assertDontSee('Dettaglio risposte');
        }
        $this->actingAs($author)->get($url)->assertOk()->assertSee('Sabato')->assertSee('Domenica')
            ->assertSee('Questo dettaglio è visibile solo all’autore del sondaggio.')
            ->assertSee('Nessuno ha votato per questa opzione')
            ->assertViewHas('selected', fn ($option) => $option->id === $poll->options[0]->id);
        $this->get(route('posts.show', $poll->post))->assertSee('Dettaglio risposte');
        $author->actor->update(['status' => 'suspended']);
        $this->get($url)->assertForbidden();
    }

    public function test_option_is_scoped_to_poll_and_remote_or_deleted_posts_have_no_detail(): void
    {
        [$author, $poll] = $this->votersFixture();
        $other = app(PostComposer::class)->compose($author->actor, ['body' => 'Other',
            'poll' => ['options' => ['One', 'Two'], 'multiple' => false, 'duration_seconds' => 300]])->poll;
        $this->actingAs($author)->get(route('posts.poll.voters', [$poll->post, $other->options[0]->id]))->assertNotFound();
        $this->get(route('posts.poll.voters', [$poll->post, 'bad-option']))->assertNotFound();
        $poll->post->update(['uri' => 'https://remote.example/question']);
        $this->get(route('posts.poll.voters', $poll->post))->assertNotFound();
        $poll->post->update(['uri' => null, 'status' => 'deleted']);
        $this->get(route('posts.poll.voters', $poll->post))->assertNotFound();
    }

    public function test_private_community_owner_has_no_special_right_to_member_answers(): void
    {
        [$author, $poll] = $this->votersFixture();
        $owner = $this->createFullAccount('groupowner');
        $community = app(CommunityRegistrar::class)->register($owner, ['slug' => 'privateanswers', 'name' => 'Private', 'is_private' => true]);
        $poll->post->update(['community_id' => $community->id]);
        $this->actingAs($owner)->get(route('posts.poll.voters', $poll->post))->assertForbidden();
        $this->actingAs($author)->get(route('posts.poll.voters', $poll->post))->assertOk();
    }

    public function test_local_and_remote_answers_are_visible_only_in_author_page_and_quotes_have_no_link(): void
    {
        [$author, $poll] = $this->votersFixture();
        $local = $this->createFullAccount('secretlocal');
        app(LocalPollVoter::class)->vote($local->actor, $poll, $poll->options->modelKeys(), $poll->optionsVersion());
        $this->seedVoters($poll, 1, 'secretremote');
        foreach ($poll->options as $option) {
            $this->actingAs($author)->get(route('posts.poll.voters', [$poll->post, $option->id]))->assertOk()
                ->assertSee('secretlocal')->assertSee('Participant secretremote0');
        }
        $this->actingAs($author);
        $shared = view('posts._poll', ['post' => $poll->post])->render();
        $this->assertStringNotContainsString('secretlocal', $shared);
        $this->assertStringNotContainsString('secretremote', $shared);
        Poll::annotateViewerState([$poll], $author->actor);
        $this->assertStringNotContainsString('Dettaglio risposte', view('posts._poll', ['post' => $poll->post, 'embed' => true])->render());
        $this->assertStringNotContainsString('secretlocal', $poll->load('votes.choices', 'votes.actor')->toJson());
        $this->assertStringNotContainsString('secretremote', $poll->toJson());
        $local->delete();
        $this->get(route('posts.poll.voters', $poll->post))->assertOk()->assertDontSee('secretlocal')->assertViewHas('archivedCount', 1);
        $this->assertSame(2, DB::table('poll_votes')->where('poll_id', $poll->id)->count());
    }
}
