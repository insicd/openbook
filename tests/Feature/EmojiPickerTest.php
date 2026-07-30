<?php

namespace Tests\Feature;

use App\Application\Services\PostComposer;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class EmojiPickerTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_the_post_composer_exposes_an_emoji_trigger_and_assets(): void
    {
        $user = $this->createFullAccount('emojipost');

        $response = $this->actingAs($user)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('data-emoji-target="composer-body"', false);
        $response->assertSee('assets/js/emoji-data.js', false);
        $response->assertSee('assets/js/emoji-picker.js', false);
        $response->assertSee('aria-label="Inserisci emoji"', false);
    }

    public function test_comment_and_reply_forms_expose_emoji_triggers(): void
    {
        $author = $this->createFullAccount('emojiautore');
        $viewer = $this->createFullAccount('emojiviewer');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post con commenti emoji.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($viewer)->post(route('comments.store', $post), [
            'body' => 'Primo commento.',
        ])->assertRedirect();

        $stored = Comment::query()->where('post_id', $post->id)->first();
        $this->assertNotNull($stored);

        $response = $this->actingAs($viewer)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('data-emoji-target="comment-body"', false);
        $response->assertSee('data-emoji-target="risposta-testo-'.$stored->id.'"', false);
    }

    public function test_guests_do_not_load_emoji_picker_assets(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('assets/js/emoji-picker.js', false);
    }
}
