<?php

namespace Tests\Feature\Communities;

use App\Application\Services\CommunityMembershipService;
use App\Application\Services\CommunityRegistrar;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Application\Services\AnnounceManager;
use App\Application\Services\CommentComposer;
use App\Domain\Communities\Community;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Mention;
use App\Domain\Posts\Post;
use App\Domain\Reactions\Announce;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Serialization\NoteSerializer;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_community_members_are_notified_about_new_posts(): void
    {
        $owner = $this->createFullAccount('notifyowner');
        $member = $this->createFullAccount('notifymember');
        $otherMember = $this->createFullAccount('notifyother');
        $outsider = $this->createFullAccount('notifyout');

        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'avvisi',
            'name' => 'Bacheca avvisi',
        ]);

        app(CommunityMembershipService::class)->join($member->actor, $community);
        app(CommunityMembershipService::class)->join($otherMember->actor, $community);

        $post = app(PostComposer::class)->compose($member->actor, [
            'body' => 'Nuovo avviso per tutti.',
            'visibility' => 'public',
            'community_id' => $community->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $owner->id,
            'actor_id' => $member->actor->id,
            'type' => Notification::TYPE_COMMUNITY_POST,
            'notifiable_id' => $post->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $otherMember->id,
            'type' => Notification::TYPE_COMMUNITY_POST,
            'notifiable_id' => $post->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $member->id,
            'type' => Notification::TYPE_COMMUNITY_POST,
            'notifiable_id' => $post->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $outsider->id,
            'type' => Notification::TYPE_COMMUNITY_POST,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $owner->id,
            'type' => Notification::TYPE_SHARE,
            'notifiable_id' => $post->id,
        ]);

        $notification = Notification::query()
            ->where('recipient_id', $owner->id)
            ->where('type', Notification::TYPE_COMMUNITY_POST)
            ->with(['actor', 'notifiable'])
            ->firstOrFail();

        $this->assertStringContainsString('Bacheca avvisi', $notification->message());
    }

    public function test_pending_private_community_applicants_are_not_notified_of_new_posts(): void
    {
        $owner = $this->createFullAccount('privnotifyowner');
        $applicant = $this->createFullAccount('privnotifyapp');

        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'segreta-avvisi',
            'name' => 'Cerchia chiusa',
            'is_private' => true,
        ]);

        app(FollowManager::class)->follow($applicant->actor, $community->actor);

        $this->assertSame(
            Follow::STATUS_PENDING,
            Follow::query()
                ->where('follower_id', $applicant->actor->id)
                ->where('following_id', $community->actor_id)
                ->value('status')
        );

        $post = app(PostComposer::class)->compose($owner->actor, [
            'body' => 'Solo per chi e\' gia\' dentro.',
            'visibility' => 'public',
            'community_id' => $community->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $applicant->id,
            'type' => Notification::TYPE_COMMUNITY_POST,
            'notifiable_id' => $post->id,
        ]);
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

    public function test_communities_index_hides_private_communities_from_guests_and_other_users(): void
    {
        $owner = $this->createFullAccount('privlistowner');
        $other = $this->createFullAccount('privlistother');

        app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'solo-noi',
            'name' => 'Cerchia nascosta',
            'is_private' => true,
        ]);

        $this->get(route('communities.index'))
            ->assertOk()
            ->assertDontSee('Cerchia nascosta')
            ->assertDontSee('!solo-noi');

        $this->actingAs($other)
            ->get(route('communities.index'))
            ->assertOk()
            ->assertDontSee('Cerchia nascosta')
            ->assertDontSee('!solo-noi');
    }

    public function test_communities_index_shows_private_communities_to_owner_and_staff(): void
    {
        $owner = $this->createFullAccount('privvisible');
        $staff = $this->createFullAccount('privstaff');
        $staff->forceFill(['is_moderator' => true])->save();

        app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'archivio-privato',
            'name' => 'Archivio del creatore',
            'is_private' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('communities.index'))
            ->assertOk()
            ->assertSee('Archivio del creatore')
            ->assertSee('!archivio-privato')
            ->assertSee(__('openbook.communities.private_badge'));

        $this->actingAs($staff)
            ->get(route('communities.index'))
            ->assertOk()
            ->assertSee('Archivio del creatore')
            ->assertSee(__('openbook.communities.private_badge'));
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

    public function test_a_non_member_can_visit_a_private_community_and_request_to_join(): void
    {
        $owner = $this->createFullAccount('privowner');
        $applicant = $this->createFullAccount('applicant');

        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'segreta',
            'name' => 'Cerchia privata',
            'summary' => 'Solo su invito.',
            'is_private' => true,
        ]);

        $secret = app(PostComposer::class)->compose($owner->actor, [
            'body' => 'Post solo per i membri.',
            'visibility' => 'public',
            'community_id' => $community->id,
        ]);

        $this->assertTrue($community->fresh()->actor->manually_approves_followers);

        $this->actingAs($applicant)
            ->get(route('communities.show', $community))
            ->assertOk()
            ->assertSee('Cerchia privata')
            ->assertSee(__('openbook.communities.request_join'))
            ->assertSee(__('openbook.communities.private_wall_locked'))
            ->assertDontSee('Post solo per i membri.');

        $this->actingAs($applicant)
            ->from(route('communities.show', $community))
            ->post(route('communities.join', $community))
            ->assertRedirect(route('communities.show', $community))
            ->assertSessionHas('status', __('openbook.communities.request_sent'));

        $this->assertTrue(
            Follow::query()
                ->where('follower_id', $applicant->actor->id)
                ->where('following_id', $community->actor_id)
                ->where('status', Follow::STATUS_PENDING)
                ->exists()
        );
        $this->assertFalse($community->fresh()->isMember($applicant->actor));

        $this->actingAs($applicant)
            ->get(route('communities.show', $community))
            ->assertOk()
            ->assertSee(__('openbook.communities.pending'))
            ->assertDontSee('Post solo per i membri.');

        $this->actingAs($owner)
            ->get(route('communities.show', $community))
            ->assertOk()
            ->assertSee('Post solo per i membri.')
            ->assertSee(__('openbook.communities.pending_requests'))
            ->assertSee('@applicant');

        $follow = Follow::query()
            ->where('follower_id', $applicant->actor->id)
            ->where('following_id', $community->actor_id)
            ->firstOrFail();

        $this->actingAs($owner)
            ->post(route('communities.accept', [$community, $follow]))
            ->assertRedirect();

        $this->assertTrue($community->fresh()->isMember($applicant->actor));

        $this->actingAs($applicant)
            ->get(route('communities.show', $community))
            ->assertOk()
            ->assertSee('Post solo per i membri.')
            ->assertDontSee(__('openbook.communities.private_wall_locked'));

        $this->assertNotNull($secret->id);
    }

    public function test_a_guest_can_see_a_private_community_landing_but_not_the_wall(): void
    {
        $owner = $this->createFullAccount('privguestowner');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'chiusa',
            'name' => 'Solo iscritti',
            'is_private' => true,
        ]);

        app(PostComposer::class)->compose($owner->actor, [
            'body' => 'Segreto per ospiti.',
            'visibility' => 'public',
            'community_id' => $community->id,
        ]);

        $this->get(route('communities.show', $community))
            ->assertOk()
            ->assertSee('Solo iscritti')
            ->assertSee(__('openbook.communities.private_login_prompt'))
            ->assertSee(__('openbook.communities.private_wall_locked'))
            ->assertDontSee('Segreto per ospiti.');
    }

    public function test_private_community_posts_stay_off_profiles_feeds_and_public_federation(): void
    {
        Queue::fake();

        $owner = $this->createFullAccount('privfedowner');
        $member = $this->createFullAccount('privfedmember');
        $outsider = $this->createFullAccount('privfedoutsider');
        $remoteFollower = $this->createRemoteActor('remotefollower', 'fuori.example');

        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'interni',
            'name' => 'Solo interni',
            'is_private' => true,
        ]);

        Follow::query()->create([
            'follower_id' => $member->actor->id,
            'following_id' => $community->actor_id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);
        $community->increment('members_count');

        Follow::query()->create([
            'follower_id' => $outsider->actor->id,
            'following_id' => $owner->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        Follow::query()->create([
            'follower_id' => $remoteFollower->id,
            'following_id' => $owner->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $post = app(PostComposer::class)->compose($owner->actor, [
            'body' => 'Segreto del circolo.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'community_id' => $community->id,
        ]);

        $this->assertSame(Post::VISIBILITY_PUBLIC, $post->fresh()->visibility);
        $this->assertTrue($post->isInPrivateCommunity());

        Queue::assertNotPushed(
            DeliverActivityJob::class,
            fn (DeliverActivityJob $job): bool => $job->inboxUrl === $remoteFollower->endpoints->shared_inbox
                || $job->inboxUrl === $remoteFollower->endpoints->inbox
        );

        $note = NoteSerializer::forPost($post->fresh(['community.actor', 'actor', 'mentions', 'media', 'hashtags']));
        $this->assertNotContains(
            NoteSerializer::PUBLIC_STREAM,
            array_merge($note['to'] ?? [], $note['cc'] ?? [])
        );

        $this->actingAs($outsider)
            ->get(route('profile.show', $owner->username))
            ->assertOk()
            ->assertDontSee('Segreto del circolo.');

        $this->actingAs($outsider)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertDontSee('Segreto del circolo.');

        $this->actingAs($member)
            ->get(route('communities.show', $community))
            ->assertOk()
            ->assertSee('Segreto del circolo.');

        $this->actingAs($outsider)
            ->get(route('posts.show', $post))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('Segreto del circolo.');
    }

    public function test_comments_on_private_community_posts_are_not_federated_to_author_followers(): void
    {
        Queue::fake();

        $owner = $this->createFullAccount('privcmtowner');
        $member = $this->createFullAccount('privcmtmember');
        $remoteFollowerOfMember = $this->createRemoteActor('seguacemt', 'fuori.example');
        $remoteGroupMember = $this->createRemoteActor('membrocmt', 'circolo.example');

        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'commenti-privati',
            'name' => 'Commenti chiusi',
            'is_private' => true,
        ]);

        Follow::query()->create([
            'follower_id' => $member->actor->id,
            'following_id' => $community->actor_id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        Follow::query()->create([
            'follower_id' => $remoteFollowerOfMember->id,
            'following_id' => $member->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        Follow::query()->create([
            'follower_id' => $remoteGroupMember->id,
            'following_id' => $community->actor_id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $post = app(PostComposer::class)->compose($owner->actor, [
            'body' => 'Post chiuso da commentare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'community_id' => $community->id,
        ]);

        Queue::fake();

        $comment = app(CommentComposer::class)->compose(
            $member->actor,
            $post,
            'Risposta riservata.',
        );

        $note = NoteSerializer::forComment($comment->fresh(['post.community.actor', 'actor', 'mentions', 'parent']));
        $this->assertNotContains(
            NoteSerializer::PUBLIC_STREAM,
            array_merge($note['to'] ?? [], $note['cc'] ?? [])
        );

        Queue::assertNotPushed(
            DeliverActivityJob::class,
            fn (DeliverActivityJob $job): bool => $job->inboxUrl === $remoteFollowerOfMember->endpoints->shared_inbox
                || $job->inboxUrl === $remoteFollowerOfMember->endpoints->inbox
        );

        Queue::assertPushed(
            DeliverActivityJob::class,
            fn (DeliverActivityJob $job): bool => $job->inboxUrl === $remoteGroupMember->endpoints->shared_inbox
                || $job->inboxUrl === $remoteGroupMember->endpoints->inbox
        );

        $this->actingAs($this->createFullAccount('privcmtoutsider'))
            ->post(route('comments.store', $post), ['body' => 'Non dovrei potere.'])
            ->assertNotFound();
    }

    public function test_guests_can_browse_members_of_a_public_community(): void
    {
        $owner = $this->createFullAccount('membriowner');
        $member = $this->createFullAccount('membrimembro');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'membri_pubblica',
            'name' => 'Membri pubblica',
        ]);
        app(CommunityMembershipService::class)->join($member->actor, $community);

        $response = $this->get(route('communities.members', $community));

        $response->assertOk();
        $response->assertSee('membriowner');
        $response->assertSee('membrimembro');
        $response->assertSee('id="ob-follow-list"', false);
        $response->assertSee(route('communities.show', $community), false);
    }

    public function test_pending_join_requests_are_not_listed_among_community_members(): void
    {
        $owner = $this->createFullAccount('pendmembown');
        $applicant = $this->createFullAccount('pendmembapp');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'privata_membri',
            'name' => 'Privata membri',
            'is_private' => true,
        ]);
        app(CommunityMembershipService::class)->join($applicant->actor, $community);

        $this->get(route('communities.members', $community))->assertForbidden();

        $public = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'pubblica_pending',
            'name' => 'Pubblica pending',
        ]);
        Follow::query()->create([
            'follower_id' => $applicant->actor->id,
            'following_id' => $public->actor_id,
            'status' => Follow::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        $this->get(route('communities.members', $public))
            ->assertOk()
            ->assertDontSee('pendmembapp');
    }

    public function test_public_community_members_list_uses_infinite_scroll_markup(): void
    {
        config(['openbook.feed.per_page' => 2]);

        $owner = $this->createFullAccount('scrollown');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'scroll_membri',
            'name' => 'Scroll membri',
        ]);

        foreach (['a', 'b', 'c'] as $suffix) {
            $user = $this->createFullAccount('scrollmem'.$suffix);
            app(CommunityMembershipService::class)->join($user->actor, $community);
        }

        $response = $this->get(route('communities.members', $community));

        $response->assertOk();
        $response->assertSee('data-infinite-scroll', false);
        $response->assertSee('data-next-url="'.route('communities.members', ['community' => $community, 'page' => 2]).'"', false);
    }

    public function test_public_community_show_links_to_the_members_list(): void
    {
        $owner = $this->createFullAccount('linkmembown');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'link_membri',
            'name' => 'Link membri',
        ]);

        $this->get(route('communities.show', $community))
            ->assertOk()
            ->assertSee(route('communities.members', $community), false);
    }

    public function test_owner_can_update_community_avatar_cover_and_name(): void
    {
        Storage::fake('public');
        Queue::fake();

        $owner = $this->createFullAccount('editcommown');
        $remoteMember = $this->createRemoteActor('editcommremote');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'editabile',
            'name' => 'Vecchio nome',
            'summary' => 'Vecchia descrizione',
        ]);

        Follow::query()->create([
            'follower_id' => $remoteMember->id,
            'following_id' => $community->actor_id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($owner)->put(route('communities.update', $community), [
            'name' => 'Nuovo nome',
            'summary' => 'Nuova descrizione',
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 800, 800),
            'cover' => UploadedFile::fake()->image('cover.jpg', 1600, 900),
        ]);

        $response->assertRedirect(route('communities.show', $community));

        $actor = $community->actor->fresh();
        $this->assertSame('Nuovo nome', $actor->name);
        $this->assertSame('Nuova descrizione', $actor->summary);
        $this->assertNotNull($actor->icon_url);
        $this->assertNotNull($actor->image_url);
        $this->assertSame($actor->icon_url, $actor->avatarUrl());
        $this->assertSame($actor->image_url, $actor->coverUrl());

        Queue::assertPushed(DeliverActivityJob::class, function (DeliverActivityJob $job) use ($community, $remoteMember): bool {
            return ($job->inboxUrl === $remoteMember->endpoints->shared_inbox
                    || $job->inboxUrl === $remoteMember->endpoints->inbox)
                && $job->signingActorId === $community->actor_id
                && $job->activity['type'] === 'Update'
                && ($job->activity['object']['type'] ?? null) === 'Group'
                && ($job->activity['object']['name'] ?? null) === 'Nuovo nome'
                && isset($job->activity['object']['icon']['url'])
                && isset($job->activity['object']['image']['url']);
        });
    }

    public function test_non_owner_cannot_edit_a_community(): void
    {
        $owner = $this->createFullAccount('noeditown');
        $other = $this->createFullAccount('noeditother');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'non_editabile',
            'name' => 'Non editabile',
        ]);

        $this->actingAs($other)
            ->get(route('communities.edit', $community))
            ->assertForbidden();

        $this->actingAs($other)
            ->put(route('communities.update', $community), ['name' => 'Hijack'])
            ->assertForbidden();
    }
}
