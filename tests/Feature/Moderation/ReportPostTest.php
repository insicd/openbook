<?php

namespace Tests\Feature\Moderation;

use App\Application\Services\PostComposer;
use App\Domain\Moderation\Report;
use App\Domain\Posts\Post;
use App\Policies\PostPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ReportPostTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_viewer_can_report_another_users_local_post(): void
    {
        $author = $this->createFullAccount('autoresegnalato');
        $reporter = $this->createFullAccount('segnalatore');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Contenuto discutibile.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($reporter)
            ->get(route('posts.report.create', $post))
            ->assertOk()
            ->assertSee(__('openbook.reports.page_title'), false)
            ->assertSee('Contenuto discutibile.', false);

        $response = $this->actingAs($reporter)->post(route('posts.report.store', $post), [
            'reason' => Report::REASON_SPAM,
            'details' => 'Pubblicita ripetuta.',
        ]);

        $response->assertRedirect(route('posts.show', $post));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $reporter->id,
            'post_id' => $post->id,
            'reason' => Report::REASON_SPAM,
            'details' => 'Pubblicita ripetuta.',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_a_remote_post_can_also_be_reported(): void
    {
        $reporter = $this->createFullAccount('segnalaremoti');
        $remote = $this->createRemoteActor('spamremote');
        $post = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => 'https://remoto.example/users/spamremote/statuses/3',
            'body' => 'Spam remoto.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->actingAs($reporter)->post(route('posts.report.store', $post), [
            'reason' => Report::REASON_OTHER,
        ])->assertRedirect(route('posts.show', $post));

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $reporter->id,
            'post_id' => $post->id,
            'reason' => Report::REASON_OTHER,
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_reporting_the_same_post_twice_is_idempotent(): void
    {
        $author = $this->createFullAccount('giaautor');
        $reporter = $this->createFullAccount('doppiosegnal');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Da segnalare una volta sola.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($reporter)->post(route('posts.report.store', $post), [
            'reason' => Report::REASON_HARASSMENT,
        ])->assertRedirect();

        $this->actingAs($reporter)->post(route('posts.report.store', $post), [
            'reason' => Report::REASON_HATE,
            'details' => 'Secondo tentativo.',
        ])->assertRedirect();

        $this->assertSame(1, Report::query()->where('post_id', $post->id)->count());
        $this->assertSame(Report::REASON_HARASSMENT, Report::query()->where('post_id', $post->id)->value('reason'));
    }

    public function test_a_user_cannot_report_their_own_post(): void
    {
        $author = $this->createFullAccount('autosegnal');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Il mio post.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->assertFalse((new PostPolicy)->report($author, $post));

        $this->actingAs($author)->get(route('posts.report.create', $post))->assertForbidden();
        $this->actingAs($author)->post(route('posts.report.store', $post), [
            'reason' => Report::REASON_SPAM,
        ])->assertForbidden();
    }

    public function test_the_overflow_menu_shows_report_for_other_posts(): void
    {
        $author = $this->createFullAccount('menureport');
        $viewer = $this->createFullAccount('vedeireport');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post con voce segnala.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($viewer)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('class="ob-post__menu"', false);
        $response->assertSee(__('openbook.actions.report'), false);
        $response->assertSee(route('posts.report.create', $post), false);
        $response->assertDontSee(__('openbook.actions.delete'), false);
    }

    public function test_a_remote_post_menu_shows_report_but_not_delete(): void
    {
        $viewer = $this->createFullAccount('viewerflag');
        $remote = $this->createRemoteActor('flaggable');
        $post = Post::query()->create([
            'actor_id' => $remote->id,
            'uri' => 'https://remoto.example/users/flaggable/statuses/1',
            'body' => 'Remoto segnalabile.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('class="ob-post__menu"', false);
        $response->assertSee(__('openbook.actions.report'), false);
        $response->assertDontSee(__('openbook.actions.delete'), false);
    }
}
