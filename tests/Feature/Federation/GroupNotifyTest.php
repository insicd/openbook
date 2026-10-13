<?php

namespace Tests\Feature\Federation;

use App\Application\Services\CommunityRegistrar;
use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Serialization\ActivitySerializer;
use App\Federation\Serialization\GroupNotifyNoteSerializer;
use App\Federation\Serialization\NoteSerializer;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class GroupNotifyTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $activity
     */
    private function process(array $activity, Actor $signer, ?Actor $inboxTarget = null): string
    {
        $item = InboxItem::query()->create([
            'target_actor_id' => $inboxTarget?->id,
            'is_shared' => false,
            'remote_activity_uri' => $activity['id'],
            'activity_type' => $activity['type'],
            'actor_uri' => $signer->uri,
            'payload' => json_encode($activity, JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'status' => InboxItem::STATUS_PENDING,
            'received_at' => now(),
        ]);

        return app(InboxActivityProcessor::class)->process($item);
    }

    public function test_an_incoming_follow_with_notify_is_stored(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('campanellaowner');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'campanella',
            'name' => 'Campanella',
        ]);
        $remote = $this->createRemoteActor('mastodoniano');

        $status = $this->process([
            'id' => 'https://remoto.example/activities/follow-notify',
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => $community->actor->uri,
            'notify' => true,
        ], $remote);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $this->assertDatabaseHas('follows', [
            'follower_id' => $remote->id,
            'following_id' => $community->actor->id,
            'status' => Follow::STATUS_ACCEPTED,
            'notify' => true,
        ]);
    }

    public function test_a_repeat_follow_updates_notify_without_clearing_it_when_omitted(): void
    {
        Queue::fake();
        $target = $this->createFullAccount('notifytarget');
        $remote = $this->createRemoteActor('notifyfollower');

        $this->process([
            'id' => 'https://remoto.example/activities/follow-1',
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => $target->actor->uri,
            'notify' => true,
        ], $remote);

        $this->process([
            'id' => 'https://remoto.example/activities/follow-2',
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => $target->actor->uri,
            'notify' => false,
        ], $remote);

        $this->assertDatabaseHas('follows', [
            'follower_id' => $remote->id,
            'following_id' => $target->actor->id,
            'notify' => false,
        ]);

        $this->process([
            'id' => 'https://remoto.example/activities/follow-3',
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => $target->actor->uri,
        ], $remote);

        $this->assertDatabaseHas('follows', [
            'follower_id' => $remote->id,
            'following_id' => $target->actor->id,
            'notify' => false,
        ]);
    }

    public function test_an_expanded_jsonld_notify_flag_is_accepted(): void
    {
        Queue::fake();
        $target = $this->createFullAccount('jsonldnotify');
        $remote = $this->createRemoteActor('jsonldfollower');

        $this->process([
            'id' => 'https://remoto.example/activities/follow-jsonld',
            'type' => 'Follow',
            'actor' => $remote->uri,
            'object' => $target->actor->uri,
            'http://joinmastodon.org/ns#notify' => [
                ['@type' => 'http://www.w3.org/2001/XMLSchema#boolean', '@value' => 'true'],
            ],
        ], $remote);

        $this->assertDatabaseHas('follows', [
            'follower_id' => $remote->id,
            'following_id' => $target->actor->id,
            'notify' => true,
        ]);
    }

    public function test_outgoing_follow_includes_the_notify_flag(): void
    {
        $follower = $this->createFullAccount('localbell');
        $group = $this->createRemoteActor('forum', 'forum.example', [
            'type' => Actor::TYPE_GROUP,
        ]);
        $follow = Follow::query()->create([
            'follower_id' => $follower->actor->id,
            'following_id' => $group->id,
            'status' => Follow::STATUS_ACCEPTED,
            'notify' => true,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);
        $follow->setRelation('follower', $follower->actor);
        $follow->setRelation('following', $group);

        $activity = ActivitySerializer::follow($follow);

        $this->assertTrue($activity['notify']);
        $this->assertSame($group->uri, $activity['object']);
    }

    public function test_a_member_can_toggle_notify_on_a_remote_group(): void
    {
        Queue::fake();
        $viewer = $this->createFullAccount('belluser');
        $group = $this->createRemoteActor('circolo', 'forum.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Circolo remoto',
        ]);
        Follow::query()->create([
            'follower_id' => $viewer->actor->id,
            'following_id' => $group->id,
            'status' => Follow::STATUS_ACCEPTED,
            'requested_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->from(route('actors.show', $group))
            ->post(route('actors.notify', $group))
            ->assertRedirect(route('actors.show', $group))
            ->assertSessionHas('status', __('openbook.follow.notify_enabled'));

        $this->assertTrue(app(FollowManager::class)->notifies($viewer->actor, $group));
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Follow'
            && $job->activity['notify'] === true
            && $job->activity['object'] === $group->uri
            && $job->signingActorId === $viewer->actor->id);

        $this->actingAs($viewer)
            ->get(route('actors.show', $group))
            ->assertOk()
            ->assertSee(route('actors.notify.destroy', $group), false)
            ->assertSee(__('openbook.follow.notify_disable_aria'), false);

        $this->actingAs($viewer)
            ->from(route('actors.show', $group))
            ->delete(route('actors.notify.destroy', $group))
            ->assertRedirect(route('actors.show', $group));

        $this->assertFalse(app(FollowManager::class)->notifies($viewer->actor, $group));
    }

    public function test_notify_cannot_be_enabled_on_a_person(): void
    {
        $viewer = $this->createFullAccount('bellperson');
        $other = $this->createFullAccount('notagroup');
        app(FollowManager::class)->follow($viewer->actor, $other->actor);

        $this->actingAs($viewer)
            ->post(route('actors.notify', $other->actor))
            ->assertSessionHasErrors('notify');
    }

    public function test_a_remote_group_announce_notifies_local_followers_with_the_bell(): void
    {
        Queue::fake();
        $member = $this->createFullAccount('ascoltatore');
        $silent = $this->createFullAccount('silenzioso');
        $group = $this->createRemoteActor('circolo', 'forum.example', [
            'type' => Actor::TYPE_GROUP,
            'name' => 'Circolo remoto',
        ]);
        $author = $this->createRemoteActor('scrittore', 'forum.example');

        foreach ([$member, $silent] as $user) {
            $follow = app(FollowManager::class)->follow($user->actor, $group);
            $follow->update(['status' => Follow::STATUS_ACCEPTED, 'accepted_at' => now()]);
        }
        Follow::query()
            ->where('follower_id', $member->actor->id)
            ->where('following_id', $group->id)
            ->update(['notify' => true]);

        $noteUri = $author->uri.'/posts/'.uniqid();
        $status = $this->process([
            'id' => $group->uri.'/activities/'.uniqid(),
            'type' => 'Announce',
            'actor' => $group->uri,
            'object' => [
                'id' => $noteUri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'content' => '<p>Post col campanello</p>',
                'published' => now()->toAtomString(),
                'to' => ['https://www.w3.org/ns/activitystreams#Public', $group->uri],
            ],
        ], $group);

        $this->assertSame(InboxItem::STATUS_PROCESSED, $status);
        $post = Post::query()->where('uri', $noteUri)->first();
        $this->assertNotNull($post);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $member->id,
            'type' => Notification::TYPE_COMMUNITY_POST,
            'notifiable_id' => $post->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'recipient_id' => $silent->id,
            'type' => Notification::TYPE_COMMUNITY_POST,
            'notifiable_id' => $post->id,
        ]);

        $notification = Notification::query()
            ->where('recipient_id', $member->id)
            ->where('type', Notification::TYPE_COMMUNITY_POST)
            ->with(['actor', 'notifiable.announces.actor'])
            ->firstOrFail();

        $this->assertStringContainsString('Circolo remoto', $notification->message());
    }

    public function test_a_local_group_delivers_a_group_create_to_all_remote_members(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('groupbellowner');
        $member = $this->createFullAccount('groupbellmember');
        $community = app(CommunityRegistrar::class)->register($owner, [
            'slug' => 'forumlocale',
            'name' => 'Forum locale',
        ]);
        app(FollowManager::class)->follow($member->actor, $community->actor);

        $remote = $this->createRemoteActor('mastodoniano', 'mastodon.example');
        app(FollowManager::class)->follow($remote, $community->actor);

        $post = app(PostComposer::class)->compose($member->actor, [
            'body' => 'Ciao a chi ha la campanella.',
            'visibility' => 'public',
            'community_id' => $community->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $owner->id,
            'type' => Notification::TYPE_COMMUNITY_POST,
            'notifiable_id' => $post->id,
        ]);

        $followers = $community->actor->activityPubId().'/followers';
        $noteId = GroupNotifyNoteSerializer::noteUri($community->actor, $post);

        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->signingActorId === $community->actor->id
            && ($job->activity['type'] ?? null) === 'Create'
            && ($job->activity['actor'] ?? null) === $community->actor->activityPubId()
            && ($job->activity['to'][0] ?? null) === $followers
            && in_array(NoteSerializer::PUBLIC_STREAM, $job->activity['cc'] ?? [], true)
            && ($job->activity['object']['attributedTo'] ?? null) === $community->actor->activityPubId()
            && ($job->activity['object']['id'] ?? null) === $noteId
            && $job->inboxUrl === 'https://mastodon.example/inbox');

        $this->get(route('group.notify_note', [
            'username' => $community->actor->preferred_username,
            'post' => $post->id,
        ]), ['Accept' => 'application/activity+json'])
            ->assertOk()
            ->assertJsonPath('type', 'Note')
            ->assertJsonPath('attributedTo', $community->actor->activityPubId())
            ->assertJsonPath('to.0', $followers)
            ->assertJsonPath('quoteUrl', NoteSerializer::postUri($post));
    }
}
