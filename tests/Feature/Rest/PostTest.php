<?php

namespace Tests\Feature\Rest;

use App\Application\Queries\FeedQuery;
use App\Application\Services\CommunityRegistrar;
use App\Domain\Accounts\User;
use App\Domain\Locations\PostLocation;
use App\Domain\Posts\Post;
use App\Federation\Actors\Actor;
use App\Http\Resources\Rest\V1\PostResource;
use App\Infrastructure\Media\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PostTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('postviewer');
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
    }

    private function cachePost(array $attributes = []): Post
    {
        $actor = isset($attributes['actor_id']) ? Actor::findOrFail($attributes['actor_id']) : Actor::create(['type' => 'person', 'is_local' => false, 'preferred_username' => uniqid('remote'),
            'domain' => 'remote.example', 'uri' => 'https://remote.example/users/'.uniqid(), 'name' => 'Remote', 'status' => 'active']);

        return Post::create(array_merge(['actor_id' => $actor->id, 'uri' => $actor->isLocal() ? null : $actor->uri.'/post', 'body' => 'Source text',
            'visibility' => 'public', 'status' => 'published', 'published_at' => now()], $attributes));
    }

    public function test_default_posts_match_the_existing_world_feed_and_exclude_private_and_local_content(): void
    {
        $visible = $this->cachePost(['title' => '', 'source_uri' => '', 'custom_emojis' => []]);
        $this->cachePost(['actor_id' => $this->viewer->actor->id]);
        $this->cachePost(['visibility' => 'followers']);
        $this->cachePost(['visibility' => 'direct']);
        $this->cachePost(['status' => 'deleted']);
        $community = app(CommunityRegistrar::class)->register($this->viewer, ['slug' => 'hiddenposts', 'name' => 'Hidden', 'is_private' => true]);
        $this->cachePost(['community_id' => $community->id]);
        $expected = app(FeedQuery::class)->world(null, 20)->getCollection()->pluck('id')->all();
        $response = $this->get('/rest/v1/posts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.postId', $visible->id);
        $this->assertSame($expected, $response->json('*.postId'));
        $this->assertSame($response->json(), $this->get('/rest/v1/posts?engine=world')->assertOk()->json());
        foreach (['postName', 'postSourceUri', 'postCustomEmojis', 'postAttachments', 'postLanguage', 'postEditedAt', 'postCWText'] as $field) {
            $response->assertJsonMissingPath('0.'.$field);
        }
        $response->assertJsonPath('0.postAttributes.postLocation', null)->assertJsonPath('0.postMyInteractions.postLiked', false);
        Http::assertNothingSent();
    }

    public function test_optional_fields_warning_location_and_ordered_attachments_are_presented_without_internal_fields(): void
    {
        $post = $this->cachePost(['title' => 'Title', 'source_uri' => 'https://news.example/article', 'custom_emojis' => [':hi:' => 'https://remote.example/hi.png'],
            'content_warning' => 'Warning', 'language' => 'it', 'edited_at' => now()]);
        PostLocation::create(['post_id' => $post->id, 'name' => 'Roma', 'admin1_name' => 'Lazio', 'country_code' => 'IT', 'latitude' => 41.9, 'longitude' => 12.5]);
        foreach ([2, 1] as $position) {
            $media = Media::create(['actor_id' => $post->actor_id, 'disk' => 'public', 'path' => 'unused',
                'remote_url' => 'https://remote.example/'.$position.'.png', 'mime_type' => 'image/png', 'byte_size' => 12, 'alt_text' => 'Alt '.$position]);
            $post->media()->attach($media->id, ['id' => (string) Str::uuid(), 'position' => $position]);
        }
        $response = $this->get('/rest/v1/posts')->assertOk()->assertJsonPath('0.postName', 'Title')
            ->assertJsonPath('0.postCW', true)->assertJsonPath('0.postCWText', 'Warning')
            ->assertJsonPath('0.postSourceUri', 'https://news.example/article')
            ->assertJsonPath('0.postCustomEmojis.:hi:', 'https://remote.example/hi.png')
            ->assertJsonPath('0.postLanguage', 'it')
            ->assertJsonStructure([['postEditedAt']])
            ->assertJsonPath('0.postAttachments.0.mediaUrl', 'https://remote.example/1.png')
            ->assertJsonPath('0.postAttachments.1.mediaAltText', 'Alt 2')
            ->assertJsonPath('0.postAttributes.postLocation.locationName', 'Roma');
        $this->assertIsFloat($response->json('0.postAttributes.postLocation.locationLatitude'));
        foreach (['disk', 'path', 'actor_id', 'created_at', 'updated_at', 'poll', 'conversation_id', 'replies_fetched_at'] as $field) {
            $response->assertJsonMissingPath('0.'.$field);
        }
        $response->assertJsonMissingPath('0.postAttachments.0.disk');
    }

    public function test_pagination_by_last_post_id_is_deterministic_and_links_use_rest_parameters(): void
    {
        foreach (range(1, 4) as $i) {
            $this->cachePost(['published_at' => now()->startOfDay()]);
        }
        $expected = app(FeedQuery::class)->world(null, 10)->getCollection()->pluck('id')->all();
        $first = $this->get('/rest/v1/posts?limit=2')->assertOk()->assertJsonCount(2)->assertHeader('Link');
        $link = $first->headers->get('Link');
        $this->assertStringNotContainsString('cursor=', $link);
        $this->assertStringContainsString('after='.$first->json('1.postId'), $link);
        preg_match('/<([^>]+)>; rel="next"/', $link, $match);
        $second = $this->get($match[1])->assertOk()->assertJsonCount(2)->assertHeaderMissing('Link');
        $this->assertSame($expected, array_merge($first->json('*.postId'), $second->json('*.postId')));
    }

    public function test_world_moderation_and_current_user_interactions_reuse_existing_core_rules(): void
    {
        $this->cachePost(['content_warning' => 'Hidden']);
        $visible = $this->cachePost();
        $visible->likes()->create(['actor_id' => $this->viewer->actor->id]);
        config(['openbook.moderation.hide_content_warnings_from_world' => true]);
        $this->get('/rest/v1/posts')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.postId', $visible->id)->assertJsonPath('0.postMyInteractions.postLiked', true);
        $visible->update(['status' => Post::STATUS_DELETED]);
        $this->get('/rest/v1/posts')->assertOk()->assertExactJson([]);
    }

    public function test_validation_cache_and_authentication_are_applied(): void
    {
        $this->cachePost();
        $first = $this->get('/rest/v1/posts')->assertOk()->assertHeader('Vary', 'Authorization');
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->get('/rest/v1/posts')->assertStatus(304);
        $this->withoutHeader('If-None-Match');
        foreach (['engine=unknown', 'afterAt=2026-01-01T00:00:00Z', 'sort=postTimelineAt', 'limit=0', 'limit=101', 'after=bad', 'after=00000000-0000-4000-8000-000000000000', 'direction=asc', 'cursor=old'] as $query) {
            $this->get('/rest/v1/posts?'.$query)->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
        }
        $secret = $this->cachePost(['visibility' => 'followers']);
        $this->get('/rest/v1/posts?after='.$secret->id)->assertStatus(422);
        $this->withoutHeader('Authorization')->get('/rest/v1/posts')->assertUnauthorized();
    }

    public function test_announce_context_is_an_optional_post_field_independent_of_the_engine(): void
    {
        $post = $this->cachePost()->load(Post::CARD_RELATIONS);
        $post->setRelation('sharedBy', $this->viewer->actor);
        $post->shared_at = now()->startOfSecond();
        foreach (['world', 'timeline'] as $engine) {
            $resource = new PostResource($post);
            if ($engine === 'timeline') {
                $resource->withTimelineContext();
            }
            $request = Request::create('/rest/v1/posts?engine='.$engine);
            $data = $resource->resolve($request);
            $this->assertSame($this->viewer->actor->id, $data['postAnnounce']['announceAuthor']['personId']);
            $this->assertSame($post->shared_at->toISOString(), $data['postAnnounce']['announcePublishedAt']);
            $this->assertSame($post->actor_id, $data['postAuthor']['personId']);
            $post->shared_at = null;
            $this->assertArrayNotHasKey('postAnnounce', $resource->resolve($request));
            $post->shared_at = now()->startOfSecond();
        }
    }
}
