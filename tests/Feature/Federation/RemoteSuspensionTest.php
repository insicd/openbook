<?php

namespace Tests\Feature\Federation;

use App\Application\Services\AutoAnnounceFanout;
use App\Application\Services\DirectMessagePolicy;
use App\Application\Services\FollowManager;
use App\Application\Services\ReactionManager;
use App\Domain\Comments\Comment;
use App\Domain\Events\Event;
use App\Domain\Events\EventComment;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RemoteSuspensionTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function document(Actor $actor, array $extra = []): array
    {
        return array_merge([
            'id' => $actor->uri, 'type' => 'Person', 'preferredUsername' => $actor->preferred_username,
            'inbox' => $actor->uri.'/inbox', 'outbox' => $actor->uri.'/outbox',
            'publicKey' => ['publicKeyPem' => $actor->key->public_key],
        ], $extra);
    }

    public function test_refresh_tracks_remote_suspension_without_changing_local_moderation(): void
    {
        $remote = $this->createRemoteActor('suspended');
        $resolver = app(RemoteActorResolver::class);
        foreach ([Actor::STATUS_ACTIVE, Actor::STATUS_BLOCKED, Actor::STATUS_SUSPENDED, Actor::STATUS_DELETED] as $status) {
            $remote->update(['status' => $status]);
            $suspended = $resolver->applyRemoteDocument($this->document($remote, ['suspended' => true]), $remote->uri);
            $this->assertTrue($suspended->isRemotelySuspended());
            $this->assertSame($status, $suspended->status);
            $active = $resolver->applyRemoteDocument($this->document($remote), $remote->uri);
            $this->assertFalse($active->isRemotelySuspended());
            $this->assertSame($status, $active->status);
        }
    }

    public function test_suspension_accepts_the_existing_json_ld_boolean_representations(): void
    {
        $remote = $this->createRemoteActor('expanded');
        foreach ([['suspended' => true], ['suspended' => ['@value' => true]],
            ['http://joinmastodon.org/ns#suspended' => [['@value' => true]]]] as $fields) {
            $actor = app(RemoteActorResolver::class)->applyRemoteDocument($this->document($remote, $fields), $remote->uri);
            $this->assertTrue($actor->isRemotelySuspended());
        }
    }

    public function test_remote_suspension_can_be_updated_and_reversed_by_incoming_updates(): void
    {
        $remote = $this->createRemoteActor('updates');
        foreach ([true, false] as $flag) {
            $activity = ['id' => $remote->uri.'/update/'.($flag ? '1' : '2'), 'type' => 'Update',
                'actor' => $remote->uri, 'object' => $this->document($remote, ['suspended' => $flag])];
            $item = InboxItem::query()->create([
                'is_shared' => true, 'remote_activity_uri' => $activity['id'], 'activity_type' => 'Update',
                'actor_uri' => $remote->uri, 'payload' => json_encode($activity),
                'signature_valid' => true, 'status' => InboxItem::STATUS_PENDING, 'received_at' => now(),
            ]);
            $this->assertSame(InboxItem::STATUS_PROCESSED, app(InboxActivityProcessor::class)->process($item));
            $this->assertSame($flag, $remote->fresh()->isRemotelySuspended());
        }
    }

    public function test_failed_or_mismatched_documents_do_not_reactivate_an_account(): void
    {
        $remote = $this->createRemoteActor('failed', overrides: ['remote_suspended' => true]);
        Http::fake(['*' => Http::response('', 503)]);
        $resolver = app(RemoteActorResolver::class);
        $resolver->refresh($remote);
        $this->assertNull($resolver->applyRemoteDocument($this->document($remote, ['id' => 'https://other.example/user']), $remote->uri));
        $this->assertTrue($remote->fresh()->isRemotelySuspended());
        $local = $this->createFullAccount('local');
        $this->assertNull($resolver->applyRemoteDocument($this->document($remote, ['id' => $local->actor->uri, 'suspended' => true]), $local->actor->uri));
        $this->assertFalse($local->actor->fresh()->isRemotelySuspended());
    }

    public function test_profile_and_cached_content_remain_visible_without_new_interaction_controls(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $user = $this->createFullAccount('reader');
        $remote = $this->createRemoteActor('visible', overrides: ['remote_suspended' => true]);
        $post = $this->postFor($remote);
        $comment = Comment::query()->create(['post_id' => $post->id, 'actor_id' => $remote->id, 'body' => 'Commento conservato', 'status' => Comment::STATUS_PUBLISHED]);
        $this->actingAs($user)->get(route('actors.show', $remote))->assertOk()
            ->assertSee(__('openbook.profile.remote_suspended_notice'))
            ->assertDontSee('action="'.route('actors.follow', $remote).'"', false)
            ->assertDontSee('href="'.route('messages.open_actor', $remote).'"', false)
            ->assertSee('Contenuto conservato');
        $this->get(route('posts.show', $post))->assertOk()->assertSee('Contenuto conservato')->assertSee('Commento conservato')
            ->assertDontSee('data-like-form', false)->assertDontSee('data-announce-form', false)
            ->assertDontSee('action="'.route('comments.store', $post).'"', false);
        $this->get(route('comments.show', $comment))->assertOk()->assertSee('Commento conservato');
        $this->assertSame(Post::STATUS_PUBLISHED, $post->fresh()->status);
        $this->assertFalse(app(DirectMessagePolicy::class)->canSend($user->actor, $remote));
        $remote->update(['remote_suspended' => false]);
        $this->get(route('posts.show', $post))->assertOk()->assertSee('data-like-form', false)
            ->assertSee('action="'.route('comments.store', $post).'"', false);
        $this->assertTrue(app(DirectMessagePolicy::class)->canSend($user->actor, $remote->fresh()));
    }

    #[DataProvider('postInteractions')]
    public function test_direct_requests_cannot_create_interactions(string $routeName, string $target): void
    {
        Queue::fake();
        $user = $this->createFullAccount('interactor');
        $remote = $this->createRemoteActor('target', overrides: ['remote_suspended' => true]);
        $post = $this->postFor($remote);
        $comment = Comment::query()->create(['post_id' => $post->id, 'actor_id' => $remote->id, 'body' => 'Commento', 'status' => Comment::STATUS_PUBLISHED]);
        $this->actingAs($user)->post(route($routeName, match ($target) {
            'actor' => $remote, 'comment' => $comment, default => $post
        }), ['body' => 'Risposta'])
            ->assertForbidden();
        Queue::assertNotPushed(DeliverActivityJob::class);
        $this->assertDatabaseCount('likes', 0);
        $this->assertDatabaseCount('announces', 0);
        $this->assertDatabaseCount('follows', 0);
        $this->assertDatabaseCount('comments', 1);
    }

    public static function postInteractions(): array
    {
        return [['actors.follow', 'actor'], ['posts.like', 'post'], ['posts.announce', 'post'], ['comments.store', 'post'], ['comments.like', 'comment']];
    }

    public function test_suspended_content_cannot_be_quoted_or_forwarded(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('quoter');
        $remote = $this->createRemoteActor('quoted', overrides: ['remote_suspended' => true]);
        $post = $this->postFor($remote);
        $this->actingAs($user)->get(route('posts.quote', $post))->assertForbidden();
        $this->get(route('posts.share_to_user', $post))->assertNotFound();
        $this->get(route('actors.share_to_user', $remote))->assertNotFound();
        $this->get(route('messages.open_actor', $remote))->assertNotFound();
        $this->post(route('posts.store'), ['body' => 'Citazione', 'visibility' => 'public', 'quoted_post_id' => $post->id])->assertForbidden();
        $this->assertDatabaseCount('posts', 1);
        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_reply_to_a_suspended_comment_is_blocked_even_under_a_local_post(): void
    {
        $user = $this->createFullAccount('reply');
        $remote = $this->createRemoteActor('commenter', overrides: ['remote_suspended' => true]);
        $post = $this->postFor($user->actor);
        $comment = Comment::query()->create(['post_id' => $post->id, 'actor_id' => $remote->id, 'body' => 'Commento', 'status' => Comment::STATUS_PUBLISHED]);
        $this->actingAs($user)->post(route('comments.store', $post), ['body' => 'Risposta', 'parent_comment_id' => $comment->id])->assertForbidden();
        $this->assertDatabaseCount('comments', 1);
    }

    public function test_reply_to_a_suspended_event_comment_is_blocked_under_a_local_event(): void
    {
        $user = $this->createFullAccount('eventreply');
        $remote = $this->createRemoteActor('eventcommenter', overrides: ['remote_suspended' => true]);
        $event = Event::query()->create(['actor_id' => $user->actor->id,
            'uri' => $user->actor->uri.'/events/1', 'name' => 'Evento locale', 'start_at' => now()->addDay(), 'status' => Event::STATUS_SCHEDULED,
            'visibility' => Event::VISIBILITY_PUBLIC]);
        $comment = EventComment::query()->create(['event_id' => $event->id,
            'actor_id' => $remote->id, 'body' => 'Commento remoto', 'status' => 'published']);
        $this->actingAs($user)->get(route('events.show', $event))->assertOk()->assertSee('Commento remoto')
            ->assertDontSee('action="'.route('event-comments.like', $comment).'"', false);
        $this->post(route('event-comments.like', $comment))->assertForbidden();
        $this->post(route('event-comments.store', $event), ['body' => 'Risposta', 'parent_comment_id' => $comment->id])->assertForbidden();
        $this->assertDatabaseCount('event_comments', 1);
    }

    public function test_event_interactions_are_blocked_and_event_content_is_preserved(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        Queue::fake();
        $user = $this->createFullAccount('eventreader');
        $remote = $this->createRemoteActor('organizer', overrides: ['remote_suspended' => true]);
        $event = Event::query()->create(['actor_id' => $remote->id, 'uri' => $remote->uri.'/events/1',
            'name' => 'Evento conservato', 'start_at' => now()->addDay(), 'status' => Event::STATUS_SCHEDULED,
            'visibility' => Event::VISIBILITY_PUBLIC, 'join_mode' => 'free']);
        $this->actingAs($user)->get(route('events.show', $event))->assertOk()->assertSee('Evento conservato');
        $this->assertFalse($event->isOpenForInteractions());
        $this->post(route('events.interest', $event))->assertNotFound();
        $this->post(route('events.join', $event))->assertNotFound();
        $this->post(route('event-comments.store', $event), ['body' => 'Risposta'])->assertNotFound();
        $this->assertDatabaseCount('event_participations', 0);
        $this->assertDatabaseCount('event_comments', 0);
        Queue::assertNotPushed(DeliverActivityJob::class);
    }

    public function test_suspension_preserves_follows_and_likes_but_allows_their_withdrawal(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('withdraw');
        $remote = $this->createRemoteActor('withdrawtarget');
        $post = $this->postFor($remote);
        app(FollowManager::class)->follow($user->actor, $remote);
        app(ReactionManager::class)->like($user->actor, $post);
        $remote->update(['remote_suspended' => true]);
        $this->assertDatabaseCount('follows', 1);
        $this->assertDatabaseCount('likes', 1);
        $this->actingAs($user)->delete(route('posts.unlike', $post))->assertRedirect();
        $this->delete(route('actors.unfollow', $remote))->assertRedirect();
        $this->assertDatabaseCount('follows', 0);
        $this->assertDatabaseCount('likes', 0);
    }

    public function test_auto_announce_skips_suspended_authors_without_losing_the_setting(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('autoreader');
        $remote = $this->createRemoteActor('autotarget');
        app(FollowManager::class)->follow($user->actor, $remote)->update(['status' => 'accepted', 'auto_announce' => true, 'auto_announce_since' => now()->subHour()]);
        $this->postFor($remote);
        $remote->update(['remote_suspended' => true]);
        $this->assertSame(0, app(AutoAnnounceFanout::class)->process(10, 10, microtime(true) + 5));
        $this->assertDatabaseHas('follows', ['following_id' => $remote->id, 'auto_announce' => true]);
        $remote->update(['remote_suspended' => false]);
        $this->assertSame(1, app(AutoAnnounceFanout::class)->process(10, 10, microtime(true) + 5));
    }

    private function postFor(Actor $actor): Post
    {
        return Post::query()->create(['actor_id' => $actor->id, 'uri' => $actor->uri.'/posts/1',
            'body' => 'Contenuto conservato', 'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED, 'published_at' => now()]);
    }
}
