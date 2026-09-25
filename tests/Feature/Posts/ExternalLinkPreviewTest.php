<?php

namespace Tests\Feature\Posts;

use App\Application\Services\PostComposer;
use App\Domain\Posts\ExternalLinkPreview;
use App\Domain\Posts\Post;
use App\Domain\Posts\PostAttachment;
use App\Infrastructure\Media\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class ExternalLinkPreviewTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_only_authenticated_users_can_request_a_preview(): void
    {
        $post = $this->createPost('https://article.example/story');

        $this->get(route('posts.link_preview', $post))->assertRedirect(route('login'));
    }

    public function test_preview_requests_have_their_own_sixty_per_minute_limit(): void
    {
        $post = $this->createPost('https://article.example/story');
        $viewer = $this->createFullAccount('previewlimit');
        Http::fake(['article.example/*' => Http::response(
            '<html><head><meta property="og:title" content="Article"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $this->actingAs($viewer);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->get(route('posts.link_preview', $post))->assertOk();
        }

        $this->get(route('posts.link_preview', $post))->assertStatus(429);
        $this->get(route('search.suggest', ['q' => 'nothing-matches']))->assertOk();
    }

    public function test_an_eligible_post_renders_only_a_hidden_async_placeholder(): void
    {
        $post = $this->createPost('Read https://article.example/story');
        $post->forceFill(['content_warning' => 'Spoiler'])->save();
        $viewer = $this->createFullAccount('viewpreview');

        $this->actingAs($viewer)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('data-link-preview-url="'.route('posts.link_preview', $post).'" hidden', false)
            ->assertDontSee('ob-post__link-preview-content', false);

        $this->assertSame(0, ExternalLinkPreview::query()->count());
    }

    public function test_the_feed_uses_the_same_async_placeholder(): void
    {
        $author = $this->createFullAccount('feedpreview');
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'https://article.example/feed-story',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($author)->get(route('feed.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('data-link-preview-url="'.route('posts.link_preview', $post).'" hidden', false);
    }

    public function test_a_markdown_actor_link_does_not_prevent_the_article_preview(): void
    {
        $post = $this->createPost(
            'https://news.example/story?source=flipboard'
            .' Posted into [@publisher](https://flipboard.example/@publisher)',
        );
        $viewer = $this->createFullAccount('actorlinkpreview');

        $this->actingAs($viewer)->get(route('posts.show', $post))
            ->assertSee('data-link-preview-url="'.route('posts.link_preview', $post).'" hidden', false);

        Http::fake(['news.example/*' => Http::response(
            '<html><head><meta property="og:title" content="The article"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);
        $this->get(route('posts.link_preview', $post))
            ->assertJsonPath('url', 'https://news.example/story?source=flipboard');
        Http::assertSentCount(1);
    }

    public function test_ineligible_and_guest_post_views_do_not_render_the_placeholder(): void
    {
        $post = $this->createPost('https://article.example/story');
        $this->get(route('posts.show', $post))->assertDontSee('data-link-preview-url=', false);

        $viewer = $this->createFullAccount('viewineligible');

        foreach ([
            $this->createPost('https://article.example/one https://article.example/two'),
            $this->createPost('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
            $this->createPost('https://article.example/private', Post::VISIBILITY_UNLISTED),
        ] as $ineligible) {
            $this->actingAs($viewer)->get(route('posts.show', $ineligible))
                ->assertDontSee('data-link-preview-url=', false);
        }
    }

    public function test_it_fetches_og_metadata_once_and_reuses_it_across_posts(): void
    {
        $first = $this->createPost('https://article.example/story?ref=music');
        $second = $this->createPost('https://article.example/story?ref=music');
        $viewer = $this->createFullAccount('previewviewer');

        Http::fake(['article.example/*' => Http::response(
            '<html><head><meta property="og:title" content="Love &amp; Gold">'
            .'<meta property="og:description" content="A great record">'
            .'<meta property="og:site_name" content="Music Blog">'
            .'<meta property="og:image" content="https://cdn.example/cover.jpg"></head></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);

        $this->actingAs($viewer)->get(route('posts.link_preview', $first))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('title', 'Love & Gold')
            ->assertJsonPath('description', 'A great record')
            ->assertJsonPath('site_name', 'Music Blog')
            ->assertJsonPath('image_url', 'https://cdn.example/cover.jpg')
            ->assertJsonPath('url', 'https://article.example/story?ref=music');

        $this->get(route('posts.link_preview', $second))->assertJsonPath('title', 'Love & Gold');
        $this->assertSame(1, ExternalLinkPreview::query()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://article.example/story?ref=music');
    }

    public function test_a_missing_og_title_is_cached_for_one_hour_then_retried(): void
    {
        $post = $this->createPost('https://article.example/no-title');
        $viewer = $this->createFullAccount('negativepreview');
        Http::fake(['article.example/*' => fn () => Http::response(
            '<html><head><meta property="og:description" content="Only description"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $this->actingAs($viewer)->get(route('posts.link_preview', $post))
            ->assertOk()->assertJsonPath('available', false);
        $this->get(route('posts.link_preview', $post))->assertJsonPath('available', false);
        Http::assertSentCount(1);
        $this->assertFalse(ExternalLinkPreview::query()->firstOrFail()->available);

        $this->travel(3601)->seconds();
        $this->get(route('posts.link_preview', $post))->assertJsonPath('available', false);
        Http::assertSentCount(2);
    }

    public function test_it_removes_hashtags_from_the_description_before_caching(): void
    {
        $post = $this->createPost('https://article.example/tagged');
        $viewer = $this->createFullAccount('taggedpreview');
        Http::fake(['article.example/*' => Http::response(
            '<html><head><meta property="og:title" content="A story">'
            .'<meta property="og:description" content="#rock #musica_italiana  Il testo vero. #finale"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $this->actingAs($viewer)->get(route('posts.link_preview', $post))
            ->assertJsonPath('description', 'Il testo vero.');
        $this->assertSame('Il testo vero.', ExternalLinkPreview::query()->firstOrFail()->description);
    }

    public function test_it_cleans_descriptions_already_in_the_cache(): void
    {
        $post = $this->createPost('https://article.example/cached');
        $viewer = $this->createFullAccount('cachedpreview');
        ExternalLinkPreview::query()->create([
            'url_hash' => hash('sha256', 'https://article.example/cached'),
            'url' => 'https://article.example/cached',
            'available' => true,
            'title' => 'A story',
            'description' => '#rock  Il testo vero.',
            'fetched_at' => now(),
        ]);
        Http::preventStrayRequests();

        $this->actingAs($viewer)->get(route('posts.link_preview', $post))
            ->assertJsonPath('description', 'Il testo vero.');
        Http::assertNothingSent();
    }

    public function test_success_cache_age_uses_current_configured_ttl(): void
    {
        $post = $this->createPost('https://article.example/ttl');
        $viewer = $this->createFullAccount('ttlpreview');
        Http::fake(['article.example/*' => fn () => Http::response(
            '<html><head><meta property="og:title" content="An article"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $this->actingAs($viewer)->get(route('posts.link_preview', $post))->assertJsonPath('title', 'An article');
        $this->travel(60)->seconds();
        config()->set('openbook.link_preview.success_ttl_seconds', 30);
        $this->get(route('posts.link_preview', $post))->assertJsonPath('title', 'An article');
        Http::assertSentCount(2);
    }

    public function test_ineligible_posts_never_trigger_an_external_fetch(): void
    {
        $viewer = $this->createFullAccount('ineligiblepreview');
        Http::preventStrayRequests();

        foreach ([
            'two-links' => 'https://one.example/a https://two.example/b',
            'youtube' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ] as $body) {
            $this->actingAs($viewer)->get(route('posts.link_preview', $this->createPost($body)))->assertNotFound();
        }

        foreach ([Post::VISIBILITY_UNLISTED, Post::VISIBILITY_FOLLOWERS, Post::VISIBILITY_DIRECT] as $visibility) {
            $this->get(route('posts.link_preview', $this->createPost('https://one.example/a', $visibility)))->assertNotFound();
        }

        $imagePost = $this->createPost('https://one.example/a');
        $image = Media::query()->create([
            'actor_id' => $imagePost->actor_id,
            'disk' => 'public',
            'path' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'byte_size' => 100,
        ]);
        PostAttachment::query()->create(['post_id' => $imagePost->id, 'media_id' => $image->id, 'position' => 0]);
        $this->get(route('posts.link_preview', $imagePost))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_duplicate_occurrences_count_as_one_link_and_internal_targets_are_rejected(): void
    {
        $viewer = $this->createFullAccount('safeurlpreview');
        Http::fake(['article.example/*' => Http::response(
            '<html><head><meta property="og:title" content="Duplicated URL"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);
        $repeated = $this->createPost('https://article.example/story https://article.example/story');
        $this->actingAs($viewer)->get(route('posts.link_preview', $repeated))
            ->assertJsonPath('title', 'Duplicated URL');

        $internal = $this->createPost('http://127.0.0.1/private');
        $this->get(route('posts.link_preview', $internal))->assertJsonPath('available', false);
        Http::assertSentCount(1);
    }

    public function test_redirects_to_internal_addresses_and_oversized_pages_are_rejected(): void
    {
        $viewer = $this->createFullAccount('hostilepreview');
        Http::fake([
            'article.example/redirect' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private']),
            'article.example/huge' => Http::response(
                '<html><head><meta property="og:title" content="Huge"></head>'.str_repeat('x', 270000).'</html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->actingAs($viewer)->get(route('posts.link_preview', $this->createPost('https://article.example/redirect')))
            ->assertJsonPath('available', false);
        $this->get(route('posts.link_preview', $this->createPost('https://article.example/huge')))
            ->assertJsonPath('available', false);

        Http::assertSentCount(2);
        $this->assertSame(2, ExternalLinkPreview::query()->where('available', false)->count());
    }

    public function test_it_ignores_non_html_responses_and_optional_og_fields_can_be_missing(): void
    {
        $viewer = $this->createFullAccount('minimalpreview');
        Http::fake([
            'article.example/document' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
            'article.example/minimal' => Http::response(
                '<html><head><meta property="og:title" content="A title"></head></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->actingAs($viewer)->get(route('posts.link_preview', $this->createPost('https://article.example/document')))
            ->assertJsonPath('available', false);
        $this->get(route('posts.link_preview', $this->createPost('https://article.example/minimal')))
            ->assertJsonPath('title', 'A title')
            ->assertJsonPath('description', null)
            ->assertJsonPath('image_url', null);
    }

    public function test_an_audio_attachment_does_not_suppress_the_preview(): void
    {
        $viewer = $this->createFullAccount('audiopreview');
        $post = $this->createPost('https://article.example/audio-story');
        $audio = Media::query()->create([
            'actor_id' => $post->actor_id,
            'disk' => 'public',
            'path' => 'test.mp3',
            'mime_type' => 'audio/mpeg',
            'byte_size' => 100,
        ]);
        PostAttachment::query()->create(['post_id' => $post->id, 'media_id' => $audio->id, 'position' => 0]);
        Http::fake(['article.example/*' => Http::response(
            '<html><head><meta property="og:title" content="Audio story"></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $this->actingAs($viewer)->get(route('posts.link_preview', $post))
            ->assertJsonPath('available', true)
            ->assertJsonPath('title', 'Audio story');
    }

    private function createPost(string $body, string $visibility = Post::VISIBILITY_PUBLIC): Post
    {
        static $index = 0;
        $index++;
        $author = $this->createFullAccount('previewauthor'.$index);

        return Post::query()->create([
            'actor_id' => $author->actor->id,
            'body' => $body,
            'visibility' => $visibility,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }
}
