<?php

namespace Tests\Feature\Messaging;

use App\Application\Services\ConversationReadTracker;
use App\Application\Services\ConversationResolver;
use App\Application\Services\InstanceRelayActor;
use App\Application\Services\MessageComposer;
use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_user_can_send_a_message_to_a_local_contact(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $post = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Ciao Bob!');

        $this->assertSame(Post::VISIBILITY_DIRECT, $post->visibility);
        $this->assertNotNull($post->conversation_id);
        $this->assertDatabaseHas('mentions', [
            'mentionable_type' => 'post',
            'mentionable_id' => $post->id,
            'actor_id' => $bob->actor->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $bob->id,
            'type' => Notification::TYPE_DIRECT_MESSAGE,
            'notifiable_id' => $post->id,
        ]);
    }

    public function test_message_notifications_are_reused_per_conversation_after_reading(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $carol = $this->createFullAccount('carol');

        $first = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Primo');
        $notification = Notification::query()->where('recipient_id', $bob->id)->firstOrFail();
        $initialRevision = $bob->fresh()->notifications_revision;

        $this->travel(1)->minute();
        $second = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Secondo');

        $this->assertSame(1, Notification::query()->where('recipient_id', $bob->id)->count());
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'recipient_id' => $bob->id,
            'notifiable_id' => $second->id,
            'read_at' => null,
        ]);
        $this->assertTrue($notification->created_at->lt($notification->fresh()->created_at));
        $this->assertSame($initialRevision + 1, $bob->fresh()->notifications_revision);
        $this->assertSame(route('messages.show', $first->conversation_id), $notification->fresh()->targetUrl());

        $otherMessage = app(MessageComposer::class)->send($carol->actor, $bob->actor, 'Altra chat');

        $this->assertSame(2, Notification::query()->where('recipient_id', $bob->id)->whereNull('read_at')->count());

        $revisionBeforeReading = $bob->fresh()->notifications_revision;
        $this->actingAs($bob)->get(route('messages.show', $first->conversation_id))->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame($revisionBeforeReading + 1, $bob->fresh()->notifications_revision);
        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $bob->id,
            'notifiable_id' => $otherMessage->id,
            'read_at' => null,
        ]);

        $this->travel(1)->minute();
        $third = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Terzo');

        $this->assertSame(2, Notification::query()->where('recipient_id', $bob->id)->count());
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'recipient_id' => $bob->id,
            'notifiable_id' => $third->id,
            'read_at' => null,
        ]);

        $this->actingAs($bob)->get(route('notifications.index'))->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->travel(1)->minute();
        $fourth = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Quarto');

        $this->assertSame(2, Notification::query()->where('recipient_id', $bob->id)->count());
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'recipient_id' => $bob->id,
            'notifiable_id' => $fourth->id,
            'read_at' => null,
        ]);
    }

    public function test_a_local_user_can_message_a_remote_actor(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $remote = $this->createRemoteActor('carol');

        $post = app(MessageComposer::class)->send($alice->actor, $remote, 'Ciao da Openbook');

        $this->assertSame(Post::VISIBILITY_DIRECT, $post->visibility);
        $this->assertNotNull($post->conversation_id);
        $this->assertDatabaseHas('mentions', [
            'mentionable_type' => 'post',
            'mentionable_id' => $post->id,
            'actor_id' => $remote->id,
        ]);
    }

    public function test_a_local_user_can_message_a_remote_application_with_an_inbox(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $application = $this->createRemoteActor('bot', 'remote.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);

        $this->actingAs($alice)
            ->post(route('messages.start'), ['recipient' => '@bot@remote.example'])
            ->assertRedirect(route('messages.show', Conversation::query()->firstOrFail()));

        $conversation = Conversation::query()->firstOrFail();
        $this->actingAs($alice)
            ->get(route('messages.open_actor', $application))
            ->assertRedirect(route('messages.show', $conversation));

        $this->actingAs($alice)
            ->post(route('messages.store', $conversation), ['body' => 'Ciao bot'])
            ->assertRedirect(route('messages.show', $conversation));

        $this->assertDatabaseHas('posts', [
            'actor_id' => $alice->actor->id,
            'conversation_id' => $conversation->id,
            'visibility' => Post::VISIBILITY_DIRECT,
            'body' => 'Ciao bot',
        ]);
        $this->assertDatabaseHas('mentions', ['actor_id' => $application->id]);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->inboxUrl === $application->endpoints->shared_inbox
            && ($job->activity['to'] ?? null) === [$application->uri]
            && ($job->activity['object']['to'] ?? null) === [$application->uri]
        );
    }

    public function test_the_messages_ui_lists_conversations(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Primo messaggio');

        $response = $this->actingAs($alice)->get(route('messages.index'));

        $response->assertOk();
        $response->assertSee(__('openbook.messages.title'), false);
        $response->assertSee('Primo messaggio');
        $response->assertSee($bob->profile?->display_name ?: $bob->username);
    }

    public function test_the_message_thread_is_accessible_to_participants(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $post = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Segreto condiviso');
        $conversation = Conversation::query()->findOrFail($post->conversation_id);

        $this->actingAs($bob)
            ->get(route('messages.show', $conversation))
            ->assertOk()
            ->assertSee('Segreto condiviso');

        $this->assertDatabaseHas('conversation_reads', [
            'conversation_id' => $conversation->id,
            'user_id' => $bob->id,
        ]);

        $this->actingAs($this->createFullAccount('stranger'))
            ->get(route('messages.show', $conversation))
            ->assertNotFound();
    }

    public function test_direct_message_posts_redirect_to_the_conversation(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $post = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Vai alla chat');

        $this->actingAs($bob)
            ->get(route('posts.show', $post))
            ->assertRedirect(route('messages.show', $post->conversation_id));
    }

    public function test_followers_only_dm_policy_blocks_non_followers(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $bob->settings->update(['direct_message_policy' => 'followers']);

        $this->expectException(ValidationException::class);

        app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Non dovresti riuscire');
    }

    public function test_the_message_feed_returns_only_new_messages_after_a_cursor(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $first = app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Primo');
        $conversation = Conversation::query()->findOrFail($first->conversation_id);

        $response = $this->actingAs($bob)
            ->getJson(route('messages.feed', $conversation).'?after='.$first->id);

        $response->assertOk();
        $response->assertJsonCount(0, 'messages');

        app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Secondo');

        $response = $this->actingAs($bob)
            ->getJson(route('messages.feed', $conversation).'?after='.$first->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'messages');
        $response->assertJsonPath('messages.0.body_html', fn ($html) => str_contains((string) $html, 'Secondo'));
        $this->assertSame(0, Notification::query()
            ->where('recipient_id', $bob->id)
            ->whereNull('read_at')
            ->count());
    }

    public function test_store_returns_json_for_ajax_requests(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $conversation = app(ConversationResolver::class)
            ->findOrCreate($alice->actor, $bob->actor);

        $response = $this->actingAs($alice)
            ->postJson(route('messages.store', $conversation), [
                'body' => 'Ciao via Ajax',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('message.mine', true);
        $response->assertJsonPath('message.body_html', fn ($html) => str_contains((string) $html, 'Ciao via Ajax'));
    }

    public function test_sending_a_message_does_not_mark_the_conversation_unread_for_the_sender(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $remote = $this->createRemoteActor('bob', 'fed.example');
        $conversation = app(ConversationResolver::class)
            ->findOrCreate($alice->actor, $remote);

        $this->actingAs($alice)
            ->get(route('messages.show', $conversation))
            ->assertOk();

        $this->travel(1)->minute();

        $this->actingAs($alice)
            ->postJson(route('messages.store', $conversation), [
                'body' => 'Ciao via Ajax',
            ])
            ->assertCreated();

        $this->assertSame(0, app(ConversationReadTracker::class)->unreadCountFor($alice->actor));
    }

    public function test_sending_a_message_keeps_the_conversation_unread_for_the_recipient(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        app(MessageComposer::class)->send($alice->actor, $bob->actor, 'Ciao Bob!');

        $this->assertSame(0, app(ConversationReadTracker::class)->unreadCountFor($alice->actor));
        $this->assertSame(1, app(ConversationReadTracker::class)->unreadCountFor($bob->actor));
    }

    public function test_recipient_suggestions_return_open_urls(): void
    {
        $viewer = $this->createFullAccount('viewer');
        $this->createFullAccount('alice');
        $remote = $this->createRemoteActor('bob', 'fed.example');
        $application = $this->createRemoteActor('bot', 'app.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);

        $localResponse = $this->actingAs($viewer)
            ->getJson(route('messages.suggest_recipients', ['q' => 'al']));

        $localResponse->assertOk();
        $localResponse->assertJsonFragment([
            'handle' => 'alice',
            'open_url' => route('messages.open', 'alice'),
        ]);

        $remoteResponse = $this->actingAs($viewer)
            ->getJson(route('messages.suggest_recipients', ['q' => 'bo']));

        $remoteResponse->assertOk();
        $remoteResponse->assertJsonFragment([
            'handle' => 'bob@fed.example',
            'open_url' => route('messages.open_actor', $remote),
        ]);
        $remoteResponse->assertJsonFragment([
            'handle' => 'bot@app.example',
            'open_url' => route('messages.open_actor', $application),
        ]);

        $this->actingAs($viewer)
            ->getJson(route('mentions.suggest', ['q' => 'bo']))
            ->assertJsonMissing(['handle' => 'bot@app.example']);
    }

    public function test_start_opens_a_conversation_with_a_local_username(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $response = $this->actingAs($alice)
            ->post(route('messages.start'), ['recipient' => 'bob']);

        $response->assertRedirect(route('messages.show', Conversation::query()->first()));
    }

    public function test_start_opens_a_conversation_with_a_remote_handle(): void
    {
        $alice = $this->createFullAccount('alice');
        $remote = $this->createRemoteActor('carol', 'social.example');

        $response = $this->actingAs($alice)
            ->post(route('messages.start'), ['recipient' => 'carol@social.example']);

        $response->assertRedirect(route('messages.show', Conversation::query()->first()));
    }

    public function test_start_rejects_unknown_recipients(): void
    {
        $alice = $this->createFullAccount('alice');

        $response = $this->actingAs($alice)
            ->from(route('messages.index'))
            ->post(route('messages.start'), ['recipient' => 'inesistente']);

        $response->assertRedirect(route('messages.index'));
        $response->assertSessionHasErrors('recipient');
    }

    public function test_start_rejects_an_unknown_remote_handle_without_a_server_error(): void
    {
        $alice = $this->createFullAccount('alice');

        $this->actingAs($alice)
            ->from(route('messages.index'))
            ->post(route('messages.start'), ['recipient' => 'missing@remote.example'])
            ->assertRedirect(route('messages.index'))
            ->assertSessionHasErrors('recipient');

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_community_and_local_relay_cannot_be_message_recipients(): void
    {
        $alice = $this->createFullAccount('alice');
        $group = $this->createRemoteActor('community', 'remote.example', [
            'type' => Actor::TYPE_GROUP,
        ]);
        $relay = app(InstanceRelayActor::class)->getOrCreate();

        foreach ([$group, $relay] as $recipient) {
            $this->actingAs($alice)
                ->from(route('messages.index'))
                ->post(route('messages.start'), ['recipient' => $recipient->handle()])
                ->assertRedirect(route('messages.index'))
                ->assertSessionHasErrors('recipient');

            $this->actingAs($alice)
                ->get(route('messages.open_actor', $recipient))
                ->assertNotFound();
        }

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_remote_application_without_an_inbox_cannot_receive_messages(): void
    {
        $alice = $this->createFullAccount('alice');
        $application = $this->createRemoteActor('bot', 'remote.example', [
            'type' => Actor::TYPE_APPLICATION,
        ]);
        $application->endpoints->update(['inbox' => null, 'shared_inbox' => null]);

        $this->actingAs($alice)
            ->getJson(route('messages.suggest_recipients', ['q' => 'bot']))
            ->assertJsonMissing(['handle' => 'bot@remote.example']);

        $this->actingAs($alice)
            ->get(route('messages.open_actor', $application))
            ->assertNotFound();

        $this->actingAs($alice)
            ->from(route('messages.index'))
            ->post(route('messages.start'), ['recipient' => 'bot@remote.example'])
            ->assertRedirect(route('messages.index'))
            ->assertSessionHasErrors('recipient');

        $application->endpoints->update(['inbox' => '', 'shared_inbox' => '']);

        $this->actingAs($alice)
            ->getJson(route('messages.suggest_recipients', ['q' => 'bot']))
            ->assertJsonMissing(['handle' => 'bot@remote.example']);
    }
}
