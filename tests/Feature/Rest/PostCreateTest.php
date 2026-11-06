<?php

namespace Tests\Feature\Rest;

use App\Domain\Accounts\User;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PostCreateTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase, UsesPassportKeys;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->author = $this->createFullAccount('apiauthor');
        Passport::actingAs($this->author, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['postContent' => 'Hello **OpenBook**!', 'postVisibility' => 'public'], $overrides);
    }

    public function test_creation_returns_the_full_post_with_server_identity_location_and_no_store(): void
    {
        $response = $this->withHeader('Origin', 'https://client.example')->withHeader('If-None-Match', '*')
            ->postJson('/rest/v1/posts', $this->payload())->assertCreated()
            ->assertJsonPath('postContent', 'Hello **OpenBook**!')->assertJsonPath('postVisibility', 'public')
            ->assertJsonPath('postAuthor.personId', $this->author->actor->id)
            ->assertJsonPath('postCounters', ['postLikesCount' => 0, 'postCommentsCount' => 0, 'postAnnouncesCount' => 0])
            ->assertJsonPath('postMyInteractions', ['postLiked' => false, 'postAnnounced' => false, 'postDirectAnnounced' => false])
            ->assertJsonPath('postAttributes.postLocation', null)->assertJsonPath('postCW', false)
            ->assertJsonMissingPath('data')->assertJsonMissingPath('postAttachments')
            ->assertJsonMissingPath('postAnnounce')->assertJsonMissingPath('postTimelineAt')
            ->assertHeaderMissing('ETag')->assertHeader('Content-Type', 'application/json');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertStringContainsString('Location', $response->headers->get('Access-Control-Expose-Headers'));
        $post = Post::findOrFail($response->json('postId'));
        $this->assertSame($this->author->actor->id, $post->actor_id);
        $this->assertSame('published', $post->status);
        $this->assertNull($post->conversation_id);
        $this->assertNull($post->community_id);
        $this->assertSame($post->published_at->toISOString(), $response->json('postPublishedAt'));
        $location = route('rest.v1.posts.show', ['postId' => $post->id]);
        $response->assertHeader('Location', $location);
        $this->withoutHeader('If-None-Match')->getJson($location)->assertOk()->assertExactJson($response->json());
        Http::assertNothingSent();
    }

    public function test_all_supported_audiences_use_existing_visibility_rules(): void
    {
        $ids = [];
        foreach (['public', 'unlisted', 'followers'] as $visibility) {
            $response = $this->postJson('/rest/v1/posts', $this->payload(['postVisibility' => $visibility]))
                ->assertCreated()->assertJsonPath('postVisibility', $visibility);
            $ids[$visibility] = $response->json('postId');
        }
        $outsider = $this->createFullAccount('outsideviewer');
        Passport::actingAs($outsider, ['api'], 'rest');
        $this->getJson('/rest/v1/posts/'.$ids['public'])->assertOk();
        $this->getJson('/rest/v1/posts/'.$ids['unlisted'])->assertOk();
        $this->getJson('/rest/v1/posts/'.$ids['followers'])->assertNotFound();
        Follow::create(['follower_id' => $outsider->actor->id, 'following_id' => $this->author->actor->id,
            'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $this->getJson('/rest/v1/posts/'.$ids['followers'])->assertOk();
    }

    public function test_required_fields_types_audience_and_the_configured_text_limit_are_validated(): void
    {
        config(['openbook.posts.max_length' => 20]);
        foreach ([[], ['postContent' => 'Hello'], ['postVisibility' => 'public'],
            $this->payload(['postContent' => null]), $this->payload(['postContent' => '']),
            $this->payload(['postContent' => " \n\t"]), $this->payload(['postContent' => "\u{00a0}"]),
            $this->payload(['postContent' => ['text']]), $this->payload(['postContent' => 42]),
            $this->payload(['postContent' => str_repeat('x', 21)]),
            $this->payload(['postVisibility' => null]), $this->payload(['postVisibility' => 'direct']),
            $this->payload(['postVisibility' => 'Public']), $this->payload(['postVisibility' => ['public']]),
        ] as $payload) {
            $this->postJson('/rest/v1/posts', $payload)->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('status', 422)
                ->assertJsonStructure(['errors']);
        }
        $this->assertDatabaseCount('posts', 0);
        $this->postJson('/rest/v1/posts', $this->payload(['postContent' => str_repeat('x', 20)]))->assertCreated();
    }

    public function test_unknown_read_only_and_unsupported_creation_fields_are_rejected(): void
    {
        foreach (['postId', 'postAuthor', 'postPublishedAt', 'postCounters', 'postName', 'postLanguage',
            'postCW', 'postAttachments', 'postAttributes', 'postMyInteractions', 'actor_id', 'body',
            'visibility', 'images', 'prepared_media', 'poll', 'community_id', 'addressed_group_actor_id',
            'quoted_post_id', 'location_id', '0'] as $field) {
            $this->postJson('/rest/v1/posts', $this->payload([$field => null]))->assertStatus(422)
                ->assertJsonStructure(['errors' => [$field]]);
        }
        $this->assertDatabaseCount('posts', 0);
        Queue::assertNothingPushed();
    }

    public function test_json_format_and_authentication_are_checked_before_creation(): void
    {
        $this->post('/rest/v1/posts', $this->payload())->assertStatus(415);
        $this->call('POST', '/rest/v1/posts', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-token'], content: '{invalid')
            ->assertStatus(400)->assertJsonPath('status', 400);
        foreach (['[]', 'null', '"text"', '123'] as $json) {
            $this->call('POST', '/rest/v1/posts', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-token'], content: $json)->assertStatus(422);
        }
        $this->author->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/rest/v1/posts', $this->payload())->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'emailVerificationRequired');
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
        $this->author->forceFill(['email_verified_at' => now()])->save();

        $this->withoutHeader('Authorization')->postJson('/rest/v1/posts', $this->payload())->assertUnauthorized();
        $this->withToken('test-token');
        $this->author->actor->update(['status' => 'suspended']);
        $this->postJson('/rest/v1/posts', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('posts', 0);
        Http::assertNothingSent();
    }

    public function test_the_composer_handles_hashtags_mentions_notifications_and_federated_create(): void
    {
        $mentioned = $this->createFullAccount('mentionedperson');
        $follower = $this->createRemoteActor('remotefollower');
        Follow::create(['follower_id' => $follower->id, 'following_id' => $this->author->actor->id,
            'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $response = $this->postJson('/rest/v1/posts', $this->payload([
            'postContent' => 'Hello @mentionedperson! #OpenBook #openbook',
        ]))->assertCreated();
        $post = Post::findOrFail($response->json('postId'));
        $this->assertSame(['openbook'], $post->hashtags()->pluck('name')->all());
        $this->assertSame([$mentioned->actor->id], $post->mentions()->pluck('actor_id')->all());
        $this->assertDatabaseHas('notifications', ['recipient_id' => $mentioned->id, 'actor_id' => $this->author->actor->id,
            'type' => Notification::TYPE_MENTION, 'notifiable_id' => $post->id]);
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job) => $job->activity['type'] === 'Create'
            && $job->activity['object']['id'] === $response->json('postUri')
            && $job->signingActorId === $this->author->actor->id);
        Http::assertNothingSent();
    }
}
