<?php

namespace Tests\Feature\Communities;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\PostComposer;
use App\Application\Services\AnnounceManager;
use App\Domain\Communities\Community;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\NoteSerializer;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

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
        $this->assertSame(2, $community->fresh()->members_count);

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
        $response->assertJsonPath('links.0.href', url('/users/teatro'));
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

    public function test_owner_can_add_and_remove_a_community_moderator(): void
    {
        $owner = $this->createFullAccount('owner4');
        $mod = $this->createFullAccount('moderator4');

        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'staffed',
            'name' => 'Con staff',
        ]);

        $this->actingAs($owner)
            ->post(route('communities.moderators.store', $community), ['username' => 'moderator4'])
            ->assertRedirect();

        $this->assertTrue($community->fresh()->isModerator($mod));

        $this->actingAs($owner)
            ->delete(route('communities.moderators.destroy', [$community, $mod]))
            ->assertRedirect();

        $this->assertFalse($community->fresh()->isModerator($mod));
    }

    public function test_local_search_resolves_a_community_handle(): void
    {
        $owner = $this->createFullAccount('owner5');
        app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'ricerca',
            'name' => 'Da cercare',
        ]);

        $this->actingAs($owner)
            ->get(route('search.create', ['q' => 'ricerca@'.config('openbook.domain')]))
            ->assertRedirect(route('communities.show', 'ricerca'));
    }

    public function test_remote_group_profile_shows_composer_for_members(): void
    {
        $member = $this->createFullAccount('membremote');
        $group = $this->createRemoteActor('circolo', 'forum.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Circolo remoto',
        ]);

        Follow::query()->create([
            'follower_id' => $member->actor->id,
            'following_id' => $group->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($member)
            ->get(route('actors.show', $group))
            ->assertOk()
            ->assertSee('name="addressed_group_actor_id"', false)
            ->assertSee($group->id);
    }

    public function test_members_can_address_a_remote_group_with_mention_audience_and_delivery(): void
    {
        Queue::fake();

        $member = $this->createFullAccount('posterremoto');
        $group = $this->createRemoteActor('forum', 'groups.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Forum remoto',
        ]);

        Follow::query()->create([
            'follower_id' => $member->actor->id,
            'following_id' => $group->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $post = app(PostComposer::class)->compose($member->actor, [
            'body' => 'Ciao community remota.',
            'visibility' => 'public',
            'addressed_group_actor_id' => $group->id,
        ]);

        $this->assertTrue(
            Mention::query()
                ->where('mentionable_id', $post->id)
                ->where('actor_id', $group->id)
                ->exists()
        );

        $note = NoteSerializer::forPost($post->fresh(['mentions.actor', 'community.actor', 'actor.endpoints', 'hashtags', 'media', 'quotedPost']));
        $this->assertContains($group->uri, $note['to']);
        $this->assertTrue(collect($note['tag'] ?? [])->contains(
            fn (array $tag): bool => ($tag['type'] ?? null) === 'Mention' && ($tag['href'] ?? null) === $group->uri
        ));

        Queue::assertPushed(
            DeliverActivityJob::class,
            fn (DeliverActivityJob $job): bool => $job->inboxUrl === $group->endpoints->inbox
                || $job->inboxUrl === $group->endpoints->shared_inbox
        );
    }

    public function test_remote_group_wall_lists_newest_posts_first(): void
    {
        $viewer = $this->createFullAccount('wallorder');
        $group = $this->createRemoteActor('timeline', 'forum.example', [
            'type' => Actor::TYPE_GROUP,
            'posts_fetched_at' => now(),
        ]);
        $author = $this->createRemoteActor('autorewall', 'forum.example');

        Follow::query()->create([
            'follower_id' => $viewer->actor->id,
            'following_id' => $group->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $older = Post::query()->create([
            'actor_id' => $author->id,
            'uri' => $author->uri.'/posts/older',
            'body' => 'Post piu vecchio della community',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDays(2),
        ]);

        $newer = Post::query()->create([
            'actor_id' => $author->id,
            'uri' => $author->uri.'/posts/newer',
            'body' => 'Post piu recente della community',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
        ]);

        // Announce creati in ordine inverso rispetto a published_at (come dopo
        // un fetch outbox che processa orderedItems dal piu' recente).
        app(AnnounceManager::class)->announce($group, $newer, notify: false);
        usleep(1000);
        app(AnnounceManager::class)->announce($group, $older, notify: false);

        $html = $this->actingAs($viewer)
            ->get(route('actors.show', $group))
            ->assertOk()
            ->getContent();

        $posNewer = strpos($html, 'Post piu recente della community');
        $posOlder = strpos($html, 'Post piu vecchio della community');

        $this->assertNotFalse($posNewer);
        $this->assertNotFalse($posOlder);
        $this->assertLessThan($posOlder, $posNewer);
    }

    public function test_body_mention_of_cached_remote_group_is_resolved(): void
    {
        Queue::fake();

        $author = $this->createFullAccount('tagger');
        $group = $this->createRemoteActor('tagged', 'groups.example', [
            'type' => Actor::TYPE_GROUP,
        ]);

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Messaggio per @tagged@groups.example grazie.',
            'visibility' => 'public',
        ]);

        $this->assertTrue(
            Mention::query()
                ->where('mentionable_id', $post->id)
                ->where('actor_id', $group->id)
                ->exists()
        );

        Queue::assertPushed(
            DeliverActivityJob::class,
            fn (DeliverActivityJob $job): bool => $job->inboxUrl === $group->endpoints->inbox
                || $job->inboxUrl === $group->endpoints->shared_inbox
        );
    }

    public function test_communities_index_lists_local_public_communities_by_default(): void
    {
        $owner = $this->createFullAccount('listowner');
        app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'biblioteca',
            'name' => 'Biblioteca locale',
        ]);

        $this->get(route('communities.index'))
            ->assertOk()
            ->assertSee('Biblioteca locale')
            ->assertSee('!biblioteca')
            ->assertSee(__('openbook.communities.scope_local'))
            ->assertSee(__('openbook.communities.scope_remote'));
    }

    public function test_communities_index_remote_tab_lists_followed_remote_groups(): void
    {
        $member = $this->createFullAccount('remotemember');
        $followed = $this->createRemoteActor('circolo', 'forum.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Circolo remoto',
        ]);
        $ignored = $this->createRemoteActor('altro', 'forum.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Non seguito',
        ]);

        Follow::query()->create([
            'follower_id' => $member->actor->id,
            'following_id' => $followed->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($member)
            ->get(route('communities.index', ['scope' => 'remote']))
            ->assertOk()
            ->assertSee('Circolo remoto')
            ->assertSee('!circolo@forum.example')
            ->assertDontSee('Non seguito')
            ->assertDontSee($ignored->name);
    }

    public function test_communities_index_remote_tab_is_empty_for_guests(): void
    {
        $this->createRemoteActor('ospite', 'forum.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Solo con account',
        ]);

        $this->get(route('communities.index', ['scope' => 'remote']))
            ->assertOk()
            ->assertSee(__('openbook.communities.empty_remote_guest'))
            ->assertDontSee('Solo con account');
    }
}
