<?php

namespace Tests\Feature\Federation;

use App\Domain\Posts\Post;
use App\Federation\Inbox\RemoteNoteUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class RemoteNoteUpserterTest extends TestCase
{
    use CreatesRemoteActors, RefreshDatabase;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-10-10T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    #[DataProvider('publicationDates')]
    public function test_publication_date_is_sanitized_on_insert_and_update(string $input, string $expected): void
    {
        $post = $this->savePost($input);

        $this->assertSame($expected, $post->fresh()->published_at->format('Y-m-d H:i:s'));
        $this->assertTrue($post->fresh()->remote_updated_at->equalTo(Carbon::parse($input)));

        // Anche una riga gia' in cache con data futura viene corretta al salvataggio.
        $post->forceFill(['published_at' => now()->addYear(), 'remote_updated_at' => null])->save();
        $updated = $this->savePost($input, $post, 'Testo aggiornato');

        $this->assertSame($post->id, $updated->id);
        $this->assertSame($expected, $updated->fresh()->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('Testo aggiornato', $updated->body);
    }

    public static function publicationDates(): array
    {
        return [
            'past' => ['2026-10-09T12:00:00+00:00', '2026-10-09 12:00:00'],
            'present' => ['2026-10-10T12:00:00+00:00', '2026-10-10 12:00:00'],
            'future' => ['2026-10-10T12:01:00+00:00', '2026-10-10 12:00:00'],
            'wrong year' => ['2199-01-01T00:00:00+00:00', '2026-10-10 12:00:00'],
            'offset past' => ['2026-10-10T13:00:00+02:00', '2026-10-10 11:00:00'],
            'offset future' => ['2026-10-10T11:00:00-02:00', '2026-10-10 12:00:00'],
        ];
    }

    public function test_repeated_future_date_does_not_bump_publication_time(): void
    {
        $post = $this->savePost('2199-01-01T00:00:00Z');
        $publishedAt = $post->fresh()->published_at;
        $this->travel(1)->hour();

        $updated = $this->savePost('2199-01-01T00:00:00Z', $post, 'Testo aggiornato');

        $this->assertTrue($updated->fresh()->published_at->equalTo($publishedAt));
        $this->assertSame('Testo aggiornato', $updated->body);
    }

    public function test_future_update_preserves_existing_valid_publication_date(): void
    {
        $post = $this->savePost('2026-10-09T12:00:00Z');

        $updated = $this->savePost('2199-01-01T00:00:00Z', $post);

        $this->assertSame('2026-10-09 12:00:00', $updated->fresh()->published_at->format('Y-m-d H:i:s'));
    }

    public function test_valid_date_can_replace_a_previously_capped_date(): void
    {
        $post = $this->savePost('2199-01-01T00:00:00Z', updatedAt: now()->toAtomString());
        $this->travel(1)->hour();

        $updated = $this->savePost('2026-10-09T12:00:00Z', $post, updatedAt: now()->toAtomString());

        $this->assertSame('2026-10-09 12:00:00', $updated->fresh()->published_at->format('Y-m-d H:i:s'));
    }

    public function test_future_date_is_stored_in_the_application_timezone(): void
    {
        config(['app.timezone' => 'Europe/Rome']);
        date_default_timezone_set('Europe/Rome');

        $post = $this->savePost('2199-01-01T00:00:00Z');

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'published_at' => '2026-10-10 14:00:00',
        ]);
    }

    private function savePost(string $published, ?Post $post = null, string $body = 'Post remoto', ?string $updatedAt = null): Post
    {
        $author = $post?->actor ?? $this->createRemoteActor('futurepost');
        $uri = $post?->uri ?? $author->uri.'/statuses/1';

        return app(RemoteNoteUpserter::class)->upsertPost(
            [
                'id' => $uri,
                'type' => 'Note',
                'attributedTo' => $author->uri,
                'published' => $published,
                'updated' => $updatedAt,
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
            $uri,
            $author,
            $body,
            Carbon::parse($published),
            notifyMentions: false,
        );
    }
}
