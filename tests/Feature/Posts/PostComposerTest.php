<?php

namespace Tests\Feature\Posts;

use App\Application\Services\PostComposer;
use App\Domain\Notifications\Notification;
use App\Domain\Posts\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PostComposerTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_it_creates_a_published_post(): void
    {
        $author = $this->createFullAccount('autoreuno');

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Il mio primo post su Openbook.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'actor_id' => $author->actor->id,
            'body' => 'Il mio primo post su Openbook.',
            'status' => Post::STATUS_PUBLISHED,
        ]);
        $this->assertNotNull($post->published_at);
    }

    public function test_it_extracts_and_links_hashtags(): void
    {
        $author = $this->createFullAccount('taggatore');

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Che bella giornata di #sole e #Mare, ancora #sole!',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $names = $post->hashtags()->pluck('name')->sort()->values()->all();

        $this->assertSame(['mare', 'sole'], $names);
    }

    public function test_it_notifies_locally_mentioned_actors(): void
    {
        $author = $this->createFullAccount('mentioner');
        $mentioned = $this->createFullAccount('mentioned');

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Ciao @mentioned, come stai?',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->assertDatabaseHas('mentions', [
            'mentionable_id' => $post->id,
            'actor_id' => $mentioned->actor->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $mentioned->id,
            'type' => Notification::TYPE_MENTION,
            'actor_id' => $author->actor->id,
        ]);
    }

    public function test_it_does_not_notify_self_mentions(): void
    {
        $author = $this->createFullAccount('selfmention');

        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Parlo di me stesso, @selfmention.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('mentions', 0);
    }

    public function test_it_stores_image_attachments_and_generates_a_thumbnail(): void
    {
        Storage::fake('public');

        $author = $this->createFullAccount('fotografo');

        $image = UploadedFile::fake()->image('foto.jpg', 1200, 900);

        $post = app(PostComposer::class)->compose($author->actor, [
            'body' => 'Guardate questa foto.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'images' => [$image],
            'alt_texts' => ['Un bel tramonto'],
        ]);

        $this->assertCount(1, $post->attachments);

        $media = $post->media()->first();
        $this->assertNotNull($media);
        $this->assertSame('Un bel tramonto', $media->alt_text);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_it_rejects_more_images_than_the_configured_maximum(): void
    {
        Storage::fake('public');
        config(['openbook.media.max_attachments_per_post' => 1]);

        $author = $this->createFullAccount('multiimmagine');

        $this->expectException(\InvalidArgumentException::class);

        app(PostComposer::class)->compose($author->actor, [
            'body' => 'Troppe immagini.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'images' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
        ]);
    }

    public function test_a_failed_upload_rolls_back_the_whole_post(): void
    {
        Storage::fake('public');

        $author = $this->createFullAccount('rollback');

        $invalidFile = UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf');

        try {
            app(PostComposer::class)->compose($author->actor, [
                'body' => 'Questo post non deve esistere.',
                'visibility' => Post::VISIBILITY_PUBLIC,
                'images' => [$invalidFile],
            ]);
            $this->fail('Ci si aspettava una eccezione per tipo di file non valido.');
        } catch (\InvalidArgumentException) {
            // atteso
        }

        $this->assertDatabaseCount('posts', 0);
    }
}
