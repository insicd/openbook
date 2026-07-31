<?php

namespace Tests\Feature\Communities;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\PostComposer;
use App\Domain\Communities\Community;
use App\Domain\Reactions\Announce;
use App\Federation\Actors\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_a_user_can_create_a_local_community_group_actor(): void
    {
        $owner = $this->createFullAccount('owner');

        $response = $this->actingAs($owner)->post(route('communities.store'), [
            'slug' => 'bici',
            'name' => 'Amanti della bici',
            'summary' => 'Pedalate e racconti.',
        ]);

        $community = Community::query()->where('slug', 'bici')->first();
        $this->assertNotNull($community);
        $response->assertRedirect(route('communities.show', $community));

        $this->assertSame(Actor::TYPE_GROUP, $community->actor->type);
        $this->assertTrue($community->actor->is_local);
        $this->assertSame(1, $community->members_count);
        $this->assertTrue($community->isMember($owner->actor));
    }

    public function test_members_can_post_to_a_community_and_it_is_announced_by_the_group(): void
    {
        $owner = $this->createFullAccount('owner2');
        $member = $this->createFullAccount('member2');

        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'libri',
            'name' => 'Club del libro',
        ]);

        $this->actingAs($member)->post(route('communities.join', $community))->assertRedirect();
        $this->assertTrue($community->fresh()->isMember($member->actor));

        $post = app(PostComposer::class)->compose($member->actor, [
            'body' => 'Il mio libro del mese.',
            'visibility' => 'public',
            'community_id' => $community->id,
        ]);

        $this->assertSame($community->id, $post->community_id);
        $this->assertSame(1, $community->fresh()->posts_count);
        $this->assertTrue(
            Announce::query()
                ->where('actor_id', $community->actor_id)
                ->where('post_id', $post->id)
                ->exists()
        );

        $this->actingAs($owner)
            ->get(route('communities.show', $community))
            ->assertOk()
            ->assertSee('Il mio libro del mese.');
    }

    public function test_webfinger_resolves_local_communities(): void
    {
        $owner = $this->createFullAccount('owner3');
        app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'teatro',
            'name' => 'Teatro locale',
        ]);

        $response = $this->get('/.well-known/webfinger?resource=acct:teatro@'.config('openbook.domain'));

        $response->assertOk();
        $response->assertJsonPath('subject', 'acct:teatro@'.config('openbook.domain'));
        $response->assertJsonPath('links.0.href', url('/@teatro'));
    }

    public function test_community_slug_cannot_collide_with_a_username(): void
    {
        $alice = $this->createFullAccount('alice');
        $bob = $this->createFullAccount('bob');

        $this->actingAs($bob)->post(route('communities.store'), [
            'slug' => 'alice',
            'name' => 'Non dovrebbe funzionare',
        ])->assertSessionHasErrors('slug');

        $this->assertNull(Community::query()->where('slug', 'alice')->first());
        $this->assertNotNull($alice->actor);
    }
}
