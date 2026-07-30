<?php

namespace Tests\Feature\Posts;

use App\Application\Services\PostComposer;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PostVideoEmbedTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_a_post_with_a_youtube_link_renders_an_embed_iframe_in_the_card(): void
    {
        $author = $this->createFullAccount('videoyt');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Guarda questo: https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($author)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('class="ob-post__video"', false);
        $response->assertSee('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', false);
        $response->assertSee('https://www.youtube.com/watch?v=dQw4w9WgXcQ', false);
    }

    public function test_a_post_with_a_peertube_link_renders_an_embed_iframe_in_the_feed(): void
    {
        $author = $this->createFullAccount('videopt');
        app(PostComposer::class)->compose($author->actor, [
            'body' => 'PeerTube https://framatube.org/w/coLQEg9FZQEMH5AbhZCoXN',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($author)->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('src="https://framatube.org/videos/embed/coLQEg9FZQEMH5AbhZCoXN"', false);
    }

    public function test_only_the_first_video_link_is_embedded_when_a_post_has_several(): void
    {
        $author = $this->createFullAccount('videomulti');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => "Uno https://youtu.be/AAAAAAAAAAA\nDue https://youtu.be/BBBBBBBBBBB",
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($author)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertSee('src="https://www.youtube-nocookie.com/embed/AAAAAAAAAAA"', false);
        $response->assertDontSee('src="https://www.youtube-nocookie.com/embed/BBBBBBBBBBB"', false);
        // Il secondo link resta comunque testo cliccabile nel body.
        $response->assertSee('https://youtu.be/BBBBBBBBBBB', false);
    }

    public function test_a_post_without_video_links_has_no_embed_iframe(): void
    {
        $author = $this->createFullAccount('videonone');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Niente video, solo testo.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($author)->get(route('posts.show', $post));

        $response->assertOk();
        $response->assertDontSee('class="ob-post__video"', false);
        $response->assertDontSee('<iframe', false);
    }
}
