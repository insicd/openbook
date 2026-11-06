<?php

namespace Tests\Feature\Rest;

use App\Application\Services\AnnounceManager;
use App\Application\Services\CommunityRegistrar;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Messaging\Conversation;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PostAnnounceTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('announceviewer');
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function published(Actor $actor, array $attributes = []): Post
    {
        return Post::create(array_merge(['actor_id' => $actor->id, 'body' => 'Original text',
            'uri' => $actor->isLocal() ? null : $actor->uri.'/statuses/1',
            'visibility' => 'public', 'status' => 'published', 'published_at' => now()->subDay()], $attributes));
    }

    private function url(Post $post): string
    {
        return '/rest/v1/posts/'.$post->id.'/announce';
    }

    #[DataProvider('emailVerificationStates')]
    public function test_manual_announces_are_idempotent_and_update_existing_post_fields(bool $verified): void
    {
        $this->viewer->forceFill(['email_verified_at' => $verified ? now() : null])->save();
        $post = $this->published($this->createFullAccount('announceauthor')->actor);
        $detail = '/rest/v1/posts/'.$post->id;
        $etag = $this->get($detail)->assertOk()->headers->get('ETag');
        $created = $this->withHeader('Origin', 'https://client.example')->withHeader('If-None-Match', $etag)
            ->put($this->url($post))->assertCreated()->assertHeader('Location', url($this->url($post)))
            ->assertHeader('Cache-Control', 'no-store, private')->assertContent('');
        $this->assertStringContainsString('Location', $created->headers->get('Access-Control-Expose-Headers'));
        $this->put($this->url($post))->assertNoContent();
        $this->assertSame(1, $post->announces()->count());
        $this->assertSame(1, Notification::query()->where('notifiable_id', $post->id)->where('type', Notification::TYPE_SHARE)->count());
        $this->withHeader('If-None-Match', $etag)->get($detail)->assertOk()
            ->assertJsonPath('postCounters.postAnnouncesCount', 1)
            ->assertJsonPath('postMyInteractions.postDirectAnnounced', true)
            ->assertJsonPath('postMyInteractions.postAnnounced', true)->assertJsonMissingPath('postAnnounce');
        $this->delete($this->url($post))->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->delete($this->url($post))->assertNoContent();
        $this->assertSame(0, $post->fresh()->announces_count);
        $this->withoutHeader('If-None-Match')->get($detail)->assertOk()->assertJsonPath('postMyInteractions.postDirectAnnounced', false);
        Http::assertNothingSent();
    }

    public function test_own_and_unlisted_posts_can_be_announced(): void
    {
        foreach (['public', 'unlisted'] as $visibility) {
            $post = $this->published($this->viewer->actor, ['visibility' => $visibility]);
            $this->put($this->url($post))->assertCreated();
            $this->delete($this->url($post))->assertNoContent();
        }
    }

    public function test_remote_announces_reuse_federated_announce_and_undo_without_duplicate_deliveries(): void
    {
        $post = $this->published($this->createRemoteActor('announceoriginal'));
        $this->put($this->url($post))->assertCreated();
        $this->put($this->url($post))->assertNoContent();
        $this->delete($this->url($post))->assertNoContent();
        $this->delete($this->url($post))->assertNoContent();
        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job) => $job->activity['type'] === 'Announce'
            && $job->activity['object'] === $post->uri && $job->signingActorId === $this->viewer->actor->id);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job) => $job->activity['type'] === 'Undo'
            && $job->activity['object']['type'] === 'Announce');
        Http::assertNothingSent();
    }

    public function test_promoting_and_removing_a_manual_announce_preserves_the_quote(): void
    {
        $post = $this->published($this->createFullAccount('quotedauthor')->actor);
        $quote = app(PostComposer::class)->compose($this->viewer->actor, ['body' => 'A comment', 'quoted_post_id' => $post->id]);
        $this->assertSame(1, $post->fresh()->announces_count);
        $this->put($this->url($post))->assertCreated();
        $this->put($this->url($post))->assertNoContent();
        $this->delete($this->url($post))->assertNoContent();
        $this->delete($this->url($post))->assertNoContent();
        $this->get('/rest/v1/posts/'.$post->id)->assertOk()
            ->assertJsonPath('postMyInteractions.postDirectAnnounced', false)
            ->assertJsonPath('postMyInteractions.postAnnounced', true)
            ->assertJsonPath('postCounters.postAnnouncesCount', 1);
        $this->assertSame('published', $quote->fresh()->status);
        $this->put($this->url($post))->assertCreated();
    }

    public function test_visible_restricted_posts_cannot_be_announced_but_existing_announces_can_be_removed(): void
    {
        $community = app(CommunityRegistrar::class)->register($this->viewer,
            ['slug' => 'announceprivate', 'name' => 'Private', 'is_private' => true]);
        foreach ([['visibility' => 'followers'], ['visibility' => 'direct'], ['community_id' => $community->id]] as $attributes) {
            $post = $this->published($this->viewer->actor, $attributes);
            $this->put($this->url($post))->assertForbidden();
            $this->assertSame(0, $post->announces()->count());
            app(AnnounceManager::class)->announce($this->viewer->actor, $post);
            $this->delete($this->url($post))->assertNoContent();
            $this->assertSame(0, $post->fresh()->announces_count);
        }
    }

    public function test_a_share_can_be_removed_after_visibility_or_remote_suspension_changes(): void
    {
        $post = $this->published($this->createRemoteActor('nowrestricted'));
        $this->put($this->url($post))->assertCreated();
        $post->update(['visibility' => 'followers']);
        $post->actor->update(['remote_suspended' => true]);
        $this->put($this->url($post))->assertNotFound();
        $this->delete($this->url($post))->assertNoContent();
        $this->delete($this->url($post))->assertNotFound();
        $this->assertSame(0, $post->fresh()->announces_count);
    }

    public function test_remotely_suspended_authors_refuse_new_announces_but_allow_undo(): void
    {
        $post = $this->published($this->createRemoteActor('suspendedoriginal'));
        $this->put($this->url($post))->assertCreated();
        $post->actor->update(['remote_suspended' => true]);
        $this->put($this->url($post))->assertForbidden();
        $this->delete($this->url($post))->assertNoContent();
        $this->assertSame(0, $post->fresh()->announces_count);
    }

    public function test_deleted_unknown_hidden_posts_and_private_messages_return_404(): void
    {
        $other = $this->createFullAccount('hiddenoriginal')->actor;
        $conversation = Conversation::create(['participant_low_id' => min($this->viewer->actor->id, $other->id),
            'participant_high_id' => max($this->viewer->actor->id, $other->id)]);
        foreach ([['status' => 'deleted'], ['visibility' => 'followers'],
            ['actor_id' => $this->viewer->actor->id, 'conversation_id' => $conversation->id, 'visibility' => 'direct']] as $attributes) {
            $post = $this->published($other, $attributes);
            $this->put($this->url($post))->assertNotFound();
            $this->delete($this->url($post))->assertNotFound();
            $this->assertSame(0, $post->announces()->count());
        }
        foreach (['invalid', (string) Str::uuid()] as $id) {
            $this->put('/rest/v1/posts/'.$id.'/announce')->assertNotFound();
            $this->delete('/rest/v1/posts/'.$id.'/announce')->assertNotFound();
        }
        Queue::assertNothingPushed();
    }

    public function test_deleted_posts_and_private_messages_stay_excluded_even_with_an_existing_share(): void
    {
        $other = $this->createFullAccount('removedoriginal')->actor;
        $post = $this->published($other);
        $this->put($this->url($post))->assertCreated();
        $post->update(['status' => Post::STATUS_DELETED]);
        $this->put($this->url($post))->assertNotFound();
        $this->delete($this->url($post))->assertNotFound();
        $this->assertSame(1, $post->announces()->count());
        Queue::fake();
        $conversation = Conversation::create(['participant_low_id' => min($this->viewer->actor->id, $other->id),
            'participant_high_id' => max($this->viewer->actor->id, $other->id)]);
        $post->update(['status' => Post::STATUS_PUBLISHED, 'visibility' => 'direct', 'conversation_id' => $conversation->id]);
        $this->put($this->url($post))->assertNotFound();
        $this->delete($this->url($post))->assertNotFound();
        $this->assertSame(1, $post->announces()->count());
        Queue::assertNothingPushed();
    }

    public function test_operations_act_only_on_the_bearer_users_announce(): void
    {
        $other = $this->createFullAccount('othersharer')->actor;
        $post = $this->published($other);
        app(AnnounceManager::class)->announce($other, $post);
        $this->putJson($this->url($post), ['actorId' => $other->id])->assertCreated();
        $this->delete($this->url($post))->assertNoContent();
        $this->assertTrue(app(AnnounceManager::class)->hasDirectAnnounced($other, $post));
        $this->assertFalse(app(AnnounceManager::class)->hasDirectAnnounced($this->viewer->actor, $post));
        $this->assertSame(1, $post->fresh()->announces_count);
    }

    public function test_authentication_scope_and_active_account_are_required(): void
    {
        $post = $this->published($this->viewer->actor);
        $this->withoutHeader('Authorization')->put($this->url($post))->assertUnauthorized();
        $this->delete($this->url($post))->assertUnauthorized();
        $this->withToken('test-token');
        Passport::actingAs($this->viewer, [], 'rest');
        $this->put($this->url($post))->assertForbidden();
        $this->delete($this->url($post))->assertForbidden();
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->viewer->update(['status' => User::STATUS_SUSPENDED]);
        $this->put($this->url($post))->assertForbidden();
        $this->delete($this->url($post))->assertForbidden();
        $this->assertSame(0, $post->announces()->count());
    }
}
