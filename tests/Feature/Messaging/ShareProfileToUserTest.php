<?php

namespace Tests\Feature\Messaging;

use App\Application\Services\ConversationResolver;
use App\Application\Services\MessageComposer;
use App\Domain\Messaging\Conversation;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ShareProfileToUserTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_a_local_profile_offers_share_next_to_the_message_button(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $this->actingAs($alice)
            ->get(route('profile.show', $bob->username))
            ->assertOk()
            ->assertSee(route('messages.open', $bob->username), false)
            ->assertSee(route('profiles.share_to_user', $bob), false)
            ->assertSee(__('openbook.messages.share_profile_aria'), false);
    }

    public function test_own_profile_offers_share_without_a_message_button(): void
    {
        $alice = $this->createFullAccount('alice');

        $this->actingAs($alice)
            ->get(route('profile.show', $alice->username))
            ->assertOk()
            ->assertDontSee(route('messages.open', $alice->username), false)
            ->assertSee(route('profiles.share_to_user', $alice), false);
    }

    public function test_a_remote_profile_offers_share_and_uses_the_local_actor_page(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $alice = $this->createFullAccount('alice');
        $remote = $this->createRemoteActor('carol', 'social.example');

        $this->actingAs($alice)
            ->get(route('actors.show', $remote))
            ->assertOk()
            ->assertSee(route('messages.open_actor', $remote), false)
            ->assertSee(route('actors.share_to_user', $remote), false);

        $this->actingAs($alice)
            ->get(route('actors.share_to_user', $remote))
            ->assertRedirect(route('messages.index', ['share' => $remote->id]));

        $this->actingAs($alice)
            ->get(route('messages.index', ['share' => $remote->id]))
            ->assertOk()
            ->assertSee(__('openbook.messages.share_title'), false)
            ->assertSee(route('actors.show', $remote), false)
            ->assertDontSee($remote->uri, false);
    }

    public function test_sharing_a_local_profile_opens_messages_with_the_canonical_page(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $this->actingAs($alice)
            ->get(route('profiles.share_to_user', $bob))
            ->assertRedirect(route('messages.index', ['share' => $bob->actor->id]));

        $this->actingAs($alice)
            ->get(route('messages.index', ['share' => $bob->actor->id]))
            ->assertOk()
            ->assertSee(route('profile.show', $bob->username), false)
            ->assertSee('name="share"', false);
    }

    public function test_groups_and_feeds_cannot_be_shared_as_a_person_profile(): void
    {
        $alice = $this->createFullAccount('alice');
        $group = $this->createRemoteActor('circolo', 'groups.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Circolo',
        ]);

        $this->actingAs($alice)
            ->get(route('actors.share_to_user', $group))
            ->assertNotFound();
    }

    public function test_starting_a_chat_preserves_the_shared_profile(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $carol = $this->createFullAccount('carol');

        $this->actingAs($alice)
            ->post(route('messages.start'), [
                'recipient' => 'bob',
                'share' => $carol->actor->id,
            ])
            ->assertRedirect(route('messages.show', [
                'conversation' => Conversation::query()->first(),
                'share' => $carol->actor->id,
            ]));

        $this->actingAs($alice)
            ->getJson(route('messages.suggest_recipients', [
                'q' => 'bo',
                'share' => $carol->actor->id,
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'handle' => 'bob',
                'open_url' => route('messages.open', [
                    'username' => 'bob',
                    'share' => $carol->actor->id,
                ]),
            ]);
    }

    public function test_a_private_message_can_cite_a_profile_with_the_local_follow_page(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $remote = $this->createRemoteActor('dana', 'social.example');
        $conversation = app(ConversationResolver::class)
            ->findOrCreate($alice->actor, $bob->actor);

        $response = $this->actingAs($alice)->postJson(route('messages.store', $conversation), [
            'body' => '',
            'quoted_actor_id' => $remote->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath(
            'message.quote_html',
            fn ($html) => str_contains((string) $html, route('actors.show', $remote))
                && ! str_contains((string) $html, $remote->uri)
                && str_contains((string) $html, __('openbook.follow.follow')),
        );

        $message = Post::query()->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($message);
        $this->assertSame($remote->id, $message->quoted_actor_id);

        $note = NoteSerializer::forPost($message);
        $this->assertStringContainsString(route('actors.show', $remote), $note['content']);
        $this->assertStringNotContainsString($remote->uri, $note['content']);

        $this->actingAs($bob)
            ->get(route('messages.show', $conversation))
            ->assertOk()
            ->assertSee(route('actors.show', $remote), false)
            ->assertDontSee($remote->uri, false)
            ->assertSee(__('openbook.follow.follow'), false);
    }

    public function test_sharing_a_local_profile_in_a_message_links_to_the_canonical_handle(): void
    {
        Queue::fake();

        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');
        $carol = $this->createFullAccount('carol');
        $conversation = app(ConversationResolver::class)
            ->findOrCreate($alice->actor, $bob->actor);

        app(MessageComposer::class)->send(
            $alice->actor,
            $bob->actor,
            'Ti consiglio questo profilo',
            $conversation,
            quotedActor: $carol->actor,
        );

        $this->actingAs($bob)
            ->get(route('messages.show', $conversation))
            ->assertOk()
            ->assertSee('Ti consiglio questo profilo')
            ->assertSee(route('profile.show', $carol->username), false);
    }
}
