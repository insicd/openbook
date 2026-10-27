<?php

namespace Tests\Feature\Rest;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\PostComposer;
use App\Domain\Accounts\User;
use App\Domain\Locations\PostLocation;
use App\Domain\Messaging\Conversation;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Infrastructure\Media\Media;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PostMutationTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase, UsesPassportKeys;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->configurePassportKeys();
        $this->author = $this->createFullAccount('mutationauthor');
        Passport::actingAs($this->author, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function published(array $attributes = []): Post
    {
        return Post::create(array_merge(['actor_id' => $this->author->actor->id,
            'body' => 'Original text', 'visibility' => 'public', 'status' => 'published',
            'published_at' => now()->subDay()], $attributes));
    }

    private function url(Post $post): string
    {
        return '/rest/v1/posts/'.$post->id;
    }

    public function test_content_only_patch_preserves_omitted_values_and_returns_the_updated_post(): void
    {
        $post = $this->published(['title' => 'Keep title', 'content_warning' => 'Keep warning',
            'language' => 'it', 'visibility' => 'followers']);
        $response = $this->withHeader('If-None-Match', '*')->patchJson($this->url($post), ['postContent' => 'Updated text'])
            ->assertOk()->assertJsonPath('postContent', 'Updated text')->assertJsonPath('postVisibility', 'followers')
            ->assertJsonPath('postName', 'Keep title')->assertJsonPath('postCWText', 'Keep warning')
            ->assertJsonPath('postLanguage', 'it')->assertJsonPath('postId', $post->id)
            ->assertJsonPath('postPublishedAt', $post->published_at->toISOString())
            ->assertJsonPath('postEditedAt', now()->toISOString())
            ->assertJsonMissingPath('data')->assertHeaderMissing('ETag');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $post->refresh();
        $this->assertSame('Keep title', $post->title);
        $this->assertSame('Keep warning', $post->content_warning);
        $this->assertSame('it', $post->language);
        Http::assertNothingSent();
    }

    public function test_visibility_only_and_combined_patches_apply_only_the_supplied_values(): void
    {
        $post = $this->published();
        $this->patchJson($this->url($post), ['postVisibility' => 'unlisted'])->assertOk()
            ->assertJsonPath('postContent', 'Original text')->assertJsonPath('postVisibility', 'unlisted');
        $this->patchJson($this->url($post), ['postContent' => 'Both changed', 'postVisibility' => 'followers'])
            ->assertOk()->assertJsonPath('postContent', 'Both changed')->assertJsonPath('postVisibility', 'followers');
        Passport::actingAs($this->createFullAccount('outsidemutation'), ['api'], 'rest');
        $this->getJson($this->url($post))->assertNotFound();
    }

    public function test_empty_invalid_null_and_unknown_patch_fields_are_rejected_without_changes(): void
    {
        $post = $this->published();
        config(['openbook.posts.max_length' => 20]);
        foreach ([[], ['postContent' => null], ['postContent' => ''], ['postContent' => " \n\t"],
            ['postContent' => 10], ['postContent' => ['text']], ['postContent' => str_repeat('x', 21)],
            ['postVisibility' => null], ['postVisibility' => 'direct'], ['postVisibility' => 'Public'],
            ['postContent' => null, 'postVisibility' => 'public'], ['postName' => 'New title'],
            ['postContent' => 'New text', 'location_id' => null], ['actor_id' => $this->author->actor->id],
        ] as $payload) {
            $this->patchJson($this->url($post), $payload)->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')->assertJsonStructure(['errors']);
        }
        $this->call('PATCH', $this->url($post), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-token'], content: '{}')
            ->assertStatus(422)->assertJsonStructure(['errors' => ['body']]);
        $this->patch($this->url($post), ['postContent' => 'New text'])->assertStatus(415);
        $this->call('PATCH', $this->url($post), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-token'], content: '{bad')
            ->assertStatus(400);
        $this->assertSame('Original text', $post->fresh()->body);
        $this->assertNull($post->fresh()->edited_at);
        Queue::assertNothingPushed();
    }

    public function test_unsupported_post_types_are_rejected_before_the_composer_can_change_them(): void
    {
        $located = $this->published();
        PostLocation::create(['post_id' => $located->id, 'name' => 'Saved place', 'source' => 'local']);
        $location = $located->location->getAttributes();
        $poll = $this->published();
        $poll->poll()->create(['multiple' => false, 'voters_count' => 0, 'ends_at' => now()->addDay()]);
        $attached = $this->published();
        $media = Media::create(['actor_id' => $this->author->actor->id, 'disk' => 'public',
            'path' => 'unused.png', 'mime_type' => 'image/png', 'byte_size' => 1]);
        $attached->media()->attach($media->id, ['id' => (string) Str::uuid(), 'position' => 0]);
        $community = app(CommunityRegistrar::class)->register($this->author, ['slug' => 'mutationcommunity', 'name' => 'Mutation community']);
        $groupPost = $this->published(['community_id' => $community->id]);
        $quote = $this->published(['quoted_post_id' => $located->id]);
        $direct = $this->published(['visibility' => 'direct']);
        foreach ([$located, $poll, $attached, $groupPost, $quote, $direct] as $post) {
            $this->patchJson($this->url($post), ['postContent' => 'Unwanted change'])->assertStatus(409)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->assertSame('Original text', $post->fresh()->body);
            $this->assertNull($post->fresh()->edited_at);
        }
        $this->assertSame($location, $located->location()->first()->getAttributes());
        $this->assertSame(1, $attached->media()->count());
    }

    public function test_other_authors_and_remote_or_private_messages_cannot_be_mutated_even_by_admin(): void
    {
        $this->author->update(['is_admin' => true]);
        $other = $this->createFullAccount('othermutationauthor')->actor;
        $remote = $this->createRemoteActor('remotemutation');
        $conversation = Conversation::create(['participant_low_id' => min($this->author->actor->id, $other->id),
            'participant_high_id' => max($this->author->actor->id, $other->id)]);
        $posts = [$this->published(['actor_id' => $other->id]),
            $this->published(['actor_id' => $remote->id, 'uri' => $remote->uri.'/posts/1']),
            $this->published(['conversation_id' => $conversation->id, 'visibility' => 'direct'])];
        foreach ($posts as $post) {
            $this->patchJson($this->url($post), ['postContent' => 'Forbidden'])->assertNotFound();
            $this->deleteJson($this->url($post))->assertNotFound();
            $this->assertSame('Original text', $post->fresh()->body);
            $this->assertSame('published', $post->fresh()->status);
        }
        $this->patchJson('/rest/v1/posts/not-an-id', ['postContent' => 'Text'])->assertNotFound();
        $this->deleteJson('/rest/v1/posts/'.Str::uuid())->assertNotFound();
    }

    public function test_delete_uses_existing_deletion_and_returns_404_before_authorizing_an_already_deleted_post(): void
    {
        $post = $this->published(['title' => 'Old title', 'content_warning' => 'Old warning']);
        PostLocation::create(['post_id' => $post->id, 'name' => 'Place', 'source' => 'local']);
        $this->withHeader('If-None-Match', '*')->deleteJson($this->url($post))
            ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->url($post))->assertNotFound();
        $post->refresh();
        $this->assertSame('deleted', $post->status);
        $this->assertSame('', $post->body);
        $this->assertNull($post->title);
        $this->assertNull($post->content_warning);
        $this->assertNull($post->location);
        Gate::before(static function (User $user, string $ability): void {
            if ($ability === 'delete') {
                throw new \LogicException('Deleted posts must be rejected before policy checks.');
            }
        });
        $this->deleteJson($this->url($post))->assertNotFound()->assertJsonPath('status', 404);
        $this->patchJson($this->url($post), ['postContent' => 'Restore'])->assertNotFound();
        $this->assertSame('deleted', $post->fresh()->status);
    }

    public function test_mutations_require_an_active_bearer_identity(): void
    {
        $post = $this->published();
        $this->withoutHeader('Authorization')->patchJson($this->url($post), ['postContent' => 'Text'])->assertUnauthorized();
        $this->deleteJson($this->url($post))->assertUnauthorized();
        $this->withToken('test-token');
        $this->author->actor->update(['status' => 'suspended']);
        $this->patchJson($this->url($post), ['postContent' => 'Text'])->assertForbidden();
        $this->deleteJson($this->url($post))->assertForbidden();
        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame('Original text', $post->fresh()->body);
    }

    public function test_update_and_delete_reuse_federation_and_update_hashtags_mentions_and_notifications(): void
    {
        $mentioned = $this->createFullAccount('newmention');
        $follower = $this->createRemoteActor('mutationfollower');
        Follow::create(['follower_id' => $follower->id, 'following_id' => $this->author->actor->id,
            'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $post = app(PostComposer::class)->compose($this->author->actor, ['body' => 'Original #old', 'visibility' => 'public']);
        Queue::fake();
        $this->patchJson($this->url($post), ['postContent' => 'Updated #new @newmention'])->assertOk();
        $this->assertSame(['new'], $post->hashtags()->pluck('name')->all());
        $this->assertSame([$mentioned->actor->id], $post->mentions()->pluck('actor_id')->all());
        $this->assertDatabaseHas('notifications', ['recipient_id' => $mentioned->id,
            'actor_id' => $this->author->actor->id, 'type' => 'mention', 'notifiable_id' => $post->id]);
        $this->deleteJson($this->url($post))->assertNoContent();
        $this->deleteJson($this->url($post))->assertNotFound();
        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job) => $job->activity['type'] === 'Update');
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job) => $job->activity['type'] === 'Delete');
        Http::assertNothingSent();
    }
}
