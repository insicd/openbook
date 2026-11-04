<?php

namespace Tests\Feature\Posts;

use App\Application\Services\PostComposer;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PostSourceCardTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public static function sources(): array
    {
        return [
            'ordinary' => [null, null],
            'community' => [['community' => 'https://forum.example/c/cooking'], 'Post ricevuto nella community forum.example/c/cooking'],
            'relay' => [['relay' => 'https://relay.example/actor'], 'Post ricevuto tramite il relay relay.example/actor'],
            'both' => [['community' => 'https://forum.example/c/cooking', 'relay' => 'https://relay.example/actor'], 'Post ricevuto nella community forum.example/c/cooking tramite il relay relay.example/actor'],
            'feed with credentials' => [['rss' => 'https://user:password@blog.example/feed.xml?token=secret#private'], 'Post importato dal feed blog.example/feed.xml'],
            'unknown future key' => [['future' => 'https://example.org'], null],
            'invalid source' => [['relay' => 'javascript:alert(1)', 'community' => ['invalid']], null],
        ];
    }

    #[DataProvider('sources')]
    public function test_feed_and_detail_show_only_safe_source_tooltips(?array $via, ?string $label): void
    {
        Queue::fake();
        $author = $this->createFullAccount('sourcereader');
        $author->settings->update(['locale' => 'it']);
        $post = app(PostComposer::class)->compose($author->actor, ['body' => 'Source card.', 'visibility' => Post::VISIBILITY_PUBLIC]);
        $post->update(['received_via' => $via]);

        foreach ([route('feed.index'), route('posts.show', $post)] as $url) {
            $headers = $url === route('feed.index') ? ['X-Requested-With' => 'XMLHttpRequest'] : [];
            $response = $this->actingAs($author)->get($url, $headers)->assertOk();
            if ($label === null) {
                $response->assertDontSee('class="ob-post__source"', false);
            } else {
                $response->assertSee('<details class="ob-post__source">', false)
                    ->assertSee('<summary class="ob-post__source-trigger" aria-label="'.e($label).'">', false)
                    ->assertSee('<div class="ob-post__source-panel" role="note">'.e($label).'</div>', false);
            }
            $response->assertDontSee('user:password', false)->assertDontSee('token=secret', false)->assertDontSee('#private', false);
        }
    }

    public function test_tooltip_is_escaped_and_absent_on_deleted_or_embedded_cards(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('safesource');
        $post = app(PostComposer::class)->compose($author->actor, ['body' => 'Safe source.']);
        $post->received_via = ['community' => 'https://forum.example/c/" onmouseover="alert(1)'];
        $post->load(Post::CARD_RELATIONS);
        $html = view('posts._card', ['post' => $post])->render();
        $this->assertStringContainsString('forum.example/c/&quot; onmouseover=&quot;alert(1)', $html);
        $this->assertStringNotContainsString(' onmouseover="', $html);
        $this->assertStringNotContainsString('class="ob-post__source"', view('posts._card', ['post' => $post, 'embed' => true])->render());
        $post->status = Post::STATUS_DELETED;
        $this->assertStringNotContainsString('class="ob-post__source"', view('posts._card', ['post' => $post])->render());
    }

    public function test_source_labels_follow_the_interface_locale(): void
    {
        foreach (['en' => 'Post received through relay relay.example/actor', 'fi' => 'Julkaisu vastaanotettu releen relay.example/actor kautta'] as $locale => $label) {
            app()->setLocale($locale);
            $html = (string) Blade::render('<x-post-source :via="$via" />', ['via' => ['relay' => 'https://relay.example/actor']]);
            $this->assertStringContainsString('role="note">'.$label.'</div>', $html);
        }
    }
}
