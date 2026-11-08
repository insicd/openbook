<?php

namespace Tests\Feature\Rest;

use App\Application\Services\CommentComposer;
use App\Application\Services\CommunityRegistrar;
use App\Domain\Accounts\User;
use App\Domain\Comments\Comment;
use App\Domain\Messaging\Conversation;
use App\Domain\Posts\Post;
use App\Domain\SocialGraph\Follow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class PostCommentTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase, UsesPassportKeys;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->author = $this->createFullAccount('commentapi');
        Passport::actingAs($this->author, ['api'], 'rest');
        $this->withToken('test-token');
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function makePost(array $values = []): Post
    {
        return Post::create(array_merge(['actor_id' => $this->author->actor->id,
            'body' => 'A post', 'visibility' => 'public', 'status' => 'published', 'published_at' => now()], $values));
    }

    private function url(Post $post, ?Comment $comment = null): string
    {
        return '/rest/v1/posts/'.$post->id.'/comments'.($comment ? '/'.$comment->id : '');
    }

    private function comment(Post $post, array $values = []): Comment
    {
        return Comment::create(array_merge(['post_id' => $post->id, 'actor_id' => $this->author->actor->id,
            'body' => 'Original comment', 'status' => 'published'], $values));
    }

    public function test_crud_and_nested_replies_preserve_links_and_counters(): void
    {
        $post = $this->makePost();
        $created = $this->postJson($this->url($post), ['commentContent' => 'First comment'])->assertCreated()
            ->assertJsonPath('commentPostId', $post->id)->assertJsonPath('commentParentId', null)
            ->assertJsonPath('commentAuthor.personId', $this->author->actor->id)->assertJsonMissingPath('data');
        $comment = Comment::findOrFail($created->json('commentId'));
        $created->assertHeader('Location', route('rest.v1.comments.show', ['postId' => $post->id, 'commentId' => $comment->id]));
        $this->postJson($this->url($post), ['commentContent' => 'A reply', 'commentParentId' => $comment->id])->assertCreated()
            ->assertJsonPath('commentParentId', $comment->id);
        $this->assertSame(2, $post->fresh()->comments_count);
        $this->getJson($this->url($post, $comment))->assertOk()->assertJsonPath('commentCounters.commentRepliesCount', 1);
        $this->patchJson($this->url($post, $comment), ['commentContent' => 'Edited comment'])->assertOk()
            ->assertJsonPath('commentContent', 'Edited comment')->assertJsonStructure(['commentEditedAt']);
        $this->assertSame(2, $post->fresh()->comments_count);
        $this->deleteJson($this->url($post, $comment))->assertNoContent();
        $this->deleteJson($this->url($post, $comment))->assertNotFound();
        $this->getJson($this->url($post, $comment))->assertNotFound();
        $this->getJson($this->url($post))->assertOk()->assertJsonCount(1)->assertJsonPath('0.commentParentId', $comment->id);
        $this->assertSame(1, $post->fresh()->comments_count);
    }

    public function test_nested_routes_and_ownership_do_not_grant_admin_access(): void
    {
        $post = $this->makePost();
        $comment = $this->comment($post);
        $otherPost = $this->makePost();
        foreach ([$this->url($otherPost, $comment), '/rest/v1/posts/invalid/comments/'.$comment->id] as $url) {
            $this->getJson($url)->assertNotFound();
            $this->patchJson($url, ['commentContent' => 'No'])->assertNotFound();
            $this->deleteJson($url)->assertNotFound();
        }
        $admin = $this->createFullAccount('commentadmin');
        $admin->forceFill(['is_admin' => true])->save();
        Passport::actingAs($admin, ['api'], 'rest');
        $this->patchJson($this->url($post, $comment), ['commentContent' => 'No'])->assertNotFound();
        $this->deleteJson($this->url($post, $comment))->assertNotFound();
        $this->assertSame('Original comment', $comment->fresh()->body);
    }

    public function test_invisible_deleted_and_conversation_posts_are_excluded_before_304_and_mutations(): void
    {
        $other = $this->createFullAccount('privateauthor');
        $conversation = Conversation::create(['participant_low_id' => $this->author->actor->id, 'participant_high_id' => $other->actor->id]);
        foreach ([['actor_id' => $other->actor->id, 'visibility' => 'followers'],
            ['status' => 'deleted'], ['conversation_id' => $conversation->id],
        ] as $values) {
            $post = $this->makePost($values);
            $comment = $this->comment($post);
            $this->withHeader('If-None-Match', '*')->getJson($this->url($post))->assertNotFound();
            $this->getJson($this->url($post, $comment))->assertNotFound();
            $this->postJson($this->url($post), ['commentContent' => 'No'])->assertNotFound();
            $this->patchJson($this->url($post, $comment), ['commentContent' => 'No'])->assertNotFound();
            $this->deleteJson($this->url($post, $comment))->assertNotFound();
        }
    }

    public function test_private_community_and_followers_use_post_visibility(): void
    {
        $owner = $this->createFullAccount('communityowner');
        $community = app(CommunityRegistrar::class)->register($owner, ['name' => 'Private comments', 'slug' => 'private-comments', 'is_private' => true]);
        $post = $this->makePost(['actor_id' => $owner->actor->id, 'community_id' => $community->id]);
        $this->comment($post);
        $this->getJson($this->url($post))->assertNotFound();
        Follow::create(['follower_id' => $this->author->actor->id, 'following_id' => $community->actor_id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $this->getJson($this->url($post))->assertOk()->assertJsonCount(1);
        $post->update(['community_id' => null, 'visibility' => 'followers']);
        $this->getJson($this->url($post))->assertNotFound();
        Follow::create(['follower_id' => $this->author->actor->id, 'following_id' => $owner->actor->id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $this->postJson($this->url($post), ['commentContent' => 'Visible reply'])->assertCreated();
    }

    public function test_direct_creation_is_rejected_and_email_guard_only_blocks_new_comments(): void
    {
        $post = $this->makePost(['visibility' => 'direct']);
        $this->postJson($this->url($post), ['commentContent' => 'No'])->assertStatus(409);
        $post->update(['visibility' => 'public']);
        $comment = $this->comment($post);
        $this->author->forceFill(['email_verified_at' => null])->save();
        $this->postJson($this->url($post), ['commentContent' => 'No'])->assertForbidden();
        $this->patchJson($this->url($post, $comment), ['commentContent' => 'Allowed edit'])->assertOk();
        $this->deleteJson($this->url($post, $comment))->assertNoContent();
    }

    public function test_input_and_parent_validation_do_not_change_content(): void
    {
        $post = $this->makePost();
        $comment = $this->comment($post);
        $foreign = $this->comment($this->makePost());
        $deleted = $this->comment($post, ['status' => 'deleted']);
        foreach ([null, '', " \n\t", 1, ['text'], str_repeat('a', 21)] as $value) {
            config(['openbook.comments.max_length' => 20]);
            $this->postJson($this->url($post), ['commentContent' => $value])->assertUnprocessable();
            $this->patchJson($this->url($post, $comment), ['commentContent' => $value])->assertUnprocessable();
        }
        foreach ([$foreign->id, $deleted->id, 'invalid'] as $parent) {
            $this->postJson($this->url($post), ['commentContent' => 'No', 'commentParentId' => $parent])->assertUnprocessable();
        }
        foreach (['commentParentId' => $comment->id, 'images' => [], 'commentPostId' => $post->id] as $key => $value) {
            $this->patchJson($this->url($post, $comment), ['commentContent' => 'No', $key => $value])->assertUnprocessable();
        }
        $this->post($this->url($post), ['commentContent' => 'No'])->assertStatus(415);
        $this->call('POST', $this->url($post), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-token'], content: '{')->assertStatus(400);
        $this->postJson($this->url($post), ['No'])->assertUnprocessable();
        $this->assertSame('Original comment', $comment->fresh()->body);
    }

    public function test_pagination_ties_directions_and_anchor_scope(): void
    {
        $post = $this->makePost();
        $comments = collect();
        for ($i = 0; $i < 5; $i++) {
            $comments->push($this->comment($post, ['created_at' => now()->startOfSecond()]));
        }
        $ids = $comments->pluck('id')->sort()->values()->all();
        foreach (['asc' => $ids, 'desc' => array_reverse($ids)] as $direction => $expected) {
            $url = $this->url($post).'?limit=2&direction='.$direction;
            $actual = [];
            do {
                $response = $this->getJson($url)->assertOk();
                $actual = array_merge($actual, array_column($response->json(), 'commentId'));
                $link = $response->headers->get('Link');
                $url = $link ? explode('>', substr($link, 1))[0] : null;
            } while ($url !== null);
            $this->assertSame($expected, $actual);
        }
        $foreign = $this->comment($this->makePost());
        $this->getJson($this->url($post).'?after='.$foreign->id)->assertUnprocessable();
        $comments->first()->update(['status' => 'deleted']);
        $this->getJson($this->url($post).'?after='.$comments->first()->id)->assertUnprocessable();
        foreach (['limit=101', 'direction=ASC', 'page=2', 'sort=commentId'] as $query) {
            $this->getJson($this->url($post).'?'.$query)->assertUnprocessable();
        }
    }

    public function test_etag_changes_after_edit_and_mutations_are_no_store(): void
    {
        $post = $this->makePost();
        $comment = $this->comment($post);
        $response = $this->getJson($this->url($post, $comment))->assertOk()->assertHeader('Vary', 'Authorization');
        $etag = $response->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson($this->url($post, $comment))->assertStatus(304);
        $mutation = $this->patchJson($this->url($post, $comment), ['commentContent' => 'Updated'])->assertOk()->assertHeaderMissing('ETag');
        $this->assertTrue($mutation->headers->hasCacheControlDirective('no-store'));
        $this->getJson($this->url($post, $comment))->assertOk();
    }

    public function test_remote_refresh_rechecks_visibility_before_returning_data(): void
    {
        $remote = $this->createRemoteActor('remotecomments');
        $post = $this->makePost(['actor_id' => $remote->id, 'uri' => 'https://remoto.example/post']);
        $comment = $this->comment($post);
        Http::fake([$post->uri => Http::response(['id' => $post->uri, 'type' => 'Question',
            'attributedTo' => $remote->uri, 'content' => '<p>Private question</p>',
            'to' => [$remote->uri.'/followers'], 'cc' => [],
            'published' => $post->published_at->toISOString(),
            'oneOf' => [['type' => 'Note', 'name' => 'Yes'], ['type' => 'Note', 'name' => 'No']]], 200)]);
        $this->withHeader('If-None-Match', '*')->getJson($this->url($post, $comment))->assertNotFound();
    }

    public function test_existing_media_and_remote_comments_are_readable_and_media_survives_patch(): void
    {
        Storage::fake('public');
        $post = $this->makePost();
        $comment = app(CommentComposer::class)->compose($this->author->actor, $post, 'Photo', null,
            [UploadedFile::fake()->image('photo.jpg')], ['A view']);
        $this->getJson($this->url($post, $comment))->assertOk()->assertJsonPath('commentAttachments.0.mediaAltText', 'A view');
        $ids = $comment->media->modelKeys();
        $this->patchJson($this->url($post, $comment), ['commentContent' => 'Updated photo'])->assertOk()
            ->assertJsonPath('commentAttachments.0.mediaId', $ids[0]);
        $remote = $this->createRemoteActor('readremotecomment');
        $reply = $this->comment($post, ['actor_id' => $remote->id, 'uri' => $remote->uri.'/comments/one']);
        $this->getJson($this->url($post, $reply))->assertOk()->assertJsonPath('commentUri', $reply->uri);
        $this->patchJson($this->url($post, $reply), ['commentContent' => 'No'])->assertNotFound();
        $this->deleteJson($this->url($post, $reply))->assertNotFound();
    }

    public function test_cookie_authentication_does_not_authorize_comment_endpoints(): void
    {
        $post = $this->makePost();
        $comment = $this->comment($post);
        $this->withoutHeader('Authorization');
        app('auth')->forgetGuards();
        $this->actingAs($this->author, 'web');
        $this->getJson($this->url($post))->assertUnauthorized();
        $this->getJson($this->url($post, $comment))->assertUnauthorized();
        $this->postJson($this->url($post), ['commentContent' => 'No'])->assertUnauthorized();
        $this->patchJson($this->url($post, $comment), ['commentContent' => 'No'])->assertUnauthorized();
        $this->deleteJson($this->url($post, $comment))->assertUnauthorized();
    }

    public function test_list_query_count_does_not_grow_per_comment(): void
    {
        $post = $this->makePost();
        for ($i = 0; $i < 20; $i++) {
            $this->comment($post);
        }
        DB::enableQueryLog();
        $this->getJson($this->url($post).'?limit=2')->assertOk()->assertJsonCount(2);
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson($this->url($post).'?limit=20')->assertOk()->assertJsonCount(20);
        $this->assertLessThanOrEqual($small + 1, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
