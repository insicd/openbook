<?php

namespace Tests\Feature\Posts;

use App\Application\Services\PostComposer;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PostLanguageCardTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    #[DataProvider('languages')]
    public function test_feed_and_detail_show_the_language_in_the_readers_locale(string $locale, ?string $language, ?string $label): void
    {
        Queue::fake();
        $author = $this->createFullAccount('languagereader');
        $author->settings->update(['locale' => $locale]);
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Post con lingua dichiarata.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'language' => $language,
        ]);

        foreach ([route('feed.index'), route('posts.show', $post)] as $url) {
            $headers = $url === route('feed.index') ? ['X-Requested-With' => 'XMLHttpRequest'] : [];
            $response = $this->actingAs($author)->get($url, $headers)->assertOk();

            if ($label === null) {
                $response->assertDontSee('class="ob-post__language"', false);
            } else {
                $response->assertSee('class="ob-post__language"', false)
                    ->assertSee('<bdi>'.e($label).'</bdi>', false)
                    ->assertSee('<span class="sr-only">'.e(__('openbook.posts.declared_language')).': </span>', false);
            }
        }
    }

    public static function languages(): array
    {
        return [
            ['it', 'en', 'Inglese'], ['en', 'it', 'Italian'],
            ['it', 'zh-hant-tw', 'Cinese (tradizionale, Taiwan)'],
            ['it', 'cmn', 'Cinese mandarino'], ['en', 'yue', 'Cantonese'],
            ['it', 'zz', 'zz'], ['en', null, null],
        ];
    }

    public function test_legacy_language_values_are_escaped_and_deleted_posts_hide_the_label(): void
    {
        Queue::fake();
        $author = $this->createFullAccount('legacylanguage');
        $tag = '"><img src=x onerror=alert(1)>';
        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Legacy language.', 'visibility' => Post::VISIBILITY_PUBLIC,
            'language' => $tag,
        ]);

        $html = view('posts._card', ['post' => $post])->render();
        $this->assertStringContainsString('<bdi>'.e($tag).'</bdi>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);

        $post->status = Post::STATUS_DELETED;
        $this->assertStringNotContainsString('class="ob-post__language"', view('posts._card', ['post' => $post])->render());
    }
}
