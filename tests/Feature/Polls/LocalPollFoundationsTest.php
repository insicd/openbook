<?php

namespace Tests\Feature\Polls;

use App\Domain\Posts\Post;
use App\Federation\Actors\LocalActorUrls;
use App\Federation\Serialization\NoteSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TestsLocalPollFoundations;
use Tests\TestCase;

class LocalPollFoundationsTest extends TestCase
{
    use RefreshDatabase, TestsLocalPollFoundations;

    public function test_http_publication_and_outbox_expose_the_same_question(): void
    {
        $user = $this->createFullAccount('httppollauthor');
        $this->actingAs($user)->post(route('posts.store'), [
            'body' => 'Quale giorno?', 'visibility' => 'public',
            'poll' => ['options' => ['Sabato', 'Domenica'], 'multiple' => false, 'duration_seconds' => 300],
        ])->assertRedirect();
        $post = Post::query()->sole();
        $this->get(route('posts.show', $post), ['Accept' => 'application/activity+json'])
            ->assertOk()->assertJsonPath('type', 'Question')->assertJsonPath('oneOf.0.name', 'Sabato');
        $outbox = LocalActorUrls::forUsername($user->username)['outbox'];
        $this->get($outbox.'?page=1', ['Accept' => 'application/activity+json'])
            ->assertOk()->assertJsonPath('orderedItems.0.object.type', 'Question')
            ->assertJsonPath('orderedItems.0.object.id', NoteSerializer::postUri($post));
    }

    public function test_follower_only_question_is_not_exposed_to_guests(): void
    {
        $post = $this->localPollPost(['visibility' => 'followers']);
        $this->get(route('posts.show', $post), ['Accept' => 'application/activity+json'])->assertNotFound();
        $outbox = LocalActorUrls::forUsername($post->actor->preferred_username)['outbox'];
        $this->get($outbox.'?page=1', ['Accept' => 'application/activity+json'])
            ->assertOk()->assertJsonPath('orderedItems', []);
    }

    public function test_http_update_rejects_poll_changes_and_visibility_changes(): void
    {
        $post = $this->localPollPost();
        $this->actingAs($post->actor->user)->put(route('posts.update', $post), [
            'body' => 'Corretto', 'visibility' => 'followers',
        ])->assertSessionHasErrors('visibility');
        $this->put(route('posts.update', $post), [
            'body' => 'Corretto', 'visibility' => 'public', 'poll' => ['multiple' => true],
        ])->assertSessionHasErrors('poll');
        $this->assertSame('public', $post->fresh()->visibility);
        $this->assertFalse($post->poll->fresh()->multiple);
    }
}
