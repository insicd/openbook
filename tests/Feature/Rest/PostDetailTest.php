<?php

namespace Tests\Feature\Rest;

use App\Domain\Accounts\User;
use App\Domain\Messaging\Conversation;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PostDetailTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('detailviewer');
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
        return '/rest/v1/posts/'.$post->id;
    }

    public function test_local_detail_has_the_post_shape_and_conditional_private_cache(): void
    {
        $post = $this->published($this->viewer->actor);
        $first = $this->get($this->url($post))->assertOk()->assertJsonPath('postId', $post->id)
            ->assertJsonPath('postAuthor.personId', $post->actor_id)->assertJsonPath('postContent', 'Original text')
            ->assertJsonMissingPath('data')->assertJsonMissingPath('postAnnounce')->assertJsonMissingPath('postTimelineAt')
            ->assertHeader('Vary', 'Authorization');
        $this->assertTrue($first->headers->hasCacheControlDirective('private'));
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->get($this->url($post))->assertStatus(304);
        $post->update(['body' => 'New text']);
        $this->get($this->url($post))->assertOk()->assertJsonPath('postContent', 'New text');
        Http::assertNothingSent();
    }

    public function test_remote_detail_refreshes_counters_once_with_the_existing_ttl(): void
    {
        $post = $this->published($this->createRemoteActor('postauthor'));
        Http::fake([$post->uri => Http::response(['id' => $post->uri, 'type' => 'Note',
            'attributedTo' => $post->actor->uri, 'content' => '<p>Original text</p>',
            'likes' => ['type' => 'Collection', 'totalItems' => 12],
            'shares' => ['type' => 'Collection', 'totalItems' => 4]], 200)]);
        $first = $this->get($this->url($post))->assertOk()
            ->assertJsonPath('postCounters.postLikesCount', 12)->assertJsonPath('postCounters.postAnnouncesCount', 4);
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->get($this->url($post))->assertStatus(304);
        Http::assertSentCount(1);
        $this->assertNotNull($post->fresh()->replies_fetched_at);
    }

    public function test_remote_failure_falls_back_to_cached_post(): void
    {
        $post = $this->published($this->createRemoteActor('unavailable'));
        Http::fake([$post->uri => Http::response('', 503)]);
        $this->get($this->url($post))->assertOk()->assertJsonPath('postContent', 'Original text');
    }

    public function test_visibility_is_checked_again_after_refresh_even_with_an_etag(): void
    {
        $author = $this->createRemoteActor('questionauthor');
        $post = $this->published($author);
        Http::fake([$post->uri => Http::response(['id' => $post->uri, 'type' => 'Question',
            'attributedTo' => $author->uri, 'content' => '<p>Private question</p>',
            'to' => [$author->uri.'/followers'], 'cc' => [],
            'published' => $post->published_at->toISOString(),
            'oneOf' => [['type' => 'Note', 'name' => 'Yes'], ['type' => 'Note', 'name' => 'No']]], 200)]);
        $this->withHeader('If-None-Match', '"old"')->get($this->url($post))->assertNotFound();
        $this->assertSame('followers', $post->fresh()->visibility);
    }

    public function test_likes_are_idempotent_and_update_the_detail_without_using_conditional_write_headers(): void
    {
        $author = $this->createFullAccount('likedauthor')->actor;
        $post = $this->published($author);
        $etag = $this->get($this->url($post))->assertOk()->headers->get('ETag');
        $url = $this->url($post).'/like';
        $created = $this->withHeader('Origin', 'https://client.example')->withHeader('If-None-Match', $etag)
            ->put($url)->assertCreated()->assertHeader('Location', url($url))->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('Location', $created->headers->get('Access-Control-Expose-Headers'));
        $this->put($url)->assertNoContent();
        $this->assertSame(1, $post->likes()->count());
        $this->withHeader('If-None-Match', $etag)->get($this->url($post))->assertOk()
            ->assertJsonPath('postCounters.postLikesCount', 1)->assertJsonPath('postMyInteractions.postLiked', true);
        $this->delete($url)->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->delete($url)->assertNoContent();
        $this->assertSame(0, $post->likes()->count());
        $this->assertSame(0, $post->fresh()->likes_count);
    }

    public function test_unavailable_posts_and_private_messages_cannot_be_read_or_liked(): void
    {
        $other = $this->createFullAccount('privateauthor')->actor;
        $conversation = Conversation::create(['participant_low_id' => min($this->viewer->actor->id, $other->id),
            'participant_high_id' => max($this->viewer->actor->id, $other->id)]);
        foreach ([['visibility' => 'followers'], ['status' => 'deleted'],
            ['actor_id' => $this->viewer->actor->id, 'conversation_id' => $conversation->id, 'visibility' => 'direct']] as $attributes) {
            $post = $this->published($other, $attributes);
            $this->get($this->url($post))->assertNotFound();
            $this->put($this->url($post).'/like')->assertNotFound();
            $this->delete($this->url($post).'/like')->assertNotFound();
            $this->assertSame(0, $post->likes()->count());
        }
        $this->get('/rest/v1/posts/not-an-id')->assertNotFound();
        $this->withoutHeader('Authorization')->get('/rest/v1/posts/not-an-id')->assertUnauthorized();
        $this->put('/rest/v1/posts/not-an-id/like')->assertUnauthorized();
        $this->delete('/rest/v1/posts/not-an-id/like')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_remotely_suspended_authors_cannot_receive_likes(): void
    {
        $post = $this->published($this->createRemoteActor('suspendedauthor', overrides: ['remote_suspended' => true]));
        $this->put($this->url($post).'/like')->assertForbidden()->assertJsonPath('status', 403);
        $this->assertSame(0, $post->likes()->count());
    }

    public function test_remote_likes_reuse_federated_like_and_undo_without_duplicate_deliveries(): void
    {
        $post = $this->published($this->createRemoteActor('federatedauthor'));
        $url = $this->url($post).'/like';
        $this->put($url)->assertCreated();
        $this->put($url)->assertNoContent();
        $this->delete($url)->assertNoContent();
        $this->delete($url)->assertNoContent();
        Queue::assertPushed(DeliverActivityJob::class, 2);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job) => $job->activity['type'] === 'Like'
            && $job->activity['object'] === $post->uri && $job->signingActorId === $this->viewer->actor->id);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job) => $job->activity['type'] === 'Undo'
            && $job->activity['object']['type'] === 'Like');
        Http::assertNothingSent();
    }
}
