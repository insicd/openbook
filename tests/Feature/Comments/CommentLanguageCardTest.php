<?php

namespace Tests\Feature\Comments;

use App\Application\Services\PostComposer;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class CommentLanguageCardTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    #[DataProvider('languages')]
    public function test_post_detail_and_comment_thread_show_the_language_in_the_readers_locale(string $locale, ?string $language, ?string $label): void
    {
        Queue::fake();
        $reader = $this->createFullAccount('commentreader');
        $reader->settings->update(['locale' => $locale]);
        $post = app(PostComposer::class)->compose($reader->actor, [
            'body' => 'Post senza lingua.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $remote = $this->createRemoteActor('commentauthor');
        $comment = Comment::query()->create([
            'post_id' => $post->id,
            'actor_id' => $remote->id,
            'uri' => $remote->uri.'/notes/comment',
            'body' => 'Commento con lingua dichiarata.',
            'language' => $language,
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        foreach ([route('posts.show', $post), route('comments.show', $comment)] as $url) {
            $response = $this->actingAs($reader)->get($url)->assertOk()
                ->assertDontSee('<img src=x', false);

            if ($label === null) {
                $response->assertDontSee('class="ob-post__language"', false);
            } else {
                $response->assertSee('<bdi>'.e($label).'</bdi>', false)
                    ->assertSee('title="'.e(__('openbook.posts.declared_language')).': '.e($label).'"', false)
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
            ['it', '"><img src=x onerror=alert(1)>', '"><img src=x onerror=alert(1)>'],
        ];
    }

    public function test_a_deleted_comment_hides_its_language_without_affecting_its_replies(): void
    {
        Queue::fake();
        $reader = $this->createFullAccount('replyreader');
        $reader->settings->update(['locale' => 'it']);
        $post = app(PostComposer::class)->compose($reader->actor, [
            'body' => 'Post senza lingua.', 'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $parent = Comment::query()->create([
            'post_id' => $post->id, 'actor_id' => $reader->actor->id,
            'body' => 'Commento eliminato.', 'language' => 'fr',
            'status' => Comment::STATUS_DELETED,
        ]);
        $reply = Comment::query()->create([
            'post_id' => $post->id, 'actor_id' => $reader->actor->id,
            'parent_comment_id' => $parent->id,
            'body' => 'Risposta visibile.', 'language' => 'en',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
        Comment::query()->create([
            'post_id' => $post->id, 'actor_id' => $reader->actor->id,
            'parent_comment_id' => $parent->id,
            'body' => 'Risposta senza lingua.', 'status' => Comment::STATUS_PUBLISHED,
        ]);

        foreach ([route('posts.show', $post), route('comments.show', $reply)] as $url) {
            $response = $this->actingAs($reader)->get($url)->assertOk()
                ->assertDontSee('<bdi>Francese</bdi>', false)
                ->assertSee('<bdi>Inglese</bdi>', false);
            $this->assertSame(1, substr_count($response->getContent(), 'class="ob-post__language"'));
        }
    }
}
