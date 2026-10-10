<?php

namespace Tests\Feature\Federation;

use App\Application\Services\DirectMessagePolicy;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Federation\Actors\RemoteActorResolver;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class MemorialProfileTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    private function document(Actor $actor, array $fields = []): array
    {
        return array_merge([
            'id' => $actor->uri, 'type' => 'Person', 'preferredUsername' => $actor->preferred_username,
            'name' => 'Autore commemorato', 'inbox' => $actor->uri.'/inbox',
            'publicKey' => ['publicKeyPem' => $actor->key->public_key],
        ], $fields);
    }

    public function test_actor_refresh_imports_memorial_and_clears_it_when_false_or_absent(): void
    {
        $remote = $this->createRemoteActor('memorialrefresh');
        Http::fake([$remote->uri => Http::sequence()
            ->push($this->document($remote, ['memorial' => true]))
            ->push($this->document($remote, ['http://joinmastodon.org/ns#memorial' => [['@value' => false]]]))
            ->push($this->document($remote, ['memorial' => ['@value' => true]]))
            ->push($this->document($remote))
            ->push('', 503),
        ]);
        $resolver = app(RemoteActorResolver::class);
        foreach ([true, false, true, false] as $expected) {
            $resolver->refresh($remote);
            $this->assertSame($expected, $remote->fresh()->memorial);
        }
        $remote->update(['memorial' => true]);
        $resolver->refresh($remote);
        $this->assertTrue($remote->fresh()->memorial);
        $this->assertNull($resolver->applyRemoteDocument($this->document($remote, ['id' => 'https://other.example/person']), $remote->uri));
        $this->assertTrue($remote->fresh()->memorial);
    }

    public function test_incoming_updates_change_memorial_without_changing_suspension_or_moderation(): void
    {
        $remote = $this->createRemoteActor('memorialupdates', overrides: ['status' => Actor::STATUS_BLOCKED, 'remote_suspended' => true]);
        foreach ([true, false] as $memorial) {
            $activity = ['id' => $remote->uri.'/update/'.($memorial ? '1' : '2'),
                'type' => 'Update', 'actor' => $remote->uri,
                'object' => $this->document($remote, ['memorial' => $memorial, 'suspended' => true])];
            $item = InboxItem::query()->create([
                'is_shared' => true, 'remote_activity_uri' => $activity['id'], 'activity_type' => 'Update',
                'actor_uri' => $remote->uri, 'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
                'signature_valid' => true, 'status' => InboxItem::STATUS_PENDING, 'received_at' => now(),
            ]);
            $this->assertSame(InboxItem::STATUS_PROCESSED, app(InboxActivityProcessor::class)->process($item));
            $remote->refresh();
            $this->assertSame($memorial, $remote->memorial);
            $this->assertTrue($remote->isRemotelySuspended());
            $this->assertSame(Actor::STATUS_BLOCKED, $remote->status);
        }
    }

    public function test_memorial_badge_does_not_prevent_follow_likes_replies_or_messages(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 404)]);
        $user = $this->createFullAccount('memorialreader');
        $remote = $this->createRemoteActor('memorialauthor', overrides: ['memorial' => true]);
        $post = Post::query()->create(['actor_id' => $remote->id, 'uri' => $remote->uri.'/posts/1',
            'body' => 'Un ricordo', 'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED, 'published_at' => now()]);
        $this->actingAs($user)->get(route('actors.show', $remote))->assertOk()
            ->assertSee(__('openbook.profile.memorial_badge'))
            ->assertSee('action="'.route('actors.follow', $remote).'"', false)
            ->assertSee('href="'.route('messages.open_actor', $remote).'"', false);
        $this->assertTrue(app(DirectMessagePolicy::class)->canSend($user->actor, $remote));
        $this->post(route('actors.follow', $remote))->assertRedirect();
        $this->post(route('posts.like', $post))->assertRedirect();
        $this->post(route('comments.store', $post), ['body' => 'Un saluto'])->assertRedirect();
        $this->assertDatabaseHas('follows', ['follower_id' => $user->actor->id, 'following_id' => $remote->id, 'status' => 'pending']);
        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseCount('comments', 1);
        $remote->update(['memorial' => false]);
        $this->get(route('actors.show', $remote))->assertOk()->assertDontSee(__('openbook.profile.memorial_badge'));
    }
}
