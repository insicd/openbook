<?php

namespace Tests\Feature\Messaging;

use App\Application\Services\ConversationResolver;
use App\Domain\Events\Event;
use App\Domain\Posts\Post;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ShareEventToUserTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_share_to_user_opens_messages_with_the_event_card(): void
    {
        $user = $this->createFullAccount('eventsharer');
        $event = $this->event();

        $this->actingAs($user)
            ->get(route('events.share_to_user', $event))
            ->assertRedirect(route('messages.index', ['event' => $event->id]));

        $this->actingAs($user)
            ->get(route('messages.index', ['event' => $event->id]))
            ->assertOk()
            ->assertSee($event->name)
            ->assertSee('name="event"', false);
    }

    public function test_a_message_can_contain_an_event_card_and_federates_its_permalink(): void
    {
        Queue::fake();
        $alice = $this->createFullAccount('eventalice');
        $bob = $this->createRemoteActor('eventbob');
        $event = $this->event();
        $conversation = app(ConversationResolver::class)->findOrCreate($alice->actor, $bob);

        $this->actingAs($alice)->postJson(route('messages.store', $conversation), [
            'body' => '',
            'quoted_event_id' => $event->id,
        ])->assertCreated()
            ->assertJsonPath('message.quote_html', fn ($html) => str_contains((string) $html, $event->name));

        $message = Post::query()->where('conversation_id', $conversation->id)->sole();
        $this->assertSame($event->id, $message->quoted_event_id);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Create'
            && str_contains((string) ($job->activity['object']['content'] ?? ''), route('events.show', $event))
        );
    }

    public function test_sharing_does_not_grant_access_to_a_restricted_event(): void
    {
        $alice = $this->createFullAccount('eventprivatealice');
        $bob = $this->createFullAccount('eventprivatebob');
        $event = $this->event([
            'uri' => 'https://events.example/events/private',
            'visibility' => Event::VISIBILITY_DIRECT,
        ]);
        $event->recipients()->attach($alice->actor->id);
        $conversation = app(ConversationResolver::class)->findOrCreate($alice->actor, $bob->actor);

        $this->actingAs($alice)->postJson(route('messages.store', $conversation), [
            'body' => '',
            'quoted_event_id' => $event->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('quoted_event_id');

        $this->assertDatabaseMissing('posts', ['quoted_event_id' => $event->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function event(array $attributes = []): Event
    {
        $actor = $this->createRemoteActor('eventagenda'.fake()->unique()->numberBetween(1, 999999), 'events.example');

        return Event::query()->create([
            'actor_id' => $actor->id,
            'uri' => 'https://events.example/events/'.fake()->uuid(),
            'name' => 'Evento da condividere',
            'visibility' => Event::VISIBILITY_PUBLIC,
            'status' => Event::STATUS_SCHEDULED,
            'start_at' => now()->addDay(),
            ...$attributes,
        ]);
    }
}
