<?php

namespace Tests\Feature\Posts;

use App\Application\Services\FollowManager;
use App\Application\Services\PostComposer;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PostControllerTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_an_authenticated_user_can_publish_a_post(): void
    {
        $user = $this->createFullAccount('pubblicatore');

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'body' => 'Il mio post dalla richiesta HTTP.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response->assertRedirect(route('feed.index'));
        $this->assertDatabaseHas('posts', [
            'actor_id' => $user->actor->id,
            'body' => 'Il mio post dalla richiesta HTTP.',
        ]);
    }

    public function test_a_guest_cannot_publish_a_post(): void
    {
        $response = $this->post(route('posts.store'), [
            'body' => 'Non dovrebbe funzionare.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $user = $this->createFullAccount('vuoto');

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'body' => '',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response->assertSessionHasErrors('body');
    }

    public function test_a_public_post_page_is_reachable_by_a_guest(): void
    {
        $author = $this->createFullAccount('visibileatutti');
        $post = $this->publishPost($author, 'Post pubblico visibile a tutti.');

        $this->get(route('posts.show', $post))->assertOk()->assertSee('Post pubblico visibile a tutti.');
    }

    public function test_a_followers_only_post_is_hidden_from_non_followers(): void
    {
        $author = $this->createFullAccount('privato');
        $post = $this->publishPost($author, 'Solo per i miei follower.', Post::VISIBILITY_FOLLOWERS);

        $stranger = $this->createFullAccount('estraneo');

        $this->actingAs($stranger)->get(route('posts.show', $post))->assertNotFound();
    }

    public function test_a_followers_only_post_is_visible_to_an_accepted_follower(): void
    {
        $author = $this->createFullAccount('privato2');
        $post = $this->publishPost($author, 'Solo per i miei follower.', Post::VISIBILITY_FOLLOWERS);

        $follower = $this->createFullAccount('seguace');
        app(FollowManager::class)->follow($follower->actor, $author->actor);

        $this->actingAs($follower)->get(route('posts.show', $post))->assertOk();
    }

    public function test_only_the_author_can_delete_their_post(): void
    {
        $author = $this->createFullAccount('proprietario');
        $post = $this->publishPost($author, 'Post da eliminare.');

        $stranger = $this->createFullAccount('nonproprietario');

        $this->actingAs($stranger)->delete(route('posts.destroy', $post))->assertForbidden();

        $this->actingAs($author)->delete(route('posts.destroy', $post))->assertRedirect(route('feed.index'));

        $post->refresh();
        $this->assertSame(Post::STATUS_DELETED, $post->status);
        $this->assertSame('', $post->body);
    }

    public function test_uploaded_images_are_rejected_when_the_type_is_not_allowed(): void
    {
        Storage::fake('public');
        $user = $this->createFullAccount('tipofile');

        $response = $this->actingAs($user)->post(route('posts.store'), [
            'body' => 'Allegato non valido.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'images' => [UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload')],
        ]);

        $response->assertSessionHasErrors('images.0');
    }

    private function publishPost($author, string $body, string $visibility = Post::VISIBILITY_PUBLIC): Post
    {
        return app(PostComposer::class)->compose($author->actor, [
            'body' => $body,
            'visibility' => $visibility,
        ]);
    }
}
