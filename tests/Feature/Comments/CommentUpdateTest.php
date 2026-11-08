<?php

namespace Tests\Feature\Comments;

use App\Application\Services\CommentComposer;
use App\Application\Services\CommunityRegistrar;
use App\Domain\Comments\Comment;
use App\Domain\Federation\Relay;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use App\Federation\Inbox\InboxActivityProcessor;
use App\Federation\Inbox\InboxItem;
use App\Federation\Serialization\NoteSerializer;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class CommentUpdateTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_web_edit_preserves_media_and_thread_and_works_without_verified_email(): void
    {
        Queue::fake();
        Storage::fake('public');
        $owner = $this->createFullAccount('editcomment');
        $post = Post::create(['actor_id' => $owner->actor->id, 'body' => 'Post', 'visibility' => 'public', 'published_at' => now()]);
        $composer = app(CommentComposer::class);
        $parent = $composer->compose($owner->actor, $post, 'Parent');
        $comment = $composer->compose($owner->actor, $post, 'Original text', $parent, [UploadedFile::fake()->image('photo.jpg')]);
        $mediaIds = $comment->media->modelKeys();
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($owner)->get(route('comments.edit', $comment))->assertOk()
            ->assertSee('Original text')->assertSee('name="body"', false)->assertDontSee('name="images[]"', false);
        $this->get(route('posts.show', $post))->assertOk()->assertSee(route('comments.edit', $comment), false);
        $this->get(route('comments.show', $comment))->assertOk()->assertSee(route('comments.edit', $comment), false);
        $this->put(route('comments.update', $comment), ['body' => 'Edited text', 'parent_comment_id' => null, 'post_id' => 'ignored'])
            ->assertRedirect(route('comments.show', $comment));
        $comment->refresh();
        $this->assertSame('Edited text', $comment->body);
        $this->assertNotNull($comment->edited_at);
        $this->assertSame($parent->id, $comment->parent_comment_id);
        $this->assertSame($mediaIds, $comment->media->modelKeys());
        $this->assertSame(2, $post->fresh()->comments_count);
        $this->assertSame(1, $parent->fresh()->replies_count);
        $this->get(route('comments.show', $comment))->assertSee(__('openbook.posts.edited'));
        $this->put(route('comments.update', $comment), ['body' => '  '])->assertSessionHasErrors('body');
    }

    public function test_others_admin_remote_and_deleted_cannot_be_edited_and_hidden_posts_are_checked(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('editowner');
        $admin = $this->createFullAccount('editadmin');
        $admin->forceFill(['is_admin' => true])->save();
        $post = Post::create(['actor_id' => $owner->actor->id, 'body' => 'Post', 'visibility' => 'public', 'published_at' => now()]);
        $comment = app(CommentComposer::class)->compose($owner->actor, $post, 'Original');
        $this->actingAs($admin)->get(route('comments.edit', $comment))->assertForbidden();
        $this->put(route('comments.update', $comment), ['body' => 'No'])->assertForbidden();
        $this->get(route('comments.show', $comment))->assertDontSee(route('comments.edit', $comment), false);
        $this->actingAs($owner);
        foreach ([['uri' => 'https://example.test/comment'], ['uri' => null, 'status' => 'deleted']] as $values) {
            $comment->update($values);
            $this->get(route('comments.edit', $comment))->assertForbidden();
            $this->put(route('comments.update', $comment), ['body' => 'No'])->assertForbidden();
            try {
                app(CommentComposer::class)->update($owner->actor, $comment, 'No');
                $this->fail('Service must reject unsupported comments.');
            } catch (\InvalidArgumentException) {
                $this->assertSame('Original', $comment->fresh()->body);
            }
        }
        $comment->update(['status' => 'published']);
        $post->update(['actor_id' => $admin->actor->id, 'visibility' => 'followers']);
        $this->get(route('comments.edit', $comment))->assertNotFound();
        $this->put(route('comments.update', $comment), ['body' => 'No'])->assertNotFound();
    }

    public function test_update_synchronizes_mentions_without_repeating_reply_notifications_or_noop_updates(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('mentioneditor');
        $first = $this->createFullAccount('oldmention');
        $second = $this->createFullAccount('newmention');
        $post = Post::create(['actor_id' => $first->actor->id, 'body' => 'Post', 'visibility' => 'public', 'published_at' => now()]);
        $composer = app(CommentComposer::class);
        $comment = $composer->compose($owner->actor, $post, 'Hello @oldmention');
        $notificationCount = Notification::where('type', Notification::TYPE_COMMENT)->count();
        $composer->update($owner->actor, $comment, 'Hello @newmention');
        $this->assertSame([$second->actor->id], $comment->mentions()->pluck('actor_id')->all());
        $this->assertSame($notificationCount, Notification::where('type', Notification::TYPE_COMMENT)->count());
        $mentionsCount = Notification::where('type', Notification::TYPE_MENTION)->count();
        $editedAt = $comment->fresh()->edited_at->toISOString();
        $this->travel(1)->minutes();
        $composer->update($owner->actor, $comment, 'Hello @newmention');
        $this->assertSame($editedAt, $comment->fresh()->edited_at->toISOString());
        $this->assertSame($mentionsCount, Notification::where('type', Notification::TYPE_MENTION)->count());
    }

    public function test_update_delivers_to_remote_parent_and_follower_with_stable_note_identity(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $owner = $this->createFullAccount('federatededitor');
        $remote = $this->createRemoteActor('remoteparent', 'parent.example');
        $follower = $this->createRemoteActor('remotefollower', 'follower.example');
        Follow::create(['follower_id' => $follower->id, 'following_id' => $owner->actor->id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $post = Post::create(['actor_id' => $remote->id, 'uri' => 'https://parent.example/post', 'body' => 'Post', 'visibility' => 'public', 'published_at' => now()]);
        $parent = Comment::create(['post_id' => $post->id, 'actor_id' => $remote->id, 'uri' => 'https://parent.example/comment', 'body' => 'Parent']);
        $comment = app(CommentComposer::class)->compose($owner->actor, $post, 'Original', $parent);
        $before = NoteSerializer::forComment($comment);
        $this->travel(1)->seconds();
        app(CommentComposer::class)->update($owner->actor, $comment, 'Edited');
        foreach ([$remote, $follower] as $target) {
            Queue::assertPushed(DeliverActivityJob::class, function ($job) use ($target, $before, $owner): bool {
                $note = $job->activity['object'] ?? [];

                return $job->activity['type'] === 'Update' && $job->inboxUrl === $target->endpoints->shared_inbox
                    && $job->signingActorId === $owner->actor->id && $job->afterCommit
                    && $note['id'] === $before['id'] && $note['inReplyTo'] === $before['inReplyTo']
                    && $note['published'] === $before['published'] && isset($note['updated'])
                    && str_contains($note['content'], 'Edited');
            });
        }
    }

    public function test_private_community_update_does_not_reach_personal_followers_or_relays(): void
    {
        Queue::fake();
        $owner = $this->createFullAccount('privateeditor');
        $community = app(CommunityRegistrar::class)->register($owner, ['slug' => 'private-edit', 'name' => 'Private', 'is_private' => true]);
        $member = $this->createRemoteActor('member', 'member.example');
        $follower = $this->createRemoteActor('follower', 'follower.example');
        foreach ([[$member->id, $community->actor_id], [$follower->id, $owner->actor->id]] as [$from, $to]) {
            Follow::create(['follower_id' => $from, 'following_id' => $to, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        }
        $post = Post::create(['actor_id' => $owner->actor->id, 'community_id' => $community->id, 'body' => 'Private', 'visibility' => 'public', 'published_at' => now()]);
        $comment = app(CommentComposer::class)->compose($owner->actor, $post, 'Original');
        app(CommentComposer::class)->update($owner->actor, $comment, 'Edited');
        Queue::assertPushed(DeliverActivityJob::class, fn ($job) => $job->activity['type'] === 'Update' && $job->inboxUrl === $member->endpoints->shared_inbox);
        Queue::assertNotPushed(DeliverActivityJob::class, fn ($job) => $job->activity['type'] === 'Update' && ($job->inboxUrl === $follower->endpoints->shared_inbox || $job->relayId !== null));
    }

    public function test_only_public_comment_updates_reach_an_enabled_relay(): void
    {
        $owner = $this->createFullAccount('relayeditor');
        $relay = Relay::create(['protocol' => Relay::PROTOCOL_MASTODON,
            'actor_uri' => 'https://relay.example/actor', 'inbox_url' => 'https://relay.example/inbox',
            'inbox_url_hash' => hash('sha256', 'https://relay.example/inbox'),
            'state' => Relay::STATE_ACCEPTED, 'receive_enabled' => true, 'publish_enabled' => true,
            'accepted_at' => now()]);
        foreach (['public', 'unlisted', 'followers'] as $visibility) {
            Queue::fake();
            $post = Post::create(['actor_id' => $owner->actor->id, 'body' => 'Post',
                'visibility' => $visibility, 'published_at' => now()]);
            $comment = app(CommentComposer::class)->compose($owner->actor, $post, 'Original');
            app(CommentComposer::class)->update($owner->actor, $comment, 'Edited');
            $matches = fn ($job) => $job->activity['type'] === 'Update' && $job->relayId === $relay->id;
            if ($visibility === 'public') {
                Queue::assertPushed(DeliverActivityJob::class, $matches);
            } else {
                Queue::assertNotPushed(DeliverActivityJob::class, $matches);
            }
        }
    }

    public function test_received_comment_update_uses_the_existing_inbox_flow(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $owner = $this->createFullAccount('incomingeditor');
        $remote = $this->createRemoteActor('incomingcomment');
        $post = Post::create(['actor_id' => $owner->actor->id, 'body' => 'Post',
            'visibility' => 'public', 'published_at' => now()]);
        $publishedAt = now()->subHour()->startOfSecond();
        $uri = $remote->uri.'/comments/one';
        foreach (['Create' => 'Original', 'Update' => 'Edited'] as $type => $body) {
            $activity = ['id' => $uri.'/'.$type, 'type' => $type, 'actor' => $remote->uri,
                'object' => ['id' => $uri, 'type' => 'Note', 'attributedTo' => $remote->uri,
                    'inReplyTo' => NoteSerializer::postUri($post), 'content' => $body,
                    'published' => $publishedAt->toAtomString(), 'updated' => now()->toAtomString(),
                    'to' => ['https://www.w3.org/ns/activitystreams#Public']]];
            $item = InboxItem::create(['target_actor_id' => $owner->actor->id, 'is_shared' => false,
                'remote_activity_uri' => $activity['id'], 'activity_type' => $type, 'actor_uri' => $remote->uri,
                'payload' => json_encode($activity, JSON_THROW_ON_ERROR), 'signature_valid' => true,
                'status' => InboxItem::STATUS_PENDING, 'received_at' => now()]);
            $this->assertSame(InboxItem::STATUS_PROCESSED, app(InboxActivityProcessor::class)->process($item));
        }
        $comment = Comment::where('uri', $uri)->sole();
        $this->assertSame('Edited', $comment->body);
        $this->assertTrue($comment->created_at->equalTo($publishedAt));
        $this->assertNotNull($comment->edited_at);
        $this->assertSame(1, $post->fresh()->comments_count);
        $this->assertSame(1, Notification::where('type', Notification::TYPE_COMMENT)->count());
    }
}
