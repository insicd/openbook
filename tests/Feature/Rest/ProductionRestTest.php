<?php

namespace Tests\Feature\Rest;

use App\Domain\Accounts\User;
use App\Domain\SocialGraph\Follow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class ProductionRestTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->viewer = $this->createFullAccount('productionrest');
        Passport::actingAs($this->viewer, ['api'], 'rest');
        $this->withToken('test-token');
        $this->app->instance('env', 'production');
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_production_post_creation_editing_reactions_and_deletion(): void
    {
        $created = $this->postJson('/rest/v1/posts', ['postContent' => 'Production post', 'postVisibility' => 'public'])->assertCreated();
        $id = $created->json('postId');
        $url = '/rest/v1/posts/'.$id;
        $this->patchJson($url, ['postContent' => 'Updated'])->assertOk()->assertJsonPath('postContent', 'Updated');
        $this->getJson('/rest/v1/posts')->assertOk();
        $this->getJson('/rest/v1/posts?engine=timeline')->assertOk()->assertJsonPath('0.postId', $id);
        $this->getJson('/rest/v1/me/posts')->assertOk()->assertJsonPath('0.postId', $id);
        $this->getJson('/rest/v1/persons/'.$this->viewer->actor->id.'/posts')->assertOk()->assertJsonPath('0.postId', $id);
        foreach (['like', 'announce'] as $relation) {
            $this->put($url.'/'.$relation)->assertCreated();
            $this->delete($url.'/'.$relation)->assertNoContent();
        }
        $this->delete($url)->assertNoContent();
        $this->getJson($url)->assertNotFound();
        $this->withoutHeader('Authorization')->postJson('/rest/v1/posts', ['postContent' => 'Unauthenticated', 'postVisibility' => 'public'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_production_incoming_requests_are_manageable_only_by_the_recipient(): void
    {
        $sender = $this->createFullAccount('productionrequester');
        $request = Follow::create(['follower_id' => $sender->actor->id, 'following_id' => $this->viewer->actor->id,
            'status' => 'pending', 'requested_at' => now()]);
        $url = '/rest/v1/me/follow-requests/'.$sender->actor->id;
        $this->getJson('/rest/v1/me/follow-requests')->assertOk()->assertJsonPath('0.personId', $sender->actor->id);
        $this->patchJson($url, ['followStatus' => 'accepted'])->assertNoContent();
        $this->assertSame('accepted', $request->fresh()->status);
        $this->delete($url)->assertNotFound();
        $rejected = $this->createFullAccount('productionrejected');
        $pending = Follow::create(['follower_id' => $rejected->actor->id, 'following_id' => $this->viewer->actor->id,
            'status' => 'pending', 'requested_at' => now()]);
        $this->delete('/rest/v1/me/follow-requests/'.$rejected->actor->id)->assertNoContent();
        $this->assertDatabaseMissing('follows', ['id' => $pending->id]);
        $this->assertSame('accepted', $request->fresh()->status);
        $this->getJson('/rest/v1/me/follow-requests')->assertOk()->assertExactJson([]);
        Http::assertNothingSent();
    }
}
