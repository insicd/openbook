<?php

namespace Tests\Feature\Messaging;

use App\Application\Services\MessageComposer;
use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

        $this->expectException(\Illuminate\Validation\ValidationException::class);

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
    }

    public function test_store_returns_json_for_ajax_requests(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $conversation = app(\App\Application\Services\ConversationResolver::class)
            ->findOrCreate($alice->actor, $bob->actor);

        $response = $this->actingAs($alice)
            ->postJson(route('messages.store', $conversation), [
                'body' => 'Ciao via Ajax',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('message.mine', true);
        $response->assertJsonPath('message.body_html', fn ($html) => str_contains((string) $html, 'Ciao via Ajax'));
    }

    public function test_recipient_suggestions_return_open_urls(): void
    {
        $viewer = $this->createFullAccount('viewer');
        $this->createFullAccount('alice');
        $remote = $this->createRemoteActor('bob', 'fed.example');

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
}
