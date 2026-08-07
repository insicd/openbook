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
}
