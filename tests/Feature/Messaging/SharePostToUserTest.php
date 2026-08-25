<?php

namespace Tests\Feature\Messaging;

use App\Application\Services\ConversationResolver;
use App\Application\Services\MessageComposer;
use App\Application\Services\PostComposer;
use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class SharePostToUserTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_share_to_user_opens_messages_with_the_quoted_post(): void
    {
        $author = $this->createFullAccount('autore');
        $sharer = $this->createFullAccount('condivisore');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post da mandare in privato.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($sharer)
            ->get(route('posts.share_to_user', $post))
            ->assertRedirect(route('messages.index', ['quote' => $post->id]));

        $this->actingAs($sharer)
            ->get(route('messages.index', ['quote' => $post->id]))
            ->assertOk()
            ->assertSee(__('openbook.messages.share_title'), false)
            ->assertSee('Post da mandare in privato.')
            ->assertSee('name="quote"', false);
    }

    public function test_share_to_user_rejects_invisible_posts_and_direct_messages(): void
    {
        $author = $this->createFullAccount('privato');
        $stranger = $this->createFullAccount('estraneo');
        $bob = $this->createFullAccount('destinatario');

        $followersOnly = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Solo per i follower.',
            'visibility' => Post::VISIBILITY_FOLLOWERS,
        ]);

        $this->actingAs($stranger)
            ->get(route('posts.share_to_user', $followersOnly))
            ->assertNotFound();

        $dm = app(MessageComposer::class)->send($author->actor, $bob->actor, 'Segreto');

        $this->actingAs($author)
            ->get(route('posts.share_to_user', $dm))
            ->assertNotFound();
    }

    public function test_starting_a_chat_preserves_the_quoted_post(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $post = app(PostComposer::class)->compose($alice->actor, [
            'body' => 'Citami in chat.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($alice)
            ->post(route('messages.start'), [
                'recipient' => 'bob',
                'quote' => $post->id,
            ])
            ->assertRedirect(route('messages.show', [
                'conversation' => Conversation::query()->first(),
                'quote' => $post->id,
            ]));

        $this->actingAs($alice)
            ->getJson(route('messages.suggest_recipients', ['q' => 'bo', 'quote' => $post->id]))
            ->assertOk()
            ->assertJsonFragment([
                'handle' => 'bob',
                'open_url' => route('messages.open', ['username' => 'bob', 'quote' => $post->id]),
            ]);
    }

    public function test_a_private_message_can_cite_a_post_without_counting_as_a_public_share(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $original = app(PostComposer::class)->compose($alice->actor, [
            'body' => 'Originale da citare in DM.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $conversation = app(ConversationResolver::class)
            ->findOrCreate($alice->actor, $bob->actor);

        $response = $this->actingAs($alice)->postJson(route('messages.store', $conversation), [
            'body' => 'Guarda questo',
            'quoted_post_id' => $original->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message.body_html', fn ($html) => str_contains((string) $html, 'Guarda questo'));
        $response->assertJsonPath('message.quote_html', fn ($html) => str_contains((string) $html, 'Originale da citare in DM.'));

        $message = Post::query()->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($message);
        $this->assertSame($original->id, $message->quoted_post_id);
        $this->assertSame(0, $original->fresh()->announces_count);
        $this->assertDatabaseMissing('notifications', [
            'type' => Notification::TYPE_QUOTE,
        ]);

        $this->actingAs($bob)
            ->get(route('messages.show', $conversation))
            ->assertOk()
            ->assertSee('Guarda questo')
            ->assertSee('Originale da citare in DM.');
    }

    public function test_a_quoted_direct_message_may_have_an_empty_body(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $original = app(PostComposer::class)->compose($alice->actor, [
            'body' => 'Solo la card, niente commento.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $conversation = app(ConversationResolver::class)
            ->findOrCreate($alice->actor, $bob->actor);

        $this->actingAs($alice)
            ->postJson(route('messages.store', $conversation), [
                'body' => '',
                'quoted_post_id' => $original->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('posts', [
            'conversation_id' => $conversation->id,
            'quoted_post_id' => $original->id,
            'body' => '',
        ]);
    }

    public function test_an_empty_message_without_a_quote_is_rejected(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $conversation = app(ConversationResolver::class)
            ->findOrCreate($alice->actor, $bob->actor);

        $this->actingAs($alice)
            ->postJson(route('messages.store', $conversation), [
                'body' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }
}
